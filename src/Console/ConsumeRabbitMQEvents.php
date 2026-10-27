<?php

namespace Uzapoint\EventBus\Console;

use Throwable;
use OutOfBoundsException;
use RuntimeException;
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

/**
 * EventBus RabbitMQ consumer.
 *
 * Design rule: ANY connection/channel problem is fatal. The process logs it, exits non-zero
 * and the supervisor starts a clean one (fresh connection, topology and consumers).
 *
 * Liveness: the loop touches a file at least every ~5s. A watchdog (ops/eventbus-watchdog.sh)
 * kills a process whose file goes stale, which covers a handler that blocks forever.
 */
class ConsumeRabbitMQEvents extends Command
{
    protected $signature = 'eventbus:consume
        {--queue=* : Consume specific queue(s)}
        {--exchange=* : Declare specific exchange(s)}';

    protected $description = 'EventBus RabbitMQ consumer';

    protected ?AMQPStreamConnection $connection = null;
    protected AbstractChannel|AMQPChannel|null $channel = null;

    protected bool $running = true;
    protected bool $closed = false;

    /** consumer tag => queue name (needed to retry/park a failed message on its own queue) */
    protected array $consumerQueues = [];

    protected ?string $livenessFile = null;

    public function __construct(
        protected EventProcessor $processor,
        protected EventRegistry $registry
    ) {
        parent::__construct();
    }

    /**
     * ENTRY POINT
     */
    public function handle(): int
    {
        $this->info('🚀 Starting EventBus Consumer...');

        if (empty(config('eventbus.queues', []))) {
            $this->info('No queues configured. Exiting.');
            return self::SUCCESS;
        }

        // Must match the watchdog's EVENTBUS_LIVENESS_DIR. The env var wins, then the config key
        // (an app's own config/eventbus.php may not define it), then the app's storage folder.
        $livenessDir = rtrim((string) (
            env('EVENTBUS_LIVENESS_DIR')
            ?: config('eventbus.consumer.liveness_dir')
            ?: storage_path('framework/eventbus')
        ), '/');

        if (!is_dir($livenessDir) && !@mkdir($livenessDir, 0775, true) && !is_dir($livenessDir)) {
            Log::critical('[EventBus] Cannot create liveness directory, the watchdog would be blind', [
                'dir' => $livenessDir,
            ]);
        }

        $this->livenessFile = $livenessDir . '/eventbus-' . getmypid() . '.alive';

        try {
            $this->connect();
            $this->declareTopology();
            $this->consume();
        } catch (Throwable $e) {
            Log::critical('[EventBus] Consumer stopped, exiting so the supervisor restarts it', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            $this->shutdown();
            sleep(2); // throttle restarts if the broker is down

            return self::FAILURE;
        }

        $this->shutdown();

        return self::SUCCESS;
    }

    /**
     * CONNECTION
     */
    protected function connect(): void
    {
        $this->connection = new AMQPStreamConnection(
            config('queue.connections.rabbitmq.host'),
            (int) config('queue.connections.rabbitmq.port'),
            config('queue.connections.rabbitmq.user'),
            config('queue.connections.rabbitmq.password'),
            config('queue.connections.rabbitmq.vhost'),
            false,
            'AMQPLAIN',
            null,
            'en_US',
            (float) config('eventbus.consumer.connection_timeout', 3),
            (float) config('eventbus.consumer.read_write_timeout', 130), // must be >= 2 x heartbeat
            null,   // stream context
            true,   // TCP keepalive
            (int) config('eventbus.consumer.heartbeat', 60)
        );

        $this->channel = $this->connection->channel();
        $this->channel->basic_qos(0, (int) config('eventbus.consumer.prefetch_count', 1), false);

        $this->info('✅ Connected to RabbitMQ');
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
            $this->channel->exchange_declare($exchange, AMQPExchangeType::TOPIC, false, true, false);
            $this->info("Exchange: {$exchange}");
        }
    }

    protected function declareDeadLetters(): void
    {
        if (!config('eventbus.dead_letter.enabled'))
            return;

        foreach (config('eventbus.exchanges', []) as $exchange) {
            $dlx = config('eventbus.dead_letter.exchange_prefix', 'dlx.') . $exchange;

            $this->channel->exchange_declare($dlx, AMQPExchangeType::TOPIC, false, true, false);
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

            // NOTE: do not change these arguments on a queue that already exists. RabbitMQ
            // answers PRECONDITION_FAILED and closes the channel.
            if (config('eventbus.dead_letter.enabled')) {
                $args = new AMQPTable([
                    'x-dead-letter-exchange' =>
                        config('eventbus.dead_letter.exchange_prefix', 'dlx.') . $exchange,
                    'x-message-ttl' =>
                        config('eventbus.dead_letter.ttl', 86400000),
                ]);
            }

            $this->channel->queue_declare($queue, false, true, false, false, false, $args);

            foreach ($keys as $key) {
                $this->channel->queue_bind($queue, $exchange, $key);
            }

            // Parking queue for messages that exhausted their retries (plain queue, no args).
            $this->declareParkingQueue($queue);

            $this->info("Queue ready: {$queue}");
        }
    }

