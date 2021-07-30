<?php

namespace Crystal\Test\Service;

use Crystal\Crystal;

use Crystal\Entity\CrystalTask;
use Crystal\PriorityStrategy\DivideTotalValueEquallyPriorityStrategy;
use Crystal\Test\Core\BaseTestApp;
use Crystal\Test\Core\FixtureHelper;
use Crystal\Test\Mock\Task\SuccessTask;
use Crystal\Test\Traits\ArrayTestCaseTrait;
use Exception;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

class CrystalTasksExecuteServiceTest extends BaseTestApp
{
    use ArrayTestCaseTrait;

    private $_crystalTasksTable;
    private $_crystalTasksExecuteService;
    private $_fixtureHelper;
    private $_crystal;
    private $_config;

    /**
     * @throws Exception
     */
    public function setUp()
    {
        parent::setUp();

        $this->_config = array_merge($this->getDatabaseConfig(), $this->getGlobalConfig());
        $testHandler = new TestHandler();
        $logger = new Logger('', [$testHandler]);
        $this->_crystal = new Crystal($this->_config, $logger);
        $this->_crystal->start();

        $this->_crystalTasksExecuteService = $this->_crystal->getCrystalTasksExecuteService();
        $this->_crystalTasksTable = $this->_crystal->getCrystalTasksTable();
        $this->_fixtureHelper = new FixtureHelper();
    }

    /**
     * Should create a new task when nothing is in the db yet
     *
     * @throws Exception
     */
    public function testGetNextToBeExecutedCrystalTasksByPriorityStrategyAndChangeStateShouldWorkWithEmptyResults()
    {
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

        $divideTotalValueEquallyPriorityStrategy = new DivideTotalValueEquallyPriorityStrategy(
            $this->_config,
            $this->_crystal->getCrystalTasksBaseService()
        );

        $crystalTasks = $this->_crystalTasksExecuteService->getNextToBeExecutedCrystalTasksByPriorityStrategyAndChangeState(
            $divideTotalValueEquallyPriorityStrategy,
            3
        );

        $this->assertSame([], $crystalTasks);
    }
}
