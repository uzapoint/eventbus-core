<?php

namespace Uzapoint\EventBus;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AbstractChannel;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Connection\AMQPStreamConnection;

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

        $this->connect();

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

        $this->channel->exchange_declare(
            $exchange,
            AMQPExchangeType::TOPIC,
            false,
            true,
            false
        );

        $this->channel->basic_publish($message, $exchange, $routingKey);
        $this->channel->wait_for_pending_acks_returns();
    }

    /**
     * @throws \Exception
     */
    protected function connect(): void
    {
        if ($this->connection?->isConnected()) return;

        $this->connection = new AMQPStreamConnection(
            config('queue.connections.rabbitmq.host'),
            config('queue.connections.rabbitmq.port'),
            config('queue.connections.rabbitmq.user'),
            config('queue.connections.rabbitmq.password'),
            config('queue.connections.rabbitmq.vhost')
        );

        $this->channel = $this->connection->channel();
        $this->channel->confirm_select();
    }
}