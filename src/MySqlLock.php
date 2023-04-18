<?php

	namespace MehrIt\LaraTransactionWaitingEvents;

	use Illuminate\Support\Facades\DB;

	class MySqlLock
	{
		/**
		 * Gets the lock with the given name
		 * @param string|null $connection The connection
		 * @param string $name The lock name
		 * @param int $timeout The timeout in seconds
		 * @return bool True if successful. Else false.
		 */
		public function getLock(?string $connection, string $name, int $timeout): bool {
			return DB::connection($connection)->selectOne('select get_lock(?, ?) as l', [$name, $timeout])->l === 1;
		}

		/**
		 * Releases the given lock
		 * @param string|null $connection The connection
		 * @param string $name The lock name
		 * @return void
		 */
		public function releaseLock(?string $connection, string $name): void {
			DB::connection($connection)->selectOne('select release_lock(?)', [$name]);
		}

		/**
		 * Checks if the given lock is free
		 * @param string|null $connection The connection
		 * @param string $name The lock name
		 * @return bool True if free. Else false.
		 */
		public function isFree(?string $connection, string $name): bool {
			return DB::connection($connection)->selectOne('select is_free_lock(?) as l', [$name])->l === 1;
		}
	}