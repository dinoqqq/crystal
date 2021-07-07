<?php

namespace Crystal\Test\Mock;

use Crystal\Task\TaskFactoryInterface;
use Crystal\Task\TaskInterface;
use Exception;

class TaskFactory implements TaskFactoryInterface 
{
    /**
     * @throws Exception
     */
    public function create(string $className): TaskInterface
    {
        return new $className;
    }
}
