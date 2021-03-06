<?php

namespace Chocofamilyme\LaravelPubSub\Providers;

use Chocofamilyme\LaravelPubSub\Amqp\Amqp;
use Chocofamilyme\LaravelPubSub\Commands\EventListenCommand;
use Chocofamilyme\LaravelPubSub\Events\Dispatcher;
use Chocofamilyme\LaravelPubSub\Listener;
use Chocofamilyme\LaravelPubSub\Queue\Connectors\RabbitMQConnector;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\QueueManager;
use Illuminate\Contracts\Queue\Factory as QueueFactoryContract;
use VladimirYuldashev\LaravelQueueRabbitMQ\LaravelQueueRabbitMQServiceProvider;

/**
 * Class PubSubServiceProvider
 *
 * @package Chocofamilyme\LaravelPubSub\Providers
 */
class PubSubServiceProvider extends LaravelQueueRabbitMQServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        parent::register();

        // Merge our config with application config
        $this->mergeConfigFrom(
            __DIR__.'/../config/queue.php', 'queue'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../config/pubsub.php', 'pubsub'
        );

        if ($this->app->runningInConsole()) {
            $this->app->singleton('rabbitmq.listener', function () {
                $isDownForMaintenance = function () {
                    return $this->app->isDownForMaintenance();
                };

                return new Listener(
                    $this->app['queue'],
                    $this->app['events'],
                    $this->app[ExceptionHandler::class],
                    $isDownForMaintenance
                );
            });

            $this->app->singleton(EventListenCommand::class, static function ($app) {
                return new EventListenCommand(
                    $app['rabbitmq.listener'],
                    $app['cache.store']
                );
            });

            // Register artisan commands
            $this->commands([
                EventListenCommand::class,
            ]);
        }

        $this->app->singleton('events', function ($app) {
            return (new Dispatcher($app))->setQueueResolver(function () use ($app) {
                return $app->make(QueueFactoryContract::class);
            });
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Config
        $this->publishes([
            __DIR__.'/../config/pubsub.php' => config_path('pubsub.php'),
        ]);

        // Add class and it's facade
        $this->app->bind('Amqp', function ($app) {
            return new Amqp($app['queue']);
        });

        if (!class_exists('Amqp')) {
            class_alias(\Chocofamilyme\LaravelPubSub\Amqp\AmqpFacade::class, 'Amqp');
        }

        /** @var QueueManager $queue */
        $queue = $this->app['queue'];

        $queue->addConnector('rabbitmq', function () {
            return new RabbitMQConnector($this->app['events']);
        });
    }

    /**
     *
     */
    public function provides()
    {
        return ['Amqp'];
    }
}
