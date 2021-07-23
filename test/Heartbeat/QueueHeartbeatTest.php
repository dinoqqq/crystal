<?php

namespace Crystal\Test\Heartbeat;

use Crystal\Config\Config;

use Crystal\Config\ConfigInterface;
use Crystal\Crystal;
use Crystal\Entity\CrystalTask;
use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Heartbeat\QueueHeartbeat;
use Crystal\MainProcess\MainProcess;
use Crystal\MainProcess\MainProcessInterface;
use Crystal\RangeStrategy\HashRangeStrategy;
use Crystal\RangeStrategy\UniqueIdRangeStrategy;
use Crystal\Test\Core\BaseTestApp;
use Crystal\Test\Core\FixtureHelper;
use Crystal\Test\Mock\Queuer;
use Crystal\Test\Mock\Task\DependeeTask;
use Crystal\Test\Mock\Task\DependentTask;
use Crystal\Test\Mock\TaskFactory;
use Exception;
use DateTime;
use DateInterval;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Phake;

class QueueHeartbeatTest extends BaseTestApp
{
    private $_crystalTasksBaseService;
    private $_crystalTasksQueueService;
    private $_crystalTasksTable;
    private $_fixtureHelper;
    private $_taskFactory;

    /**
     * @throws Exception
     */
    public function setUp()
    {
        parent::setUp();

        $config = array_merge($this->getDatabaseConfig(), $this->getSingleTaskConfig());
        $testHandler = new TestHandler();
        $logger = new Logger('', [$testHandler]);
        $crystal = new Crystal($config, $logger);
        $crystal->start();

        $this->_crystalTasksBaseService = $crystal->getCrystalTasksBaseService();
        $this->_crystalTasksTable = $crystal->getCrystalTasksTable();
        $this->_crystalTasksQueueService = $crystal->getCrystalTasksQueueService();
        $this->_fixtureHelper = new FixtureHelper();


        $this->_taskFactory = new TaskFactory();
    }

    private function getConfigDependentDependeeTask(): array
    {
        return [
            'enable' => true,
            'maxExecutionSlots' => 3,
            'maxErrorTries' => 5,
            'applicationPhpFile' => '/foo/bar.php',
            'sleepTimeSeconds' => 1,
            'runTimeSeconds' => 60,
            'mainProcesses' => [
                [
                    'name' => 'DependentDependeeTask',
                    'tasks' => [
                        [
                            'class' => DependeeTask::class,
                        ],
                        [
                            'class' => DependentTask::class,
                            'dependOn' => DependeeTask::class,
                        ],
                    ],
                ],
            ],
            'tasks' => [
                [
                    'class' => DependeeTask::class,
                    'timeout' => 60,
                    'cooldown' => 1,
                    'resources' => 2,
                    'entityUid' => 'some.id',
                    'rangeStrategy' => UniqueIdRangeStrategy::class,
                ],
                [
                    'class' => DependentTask::class,
                    'timeout' => 33,
                    'cooldown' => 1,
                    'resources' => 3,
                    'entityUid' => 'some.hash',
                    'rangeStrategy' => HashRangeStrategy::class,
                ],
            ],
        ];
    }

    /**
     * @throws Exception
     */
    private function createMainProcess(ConfigInterface $config): MainProcessInterface
    {
        return MainProcess::create(
            $config,
            $this->_taskFactory,
            'DependentDependeeTask',
            ['uid' => 1]
        );
    }

    /**
     * @throws Exception
     */
    private function createQueueHeartbeat($queuer): QueueHeartbeat
    {
        return new QueueHeartbeat(
            new Config($this->getConfigDependentDependeeTask()),
            $this->_crystalTasksBaseService,
            $this->_crystalTasksQueueService,
            $queuer
        );
    }

    /**
     * Should throw and error and trigger "queueFailed" function, when there is already a (not-dependent) crystal task running
     *
     * @throws Exception
     */
    public function testQueueMainProcessesCrystalTaskAlreadyRunningNotDependent()
    {
        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);

        $config = array_merge($this->getDatabaseConfig(), $this->getConfigDependentDependeeTask());
        $config = new Config($config);

        $queuerMock = Phake::partialMock(Queuer::class);
        $mainProcess = $this->createMainProcess($config);
        Phake::when($queuerMock)->getNextMainProcesses(Phake::anyParameters())->thenReturn([$mainProcess]);
        $queueHeartbeat = $this->createQueueHeartbeat($queuerMock);

        // Not dependent on...
        $dataDependeeRunning = [
            'class' => DependeeTask::class,
            'timeout' => '9000',
            'cooldown' => '9000',
            'entity_uid' => 'some.id',
            'range' => '1',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
        ];
        $this->_fixtureHelper->setupCrystalTask($dataDependeeRunning);

        $queueHeartbeat->updateDependencies();
        $queueHeartbeat->queueMainProcesses();

        $exception = new CrystalTaskStateErrorException('RunningToNewStateChangeStrategy encountered, already picked up');
        Phake::verify($queuerMock)->queueingFailed($mainProcess, $exception);

