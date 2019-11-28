<?php


	namespace MehrIt\LaraTransactionWaitingEvents;


	use Illuminate\Events\Dispatcher;

	/**
	 * Extended event dispatcher supporting events waiting for database transactions
	 * @package MehrIt\LaraTransactionWaitingEvents
	 */
	class EventDispatcher extends Dispatcher
	{
		use CreatesListenerJobsWaitingForTransaction;

	}