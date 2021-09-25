<?php

namespace Crystal\PriorityStrategy;

use Exception;
use Crystal\Service\CrystalTasksBaseService;

class PriorityStrategyFactory implements PriorityStrategyFactoryInterface
{
    private $_config;
    private $_crystalTasksBaseService;

    public function __construct(
        array $config,
        CrystalTasksBaseService $crystalTasksBaseService
    ) {
        $this->_config = $config;
        $this->_crystalTasksBaseService = $crystalTasksBaseService;
    }

    /**
     * @throws Exception
     */
    public function create(string $className): PriorityStrategyInterface
    {
        switch ($className) {
            case DivideTotalValueEquallyPriorityStrategy::class:
                return new DivideTotalValueEquallyPriorityStrategy(
                    $this->_config,
                    $this->_crystalTasksBaseService
                );

            case SortByDateCreatedPriorityStrategy::class:
                return new SortByDateCreatedPriorityStrategy(
                    $this->_config
                );

            default:
                throw new Exception('Name of priorityStrategy not found');
        }
    }
}
