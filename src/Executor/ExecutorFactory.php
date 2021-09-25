<?php

namespace Crystal\Executor;

use Crystal\Database\CrystalTasksTable;
use Crystal\StateChangeStrategy\StateChangeStrategyFactory;
use Exception;
use Crystal\Service\CrystalTasksBaseService;
use Crystal\Service\CrystalTasksExecuteService;
use Psr\Container\ContainerInterface;

class ExecutorFactory implements ExecutorFactoryInterface
{
    private $_container;

    public function __construct(
        ContainerInterface $container
    ) {
        $this->_container = $container;
    }

    /**
     * @throws Exception
     */
    public function create(): ExecutorInterface
    {
        return new Executor(
            $this->_container->get(CrystalTasksBaseService::class),
            $this->_container->get(CrystalTasksExecuteService::class),
            $this->_container->get(StateChangeStrategyFactory::class),
            $this->_container->get(CrystalTasksTable::class)
        );
    }
}
