<?php


namespace Crystal\Test\Executor;


use Crystal\Crystal;
use Crystal\Entity\CrystalTask;
use Crystal\Test\Core\BaseTestApp;
use Crystal\Test\Core\FixtureHelper;
use Crystal\Test\Mock\Task\ErrorTask;
use Crystal\Test\Mock\Task\NotCompletedTask;
use Crystal\Test\Mock\Task\SuccessTask;
use DateTime;
use Exception;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

class ExecutorTest extends BaseTestApp
{
    private $_testHandler;
    private $_crystalTasksTable;
    private $_fixtureHelper;
    private $_executorFactory;

    /**
     * @throws Exception
     */
    public function setUp()
    {
        parent::setUp();

        $config = array_merge($this->getDatabaseConfig(), $this->getGlobalConfig());
        $this->_testHandler = new TestHandler();
        $logger = new Logger('', [$this->_testHandler]);
        $crystal = new Crystal($config, $logger);
        $crystal->start();

        $this->_executorFactory = $crystal->getExecutorFactory();

        $this->_crystalTasksTable = $crystal->getCrystalTasksTable();
        $this->_fixtureHelper = new FixtureHelper();
    }

    /**
     * Should NOT set ERROR state when RUNNING state is executed again
     *
     * @throws Exception
     */
    public function testValidatePrepareAndExecuteCrystalTaskShouldSetErrorStateWhenExecutedAgain()
    {
        $this->truncate(['crystal_tasks']);

        $this->_fixtureHelper->setupCrystalTask([
            'class' => SuccessTask::class,
            'entity_uid' => 'some.id',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'range' => 1,
        ]);


        $class = SuccessTask::class;
        $crystalTaskId = 1;
        $timeout = 10;
        $cooldown = 10;

        $successTask = new SuccessTask();
        $executor = $this->_executorFactory->create();
        $this->assertTrue($executor->validatePrepareAndExecuteCrystalTask($successTask, $class, $crystalTaskId, $timeout, $cooldown));
        $this->assertFalse($this->_testHandler->hasRecords(Logger::ERROR));

        $this->database->update('crystal_tasks', [
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
        ], ['id' => 1]);

        $this->assertFalse($executor->validatePrepareAndExecuteCrystalTask($successTask, $class, $crystalTaskId, $timeout, $cooldown));
        $this->assertTrue($this->_testHandler->hasRecordThatContains(
            'Trying to executeCrystalTask for the second time, weird',
            Logger::ERROR
        ));
    }

