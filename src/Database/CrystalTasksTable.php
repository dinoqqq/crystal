<?php

namespace Crystal\Database;

use DateTime;
use Exception;
use Crystal\Entity\CrystalTask;
use Medoo\Medoo;
use PDO;

class CrystalTasksTable extends AbstractTable
{
    use TableTrait;

    private const PRIMARY_KEY = 'id';
    private const TABLE_NAME = 'crystal_tasks';
    private const TABLE_COLUMNS = [
        'id',
        'class',
        'timeout',
        'cooldown',
        'entity_uid',
        'range',
        'date_start',
        'date_end',
        'state',
        'error_tries',
        'date_created',
    ];

    private $_database;

    protected const ENTITY = CrystalTask::class;

    public function __construct(
        Medoo $database
    )
    {
        $this->_database = $database;
    }

    private function getWhereClauseStateCrystalTaskNew(): string
    {
        return '(`state` = \'' . CrystalTask::STATE_CRYSTAL_TASK_NEW . '\')';
    }

    /**
     * A task that ran out of time needs some shutdown time as well
     */
    private function getWhereClauseStateCrystalTaskRunning(): string
    {
        return '(`state` = \'' . CrystalTask::STATE_CRYSTAL_TASK_RUNNING . '\' AND `date_start` >= DATE_SUB(\'' . (new DateTime)->format('Y-m-d H:i:s') . '\', INTERVAL (`timeout`+`cooldown`) SECOND))';
    }

    /**
     * A task that ran out of time needs some shutdown time as well
     * Note: here we also add the general cooldown, so it is 100% dead.
     */
    private function getWhereClauseCrystalTaskDead(): string
    {
        return '(`state` = \'' . CrystalTask::STATE_CRYSTAL_TASK_RUNNING . '\' AND `date_start` < DATE_SUB(\'' . (new DateTime)->format('Y-m-d H:i:s') . '\', INTERVAL (`timeout`+`cooldown`+' . CrystalTask::STATE_CRYSTAL_TASK_RUNNING_TO_DEAD_COOLDOWN . ') SECOND))';
    }

    private function getWhereClauseStateCrystalTaskCompleted(): string
    {
        return '(`state` = \'' . CrystalTask::STATE_CRYSTAL_TASK_COMPLETED . '\')';
    }

    private function getWhereClauseStateCrystalTaskNotCompleted(): string
    {
        return '(`state` = \'' . CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED . '\')';
    }

    /**
     * All tasks that are "to be scheduled" again
     */
    private function getWhereClauseCrystalTaskUnfinished(): string
    {
        return '('
            . $this->getWhereClauseStateCrystalTaskNotCompleted()
            . ' OR ' . $this->getWhereClauseStateCrystalTaskRunning()
            . ' OR ' . $this->getWhereClauseCrystalTaskDead()
            . ' OR ' . $this->getWhereClauseStateCrystalTaskNew()
            . ')';
    }

    /**
     * Task is completed but had an overlap with the input date start
     */
    private function getWhereClauseCrystalTaskCompletedBeforeDateStart(string $dateStart): string
    {
        return '(' . $this->getWhereClauseStateCrystalTaskCompleted() . ' AND date_end >= ' . $dateStart . ')';
    }

    public function countRunningCrystalTasks(): int
    {
        $query = 'SELECT COUNT(*) as c'
            . ' FROM <' . self::TABLE_NAME . '>'
            . ' WHERE ' . $this->getWhereClauseStateCrystalTaskRunning();

        $result = $this->_database->query($query)->fetch();
        return (int)$result['c'];
    }

    public function getNextToBeExecutedCrystalTasksWithForUpdate(int $limit): array
    {
        $query = 'SELECT ' . $this->escapeTableColumns(self::TABLE_COLUMNS)
            . ' FROM <' . self::TABLE_NAME . '>'
            . ' WHERE ' . $this->getWhereClauseStateCrystalTaskNew()
            . ' ORDER BY <date_created> ASC'
            . ' LIMIT ' . $limit
            . ' FOR UPDATE';

        return $this->_database->query($query)->fetchAll(PDO::FETCH_CLASS, CrystalTask::class);
    }

    public function getNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdate(array $taskClassesAndGrantedExecutionSlots): array
    {
        $query = $this->buildSqlQueryGetNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdate($taskClassesAndGrantedExecutionSlots);
        $crystalTasks = $this->_database->query($query)->fetchAll(PDO::FETCH_CLASS, CrystalTask::class);

        $ids = array_column($crystalTasks, 'id');

        $where1 = $this->getWhereClauseStateCrystalTaskNew();
        $where2 = ' (<id> IN (' . implode(',', $ids) . ')) ';
        $where = '(' . $where1 . ') AND (' . $where2 . ')';

        $query = 'SELECT ' . $this->escapeTableColumns(self::TABLE_COLUMNS)
            . ' FROM <' . self::TABLE_NAME . '>'
            . ' WHERE ' . $where
            . ' FOR UPDATE';

        return $this->_database->query($query)->fetchAll(PDO::FETCH_CLASS, CrystalTask::class);
        
    }

