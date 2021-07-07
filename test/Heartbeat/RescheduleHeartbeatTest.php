<?php

namespace Crystal\Test\Heartbeat;

use Crystal\Config\Config;
use Crystal\Crystal;
use Crystal\Entity\CrystalTask;
use Crystal\Heartbeat\RescheduleHeartbeat;
use Crystal\Test\Core\BaseTestApp;
use Crystal\Test\Core\FixtureHelper;
use Crystal\Test\Mock\Task\DependeeTask;
use Crystal\Test\Mock\Task\DependentTask;
use Crystal\Test\Traits\ArrayTestCaseTrait;
use DateTime;
use DateInterval;
use Exception;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

class RescheduleHeartbeatTest extends BaseTestApp
{
    use ArrayTestCaseTrait;

    private $_fixtureHelper;
    private $_rescheduleHeartbeat;
    private $_crystalTasksTable;
    private $_testHandler;

    /**
     * @throws Exception
     */
    protected function setUp()
    {
        parent::setUp();

        $config = array_merge($this->getDatabaseConfig(), $this->getSingleTaskConfig());
        $this->_testHandler = new TestHandler();
        $logger = new Logger('', [$this->_testHandler]);
        $crystal = new Crystal($config, $logger);

        $crystalTasksBaseService = $crystal->getCrystalTasksBaseService();
        $this->_crystalTasksTable = $crystal->getCrystalTasksTable();
        $crystalTasksRescheduleService = $crystal->getCrystalTasksRescheduleService();
        $this->_fixtureHelper = new FixtureHelper();

        // Use directly so we can manipulate the config
        $this->_rescheduleHeartbeat = new RescheduleHeartbeat(
            new Config($this->getSingleTaskConfig()),
            $crystalTasksBaseService,
            $crystalTasksRescheduleService
        );

        $this->truncate(['crystal_tasks', 'crystal_tasks_dependencies']);
    }

    /**
     * Should reschedule one DEAD task
     *
     * @throws Exception
     */
    public function testRescheduleCrystalTasksDeadOne()
    {
        $dataDead = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('P10Y'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING
        ];
        $this->_fixtureHelper->setupCrystalTask($dataDead);

        $this->_rescheduleHeartbeat->rescheduleCrystalTasks();

        $this->assertTrue($this->_testHandler->hasRecordThatContains(
            'Rescheduled CrystalTask',
            Logger::INFO
        ));

        $this->assertCount(1, $this->_crystalTasksTable->getAll());

        $dataRescheduled = array_merge($dataDead, [
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ]);

        $this->assertArraySubset($dataRescheduled, (array)$this->_crystalTasksTable->getByPK(1));
    }

    /**
     * Should reschedule multiple DEAD tasks and leave others
     *
     * @throws Exception
     */
    public function testRescheduleCrystalTasksDeadMultiple()
    {
        $dataDead1 = [
            'class' => DependeeTask::class,
            'timeout' => '99',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '32',
            'date_start' => (new DateTime)->sub(new DateInterval('P10Y'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING
        ];
        $dataDead2 = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('P10Y'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING
        ];
        $dataRunning = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '99',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING
        ];
        $dataCompleted = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '100',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED
        ];
        $dataNew = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '101',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW
        ];
        $this->_fixtureHelper->setupCrystalTask($dataDead1);
        $this->_fixtureHelper->setupCrystalTask($dataDead2);
        $this->_fixtureHelper->setupCrystalTask($dataRunning);
        $this->_fixtureHelper->setupCrystalTask($dataCompleted);
        $this->_fixtureHelper->setupCrystalTask($dataNew);

        $this->assertCount(5, $this->_crystalTasksTable->getAll());

        $this->_rescheduleHeartbeat->rescheduleCrystalTasks();
        $this->assertTrue($this->_testHandler->hasRecordThatContains(
            'Rescheduled CrystalTask',
            Logger::INFO
        ));

        $this->assertCount(5, $this->_crystalTasksTable->getAll());

        $dataRescheduled1 = array_merge($dataDead1, [
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ]);

        $dataRescheduled2 = array_merge($dataDead2, [
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ]);

