<?php
namespace Crystal\Service;

use Crystal\Crystal;
use Crystal\RangeStrategy\UniqueIdRangeStrategy;
use Exception;
use Crystal\Database\CrystalTasksDependenciesTable;
use Crystal\Database\CrystalTasksTable;
use Crystal\Entity\CrystalTask;
use Crystal\Entity\CrystalTaskDependency;
use Crystal\StateChangeStrategy\StateChangeStrategyInterface;
use Crystal\Config\Config as CrystalConfig;
use Medoo\Medoo;

class CrystalTasksBaseService
{

    const EXCEPTION_CODE_VALIDATION_NOT_EXISTS = 100;
    const EXCEPTION_CODE_VALIDATION_DIRTY = 101;
    const EXCEPTION_CODE_VALIDATION_STATE_CHANGE = 102;

    private $_config;
    private $_database;
    private $_crystalTasksTable;
    private $_crystalConfig;
    private $_crystalTasksDependenciesTable;

    public function __construct(
        array $config,
        Medoo $database,
        CrystalTasksTable $crystalTasksTable,
        CrystalTasksDependenciesTable $crystalTasksDependenciesTable,
        ?CrystalConfig $crystalConfig
    )
    {
        $this->_config = $config;
        $this->_database = $database;
        $this->_crystalTasksTable = $crystalTasksTable;
        $this->_crystalTasksDependenciesTable = $crystalTasksDependenciesTable;
        $this->_crystalConfig = $crystalConfig;
    }

    public function countNextToBeExecutedCrystalTasks(array $taskClasses): array
    {
        return $this->_crystalTasksTable->countNextToBeExecutedCrystalTasks($taskClasses);
    }

    public function getNextToBeExecutedCrystalTasksWithForUpdate(int $limit): array
    {
        return $this->_crystalTasksTable->getNextToBeExecutedCrystalTasksWithForUpdate($limit);
    }

    public function getNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdate(array $taskClassesAndGrantedExecutionSlots): array
    {
        return $this->_crystalTasksTable->getNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdate($taskClassesAndGrantedExecutionSlots);
    }

    /**
     * Get the dead crystal tasks
     *
     * @throws Exception
     */
    public function getDeadOrNotCompletedCrystalTasks(int $limit = null): array
    {
        return $this->_crystalTasksTable->getDeadOrNotCompletedCrystalTasks($limit);
    }

    /**
     * Check if there is a dependee that have not finished yet or have an overlap in time while the depender ran.
     *
     * @throws Exception
     */
    public function isDependeeUnfinishedOrOverlapping(CrystalTask $crystalTask): bool
    {
        return $this->_crystalTasksTable->isDependeeUnfinishedOrOverlapping($crystalTask);
    }

    /**
     * @throws Exception
     */
    public function isDependOnCrystalTaskCompleted(CrystalTask $crystalTask): string
    {
        if ($this->isDependeeUnfinishedOrOverlapping($crystalTask)) {
            return CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED;
        }

        return CrystalTask::STATE_CRYSTAL_TASK_COMPLETED;
    }

    public function hasDependOnDependency(CrystalTask $crystalTask): bool
    {
        return $this->_crystalTasksDependenciesTable->hasDependOnDependency($crystalTask->class);
    }

    /**
     * @throws Exception
     */
    public function countAvailableExecutionSlots(): int
    {
        $runningCrystalTasks = $this->countRunningCrystalTasks();
        $availableExecutionSlots = $this->_crystalConfig->getConfigByKey('maxExecutionSlots') - $runningCrystalTasks;
        if ($availableExecutionSlots < 0) {
            $availableExecutionSlots = 0;
        }
        return $availableExecutionSlots;
    }

    /**
     * @throws Exception
     */
    public function countRunningCrystalTasks(): int
    {
        return $this->_crystalTasksTable->countRunningCrystalTasks();
    }

    /**
     * Get all the tasks by a certain entity_uid and range.
     *
     * This will query other tables. This will also calculate a unique hash for the entity_uid column, subtract
     * the first letter and see if the range provided matches that.
     *
     * @throws Exception
     */
    public function getByEntityUidAndRange(string $entityUid, string $range): array
    {
        return $this->_crystalTasksTable->getByEntityUidAndRange($entityUid, $range);
    }

    public function getUniqueCrystalTaskWithForUpdate(CrystalTask $crystalTask): ?CrystalTask
    {
        return $this->_crystalTasksTable->getUniqueCrystalTaskWithForUpdate($crystalTask);
    }

    public function getCrystalTaskByIdWithForUpdate(CrystalTask $crystalTask): ?CrystalTask
    {
        return $this->_crystalTasksTable->getCrystalTaskByIdWithForUpdate($crystalTask);
    }

