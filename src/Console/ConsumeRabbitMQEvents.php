<?php

namespace Uzapoint\EventBus\Console;

use Throwable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AbstractChannel;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Uzapoint\EventBus\EventProcessor;
use Uzapoint\EventBus\EventRegistry;

class ConsumeRabbitMQEvents extends Command
{
    protected $signature = 'eventbus:consume
        {--queue=* : Consume specific queue(s)}
        {--exchange=* : Declare specific exchange(s)}';

    protected $description = 'EventBus RabbitMQ consumer';

    protected ?AMQPStreamConnection $connection = null;
    protected AbstractChannel|AMQPChannel|null $channel = null;

    protected bool $running = true;

    public function __construct(
        protected EventProcessor $processor,
        protected EventRegistry $registry
    ) {
        parent::__construct();
    }

    /**
     * ENTRY POINT
     */
    public function handle(): void
    {
        $this->info('🚀 Starting EventBus Consumer...');

        if (empty(config('eventbus.queues', []))) {
            $this->info('No queues configured. Exiting.');
            return;
        }

        $this->connect();
        $this->declareTopology();
        $this->consume();
    }

    /**
     * CONNECTION
     */
    protected function connect(): void
    {
        try {
            $this->connection = new AMQPStreamConnection(
                config('queue.connections.rabbitmq.host'),
                (int) config('queue.connections.rabbitmq.port'),
                config('queue.connections.rabbitmq.user'),
                config('queue.connections.rabbitmq.password'),
                config('queue.connections.rabbitmq.vhost'),
                false,
                'AMQPLAIN',
                null,
                config('eventbus.consumer.connection_timeout', 3),
                config('eventbus.consumer.read_write_timeout', 130),
                null,
                false,
                config('eventbus.consumer.heartbeat', 60)
            );

            $this->channel = $this->connection->channel();

            $prefetch = (int) config('eventbus.consumer.prefetch_count', 1);
            $this->channel->basic_qos(0, $prefetch, false);

            $this->info('✅ Connected to RabbitMQ');

        } catch (Throwable $e) {
            Log::critical('[EventBus] Connection failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * TOPOLOGY (exchanges + DLX + queues)
     */
    protected function declareTopology(): void
    {
        $this->declareExchanges();
        $this->declareDeadLetters();
        $this->declareQueues();
    }

    protected function declareExchanges(): void
    {
        $exchanges = $this->option('exchange')
            ?: config('eventbus.exchanges', []);

        foreach ($exchanges as $exchange) {
            $this->channel->exchange_declare(
                $exchange,
                AMQPExchangeType::TOPIC,
                false,
                true,
                false
            );

            $this->info("Exchange: {$exchange}");
        }
    }

    protected function declareDeadLetters(): void
    {
        if (!config('eventbus.dead_letter.enabled')) return;

        foreach (config('eventbus.exchanges', []) as $exchange) {
            $dlx = config('eventbus.dead_letter.exchange_prefix', 'dlx.') . $exchange;

            $this->channel->exchange_declare(
                $dlx,
                AMQPExchangeType::TOPIC,
                false,
                true,
                false
            );

            $this->info("DLX: {$dlx}");
        }
    }

    protected function declareQueues(): void
    {
        $queues = $this->option('queue') ?: config('eventbus.queues', []);

        foreach ($queues as $queueConfig) {

            if (is_string($queueConfig)) {
                continue;
            }

            $queue = $queueConfig['name'];
            $exchange = $queueConfig['exchange'];
            $keys = $queueConfig['routing_keys'];

            $args = [];

            if (config('eventbus.dead_letter.enabled')) {
                $args = new AMQPTable([
                    'x-dead-letter-exchange' =>
                        config('eventbus.dead_letter.exchange_prefix', 'dlx.') . $exchange,
                    'x-message-ttl' =>
                        config('eventbus.dead_letter.ttl', 86400000),
                ]);
            }

            $this->channel->queue_declare(
                $queue,
                false,
                true,
                false,
                false,
                false,
                $args
            );

            foreach ($keys as $key) {
                $this->channel->queue_bind($queue, $exchange, $key);
            }

            $this->info("Queue ready: {$queue}");
        }
    }

    /**
     * CONSUME LOOP
     */
    protected function consume(): void
    {
        $queues = $this->option('queue')
            ?: array_column(config('eventbus.queues', []), 'name');

        $callback = function (AMQPMessage $message) {

            $start = microtime(true);

            $trace = [
                'routing_key' => $message->getRoutingKey(),
                'delivery_tag' => $message->getDeliveryTag(),
            ];

            try {
                $this->processor->process($message);

                $message->ack();

                $this->logPerformance($message, $start);

            } catch (Throwable $e) {

                Log::error('[EventBus] Processing failed', $trace + [
                        'error' => $e->getMessage(),
                    ]);

                $retryCount = $this->getRetryCount($message);
                $maxRetries = (int) config('eventbus.dead_letter.max_retries', 3);

                if ($retryCount >= $maxRetries) {

                    Log::critical('[EventBus] Max retries exceeded → DLX', $trace + [
                            'retry_count' => $retryCount,
                        ]);

                    $message->ack();
                    return;
                }

                $this->retryMessage($message, $retryCount + 1);

                $message->ack();
            }
        };

        foreach ($queues as $queue) {

            $queueName = is_array($queue) ? $queue['name'] : $queue;

            $this->channel->basic_consume(
                $queueName,
                '',
                false,
                false,
                false,
                false,
                $callback
            );

            $this->info("Consuming: {$queueName}");
        }

        $this->attachSignalHandlers();

        while ($this->running && $this->channel->is_consuming()) {

            try {
                $this->channel->wait(null, false, 5);

            } catch (AMQPTimeoutException) {
                // heartbeat idle
            } catch (Throwable $e) {

                Log::error('[EventBus] Consumer loop error', [
                    'error' => $e->getMessage(),
                ]);

                sleep(2);
                $this->reconnect();
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * RETRY SYSTEM (safe republish)
     */
    protected function retryMessage(AMQPMessage $message, int $retryCount): void
    {
        $headers = $message->get('application_headers')?->getNativeData() ?? [];

        $headers['x-retry-count'] = $retryCount;

        $new = new AMQPMessage($message->getBody(), [
            'delivery_mode' => 2,
            'application_headers' => new AMQPTable($headers),
        ]);

        $this->channel->basic_publish(
            $new,
            '', // default exchange (safe retry loop inside same queue)
            $message->getRoutingKey()
        );
    }

    /**
     * RETRY COUNT
     */
    protected function getRetryCount(AMQPMessage $message): int
    {
        return $message->get('application_headers')
            ?->getNativeData()['x-retry-count']
            ?? 0;
    }

    /**
     * RECONNECT HANDLING
     */
    protected function reconnect(): void
    {
        try {
            $this->connection?->close();
        } catch (Throwable) {}

        $this->connect();
    }

    /**
     * SIGNAL HANDLING
     */
    protected function attachSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) return;

        pcntl_signal(SIGTERM, fn () => $this->shutdown());
        pcntl_signal(SIGINT, fn () => $this->shutdown());
    }

    /**
     * PERFORMANCE LOGGING
     */
    protected function logPerformance(AMQPMessage $message, float $start): void
    {
        if (!config('eventbus.monitoring.enabled')) return;

        $duration = (microtime(true) - $start) * 1000;

        if ($duration > config('eventbus.monitoring.slow_event_threshold', 5000)) {
            Log::warning('[EventBus] Slow event', [
                'routing_key' => $message->getRoutingKey(),
                'duration_ms' => round($duration, 2),
            ]);
        }
    }

    /**
     * SHUTDOWN
     */
    protected function shutdown(): void
    {
        $this->running = false;

        try {
            $this->channel?->close();
            $this->connection?->close();

            $this->info('🛑 EventBus consumer stopped');

        } catch (Throwable $e) {
            Log::warning('[EventBus] Shutdown error', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function __destruct()
    {
        $this->shutdown();
    }
}