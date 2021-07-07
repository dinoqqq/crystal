<?php

namespace Crystal\Task;

interface TaskFactoryInterface {
    public function create(string $className): TaskInterface;
}

