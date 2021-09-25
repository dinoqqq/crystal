<?php

namespace Crystal\Service;

use Crystal\Crystal;
use Exception;
use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Entity\CrystalTask;
use Crystal\StateChangeStrategy\StateChangeStrategyFactory;

class CrystalTasksQueueService
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
     * Save all CrystalTasks for the QueueHeartbeat process. Done in 1 transaction.
     *
     * @throws CrystalTaskStateErrorException
     */
    public function saveQueue(array $queue): bool
    {
        $this->_crystalTasksBaseService->beginTransaction();

        try {
            $queue = $this->prepareCrystalTasksForSaving($queue);
            foreach ($queue as $crystalTask) {
                $this->_crystalTasksBaseService->saveWithoutTransaction($crystalTask);
            }
            $this->_crystalTasksBaseService->commitTransaction();
        } catch (CrystalTaskStateErrorException $e) {
            $this->_crystalTasksBaseService->rollbackTransaction();
            throw $e;
        } catch (Exception $e) {
            Crystal::$logger->error('CRYSTAL-0004: saveAllForQueueHeartbeat failed to save', [
                'queue' => $queue,
                'errorMessage' => $e->getMessage(),
            ]);
            $this->_crystalTasksBaseService->rollbackTransaction();
            return false;
        }

        return true;
    }

    /**
     * Set the right data for the crystalTasks to be saved
     *
     * Create NEW when not existing
     * When existing, take "some" data of the new task and simply reschedule the old task
     *
     * Note: Skip the tasks simply with a wrong state, they will be rescheduled by the RESCHEDULE process.
     * Note: When a task is somehow already RUNNING, we throw an exception
     * Note: When a "dependOn" task is somehow already RUNNING, we simply skip it, since they will never get
     * the state COMPLETED when their dependee is not COMPLETED yet.
     *
     * @throws CrystalTaskStateErrorException
     * @throws Exception
     */
    private function prepareCrystalTasksForSaving(array $queue): array
    {
        $queueNew = [];

        // have the same insert order, to avoid deadlocks
        $queue = $this->_crystalTasksBaseService->sortByClassNameEntityUidAndRangeId($queue);

        foreach ($queue as $crystalTask) {
            // It can be that the tasks doesn't exist yet, just queue it then (state NOTHING to NEW)
            $crystalTaskExisting = $this->_crystalTasksBaseService->getUniqueCrystalTaskWithForUpdate($crystalTask);
            if (!$crystalTaskExisting instanceof CrystalTask) {
                $queueNew[] = $crystalTask;
                continue;
            }

            $stateChangeStrategy = $this->_stateChangeStrategyFactory->create(
                $crystalTaskExisting->getState(),
                CrystalTask::STATE_CRYSTAL_TASK_NEW
            );

            try {
                $this->_crystalTasksBaseService->validateAndChangeStateOnCrystalTask($crystalTaskExisting, $crystalTask, $stateChangeStrategy);
            } catch (CrystalTaskStateErrorException $e) {
                throw $e;
            } catch (Exception $e) {
                if ($this->isValidationExceptionCritical($e)) {
                    $data = [
                        'crystalTask' => $crystalTask,
                        'crystalTaskExisting' => $crystalTaskExisting,
                        'stateChangeStrategy' => get_class($stateChangeStrategy),
                    ];

                    Crystal::$logger->error('CRYSTAL-0021: ' . $e->getMessage(), $data);
                }

                continue;
            }

            // Do takeover some values from the new task
            $crystalTaskExisting->copyNewToExistingTaskForSave($crystalTask);

            $queueNew[] = $crystalTaskExisting;
        }

        return $queueNew;
    }

    /**
     * It is expected (possible) that the state has changed or task is dirty, while QUEUEING. For all other cases, it is
     * considered critical.
     */
    private function isValidationExceptionCritical(Exception $e): bool
    {
        return !in_array($e->getCode(), [
            $this->_crystalTasksBaseService::EXCEPTION_CODE_VALIDATION_DIRTY,
            $this->_crystalTasksBaseService::EXCEPTION_CODE_VALIDATION_STATE_CHANGE,
        ]);
    }
}
