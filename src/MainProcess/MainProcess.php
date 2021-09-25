<?php

namespace Crystal\MainProcess;

use Exception;
use Crystal\Config\TaskConfig;
use Crystal\Config\ConfigInterface;
use Crystal\Task\TaskFactoryInterface;

class MainProcess implements MainProcessInterface
{

    private $_name;
    private $_tasks;
    private $_data;

    /**
     * @throws Exception
     */
    public function __construct(
        ConfigInterface $config,
        TaskFactoryInterface $taskFactory,
        string $name,
        $data
    ) {
        $this->_name = $name;
        $this->_data = $data;

        $mainProcessConfigArray = $config->mainProcessNameToConfigArray($name);
        $this->setTasks($mainProcessConfigArray['tasks'] ?? [], $config, $taskFactory, $data);
    }

    /**
     * @throws Exception
     */
    private function setTasks($tasks, ConfigInterface $config, TaskFactoryInterface $taskFactory, $data): void
    {
        foreach ($tasks as $mainProcessTaskConfig) {
            $mainProcessTaskConfig = $config->mergeBaseTaskConfigWithMainProcessTaskConfig($mainProcessTaskConfig);
            $taskConfig = new TaskConfig($mainProcessTaskConfig);
            $task = $taskFactory->create($taskConfig->getClass());
            $task->setTaskConfig($taskConfig);
            $task->setData($data);
            $this->_tasks[] = $task;
        }
    }


    public function getName(): string
    {
        return $this->_name;
    }

    public function getData()
    {
        return $this->_data;
    }

    public function getTasks(): array
    {
        return $this->_tasks;
    }

    /**
     * @throws Exception
     */
    public static function create(
        ConfigInterface $config,
        TaskFactoryInterface $taskFactory,
        string $name,
        $data
    ): MainProcessInterface {
        return new self($config, $taskFactory, $name, $data);
    }
}
