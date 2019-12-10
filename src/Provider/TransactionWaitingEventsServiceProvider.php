<?php


	namespace MehrIt\LaraTransactionWaitingEvents\Provider;


	use Illuminate\Contracts\Queue\Factory as QueueFactoryContract;
	use Illuminate\Events\Dispatcher;
	use Illuminate\Support\ServiceProvider;
	use MehrIt\LaraTransactionWaitingEvents\EventDispatcher;

	class TransactionWaitingEventsServiceProvider extends ServiceProvider
	{
		public function boot() {
			$this->publishes([
				 __DIR__ . '/../../config/config.php' => config_path('transaction-waiting-events.php'),
			]);
		}

		/**
		 * @inheritDoc
		 */
		public function register() {

			$this->mergeConfigFrom(__DIR__ . '/../../config/config.php', 'transactionWaitingEvents');

			// inject our own event dispatcher
			$this->app->extend('events', function($x, $app) {
				return (new EventDispatcher($app))
					->setQueueResolver(function () use ($app) {
						return $app->make(QueueFactoryContract::class);
					})
					->setEventsWaitForTransactions(config('transactionWaitingEvents.wait_for_transactions', true));
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