        $this->assertArraySubset($dataRescheduled1, (array)$this->_crystalTasksTable->getByPK(1));
        $this->assertArraySubset($dataRescheduled2, (array)$this->_crystalTasksTable->getByPK(2));
        $this->assertArraySubset($dataRunning, (array)$this->_crystalTasksTable->getByPK(3));
        $this->assertArraySubset($dataCompleted, (array)$this->_crystalTasksTable->getByPK(4));
        $this->assertArraySubset($dataNew, (array)$this->_crystalTasksTable->getByPK(5));
    }

    /**
     * Should not reschedule NEW tasks
     *
     * @throws Exception
     */
    public function testRescheduleCrystalTasksNotNew()
    {
        $dataNew = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '4',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
            'date_created' => (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s'),
        ];

        $this->_fixtureHelper->setupCrystalTask($dataNew);

        $this->_rescheduleHeartbeat->rescheduleCrystalTasks();
        $this->assertFalse($this->_testHandler->hasRecords(Logger::ERROR));
        $this->assertFalse($this->_testHandler->hasRecords(Logger::INFO));

        $this->assertCount(1, $this->_crystalTasksTable->getAll());

        $this->assertArraySubset($dataNew, (array)$this->_crystalTasksTable->getByPK(1));
    }

    /**
     * Should not reschedule COMPLETED tasks
     *
     * @throws Exception
     */
    public function testRescheduleCrystalTasksShouldNotRescheduleCompleted()
    {
        $dataCompleted = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '4',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
            'date_created' => (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s'),
        ];
        $this->_fixtureHelper->setupCrystalTask($dataCompleted);
        $this->_rescheduleHeartbeat->rescheduleCrystalTasks();
        $this->assertFalse($this->_testHandler->hasRecords(Logger::ERROR));
        $this->assertFalse($this->_testHandler->hasRecords(Logger::INFO));
        $this->assertCount(1, $this->_crystalTasksTable->getAll());

        $this->assertArraySubset($dataCompleted, (array)$this->_crystalTasksTable->getByPK(1));
    }

    /**
     * Should not reschedule RUNNING tasks
     *
     * @throws Exception
     */
    public function testRescheduleCrystalTasksNotRunning()
    {
        $dataRunning = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '4',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'date_created' => (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s'),
        ];

        $this->_fixtureHelper->setupCrystalTask($dataRunning);

        $this->_rescheduleHeartbeat->rescheduleCrystalTasks();
        $this->assertFalse($this->_testHandler->hasRecords(Logger::ERROR));
        $this->assertFalse($this->_testHandler->hasRecords(Logger::INFO));
        $this->assertCount(1, $this->_crystalTasksTable->getAll());

        $this->assertArraySubset($dataRunning, (array)$this->_crystalTasksTable->getByPK(1));
    }

    /**
     * Should only reschedule depender when own status is NOT_COMPLETED or DEAD and there is a not completed dependee
     *
     * @throws Exception
     */
    public function testRescheduleCrystalTasksMultipleShouldNot()
    {
        $rescheduleCooldown = CrystalTask::STATE_CRYSTAL_TASK_RESCHEDULE_COOLDOWN;

        $dataDependeeCompleted = [
            'class' => DependeeTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '100',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];
        $dataDependeeRunning = [
            'class' => DependeeTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '99',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
        ];
        $dataDependeeDead = [
            'class' => DependeeTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '22100',
            'date_start' => (new DateTime)->sub(new DateInterval('P9Y'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
        ];
        $dataDependeeNotCompleted = [
            'class' => DependeeTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '3399',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->sub(new DateInterval('PT' . (2 + $rescheduleCooldown) . 'S'))->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED,
        ];
        $dataDependeeNew = [
            'class' => DependeeTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.id',
            'range' => '1003333',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];
        $dataDependentCompleted = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '101',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_COMPLETED,
        ];
        $dataDependentNotCompleted = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '939',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->sub(new DateInterval('PT' . (2 + $rescheduleCooldown) . 'S'))->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED,
        ];
        $dataDependentNew = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => 'abc',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];
        $dataDependentRunning = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '939222',
            'date_start' => (new DateTime)->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
        ];
        $dataDependentDead = [
            'class' => DependentTask::class,
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '9399',
            'date_start' => (new DateTime)->sub(new DateInterval('P9Y'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
        ];

        $dataDependency = [
            'class' => DependentTask::class,
            'depend_on' => DependeeTask::class,
        ];

        $this->_fixtureHelper->setupCrystalTask($dataDependeeCompleted);
        $this->_fixtureHelper->setupCrystalTask($dataDependeeRunning);
        $this->_fixtureHelper->setupCrystalTask($dataDependeeDead);
        $this->_fixtureHelper->setupCrystalTask($dataDependeeNotCompleted);
        $this->_fixtureHelper->setupCrystalTask($dataDependeeNew);
        $this->_fixtureHelper->setupCrystalTask($dataDependentCompleted);
        $this->_fixtureHelper->setupCrystalTask($dataDependentRunning);
        $this->_fixtureHelper->setupCrystalTask($dataDependentDead);
        $this->_fixtureHelper->setupCrystalTask($dataDependentNotCompleted);
        $this->_fixtureHelper->setupCrystalTask($dataDependentNew);

        $this->_fixtureHelper->setupCrystalTaskDependency($dataDependency);

        $this->assertCount(10, $this->_crystalTasksTable->getAll());

        $this->_rescheduleHeartbeat->rescheduleCrystalTasks();
        $this->assertTrue($this->_testHandler->hasRecordThatContains(
            'Rescheduled CrystalTask',
            Logger::INFO
        ));

        $this->assertCount(10, $this->_crystalTasksTable->getAll());

        $dataDependeeDead = array_merge($dataDependeeDead, [
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ]);

        $dataDependeeNotCompleted = array_merge($dataDependeeNotCompleted, [
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ]);

        $dataDependentDead = array_merge($dataDependentDead, [
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ]);

        $dataDependentNotCompleted = array_merge($dataDependentNotCompleted, [
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ]);

        // Should be rescheduled
        $this->assertArraySubset($dataDependeeDead, (array)$this->_crystalTasksTable->getByPK(3));
        $this->assertArraySubset($dataDependeeNotCompleted, (array)$this->_crystalTasksTable->getByPK(4));
        $this->assertArraySubset($dataDependentDead, (array)$this->_crystalTasksTable->getByPK(8));
        $this->assertArraySubset($dataDependentNotCompleted, (array)$this->_crystalTasksTable->getByPK(9));

        // Should not be rescheduled
        $this->assertArraySubset($dataDependeeCompleted, (array)$this->_crystalTasksTable->getByPK(1));
        $this->assertArraySubset($dataDependeeRunning, (array)$this->_crystalTasksTable->getByPK(2));
        $this->assertArraySubset($dataDependeeNew, (array)$this->_crystalTasksTable->getByPK(5));
        $this->assertArraySubset($dataDependentCompleted, (array)$this->_crystalTasksTable->getByPK(6));
        $this->assertArraySubset($dataDependentRunning, (array)$this->_crystalTasksTable->getByPK(7));
        $this->assertArraySubset($dataDependentNew, (array)$this->_crystalTasksTable->getByPK(10));
    }

    /**
     * Should (not) reschedule DEAD and NOT_COMPLETED when still in RESCHEDULE cooldown period
     *
     * @throws Exception
     */
    public function testRescheduleCrystalTasksShouldNotRescheduleWhenInCooldownPeriod()
    {
        $rescheduleCooldown = CrystalTask::STATE_CRYSTAL_TASK_RESCHEDULE_COOLDOWN;
        $deadCooldown = CrystalTask::STATE_CRYSTAL_TASK_RUNNING_TO_DEAD_COOLDOWN;

        // subtract 2 seconds for flexibility
        $deadPeriodNoReschedule = (4 + 1 + $rescheduleCooldown + $deadCooldown) - 2;
        $notCompletedPeriodNoReschedule = ($rescheduleCooldown) - 2;

        // add 2 seconds for flexibility
        $deadPeriodReschedule = (4 + 1 + $rescheduleCooldown + $deadCooldown) + 2;
        $notCompletedPeriodReschedule = ($rescheduleCooldown) + 2;

        // Should not reschedule
        $dataDead1 = [
            'class' => 'Foo1',
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('PT' . $deadPeriodNoReschedule . 'S'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'date_created' => (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s'),
        ];
        // Should not reschedule
        $dataNotCompleted2 = [
            'class' => 'Foo2',
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('PT99S'))->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->sub(new DateInterval('PT' . $notCompletedPeriodNoReschedule . 'S'))->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED,
            'date_created' => (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s'),
        ];

        // Should reschedule
        $dataDead3 = [
            'class' => 'Foo3',
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('PT' . $deadPeriodReschedule . 'S'))->format('Y-m-d H:i:s'),
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_RUNNING,
            'date_created' => (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s'),
        ];
        // Should reschedule
        $dataNotCompleted4 = [
            'class' => 'Foo4',
            'timeout' => '4',
            'cooldown' => '1',
            'entity_uid' => 'some.hash',
            'range' => '4',
            'date_start' => (new DateTime)->sub(new DateInterval('PT99S'))->format('Y-m-d H:i:s'),
            'date_end' => (new DateTime)->sub(new DateInterval('PT' . $notCompletedPeriodReschedule . 'S'))->format('Y-m-d H:i:s'),
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED,
            'date_created' => (new DateTime)->sub(new DateInterval('PT2S'))->format('Y-m-d H:i:s'),
        ];

        $this->_fixtureHelper->setupCrystalTask($dataDead1);
        $this->_fixtureHelper->setupCrystalTask($dataNotCompleted2);
        $this->_fixtureHelper->setupCrystalTask($dataDead3);
        $this->_fixtureHelper->setupCrystalTask($dataNotCompleted4);

        $this->_rescheduleHeartbeat->rescheduleCrystalTasks();

        $this->assertTrue($this->_testHandler->hasRecordThatContains(
            'Rescheduled CrystalTask',
            Logger::INFO
        ));

        $crystalTaskDb1 = $this->_crystalTasksTable->getByPK(1);
        $this->assertArraySubset($dataDead1, (array)$crystalTaskDb1);
        $crystalTaskDb2 = $this->_crystalTasksTable->getByPK(2);
        $this->assertArraySubset($dataNotCompleted2, (array)$crystalTaskDb2);
        /** @var CrystalTask $crystalTaskDb3 */
        $crystalTaskDb3 = $this->_crystalTasksTable->getByPK(3);
        $this->assertNotEquals($dataDead3['date_created'], $crystalTaskDb3->date_created);
        /** @var CrystalTask $crystalTaskDb4 */
        $crystalTaskDb4 = $this->_crystalTasksTable->getByPK(4);
        $this->assertNotEquals($dataNotCompleted4['date_created'], $crystalTaskDb4->date_created);
    }
}
