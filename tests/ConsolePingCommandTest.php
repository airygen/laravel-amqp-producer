<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Airygen\RabbitMQ\RabbitMQServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;

final class ConsolePingCommandTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [RabbitMQServiceProvider::class];
    }

    private function fakeManager(array $failing): ConnectionManagerInterface
    {
        return new class ($failing) implements ConnectionManagerInterface
        {
            public function __construct(private array $failing)
            {
            }

            public function get(): AbstractConnection
            {
                return new class extends AbstractConnection
                {
                    public function __construct()
                    {
                    }

                    public function connect()
                    {
                    }

                    public function channel($channel_id = null)
                    {
                    }

                    public function close(
                        $reply_code = 0,
                        $reply_text = '',
                        $method_sig = [0, 0]
                    ) {
                    }

                    public function reconnect()
                    {
                    }
                };
            }

            public function withChannel(
                callable $fn,
                string $connectionName = 'default'
            ) {
                if (in_array($connectionName, $this->failing, true)) {
                    throw new \RuntimeException('simulated failure');
                }
                $channel = new class extends AMQPChannel
                {
                    public function __construct()
                    {
                    }
                };

                return $fn($channel);
            }

            public function reset(): void
            {
            }
        };
    }

    public function testPingAllAndSingle(): void
    {
        config()->set('amqp.connections', [
            'default' => [
                'host' => 'x',
                'port' => 5672,
                'user' => 'u',
                'password' => 'p',
                'vhost' => '/',
            ],
            'failing' => [
                'host' => 'x',
                'port' => 5672,
                'user' => 'u',
                'password' => 'p',
                'vhost' => '/',
            ],
        ]);

        $this->app->singleton(
            ConnectionManagerInterface::class,
            fn () => $this->fakeManager(['failing'])
        );

        $codeAll = Artisan::call('rabbitmq:ping');
        $outAll = Artisan::output();
        $this->assertSame(1, $codeAll);
        $this->assertStringContainsString('[OK] default', $outAll);
        $this->assertStringContainsString('[FAIL] failing', $outAll);

        $codeDefault = Artisan::call(
            'rabbitmq:ping',
            ['connection' => 'default']
        );
        $this->assertSame(0, $codeDefault);
        $codeFail = Artisan::call(
            'rabbitmq:ping',
            ['connection' => 'failing']
        );
        $this->assertSame(1, $codeFail);
    }
}
