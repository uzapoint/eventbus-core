<?php

namespace Uzapoint\EventBus\Console;

use Throwable;
use PhpAmqpLib\Wire\AMQPTable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use Uzapoint\EventBus\EventProcessor;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class ConsumeRabbitMQEvents extends Command
{
    protected $signature = 'eventbus:consume
                            {--queue=* : Consume specific queue(s)}
                            {--exchange=* : Declare specific exchange(s)}';

    protected $description = 'Start the EventBus RabbitMQ consumer';

    protected ?AMQPStreamConnection $connection = null;
    protected $channel = null;

    public function __construct(
        protected EventProcessor $processor
    ) {
        parent::__construct();
    }

    /**
     * Entry point.
     */
    public function handle(): void
    {
        $this->info('🚀 Starting EventBus Consumer...');

        $this->connect();
        $this->declareExchanges();
        $this->declareQueues();
        $this->consume();

        $this->shutdown();
    }

    /**
     * Establish AMQP connection.
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

            $prefetch = config('eventbus.consumer.prefetch_count', 1);
            $this->channel->basic_qos(null, $prefetch, null);

            $this->info('✅ Connected to RabbitMQ');

        } catch (Throwable $e) {
            $this->error('❌ Failed to connect to RabbitMQ');
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
        }
    }

    /**
     * Declare queues + bindings.
     */
    protected function declareQueues(): void
    {
        $queueConfigs = config('eventbus.queues', []);

        foreach ($queueConfigs as $queueConfig) {

            $arguments = null;

            if (config('eventbus.dead_letter.enabled')) {

                $dlxPrefix = config('eventbus.dead_letter.exchange_prefix', 'dlx.');

                $arguments = new AMQPTable([
                    'x-dead-letter-exchange' =>
                        $dlxPrefix . $queueConfig['exchange'],
                    'x-message-ttl' =>
                        config('eventbus.dead_letter.ttl', 86400000),
                ]);
            }

            $this->channel->queue_declare(
                $queueConfig['name'],
                false,
                true,
                false,
                false,
                false,
                $arguments
            );

            foreach ($queueConfig['routing_keys'] as $routingKey) {
                $this->channel->queue_bind(
                    $queueConfig['name'],
                    $queueConfig['exchange'],
                    $routingKey
                );
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

        foreach ($queues as $queue) {

            $this->channel->basic_consume(
                $queue,
                '',
                false,
                false,
                false,
                false,
                function (AMQPMessage $message) {

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
                }
            );

            $this->info("📥 Consuming from queue: {$queue}");
        }

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
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

            $this->channel?->close();
            $this->connection?->close();

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