<?php

namespace Crystal\Test\PriorityStrategy;

use Crystal\Crystal;
use Crystal\Entity\CrystalTask;
use Crystal\PriorityStrategy\DivideTotalValueEquallyPriorityStrategy;
use Crystal\PriorityStrategy\SortByDateCreatedPriorityStrategy;
use Crystal\Test\Core\BaseTestApp;
use Crystal\Test\Core\FixtureHelper;
use Crystal\Test\Mock\Task\DependeeTask;
use Crystal\Test\Mock\Task\DependentTask;
use Crystal\Test\Mock\Task\ThirtySecondsTask;
use Exception;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use ReflectionException;

class DivideTotalValueEquallyPriorityStrategyTest extends BaseTestApp
{
    private $_crystalTasksBaseService;
    private $_fixtureHelper;
    private $_config;

    /**
     * @throws Exception
     */
    public function setUp()
    {
        parent::setUp();

        $this->_config = $this->getGlobalConfig();

        $config = array_merge($this->getDatabaseConfig(), $this->getGlobalConfig());
        $testHandler = new TestHandler();
        $logger = new Logger('', [$testHandler]);
        $crystal = new Crystal($config, $logger);
        $crystal->start();

        $this->_crystalTasksBaseService = $crystal->getCrystalTasksBaseService();
        $this->_fixtureHelper = new FixtureHelper;
    }

    /**
     * @throws Exception
     */
    private function createDivideTotalValueEquallyPriorityStrategy(array $config = []): DivideTotalValueEquallyPriorityStrategy
    {
        return new DivideTotalValueEquallyPriorityStrategy(
            empty($config) ? $this->_config : $config,
            $this->_crystalTasksBaseService
        );
    }

    /**
     * Should match exactly
     *
     * @throws Exception
     */
    public function testIterateArrayAndCheckKeysExistShouldMatchExact()
    {
        $config = [
            'priorityStrategy' => SortByDateCreatedPriorityStrategy::class,
            'applicationPhpFile' => '/foo/bar.php',
            'maxExecutionSlots' => 10,
            'tasks' => [
                [
                    'class' => 'Foo',
                    'priority' => 1
                ]
            ]
        ];

        $this->createDivideTotalValueEquallyPriorityStrategy($config);

        // No exceptions thrown
        $this->assertTrue(true);
    }

    /**
     * Should match with a deeper array
     *
     * @throws Exception
     */
    public function testIterateArrayAndCheckKeysExistShouldMatchDeeper()
    {
        $config = [
            'priorityStrategy' => SortByDateCreatedPriorityStrategy::class,
            'applicationPhpFile' => '/foo/bar.php',
            'maxExecutionSlots' => 10,
            'tasks' => [
                [
                    'class' => [
                        'Foo',
                    ],
                    'priority' => 1

                ]
            ]
        ];

        $this->createDivideTotalValueEquallyPriorityStrategy($config);

        // No exceptions thrown
        $this->assertTrue(true);
    }

    /**
     * Should match when no key names specified
     *
     * @throws Exception
     */
    public function testIterateArrayAndCheckKeysExistShouldMatchWithEmptyKeyNames()
    {
        $config = [
            'priorityStrategy' => SortByDateCreatedPriorityStrategy::class,
            'applicationPhpFile' => '/foo/bar.php',
            'maxExecutionSlots' => 10,
            'tasks' => [
                [
                    'class' => 'Foo',
                    'priority' => 1
                ]
            ]
        ];

        $divideTotalValueEquallyPriorityStrategy = new DivideTotalValueEquallyPriorityStrategy(
            $config,
            $this->_crystalTasksBaseService
        );

        $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'iterateArrayAndCheckKeysExist',
            [
                $config,
                [],
            ]
        );

