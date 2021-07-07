<?php
namespace Crystal\Service;

use Exception;
use Crystal\Entity\CrystalTask;
use Crystal\PriorityStrategy\ExtendedPriorityStrategyInterface;
use Crystal\PriorityStrategy\PriorityStrategyInterface;
use Crystal\StateChangeStrategy\StateChangeStrategyFactory;
use Crystal\Crystal;

class CrystalTasksExecuteService
{
    private $_crystalTasksBaseService;
    private $_stateChangeStrategyFactory;

    public function __construct(
        CrystalTasksBaseService $crystalTasksBaseService,
        StateChangeStrategyFactory $stateChangeStrategyFactory
    )
    {
        $this->_crystalTasksBaseService = $crystalTasksBaseService;
        $this->_stateChangeStrategyFactory = $stateChangeStrategyFactory;
    }

    /**
     * @throws Exception
     */
    public function getNextToBeExecutedCrystalTasksByPriorityStrategyAndChangeState(
        PriorityStrategyInterface $priorityStrategy,
        $availableExecutionSlots
    ): array
    {
        $stateChangeStrategy = $this->_stateChangeStrategyFactory->create(
            CrystalTask::STATE_CRYSTAL_TASK_NEW,
            CrystalTask::STATE_CRYSTAL_TASK_RUNNING
        );

        $this->_crystalTasksBaseService->beginTransaction();

        $crystalTasks = null;

        try {
            if (!$priorityStrategy instanceof ExtendedPriorityStrategyInterface) {
                $crystalTasks = $this->_crystalTasksBaseService
                    ->getNextToBeExecutedCrystalTasksWithForUpdate($availableExecutionSlots);
            } else {
                $taskClassesAndGrantedExecutionSlots = $priorityStrategy->getTaskClassesAndGrantedExecutionSlots($availableExecutionSlots);
                if (is_null($taskClassesAndGrantedExecutionSlots)) {
                    $this->_crystalTasksBaseService->rollbackTransaction();
                    return [];
                }

                $crystalTasks = $this->_crystalTasksBaseService
                    ->getNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdate($taskClassesAndGrantedExecutionSlots);
            }

            if (is_null($crystalTasks)) {
                $this->_crystalTasksBaseService->rollbackTransaction();
                return [];
            }

            $this->_crystalTasksBaseService->saveStateChangeMultiple($crystalTasks, $stateChangeStrategy);
            $this->_crystalTasksBaseService->commitTransaction();
        } catch (Exception $e) {
            $this->_crystalTasksBaseService->rollbackTransaction();

            Crystal::$logger->error('CRYSTAL-0015: one of the getNextToBeExecutedCrystalTasks functions failed', [
                'availableExecutionSlots' => $availableExecutionSlots,
                'crystalTasks' => $crystalTasks,
                'errorMessage' => $e->getMessage(),
                'taskClassesAndPriority' => $taskClassesAndGrantedExecutionSlots ?? []
            ]);

            throw $e;
        }

        return $crystalTasks;
    }

    /**
     * Set the state after execution
     *
     * Special case when we have a depend_on task:
     * - depender start_date >= dependee end_date
     * (because we always want to be sure the depender fully ran after its dependee completed)
     *
     * @throws Exception
     */
    public function getCrystalTaskStateAfterExecution(CrystalTask $crystalTask): string
    {
        if ($this->_crystalTasksBaseService->hasDependOnDependency($crystalTask)) {
            return $this->_crystalTasksBaseService->isDependOnCrystalTaskCompleted($crystalTask);
        }

        return CrystalTask::STATE_CRYSTAL_TASK_COMPLETED;
    }

}