<?php


	namespace MehrItLaraTransactionWaitingEventsTest\Cases\Unit\Provider;


	use Illuminate\Events\Dispatcher;
	use MehrIt\LaraTransactionWaitingEvents\EventDispatcher;
	use MehrItLaraTransactionWaitingEventsTest\Cases\Unit\TestCase;

	class TransactionWaitingEventsServiceProviderTest extends TestCase
	{

		public function testEventDispatcherRegistration() {
			/** @var EventDispatcher $resolved */
			$resolved = app('events');
			$this->assertInstanceOf(EventDispatcher::class, $resolved);
			$this->assertSame($resolved, app('events'));
			$this->assertSame($resolved, app(Dispatcher::class));
			$this->assertSame($resolved, app(\Illuminate\Contracts\Events\Dispatcher::class));
			$this->assertSame($resolved, app(EventDispatcher::class));

			$this->assertSame(true, $resolved->getEventsWaitForTransaction());
		}


	}