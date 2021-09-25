<?php

namespace Crystal\Service;

use Crystal\Crystal;
use Exception;
use Crystal\Entity\CrystalTask;
use Crystal\StateChangeStrategy\StateChangeStrategyFactory;

class CrystalTasksRescheduleService
{
    private $_crystalTasksBaseService;
    private $_stateChangeStrategyFactory;

    public function __construct(
        CrystalTasksBaseService $crystalTasksBaseService,
        StateChangeStrategyFactory $stateChangeStrategyFactory
    ) {
        $this->_crystalTasksBaseService = $crystalTasksBaseService;
        $this->_stateChangeStrategyFactory = $stateChangeStrategyFactory;
    }

    /**
     * @throws Exception
     */
    public function rescheduleCrystalTask(CrystalTask $crystalTask): bool
    {
        try {
            $stateChangeStrategy = $this->_stateChangeStrategyFactory->create(
                $crystalTask->getState(),
                CrystalTask::STATE_CRYSTAL_TASK_NEW
            );

            $success = $this->_crystalTasksBaseService->saveStateChange($crystalTask, $stateChangeStrategy);
            if (!$success) {
                throw new Exception();
            }

            Crystal::$logger->info('CRYSTAL-0016: Rescheduled CrystalTask', [
                'crystalTask' => $crystalTask,
            ]);

            return true;
        } catch (Exception $e) {
            $this->_crystalTasksBaseService->saveCrystalTaskAndIncreaseErrorTries($crystalTask);

            Crystal::$logger->error('CRYSTAL-0017: Could not reschedule CrystalTask', [
                'crystalTask' => $crystalTask,
                'errorMessage' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
