<?php

namespace Inna\RabbitQueue;

use Carbon\Carbon;
use ReflectionClass;
use ReflectionProperty;
use think\Model;

abstract class Job
{
    /**
     * 队列名称
     *
     * @var string
     */
    protected $queue;

    /**
     * 任务UUID
     *
     * @var string
     */
    protected $uuid;

    /**
     * 延迟时间（秒）
     *
     * @var int
     */
    protected $delay = 0;

    /**
     * 已尝试次数
     *
     * @var int
     */
    protected $attempts = 0;

    /**
     * 最大尝试次数
     *
     * @var int
     */
    protected $maxTries = 3;

    /**
     * 重试延迟时间
     *
     * @var int
     */
    protected $retryAfter = 60;

    /**
     * 执行任务超时时间
     *
     * @var int
     */
    protected $timeout = 120;

    /**
     * 执行任务
     *
     * @return void
     */
    abstract public function handle();

    /**
     * 设置延迟时间
     *
     * @param  \Carbon\Carbon|int $delay
     * @return static
     */
    public function delay($delay)
    {
        if (class_exists(Carbon::class) && $delay instanceof Carbon) {
            $delay = Carbon::now()->diffInRealSeconds($delay, false);
        }

        $this->delay = max($delay, 0);

        return $this;
    }

    /**
     * 分发任务
     *
     * @return void
     */
    public function dispatch()
    {
        if ($this instanceof ShouldQueue) {
            Queue::push($this->generateUuid());
        } else {
            $this->handle();
        }
    }

    /**
     * 任务失败
     *
     * @param  \Throwable $e
     * @return void
     */
    public function failed($e)
    {
        //
    }

    /**
     * 生成UUID
     *
     * @return static
     */
    public function generateUuid()
    {
        $this->uuid = bin2hex(random_bytes(12));

        return $this;
    }

    /**
     * 设置队列名称
     *
     * @param  string $queue
     * @return $this
     */
    public function queue($queue)
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * 获取队列名称
     *
     * @return string
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * 获取任务UUID
     *
     * @return string
     */
    public function getUuid()
    {
        return $this->uuid;
    }

    /**
     * 获取任务名称
     *
     * @return string
     */
    public function getName()
    {
        return static::class;
    }

    /**
     * 获取任务主体
     *
     * @return string
     */
    public function getBody()
    {
        return serialize($this);
    }

    /**
     * 获取延迟时间
     *
     * @return int
     */
    public function getDelay()
    {
        return $this->delay;
    }

    /**
     * 获取已尝试次数
     *
     * @return int
     */
    public function getAttempts()
    {
        return $this->attempts;
    }

    /**
     * 增加已尝试次数
     *
     * @return void
     */
    public function hitAttempts()
    {
        $this->attempts++;
    }

    /**
     * 获取最大尝试次数
     *
     * @return int
     */
    public function getMaxTries()
    {
        return $this->maxTries;
    }

    /**
     * 获取重试延迟时间
     *
     * @return int
     */
    public function getRetryAfter()
    {
        return $this->retryAfter;
    }

    /**
     * 获取执行超时时间
     *
     * @return int
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    public function __sleep()
    {
        $propertyNames = [];
        $properties = (new ReflectionClass($this))->getProperties();

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $propertyValue = $this->getPropertyValue($property);

            if ($propertyValue instanceof Model) {
                $property->setValue($this, new SerializeModel(
                    get_class($propertyValue),
                    $propertyValue->getData($propertyValue->getPk())
                ));
            }

            $propertyNames[] = $property->getName();
        }

        return $propertyNames;
    }

    public function __wakeup()
    {
        $properties = (new ReflectionClass($this))->getProperties();

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $propertyValue = $this->getPropertyValue($property);

            if ($propertyValue instanceof SerializeModel) {
                $model = (new $propertyValue->class);

                $property->setValue($this, $model->where($model->getPk(), $propertyValue->id)->findOrFail());
            }
        }
    }

    /**
     * 获取对象属性值
     *
     * @param  \ReflectionProperty $property
     * @return mixed
     */
    protected function getPropertyValue(ReflectionProperty $property)
    {
        $property->setAccessible(true);

        return $property->getValue($this);
    }
}
