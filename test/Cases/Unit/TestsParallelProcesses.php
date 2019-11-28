<?php


	namespace MehrItLaraTransactionWaitingEventsTest\Cases\Unit;


	use Illuminate\Support\Facades\DB;

	trait TestsParallelProcesses
	{
		protected function assertDurationLessThan($expectedMicroTime, callable $fn) {
			$ts = microtime(true);

			call_user_func($fn);

			$duration = microtime(true) - $ts;

			if ($duration > $expectedMicroTime)
				$this->fail("Operation should not take less than {$expectedMicroTime}s, but took " . round($duration, 3) . 's');
		}

		protected function assertDurationGreaterThan($expectedMicroTime, callable $fn) {
			$ts = microtime(true);

			call_user_func($fn);

			$duration = microtime(true) - $ts;

			if ($duration < $expectedMicroTime)
				$this->fail("Operation should take more than {$expectedMicroTime}s, but took " . round($duration, 3) . 's');
		}

		protected function getNextMessage($handle, $timeout = 1000000) {
			$timeWaited = 0;

			while (!($lastRead = fgets($handle))) {
				usleep(50000);
				$timeWaited += 50000;
				if ($timeWaited > $timeout)
					$this->fail('Timeout waiting for message.');
			}

			return $lastRead;
		}

		protected function assertNextMessage($expected, $handle, $timeout = 1000000) {
			$timeWaited = 0;

			while (!($lastRead = fgets($handle))) {
				usleep(50000);
				$timeWaited += 50000;
				if ($timeWaited > $timeout)
					$this->fail('Timeout waiting for message "' . $expected . '"');
			}
			$this->assertEquals($expected, $lastRead);
		}

		protected function assertNoMessage($handle, $forDurationSeconds) {
			sleep($forDurationSeconds);
			$this->assertEmpty(fgets($handle));
		}

		protected function waitForChild($pid) {
			if (posix_kill($pid, 0))
				while ($pid != pcntl_wait($status)) {
				}
		}

		protected function sendMessage($message, $handle) {
			fwrite($handle, $message);
		}

		protected function fork(callable $parentFn, callable $childFn) {
			$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
			$pid     = pcntl_fork();
			if ($pid == -1) {
				throw new \Exception('Could not fork');
			}
			else if ($pid) {
				// parent

				// close socket
				fclose($sockets[0]);

				// non-blocking read
				stream_set_blocking($sockets[1], false);

				// call user function
				call_user_func($parentFn, $sockets[1], $pid);


				// close socket
				fclose($sockets[1]);

				// wait for child to die
				$this->waitForChild($pid);
			}
			else {
				// child

				// close socket
				fclose($sockets[1]);

				DB::purge(null);
				DB::purge('other');

				// call user function
				call_user_func($childFn, $sockets[0]);

				// close socket
				fclose($sockets[0]);

				die();
			}
		}
	}