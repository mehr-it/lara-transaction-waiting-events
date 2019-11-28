<?php


	namespace MehrIt\LaraTransactionWaitingEvents\Provider;


	use Illuminate\Contracts\Queue\Factory as QueueFactoryContract;
	use Illuminate\Events\Dispatcher;
	use Illuminate\Support\ServiceProvider;
	use MehrIt\LaraTransactionWaitingEvents\EventDispatcher;

	class TransactionWaitingEventsServiceProvider extends ServiceProvider
	{

		/**
		 * @inheritDoc
		 */
		public function register() {

			// inject our own event dispatcher
			$this->app->extend('events', function($x, $app) {
				return (new EventDispatcher($app))->setQueueResolver(function () use ($app) {
					return $app->make(QueueFactoryContract::class);
				});
			});

			$this->app->extend(Dispatcher::class, function () {
				return $this->app['events'];
			});

			$this->app->extend(\Illuminate\Contracts\Events\Dispatcher::class, function () {
				return $this->app['events'];
			});

			$this->app->singleton(EventDispatcher::class, function () {
				return $this->app['events'];
			});

		}

	}