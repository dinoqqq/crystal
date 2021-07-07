<?php

namespace Crystal\Test\Service;

use Crystal\Crystal;

use Crystal\Entity\CrystalTask;
use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Test\Core\BaseTestApp;
use Crystal\Test\Core\FixtureHelper;
use Crystal\Test\Mock\Task\SuccessTask;
use Crystal\Test\Traits\ArrayTestCaseTrait;
use DateInterval;
use DateTime;
use Exception;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

class CrystalTasksQueueServiceTest extends BaseTestApp
{
    use ArrayTestCaseTrait;

    private $_crystalTasksTable;
    private $_crystalTasksQueueService;
    private $_fixtureHelper;

    /**
     * @throws Exception
     */
    public function setUp()
    {
        parent::setUp();

        $config = array_merge($this->getDatabaseConfig(), $this->getGlobalConfig());
        $testHandler = new TestHandler();
        $logger = new Logger('', [$testHandler]);
        $crystal = new Crystal($config, $logger);

        $this->_crystalTasksQueueService = $crystal->getCrystalTasksQueueService();
        $this->_crystalTasksTable = $crystal->getCrystalTasksTable();
        $this->_fixtureHelper = new FixtureHelper();
    }

    /**
     * Should reschedule same unique task and change all the free fields
     *
     * @throws Exception
     */
    public function testSaveForQueueHeartbeatShouldQueueNewTaskWithFreeFieldsChanged()
    {
        $this->truncate(['crystal_tasks']);
        $data = [
            'class' => SuccessTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
            'error_tries' => 4,
            'date_created' => (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s')
        ];

        $this->_fixtureHelper->setupCrystalTask($data);

        $dateNew = (new DateTime)->sub(new DateInterval('PT99S'))->format('Y-m-d H:i:s');
        $data['timeout'] = 99999;
        $data['cooldown'] = 99999;
        $data['date_start'] = $dateNew;
        $data['date_end'] = $dateNew;
        $data['error_tries'] = 0;
        $data['date_created'] = $dateNew;

        $crystalTask = new CrystalTasK($data);

        // Should return true
        $this->assertTrue($this->_crystalTasksQueueService->saveQueue([$crystalTask]));

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);

        $this->assertEquals($crystalTaskDb->timeout, $data['timeout']);
        $this->assertEquals($crystalTaskDb->cooldown, $data['cooldown']);
        $this->assertEquals($crystalTaskDb->date_start, $data['date_start']);
        $this->assertEquals($crystalTaskDb->date_end, $data['date_end']);
        $this->assertEquals($crystalTaskDb->error_tries, $data['error_tries']);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb->state);
        // Should have a new date created
        $this->assertEquals($crystalTaskDb->date_created, $data['date_created']);
    }

    /**
     * Should reschedule same unique task with state NEW in db
     *
     * @throws Exception
     */
    public function testSaveForQueueHeartbeatShouldRescheduleNewTask()
    {
        $this->truncate(['crystal_tasks']);
        $dateCreatedOld = (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s');

        $data = [
            'class' => SuccessTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
            'date_created' => $dateCreatedOld,
        ];

        $this->_fixtureHelper->setupCrystalTask($data);

        $data['date_created'] = null;
        $crystalTask = new CrystalTask($data);

        // Should return true
        $this->assertTrue($this->_crystalTasksQueueService->saveQueue([$crystalTask]));

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);

        // Should have a new date created
        $this->assertNotEquals($crystalTaskDb->date_created, $dateCreatedOld);
        $this->assertNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb->state);
    }

    /**
     * Should reschedule same unique task with state COMPLETED in db
     *
     * @throws Exception
     */
    public function testSaveForQueueHeartbeatShouldRescheduleCompletedTask()
    {
        $this->truncate(['crystal_tasks']);
        $dateCreatedOld = (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s');
        $dateCreatedNew = (new DateTime)->sub(new DateInterval('PT99S'))->format('Y-m-d H:i:s');

        $dataCompleted = [
            'class' => SuccessTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_end' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
            'date_created' => $dateCreatedOld,
        ];

        $dataNew = [
            'class' => SuccessTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
            'date_created' => $dateCreatedNew,
        ];

        $this->_fixtureHelper->setupCrystalTask($dataCompleted);

        $crystalTask = new CrystalTask($dataNew);

        $result = $this->_crystalTasksQueueService->saveQueue([$crystalTask]);
        $this->assertTrue($result);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertNotEquals($crystalTaskDb->date_created, $dateCreatedOld);
        $this->assertEquals($crystalTaskDb->date_created, $dateCreatedNew);
        $this->assertNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb->state);
    }

    /**
     * Should NOT reschedule same unique task with state RUNNING (but DEAD) in db
     *
     * @throws Exception
     */
    public function testSaveForQueueHeartbeatShouldNotRescheduleDeadTask()
    {
        $this->truncate(['crystal_tasks']);
        $data = [
            'class' => SuccessTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('P10Y'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'date_created' => (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s')
        ];

        $this->_fixtureHelper->setupCrystalTask($data);

        $crystalTask = new CrystalTask($data);

        // Should return true
        $this->assertTrue($this->_crystalTasksQueueService->saveQueue([$crystalTask]));

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertArraySubset($data, (array)$crystalTaskDb);
    }

    /**
     * Should NOT reschedule same unique task with state RUNNING in db
     *
     * @throws Exception
     */
    public function testSaveForQueueHeartbeatShouldNotRescheduleRunningTask()
    {
        $this->truncate(['crystal_tasks']);
        $data = [
            'class' => SuccessTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'date_created' => (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s')
        ];

        $this->_fixtureHelper->setupCrystalTask($data);

        $crystalTask = new CrystalTask($data);

        // Should throw a big exception
        $this->expectException(CrystalTaskStateErrorException::class);
        $this->expectExceptionMessage('RunningToNewStateChangeStrategy encountered, already picked up');

        $this->_crystalTasksQueueService->saveQueue([$crystalTask]);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertArraySubset($data, (array)$crystalTaskDb);
    }

    /**
     * Should reschedule same unique task with state ERROR in db
     *
     * @throws Exception
     */
    public function testSaveForQueueHeartbeatShouldRescheduleErrorTask()
    {
        $this->truncate(['crystal_tasks']);
        $dateCreatedOld = (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s');
        $dateCreatedNew = (new DateTime)->sub(new DateInterval('PT99S'))->format('Y-m-d H:i:s');

        $dataError = [
            'class' => SuccessTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_end' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_ERROR,
            'date_created' => $dateCreatedOld,
        ];

        $dataNew = [
            'class' => SuccessTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
            'date_created' => $dateCreatedNew,
        ];

        $this->_fixtureHelper->setupCrystalTask($dataError);

        $crystalTask = new CrystalTask($dataNew);

        $result = $this->_crystalTasksQueueService->saveQueue([$crystalTask]);
        $this->assertTrue($result);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertNotEquals($crystalTaskDb->date_created, $dateCreatedOld);
        $this->assertEquals($crystalTaskDb->date_created, $dateCreatedNew);
        $this->assertNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb->state);
    }


    /**
     * Should create a new task when nothing is in the db yet
     *
     * @throws Exception
     */
    public function testSaveForQueueHeartbeatShouldCreateNewTask()
    {
        $this->truncate(['crystal_tasks']);
        // New
        $data = [
            'class' => SuccessTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $crystalTask = new CrystalTask($data);
        $result = $this->_crystalTasksQueueService->saveQueue([$crystalTask]);
        $this->assertTrue($result);

        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertArraySubset($data, (array)$crystalTaskDb);
    }
}
