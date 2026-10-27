<?php

namespace Uzapoint\EventBus\Console;

use Composer\InstalledVersions;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use Uzapoint\EventBus\EventProcessor;
use Uzapoint\EventBus\EventRegistry;

/**
 * Read-only audit of this service's EventBus setup. It changes nothing: it only reads config,
 * reads the filesystem and (unless --skip-broker) opens short, bounded connections to Redis and
 * RabbitMQ. Exit code is non-zero when something is wrong, so it can gate a deploy or a cron.
 */
class DoctorCommand extends Command
{
    protected $signature = 'eventbus:doctor {--skip-broker : Do not connect to Redis or RabbitMQ}';

    protected $description = 'Read-only health and configuration audit of this service\'s EventBus setup';

    private int $problems = 0;
    private int $cautions = 0;

    public function handle(): int
    {
        $this->line('<options=bold>EventBus doctor</> — ' . config('app.name') . ' [' . config('app.env') . ']');

        $this->section('Package and runtime', fn() => $this->checkRuntime());
        $this->section('Processor', fn() => $this->checkProcessor());
        $this->section('Topology and handlers', fn() => $this->checkTopology());
        $this->section('Liveness (watchdog)', fn() => $this->checkLiveness());
        $this->section('Queue and Horizon settings', fn() => $this->checkQueueSettings());
        $this->section('Job classes', fn() => $this->checkJobClasses());

        if (!$this->option('skip-broker')) {
            $this->section('Redis', fn() => $this->checkRedis());
            $this->section('RabbitMQ', fn() => $this->checkBroker());
        }

        $this->newLine();
        $this->line("Result: <fg=" . ($this->problems ? 'red' : 'green') . ">{$this->problems} problem(s)</>, {$this->cautions} caution(s).");

        return $this->problems ? self::FAILURE : self::SUCCESS;
    }

    /* ------------------------------------------------------------------ checks */

    private function checkRuntime(): void
    {
        try {
            $this->pass('eventbus-core ' . InstalledVersions::getPrettyVersion('uzapoint/eventbus-core'));
        } catch (Throwable) {
            $this->caution('Could not read the installed eventbus-core version.');
        }

        $this->pass('PHP ' . PHP_VERSION . ', pcntl ' . (function_exists('pcntl_signal') ? 'available' : 'MISSING'));
        if (!function_exists('pcntl_signal')) {
            $this->problem('pcntl is missing: the consumer cannot shut down gracefully.');
        }

        if (config('app.debug')) {
            $this->caution('APP_DEBUG is true. Debug pages expose stack traces and request data; disable it in production.');
        }

        if (config('app.env') !== 'production') {
            $this->caution("APP_ENV is '" . config('app.env') . "'. Horizon uses the matching block of config/horizon.php for process counts.");
        }
    }

    private function checkProcessor(): void
    {
        $bound = get_class(app(EventProcessor::class));

        $bound === EventProcessor::class
            ? $this->pass('Package EventProcessor is in use (two-phase idempotency, retry-safe routing key).')
            : $this->problem("EventProcessor is overridden by {$bound}. Its idempotency and retry behaviour are not the package's; remove the binding unless it is deliberate.");
    }

    private function checkTopology(): void
    {
        $queues = config('eventbus.queues', []);

        if (!$queues) {
            $this->caution('No queues configured: eventbus:consume will exit immediately (fine for publish-only services).');
            return;
        }

        $registry = app(EventRegistry::class);
        $keys = 0;

        foreach ($queues as $queue) {
            foreach ($queue['routing_keys'] ?? [] as $key) {
                $keys++;
                $handler = $registry->getHandler($key) ?? config("eventbus.handlers.$key");

                if (!$handler) {
                    $this->caution("{$queue['name']}: routing key '$key' is bound but has no handler; its messages are acked and dropped.");
                } elseif (!class_exists($handler)) {
                    $this->problem("{$queue['name']}: handler for '$key' does not exist: $handler");
                }
            }
        }

        $this->pass(count($queues) . ' queue(s), ' . $keys . ' routing key(s) checked.');
    }

    private function checkLiveness(): void
    {
        $dir = ConsumeRabbitMQEvents::livenessDir();

        if (!is_dir($dir)) {
            $this->caution("Liveness directory does not exist yet ($dir). The consumer creates it on start.");
            return;
        }

        if (!is_writable($dir)) {
            $this->problem("Liveness directory is not writable: $dir");
            return;
        }

        $files = glob($dir . '/eventbus-*.alive') ?: [];
        $this->pass("Directory $dir is writable; " . count($files) . ' liveness file(s).');

        if (!$files) {
            $this->caution('No liveness files: consumers are not running, or they run a version older than v1.1.1.');
            return;
        }

        foreach ($files as $file) {
            $age = time() - (int) filemtime($file);
            if ($age > 60) {
                $this->caution(basename($file) . " last touched {$age}s ago (left over from a dead process, or the consumer is stuck).");
            }
        }
    }

