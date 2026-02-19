<?php

namespace Uzapoint\EventBus\Console;

use PhpAmqpLib\Channel\AbstractChannel;
use PhpAmqpLib\Channel\AMQPChannel;
use Throwable;
use PhpAmqpLib\Wire\AMQPTable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use Uzapoint\EventBus\EventProcessor;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Uzapoint\EventBus\EventRegistry;

class ConsumeRabbitMQEvents extends Command
{
    protected $signature = 'eventbus:consume
                            {--queue=* : Consume specific queue(s)}
                            {--exchange=* : Declare specific exchange(s)}';

    protected $description = 'Start the EventBus RabbitMQ consumer';

    protected ?AMQPStreamConnection $connection = null;
    protected AbstractChannel|AMQPChannel|null $channel = null;

    public function __construct(
        protected EventProcessor $processor,
        protected EventRegistry  $registry
    )
    {
        parent::__construct();
    }

    /**
     * Entry point.
     */
    public function handle(): void
    {
        $this->info('🚀 Starting EventBus Consumer...');

        $this->setUpConnection();
        $this->setUpDeadLetterExchange();
        $this->declareExchanges();
        $this->declareQueues();
        $this->consume();
    }

    /**
     * Establish AMQP connection.
     */
    protected function setUpConnection(): void
    {
        try {
            $this->connection = new AMQPStreamConnection(
                config('queue.connections.rabbitmq.host'),
                (int)config('queue.connections.rabbitmq.port'),
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

            $prefetch = config('eventbus.consumer.prefetch_count', 1);
            $this->channel->basic_qos((int)null, $prefetch, null);

            $this->info('✅ Connected to RabbitMQ');

        } catch (Throwable $e) {
            $this->error('❌ Failed to connect to RabbitMQ' . $e->getMessage() . PHP_EOL);
            Log::critical('[EventBus] Connection failed', [
                'error' => $e->getMessage(),
            ]);
            exit(1);
        }
    }

    /**
     * Declare exchanges.
     */
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

            $this->info("Exchange Declared: {$exchange}");
        }
    }

    protected function setUpDeadLetterExchange(): void
    {
        if (!config('eventbus.dead_letter.enabled')) return;

        foreach (config('eventbus.exchanges', []) as $exchange) {
            $dlxPrefix = config('eventbus.dead_letter.exchange_prefix', 'dlx.');
            $this->channel->exchange_declare(
                $dlxPrefix . $exchange,
                AMQPExchangeType::TOPIC,
                false,
                true,
                false
            );

            $this->info("Created dead letter exchange: {$dlxPrefix}{$exchange}");
        }
    }

    /**
     * Declare queues + bindings.
     */
    protected function declareQueues(): void
    {
        $queueConfigs = $this->option('queue') ?: config('eventbus.queues', []);

        foreach ($queueConfigs as $queueConfig) {
            if (is_string($queueConfig)) {
                $this->info("Skipping queue declaration: {$queueConfig}");
                continue;
            }

            $queueName = $queueConfig['name'];
            $exchange = $queueConfig['exchange'];
            $routingKeys = $queueConfig['routing_keys'];
            $arguments = [];

            if (config('eventbus.dead_letter.enabled')) {
                $dlxPrefix = config('eventbus.dead_letter.exchange_prefix', 'dlx.');
                $arguments = new AMQPTable([
                    'x-dead-letter-exchange' =>
                        $dlxPrefix . $exchange,
                    'x-message-ttl' =>
                        config('eventbus.dead_letter.ttl', 86400000),
                ]);
            }

            $this->channel->queue_declare(
                $queueName,
                false,
                true,
                false,
                false,
                false,
                $arguments
            );

            foreach ($routingKeys as $routingKey) {
                $this->channel->queue_bind(
                    $queueName,
                    $exchange,
                    $routingKey
                );

                $this->info("Queue bound: {$queueName} -> {$exchange}:{$routingKey}");
            }
        }
    }

    /**
     * Start consuming.
     */
    protected function consume(): void
    {
        $queues = $this->option('queue')
            ?: array_column(config('eventbus.queues', []), 'name');

        $callback = function (AMQPMessage $message) {
            $startTime = microtime(true);

            try {
                $this->processor->process($message);
                $message->ack();
            } catch (Throwable $e) {
                Log::error('[EventBus] Event processing failed', [
                    'routing_key' => $message->getRoutingKey(),
                    'error' => $e->getMessage(),
                ]);

                if (config('eventbus.consumer.retry_on_failure', false)) {
                    $message->nack(false, true);
                    return;
                }

                // Prevent poison loops
                $message->ack();
            }

            $this->monitorPerformance($message, $startTime);
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

            $this->info("📥 Consuming from queue: {$queue}");
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, fn () => $this->shutdown());
            pcntl_signal(SIGINT, fn () => $this->shutdown());
        }

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Monitor slow events.
     */
    protected function monitorPerformance(AMQPMessage $message, float $startTime): void
    {
        if (!config('eventbus.monitoring.enabled', false)) {
            return;
        }

        $duration = (microtime(true) - $startTime) * 1000;

        if ($duration > config('eventbus.monitoring.slow_event_threshold', 5000)) {
            Log::warning('[EventBus] Slow event detected', [
                'routing_key' => $message->getRoutingKey(),
                'duration_ms' => round($duration, 2),
            ]);
        }
    }

    /**
     * Graceful shutdown.
     */
    protected function shutdown(): void
    {
        try {

            if ($this->channel?->is_open())
                $this->channel?->close();

            if ($this->connection?->isConnected())
                $this->connection?->close();

            if(!is_null($this->output))
                $this->info('🛑 EventBus consumer stopped gracefully.');

        } catch (Throwable $e) {

            Log::warning('[EventBus] Shutdown cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Destructor safety net.
     */
    public function __destruct()
    {
        $this->shutdown();
    }
}