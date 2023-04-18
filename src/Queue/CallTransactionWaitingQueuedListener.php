<?php


	namespace MehrIt\LaraTransactionWaitingEvents\Queue;


	use Illuminate\Container\Container;
	use Illuminate\Events\CallQueuedListener;
	use MehrIt\LaraTransactionWaitingEvents\MySqlLock;


	/**
	 * Job calling queued listener waiting for database transactions to be completed
	 * @package MehrIt\LaraTransactionWaitingEvents
	 */
	class CallTransactionWaitingQueuedListener extends CallQueuedListener
	{

		/**
		 * @var string[] The locks to wait for before invoking event handling
		 */
		public $transactionLocks = [];

		public $transactionLockWaitTimeout = 0;

		public $transactionLockRetryAfter = 5;


		public function __construct($class, $method, $data, array $locks = [], int $lockWaitTimeout = 1, int $lockRetryAfter = 5) {
			parent::__construct($class, $method, $data);

			$this->transactionLocks           = $locks;
			$this->transactionLockWaitTimeout = $lockWaitTimeout;
			$this->transactionLockRetryAfter  = $lockRetryAfter;
		}

		/**
		 * Handle the queued job.
		 *
		 * @param \Illuminate\Container\Container $container
		 * @return void
		 */
		public function handle(Container $container) {

			// Before invoking the queued handler we have to verify that the transaction have completed.
			// We do this by checking the existence of the transaction locks
			

			// check all locks to be free (this is the case when the transaction is finished)
			foreach ($this->transactionLocks as $connection => $lockName) {

				/** @var MySqlLock $lock */
				$lock = app(MySqlLock::class);
				
				if (!$lock->isFree($connection, $lockName)) {
					// lock is in use
					
					if ($this->transactionLockWaitTimeout) {
						// try to wait for lock to become available

						if ($lock->getLock($connection, $lockName, $this->transactionLockWaitTimeout)) {
							// Now, we got the lock. We release it immediately, because we only wanted to wait for it
							$lock->releaseLock($connection, $lockName);
							continue;
						}
					}


					$this->release($this->transactionLockRetryAfter);

					return;
				}
			
			}


			parent::handle($container);
		}


	}