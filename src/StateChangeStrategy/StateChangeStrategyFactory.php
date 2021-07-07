<?php

namespace Crystal\StateChangeStrategy;

use Exception;

use Crystal\Entity\CrystalTask;
use Crystal\Service\CrystalTasksBaseService;

class StateChangeStrategyFactory
{
    private $_crystalTasksBaseService;

    public function __construct(
        CrystalTasksBaseService $crystalTasksBaseService
    )
    {
        $this->_crystalTasksBaseService = $crystalTasksBaseService;
    }

    /**
     * @throws Exception
     */
    public function create(string $stateFrom, string $stateTo): StateChangeStrategyInterface
    {
        if ($stateFrom === CrystalTask::STATE_CRYSTAL_TASK_COMPLETED && $stateTo === CrystalTask::STATE_CRYSTAL_TASK_NEW) {
            return new CompletedToNewStateChangeStrategy();
        }

        if ($stateFrom === CrystalTask::STATE_CRYSTAL_TASK_DEAD && $stateTo === CrystalTask::STATE_CRYSTAL_TASK_NEW) {
            return new DeadToNewStateChangeStrategy();
        }

        if ($stateFrom === CrystalTask::STATE_CRYSTAL_TASK_ERROR && $stateTo === CrystalTask::STATE_CRYSTAL_TASK_NEW) {
            return new ErrorToNewStateChangeStrategy();
        }

        if ($stateFrom === CrystalTask::STATE_CRYSTAL_TASK_NEW && $stateTo === CrystalTask::STATE_CRYSTAL_TASK_NEW) {
            return new NewToNewStateChangeStrategy();
        }

        if ($stateFrom === CrystalTask::STATE_CRYSTAL_TASK_NEW && $stateTo === CrystalTask::STATE_CRYSTAL_TASK_RUNNING) {
            return new NewToRunningStateChangeStrategy();
        }

        if ($stateFrom === CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED && $stateTo === CrystalTask::STATE_CRYSTAL_TASK_NEW) {
            return new NotCompletedToNewStateChangeStrategy();
        }

        if ($stateFrom === CrystalTask::STATE_CRYSTAL_TASK_RUNNING && $stateTo === CrystalTask::STATE_CRYSTAL_TASK_COMPLETED) {
            return new RunningToCompletedStateChangeStrategy();
        }

        if ($stateFrom === CrystalTask::STATE_CRYSTAL_TASK_RUNNING && $stateTo === CrystalTask::STATE_CRYSTAL_TASK_ERROR) {
            return new RunningToErrorStateChangeStrategy();
        }

        if ($stateFrom === CrystalTask::STATE_CRYSTAL_TASK_RUNNING && $stateTo === CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED) {
            return new RunningToNotCompletedStateChangeStrategy();
        }

        // Erroneous state changes that require proper handling

        if ($stateFrom === CrystalTask::STATE_CRYSTAL_TASK_RUNNING && $stateTo === CrystalTask::STATE_CRYSTAL_TASK_NEW) {
            return new RunningToNewStateChangeStrategy(
                $this->_crystalTasksBaseService
            );
        }

        throw new Exception('StateChangeStrategy not found');
    }

}
