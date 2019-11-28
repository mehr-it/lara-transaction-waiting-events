<?php


	namespace MehrItLaraTransactionWaitingEventsTest\Helpers;


	use Illuminate\Contracts\Queue\ShouldQueue;

	class QueuedListener implements ShouldQueue
	{

		public function handle($event) {

		}

	}