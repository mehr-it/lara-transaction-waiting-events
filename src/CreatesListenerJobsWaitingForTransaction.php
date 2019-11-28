<?php


	namespace MehrIt\LaraTransactionWaitingEvents;


	use Illuminate\Database\DatabaseManager;
	use Illuminate\Database\Events\ConnectionEvent;
	use Illuminate\Database\Events\TransactionBeginning;
	use Illuminate\Database\Events\TransactionCommitted;
	use Illuminate\Database\Events\TransactionRolledBack;
	use Illuminate\Support\Str;
	use InvalidArgumentException;
	use MehrIt\LaraMySqlLocks\DbLock;
	use MehrIt\LaraMySqlLocks\DbLockFactory;
	use MehrIt\LaraMySqlLocks\Exception\DbLockReleaseException;
	use MehrIt\LaraTransactionWaitingEvents\Contracts\WaitsForTransactions;
	use MehrIt\LaraTransactionWaitingEvents\Queue\CallTransactionWaitingQueuedListener;
	use ReflectionClass;
	use ReflectionException;

	/**
	 * Handles events waiting for transactions
	 * @package MehrIt\LaraTransactionWaitingEvents
	 */
	trait CreatesListenerJobsWaitingForTransaction
	{
		protected $eventsWaitForTransactions = true;

		/**
		 * @var DbLockFactory
		 */
		protected $lockFactory;

		/**
		 * @var DatabaseManager
		 */
		protected $databaseManager;

		/**
		 * @var boolean[] State of active DB transactions. Connection name as key
		 */
		protected $activeDbTransactions = [];

		/**
		 * @var DbLock[] The DB transaction locks. Connection name as key
		 */
		protected $dbTransactionLocks = [];

		/**
		 * @var int[] Ids of the PDO connections
		 */
		protected $dbConnectionInstanceIds = [];

		/**
		 * Gets if events should wait for transactions to complete
		 * @return bool True if waiting. Else false.
		 */
		public function getEventsWaitForTransaction(): bool {
			return $this->eventsWaitForTransactions;
		}

		/**
		 * Sets if events should wait for transactions to complete
		 * @param bool $value The value
		 * @return $this
		 */
		public function setEventsWaitForTransactions(bool $value) {
			$this->eventsWaitForTransactions = $value;

			return $this;
		}

		/**
		 * Fire an event and call the listeners.
		 *
		 * @param string|object $event
		 * @param mixed $payload
		 * @param bool $halt
		 * @return array|null
		 */
		public function dispatch($event, $payload = [], $halt = false) {

			// handle transaction events
			if (is_object($event) && (
					$event instanceof TransactionBeginning ||
					$event instanceof TransactionCommitted ||
					$event instanceof TransactionRolledBack
				)
			) {
				$this->onTransactionStateChanged($event);
			}

			return parent::dispatch($event, $payload, $halt);
		}


		/**
		 * Create the listener and job for a queued listener
		 *
		 * @param string $class
		 * @param string $method
		 * @param array $arguments
		 * @return array
		 * @throws ReflectionException
		 */
		protected function createListenerAndJob($class, $method, $arguments) {

			// check if enabled
			if ($this->eventsWaitForTransactions) {

				$listener = (new ReflectionClass($class))->newInstanceWithoutConstructor();

				if ($listener instanceof WaitsForTransactions) {

					// get a list of the connections to wait for transactions to complete
					$waitForTransactionsOnConnections = $listener->waitForTransactions ?? null;
					if (!is_array($waitForTransactionsOnConnections))
						$waitForTransactionsOnConnections = [$waitForTransactionsOnConnections];

					$lockTtl = $listener->waitForTransactionsTtl ?? 86400;

					// create locks for all required connections
					$locks = [];
					foreach ($waitForTransactionsOnConnections as $connection) {

						$connection = $this->getDbConnectionName($connection);

						$transactionLock = $this->makeTransactionLock($connection, $lockTtl);
						if ($transactionLock)
							$locks[$connection] = $transactionLock;
					}

					// create waiting job and return with listener
					return [
						$listener,
						$this->propagateListenerOptions(
							$listener,
							new CallTransactionWaitingQueuedListener(
								$class,
								$method,
								$arguments,
								$locks,
								$listener->waitForTransactionsTimeout ?? 1,
								$listener->waitForTransactionsRetryAfter ?? 5,
								$lockTtl
							)
						)
					];
				}
			}

			// call parent for non-waiting listeners
			return parent::createListenerAndJob($class, $method, $arguments);


		}

		/**
		 * Creates a lock being released after transaction end and returns the lock name. If a lock for the current transaction exists, it will be used.
		 * If no transaction is active for the given connection, no lock is created and null is returned
		 * @param string $connection The connection name
		 * @param int $ttl The TTL for the lock
		 * @return string|null The lock name or null if no transaction is active.
		 */
		protected function makeTransactionLock(string $connection, int $ttl): ?string {

			// Check if we are still using the same PDO connection. If not, a reconnect has taken place meanwhile and
			// our created lock is obsolete and the transaction level has to be updated
			if (($this->activeDbTransactions[$connection] ?? null) && $this->isNewPdoConnection($connection)) {

				// reset transaction state
				$this->rememberTransactionState($connection);

				// simply remove the lock reference, because a release() would fail due to changed PDO
				$this->dbTransactionLocks[$connection] = null;
			}

			// we only need a transaction lock, if a transaction is already started
			if (!($this->activeDbTransactions[$connection] ?? false))
				return null;


			// create new lock if yet none exists
			if (!($this->dbTransactionLocks[$connection] ?? null)) {

				// remember the PDO instance, which allows us to detect reconnects
				$this->rememberPdoConnection($connection);

				$this->dbTransactionLocks[$connection] = $this->getLockFactory()->lock('dbtx-' . (string)Str::uuid(), 0, $ttl, $connection);
			}

			$this->dbTransactionLocks[$connection]->acquired();

			return $this->dbTransactionLocks[$connection]->getName();
		}

		/**
		 * Handles transaction events
		 * @param ConnectionEvent $event The transaction event
		 */
		protected function onTransactionStateChanged(ConnectionEvent $event) {
			$connectionName = $this->getDbConnectionName($event->connectionName);

			// Stop here if the connection cannot be found in the list of configured connections. It might
			// be a temporary connection name which we don't have to care for
			if (!$this->isDbConnectionConfigured($connectionName))
				return;

			// remember the transaction state
			$transactionActive = $this->rememberTransactionState($connectionName);

			// release lock if not active anymore
			if (!$transactionActive)
				$this->releaseTransactionLock($connectionName);
		}


		/**
		 * Remembers the transaction state for the given connection
		 * @param string $connection The connection name
		 * @return bool True if transaction active. Else false.
		 */
		protected function rememberTransactionState(string $connection) {
			$transactionActive = ($this->getDatabaseManager()->connection($connection)->transactionLevel() > 0);

			// update the transaction state
			$this->activeDbTransactions[$connection] = $transactionActive;

			return $transactionActive;
		}

		/**
		 * Releases the transaction lock for the given connection if any
		 * @param string $connectionName The connection name
		 */
		protected function releaseTransactionLock(string $connectionName) {

			$activeLock = ($this->dbTransactionLocks[$connectionName] ?? null);

			if ($activeLock) {
				try {
					$activeLock->release();
				}
				catch (DbLockReleaseException $ex) {
					// if this happens, we can ignore it, because the lock is automatically released because it ends with the the transaction
					report($ex);
				}

				$this->dbTransactionLocks[$connectionName] = null;
			}
		}

		/**
		 * Gets the name of the database connection
		 * @param string|null $name The name or null if to return the default connection name
		 * @return string The connection name
		 */
		protected function getDbConnectionName($name) {
			if (!$name)
				return $this->getDatabaseManager()->getDefaultConnection();

			return $name;
		}

		/**
		 * Checks if a DB connection with given name is configured
		 * @param string $name The name
		 * @return bool True if connection is configured. Else false.
		 */
		protected function isDbConnectionConfigured($name): bool {
			try {
				$this->getDatabaseManager()->connection($name);
				return true;
			}
			catch (InvalidArgumentException $ex) {
				return false;
			}
		}

		/**
		 * Gets the ID of the PDO connection instance
		 * @param string $connection The connection name
		 * @return int|void
		 */
		protected function getPdoInstanceId(string $connection) {
			return spl_object_id($this->getDatabaseManager()->connection($connection)->getPdo());
		}

		/**
		 * Remembers the PDO connection for the given connection instance
		 * @param string $connection The connection name
		 */
		protected function rememberPdoConnection(string $connection) {
			$this->dbConnectionInstanceIds[$connection] = $this->getPdoInstanceId($connection);
		}

		/**
		 * Checks if the PDO connection is the same as last remembered PDO
		 * @param string $connection The connection name
		 * @return bool True if new connection. Else false.
		 */
		protected function isNewPdoConnection(string $connection): bool {

			if (!($this->dbConnectionInstanceIds[$connection] ?? null))
				return true;

			return $this->dbConnectionInstanceIds[$connection] != $this->getPdoInstanceId($connection);
		}

		/**
		 * Gets a database manager instance
		 * @return DatabaseManager
		 */
		protected function getDatabaseManager() {
			if (!$this->databaseManager)
				$this->databaseManager = app('db');

			return $this->databaseManager;
		}

		/**
		 * Gets a lock factory instance
		 * @return DbLockFactory
		 */
		protected function getLockFactory() {
			if (!$this->lockFactory)
				$this->lockFactory = app(DbLockFactory::class);

			return $this->lockFactory;
		}


	}