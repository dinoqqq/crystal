<?php

namespace Crystal;

use Crystal\Config\Config;
use Crystal\Config\ConfigInterface;
use Crystal\Container\Container;
use Crystal\Database\CrystalTasksDependenciesTable;
use Crystal\Database\CrystalTasksTable;
use Crystal\Executor\ExecutorFactory;
use Crystal\Executor\ExecutorFactoryInterface;
use Crystal\Heartbeat\ExecuteHeartbeat;
use Crystal\Heartbeat\QueuerInterface;
use Crystal\Heartbeat\HeartbeatFactory;
use Crystal\Heartbeat\QueueHeartbeat;
use Crystal\Heartbeat\RescheduleHeartbeat;
use Crystal\Service\CrystalTasksBaseService;
use Crystal\Service\CrystalTasksExecuteService;
use Crystal\Service\CrystalTasksQueueService;
use Crystal\Service\CrystalTasksRescheduleService;
use Exception;
use Psr\Log\LoggerInterface;

class Crystal
{
    private $_container;
    private $_config;
    public static $logger;

    /**
     * @throws Exception
     */
    public function __construct($config, LoggerInterface $logger = null)
    {
        self::$logger = $logger;
        $this->_config = $config;
    }

    public function start()
    {
        if (!isset($this->_config)) {
            throw new Exception('First set the config via the constructor, before calling start');
        }

        $this->_container = new Container($this->_config);
    }

    public function getConfig(): ConfigInterface
    {
        return $this->_container->get(Config::class);
    }

    public function getCrystalTasksBaseService(): CrystalTasksBaseService
    {
        return $this->_container->get(CrystalTasksBaseService::class);
    }

    public function getCrystalTasksQueueService(): CrystalTasksQueueService
    {
        return $this->_container->get(CrystalTasksQueueService::class);
    }

    public function getCrystalTasksExecuteService(): CrystalTasksExecuteService
    {
        return $this->_container->get(CrystalTasksExecuteService::class);
    }

    public function getCrystalTasksRescheduleService(): CrystalTasksRescheduleService
    {
        return $this->_container->get(CrystalTasksRescheduleService::class);
    }

    public function getCrystalTasksTable(): CrystalTasksTable
    {
        return $this->_container->get(CrystalTasksTable::class);
    }

    public function getCrystalTasksDependenciesTable(): CrystalTasksDependenciesTable
    {
        return $this->_container->get(CrystalTasksDependenciesTable::class);
    }

    public function getQueueHeartbeat(QueuerInterface $queuer): QueueHeartbeat
    {
        $heartbeatFactory = $this->_container->get(HeartbeatFactory::class);
        return $heartbeatFactory->create('queue', $queuer);
    }

    public function getExecuteHeartbeat(): ExecuteHeartbeat
    {
        $heartbeatFactory = $this->_container->get(HeartbeatFactory::class);
        return $heartbeatFactory->create('execute');
    }

    public function getRescheduleHeartbeat(): RescheduleHeartbeat
    {
        $heartbeatFactory = $this->_container->get(HeartbeatFactory::class);
        return $heartbeatFactory->create('reschedule');
    }

    public function getExecutorFactory(): ExecutorFactoryInterface
    {
        return $this->_container->get(ExecutorFactory::class);
    }
}