    public function countNextToBeExecutedCrystalTasks(array $taskClasses): array
    {
        $taskClassesEscaped = [];
        foreach ($taskClasses as $taskClass) {
            $taskClassesEscaped[] = $this->_database->quote($taskClass);
        }

        if (empty($taskClassesEscaped)) {
            return [];
        }

        $where1 = $this->getWhereClauseStateCrystalTaskNew();
        $where2 = ' (<class> IN (' . implode(',', $taskClassesEscaped) . ')) ';

        $where = '(' . $where1 . ') AND (' . $where2 . ')';

        $query = 'SELECT <class>, COUNT(*) as dbCount'
            . ' FROM <' . self::TABLE_NAME . '>'
            . ' WHERE ' . $where
            . ' GROUP BY (<class>)';

        return $this->_database->query($query)->fetchAll(PDO::FETCH_ASSOC);
    }

    // TODO: set a limit on all function calls
    /**
     * Get the dead or not completed crystal tasks
     */
    public function getDeadOrNotCompletedCrystalTasks(int $limit = null): array
    {
        $where = '(' . $this->getWhereClauseCrystalTaskDead() . ' OR ' . $this->getWhereClauseStateCrystalTaskNotCompleted() . ') ';
        $query = 'SELECT ' . $this->escapeTableColumns(self::TABLE_COLUMNS)
            . ' FROM <' . self::TABLE_NAME . '>'
            . ' WHERE ' . $where
            . ' ORDER BY <date_created> ASC';

        if (is_int($limit)) {
            $query .= ' LIMIT ' . $limit;
        }

        return $this->_database->query($query)->fetchAll(PDO::FETCH_CLASS, CrystalTask::class);
    }

    /**
     * Note: no transaction in here, can be done in a parent
     */
    public function getCrystalTaskByIdWithForUpdate(CrystalTask $crystalTask): ?CrystalTask
    {
        $query = 'SELECT ' . $this->escapeTableColumns(self::TABLE_COLUMNS)
            . ' FROM <' . self::TABLE_NAME . '>'
            . ' WHERE <id>=' . $this->_database->quote($crystalTask->id)
            . ' LIMIT 1'
            . ' FOR UPDATE';

        $result = $this->_database->query($query)->fetchAll(PDO::FETCH_CLASS, CrystalTask::class);
        /** @var CrystalTask $crystalTask */
        $crystalTask = $this->returnOneOrNull($result);
        return $crystalTask;
    }

    /**
     * Note: no transaction in here, can be done in a parent
     */
    public function getUniqueCrystalTaskWithForUpdate(CrystalTask $crystalTask): ?CrystalTask
    {
        $whereString = $this->buildWhereClauseUniqueCrystalTask($crystalTask);

        $query = 'SELECT ' . $this->escapeTableColumns(self::TABLE_COLUMNS)
            . ' FROM <' . self::TABLE_NAME . '>'
            . ' WHERE ' . $whereString
            . ' LIMIT 1'
            . ' FOR UPDATE';

        $result = $this->_database->query($query)->fetchAll(PDO::FETCH_CLASS, CrystalTask::class);
        /** @var CrystalTask $crystalTask */
        $crystalTask = $this->returnOneOrNull($result);
        return $crystalTask;
    }

    public function isDependeeUnfinishedOrOverlapping(CrystalTask $crystalTask): bool
    {
        $whereString = $this->buildWhereClauseIsDependeeUnfinishedOrOverlapping($crystalTask);
        $query = 'SELECT COUNT(*) as c'
            . ' FROM <' . self::TABLE_NAME . '>'
            . ' WHERE ' . $whereString;

        $result = $this->_database->query($query)->fetch();
        return !!$result['c'];
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
        list($table, $column) = $this->splitEntityUidInTableAndColumn($entityUid);

        $hashStartColumnName = '___hash_start_column';
        $columnSubstring = 'SUBSTRING(SHA1(<' . $column . '>),1,1)';

        $rangeArray = str_split($range);
        foreach ($rangeArray as $key => $rangeCharacter) {
            $rangeArray[$key] = $this->_database->quote($rangeCharacter);
        }

        $query = 'SELECT *,' . $columnSubstring . ' as ' .  $hashStartColumnName
            . ' FROM `' . $table . '`'
            . ' HAVING ' . $hashStartColumnName . ' IN (' . implode(',', $rangeArray) . ')';

        return $this->_database->query($query)->fetchAll(PDO::FETCH_CLASS, CrystalTask::class);
    }