        // No exceptions thrown
        $this->assertTrue(true);
    }

    /**
     * Should throw when key do not match
     *
     * @throws Exception
     */
    public function testIterateArrayAndCheckKeysExistShouldNotMatchWrongKeyName()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Key not found in array: "class"');

        $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'iterateArrayAndCheckKeysExist',
            [
                [
                    'applicationPhpFile' => '/foo/bar.php',
                    'maxExecutionSlots' => 1,
                    'priorityStrategy' => SortByDateCreatedPriorityStrategy::class,
                    'tasks' => [
                        [
                            'class'
                        ]
                    ]
                ],
                ['tasks', 'class'],
            ]
        );
    }

    /**
     * Should throw when empty config
     *
     * @throws Exception
     */
    public function testIterateArrayAndCheckKeysExistShouldNotMatchEmpty()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Key not found in array: "tasks"');

        $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'iterateArrayAndCheckKeysExist',
            [
                [],
                ['tasks', 'class'],
            ]
        );
    }

    /**
     * Should return the slots evenly distributed
     *
     * @throws Exception
     */
    public function testIterateArrayAndDistributeExecutionSlotsEqualCountAndPrioritySimple()
    {
        $taskClassesAndPriority = [
            [
                'class' => DependeeTask::class,
                'dbCount' => 7,
                'priority' => 70,
            ],
            [
                'class' => ThirtySecondsTask::class,
                'dbCount' => 2,
                'priority' => 20,
            ],
            [
                'class' => DependentTask::class,
                'dbCount' => 1,
                'priority' => 10,
            ],
        ];

        $maxExecutionSlots = 10;

        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'iterateArrayAndDistributeExecutionSlots',
            [
                $taskClassesAndPriority,
                $maxExecutionSlots
            ]
        );

        $this->assertEquals(0, $result[0]['dbCount']);
        $this->assertEquals(0, $result[0]['openExecutionSlots']);
        $this->assertEquals(7, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[1]['dbCount']);
        $this->assertEquals(0, $result[1]['openExecutionSlots']);
        $this->assertEquals(2, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[2]['dbCount']);
        $this->assertEquals(0, $result[2]['openExecutionSlots']);
        $this->assertEquals(1, $result[2]['grantedExecutionSlots']);
    }

    /**
     * Should return the slots evenly distributed
     *
     * @throws Exception
     */
    public function testIterateArrayAndDistributeExecutionSlotsTooManyDbCounts()
    {
        $taskClassesAndPriority = [
            [
                'class' => DependeeTask::class,
                'dbCount' => 7,
                'priority' => 70,
            ],
            [
                'class' => ThirtySecondsTask::class,
                'dbCount' => 2,
                'priority' => 20,
            ],
            [
                'class' => DependentTask::class,
                'dbCount' => 1,
                'priority' => 10,
            ],
        ];

        $maxExecutionSlots = 5;

        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'iterateArrayAndDistributeExecutionSlots',
            [
                $taskClassesAndPriority,
                $maxExecutionSlots
            ]
        );

        $this->assertEquals(3.5, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(0.5, $result[2]['grantedExecutionSlots']);
    }

    /**
     * Should return null when nothing to process
     *
     * @throws Exception
     */
    public function testGetTaskClassesAndPriorityNoTasks()
    {
        $this->truncate(['crystal_tasks']);

        $config = [
            'applicationPhpFile' => '/foo/bar.php',
            'maxExecutionSlots' => 1,
            'tasks' => [
                [
                    'class' => DependeeTask::class,
                    'priority' => 1
                ]
            ]
        ];

        $maxExecutionSlots = $config['maxExecutionSlots'];

        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy($config);
        $this->assertNull($divideTotalValueEquallyPriorityStrategy->getTaskClassesAndGrantedExecutionSlots($maxExecutionSlots));
    }

    /**
     * Should get one task
     *
     * @throws Exception
     */
    public function testGetTaskClassesAndPriorityOneTask()
    {
        $this->truncate(['crystal_tasks']);

        $dataNew = [
            'class' => 'Dependee',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask($dataNew);

        $config = [
            'maxExecutionSlots' => 10,
            'applicationPhpFile' => '/foo/bar.php',
            'tasks' => [
                [
                    'class' => 'Dependee',
                    'priority' => 1
                ]
            ]
        ];

        $maxExecutionSlots = $config['maxExecutionSlots'];

        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy($config);
        $taskClassesAndGrantedExecutionSlots = $divideTotalValueEquallyPriorityStrategy->getTaskClassesAndGrantedExecutionSlots($maxExecutionSlots);

        $crystalTasks = $this->_crystalTasksBaseService->getNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdate($taskClassesAndGrantedExecutionSlots);

        $this->assertArraySubset($dataNew, (array)$crystalTasks[0]);
        $this->assertCount(1, $crystalTasks);
    }

    /**
     * Should get multiple tasks, but only 3 and 2 of each
     *
     * @throws Exception
     */
    public function testGetTaskClassesAndPriorityMultipleTasks()
    {
        $this->truncate(['crystal_tasks']);

        $dataNew1 = [
            'class' => 'Dependee',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));

        $dataNew2 = [
            'class' => 'Dependent',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew2, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew2, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew2, ['entity_uid' => uniqid()]));

        $config = [
            'applicationPhpFile' => '/foo/bar.php',
            'maxExecutionSlots' => 5,
            'tasks' => [
                [
                    'class' => 'Dependee',
                    'priority' => 60
                ],
                [
                    'class' => 'Dependent',
                    'priority' => 40
                ]
            ]
        ];

        $maxExecutionSlots = $config['maxExecutionSlots'];

        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy($config);

        $taskClassesAndGrantedExecutionSlots = $divideTotalValueEquallyPriorityStrategy->getTaskClassesAndGrantedExecutionSlots($maxExecutionSlots);
        $crystalTasks = $this->_crystalTasksBaseService->getNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdate($taskClassesAndGrantedExecutionSlots);

        $this->assertEquals(1, $crystalTasks[0]->id);
        $this->assertEquals(2, $crystalTasks[1]->id);
        $this->assertEquals(3, $crystalTasks[2]->id);
        $this->assertEquals(6, $crystalTasks[3]->id);
        $this->assertEquals(7, $crystalTasks[4]->id);
        $this->assertCount(5, $crystalTasks);
    }

    /**
     * Should not starve a task
     *
     * @throws Exception
     */
    public function testGetTaskClassesAndPriorityMultipleTasksNoStarvation()
    {
        $this->truncate(['crystal_tasks']);

        $dataNew1 = [
            'class' => 'Dependee',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));

        $dataNew2 = [
            'class' => 'Dependent',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew2, ['entity_uid' => uniqid()]));

        $config = [
            'applicationPhpFile' => '/foo/bar.php',
            'maxExecutionSlots' => 2,
            'priorityStrategy' => DivideTotalValueEquallyPriorityStrategy::class,
            'tasks' => [
                [
                    'class' => 'Dependee',
                    'priority' => 99
                ],
                [
                    'class' => 'Dependent',
                    'priority' => 1
                ]
            ]
        ];

        $maxExecutionSlots = $config['maxExecutionSlots'];

        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy($config);

        $taskClassesAndGrantedExecutionSlots = $divideTotalValueEquallyPriorityStrategy->getTaskClassesAndGrantedExecutionSlots($maxExecutionSlots);
        $crystalTasks = $this->_crystalTasksBaseService->getNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdate($taskClassesAndGrantedExecutionSlots);

        $this->assertEquals('Dependee', $crystalTasks[0]->class);
        $this->assertEquals('Dependent', $crystalTasks[1]->class);
        $this->assertCount(2, $crystalTasks);
    }

    /**
     * Should never return grantedExecutionSlots > maxExecutionSlots
     *
     * @throws Exception
     */
    public function testGetTaskClassesAndPriorityShouldNeverExceedMaxExecutionSlots()
    {
        $this->truncate(['crystal_tasks']);

        $dataNew1 = [
            'class' => 'Dependee',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));

        $dataNew2 = [
            'class' => 'Dependent',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew2, ['entity_uid' => uniqid()]));

        $config = [
            'applicationPhpFile' => '/foo/bar.php',
            'maxExecutionSlots' => 2,
            'tasks' => [
                [
                    'class' => 'Dependee',
                    'priority' => 20
                ],
                [
                    'class' => 'Dependent',
                    'priority' => 40
                ]
            ]
        ];

        $maxExecutionSlots = $config['maxExecutionSlots'];

        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy($config);
        $result = $divideTotalValueEquallyPriorityStrategy->getTaskClassesAndGrantedExecutionSlots($maxExecutionSlots);

        $this->assertLessThanOrEqual($maxExecutionSlots, $result[0]['grantedExecutionSlots'] + $result[1]['grantedExecutionSlots']);
    }

    /**
     * Should never return grantedExecutionSlots with decimals
     *
     * @throws Exception
     */
    public function testGetTaskClassesAndPriorityWithUnequalNumberOfTasksAndPriority()
    {
        $this->truncate(['crystal_tasks']);

        $dataNew1 = [
            'class' => 'Dependee',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));

        $dataNew2 = [
            'class' => 'Dependent',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew2, ['entity_uid' => uniqid()]));

        $dataNew3 = [
            'class' => 'ThirtySeconds',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));
        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));

        $config = [
            'applicationPhpFile' => '/foo/bar.php',
            'maxExecutionSlots' => 11,
            'tasks' => [
                [
                    'class' => 'Dependee',
                    'priority' => 20
                ],
                [
                    'class' => 'ThirtySeconds',
                    'priority' => 40
                ],
                [
                    'class' => 'Dependent',
                    'priority' => 10,
                ]
            ]
        ];

        $maxExecutionSlots = $config['maxExecutionSlots'];

        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy($config);
        $result = $divideTotalValueEquallyPriorityStrategy->getTaskClassesAndGrantedExecutionSlots($maxExecutionSlots);

        $this->assertCount(3, $result);
        $this->assertEquals('ThirtySeconds', $result[0]['class']);
        $this->assertEquals(7, $result[0]['grantedExecutionSlots']);
        $this->assertEquals('Dependee', $result[1]['class']);
        $this->assertEquals(3, $result[1]['grantedExecutionSlots']);
        $this->assertEquals('Dependent', $result[2]['class']);
        $this->assertEquals(1, $result[2]['grantedExecutionSlots']);
    }

    /**
     * Should use the simply distribution with 2 availableExecutionSlot and 3 tasks
     *
     * @throws Exception
     */
    public function testGetTaskClassesAndPriorityShouldUseSimpleDistributionWith2AvailableExecutionSlots()
    {
        $this->truncate(['crystal_tasks']);

        $dataNew2 = [
            'class' => 'Dependent',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew2, ['entity_uid' => uniqid()]));

        $dataNew3 = [
            'class' => 'ThirtySeconds',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));

        $config = [
            'applicationPhpFile' => '/foo/bar.php',
            'maxExecutionSlots' => 3,
            'tasks' => [
                [
                    'class' => 'Dependee',
                    'priority' => 20
                ],
                [
                    'class' => 'ThirtySeconds',
                    'priority' => 40
                ],
                [
                    'class' => 'Dependent',
                    'priority' => 10,
                ]
            ]
        ];

        $availableExecutionSlots = 2;

        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy($config);
        $result = $divideTotalValueEquallyPriorityStrategy->getTaskClassesAndGrantedExecutionSlots($availableExecutionSlots);

        // Should assign to the highest, skip the second (no tasks in db), and assign to the lowest
        $this->assertCount(2, $result);
        $this->assertEquals('ThirtySeconds', $result[0]['class']);
        $this->assertEquals(1, $result[0]['grantedExecutionSlots']);
        $this->assertEquals('Dependent', $result[1]['class']);
        $this->assertEquals(1, $result[1]['grantedExecutionSlots']);
    }

    /**
     * Should use the simply distribution with 1 availableExecutionSlot and 3 tasks
     *
     * @throws Exception
     */
    public function testGetTaskClassesAndPriorityShouldUseSimpleDistributionWith1AvailableExecutionSlots()
    {
        $this->truncate(['crystal_tasks']);

        $dataNew1 = [
            'class' => 'Dependee',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));

        $dataNew2 = [
            'class' => 'Dependent',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew2, ['entity_uid' => uniqid()]));

        $dataNew3 = [
            'class' => 'ThirtySeconds',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew3, ['entity_uid' => uniqid()]));

        $config = [
            'applicationPhpFile' => '/foo/bar.php',
            'maxExecutionSlots' => 3,
            'tasks' => [
                [
                    'class' => 'Dependee',
                    'priority' => 20
                ],
                [
                    'class' => 'ThirtySeconds',
                    'priority' => 40
                ],
                [
                    'class' => 'Dependent',
                    'priority' => 10,
                ]
            ]
        ];

        $availableExecutionSlots = 1;

        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy($config);
        $result = $divideTotalValueEquallyPriorityStrategy->getTaskClassesAndGrantedExecutionSlots($availableExecutionSlots);

        // Should assign only to the highest priority
        $this->assertCount(1, $result);
        $this->assertEquals('ThirtySeconds', $result[0]['class']);
        $this->assertEquals(1, $result[0]['grantedExecutionSlots']);
    }


    /**
     * Should work with 0 availableExecutionSlots
     *
     * @throws Exception
     */
    public function testGetTaskClassesAndPriorityWithZeroAvailableExecutionSlots()
    {
        $config = [
            'applicationPhpFile' => '/foo/bar.php',
            'maxExecutionSlots' => 1,
            'tasks' => [
                [
                    'class' => 'Dependee',
                    'priority' => 99
                ],
            ]
        ];

        $maxExecutionSlots = 0;

        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy($config);
        $result = $divideTotalValueEquallyPriorityStrategy->getTaskClassesAndGrantedExecutionSlots($maxExecutionSlots);

        $this->assertNull($result);
    }

    /**
     * Should work with more slots than tasks
     *
     * @throws Exception
     */
    public function testGetTaskClassesAndPriorityMoreSlotsThanTasks()
    {
        $this->truncate(['crystal_tasks']);

        $dataNew1 = [
            'class' => 'Dependee',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew1, ['entity_uid' => uniqid()]));

        $dataNew2 = [
            'class' => 'Dependent',
            'timeout' => '60',
            'cooldown' => '1',
            'entity_uid' => uniqid(),
            'range' => '1',
            'date_start' => null,
            'date_end' => null,
            'state' => CrystalTask::STATE_CRYSTAL_TASK_NEW,
        ];

        $this->_fixtureHelper->setupCrystalTask(array_merge($dataNew2, ['entity_uid' => uniqid()]));

        $config = [
            'applicationPhpFile' => '/foo/bar.php',
            'maxExecutionSlots' => 5,
            'tasks' => [
                [
                    'class' => 'Dependee',
                    'priority' => 99
                ],
                [
                    'class' => 'Dependent',
                    'priority' => 1
                ]
            ]
        ];

        $maxExecutionSlots = $config['maxExecutionSlots'];

        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy($config);

        $taskClassesAndGrantedExecutionSlots = $divideTotalValueEquallyPriorityStrategy->getTaskClassesAndGrantedExecutionSlots($maxExecutionSlots);
        $crystalTasks = $this->_crystalTasksBaseService->getNextToBeExecutedCrystalTasksWithPriorityStrategyWithForUpdate($taskClassesAndGrantedExecutionSlots);

        $this->assertEquals('Dependee', $crystalTasks[0]->class);
        $this->assertEquals('Dependent', $crystalTasks[1]->class);
        $this->assertCount(2, $crystalTasks);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testGrandExecutionSlotsShouldWorkWithGrantedExecutionSlotsFloats()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'class' => 'Foo1',
                'priority' => 997,
                'dbCount' => 3,
                'openExecutionSlots' => 997/1000 * 5,
            ],
            [
                'class' => 'Foo2',
                'priority' => 2,
                'dbCount' => 3,
                'openExecutionSlots' => 2/1000 * 5
            ],
            [
                'class' => 'Foo3',
                'priority' => 1,
                'dbCount' => 3,
                'openExecutionSlots' => 1/1000 * 5
            ],
        ];

        $taskClassesAndPriority = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'grantExecutionSlots',
            [
                $taskClassesAndPriority,
            ]
        );

        $maxExecutionSlots = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'getSumOpenExecutionSlots',
            [
                $taskClassesAndPriority,
            ]
        );

        $taskClassesAndPriority = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'addOpenExecutionSlots',
            [
                $taskClassesAndPriority,
                $maxExecutionSlots
            ]
        );


        $taskClassesAndPriority = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'grantExecutionSlots',
            [
                $taskClassesAndPriority,
            ]
        );

        $result =   [
            0 => [
                "class" => "Foo1",
                "priority" => 997,
                "dbCount" => 0,
                "openExecutionSlots" => 1.985,
                "grantedExecutionSlots" => 3.0,
                "done" => true,
            ],
            1 => [
                "class" => "Foo2",
                "priority" => 2,
                "dbCount" => 1.6666666666667,
                "openExecutionSlots" => 0,
                "grantedExecutionSlots" => 1.3333333333333,
            ],
            2 => [
                "class" => "Foo3",
                "priority" => 1,
                "dbCount" => 2.3333333333333,
                "openExecutionSlots" => 0,
                "grantedExecutionSlots" => 0.66666666666667,
            ],
        ];

        $this->assertEquals($result, $taskClassesAndPriority);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testGetSumRemaindersGrantedExecutionSlotsWithRestZero()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();
        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 8.0,
            ]
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'getSumRemaindersGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
            ]
        );

        $this->assertEquals(0, $result);
    }

    /**
     * Should add the remainders and round down
     *
     * @throws ReflectionException
     * @throws Exception
     */
    public function testGetSumRemaindersGrantedExecutionSlotsWithMultiple()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 0.0,
            ],
            [
                'grantedExecutionSlots' => 48.9,
            ],
            [
                'grantedExecutionSlots' => 2.0,
            ],
            [
                'grantedExecutionSlots' => 1.3333333,
            ],
            [
                'grantedExecutionSlots' => 0.111111,
            ]
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'getSumRemaindersGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
            ]
        );

        $this->assertEquals(1, $result);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRoundGrantedExecutionSlotsWithLRHareMethodNoRoundingOne()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 1.0,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'roundGrantedExecutionSlotsWithLRHareMethod',
            [
                $taskClassesAndPriority,
            ]
        );

        $this->assertEquals(1, $result[0]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRoundGrantedExecutionSlotsWithLRHareMethodNoRoundingMultiple()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 1.0,
            ],
            [
                'grantedExecutionSlots' => 8.0,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'roundGrantedExecutionSlotsWithLRHareMethod',
            [
                $taskClassesAndPriority,
            ]
        );

        $this->assertEquals(1, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(8, $result[1]['grantedExecutionSlots']);
    }

    /**
     * Should round up because only the last 4 numbers are 9999
     *
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRoundGrantedExecutionSlotsWithLRHareMethodShouldRoundUp()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 1.999999999,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'roundGrantedExecutionSlotsWithLRHareMethod',
            [
                $taskClassesAndPriority,
            ]
        );

        $this->assertEquals(2, $result[0]['grantedExecutionSlots']);
    }

    /**
     * Should not round up because only the last 2 numbers are 99
     *
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRoundGrantedExecutionSlotsWithLRHareMethodShouldRoundDown()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 1.99,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'roundGrantedExecutionSlotsWithLRHareMethod',
            [
                $taskClassesAndPriority,
            ]
        );

        $this->assertEquals(1, $result[0]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRoundGrantedExecutionSlotsWithLRHareMethodWithRoundingMultiple()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 1.99,
            ],
            [
                'grantedExecutionSlots' => 1.99,
            ],
            [
                'grantedExecutionSlots' => 1.99,
            ],
            [
                'grantedExecutionSlots' => 1.99,
            ],
            [
                'grantedExecutionSlots' => 0.01,
            ],
            [
                'grantedExecutionSlots' => 0.02,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'roundGrantedExecutionSlotsWithLRHareMethod',
            [
                $taskClassesAndPriority,
            ]
        );

        $this->assertEquals(2, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(2, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(2, $result[2]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[3]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[4]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[5]['grantedExecutionSlots']);
    }

    /**
     * Should work with decimals that add up to 1 (0.33333)
     *
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRoundGrantedExecutionSlotsWithLRHareMethodWithRoundingMultiple2()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 10/3,
            ],
            [
                'grantedExecutionSlots' => 10/3,
            ],
            [
                'grantedExecutionSlots' => 10/3,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'roundGrantedExecutionSlotsWithLRHareMethod',
            [
                $taskClassesAndPriority,
            ]
        );

        $this->assertEquals(4, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(3, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(3, $result[2]['grantedExecutionSlots']);
    }

    /**
     * Should work with decimals that add up to 2 (0.6666)
     *
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRoundGrantedExecutionSlotsWithLRHareMethodWithRoundingMultiple3()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 20/3,
            ],
            [
                'grantedExecutionSlots' => 20/3,
            ],
            [
                'grantedExecutionSlots' => 20/3,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'roundGrantedExecutionSlotsWithLRHareMethod',
            [
                $taskClassesAndPriority,
            ]
        );

        $this->assertEquals(7, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(6, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(6, $result[2]['grantedExecutionSlots']);
    }

    /**
     * Should round when 4 numbers behind the comma
     *
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRoundGrantedExecutionSlotsWithLRHareMethodWithRoundingMultiple4()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 0.333333,
            ],
            [
                'grantedExecutionSlots' => 0.333333,
            ],
            [
                'grantedExecutionSlots' => 0.333333,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'roundGrantedExecutionSlotsWithLRHareMethod',
            [
                $taskClassesAndPriority,
            ]
        );

        $this->assertEquals(1, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[2]['grantedExecutionSlots']);
    }

    /**
     * Should not round when only 3 numbers behind the comma
     *
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRoundGrantedExecutionSlotsWithLRHareMethodWithRoundingMultiple5()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 0.333,
            ],
            [
                'grantedExecutionSlots' => 0.333333,
            ],
            [
                'grantedExecutionSlots' => 0.333333,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'roundGrantedExecutionSlotsWithLRHareMethod',
            [
                $taskClassesAndPriority,
            ]
        );

        $this->assertEquals(0, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[2]['grantedExecutionSlots']);
    }

    /**
     * Should not round when only 3 numbers behind the comma
     *
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRoundGrantedExecutionSlotsWithLRHareMethodWithRoundingMultiple6()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority =   [
            [
                "class" => 'Foo1',
                "grantedExecutionSlots" => 3.0,
            ],
            [
                "class" => 'Foo2',
                "grantedExecutionSlots" => 1.3333333333333,
            ],
            [
                "class" => 'Foo3',
                "grantedExecutionSlots" => 0.66666666666667,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'roundGrantedExecutionSlotsWithLRHareMethod',
            [
                $taskClassesAndPriority,
            ]
        );

        $this->assertEquals(1, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(3, $result[2]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRedistributeHighestGrantedExecutionSlotsShouldWorkWithNoZeros()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 1,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'redistributeHighestGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
                0
            ]
        );

        $this->assertEquals(1, $result[0]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRedistributeHighestGrantedExecutionSlotsShouldWorkWithNoZeros2()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 1,
            ],
            [
                'grantedExecutionSlots' => 7,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'redistributeHighestGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
                0
            ]
        );

        $this->assertEquals(7, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[1]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRedistributeHighestGrantedExecutionSlotsShouldWorkWithFloatAndInt()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 2.0
            ],
            [
                'grantedExecutionSlots' => 2
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'redistributeHighestGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
                1
            ]
        );

        $this->assertEquals(2, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[1]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRedistributeHighestGrantedExecutionSlotsShouldWorkWithMultiple()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 8,
            ],
            [
                'grantedExecutionSlots' => 6,
            ],
            [
                'grantedExecutionSlots' => 6,
            ],
            [
                'grantedExecutionSlots' => 6,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'redistributeHighestGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
                3
            ]
        );

        $this->assertEquals(6, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(6, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(6, $result[2]['grantedExecutionSlots']);
        $this->assertEquals(5, $result[3]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[4]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[5]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[6]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRedistributeHighestGrantedExecutionSlotsShouldWorkWithMultipleMore()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 8,
            ],
            [
                'grantedExecutionSlots' => 6,
            ],
            [
                'grantedExecutionSlots' => 6,
            ],
            [
                'grantedExecutionSlots' => 6,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'redistributeHighestGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
                7
            ]
        );

        $this->assertEquals(6, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(5, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(4, $result[2]['grantedExecutionSlots']);
        $this->assertEquals(4, $result[3]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[4]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[5]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[6]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[7]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[8]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[9]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[10]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRedistributeHighestGrantedExecutionSlotsShouldWorkWithMultiple2()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 7,
            ],
            [
                'grantedExecutionSlots' => 6,
            ],
            [
                'grantedExecutionSlots' => 6,
            ],
            [
                'grantedExecutionSlots' => 6,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'redistributeHighestGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
                5
            ]
        );

        $this->assertEquals(6, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(5, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(5, $result[2]['grantedExecutionSlots']);
        $this->assertEquals(4, $result[3]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[4]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[5]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[6]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[7]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[8]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRedistributeHighestGrantedExecutionSlotsShouldWorkWithOnlyTwoAndOne()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 2,
            ],
            [
                'grantedExecutionSlots' => 1,
            ],
            [
                'grantedExecutionSlots' => 1,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'redistributeHighestGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
                1
            ]
        );

        $this->assertEquals(1, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[2]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[3]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRedistributeHighestGrantedExecutionSlotsShouldWorkWithOnlyNineAndOne()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 9,
            ],
            [
                'grantedExecutionSlots' => 1,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'redistributeHighestGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
                2
            ]
        );

        $this->assertEquals(7, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[2]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[3]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRedistributeHighestGrantedExecutionSlotsShouldWorkWithOnlyOne()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 2,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'redistributeHighestGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
                1
            ]
        );

        $this->assertEquals(1, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[1]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRedistributeHighestGrantedExecutionSlotsShouldWorkWithTenWithRandomOrderWithInt()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 3,
            ],
            [
                'grantedExecutionSlots' => 2,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 1,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 4,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
            [
                'grantedExecutionSlots' => 0,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'redistributeHighestGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
                6
            ]
        );

        $this->assertEquals(1, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[2]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[3]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[4]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[5]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[6]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[7]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[8]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[9]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testRedistributeHighestGrantedExecutionSlotsShouldWorkWithTenWithRandomOrderWithFloats()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => 0.0,
            ],
            [
                'grantedExecutionSlots' => 3.0,
            ],
            [
                'grantedExecutionSlots' => 2.0,
            ],
            [
                'grantedExecutionSlots' => 0.0,
            ],
            [
                'grantedExecutionSlots' => 0.0,
            ],
            [
                'grantedExecutionSlots' => 1.0,
            ],
            [
                'grantedExecutionSlots' => 0.0,
            ],
            [
                'grantedExecutionSlots' => 4.0,
            ],
            [
                'grantedExecutionSlots' => 0.0,
            ],
            [
                'grantedExecutionSlots' => 0.0,
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'redistributeHighestGrantedExecutionSlots',
            [
                $taskClassesAndPriority,
                6
            ]
        );

        $this->assertEquals(1, $result[0]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[1]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[2]['grantedExecutionSlots']);
        $this->assertEquals(1, $result[3]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[4]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[5]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[6]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[7]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[8]['grantedExecutionSlots']);
        $this->assertEquals(0, $result[9]['grantedExecutionSlots']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function testSortByGrantedExecutionSlotsHighToLowWithFloatAndInt()
    {
        $divideTotalValueEquallyPriorityStrategy = $this->createDivideTotalValueEquallyPriorityStrategy();

        $taskClassesAndPriority = [
            [
                'grantedExecutionSlots' => '',
            ],
            [
                'grantedExecutionSlots' => 2
            ],
            [
                'grantedExecutionSlots' => 2.0
            ],
            [
                'grantedExecutionSlots' => 2.9999990
            ],
            [
                'grantedExecutionSlots' => 0.9999999
            ],
            [
                'grantedExecutionSlots' => null,
            ],
            [
                'grantedExecutionSlots' => [],
            ],
            [
                'grantedExecutionSlots' => 0.0000001
            ],
        ];

        $result = $this->invokeMethod(
            $divideTotalValueEquallyPriorityStrategy,
            'sortByGrantedExecutionSlotsHighToLow',
            [
                $taskClassesAndPriority,
                1
            ]
        );

        $this->assertSame([], $result[0]['grantedExecutionSlots']);
        $this->assertSame(2.9999990, $result[1]['grantedExecutionSlots']);
        $this->assertSame(2.0, $result[2]['grantedExecutionSlots']);
        $this->assertSame(2, $result[3]['grantedExecutionSlots']);
        $this->assertSame(0.9999999, $result[4]['grantedExecutionSlots']);
        $this->assertSame(0.0000001, $result[5]['grantedExecutionSlots']);
        $this->assertSame(null, $result[6]['grantedExecutionSlots']);
        $this->assertSame('', $result[7]['grantedExecutionSlots']);
    }


}
