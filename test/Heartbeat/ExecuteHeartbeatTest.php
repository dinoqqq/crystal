<?php

namespace Crystal\Test\Heartbeat;

use Crystal\Config\Config;
use Crystal\Crystal;
use Crystal\Heartbeat\ExecuteHeartbeat;
use Crystal\RangeStrategy\HashRangeStrategy;
use Crystal\RangeStrategy\UniqueIdRangeStrategy;
use Crystal\Test\Core\BaseTestApp;
use Crystal\Test\Core\FixtureHelper;
use Crystal\Test\Mock\Task\DependeeTask;
use Crystal\Test\Mock\Task\DependentTask;
use Crystal\Test\Mock\Task\SuccessTask;
use Crystal\Test\Mock\Task\ThirtySecondsTask;
use Exception;
use Crystal\Entity\CrystalTask;
use Crystal\PriorityStrategy\DivideTotalValueEquallyPriorityStrategy;
use Crystal\PriorityStrategy\PriorityStrategyFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Phake;
use Phake_IMock;

class ExecuteHeartbeatTest extends BaseTestApp
{
    private $_fixtureHelper;
    private $_crystalTasksBaseService;
    /** @var ExecuteHeartbeat */
    private $_executeHeartbeatMock;
    private $_crystalTasksExecuteService;
    private $_crystalTasksTable;
    private $_crystal;

    /**
     * @throws Exception
     */
    public function setUp()
    {
        parent::setUp();

        $config = array_merge($this->getDatabaseConfig(), $this->getSingleTaskConfig());
        $testHandler = new TestHandler();
        $logger = new Logger('', [$testHandler]);
        $this->_crystal = new Crystal($config, $logger);
        $this->_crystal->start();

        $this->_crystalTasksBaseService = $this->_crystal->getCrystalTasksBaseService();
        $this->_crystalTasksTable = $this->_crystal->getCrystalTasksTable();
        $this->_crystalTasksExecuteService = $this->_crystal->getCrystalTasksExecuteService();
        $this->_fixtureHelper = new FixtureHelper();

        // Use directly so we can manipulate the config
        $priorityStrategyFactoryMock = $this->createPriorityStrategyFactoryMock($this->getSingleTaskConfig());
        $this->_executeHeartbeatMock = $this->createExecuteHeartbeatMock($this->getSingleTaskConfig(), $priorityStrategyFactoryMock);

        // disable spawning of real processes

        /** @noinspection PhpUndefinedMethodInspection */
        Phake::when($this->_executeHeartbeatMock)->executePhp(Phake::anyParameters())->thenReturn(true);

        $this->truncate(['crystal_tasks']);
    }

