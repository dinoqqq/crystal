<?php

namespace Crystal\Heartbeat;

use Crystal\Config\Config;
use Exception;

use Crystal\Service\CrystalTasksBaseService;
use Crystal\Service\CrystalTasksExecuteService;
use Crystal\Service\CrystalTasksQueueService;
use Crystal\Service\CrystalTasksRescheduleService;
use Crystal\PriorityStrategy\PriorityStrategyFactory;
use Psr\Container\ContainerInterface;

class HeartbeatFactory implements HeartbeatFactoryInterface
{
    private $_container;

    public function __construct(
        ContainerInterface $container
    )
    {
        $this->_container = $container;
    }

    /**
     * @throws Exception
     */
    public function create(string $type, QueuerInterface $queuer = null): HeartbeatInterface
    {
        switch ($type) {
            case 'queue':
                return new QueueHeartbeat(
                    $this->_container->get(Config::class),
                    $this->_container->get(CrystalTasksBaseService::class),
                    $this->_container->get(CrystalTasksQueueService::class),
                    $queuer
                );
            case 'execute':
                return new ExecuteHeartbeat(
                    $this->_container->get(Config::class),
                    $this->_container->get(CrystalTasksBaseService::class),
                    $this->_container->get(CrystalTasksExecuteService::class),
                    $this->_container->get(PriorityStrategyFactory::class)
                );
            case 'reschedule':
                return new RescheduleHeartbeat(
                    $this->_container->get(Config::class),
                    $this->_container->get(CrystalTasksBaseService::class),
                    $this->_container->get(CrystalTasksRescheduleService::class)
                );
            default:
                throw new Exception('Heartbeat type not recognized');
        }
    }
}
