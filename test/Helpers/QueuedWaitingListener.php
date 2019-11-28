<?php


	namespace MehrItLaraTransactionWaitingEventsTest\Helpers;


	use Illuminate\Contracts\Queue\ShouldQueue;
	use MehrIt\LaraTransactionWaitingEvents\Contracts\WaitsForTransactions;

	class QueuedWaitingListener implements ShouldQueue, WaitsForTransactions
	{
		public function handle($event) {

		}
	}