    /**
     * @throws Exception
     */
    private function splitEntityUidInTableAndColumn(string $entityUid): array
    {
        $parts = explode('.', $entityUid);
        if (count($parts) !== 2) {
            throw new Exception('Could not split entityUid to table and column: ' . $entityUid);
        }

        return [$parts[0], $parts[1]];
    }

    public function getUnfinishedCrystalTasksByRangeAndTaskClasses(string $range, array $taskClasses): array
    {
        $whereString = $this->buildWhereClauseUnfinishedCrystalTasksByRangeAndTaskClasses($range, $taskClasses);
        $query = 'SELECT ' . $this->escapeTableColumns(self::TABLE_COLUMNS)
            . ' FROM <' . self::TABLE_NAME . '>'
            . ' WHERE ' . $whereString;

        return $this->_database->query($query)->fetchAll(PDO::FETCH_CLASS, CrystalTask::class);
    }


    /**
     * Build the SQL WHERE clause to find a unique crystal task
     */
    private function buildWhereClauseUniqueCrystalTask(CrystalTask $crystalTask): string
    {
        $where = [];
        $duplicateColumnsAndValues = array_intersect_key(
            (array)$crystalTask,
            array_flip(CrystalTask::UNIQUE_INDEX_CRYSTAL_TASK)
        );

        foreach ($duplicateColumnsAndValues as $duplicateColumn => $value) {
            // HOTFIX
            if (is_null($value)) {
                $where[] = '`' . $duplicateColumn . '`'
                    . ' IS NULL';

                continue;
            }

            $where[] = '`' . $duplicateColumn . '`'
                . '='
                . $this->_database->quote($value);
        }

        return ' (' . implode(' AND ', $where) . ') ';
    }

    /**
     * NotYetCompleted: is any state not being COMPLETED or ERROR
     * OverlappingDependees: is any task we depend on and is NotYetCompleted
     *
     * Note: when state of dependee is ERROR we also want to complete
     * Note: when task is COMPLETED, also check if it ended BEFORE our start date
     */
    private function buildWhereClauseIsDependeeUnfinishedOrOverlapping(CrystalTask $crystalTask): string
    {
        $class = $this->_database->quote($crystalTask->class);
        $dateStart = $this->_database->quote($crystalTask->date_start);

        $where1 = $this->getWhereClauseCrystalTaskUnfinished();
        $where2 = $this->getWhereClauseCrystalTaskCompletedBeforeDateStart($dateStart);

        $whereGetDependees = '(SELECT `depend_on` FROM `crystal_tasks_dependencies` WHERE class = ' . $class . ')';

        return ' (`class` IN ' . $whereGetDependees . ' AND (('  . $where1 . ') OR (' . $where2 . ')) )';
    }

    /**
     * Build the SQL WHERE clause to find all tasks that have a not finished state
     */
    private function buildWhereClauseUnfinishedCrystalTasksByRangeAndTaskClasses(
        string $range,
        array $taskClasses
    ): string
    {
        $where = [];
        foreach ($taskClasses as $taskClass) {
            $where[] = $this->_database->quote($taskClass);
        }

        $where1 = $this->getWhereClauseCrystalTaskUnfinished();
        $where2 = '`class` IN (' . implode(',', $where) . ') ';
        $where3 = '`range` = ' . $this->_database->quote($range);
        return '(' . $where1 . ') AND (' . $where2 . ') AND (' . $where3 . ')';
    }

    /**
     * Build the SQL query to find the next tasks for this strategy
     */
    private function buildSqlQueryGetNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdate($taskClassesAndGrantedExecutionSlots): string
    {
        $wheres = [];
        foreach ($taskClassesAndGrantedExecutionSlots as $taskClassAndPriority) {
            $classEscaped = $this->_database->quote($taskClassAndPriority['class']);
            $normalizedCountEscaped = (int)$taskClassAndPriority['grantedExecutionSlots'];
            $uniqid = uniqid('table_');

            $wheres[] = 'SELECT ' . $uniqid . '.* FROM (
                    SELECT * FROM `crystal_tasks` WHERE ' . $this->getWhereClauseStateCrystalTaskNew()
                . ' AND `class` = ' . $classEscaped . ' ORDER BY `date_created` ASC LIMIT ' . $normalizedCountEscaped
                . ') as ' . $uniqid;
        }

        return implode(' UNION ALL ', $wheres);
    }
}