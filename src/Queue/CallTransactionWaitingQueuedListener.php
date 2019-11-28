<?php


	namespace MehrIt\LaraTransactionWaitingEvents\Queue;


	use Illuminate\Container\Container;
	use Illuminate\Events\CallQueuedListener;
	use MehrIt\LaraMySqlLocks\DbLockFactory;
	use MehrIt\LaraMySqlLocks\Exception\DbLockTimeoutException;


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

		public $transactionLockTtl = 86400;


		public function __construct($class, $method, $data, array $locks = [], int $lockWaitTimeout = 1, int $lockRetryAfter = 5, int $lockTtl = 86400) {
			parent::__construct($class, $method, $data);

			$this->transactionLocks           = $locks;
			$this->transactionLockWaitTimeout = $lockWaitTimeout;
			$this->transactionLockRetryAfter  = $lockRetryAfter;
			$this->transactionLockTtl         = $lockTtl;
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
			/** @var DbLockFactory $locks */
			$locks = app(DbLockFactory::class);
			try {
				// check all locks to be free (this is the case when the transaction is finished)
				foreach ($this->transactionLocks as $connection => $lockName) {

					// lock and release immediately (because we only want to check existence)
					$locks->lock($lockName, $this->transactionLockWaitTimeout, $this->transactionLockTtl, $connection)
						->release();
				}
			}
			catch(DbLockTimeoutException $ex) {
				// if a lock could be obtained, we retry later
				$this->release($this->transactionLockRetryAfter);
				return;
			}


			parent::handle($container);
		}


	}