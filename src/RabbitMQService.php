<?php

namespace Uzapoint\EventBus;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AbstractChannel;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

/**
 * Publisher. It lives as a singleton inside long-running workers (the eventbus consumer runs
 * handlers that publish), so it must never be able to block them forever:
 *  - every network read/write is bounded (read_write_timeout),
 *  - the broker confirm is awaited with a timeout (the library default is "wait forever"),
 *  - after ANY failure the connection is thrown away, so the next publish starts clean.
 */
class RabbitMQService
{
    protected ?AMQPStreamConnection $connection = null;
    protected AbstractChannel|AMQPChannel|null $channel = null;

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    public function publish(
        string $exchange,
        string $routingKey,
        array $data,
        array $headers = []
    ): void {

        $id = $headers['idempotency_key'] ?? Str::uuid();

        $payload = json_encode([
            'meta' => [
                'id' => $id,
                'type' => $routingKey,
                'source' => config('app.name'),
                'timestamp' => now()->toIso8601String(),
            ],
            'data' => $data,
        ], JSON_THROW_ON_ERROR);

        $message = new AMQPMessage($payload, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        try {
            $this->connect();

            $this->channel->exchange_declare(
                $exchange,
                AMQPExchangeType::TOPIC,
                false,
                true,
                false
            );

            $this->channel->basic_publish($message, $exchange, $routingKey);
            $this->channel->wait_for_pending_acks_returns(
                (float) config('eventbus.publisher.confirm_timeout', 10)
            );
        } catch (Throwable $e) {
            Log::error('[EventBus] Publish failed, dropping publisher connection', [
                'exchange' => $exchange,
                'routing_key' => $routingKey,
                'error' => $e->getMessage(),
            ]);

            $this->reset();

            throw $e;
        }
    }

    /**
     * @throws \Exception
     */
    protected function connect(): void
    {
        if ($this->connection?->isConnected() && $this->channel?->is_open()) {
            return;
        }

        $this->reset();

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
            (float) config('eventbus.publisher.connection_timeout', 3),
            (float) config('eventbus.publisher.read_write_timeout', 15),
            null,
            true,  // TCP keepalive
            0      // no heartbeat: the publisher is mostly idle, bounded reads are what protect it
        );

        $this->channel = $this->connection->channel();
        $this->channel->confirm_select();
    }

    protected function reset(): void
    {
        try {
            $this->channel?->close();
        } catch (Throwable) {
        }

        try {
            $this->connection?->close();
        } catch (Throwable) {
        }

        $this->channel = null;
        $this->connection = null;
    }
}
