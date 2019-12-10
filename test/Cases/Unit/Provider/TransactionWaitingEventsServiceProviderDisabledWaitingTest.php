<?php


	namespace MehrItLaraTransactionWaitingEventsTest\Cases\Unit\Provider;


	use Illuminate\Events\Dispatcher;
	use MehrIt\LaraTransactionWaitingEvents\EventDispatcher;
	use MehrItLaraTransactionWaitingEventsTest\Cases\Unit\TestCase;

	class TransactionWaitingEventsServiceProviderDisabledWaitingTest extends TestCase
	{
		protected function getPackageProviders($app) {
			$app['config']->set('transactionWaitingEvents.wait_for_transactions', false);

			return parent::getPackageProviders($app);
		}


		public function testEventDispatcherRegistration_waitingDisabled() {


			/** @var EventDispatcher $resolved */
			$resolved = app('events');
			$this->assertInstanceOf(EventDispatcher::class, $resolved);
			$this->assertSame($resolved, app('events'));
			$this->assertSame($resolved, app(Dispatcher::class));
			$this->assertSame($resolved, app(\Illuminate\Contracts\Events\Dispatcher::class));
			$this->assertSame($resolved, app(EventDispatcher::class));

			$this->assertSame(false, $resolved->getEventsWaitForTransaction());
		}


	}