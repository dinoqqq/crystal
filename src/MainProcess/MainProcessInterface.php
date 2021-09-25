<?php

namespace Crystal\MainProcess;

use Crystal\Config\ConfigInterface;
use Crystal\Task\TaskFactoryInterface;

interface MainProcessInterface
{
    public function getTasks(): array;
    public function getName(): string;
    public function getData();
    public static function create(
        ConfigInterface $config,
        TaskFactoryInterface $taskFactory,
        string $name,
        $data
    ): MainProcessInterface;
}
