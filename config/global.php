<?php

use Crystal\PriorityStrategy\DivideTotalValueEquallyPriorityStrategy;
use Crystal\RangeStrategy\HashRangeStrategy;
use Crystal\RangeStrategy\UniqueIdRangeStrategy;
use Crystal\Test\Mock\Task\DependeeForeverRunningTask;
use Crystal\Test\Mock\Task\DependeeTask;
use Crystal\Test\Mock\Task\DependentNeverCompletedTask;
use Crystal\Test\Mock\Task\DependentTask;
use Crystal\Test\Mock\Task\ErrorTask;
use Crystal\Test\Mock\Task\NotCompletedTask;
use Crystal\Test\Mock\Task\SuccessTask;
use Crystal\Test\Mock\Task\ThirtySecondsTask;

return [
    // Total number of tasks to be executed
    'applicationPhpFile' => '/foo/bar.php',
    // Total number of tasks to be executed
    'maxExecutionSlots' => 10,
    // Heartbeat tempo
    'sleepTimeSeconds' => 5,
    // Runtime of heartbeat process
    'runTimeSeconds' => 60,
    // After this number of error tries we set the state to ERROR, no further processing
    'maxErrorTries' => 5,
    // Prioritize equally
    'priorityStrategy' => DivideTotalValueEquallyPriorityStrategy::class,
    'enable' => true,
    'mainProcesses' => [
        [
            'name' => 'DependentOneTask',
            'tasks' => [
                [
                    'class' => DependeeTask::class,
                    'dependOn' => DependeeTask::class,
                ],
            ]
        ],
        [
            'name' => 'DependentForeverRunningTask',
            'tasks' => [
                [
                    'class' => DependentNeverCompletedTask::class,
                    'dependOn' => DependeeForeverRunningTask::class,
                ]
            ]
        ],
        [
            'name' => 'SuccessAndNotCompletedTask',
            'tasks' => [
                [
                    'class' => SuccessTask::class,
                ],
                [
                    'class' => NotCompletedTask::class,
                ],
            ]
        ],
        [
            'name' => 'ErrorAndThirtySecondsTask',
            'tasks' => [
                [
                    'class' => ErrorTask::class,
                ],
                [
                    'class' => ThirtySecondsTask::class,
                ],
            ]
        ],
    ],
    'tasks' => [
        [
            'class' => DependentTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 20,
        ],
        [
            'class' => DependeeTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'resources' => 1,
            'entityUid' => 'some.hash',
            'rangeStrategy' => HashRangeStrategy::class,
            'priority' => 20,
        ],
        [
            'class' => DependentNeverCompletedTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'resources' => 1,
            'entityUid' => 'some.hash',
            'rangeStrategy' => HashRangeStrategy::class,
            'priority' => 20,
        ],
        [
            'class' => DependeeForeverRunningTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'resources' => 1,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 20,
        ],
        [
            'class' => ThirtySecondsTask::class,
            'timeout' => 60,
            'cooldown' => 5,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 3000,
        ],
        [
            'class' => SuccessTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 10,
        ],
        [
            'class' => ErrorTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 10,
        ],
        [
            'class' => NotCompletedTask::class,
            'timeout' => 60,
            'cooldown' => 5,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 10,
        ],
    ]
];