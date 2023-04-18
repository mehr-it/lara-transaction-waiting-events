<?php


	namespace MehrItLaraTransactionWaitingEventsTest\Cases\Unit;


	use Illuminate\Support\Facades\DB;
	use MehrIt\LaraTransactionWaitingEvents\Provider\TransactionWaitingEventsServiceProvider;

	class TestCase extends \Orchestra\Testbench\TestCase
	{
		/**
		 * @inheritDoc
		 */
		protected function setUp(): void {
			parent::setUp();

			DB::reconnect();
			DB::reconnect('other');

			// prepare database
			$this->artisan('migrate')->run();
		}

		/**
		 * Define environment setup.
		 *
		 * @param \Illuminate\Foundation\Application $app
		 * @return void
		 */
		protected function getEnvironmentSetUp($app) {
			// Configure a clone of our default connection, so we can test with two independent connections
			$app['config']->set('database.connections.other', $app['config']->get('database.connections.' . $app['config']->get('database.default')));
		}


		protected function getPackageProviders($app) {
			return [
				TransactionWaitingEventsServiceProvider::class,
			];
		}
	}