    private function checkQueueSettings(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after', 0);

        if (config('horizon') && $retryAfter > 0) {
            $timeouts = [];
            foreach ((array) config('horizon.defaults', []) as $supervisor) {
                $timeouts[] = (int) ($supervisor['timeout'] ?? 0);
            }
            foreach ((array) config('horizon.environments', []) as $env) {
                foreach ((array) $env as $supervisor) {
                    $timeouts[] = (int) ($supervisor['timeout'] ?? 0);
                }
            }
            $maxTimeout = max($timeouts ?: [0]);

            $retryAfter > $maxTimeout
                ? $this->pass("retry_after ($retryAfter s) is above the longest Horizon worker timeout ($maxTimeout s).")
                : $this->problem("retry_after ($retryAfter s) must be greater than the Horizon worker timeout ($maxTimeout s), otherwise slow jobs run twice.");
        }

        $blockFor = config('queue.connections.redis.block_for');
        $readTimeout = config('database.redis.default.read_timeout');

        if ($readTimeout === null || (float) $readTimeout <= 0) {
            $this->caution('Redis read_timeout is not set: a stalled Redis socket can block a worker forever.');
        } elseif ($blockFor !== null && (float) $blockFor >= (float) $readTimeout) {
            $this->problem("Redis queue block_for ($blockFor s) must be below the Redis read_timeout ($readTimeout s).");
        } else {
            $this->pass("Redis read_timeout is {$readTimeout}s.");
        }
    }

    /** A job that redeclares Queueable's $afterCommit is a PHP fatal the moment the class loads. */
    private function checkJobClasses(): void
    {
        $bad = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            if (
                str_contains($code, 'Queueable')
                && preg_match('/^\s*(?:public|protected|private)\s+(?:\??bool\s+)?\$afterCommit\b/m', $code)
            ) {
                $bad[] = Str::after($file->getPathname(), base_path() . '/');
            }
        }

        $bad
            ? array_map(fn($f) => $this->problem("$f redeclares \$afterCommit; assign it in the constructor instead (fatal error when the class loads)."), $bad)
            : $this->pass('No job class redeclares $afterCommit.');
    }

    private function checkRedis(): void
    {
        $key = 'eventbus:doctor:' . Str::random(8);

        try {
            $first = Redis::set($key, 1, 'EX', 10, 'NX');
            $second = Redis::set($key, 1, 'EX', 10, 'NX');
            Redis::del($key);

            ($first && !$second)
                ? $this->pass('Redis reachable, SET NX behaves (client: ' . config('database.redis.client') . ').')
                : $this->problem('Redis SET NX did not behave as a lock (first=' . var_export($first, true) . ', second=' . var_export($second, true) . ').');
        } catch (Throwable $e) {
            $this->problem('Redis check failed: ' . $e->getMessage());
        }
    }

    private function checkBroker(): void
    {
        $cfg = config('queue.connections.rabbitmq');

        try {
            $connection = new AMQPStreamConnection(
                $cfg['host'],
                (int) $cfg['port'],
                $cfg['user'],
                $cfg['password'],
                $cfg['vhost'],
                false,
                'AMQPLAIN',
                null,
                'en_US',
                3.0,
                10.0,
                null,
                false,
                0
            );
        } catch (Throwable $e) {
            $this->problem('RabbitMQ connection failed: ' . $e->getMessage());
            return;
        }

        $this->pass("RabbitMQ reachable at {$cfg['host']}:{$cfg['port']}.");

        foreach (config('eventbus.queues', []) as $queue) {
            $name = $queue['name'];

            $stats = $this->inspectQueue($connection, $name);

            if ($stats !== null) {
                [$ready, $consumers] = $stats;
                $line = "$name: $ready ready, $consumers consumer(s)";
                $consumers < 1 ? $this->problem("$line. Nothing is consuming this queue.") : $this->pass($line . '.');
            } else {
                $this->caution("$name does not exist yet (created when the consumer first starts).");
            }

            if ($parked = $this->inspectQueue($connection, $name . '.dlq')) {
                $parked[0] > 0
                    ? $this->caution("{$name}.dlq holds {$parked[0]} parked message(s) that exhausted their retries.")
                    : $this->pass("{$name}.dlq is empty.");
            }
        }

        try {
            $connection->close();
        } catch (Throwable) {
        }
    }

    /** @return array{0:int,1:int}|null [messages ready, consumers], or null when the queue does not exist */
    private function inspectQueue(AMQPStreamConnection $connection, string $queue): ?array
    {
        $channel = null;

        try {
            $channel = $connection->channel(); // a passive declare of a missing queue closes its channel
            [, $ready, $consumers] = $channel->queue_declare($queue, true);

            return [(int) $ready, (int) $consumers];
        } catch (Throwable) {
            return null;
        } finally {
            try {
                $channel?->close();
            } catch (Throwable) {
            }
        }
    }

    /* ----------------------------------------------------------------- output */

    private function section(string $title, callable $check): void
    {
        $this->newLine();
        $this->line("<options=bold>$title</>");

        try {
            $check();
        } catch (Throwable $e) {
            $this->problem("$title check crashed: " . $e->getMessage());
        }
    }

    private function pass(string $message): void
    {
        $this->line("  <fg=green>✔</> $message");
    }

    private function caution(string $message): void
    {
        $this->cautions++;
        $this->line("  <fg=yellow>⚠</> $message");
    }

    private function problem(string $message): void
    {
        $this->problems++;
        $this->line("  <fg=red>✖</> $message");
    }
}
