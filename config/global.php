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
    // The php executable file
    'phpExecutable' => 'php',
    // The php executable file parameters
    'phpExecutableParameters' => '-f',
    // The php file, that is gonna run the task
    'applicationPhpFile' => '/foo/bar.php',
    // The php file parameters
    'applicationPhpFileParameters' => [
        'crystaltaskexecute',
    ],
    // Total number of tasks to be executed simultaneously
    'maxExecutionSlots' => 10,
    // Heartbeat tempo, of queueing/executing/rescheduling new tasks
    'sleepTimeSeconds' => 5,
    // Heartbeat running time, of queueing/executing/rescheduling new tasks
    'runTimeSeconds' => 60,
    // After this number of error tries we set the state to ERROR, no further processing
    'maxErrorTries' => 5,
    // Prioritize equally
    'priorityStrategy' => DivideTotalValueEquallyPriorityStrategy::class,
    // A process consists of multiple tasks and tasks can have dependencies
    'mainProcesses' => [
        'mainProcess1' => [
            'name' => 'DependentOneTask',
            'tasks' => [
                [
                    'class' => DependeeTask::class,
                    'dependOn' => DependeeTask::class,
                ],
            ]
        ],
        'mainProcess2' => [
            'name' => 'DependentForeverRunningTask',
            'tasks' => [
                [
                    'class' => DependentNeverCompletedTask::class,
                    'dependOn' => DependeeForeverRunningTask::class,
                ]
            ]
        ],
        'mainProcess3' => [
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
        'mainProcess4' => [
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
    // Here we configure the general settings for a tasks
    'tasks' => [
        'task1' => [
            'class' => DependentTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 20,
        ],
        'task2' => [
            'class' => DependeeTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'resources' => 1,
            'entityUid' => 'some.hash',
            'rangeStrategy' => HashRangeStrategy::class,
            'priority' => 20,
        ],
        'task3' => [
            'class' => DependentNeverCompletedTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'resources' => 1,
            'entityUid' => 'some.hash',
            'rangeStrategy' => HashRangeStrategy::class,
            'priority' => 20,
        ],
        'task4' => [
            'class' => DependeeForeverRunningTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'resources' => 1,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 20,
        ],
        'task5' => [
            'class' => ThirtySecondsTask::class,
            'timeout' => 60,
            'cooldown' => 5,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 3000,
        ],
        'task6' => [
            'class' => SuccessTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 10,
        ],
        'task7' => [
            'class' => ErrorTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 10,
        ],
        'task8' => [
            'class' => NotCompletedTask::class,
            'timeout' => 60,
            'cooldown' => 5,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 10,
        ],
    ]
];
