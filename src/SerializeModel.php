<?php

namespace Inna\RabbitQueue;

class SerializeModel
{
    /**
     * 模型类名称
     *
     * @var string
     */
    public $class;

    /**
     * 模型主键值
     *
     * @var mixed
     */
    public $id;

    /**
     * @param  string $class
     * @param  mixed $id
     * @return void
     */
    public function __construct($class, $id)
    {
        $this->class = $class;
        $this->id = $id;
    }
}
