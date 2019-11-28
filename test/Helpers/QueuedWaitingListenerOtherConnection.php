<?php


	namespace MehrItLaraTransactionWaitingEventsTest\Helpers;


	class QueuedWaitingListenerOtherConnection extends QueuedWaitingListener
	{

		public $waitForTransactions = ['other'];

	}