    /**
     * @throws Exception
     */
     public function getUnfinishedCrystalTasksByRangeAndMainProcessName(int $range, string $mainProcessName): array
     {
         $taskClasses = $this->_crystalConfig->getTaskClassesByMainProcessNameAndRangeStrategy(
             $mainProcessName,
             UniqueIdRangeStrategy::class
         );
         return $this->_crystalTasksTable->getUnfinishedCrystalTasksByRangeAndTaskClasses($range, $taskClasses);
     }

    /**
     * @throws Exception
     */
     public function isMainProcessNameInConfig(string $mainProcessName): bool
     {
         return $this->_crystalConfig->isMainProcessNameInConfig($mainProcessName);
     }

    public function beginTransaction(): void
    {
        $this->_database->query('START TRANSACTION');
    }

    public function commitTransaction(): void
    {
        $this->_database->query('COMMIT');
    }

    public function rollbackTransaction(): void
    {
        $this->_database->query('ROLLBACK');
    }

    /**
     * @throws Exception
     */
    public function saveWithoutTransaction(CrystalTask $crystalTask): bool
    {
        return $this->_crystalTasksTable->save($crystalTask);
    }

    /**
     * Increase error and save
     *
     * If maxErrorTries is reached, the status will also be set to ERROR
     *
     * @throws Exception
     */
    public function saveCrystalTaskAndIncreaseErrorTries(CrystalTask $crystalTask): bool
    { 
        $crystalTask->increaseErrorTries();

        // TODO: change state should be in transaction
        if ($this->isMaxErrorTriesReached($this->_config, $crystalTask)) {
            $crystalTask->forceStateError();

            Crystal::$logger->error('CRYSTAL-0009: saveCrystalTaskAndIncreaseErrorTries max error tries reached, setting state to ERROR', [
                'crystalTask' => $crystalTask,
            ]);
        }
        return $this->saveWithoutTransaction($crystalTask);
    }

    public function saveStateChange(CrystalTask $crystalTask, StateChangeStrategyInterface $stateChangeStrategy): bool
    {
        $crystalTaskExisting = null;

        $this->beginTransaction();

        try {
            $crystalTaskExisting = $this->getUniqueCrystalTaskWithForUpdate($crystalTask);

            $this->validateAndChangeStateOnCrystalTask($crystalTaskExisting, $crystalTask, $stateChangeStrategy);

            $saved = $this->saveWithoutTransaction($crystalTaskExisting);
            $this->commitTransaction();

            return $saved;
        } catch (Exception $e) {
            $data = [
                'crystalTask' => $crystalTask,
                'stateChangeStrategy' => get_class($stateChangeStrategy),
            ];
            if ($crystalTaskExisting instanceof CrystalTask) {
                $data['crystalTaskExisting'] = $crystalTaskExisting;
            }

            Crystal::$logger->error('CRYSTAL-0010: ' . $e->getMessage(), $data);

            $this->rollbackTransaction();
            return false;
        }
    }

    /**
     * Save state changes on multiple items.
     *
     * Do not use a transaction, that can be done outside of this function
     */
    public function saveStateChangeMultiple($crystalTasks, StateChangeStrategyInterface $stateChangeStrategy): void
    {
        $crystalTaskExisting = null;

        try {
            $crystalTasks = $this->sortByClassNameEntityUidAndRangeId($crystalTasks);

            foreach ($crystalTasks as $crystalTask) {
                $crystalTaskExisting = $this->getCrystalTaskByIdWithForUpdate($crystalTask);

                $this->validateAndChangeStateOnCrystalTask($crystalTaskExisting, $crystalTask, $stateChangeStrategy);

                $this->saveWithoutTransaction($crystalTaskExisting);
            }
        } catch (Exception $e) {
            $data = [
                'crystalTask' => $crystalTask ?? [],
                'stateChangeStrategy' => get_class($stateChangeStrategy),
            ];
            if ($crystalTaskExisting instanceof CrystalTask) {
                $data['crystalTaskExisting'] = $crystalTaskExisting;
            }

            Crystal::$logger->error('CRYSTAL-0010: ' . $e->getMessage(), $data);
        }
    }