    private function getConfigDependeeTaskWithDivideTotalValueEquallyPriorityStrategy(): array
    {
        $config = $this->getSingleTaskConfig();
        unset($config['maxExecutionSlots']);
        unset($config['tasks']);
        unset($config['priorityStrategy']);

        return array_merge_recursive($config, [
            'maxExecutionSlots' => 3,
            'priorityStrategy' => DivideTotalValueEquallyPriorityStrategy::class,
            'tasks' => [
                [
                    'class' => DependeeTask::class,
                    'timeout' => 60,
                    'cooldown' => 60,
                    'entityUid' => 'some.id',
                    'rangeStrategy' => UniqueIdRangeStrategy::class,
                    'priority' => 60,
                ],
                [
                    'class' => ThirtySecondsTask::class,
                    'timeout' => 60,
                    'cooldown' => 60,
                    'entityUid' => 'some.id',
                    'rangeStrategy' => UniqueIdRangeStrategy::class,
                    'priority' => 10,
                ],
                [
                    'class' => DependentTask::class,
                    'timeout' => 60,
                    'cooldown' => 60,
                    'entityUid' => 'some.hash',
                    'rangeStrategy' => HashRangeStrategy::class,
                    'priority' => 30,
                ],
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    private function createExecuteHeartbeatMock(array $config, $priorityStrategyFactory): Phake_IMock
    {
        return Phake::partialMock(
            ExecuteHeartbeat::class,
            new Config($config),
            $this->_crystalTasksBaseService,
            $this->_crystalTasksExecuteService,
            $priorityStrategyFactory
        );
    }

    /**
     * @throws Exception
     */
    private function createPriorityStrategyFactoryMock(array $config): Phake_IMock
    {
        return Phake::partialMock(
            PriorityStrategyFactory::class,
            $config,
            $this->_crystalTasksBaseService
        );
    }

    /**
     * Should add a crystalTask with the some id set as range
     *
     * @throws Exception
     */
    public function testSpawnCrystalTaskShouldSpawnOneTask()
    {
        $class = DependeeTask::class;
        $crystalTask = $this->_fixtureHelper->setupCrystalTask([
            'class' => $class,
            'entity_uid' => 'some.id',
            'range' => 1,
            'timeout' => 30,
            'cooldown' => 1,
        ]);

        $this->_executeHeartbeatMock->spawnCrystalTask($crystalTask);

        /** @var ExecuteHeartbeat $phakeVerify */
        $phakeVerify = Phake::verify($this->_executeHeartbeatMock);
        $phakeVerify->executePhp(Phake::capture($exec));
        $applicationPhpFile = $this->_crystal->getConfig()->getConfigByKey('applicationPhpFile');
        $this->assertEquals("php -f " . escapeshellarg($applicationPhpFile) . " crystaltaskexecute  --id='1' --class="
            . escapeshellarg($class) . " --range='1' --timeout='30' --cooldown='1' 1>/dev/null &",
            $exec
        );
    }

    /**
     * Should add a crystalTask with the some.id id set as range
     *
     * @throws Exception
     */
    public function testSpawnCrystalTaskShouldSpawnOneTaskDifferent()
    {
        $class = SuccessTask::class;
        $crystalTask = $this->_fixtureHelper->setupCrystalTask([
            'class' => $class,
            'entity_uid' => 'some.id',
            'range' => 9,
            'timeout' => 11,
            'cooldown' => 2,
        ]);

        $this->_executeHeartbeatMock->spawnCrystalTask($crystalTask);

        /** @var ExecuteHeartbeat $phakeVerify */
        $phakeVerify = Phake::verify($this->_executeHeartbeatMock);
        $phakeVerify->executePhp(Phake::capture($exec));
        $applicationPhpFile = $this->_crystal->getConfig()->getConfigByKey('applicationPhpFile');
        $this->assertEquals("php -f " . escapeshellarg($applicationPhpFile) . " crystaltaskexecute  --id='1' --class="
            . escapeshellarg($class) . " --range='9' --timeout='11' --cooldown='2' 1>/dev/null &",
            $exec
        );
    }

    /**
     * Should update the tasks in the db
     *
     * @throws Exception
     */
    public function testProcessCrystalTasksShouldUpdateTasksInDb()
    {
        $this->_fixtureHelper->setupCrystalTask([
            'class' => SuccessTask::class,
            'entity_uid' => 'some.id',
            'range' => 9,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
            'timeout' => 11,
            'cooldown' => '1',
        ]);

        $this->_fixtureHelper->setupCrystalTask([
            'class' => ThirtySecondsTask::class,
            'entity_uid' => 'some.id',
            'range' => 10,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
            'timeout' => 12,
            'cooldown' => '1',
        ]);

        $this->_executeHeartbeatMock->processCrystalTasks();

        /** @var CrystalTask $crystalTaskDb */
        $crystalTaskDb = $this->_crystalTasksTable->getByPK(1);
        $this->assertEquals(SuccessTask::class, $crystalTaskDb->class);
        $this->assertEquals(11, $crystalTaskDb->timeout);
        $this->assertEquals('some.id', $crystalTaskDb->entity_uid);
        $this->assertEquals(9, $crystalTaskDb->range);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_RUNNING, $crystalTaskDb->state);
        $this->assertNotNull($crystalTaskDb->date_start);
        $this->assertNull($crystalTaskDb->date_end);

        /** @var CrystalTask $crystalTaskDb2 */
        $crystalTaskDb2 = $this->_crystalTasksTable->getByPK(2);
        $this->assertEquals(ThirtySecondsTask::class, $crystalTaskDb2->class);
        $this->assertEquals(12, $crystalTaskDb2->timeout);
        $this->assertEquals('some.id', $crystalTaskDb2->entity_uid);
        $this->assertEquals(10, $crystalTaskDb2->range);
        $this->assertEquals(CrystalTask::STATE_CRYSTAL_TASK_RUNNING, $crystalTaskDb2->state);
        $this->assertNotNull($crystalTaskDb2->date_start);
        $this->assertNull($crystalTaskDb2->date_end);
    }

    /**
     * Should work with priorityStrategy
     *
     * @throws Exception
     */
    public function testGetNextToBeExecutedCrystalTasksByPriorityStrategyShouldWorkWithDivideTotalValueEquallyPriorityStrategy()
    {
        $priorityStrategyFactoryMock = $this->createPriorityStrategyFactoryMock(
            $this->getConfigDependeeTaskWithDivideTotalValueEquallyPriorityStrategy()
        );

        /** @var ExecuteHeartbeat $executeHeartbeatMock */
        $executeHeartbeatMock = $this->createExecuteHeartbeatMock(
            $this->getConfigDependeeTaskWithDivideTotalValueEquallyPriorityStrategy(),
            $priorityStrategyFactoryMock
        );

        $dataDependeeTask = [
            'class' => DependeeTask::class,
            'entity_uid' => 'some.id',
            'range' => 1,
            'timeout' => 11,
            'cooldown' => 1,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $dataThirtySecondsTask = [
            'class' => ThirtySecondsTask::class,
            'entity_uid' => 'some.id',
            'range' => 2,
            'timeout' => 11,
            'cooldown' => 1,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $dataDependentTask = [
            'class' => DependentTask::class,
            'entity_uid' => 'some.id',
            'range' => 3,
            'timeout' => 11,
            'cooldown' => 1,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 1]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 2]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 3]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 4]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 5]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataThirtySecondsTask, ['range' => 6]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataThirtySecondsTask, ['range' => 7]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataThirtySecondsTask, ['range' => 8]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataThirtySecondsTask, ['range' => 9]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataThirtySecondsTask, ['range' => 10]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependentTask, ['range' => 11]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependentTask, ['range' => 12]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependentTask, ['range' => 13]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependentTask, ['range' => 14]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependentTask, ['range' => 15]));

