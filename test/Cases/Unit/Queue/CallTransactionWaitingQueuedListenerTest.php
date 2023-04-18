<?php


	namespace MehrItLaraTransactionWaitingEventsTest\Cases\Unit\Queue;


	use Illuminate\Contracts\Queue\Job;
	use Illuminate\Support\Arr;
	use Illuminate\Support\Facades\DB;
	use MehrIt\LaraMySqlLocks\Facades\DbLock;
	use MehrIt\LaraTransactionWaitingEvents\MySqlLock;
	use MehrIt\LaraTransactionWaitingEvents\Queue\CallTransactionWaitingQueuedListener;
	use MehrItLaraTransactionWaitingEventsTest\Cases\Unit\TestCase;
	use MehrItLaraTransactionWaitingEventsTest\Cases\Unit\TestsParallelProcesses;
	use MehrItLaraTransactionWaitingEventsTest\Helpers\QueuedWaitingListener;
	use PHPUnit\Framework\MockObject\MockObject;

	class CallTransactionWaitingQueuedListenerTest extends TestCase
	{
		use TestsParallelProcesses;

		public function testConstructor() {

			$job = new CallTransactionWaitingQueuedListener(QueuedWaitingListener::class, 'handle', ['event' => 'my-event'], ['default' => 'my-lock'], 5, 18);

			$this->assertSame(QueuedWaitingListener::class, $job->class);
			$this->assertSame('handle', $job->method);
			$this->assertSame(['event' => 'my-event'], $job->data);
			$this->assertSame(['default' => 'my-lock'], $job->transactionLocks);
			$this->assertSame(5, $job->transactionLockWaitTimeout);
			$this->assertSame(18, $job->transactionLockRetryAfter);

		}

		public function testInvokeHandler_withoutLocks() {

			/** @var Job|MockObject $jobMock */
			$jobMock = $this->getMockBuilder(Job::class)->getMock();

			$job = new CallTransactionWaitingQueuedListener(QueuedWaitingListener::class, 'handle', ['event' => 'my-event'], [], 5, 18);
			$job->setJob($jobMock);

			/** @var QueuedWaitingListener|MockObject $listenerMock */
			$listenerMock = $this->getMockBuilder(QueuedWaitingListener::class)->getMock();
			$listenerMock
				->expects($this->once())
				->method('handle')
				->with('my-event');

			app()->bind(QueuedWaitingListener::class, function() use ($listenerMock) {
				return $listenerMock;
			});

			$job->handle(app());

		}

		public function testInvokeHandler_lockIsFree() {

			/** @var Job|MockObject $jobMock */
			$jobMock = $this->getMockBuilder(Job::class)->getMock();
			$jobMock
				->expects($this->never())
				->method('release');

			$locks = [
				DB::getDefaultConnection() => 'lock-' . uniqid(),
			];

			$job = new CallTransactionWaitingQueuedListener(QueuedWaitingListener::class, 'handle', ['event' => 'my-event'], $locks, 5, 18);
			$job->setJob($jobMock);

			/** @var QueuedWaitingListener|MockObject $listenerMock */
			$listenerMock = $this->getMockBuilder(QueuedWaitingListener::class)->getMock();
			$listenerMock
				->expects($this->once())
				->method('handle')
				->with('my-event');

			app()->bind(QueuedWaitingListener::class, function() use ($listenerMock) {
				return $listenerMock;
			});

			$job->handle(app());

		}

		public function testInvokeHandler_multipleLocksAreFree() {

			/** @var Job|MockObject $jobMock */
			$jobMock = $this->getMockBuilder(Job::class)->getMock();
			$jobMock
				->expects($this->never())
				->method('release');

			$locks = [
				DB::getDefaultConnection() => 'lock-' . uniqid(),
				'other' => 'lock-' . uniqid(),
			];

			$job = new CallTransactionWaitingQueuedListener(QueuedWaitingListener::class, 'handle', ['event' => 'my-event'], $locks, 5, 18, 7200);
			$job->setJob($jobMock);

			/** @var QueuedWaitingListener|MockObject $listenerMock */
			$listenerMock = $this->getMockBuilder(QueuedWaitingListener::class)->getMock();
			$listenerMock
				->expects($this->once())
				->method('handle')
				->with('my-event');

			app()->bind(QueuedWaitingListener::class, function() use ($listenerMock) {
				return $listenerMock;
			});

			$job->handle(app());

		}

		public function testInvokeHandler_lockIsAcquired() {

			/** @var Job|MockObject $jobMock */
			$jobMock = $this->getMockBuilder(Job::class)->getMock();
			$jobMock
				->expects($this->once())
				->method('release')
				->with(18);

			$locks = [
				DB::getDefaultConnection() => 'lock-' . uniqid(),
			];

			$job = new CallTransactionWaitingQueuedListener(QueuedWaitingListener::class, 'handle', ['event' => 'my-event'], $locks, 1, 18);
			$job->setJob($jobMock);

			/** @var QueuedWaitingListener|MockObject $listenerMock */
			$listenerMock = $this->getMockBuilder(QueuedWaitingListener::class)->getMock();
			$listenerMock
				->expects($this->never())
				->method('handle');

			app()->bind(QueuedWaitingListener::class, function () use ($listenerMock) {
				return $listenerMock;
			});

			$this->fork(
				function($sh) use ($job) {

					// wait for lock to be acquired
					$this->assertNextMessage('acquired', $sh);

					$job->handle(app());

					$this->sendMessage('job handled', $sh);
				},
				function($sh) use ($locks) {

					/** @var MySqlLock $lock */
					$lock = app(MySqlLock::class);
					
					if (!$lock->getLock(null, Arr::first($locks), 0))
						$this->fail('Failed to acquire lock');

					$this->sendMessage('acquired', $sh);

					$this->assertNextMessage('job handled', $sh);

					$lock->releaseLock(null, Arr::first($locks));
					

				}
			);

		}

		public function testInvokeHandler_oneOfMultipleLocksIsAcquired() {

			/** @var Job|MockObject $jobMock */
			$jobMock = $this->getMockBuilder(Job::class)->getMock();
			$jobMock
				->expects($this->once())
				->method('release')
				->with(18);

			$locks = [
				DB::getDefaultConnection() => 'lock-' . uniqid(),
				'other' => 'lock-' . uniqid(),
			];

			$job = new CallTransactionWaitingQueuedListener(QueuedWaitingListener::class, 'handle', ['event' => 'my-event'], $locks, 1, 18);
			$job->setJob($jobMock);

			/** @var QueuedWaitingListener|MockObject $listenerMock */
			$listenerMock = $this->getMockBuilder(QueuedWaitingListener::class)->getMock();
			$listenerMock
				->expects($this->never())
				->method('handle');

			app()->bind(QueuedWaitingListener::class, function () use ($listenerMock) {
				return $listenerMock;
			});

			$this->fork(
				function($sh) use ($job) {

					// wait for lock to be acquired
					$this->assertNextMessage('acquired', $sh);

					$job->handle(app());

					$this->sendMessage('job handled', $sh);
				},
				function($sh) use ($locks) {

					/** @var MySqlLock $lock */
					$lock = app(MySqlLock::class);

					if (!$lock->getLock('other', $locks['other'], 0))
						$this->fail('Failed to acquire lock');

					$this->sendMessage('acquired', $sh);

					$this->assertNextMessage('job handled', $sh);

					$lock->releaseLock('other', $locks['other']);

				}
			);

		}

		public function testInvokeHandler_handleIsInvokedWhenLockReleasedWhileWaiting() {

			/** @var Job|MockObject $jobMock */
			$jobMock = $this->getMockBuilder(Job::class)->getMock();
			$jobMock
				->expects($this->never())
				->method('release');

			$locks = [
				DB::getDefaultConnection() => 'lock-' . uniqid(),
			];

			$job = new CallTransactionWaitingQueuedListener(QueuedWaitingListener::class, 'handle', ['event' => 'my-event'], $locks, 4, 18);
			$job->setJob($jobMock);

			/** @var QueuedWaitingListener|MockObject $listenerMock */
			$listenerMock = $this->getMockBuilder(QueuedWaitingListener::class)->getMock();
			$listenerMock
				->expects($this->once())
				->method('handle')
				->with('my-event');

			app()->bind(QueuedWaitingListener::class, function () use ($listenerMock) {
				return $listenerMock;
			});

			$this->fork(
				function ($sh) use ($job) {

					// wait for lock to be acquired
					$this->assertNextMessage('acquired', $sh);

					$this->assertDurationGreaterThan(1, function() use ($job) {

						$job->handle(app());

					});


				},
				function ($sh) use ($locks) {

					/** @var MySqlLock $lock */
					$lock = app(MySqlLock::class);
					
					if (!$lock->getLock(null, Arr::first($locks), 0))
						$this->fail('Failed to acquire lock');

					$this->sendMessage('acquired', $sh);

					sleep(2);

					$lock->releaseLock(null, Arr::first($locks));


				}
			);

		}
	}