    /**
     * Should return false, leave the task state be, when task class does not match with the CrystalTask in the db
     *
     * @throws Exception
     */
    public function testValidatePrepareAndExecuteCrystalTaskShouldSetErrorStateWhenTaskClassDoesNotMatchInputParameter()
    {
        $this->truncate(['crystal_tasks']);

        $this->_fixtureHelper->setupCrystalTask([
            'class' => SuccessTask::class,
            'entity_uid' => 'some.id',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'range' => 1,
            'error_tries' => 0
        ]);

        $class = NotCompletedTask::class;
        $crystalTaskId = 1;
        $timeout = 10;
        $cooldown = 10;

        $successTask = new SuccessTask();
        $executor = $this->_executorFactory->create();
        $this->assertFalse($executor->validatePrepareAndExecuteCrystalTask($successTask, $class, $crystalTaskId, $timeout, $cooldown));
        $this->assertTrue($this->_testHandler->hasRecordThatContains(
            'Request input class and task class do not match, weird',
            Logger::ERROR
        ));

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_RUNNING, $crystalTaskDb->state);
        $this->assertEquals(0, $crystalTaskDb->error_tries);
        $this->assertNotNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);
    }


    /**
     * Should set state to error when crystal task does not exist
     *
     * @throws Exception
     */
    public function testExecuteCrystalTaskShouldSetErrorStateWhenCrystalTaskIdDoesNotExist()
    {
        $this->truncate(['crystal_tasks']);

        $this->_fixtureHelper->setupCrystalTask([
            'class' => SuccessTask::class,
            'entity_uid' => 'some.id',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
            'range' => 1,
        ]);

        $successTask = new SuccessTask();
        $executor = $this->_executorFactory->create();
        $this->assertFalse($executor->executeCrystalTask(2, $successTask));
        $this->assertEquals(
            'Could not get CrystalTask with id: "2"',
            $this->_testHandler->getRecords()[0]['context']['errorMessage']
        );
    }

    /**
     * Should simply skip, it would be very weird if this happens
     *
     * @throws Exception
     */
    public function testExecuteCrystalTaskShouldSetErrorStateWhenStateIsInconsistent()
    {
        $this->truncate(['crystal_tasks']);

        $crystalTask = $this->_fixtureHelper->setupCrystalTask([
            'class' => SuccessTask::class,
            'entity_uid' => 'some.id',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
            'range' => 1,
            'error_tries' => 0,
        ]);

        $successTask = new SuccessTask();
        $executor = $this->_executorFactory->create();
        $this->assertFalse($executor->executeCrystalTask($crystalTask->id, $successTask));
        $this->assertEquals(
            'Trying to executeCrystalTask with no RUNNING state, weird',
            $this->_testHandler->getRecords()[0]['context']['errorMessage']
        );

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_COMPLETED, $crystalTaskDb->state);
        $this->assertEquals(1, $crystalTaskDb->error_tries);
        $this->assertNotNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);
    }

    /**
     * Should set ERROR state when task class does not match with the CrystalTask in the db
     *
     * @throws Exception
     */
    public function testExecuteCrystalTaskShouldSetErrorStateWhenTaskClassDoesNotMatchCrystalTaskInDb()
    {
        $this->truncate(['crystal_tasks']);

        $crystalTask = $this->_fixtureHelper->setupCrystalTask([
            'class' => SuccessTask::class,
            'entity_uid' => 'some.id',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'range' => 1,
            'error_tries' => 0
        ]);

        $notCompletedTask = new NotCompletedTask();
        $executor = $this->_executorFactory->create();
        $this->assertFalse($executor->executeCrystalTask($crystalTask->id, $notCompletedTask));
        $this->assertEquals(
            'Request input class and task class do not match, weird',
            $this->_testHandler->getRecords()[0]['context']['errorMessage']
        );

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_ERROR, $crystalTaskDb->state);
        $this->assertEquals(1, $crystalTaskDb->error_tries);
        $this->assertNotNull($crystalTaskDb->date_start);
        $this->assertNotNull($crystalTaskDb->date_end);
    }


    /**
     * Should set ERROR state when task class does not exist
     *
     * @throws Exception
     */
    public function testExecuteCrystalTaskShouldSetErrorStateWhenTaskClassErroneous()
    {
        $this->truncate(['crystal_tasks']);

        $crystalTask = $this->_fixtureHelper->setupCrystalTask([
            'class' => 'Some\NonExistingTask',
            'entity_uid' => 'some.id',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'range' => 1,
            'error_tries' => 0
        ]);

        $successTask = new SuccessTask();
        $executor = $this->_executorFactory->create();
        $this->assertFalse($executor->executeCrystalTask($crystalTask->id, $successTask));
        $this->assertEquals(
            'Request input class and task class do not match, weird',
            $this->_testHandler->getRecords()[0]['context']['errorMessage']
        );

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_ERROR, $crystalTaskDb->state);
        $this->assertEquals(1, $crystalTaskDb->error_tries);
        $this->assertNotNull($crystalTaskDb->date_start);
        $this->assertNotNull($crystalTaskDb->date_end);
    }

    /**
     * Should set ERROR state when task throws an error
     *
     * @throws Exception
     */
    public function testExecuteCrystalTaskShouldSetErrorStateWhenTaskThrowsError()
    {
        $this->truncate(['crystal_tasks']);

        $crystalTask = $this->_fixtureHelper->setupCrystalTask([
            'class' => ErrorTask::class,
            'entity_uid' => 'some.id',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'range' => 1,
            'error_tries' => 0
        ]);

        $errorTask = new ErrorTask();
        $executor = $this->_executorFactory->create();
        $this->assertFalse($executor->executeCrystalTask($crystalTask->id, $errorTask));
        $this->assertEquals(
            'Random error',
            $this->_testHandler->getRecords()[0]['context']['errorMessage']
        );

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_ERROR, $crystalTaskDb->state);
        $this->assertEquals(1, $crystalTaskDb->error_tries);
        $this->assertNotNull($crystalTaskDb->date_start);
        $this->assertNotNull($crystalTaskDb->date_end);
    }

    /**
     * Should set COMPLETED state when task execute functions returns true
     *
     * @throws Exception
     */
    public function testExecuteCrystalTaskShouldSetCompletedStateWhenTaskReturnsTrue()
    {
        $this->truncate(['crystal_tasks']);

        $crystalTask = $this->_fixtureHelper->setupCrystalTask([
            'class' => SuccessTask::class,
            'entity_uid' => 'some.id',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'range' => 1,
            'error_tries' => 0
        ]);

        $successTask = new SuccessTask();
        $executor = $this->_executorFactory->create();
        $this->assertTrue($executor->executeCrystalTask($crystalTask->id, $successTask));

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_COMPLETED, $crystalTaskDb->state);
    }

    /**
     * Should set NOT_COMPLETED state when task execute functions returns false
     *
     * @throws Exception
     */
    public function testExecuteCrystalTaskShouldSetNotCompletedStateWhenTaskReturnsFalse()
    {
        $this->truncate(['crystal_tasks']);

        $crystalTask = $this->_fixtureHelper->setupCrystalTask([
            'class' => NotCompletedTask::class,
            'entity_uid' => 'some.id',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'range' => 1,
            'error_tries' => 0
        ]);

        $notCompletedTask = new NotCompletedTask();
        $executor = $this->_executorFactory->create();
        $this->assertFalse($executor->executeCrystalTask($crystalTask->id, $notCompletedTask));

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED, $crystalTaskDb->state);
    }

    /**
     * Should set task to COMPLETE + all other values should be correct
     *
     * @throws Exception
     */
    public function testExecuteCrystalTaskShouldCompleteCrystalTaskAndAllValuesShouldBeCorrect()
    {
        $this->truncate(['crystal_tasks']);

        $crystalTask = $this->_fixtureHelper->setupCrystalTask([
            'class' => SuccessTask::class,
            'entity_uid' => 'some.id',
            'timeout' => 60,
            'cooldown' => 10,
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'error_tries' => 0,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'range' => 1,
        ]);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(SuccessTask::class, $crystalTaskDb->class);
        $this->assertEquals(60, $crystalTaskDb->timeout);
        $this->assertEquals(10, $crystalTaskDb->cooldown);
        $this->assertEquals('some.id', $crystalTaskDb->entity_uid);
        $this->assertEquals(0, $crystalTaskDb->error_tries);
        $this->assertEquals(1, $crystalTaskDb->range);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_RUNNING, $crystalTaskDb->state);
        $this->assertNotNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);

        $successTask = new SuccessTask();
        $executor = $this->_executorFactory->create();
        $executor->executeCrystalTask($crystalTask->id, $successTask);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(SuccessTask::class, $crystalTaskDb->class);
        $this->assertEquals(60, $crystalTaskDb->timeout);
        $this->assertEquals(10, $crystalTaskDb->cooldown);
        $this->assertEquals('some.id', $crystalTaskDb->entity_uid);
        $this->assertEquals(0, $crystalTaskDb->error_tries);
        $this->assertEquals(1, $crystalTaskDb->range);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_COMPLETED, $crystalTaskDb->state);
        $this->assertNotNull($crystalTaskDb->date_start);
        $this->assertNotNull($crystalTaskDb->date_end);
    }
}