    public function updateCrystalTasksDependencies(array $crystalTaskDependenciesNew): bool
    {
        try {
            $crystalTaskDependenciesExisting = $this->_crystalTasksDependenciesTable->getAll();
            $crystalTaskDependenciesToBeRemoved = $this->getCrystalTaskDependenciesToBeRemoved(
                $crystalTaskDependenciesExisting,
                $crystalTaskDependenciesNew
            );
            $crystalTaskDependenciesToBeAdded = $this->getCrystalTaskDependenciesToBeAdded(
                $crystalTaskDependenciesExisting,
                $crystalTaskDependenciesNew
            );

            foreach ($crystalTaskDependenciesToBeAdded as $crystalTaskDependencyToBeAdded) {
                $this->_crystalTasksDependenciesTable->insert($crystalTaskDependencyToBeAdded);
            }
            foreach ($crystalTaskDependenciesToBeRemoved as $crystalTaskDependencyToBeRemoved) {
                $this->_crystalTasksDependenciesTable->deleteByPK($crystalTaskDependencyToBeRemoved->id);
            }
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    private function getCrystalTaskDependenciesToBeRemoved(
        array $crystalTaskDependenciesExisting,
        array $crystalTaskDependenciesNew
    ): array
    {
        $crystalTaskDependenciesToBeRemoved = [];
        foreach ($crystalTaskDependenciesExisting as $crystalTaskDependencyExisting) {
            foreach ($crystalTaskDependenciesNew as $crystalTaskDependencyNew) {
                // When we have a match, skip to the next existing
                if ($crystalTaskDependencyExisting->getValuesUniqueIndexAsArray() === $crystalTaskDependencyNew->getValuesUniqueIndexAsArray()) {
                    continue 2;
                }
            }

            // If none matched, it means it needs to be removed
            $crystalTaskDependenciesToBeRemoved[] = $crystalTaskDependencyExisting;
        }
        return $crystalTaskDependenciesToBeRemoved;
    }

    private function getCrystalTaskDependenciesToBeAdded(
        array $crystalTaskDependenciesExisting,
        array $crystalTaskDependenciesNew
    ): array
    {
        $crystalTaskDependenciesToBeAdded = [];
        foreach ($crystalTaskDependenciesNew as $crystalTaskDependencyNew) {
            foreach ($crystalTaskDependenciesExisting as $crystalTaskDependencyExisting) {
                // When we have a match, skip to the next new
                if ($crystalTaskDependencyExisting->getValuesUniqueIndexAsArray() === $crystalTaskDependencyNew->getValuesUniqueIndexAsArray()) {
                    continue 2;
                }
            }

            // If none matched, it means it needs to be added
            $crystalTaskDependenciesToBeAdded[] = $crystalTaskDependencyNew;
        }
        return $crystalTaskDependenciesToBeAdded;
    }

    /**
     * Save a state change
     *
     * @throws Exception
     */
    public function validateAndChangeStateOnCrystalTask(
        ?CrystalTask $crystalTaskExisting,
        CrystalTask $crystalTask,
        StateChangeStrategyInterface $stateChangeStrategy
    ): void
    {
        // Should always exist
        if (!$crystalTaskExisting instanceof CrystalTask) {
            throw new Exception('CrystalTask does not exist anymore, weird', self::EXCEPTION_CODE_VALIDATION_NOT_EXISTS);
        }

        $isDirty = $crystalTaskExisting->isDirty($crystalTask);
        if (!$stateChangeStrategy->isDirtyShouldContinue($isDirty)) {
            throw new Exception('CrystalTask is dirty, but that is not allowed', self::EXCEPTION_CODE_VALIDATION_DIRTY);
        }

        $stateChanged = $stateChangeStrategy->changeState($crystalTaskExisting);
        if (!$stateChangeStrategy->stateNotChangedShouldContinue($stateChanged)) {
            throw new Exception('CrystalTask state not changed, but that is not allowed.', self::EXCEPTION_CODE_VALIDATION_STATE_CHANGE);
        }
    }

    private function isMaxErrorTriesReached(array $config, CrystalTask $crystalTask): bool
    {
        return $config['maxErrorTries'] <= $crystalTask->error_tries;
    }

    public function dependenciesConfigToCrystalTasksDependencies(array $dependenciesConfig): array
    {
        $crystalTaskDependencies = [];
        foreach ($dependenciesConfig as $dependencyConfig) {
            $crystalTaskDependencies[] = new CrystalTaskDependency($dependencyConfig);
        }
        return $crystalTaskDependencies;
    }

    public function sortByClassNameEntityUidAndRangeId(array $crystalTasks): array
    {
        usort($crystalTasks, function($a, $b) {
            return [$a->class, $a->entity_uid, $a->range] <=> [$b->class, $b->entity_uid, $b->range];
        });

        return $crystalTasks;
    }

    /**
     * Let ExecuteHeartbeat save an erroneous executed task.
     *
     * @throws Exception
     */
    public function saveAndSetErrorState(CrystalTask $crystalTask): bool
    {
        $crystalTask->increaseErrorTries();
        $crystalTask->stateRunningToError();
        return $this->_crystalTasksTable->save($crystalTask);
    }


}