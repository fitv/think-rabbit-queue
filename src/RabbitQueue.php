<?php

namespace Inna\RabbitQueue;

use ErrorException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class RabbitQueue
{
    const EXIT_SUCCESS = 0;
    const EXIT_ERROR = 1;

    protected $channel;

    protected $connection;

    protected $shouldQuit = false;

    public function __construct()
    {
        $this->connection = new AMQPStreamConnection(
            config('rabbitmq.host'),
            config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password'),
            config('rabbitmq.vhost')
        );
        $this->channel = $this->connection->channel();

        $this->channel->exchange_declare($this->getExchange(), 'x-delayed-message', false, true, false, false, false, new AMQPTable(['x-delayed-type' => 'direct']));
        $this->channel->queue_declare($this->getQueue(), false, true, false, false);
        $this->channel->queue_bind($this->getQueue(), $this->getExchange());
    }

    /**
     * 推送任务至队列
     *
     * @param  \app\common\library\Job $job
     * @return void
     */
    public function push(Job $job)
    {
        $msg = new AMQPMessage(serialize($job->queue($this->getQueue())), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
        $msg->set('application_headers', new AMQPTable([
            'x-delay' => ($job->getAttempts() ? $job->getRetryAfter() : $job->getDelay()) * 1000,
        ]));

        $this->channel->basic_publish($msg, $this->getExchange());
    }

    /**
     * 消费队列
     *
     * @param  int $sleep
     * @return int
     */
    public function consume($sleep = 3)
    {
        $this->listenForSignals();

        $this->channel->basic_qos(null, 1, null);
        $this->channel->basic_consume($this->getQueue(), '', false, false, false, false, [$this, 'process']);

        while ($this->channel->is_consuming()) {
            $this->channel->wait(null, true);

            if ($this->shouldQuit) {
                return self::EXIT_SUCCESS;
            }

            sleep($sleep);
        }
    }

    /**
     * 处理任务
     *
     * @param  \PhpAmqpLib\Message\AMQPMessage $message
     * @return void
     */
    public function process(AMQPMessage $message)
    {
        $job = unserialize($message->getBody());

        if (! $job instanceof Job) {
            $this->invalidHandler($message);

            return $message->ack();
        }

        $this->registerTimeoutHandler($job, $message);

        try {
            $this->output($job, 'Processing');

            $job->handle();

            $this->output($job, 'Processed');
        } catch (Throwable $e) {
            $this->output($job, 'Failed');

            $this->failedHandler($job, $e);
        }

        $message->ack();

        $this->resetTimeoutHandler();
    }

    /**
     * 获取队列名称
     *
     * @return string
     */
    public function getQueue()
    {
        return config('rabbitmq.prefix').'queue.default';
    }

    /**
     * 获取交换机名称
     *
     * @return string
     */
    public function getExchange()
    {
        return config('rabbitmq.prefix').'exchange.delay';
    }

    /**
     * 输出任务消息
     *
     * @param  \app\common\library\Job $job
     * @param  string $message
     * @return void
     */
    protected function output(Job $job, $message)
    {
        $now = date('Y-m-d H:i:s');
        $name = $job->getName();
        $uuid = $job->getUuid();

        echo "[{$now}][{$uuid}] {$message}: {$name}".PHP_EOL;
    }

    /**
     * 输出无效消息
     *
     * @param  \PhpAmqpLib\Message\AMQPMessage $message
     * @return void
     */
    protected function invalidHandler(AMQPMessage $message)
    {
        $now = date('Y-m-d H:i:s');
        $body = $message->getBody();

        echo "[{$now}] Invalid: {$body}".PHP_EOL;
    }

    /**
     * 处理失败任务
     *
     * @param \app\common\library\Job $job
     * @param  \Throwable $e
     * @return void
     */
    protected function failedHandler(Job $job, $e)
    {
        $job->hitAttempts();

        if ($job->getAttempts() >= $job->getMaxTries()) {
            $job->failed($e);
        } else {
            $this->push($job);
        }
    }

    /**
     * 监听进程信号
     *
     * @return void
     */
    protected function listenForSignals()
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () {
            $this->shouldQuit = true;
        });

        pcntl_signal(SIGTERM, function () {
            $this->shouldQuit = true;
        });
    }

    /**
     * 注册超时进程alarm信号
     *
     * @param  \app\common\library\Job $job
     * @param  \PhpAmqpLib\Message\AMQPMessage $message
     * @return void
     */
    protected function registerTimeoutHandler(Job $job, AMQPMessage $message)
    {
        pcntl_signal(SIGALRM, function () use ($job, $message) {
            $this->failedHandler($job, new JobTimeoutException);

            $message->ack();

            $this->kill(self::EXIT_ERROR);
        });

        pcntl_alarm(max($job->getTimeout(), 0));
    }

    /**
     * 清除进程alarm信号
     *
     * @return void
     */
    protected function resetTimeoutHandler()
    {
        pcntl_alarm(0);
    }

    /**
     * 终止进程
     *
     * @param  int $status
     * @return void
     */
    public function kill($status = 0)
    {
        if (extension_loaded('posix')) {
            posix_kill(getmypid(), SIGKILL);
        }

        exit($status);
    }

    public function __destruct()
    {
        try {
            $this->channel->close();
            $this->connection->close();
        } catch (ErrorException $e) {
            //
        }
    }
}
