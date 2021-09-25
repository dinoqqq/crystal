<?php

namespace Crystal\Executor;

interface ExecutorFactoryInterface
{
    public function create(): ExecutorInterface;
}
