<?php

	return [

		/*
		|--------------------------------------------------------------------------
		| Enable/disable waiting for transactions
		|--------------------------------------------------------------------------
		|
		| This option activates enables or disables transactions waiting for events
		|
		| You should set this setting to `false`, when using "sync" queue driver.
		| Otherwise events might not be not dispatched
		|
		*/

		'wait_for_transactions' => env('EVENTS_WAIT_FOR_TRANSACTIONS', true),


	];
