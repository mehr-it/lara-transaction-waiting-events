# Laravel events waiting for transactions to complete
This package implements queued events for Laravel which are **guaranteed not be handled before** any pending database 
transaction has been closed.

## Why is it necessary?
Queued event listeners often rely on data that is written by the event emitter. Even the usage of 
database transactions is required or at least extremely recommendable, Laravel does not offer a
mechanism to block event handling after any pending database transactions are done.

Many other packages dealing with events and transactions, simply collect events in memory until the
database transactions have been committed. Then they send them to queue. However if the process runs
into an error condition after committing and before having sent all events to the queue, you will
loose some events. Imagine a customer order never being processed due to a lost event...

It would be much better to emit the event first and then commit the database transaction after the event 
has been sent (Note: the event handler must gracefully handle cases where the transaction is rolled 
back  - which usually is simple).


## How does it work?

Whenever an event is dispatched to a queued listener while a database transaction is open, the
event dispatcher will write a lock entry to the database which is released as soon as the 
transaction is closed. Queued listeners wait for the "transaction lock" to be released before being
invoked. That's all we need.

**Important: Event listeners are invoked even after the transaction has been rolled back**! It's up
to the listener to deal with cases like this. Since it is always a very good idea that the listener
checks any preconditions when invoked (instead of assuming a specific state of the system), their
should be no extra work here.

### Implementation details

This packages extends Laravel's event dispatcher to set "transaction locks" whenever a queued
listener (marked with the `WaitsForTransactions` interface) is invoked. It uses 
[mehr-it/lara-mysql-locks](https://github.com/mehr-it/lara-mysql-locks) to set the 
"transaction locks". Therefore **this package only works for MySQL connections**. The lock implementation
assures that locks are always removed when a transaction ends. No matter if committed, rolled back 
or the process died unexpectedly.

Before calling the queued listener, any transaction locks are checked for existence. If a 
transaction lock still exists, event handling job is released to the queue again and is retried
later.

## Requirements

* Laravel >= 5.8
* PHP >= 7.1
* MySQL >= 5.7.5

**This package only works with MySQL database connections!**


## Installation

    composer require mehr-it/lara-transaction-waiting-events

This package uses Laravel's package auto-discovery, so the service provider and aliases will be 
loaded automatically.

## Usage
There is only one thing to do, to make queued event handlers wait for transactions to be complete. 
The `WaitsForTransactions` marker interface has to be added to the listener class:

    class Listener implements ShouldQueue, WaitsForTransactions
    {
        public function handle($event) {

        }
    }
  
    
### Specify transaction lock details

The listener may specify some options for the transaction locks:


#### Custom connection
By default listeners only wait for transactions using the default DB connection. You may pass in
another connection name or specify multiple connections using the `waitForTransactions` property:

    class Listener implements ShouldQueue, WaitsForTransactions
    {
        public $waitForTransactions = ['default-connection', 'secondary-connection'];
     
        public function handle($event) {
        
        }
    }
    
#### Timeout and retry delay
By default the listener waits up to 1s for transactions to complete. If yet not complete, the
handler job is released back to queue to be retried in 5s. If you are using long-running
transactions, you might want to adapt these values:

    class Listener implements ShouldQueue, WaitsForTransactions
    {
        // the time to wait for transactions to complete
        public $waitForTransactionsTimeout = 7;
        
        // the retry delay
        public $waitForTransactionsRetryAfter = 30;
      
     
        public function handle($event) {
        
        }
    }

    