        $availableExecutionSlots = 7;
        $crystalTasksArray = $executeHeartbeatMock->getNextToBeExecutedCrystalTasksByPriorityStrategy($availableExecutionSlots);

        $crystalTaskRanges = array_column($crystalTasksArray, 'range');
        $crystalTaskClasses = array_column($crystalTasksArray, 'class');
        sort($crystalTaskRanges);
        sort($crystalTaskClasses);

        $this->assertEquals(['1', '2', '3', '4', '6', '11', '12'], $crystalTaskRanges);
        $this->assertEquals([
            DependeeTask::class,
            DependeeTask::class,
            DependeeTask::class,
            DependeeTask::class,
            DependentTask::class,
            DependentTask::class,
            ThirtySecondsTask::class,
        ], $crystalTaskClasses);
    }

    /**
     * Should fallback to SortByDateCreatedPriorityStrategy (when error in config)
     *
     * @throws Exception
     */
    public function testGetNextToBeExecutedCrystalTasksByPriorityStrategyShouldFallbackSortByDateCreatedPriorityStrategy()
    {
        $config = $this->getConfigDependeeTaskWithDivideTotalValueEquallyPriorityStrategy();

        $config['tasks'][] = ['class' => 'foo'];

        $priorityStrategyFactoryMock = $this->createPriorityStrategyFactoryMock(
            $config
        );

        /** @var ExecuteHeartbeat $executeHeartbeatMock */
        $executeHeartbeatMock = $this->createExecuteHeartbeatMock(
            $config,
            $priorityStrategyFactoryMock
        );

        $dataDependeeTask = [
            'class' => DependeeTask::class,
            'entity_uid' => 'some.id',
            'range' => 1,
            'timeout' => 11,
            'cooldown' => 1,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $dataThirtySecondsTask = [
            'class' => ThirtySecondsTask::class,
            'entity_uid' => 'some.id',
            'range' => 2,
            'timeout' => 11,
            'cooldown' => 1,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $dataDependentTask = [
            'class' => DependentTask::class,
            'entity_uid' => 'some.id',
            'range' => 3,
            'timeout' => 11,
            'cooldown' => 1,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 1]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 2]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 3]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 4]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 5]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataThirtySecondsTask, ['range' => 6]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataThirtySecondsTask, ['range' => 7]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataThirtySecondsTask, ['range' => 8]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataThirtySecondsTask, ['range' => 9]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataThirtySecondsTask, ['range' => 10]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependentTask, ['range' => 11]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependentTask, ['range' => 12]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependentTask, ['range' => 13]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependentTask, ['range' => 14]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependentTask, ['range' => 15]));

        $availableExecutionSlots = 7;
        $crystalTasksArray = $executeHeartbeatMock->getNextToBeExecutedCrystalTasksByPriorityStrategy($availableExecutionSlots);

        $crystalTaskRanges = array_column($crystalTasksArray, 'range');
        $crystalTaskClasses = array_column($crystalTasksArray, 'class');
        sort($crystalTaskRanges);
        sort($crystalTaskClasses);

        $this->assertEquals(['1', '2', '3', '4', '5', '6', '7'], $crystalTaskRanges);
        $this->assertEquals([
            DependeeTask::class,
            DependeeTask::class,
            DependeeTask::class,
            DependeeTask::class,
            DependeeTask::class,
            ThirtySecondsTask::class,
            ThirtySecondsTask::class,
        ], $crystalTaskClasses);
    }

    /**
     * Should throw an Exception when maxExecutionSlots is exceeded
     *
     * @throws Exception
     */
    public function testCheckNextToBeExecutedCrystalTasksDoNotExceedMaxExecutionSlots()
    {
        $priorityStrategyFactoryMock = $this->createPriorityStrategyFactoryMock(
            $this->getConfigDependeeTaskWithDivideTotalValueEquallyPriorityStrategy()
        );

        /** @var ExecuteHeartbeat $executeHeartbeatMock */
        $executeHeartbeatMock = $this->createExecuteHeartbeatMock(
            $this->getConfigDependeeTaskWithDivideTotalValueEquallyPriorityStrategy(),
            $priorityStrategyFactoryMock
        );

        $dataDependeeTask = [
            'class' => DependeeTask::class,
            'entity_uid' => 'some.id',
            'range' => 1,
            'timeout' => 11,
            'cooldown' => 1,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 1]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 2]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 3]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 4]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataDependeeTask, ['range' => 5]));

        $crystalTasks = $executeHeartbeatMock->getNextToBeExecutedCrystalTasksByPriorityStrategy(4);

        $this->expectException(Exception::class);
        $this->_executeHeartbeatMock->checkNextToBeExecutedCrystalTasksDoNotExceedMaxExecutionSlots($crystalTasks);
    }
}
