# Think Rabbit Queue
RabbitMQ Queue for ThinkPHP 5.

## Installation
```shell
$ composer require inna/think-rabbit-queue
```

## Usage
```php
<?php

use Inna\RabbitQueue\Job;
use Inna\RabbitQueue\ShouldQueue;

class CancelOrderJob extends Job implements ShouldQueue
{
    public $order;
    
    public function __construct($order)
    {
        $this->order = $order;
    }

    public function handle() 
    {
        if ($this->order->shouldCancel()) {
            $this->order->cancel();
        }
    }
}
```

```php
<?php

use Carbon\Carbon;
use Inna\RabbitQueue\Queue;

$order = Order::find(1);

$job = (new CancelOrderJob($order))->delay(Carbon::now()->addDays(7));

$job->dispatch();
```

```php
<?php

use Inna\RabbitQueue\Queue;

Queue::consume();
```