    protected function parkingQueueName(string $queue): string
    {
        return $queue . '.dlq';
    }

    protected function declareParkingQueue(string $queue): void
    {
        $this->channel->queue_declare($this->parkingQueueName($queue), false, true, false, false);
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

                $this->handleFailure($message, $e);
            }
        };

        foreach ($queues as $queue) {

            $queueName = is_array($queue) ? $queue['name'] : $queue;

            $tag = $this->channel->basic_consume($queueName, '', false, false, false, false, $callback);
            $this->consumerQueues[$tag] = $queueName;

            $this->info("Consuming: {$queueName}");
        }

        $this->attachSignalHandlers();

        while ($this->running) {

            $this->touchLiveness();

            if (!$this->channel->is_consuming()) {
                throw new RuntimeException('No active consumers on the channel');
            }

            try {
                $this->channel->wait(null, false, 5);
            } catch (AMQPTimeoutException) {
                // idle: nothing delivered within 5s (heartbeats are serviced inside wait())
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * FAILURE HANDLING
     *
     * Retry = republish straight onto the message's own queue through the default exchange
     * (routing key = queue name) and remember the ORIGINAL routing key in a header so the
     * processor can still resolve the handler. After max retries the message is parked in
     * "<queue>.dlq" instead of being dropped. The original is acked only after the
     * republish succeeded.
     */
    protected function handleFailure(AMQPMessage $message, Throwable $e): void
    {
        $queue = $this->consumerQueues[$message->delivery_info['consumer_tag'] ?? ''] ?? null;

        if ($queue === null) {
            Log::critical('[EventBus] Cannot resolve queue for failed message, requeueing', [
                'routing_key' => $message->getRoutingKey(),
            ]);
            sleep(1);
            $message->reject(true);
            return;
        }

        $headers = $this->headers($message);
        $retryCount = (int) ($headers['x-retry-count'] ?? 0);
        $maxRetries = (int) config('eventbus.dead_letter.max_retries', 3);

        $headers['x-original-routing-key'] = $headers['x-original-routing-key'] ?? $message->getRoutingKey();
        $headers['x-last-error'] = mb_substr($e->getMessage(), 0, 500);

        if ($retryCount >= $maxRetries) {
            $headers['x-retry-count'] = $retryCount;
            $this->declareParkingQueue($queue);
            $this->publishRaw($message, $this->parkingQueueName($queue), $headers);

            Log::critical('[EventBus] Max retries exceeded, message parked', [
                'queue' => $queue,
                'parked_in' => $this->parkingQueueName($queue),
                'routing_key' => $headers['x-original-routing-key'],
                'retry_count' => $retryCount,
                'error' => $e->getMessage(),
            ]);
        } else {
            $headers['x-retry-count'] = $retryCount + 1;
            sleep(min(2 * ($retryCount + 1), 10)); // short backoff; heartbeat is 60s
            $this->publishRaw($message, $queue, $headers);
        }

        $message->ack();
    }

    protected function publishRaw(AMQPMessage $message, string $queue, array $headers): void
    {
        $this->channel->basic_publish(
            new AMQPMessage($message->getBody(), [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable($headers),
            ]),
            '',     // default exchange: routing key == queue name
            $queue
        );
    }

    /**
     * Native application headers of a message. A message published without headers has no
     * 'application_headers' property and AMQPMessage::get() throws for it, so read it safely.
     */
    protected function headers(AMQPMessage $message): array
    {
        try {
            $table = $message->get('application_headers');
        } catch (OutOfBoundsException) {
            return [];
        }

        return $table instanceof AMQPTable ? $table->getNativeData() : [];
    }

    /**
     * LIVENESS (read by ops/eventbus-watchdog.sh)
     */
    protected function touchLiveness(): void
    {
        if ($this->livenessFile) {
            @touch($this->livenessFile);
        }
    }

    /**
     * SIGNAL HANDLING
     */
    protected function attachSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal'))
            return;

        pcntl_signal(SIGTERM, fn() => $this->running = false);
        pcntl_signal(SIGINT, fn() => $this->running = false);
    }

    /**
     * PERFORMANCE LOGGING
     */
    protected function logPerformance(AMQPMessage $message, float $start): void
    {
        if (!config('eventbus.monitoring.enabled'))
            return;

        $duration = (microtime(true) - $start) * 1000;

        if ($duration > config('eventbus.monitoring.slow_event_threshold', 5000)) {
            Log::warning('[EventBus] Slow event', [
                'routing_key' => $message->getRoutingKey(),
                'duration_ms' => round($duration, 2),
            ]);
        }
    }

    /**
     * SHUTDOWN (idempotent)
     */
    protected function shutdown(): void
    {
        $this->running = false;

        if ($this->closed)
            return;
        $this->closed = true;

        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (Throwable $e) {
            Log::warning('[EventBus] Shutdown error', ['error' => $e->getMessage()]);
        }

        if ($this->livenessFile) {
            @unlink($this->livenessFile);
        }
    }

    public function __destruct()
    {
        $this->shutdown();
    }
}
