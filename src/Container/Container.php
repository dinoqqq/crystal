<?php


namespace Crystal\Container;


use Crystal\Config\Config;
use Crystal\Database\CrystalTasksDependenciesTable;
use Crystal\Database\CrystalTasksTable;
use Crystal\Database\Database;
use Crystal\Executor\ExecutorFactory;
use Crystal\Heartbeat\HeartbeatFactory;
use Crystal\PriorityStrategy\PriorityStrategyFactory;
use Crystal\Service\CrystalTasksBaseService;
use Crystal\Service\CrystalTasksExecuteService;
use Crystal\Service\CrystalTasksQueueService;
use Crystal\Service\CrystalTasksRescheduleService;
use Crystal\StateChangeStrategy\StateChangeStrategyFactory;
use Exception;
use Psr\Container\ContainerInterface;

class Container implements ContainerInterface
{
    private $_container;

    /**
     * @throws Exception
     */
    public function __construct(array $config)
    {
        $crystalConfig = new Config($config);
        $this->add(Config::class, $crystalConfig);

        $database = Database::getInstance($config);
        $this->add( Database::class, $database);

        $crystalTasksTable = new CrystalTasksTable($this->get(Database::class));
        $this->add(CrystalTasksTable::class, $crystalTasksTable);

        $crystalTasksDependenciesTable = new CrystalTasksDependenciesTable($this->get(Database::class));
        $this->add(CrystalTasksDependenciesTable::class, $crystalTasksDependenciesTable);

        $crystalTasksBaseService = new CrystalTasksBaseService(
            $config,
            $database,
            $crystalTasksTable,
            $crystalTasksDependenciesTable,
            $crystalConfig
        );
        $this->add(CrystalTasksBaseService::class, $crystalTasksBaseService);

        $stateChangeStrategyFactory = new StateChangeStrategyFactory(
            $crystalTasksBaseService
        );
        $this->add(StateChangeStrategyFactory::class, $stateChangeStrategyFactory);

        $crystalTasksQueueService = new CrystalTasksQueueService(
            $crystalTasksBaseService,
            $stateChangeStrategyFactory
        );
        $this->add(CrystalTasksQueueService::class, $crystalTasksQueueService);

        $crystalTasksRescheduleService =  new CrystalTasksRescheduleService(
            $crystalTasksBaseService,
            $stateChangeStrategyFactory
        );
        $this->add(CrystalTasksRescheduleService::class, $crystalTasksRescheduleService);

        $crystalTasksExecuteService = new CrystalTasksExecuteService(
            $crystalTasksBaseService,
            $stateChangeStrategyFactory
        );
        $this->add(CrystalTasksExecuteService::class, $crystalTasksExecuteService);

        $heartbeatFactory = new HeartbeatFactory($this);
        $this->add(HeartbeatFactory::class, $heartbeatFactory);

        $priorityStrategyFactory = new PriorityStrategyFactory(
            $config,
            $crystalTasksBaseService
        );
        $this->add(PriorityStrategyFactory::class, $priorityStrategyFactory);

        $executorFactory = new ExecutorFactory($this);
        $this->add(ExecutorFactory::class, $executorFactory);
    }

    public function get($id)
    {
        return $this->_container[$id];
    }

    public function has($id): bool
    {
        return array_key_exists($id, $this->_container);
    }

    private function add(string $id, $service)
    {
        $this->_container[$id] = $service;
    }



}