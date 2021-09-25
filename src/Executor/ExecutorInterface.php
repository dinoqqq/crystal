<?php

namespace Crystal\Executor;

use Crystal\Task\TaskInterface;

interface ExecutorInterface
{
    public function validatePrepareAndExecuteCrystalTask(
        TaskInterface $task,
        string $class,
        int $crystalTaskId,
        int $timeout,
        int $cooldown
    ): bool;
}
