<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Support;

use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Airygen\RabbitMQ\Contracts\PidProviderInterface;
use Airygen\RabbitMQ\Factories\ConnectionFactory;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use Throwable;

final class ConnectionManager implements ConnectionManagerInterface
{
    /** @var array<string, AbstractConnection|null> */
    private array $connections = [];

    /** @var array<string, AMQPChannel> Reusable open channels keyed by connection name */
    private array $channels = [];

    /** @var array<string,int> Usage counts for reusable channels */
    private array $channelUses = [];

    /** Maximum times a single channel can be reused before forced recycle */
    private int $maxChannelUses;

    /** Whether to reuse a single channel per connection instead of opening/closing every call */
    private bool $reuseChannel;

    /** Original full AMQP config (may contain 'connections' & global 'options') */
    private array $config;

    /** Extracted connections definitions */
    private array $connectionDefinitions;

    /** PID at the time this manager was instantiated (for fork detection in Octane/Swoole) */
    private int $pid;

    public function __construct(
        private ConnectionFactory $factory,
        array $config,
        private ?PidProviderInterface $pidProvider = null
    ) {
        $this->pidProvider = $pidProvider ?? new SystemPidProvider();
        $this->pid = $this->pidProvider->getPid();
        $this->config = $config;
        $this->connectionDefinitions = $config['connections'] ?? $config; // backward compatibility
        $globalOptions = $config['options'] ?? [];
        $this->reuseChannel = (bool) ($globalOptions['reuse_channel'] ?? true);
        $this->maxChannelUses = max(1, (int) ($globalOptions['max_channel_uses'] ?? 5000));
    }

    public function get(string $name = 'default'): AbstractConnection
    {
        // Detect process fork (Octane/Swoole worker). If PID changed, dispose all stale resources.
        $currentPid = $this->pidProvider?->getPid() ?? getmypid();
        if ($currentPid !== $this->pid) {
            $this->reset();
            $this->pid = $currentPid;
        }

        if (
            ! isset($this->connections[$name])
            || $this->connections[$name] === null
            || ! $this->connections[$name]->isConnected()
        ) {
            $config = $this->connectionDefinitions[$name] ?? $this->connectionDefinitions['default'] ?? [];
            $this->connections[$name] = $this->factory->create($config);
        }

        return $this->connections[$name];
    }

    /**
     * @template T
     *
     * @param  callable(AMQPChannel):T  $fn
     * @return T
     */
    public function withChannel(callable $fn, string $connectionName = 'default')
    {
        $conn = $this->get($connectionName);

        // Resolve per-connection overrides if provided
        $connCfg = $this->connectionDefinitions[$connectionName] ?? ($this->connectionDefinitions['default'] ?? []);
        $reuse = (bool) ($connCfg['options']['reuse_channel'] ?? $this->reuseChannel);
        $maxUses = max(1, (int) ($connCfg['options']['max_channel_uses'] ?? $this->maxChannelUses));

        if ($reuse) {
            $channel = $this->acquireReusableChannel($connectionName, $conn, $maxUses);
            try {
                return $fn($channel);
            } finally {
                $this->releaseReusableChannel($connectionName, $channel, $maxUses);
            }
        }

        // Non-reuse path: open, run, close each time (original behavior)
        $channel = $conn->channel();
        try {
            return $fn($channel);
        } finally {
            try {
                if ($channel->is_open()) {
                    $channel->close();
                }
            } catch (Throwable) {
            }
        }
    }

    private function acquireReusableChannel(string $name, AbstractConnection $conn, int $maxUses): AMQPChannel
    {
        if (isset($this->channels[$name])) {
            $ch = $this->channels[$name];
            if ($ch->is_open() && ($this->channelUses[$name] ?? 0) < $maxUses) {
                return $ch;
            }
            // stale or exceeded use count -> dispose
            try {
                if ($ch->is_open()) {
                    $ch->close();
                }
            } catch (Throwable) {
            }
            unset($this->channels[$name], $this->channelUses[$name]);
        }
        $ch = $conn->channel();
        $this->channels[$name] = $ch;
        $this->channelUses[$name] = 0;

        return $ch;
    }

    private function releaseReusableChannel(string $name, AMQPChannel $channel, int $maxUses): void
    {
        // Increment usage; if exceeded or closed externally, drop from pool so next call recreates.
        if (! isset($this->channels[$name])) {
            return; // not tracked
        }
        $this->channelUses[$name]++;
        if (! $channel->is_open() || $this->channelUses[$name] >= $maxUses) {
            try {
                if ($channel->is_open()) {
                    $channel->close();
                }
            } catch (Throwable) {
            }
            unset($this->channels[$name], $this->channelUses[$name]);
        }
    }

    public function reset(?string $name = null): void
    {
        if ($name === null) {
            // 明確要求全部清除才會走全域 reset，避免傳遞 null (如 batchPublish 內部) 誤清除所有連線。
            foreach ($this->connections as $key => $conn) {
                if ($conn) {
                    try {
                        $conn->close();
                    } catch (Throwable) {
                    }
                    $this->connections[$key] = null;
                }
            }
            foreach ($this->channels as $key => $ch) {
                try {
                    if ($ch->is_open()) {
                        $ch->close();
                    }
                } catch (Throwable) {
                }
                unset($this->channels[$key], $this->channelUses[$key]);
            }

            return;
        }
        if ($name !== null) {
            if (isset($this->connections[$name]) && $this->connections[$name]) {
                try {
                    $this->connections[$name]->close();
                } catch (Throwable) {
                }
                $this->connections[$name] = null;
            }
            if (isset($this->channels[$name])) {
                try {
                    if ($this->channels[$name]->is_open()) {
                        $this->channels[$name]->close();
                    }
                } catch (Throwable) {
                }
                unset($this->channels[$name], $this->channelUses[$name]);
            }
        }
    }
}
