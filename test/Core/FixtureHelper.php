<?php

namespace Crystal\Test\Core;

use Crystal\Entity\CrystalTask;
use Crystal\Entity\CrystalTaskDependency;
use Crystal\Test\Mock\Task\DependeeTask;
use Crystal\Test\Mock\Task\DependentTask;
use Exception;

/**
 * FixtureHelper
 *
 * Used directly in the test to work more efficient and cleaner with fixtures
 *
 * Extend BaseTestApp so we can use its functions
 */
class FixtureHelper extends BaseTestApp
{
    /**
     * @throws Exception
     */
    public function __construct()
    {
        parent::__construct();
        parent::setUp();
    }

    public function setupCrystalTask(array $data): CrystalTask
    {
        $crystalTask = [];
        $crystalTask['class'] = DependeeTask::class;
        $crystalTask['timeout'] = 60;
        $crystalTask['cooldown'] = 10;
        $crystalTask['entity_uid'] = 'some.id';
        $crystalTask['range'] = 1;
        $crystalTask['date_start'] = null;
        $crystalTask['date_end'] = null;
        $crystalTask['state'] = CrystalTask::STATE_CRYSTAL_TASK_NEW;
        $crystalTask['error_tries'] = 0;
        $crystalTask['date_created'] = '2021-01-01 01:01:01';

        $crystalTask = array_merge($crystalTask, $data);

        $this->database->insert('crystal_tasks', $crystalTask);
        $crystalTask['id'] = $this->database->id();

        return new CrystalTask($crystalTask);
    }

    public function setupCrystalTaskDependency(array $data): CrystalTaskDependency
    {
        $crystalTaskDependency = [];
        $crystalTaskDependency['class'] = DependentTask::class;
        $crystalTaskDependency['depend_on'] = DependeeTask::class;

        $crystalTaskDependency = array_merge($crystalTaskDependency, $data);

        $this->database->insert('crystal_tasks_dependencies', $crystalTaskDependency);
        $crystalTaskDependency['id'] = $this->database->id();

        return new CrystalTaskDependency($crystalTaskDependency);
    }
}
