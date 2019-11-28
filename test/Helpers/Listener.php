<?php


	namespace MehrItLaraTransactionWaitingEventsTest\Helpers;


	class Listener
	{
		public static $callCount = 0;

		public function handle() {

			++self::$callCount;

		}
	}