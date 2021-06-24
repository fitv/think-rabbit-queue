<?php

namespace Inna\RabbitQueue;

/**
 * @method static void push(Job $job)
 * @method static int consume($sleep = 3)
 */
class Queue
{
    protected static $driver;

    /**
     * @param  string $method
     * @param  array $args
     * @return mixed
     */
    public static function __callStatic($method, $args)
    {
        if (is_null(static::$driver)) {
            static::$driver = new RabbitQueue;
        }

        return static::$driver->$method(...$args);
    }
}