        // Should NOT be rescheduled and NOT added
        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_RUNNING, $crystalTaskDb->state);
        $this->assertNotNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);
        $this->assertCount(1, $this->_crystalTasksTable->getAll());
    }

    /**
     * Should not reschedule the dependent task, it should ignore it and just schedule the other ones
     *
     * @throws Exception
     */
    public function testQueueMainProcessesCrystalTaskAlreadyRunningDependent()
    {
        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);

        $config = array_merge($this->getDatabaseConfig(), $this->getConfigDependentDependeeTask());
        $config = new Config($config);

        $queuerMock = Phake::partialMock(Queuer::class);
        $mainProcess = $this->createMainProcess($config);
        Phake::when($queuerMock)->getNextMainProcesses(Phake::anyParameters())->thenReturn([$mainProcess]);
        $queueHeartbeat = $this->createQueueHeartbeat($queuerMock);

        $dataDependentRunning = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '1',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
        ];

        $this->_fixtureHelper->setupCrystalTask($dataDependentRunning);

        $queueHeartbeat->updateDependencies();
        $queueHeartbeat->queueMainProcesses();

        Phake::verify($queuerMock)->queueingStop($mainProcess);

        // Should NOT be rescheduled and NOT added
        /** @var CrystalTask $crystalTaskDb1 */
        $crystalTaskDb1 = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_RUNNING, $crystalTaskDb1->state);
        $this->assertNotNull($crystalTaskDb1->date_start);
        $this->assertNull($crystalTaskDb1->date_end);

        // The rest should be added successfully
        /** @var CrystalTask $crystalTaskDb2 */
        $crystalTaskDb2 = $this->_crystalTasksTable->getByPK(2);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb2->state);
        /** @var CrystalTask $crystalTaskDb3 */
        $crystalTaskDb3 = $this->_crystalTasksTable->getByPK(3);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb3->state);
        /** @var CrystalTask $crystalTaskDb4 */
        $crystalTaskDb4 = $this->_crystalTasksTable->getByPK(4);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb4->state);
        /** @var CrystalTask $crystalTaskDb5 */
        $crystalTaskDb5 = $this->_crystalTasksTable->getByPK(5);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb5->state);

        $this->assertCount(5, $this->_crystalTasksTable->getAll());
    }

    /**
     * Should schedule 1 dependee + 3 dependent tasks with the right ranges
     *
     * @throws Exception
     */
    public function testQueueMainProcessesCrystalTaskOneDependeeThreeDependent()
    {
        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);

        $config = array_merge($this->getDatabaseConfig(), $this->getConfigDependentDependeeTask());
        $config = new Config($config);

        $queuerMock = Phake::partialMock(Queuer::class);
        $mainProcess = $this->createMainProcess($config);
        Phake::when($queuerMock)->getNextMainProcesses(Phake::anyParameters())->thenReturn([$mainProcess]);
        $queueHeartbeat = $this->createQueueHeartbeat($queuerMock);

        $queueHeartbeat->updateDependencies();
        $queueHeartbeat->queueMainProcesses();

        Phake::verify($queuerMock)->queueingStop($mainProcess);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(DependeeTask::class, $crystalTaskDb->class);
        $this->assertEquals(60, $crystalTaskDb->timeout);
        $this->assertEquals('some.id', $crystalTaskDb->entity_uid);
        $this->assertEquals(1, $crystalTaskDb->range);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(2);
        $this->assertEquals(DependentTask::class, $crystalTaskDb->class);
        $this->assertEquals(33, $crystalTaskDb->timeout);
        $this->assertEquals('some.hash', $crystalTaskDb->entity_uid);
        $this->assertEquals('012345', $crystalTaskDb->range);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(3);
        $this->assertEquals(DependentTask::class, $crystalTaskDb->class);
        $this->assertEquals(33, $crystalTaskDb->timeout);
        $this->assertEquals('some.hash', $crystalTaskDb->entity_uid);
        $this->assertEquals('6789a', $crystalTaskDb->range);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(4);
        $this->assertEquals(DependentTask::class, $crystalTaskDb->class);
        $this->assertEquals(33, $crystalTaskDb->timeout);
        $this->assertEquals('some.hash', $crystalTaskDb->entity_uid);
        $this->assertEquals('bcdef', $crystalTaskDb->range);
    }

    /**
     * Should reschedule 1 dependee + 2 dependent tasks with the right timeout + cooldown
     *
     * @throws Exception
     */
    public function testPQueueMainProcessesCrystalTaskRescheduleWithTimeoutChange()
    {
        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);

        $dataDependeeCompleted = [
            'class' => DependeeTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '1',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];
        $dataDependentCompleted1 = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '01234567',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];
        $dataDependentError1 = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '89abcdef',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_ERROR,
        ];

        $dataDependency1 = [
            'class' => DependeeTask::class,
            'depend_on' => 'foo',
        ];

        $dataDependency2 = [
            'class' => DependentTask::class,
            'depend_on' => 'bar',
        ];

        $this->_fixtureHelper->setupCrystalTask($dataDependeeCompleted);
        $this->_fixtureHelper->setupCrystalTask($dataDependentCompleted1);
        $this->_fixtureHelper->setupCrystalTask($dataDependentError1);

        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency1);
        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency2);

        // Change some things
        $config = $this->getConfigDependentDependeeTask();
        $config['tasks'][0]['timeout'] = 93;
        $config['tasks'][0]['cooldown'] = 111;
        $config['tasks'][1]['timeout'] = 992;
        $config['tasks'][1]['cooldown'] = 111;
        $config['tasks'][1]['resources'] = 2;

        $config = array_merge($this->getDatabaseConfig(), $config);
        $config = new Config($config);

        $queuerMock = Phake::partialMock(Queuer::class);
        $mainProcess = $this->createMainProcess($config);
        Phake::when($queuerMock)->getNextMainProcesses(Phake::anyParameters())->thenReturn([$mainProcess]);
        $queueHeartbeat = $this->createQueueHeartbeat($queuerMock);

        $queueHeartbeat->queueMainProcesses();

        Phake::verify($queuerMock)->queueingStop($mainProcess);

        $this->assertCount(3, $this->_crystalTasksTable->getAll());

        // Should all be rescheduled with new timeout
        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(DependeeTask::class, $crystalTaskDb->class);
        $this->assertEquals(93, $crystalTaskDb->timeout);
        $this->assertEquals(111, $crystalTaskDb->cooldown);
        $this->assertEquals('some.id', $crystalTaskDb->entity_uid);
        $this->assertEquals(1, $crystalTaskDb->range);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb->state);
        $this->assertNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(2);
        $this->assertEquals(DependentTask::class, $crystalTaskDb->class);
        $this->assertEquals(992, $crystalTaskDb->timeout);
        $this->assertEquals(111, $crystalTaskDb->cooldown);
        $this->assertEquals('some.hash', $crystalTaskDb->entity_uid);
        $this->assertEquals('01234567', $crystalTaskDb->range);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb->state);
        $this->assertNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(3);
        $this->assertEquals(DependentTask::class, $crystalTaskDb->class);
        $this->assertEquals(992, $crystalTaskDb->timeout);
        $this->assertEquals(111, $crystalTaskDb->cooldown);
        $this->assertEquals('some.hash', $crystalTaskDb->entity_uid);
        $this->assertEquals('89abcdef', $crystalTaskDb->range);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb->state);
        $this->assertNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);
    }

    /**
     * Should reschedule 1 dependee + reschedule 2 dependent tasks + ignore 2 other dependent tasks with the wrong status
     *
     * @throws Exception
     */
    public function testQueueMainProcessesCrystalTaskRescheduleSomeNot()
    {
        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);

        $dataDependeeCompleted = [
            'class' => DependeeTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '1',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];
        $dataDependentCompleted = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '0123',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];
        $dataDependentNotCompleted = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '4567',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED,
        ];
        $dataDependentRunning = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '89ab',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
        ];
        $dateCreated = (new DateTime)->sub(new DateInterval('P2Y'))->format('Y-m-d H:i:s');
        $dataDependentNew = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => 'cdef',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
            'date_created' => $dateCreated
        ];

        $dataDependency = [
            'class' => DependentTask::class,
            'depend_on' => DependeeTask::class,
        ];

        $this->_fixtureHelper->setupCrystalTask($dataDependeeCompleted);
        $this->_fixtureHelper->setupCrystalTask($dataDependentCompleted);
        $this->_fixtureHelper->setupCrystalTask($dataDependentNotCompleted);
        $this->_fixtureHelper->setupCrystalTask($dataDependentRunning);
        $this->_fixtureHelper->setupCrystalTask($dataDependentNew);

        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency);

        $config = $this->getConfigDependentDependeeTask();
        $config['tasks'][1]['resources'] = 4;

        $config = array_merge($this->getDatabaseConfig(), $config);
        $config = new Config($config);

        $queuerMock = Phake::partialMock(Queuer::class);
        $mainProcess = $this->createMainProcess($config);
        Phake::when($queuerMock)->getNextMainProcesses(Phake::anyParameters())->thenReturn([$mainProcess]);
        $queueHeartbeat = $this->createQueueHeartbeat($queuerMock);

        $queueHeartbeat->queueMainProcesses();

        Phake::verify($queuerMock)->queueingStop($mainProcess);

        $this->assertCount(5, $this->_crystalTasksTable->getAll());

        // Should be rescheduled
        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb->state);
        $this->assertNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);

        // Should be rescheduled
        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(2);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb->state);
        $this->assertNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);

        // Should NOT be rescheduled
        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(3);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED, $crystalTaskDb->state);
        $this->assertNotNull($crystalTaskDb->date_start);
        $this->assertNotNull($crystalTaskDb->date_end);

        // Should NOT be rescheduled
        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(4);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_RUNNING, $crystalTaskDb->state);
        $this->assertNotNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);

        // Should be rescheduled
        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(5);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_NEW, $crystalTaskDb->state);
        $this->assertNotEquals($dateCreated, $crystalTaskDb->date_created);
        $this->assertNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);
    }
}
