<?php


	namespace MehrItLaraTransactionWaitingEventsTest\Cases\Unit;


	use Illuminate\Contracts\Events\Dispatcher;
	use Illuminate\Events\CallQueuedListener;
	use Illuminate\Support\Arr;
	use Illuminate\Support\Facades\Bus;
	use Illuminate\Support\Facades\DB;
	use MehrIt\LaraTransactionWaitingEvents\EventDispatcher;
	use MehrIt\LaraTransactionWaitingEvents\MySqlLock;
	use MehrIt\LaraTransactionWaitingEvents\Queue\CallTransactionWaitingQueuedListener;
	use MehrItLaraTransactionWaitingEventsTest\Helpers\Listener;
	use MehrItLaraTransactionWaitingEventsTest\Helpers\QueuedListener;
	use MehrItLaraTransactionWaitingEventsTest\Helpers\QueuedWaitingListener;
	use MehrItLaraTransactionWaitingEventsTest\Helpers\QueuedWaitingListenerOtherConnection;

	class EventDispatcherTest extends TestCase
	{
		protected function assertLockExists($lockName, $connection = null) {

			/** @var MySqlLock $lock */
			$lock = app(MySqlLock::class);

			return !$lock->isFree($connection, $lockName);

		}

		protected function assertLockMissing($lockName, $connection = null) {

			/** @var MySqlLock $lock */
			$lock = app(MySqlLock::class);
			
			return $lock->isFree($connection, $lockName);
		}


		public function testDispatch_closure() {
			/** @var Dispatcher $events */
			$events = app('events');

			$called = 0;

			$events->listen('test-event', function() use (&$called) {
				++$called;
			});

			$events->dispatch('test-event');

			$this->assertSame(1, $called);
		}

		public function testDispatch_notQueued() {
			/** @var Dispatcher $events */
			$events = app('events');


			$events->listen('test-event', Listener::class);

			$events->dispatch('test-event');

			$this->assertSame(1, Listener::$callCount);
		}


		public function testDispatch_notWaitingForTransactions() {


			/** @var Dispatcher $events */
			$events = app('events');

			$events->listen('test-event', QueuedListener::class);

			Bus::fake([
				CallTransactionWaitingQueuedListener::class,
				CallQueuedListener::class,
			]);

			$events->dispatch('test-event');

			Bus::assertDispatched(CallQueuedListener::class, function (CallQueuedListener $job) {
				return
					$job->class === QueuedListener::class &&
					$job->method === 'handle';
			});

		}

		public function testDispatch_waitingForTransactions_noTransactionStarted() {


			/** @var Dispatcher $events */
			$events = app('events');

			$events->listen('test-event', QueuedWaitingListener::class);

			Bus::fake([
				CallTransactionWaitingQueuedListener::class,
				CallQueuedListener::class,
			]);

			$events->dispatch('test-event');

			Bus::assertDispatched(CallTransactionWaitingQueuedListener::class, function (CallTransactionWaitingQueuedListener $job) {
				return
					$job->class === QueuedWaitingListener::class &&
					$job->method === 'handle' &&
					empty($job->transactionLocks);
			});

		}

		public function testDispatch_waitingForTransactions_transactionStarted() {


			/** @var Dispatcher $events */
			$events = app('events');

			$events->listen('test-event', QueuedWaitingListener::class);

			Bus::fake([
				CallTransactionWaitingQueuedListener::class,
				CallQueuedListener::class,
			]);

			$transactionLocks = [];

			DB::transaction(function() use ($events, &$transactionLocks) {

				$events->dispatch('test-event');


				Bus::assertDispatched(CallTransactionWaitingQueuedListener::class, function (CallTransactionWaitingQueuedListener $job) use (&$transactionLocks) {

					$transactionLocks = $job->transactionLocks;

					return
						$job->class === QueuedWaitingListener::class &&
						$job->method === 'handle' &&
						!empty($job->transactionLocks);
				});

				$this->assertLockExists(Arr::first($transactionLocks));
			});


			$this->assertLockMissing(Arr::first($transactionLocks));
		}

		public function testDispatch_waitingForTransactions_transactionStartedButWaitingDisabled() {


			/** @var Dispatcher|EventDispatcher $events */
			$events = app('events');

			$events->listen('test-event', QueuedWaitingListener::class);

			Bus::fake([
				CallTransactionWaitingQueuedListener::class,
				CallQueuedListener::class,
			]);

			DB::transaction(function() use ($events, &$transactionLocks) {

				$this->assertSame(true, $events->getEventsWaitForTransaction());
				$this->assertSame($events, $events->setEventsWaitForTransactions(false));
				$this->assertSame(false, $events->getEventsWaitForTransaction());

				$events->dispatch('test-event');


				Bus::assertDispatched(CallQueuedListener::class, function (CallQueuedListener $job) {

					return
						$job->class === QueuedWaitingListener::class &&
						$job->method === 'handle';
				});
			});
		}

		public function testDispatch_waitingForTransactions_transactionStarted_waitForTransactionsPropertySet() {


			/** @var Dispatcher $events */
			$events = app('events');

			$events->listen('test-event', QueuedWaitingListenerOtherConnection::class);

			Bus::fake([
				CallTransactionWaitingQueuedListener::class,
				CallQueuedListener::class,
			]);

			$transactionLocks = [];

			DB::connection('other')->transaction(function() use ($events, &$transactionLocks) {

				$events->dispatch('test-event');


				Bus::assertDispatched(CallTransactionWaitingQueuedListener::class, function (CallTransactionWaitingQueuedListener $job) use (&$transactionLocks) {

					$transactionLocks = $job->transactionLocks;

					return
						$job->class === QueuedWaitingListenerOtherConnection::class &&
						$job->method === 'handle' &&
						!empty($job->transactionLocks);
				});

				$this->assertLockExists(Arr::first($transactionLocks));
			});


			$this->assertLockMissing(Arr::first($transactionLocks));
		}

		public function testDispatch_waitingForTransactions_transactionStartedOnDifferentConnection_waitForTransactionsPropertySet() {


			/** @var Dispatcher $events */
			$events = app('events');

			$events->listen('test-event', QueuedWaitingListenerOtherConnection::class);

			Bus::fake([
				CallTransactionWaitingQueuedListener::class,
				CallQueuedListener::class,
			]);

			DB::transaction(function () use ($events) {
				$events->dispatch('test-event');

				Bus::assertDispatched(CallTransactionWaitingQueuedListener::class, function (CallTransactionWaitingQueuedListener $job) {
					return
						$job->class === QueuedWaitingListenerOtherConnection::class &&
						$job->method === 'handle' &&
						empty($job->transactionLocks);
				});
			});

		}

		public function testDispatch_waitingForTransactions_transactionStartedOnDifferentConnection() {


			/** @var Dispatcher $events */
			$events = app('events');

			$events->listen('test-event', QueuedWaitingListener::class);

			Bus::fake([
				CallTransactionWaitingQueuedListener::class,
				CallQueuedListener::class,
			]);

			DB::connection('other')->transaction(function () use ($events) {
				$events->dispatch('test-event');

				Bus::assertDispatched(CallTransactionWaitingQueuedListener::class, function (CallTransactionWaitingQueuedListener $job) {
					return
						$job->class === QueuedWaitingListener::class &&
						$job->method === 'handle' &&
						empty($job->transactionLocks);
				});
			});

		}

		public function testDispatch_waitingForTransactions_transactionAlreadyCommitted() {


			/** @var Dispatcher $events */
			$events = app('events');

			$events->listen('test-event', QueuedWaitingListener::class);

			Bus::fake([
				CallTransactionWaitingQueuedListener::class,
				CallQueuedListener::class,
			]);

			DB::beginTransaction();
			DB::commit();

			$events->dispatch('test-event');

			Bus::assertDispatched(CallTransactionWaitingQueuedListener::class, function (CallTransactionWaitingQueuedListener $job) {
				return
					$job->class === QueuedWaitingListener::class &&
					$job->method === 'handle' &&
					empty($job->transactionLocks);
			});
		}

		public function testDispatch_waitingForTransactions_transactionAlreadyRolledBack() {


			/** @var Dispatcher $events */
			$events = app('events');

			$events->listen('test-event', QueuedWaitingListener::class);

			Bus::fake([
				CallTransactionWaitingQueuedListener::class,
				CallQueuedListener::class,
			]);

			DB::beginTransaction();
			DB::rollBack();

			$events->dispatch('test-event');

			Bus::assertDispatched(CallTransactionWaitingQueuedListener::class, function (CallTransactionWaitingQueuedListener $job) {
				return
					$job->class === QueuedWaitingListener::class &&
					$job->method === 'handle' &&
					empty($job->transactionLocks);
			});
		}

		public function testDispatch_waitingForTransactions_reconnectAfterTransactionStart() {


			/** @var Dispatcher $events */
			$events = app('events');

			$events->listen('test-event', QueuedWaitingListener::class);

			Bus::fake([
				CallTransactionWaitingQueuedListener::class,
				CallQueuedListener::class,
			]);

			DB::beginTransaction();
			DB::reconnect();


			$events->dispatch('test-event');

			Bus::assertDispatched(CallTransactionWaitingQueuedListener::class, function (CallTransactionWaitingQueuedListener $job) {
				return
					$job->class === QueuedWaitingListener::class &&
					$job->method === 'handle' &&
					empty($job->transactionLocks);
			});
		}

		public function testDispatch_multipleWaitingForTransactions_withinSameTransaction() {


			/** @var Dispatcher $events */
			$events = app('events');

			$events->listen('test-event', QueuedWaitingListener::class);

			Bus::fake([
				CallTransactionWaitingQueuedListener::class,
				CallQueuedListener::class,
			]);

			$transactionLocks1 = [];
			$transactionLocks2 = [];

			DB::transaction(function () use ($events, &$transactionLocks1, &$transactionLocks2) {

				$events->dispatch('test-event', [15]);
				$events->dispatch('test-event', [20]);

				Bus::assertDispatched(CallTransactionWaitingQueuedListener::class, function (CallTransactionWaitingQueuedListener $job) use (&$transactionLocks1) {

					$ret =
						$job->class === QueuedWaitingListener::class &&
						$job->method === 'handle' &&
						$job->data === [15] &&
						!empty($job->transactionLocks);

					if ($ret)
						$transactionLocks1 = $job->transactionLocks;

					return $ret;
				});

				Bus::assertDispatched(CallTransactionWaitingQueuedListener::class, function (CallTransactionWaitingQueuedListener $job) use (&$transactionLocks2) {


					$ret =
						$job->class === QueuedWaitingListener::class &&
						$job->method === 'handle' &&
						$job->data === [20] &&
						!empty($job->transactionLocks);

					if ($ret)
						$transactionLocks2 = $job->transactionLocks;

					return $ret;
				});


				$this->assertSame($transactionLocks1, $transactionLocks2);

				$this->assertLockExists(Arr::first($transactionLocks1));
				$this->assertLockExists(Arr::first($transactionLocks2));
			});

			$this->assertLockMissing(Arr::first($transactionLocks1));
			$this->assertLockMissing(Arr::first($transactionLocks2));

		}

		public function testDispatch_multipleWaitingForTransactions_twoDifferentTransactions() {


			/** @var Dispatcher $events */
			$events = app('events');

			$events->listen('test-event', QueuedWaitingListener::class);

			Bus::fake([
				CallTransactionWaitingQueuedListener::class,
				CallQueuedListener::class,
			]);

			$transactionLocks1 = [];
			$transactionLocks2 = [];

			DB::transaction(function () use ($events, &$transactionLocks1) {


				$events->dispatch('test-event', [15]);


				Bus::assertDispatched(CallTransactionWaitingQueuedListener::class, function (CallTransactionWaitingQueuedListener $job) use (&$transactionLocks1) {

					$ret =
						$job->class === QueuedWaitingListener::class &&
						$job->method === 'handle' &&
						$job->data === [15] &&
						!empty($job->transactionLocks);

					if ($ret)
						$transactionLocks1 = $job->transactionLocks;

					return $ret;
				});

				$this->assertLockExists(Arr::first($transactionLocks1));
			});

			DB::transaction(function () use ($events, &$transactionLocks2) {

				$events->dispatch('test-event', [20]);

				Bus::assertDispatched(CallTransactionWaitingQueuedListener::class, function (CallTransactionWaitingQueuedListener $job) use (&$transactionLocks2) {


					$ret =
						$job->class === QueuedWaitingListener::class &&
						$job->method === 'handle' &&
						$job->data === [20] &&
						!empty($job->transactionLocks);

					if ($ret)
						$transactionLocks2 = $job->transactionLocks;

					return $ret;
				});

				$this->assertLockExists(Arr::first($transactionLocks2));
			});

			$this->assertNotEquals($transactionLocks1, $transactionLocks2);

			$this->assertLockMissing(Arr::first($transactionLocks1));
			$this->assertLockMissing(Arr::first($transactionLocks2));

		}

	}