<?php
namespace Crystal\Test\Service;

use Crystal\Crystal;
use Crystal\Entity\CrystalTask;
use Crystal\Entity\CrystalTaskDependency;
use Crystal\Test\Core\BaseTestApp;
use Crystal\Test\Core\FixtureHelper;
use Crystal\Test\Traits\ArrayTestCaseTrait;
use DateTime;
use DateInterval;
use Exception;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

class CrystalTasksBaseServiceTest extends BaseTestApp
{
    use ArrayTestCaseTrait;

    private $_crystalTasksBaseService;
    private $_fixtureHelper;
    private $_crystalTasksDependenciesTable;
    private $_crystalTasksTable;

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

        $this->_crystalTasksBaseService = $crystal->getCrystalTasksBaseService();
        $this->_crystalTasksTable = $crystal->getCrystalTasksTable();
        $this->_crystalTasksDependenciesTable = $crystal->getCrystalTasksDependenciesTable();
        $this->_fixtureHelper = new FixtureHelper();
    }

    private function createReferenceTable()
    {
        $this->database->create("account", [
            "id" => [
                "INT",
                "NOT NULL",
                "AUTO_INCREMENT"
            ],
            "email" => [
                "VARCHAR(70)",
                "NOT NULL",
                "UNIQUE"
            ],
            "PRIMARY KEY (<id>)"
        ], [
            "ENGINE" => "InnoDB",
            "AUTO_INCREMENT" => 1
        ]);
    }

    private function insertEntriesInReferenceTable()
    {
        $this->database->insert("account", [
            [
                "email" => 'foo@foo.foo',
            ],[
                "email" => 'bar@bar.bar',
            ],[
                "email" => 'baz@baz.baz',
            ]
        ]);
    }

    /**
     * Should work with empty results
     *
     * @throws Exception
     */
    public function testGetByEntityUidAndRangeShouldReturnEmptyObject()
    {
        $this->truncate(['account']);
        $this->createReferenceTable();

        $entityUid = 'account.email';
        $range = '0123456789abcdef';

        $result = $this->_crystalTasksBaseService->getByEntityUidAndRange($entityUid, $range);
        $this->assertEmpty($result);
    }

    /**
     * Should get the matching entry
     *
     * @throws Exception
     */
    public function testGetByEntityUidAndRangeShouldReturnOnlyMatchingWithHash()
    {
        $this->truncate(['account']);
        $this->createReferenceTable();
        $this->insertEntriesInReferenceTable();

        // sha1('foo@foo.foo') === 68b3b0bd2449b8556a157d75d719afe6f606b979
        // sha1('bar@bar.bar') === 7301455d08f0c92c5de916902f466000c367ae4d
        // sha1('baz@baz.baz') === 22e1a7292224ef3205af40b587ec41172a4972b1
        $entityUid = 'account.email';
        $range = '0123456789abcdef';

        $crystalTasks = $this->_crystalTasksBaseService->getByEntityUidAndRange($entityUid, $range);
        $this->assertEquals('bar@bar.bar', $crystalTasks[0]->email);
        $this->assertEquals('baz@baz.baz', $crystalTasks[1]->email);
        $this->assertEquals('foo@foo.foo', $crystalTasks[2]->email);
        $this->assertCount(3, $crystalTasks);
    }

    /**
     * Should only get the one matching entry
     *
     * @throws Exception
     */
    public function testGetByEntityUidAndRangeShouldReturnOnlyTheRightEntry()
    {
        $this->truncate(['account']);
        $this->createReferenceTable();
        $this->insertEntriesInReferenceTable();

        // sha1('foo@foo.foo') === 68b3b0bd2449b8556a157d75d719afe6f606b979
        // sha1('bar@bar.bar') === 7301455d08f0c92c5de916902f466000c367ae4d
        // sha1('baz@baz.baz') === 22e1a7292224ef3205af40b587ec41172a4972b1
        $entityUid = 'account.email';
        $range = '01234';

        $crystalTasks = $this->_crystalTasksBaseService->getByEntityUidAndRange($entityUid, $range);
        $this->assertEquals('baz@baz.baz', $crystalTasks[0]->email);
        $this->assertCount(1, $crystalTasks);
    }

    /**
     * Should get both matching values
     *
     * @throws Exception
     */
    public function testGetByEntityUidAndRangeShouldReturnBothEntries()
    {
        $this->truncate(['account']);
        $this->createReferenceTable();
        $this->insertEntriesInReferenceTable();

        // sha1('foo@foo.foo') === 68b3b0bd2449b8556a157d75d719afe6f606b979
        // sha1('bar@bar.bar') === 7301455d08f0c92c5de916902f466000c367ae4d
        // sha1('baz@baz.baz') === 22e1a7292224ef3205af40b587ec41172a4972b1
        $entityUid = 'account.email';
        $range = '62';

        $crystalTasks = $this->_crystalTasksBaseService->getByEntityUidAndRange($entityUid, $range);
        $this->assertEquals('baz@baz.baz', $crystalTasks[0]->email);
        $this->assertEquals('foo@foo.foo', $crystalTasks[1]->email);
        $this->assertCount(2, $crystalTasks);
    }

    /**
     * Should only get dead and not completed crystal tasks with timeout and cooldown considered
     *
     * @throws Exception
     */
    public function testGetDeadOrNotCompletedCrystalTasksShouldWorkWithTimeoutAndCooldown()
    {
        $this->truncate(['crystal_tasks']);

        $runningToDeadCooldown = CrystalTask::STATE_CRYSTAL_TASK_RUNNING_TO_DEAD_COOLDOWN;
        $rescheduleCooldown = CrystalTask::STATE_CRYSTAL_TASK_RESCHEDULE_COOLDOWN;

        // Should NOT match
        $dataRunning = [
            'class' => 'Task1',
            'timeout' => '1',
            'cooldown' => '60',
            'entity_uid' => 'dependee1.id',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('PT60S'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
        ];

        // Should match
        $dataDead1 = [
            'class' => 'Task2',
            'timeout' => '1',
            'cooldown' => '60',
            'entity_uid' => 'dependee2.id',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('PT99S'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
        ];

        // Should match
        $dataDead2 = [
            'class' => 'Task3',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee2.id',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('PT99S'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
        ];

        // Should NOT match
        // Has the COOLDOWN taken into account, but still not dead
        $dataDead3 = [
            'class' => 'Task99',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee2.id',
            'range' => '4',
            'date_start' => (new DateTime)
                ->sub(new DateInterval('PT' . (61 + $runningToDeadCooldown + $rescheduleCooldown - 3) . 'S'))
                ->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
        ];

        // Should match + general cooldown time
        // Has the COOLDOWN taken into account
        $dataNotCompleted1 = [
            'class' => 'Task4',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee2.id',
            'range' => '4',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)
                ->sub(new DateInterval('PT' . ($rescheduleCooldown + 1) . 'S'))
                ->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED,
        ];

        // Should match
        $dataNotCompleted2 = [
            'class' => 'Task5',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee2.id',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('PT3S'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED,
        ];

        // Should NOT match
        $dataNew = [
            'class' => 'Task6',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee2.id',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('PT3S'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        // Should NOT match
        $dataError = [
            'class' => 'Task7',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee2.id',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('PT3S'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_ERROR,
        ];

        // Should NOT match
        $dataCompleted = [
            'class' => 'Task8',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee2.id',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('PT3S'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        $this->_fixtureHelper->setupCrystalTask($dataRunning);
        $this->_fixtureHelper->setupCrystalTask($dataNew);
        $this->_fixtureHelper->setupCrystalTask($dataError);
        $this->_fixtureHelper->setupCrystalTask($dataCompleted);
        $this->_fixtureHelper->setupCrystalTask($dataDead3);

        $this->_fixtureHelper->setupCrystalTask($dataDead1);
        $this->_fixtureHelper->setupCrystalTask($dataDead2);
        $this->_fixtureHelper->setupCrystalTask($dataNotCompleted1);
        $this->_fixtureHelper->setupCrystalTask($dataNotCompleted2);

        $crystalTasks = $this->_crystalTasksBaseService->getDeadOrNotCompletedCrystalTasks();

        $this->assertCount(4, $crystalTasks);

        $this->assertEquals($dataDead1['state'], $crystalTasks[0]->state);
        $this->assertEquals($dataDead2['state'], $crystalTasks[1]->state);
        $this->assertEquals($dataNotCompleted1['state'], $crystalTasks[2]->state);
        $this->assertEquals($dataNotCompleted2['state'], $crystalTasks[3]->state);
    }

    /**
     * Should return true with not completed dependee
     *
     * @throws Exception
     */
    public function testIsDependeeUnfinishedOrOverlappingNotCompleted()
    {
        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);

        // Dependee NOT COMPLETED
        // Should match
        $dataDependee = [
            'class' => 'DEPENDEE',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee1.id',
            'range' => '4',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED,
        ];

        // Depender
        $dataDepender = [
            'class' => 'DEPENDER',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => '2019-01-01 00:00:00',
            'date_end' => '2020-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        $dataDependency = [
            'class' => 'DEPENDER',
            'depend_on' => 'DEPENDEE',
        ];

        $this->_fixtureHelper->setupCrystalTask($dataDependee);
        $crystalTask = $this->_fixtureHelper->setupCrystalTask($dataDepender);
        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency);
        $this->assertTrue($this->_crystalTasksBaseService->isDependeeUnfinishedOrOverlapping($crystalTask));
    }

    /**
     * Should return false with not completed but non matching class
     *
     * @throws Exception
     */
    public function testIsDependeeUnfinishedOrOverlappingNonMatchingClass()
    {
        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);

        // Dependee NOT COMPLETED, but non-matching class
        // Should not match
        $dataDependee = [
            'class' => 'DEPENDEEXXXX',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee2.id',
            'range' => '4',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED,
        ];

        // Depender
        $dataDepender = [
            'class' => 'DEPENDER',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => '2019-01-01 00:00:00',
            'date_end' => '2020-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        $dataDependency = [
            'class' => 'DEPENDER',
            'depend_on' => 'DEPENDEE',
        ];

        $this->_fixtureHelper->setupCrystalTask($dataDependee);
        $crystalTask = $this->_fixtureHelper->setupCrystalTask($dataDepender);
        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency);
        $this->assertFalse($this->_crystalTasksBaseService->isDependeeUnfinishedOrOverlapping($crystalTask));
    }

    /**
     * Should return false with completed but before date_start depender
     *
     * @throws Exception
     */
    public function testIsDependeeUnfinishedOrOverlappingNonOverlap()
    {
        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);

        // Dependee COMPLETED, and ended before depender date_start
        // Should not match
        $dataDependee = [
            'class' => 'DEPENDEE',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee3.id',
            'range' => '4',
            'date_start' => '2018-01-01 00:00:00',
            'date_end' => '2018-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        // Depender
        $dataDepender = [
            'class' => 'DEPENDER',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => '2019-01-01 00:00:00',
            'date_end' => '2020-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        $dataDependency = [
            'class' => 'DEPENDER',
            'depend_on' => 'DEPENDEE',
        ];

        $this->_fixtureHelper->setupCrystalTask($dataDependee);
        $crystalTask = $this->_fixtureHelper->setupCrystalTask($dataDepender);
        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency);
        $this->assertFalse($this->_crystalTasksBaseService->isDependeeUnfinishedOrOverlapping($crystalTask));
    }

    /**
     * Should return true with completed and after date_start depender
     *
     * @throws Exception
     */
    public function testIsDependeeUnfinishedOrOverlappingOverlap()
    {
        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);

        // Dependee COMPLETED, but ended after depender date_start
        // Should match
        $dataDependee = [
            'class' => 'DEPENDEE',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee4.id',
            'range' => '4',
            'date_start' => '2018-01-01 00:00:00',
            'date_end' => '2019-01-01 00:00:01',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        // Depender
        $dataDepender = [
            'class' => 'DEPENDER',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => '2019-01-01 00:00:00',
            'date_end' => '2020-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        $dataDependency = [
            'class' => 'DEPENDER',
            'depend_on' => 'DEPENDEE',
        ];

        $this->_fixtureHelper->setupCrystalTask($dataDependee);
        $crystalTask = $this->_fixtureHelper->setupCrystalTask($dataDepender);
        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency);
        $this->assertTrue($this->_crystalTasksBaseService->isDependeeUnfinishedOrOverlapping($crystalTask));
    }

    /**
     * Should return true with completed and same date_end as date_start depender
     *
     * @throws Exception
     */
    public function testIsDependeeUnfinishedOrOverlappingSameDate()
    {
        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);

        // Dependee COMPLETED, but ended at the same time the depender date_start
        // Should match
        $dataDependee = [
            'class' => 'DEPENDEE',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee5.id',
            'range' => '4',
            'date_start' => '2018-01-01 00:00:00',
            'date_end' => '2019-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        // Depender
        $dataDepender = [
            'class' => 'DEPENDER',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => '2019-01-01 00:00:00',
            'date_end' => '2020-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        $dataDependency = [
            'class' => 'DEPENDER',
            'depend_on' => 'DEPENDEE',
        ];

        $this->_fixtureHelper->setupCrystalTask($dataDependee);
        $crystalTask = $this->_fixtureHelper->setupCrystalTask($dataDepender);
        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency);
        $this->assertTrue($this->_crystalTasksBaseService->isDependeeUnfinishedOrOverlapping($crystalTask));
    }

    /**
     * Should return true when there is a non-matching and a matching one
     *
     * @throws Exception
     */
    public function testIsDependeeUnfinishedOrOverlappingMatchWithMultipleDependees()
    {
        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);

        // Dependee COMPLETED, and ended before depender date_start
        // Should not match
        $dataDependee1 = [
            'class' => 'DEPENDEE',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee3.id',
            'range' => '4',
            'date_start' => '2018-01-01 00:00:00',
            'date_end' => '2018-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        // Dependee COMPLETED, but ended at the same time the depender date_start
        // Should match
        $dataDependee2 = [
            'class' => 'DEPENDEE',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee5.id',
            'range' => '4',
            'date_start' => '2018-01-01 00:00:00',
            'date_end' => '2019-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        // Depender
        $dataDepender = [
            'class' => 'DEPENDER',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => '2019-01-01 00:00:00',
            'date_end' => '2020-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        $dataDependency = [
            'class' => 'DEPENDER',
            'depend_on' => 'DEPENDEE',
        ];

        $this->_fixtureHelper->setupCrystalTask($dataDependee1);
        $this->_fixtureHelper->setupCrystalTask($dataDependee2);
        $crystalTask = $this->_fixtureHelper->setupCrystalTask($dataDepender);
        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency);
        $this->assertTrue($this->_crystalTasksBaseService->isDependeeUnfinishedOrOverlapping($crystalTask));
    }

    /**
     * Should return false when one has an error state
     *
     * @throws Exception
     */
    public function testIsDependeeUnfinishedOrOverlappingNotMatchWithErrorState()
    {
        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);

        // Dependee ERROR
        // Should not match
        $dataDependee = [
            'class' => 'DEPENDEE',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee3.id',
            'range' => '4',
            'date_start' => '2018-01-01 00:00:00',
            'date_end' => '2018-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_ERROR,
        ];

        // Depender
        $dataDepender = [
            'class' => 'DEPENDER',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => '2019-01-01 00:00:00',
            'date_end' => '2020-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        $dataDependency = [
            'class' => 'DEPENDER',
            'depend_on' => 'DEPENDEE',
        ];

        $this->_fixtureHelper->setupCrystalTask($dataDependee);
        $crystalTask = $this->_fixtureHelper->setupCrystalTask($dataDepender);
        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency);
        $this->assertFalse($this->_crystalTasksBaseService->isDependeeUnfinishedOrOverlapping($crystalTask));
    }

    /**
     * Should set state to ERROR when maxErrorTries is reached
     *
     * @throws Exception
     */
    public function testSaveCrystalTaskAndIncreaseErrorTriesSetErrorState()
    {
        $this->truncate(['crystal_tasks']);

        $data = [
            'class' => 'Foo',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee5.id',
            'range' => '4',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'error_tries' => 4,
        ];

        $crystalTask = $this->_fixtureHelper->setupCrystalTask($data);
        $this->_crystalTasksBaseService->saveCrystalTaskAndIncreaseErrorTries($crystalTask);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(5, $crystalTaskDb->error_tries);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_ERROR, $crystalTaskDb->state);
    }

    /**
     * Should only increase error_tries
     *
     * @throws Exception
     */
    public function testSaveCrystalTaskAndIncreaseErrorTriesSuccess()
    {
        $this->truncate(['crystal_tasks']);

        $data = [
            'class' => 'Foo',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee5.id',
            'range' => '4',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'error_tries' => 0,
        ];

        $crystalTask = $this->_fixtureHelper->setupCrystalTask($data);
        $this->_crystalTasksBaseService->saveCrystalTaskAndIncreaseErrorTries($crystalTask);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(1, $crystalTaskDb->error_tries);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_RUNNING, $crystalTaskDb->state);
    }

    /**
     * Should replace the current dependency
     */
    public function testUpdateCrystalTasksDependenciesWithOne()
    {
        $this->truncate(['crystal_tasks_dependencies']);

        $dataDependency = [
            'class' => 'DEPENDER1',
            'depend_on' => 'DEPENDEE1',
        ];

        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency);

        $dependenciesConfig = [
            [
                'class' => 'DEPENDER2',
                'depend_on' => 'DEPENDEE2',
            ]
        ];

        $crystalTaskDependencies = $this->_crystalTasksBaseService->dependenciesConfigToCrystalTasksDependencies($dependenciesConfig);
        $this->assertTrue($this->_crystalTasksBaseService->updateCrystalTasksDependencies($crystalTaskDependencies));

        $crystalTaskDependencyDb = $this->_crystalTasksDependenciesTable->getAll();
        $this->assertCount(1, $crystalTaskDependencyDb);
        $this->assertArraySubset($dependenciesConfig[0], (array)$crystalTaskDependencyDb[0]);
    }

    /**
     * Should work with an empty config
     */
    public function testUpdateCrystalTasksDependenciesEmptyConfig()
    {
        $this->truncate(['crystal_tasks_dependencies']);

        $dataDependency = [
            'class' => 'DEPENDER1',
            'depend_on' => 'DEPENDEE1',
        ];

        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency);

        $dependenciesConfig = [];
        $crystalTaskDependencies = $this->_crystalTasksBaseService->dependenciesConfigToCrystalTasksDependencies($dependenciesConfig);
        $this->assertTrue($this->_crystalTasksBaseService->updateCrystalTasksDependencies($crystalTaskDependencies));

        $crystalTaskDependencyDb = $this->_crystalTasksDependenciesTable->getAll();
        $this->assertCount(0, $crystalTaskDependencyDb);
    }

    /**
     * Should work with empty db
     */
    public function testUpdateCrystalTasksDependenciesEmptyDb()
    {
        $this->truncate(['crystal_tasks_dependencies']);

        $dependenciesConfig = [
            [
                'class' => 'DEPENDER2',
                'depend_on' => 'DEPENDEE2',
            ]
        ];

        $crystalTaskDependencies = $this->_crystalTasksBaseService->dependenciesConfigToCrystalTasksDependencies($dependenciesConfig);
        $this->assertTrue($this->_crystalTasksBaseService->updateCrystalTasksDependencies($crystalTaskDependencies));

        $crystalTaskDependencyDb = $this->_crystalTasksDependenciesTable->getAll();
        $this->assertCount(1, $crystalTaskDependencyDb);
    }

    /**
     * Should add and remove multiple
     */
    public function testUpdateCrystalTasksDependenciesWithMultiple()
    {
        $this->truncate(['crystal_tasks_dependencies']);

        $dataDependency1 = [
            'class' => 'DEPENDER1',
            'depend_on' => 'DEPENDEE1',
        ];

        $dataDependency2 = [
            'class' => 'DEPENDER2',
            'depend_on' => 'DEPENDEE2',
        ];

        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency1);
        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency2);

        $dependenciesConfig = [
            [
                'class' => 'DEPENDER2',
                'depend_on' => 'DEPENDEE2',
            ],
            [
                'class' => 'DEPENDER3',
                'depend_on' => 'DEPENDEE3',
            ]
        ];

        $crystalTaskDependencies = $this->_crystalTasksBaseService->dependenciesConfigToCrystalTasksDependencies($dependenciesConfig);
        $this->assertTrue($this->_crystalTasksBaseService->updateCrystalTasksDependencies($crystalTaskDependencies));

        $crystalTaskDependencyDb = $this->_crystalTasksDependenciesTable->getAll();
        $this->assertCount(2, $crystalTaskDependencyDb);
        $this->assertArraySubset($dependenciesConfig[0], (array)$crystalTaskDependencyDb[0]);
        $this->assertArraySubset($dependenciesConfig[1], (array)$crystalTaskDependencyDb[1]);
    }

    public function testCountNextToBeExecutedCrystalTasksShouldCountMultiple()
    {
        $this->truncate(['crystal_tasks']);

        $data1 = [
            'class' => 'Task1',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'dependee3.id',
            'range' => '4',
            'date_start' => '2018-01-01 00:00:00',
            'date_end' => '2018-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $data2 = [
            'class' => 'Task1',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => '2019-01-01 00:00:00',
            'date_end' => '2020-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $data3 = [
            'class' => 'Task2',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '4',
            'date_start' => '2019-01-01 00:00:00',
            'date_end' => '2020-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        // Wrong state
        $data4 = [
            'class' => 'Task2',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '99',
            'date_start' => '2019-01-01 00:00:00',
            'date_end' => '2020-01-01 00:00:00',
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];

        $this->_fixtureHelper->setupCrystalTask($data1);
        $this->_fixtureHelper->setupCrystalTask($data2);
        $this->_fixtureHelper->setupCrystalTask($data3);
        $this->_fixtureHelper->setupCrystalTask($data4);

        $taskClasses = ['Task1', 'Task2'];

        $crystalTaskCount = $this->_crystalTasksBaseService->countNextToBeExecutedCrystalTasks($taskClasses);

        $result = [
            [
                'class' => 'Task1',
                'dbCount' => 2,
            ], [
                'class' => 'Task2',
                'dbCount' => 1,
            ]
        ];

        $this->assertEquals($result, $crystalTaskCount);
    }

    public function testSortByClassNameEntityUidAndRangeIdSuccess()
    {
        $crystalTasks = [];
        $data1 = [
            'class' => 'Some\Task3',
            'entity_uid' => 'some.id',
            'range' => '4',
        ];

        $data2 = [
            'class' => 'Some\Task2',
            'entity_uid' => 'dependee2.id',
            'range' => 3,
        ];

        $data3 = [
            'class' => 'Some\TaskOk',
            'entity_uid' => 'dependee1.id',
            'range' => '4',
        ];

        $data4 = [
            'class' => 'Some\Task3',
            'entity_uid' => 'abc',
            'range' => 'abc'
        ];

        $data5 = [
            'class' => 'Some\Task3',
            'entity_uid' => 'some.id',
            'range' => 'abc'
        ];

        $crystalTasks[] = $this->_fixtureHelper->setupCrystalTask($data1);
        $crystalTasks[] = $this->_fixtureHelper->setupCrystalTask($data2);
        $crystalTasks[] = $this->_fixtureHelper->setupCrystalTask($data3);
        $crystalTasks[] = $this->_fixtureHelper->setupCrystalTask($data4);
        $crystalTasks[] = $this->_fixtureHelper->setupCrystalTask($data5);

        $result = $this->_crystalTasksBaseService->sortByClassNameEntityUidAndRangeId($crystalTasks);

        $this->assertEquals('Some\Task2', $result[0]->class);
        $this->assertEquals('Some\Task3', $result[1]->class);
        $this->assertEquals('Some\Task3', $result[2]->class);
        $this->assertEquals('Some\Task3', $result[3]->class);
        $this->assertEquals('Some\TaskOk', $result[4]->class);

        $this->assertEquals('dependee2.id', $result[0]->entity_uid);
        $this->assertEquals('abc', $result[1]->entity_uid);
        $this->assertEquals('some.id', $result[2]->entity_uid);
        $this->assertEquals('some.id', $result[3]->entity_uid);
        $this->assertEquals('dependee1.id', $result[4]->entity_uid);

        $this->assertEquals(3, $result[0]->range);
        $this->assertEquals('abc', $result[1]->range);
        $this->assertEquals('4', $result[2]->range);
        $this->assertEquals('abc', $result[3]->range);
        $this->assertEquals('4', $result[4]->range);
    }

    public function testUpdateCrystalTasksDependencies() {
        $this->truncate(['crystal_tasks_dependencies']);

        $data1 = [
            'class' => 'Task1',
            'depend_on' => 'Task2',
        ];

        $data2 = [
            'class' => 'Task3',
            'depend_on' => 'Task4',
        ];

        $this->_fixtureHelper->setupCrystalTaskDependency($data1);
        $this->_fixtureHelper->setupCrystalTaskDependency($data2);

        $crystalTaskDb1 = $this->_crystalTasksDependenciesTable->getByPK(1);
        $crystalTaskDb2 = $this->_crystalTasksDependenciesTable->getByPK(2);
        $crystalTaskDb3 = $this->_crystalTasksDependenciesTable->getByPK(3);
        $crystalTaskDb4 = $this->_crystalTasksDependenciesTable->getByPK(4);

        $this->assertNotNull($crystalTaskDb1);
        $this->assertNotNull($crystalTaskDb2);
        $this->assertNull($crystalTaskDb3);
        $this->assertNull($crystalTaskDb4);

        $data3 = [
            'class' => 'Task3',
            'depend_on' => 'Task4',
        ];

        $data4 = [
            'class' => 'Task5',
            'depend_on' => 'Task6',
        ];

        $crystalTasksDependenciesNew = [];
        $crystalTasksDependenciesNew[] = new CrystalTaskDependency($data3);
        $crystalTasksDependenciesNew[] = new CrystalTaskDependency($data4);

        $this->assertTrue($this->_crystalTasksBaseService->updateCrystalTasksDependencies($crystalTasksDependenciesNew));

        $crystalTaskDb1 = $this->_crystalTasksDependenciesTable->getByPK(1);
        $crystalTaskDb2 = $this->_crystalTasksDependenciesTable->getByPK(2);
        $crystalTaskDb3 = $this->_crystalTasksDependenciesTable->getByPK(3);
        $crystalTaskDb4 = $this->_crystalTasksDependenciesTable->getByPK(4);

        $this->assertNull($crystalTaskDb1);
        $this->assertNotNull($crystalTaskDb2);
        $this->assertNotNull($crystalTaskDb3);
        $this->assertNull($crystalTaskDb4);

    }
    /**
     * Should work with empty results and return null
     */
    public function testGetNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdateEmptyResult()
    {
        $this->truncate(['crystal_tasks']);
        $taskClassesAndGrantedExecutionSlots = [
            [
                'class' => 'Foo1',
                'grantedExecutionSlots' => 4,
            ],
        ];

        $result = $this->_crystalTasksBaseService->getNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdate(
            $taskClassesAndGrantedExecutionSlots
        );

        $this->assertEquals([], $result);
    }

    /**
     * Should return only the ones that matter
     */
    public function testGetNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdateFullResult()
    {
        $this->truncate(['crystal_tasks']);
        $data1 = [
            'class' => 'Task1',
            'range' => 1,
        ];

        $data2 = [
            'class' => 'Task1',
            'range' => 2,
        ];

        $data3 = [
            'class' => 'Task2',
            'range' => 1,
        ];

        $data4 = [
            'class' => 'Task2',
            'range' => 2,
        ];

        $data5 = [
            'class' => 'Task3',
            'range' => 1,
        ];

        $this->_fixtureHelper->setupCrystalTask($data1);
        $this->_fixtureHelper->setupCrystalTask($data2);
        $this->_fixtureHelper->setupCrystalTask($data3);
        $this->_fixtureHelper->setupCrystalTask($data4);
        $this->_fixtureHelper->setupCrystalTask($data5);

        $taskClassesAndGrantedExecutionSlots = [
            [
                'class' => 'Task1',
                'grantedExecutionSlots' => 1,
            ],
            [
                'class' => 'Task2',
                'grantedExecutionSlots' => 3,
            ],
            [
                'class' => 'Task3',
                'grantedExecutionSlots' => 1,
            ],
        ];

        $crystalTasks = $this->_crystalTasksBaseService->getNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdate(
            $taskClassesAndGrantedExecutionSlots
        );

        $this->assertCount(4, $crystalTasks);
        $this->assertEquals('Task1', $crystalTasks[0]->class);
        $this->assertEquals('Task2', $crystalTasks[1]->class);
        $this->assertEquals('Task2', $crystalTasks[2]->class);
        $this->assertEquals('Task3', $crystalTasks[3]->class);
    }

    public function testGetUniqueCrystalTaskWithForUpdateWithNullValues()
    {
        $this->truncate(['crystal_tasks']);
        $data1 = [
            'class' => 'Some\Task1',
            'entity_uid' => null,
            'range' => '',
        ];
        $crystalTask = $this->_fixtureHelper->setupCrystalTask($data1);

        $result = $this->_crystalTasksBaseService->getUniqueCrystalTaskWithForUpdate($crystalTask);
        $this->assertEquals(null, $crystalTask->entity_uid);
        $this->assertEquals('', $crystalTask->range);
        $this->assertEquals($result, $crystalTask);
    }
}

