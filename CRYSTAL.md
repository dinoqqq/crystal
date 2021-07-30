# Crystal

Crystal stores its tasks into a database. It requires 2 tables to be created by the user: `crystal_tasks` and `crystal_tasks_dependencies`. The schema can be found in `/miration/schema.sql`.

## The Crystal Heartbeat Processes

    ┌─────────────┐    ┌─────────────┐    ┌─────────────┐
    │             │    │             │    │             │
    │    QUEUE    ├───►│   EXECUTE   ├───►│  RESCHEDULE │
    │             │    │             │    │             │
    └─────────────┘    └─────────────┘    └─────────────┘

There are 3 core processes that handle all tasks: QUEUE, EXECUTE, RESCHEDULE. They are called the heartbeat processes. They trigger all the other parts of the system and should always be started and restarted.

To start and keep those processes running it is advised to create cron entries. Those cron entries should trigger a point in the user's application where the heartbeat processes are started manually (explained at [Controller](#the-controller))

### Cron Entries

Cron entries could look like this:

```
* * * * * user php -f /app/index.php crystalheartbeat  --type='queue' >> /var/log/cron.log
* * * * * user php -f /app/index.php crystalheartbeat  --type='execute' >> /var/log/cron.log
* * * * * user php -f /app/index.php crystalheartbeat  --type='reschedule' >> /var/log/cron.log
```

### QUEUE

This heartbeat process is responsible for queueing tasks into the `crystal_tasks` table. A task needs to be supplied to the QUEUE process, who then inserts it into the `crystal_tasks` table.

### EXECUTE

This heartbeat process is responsible for picking up a task from the `crystal_tasks` table, spawn a child process and execute the task inside of that child process. The child process will also update the state of the task. The parent doesn't listen to signals of the child anymore after spawning (fire-and-forget).

### RESCHEDULE

This heartbeat process is responsible for picking up "dead" or "not_completed" tasks from the `crystal_tasks` table, and change the state to "new" again.

### The Controller

The cronjob entries execute a PHP file inside your application with some parameters. Inside your webapp's controller, you need to start the Heartbeat process. Here is an example of how that could look like:

```
use Crystal\Crystal;
use SomeLogger;

class Controller 
{
    public function crystalHeartbeatAction()
    {
        $request = $this->getRequest();
        
        $queuer = new Queuer($this->_config, $this->_taskFactory);
        $logger = new SomeLogger();
        
        $crystal  = new Crystal(
            $this->_config,
            $logger
        );

        $type = $request->getParam('type');
        switch ($type) {
            case 'queue':
                $queueHeartbeat = $crystal->getQueueHeartbeat($this->_queuer);
                $queueHeartbeat->heartbeat();
                break;
            case 'execute':
                $executeHeartbeat = $crystal->getExecuteHeartbeat();
                $executeHeartbeat->heartbeat();
                break;
            case 'reschedule':
                $rescheduleHeartbeat = $crystal->getRescheduleHeartbeat();
                $rescheduleHeartbeat->heartbeat();
                break;
            default:
                throw new \Exception('Type of crystalHeartbeat not recognized');
        }

        exit(0);
    }
```

To use Crystal, include the package and create an instance. The first parameter should be the [config](#config) and the second can optionally be a [PSR3 compliant LoggerInterface](https://www.php-fig.org/psr/psr-3/).

A Queuer class and a TaskFactory class also need to be created.

### The Queuer Class

The QueueHeartbeat process needs to know which tasks it needs to add to the `crystal_tasks` table. Therefore, a class with a QueuerInterface needs to be implemented where a user's scheduler provides the QueueHeartbeat with tasks to schedule.

Here is an example:

```
use Crystal\Config\Config;
use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Heartbeat\QueuerInterface;
use Crystal\MainProcess\MainProcess;
use Crystal\Task\TaskFactoryInterface;
use Exception;

class Queuer implements QueuerInterface
{
    private $_config;
    private $_taskFactory;

    public function __construct(
        Config $config,
        TaskFactoryInterface $taskFactory
    )
    {
        $this->_config = $config;
        $this->_taskFactory = $taskFactory;
    }

    /**
     * @throws Exception
     */
    public function getNextMainProcesses(): array
    {
        // My own scheduler; maybe fetches the process names that need to be queued
        $someProcesses = $someScheduler->getProcessesThatNeedToBeQueued();
        
        $mainProcesses = [];
        foreach ($someProcesses as $someProcess) {
            // Create a Crystal mainProcess 
            $mainProcesses[] = MainProcess::create($this->_config, $this->_taskFactory, $someProcess->name, $someProcess);
        }
        
        return $mainProcesses;
    }

    /**
     * @throws Exception
     */
    public function queueingStart(MainProcess $mainProcess): bool
    {
        // queueing process has started
    }

    /**
     * @throws Exception
     */
    public function queueingStop(MainProcess $mainProcess): bool
    {
        // queueing process is stopped (successfully)
    }

    /**
     * @throws Exception
     */
    public function queueingFailed(MainProcess $mainProcess, Exception $e): void
    {
        // something went wrong while queueing
        
        $data = $mainProcess->getData();
        if ($e instanceof CrystalTaskStateErrorException) {
            // do some logging
            return;
        }

        if ($e instanceof Exception) {
            // do some logging
            return;
        }
    }
```

To convert a user's process to a Crystal mainProcess, the function `MainProcess::create()` can be be used.

The QUEUE process will call the function `getNextMainProcesses()` on every heartbeat and expects an array of `MainProcess` classes back.

The other 3 functions are callbacks to notify the user about the status of the queueing.

### The TaskFactory class

All user's tasks should be defined in a taskFactory. The taskFactory is an implementation of a TaskFactoryInterface.

Here is an example:

```
use Crystal\Task\TaskFactoryInterface;
use Crystal\Task\TaskInterface;
use Exception;

class TaskFactory implements TaskFactoryInterface 
{
    /**
     * @throws Exception
     */
    public function create(string $className): TaskInterface
    {
        return new $className;
    }
}
```


## Tasks

A task is one unit of work that will be handled by Crystal. All tasks are stored in a `crystal_tasks` table.

### States

All tasks have states. The following finite state diagram shows which process handles which state change.

```
            QUEUE()
            ┌─────┐
            │     │ 
        ┌───▼─────────┐            ┌─────────────┐    ┌─────────────┐
QUEUE() │             │  EXECUTE() │             │TIME│(DB: RUNNING)│              
───────►│     NEW     ├───────────►│   RUNNING   ├───►│    DEAD     ├──────┐       
        │             │            │             │    │             │      │      
        └───────▲───▲─┘            └──┬──────────┘    └─────────────┘      │     
                │   │                 │                                    │    
                │   │                 │                                    │    
                │   │                 │               ┌─────────────┐      │
                │   │                 │   EXECUTE()   │             │      │
                │   │                 └──────────────►│NOT_COMPLETED├──────┐ 
                │   │                 │               │             │      │
                │   │                 │               └─────────────┘      │
                │   │                 │                                    │
                │   │                 │               ┌─────────────┐      │
                │   │                 │   EXECUTE()   │             │      │
                │   │                 └──────────────►│    ERROR    ├───┐  │
                │   │                 │               │             │   │  │
                │   │                 │               └─────────────┘   │  │
                │   │                 │                                 │  │
                │   │                 │               ┌─────────────┐   │  │
                │   │                 │   EXECUTE()   │             │   │  │
                │   │                 └──────────────►│  COMPLETED  │───┐  │  
                │   │                                 │             │   │  │  
                │   │                                 └─────────────┘   │  │  
                │   │           QUEUE()                                 │  │  
                │   └───────────────────────────────────────────────────┘  │  
                │             RESCHEDULE()                                 │  
                └──────────────────────────────────────────────────────────┘  

```


Design principles:

- A task can only have 1 of the states in the diagram.
- Ideally only 1 process should take care of a state change (which doesn't happen from "new" to "new" (QUEUE) and "new" to "running" (EXECUTE), but any problems here are mitigated with database locking).

The "dead" state:

- All tasks have a certain timeout + cooldown time wihch is a period that they are allowed to run. If in that time the state isn't changed from "running" to either "completed", "not_completed" or "error" and the task runs out of time the state is considered "dead".
- Only the "dead" state is taken automatically by the passing of time.
- The "dead" state is virtual (only exists in code), the database state will still be "running".

Rules of queueing:
- If a task needs to be QUEUED, but is "dead" or "not_completed", the queueing will fail. First it needs to be RESCHEDULED and then it can be QUEUED again.
- If a task needs to be QUEUED, but is "running":
    - If a task DOES NOT "depend_on" another task, the QUEUE process will fail
    - If a task DOES "depend_on" another task, the QUEUE process will skip only that task. The reason is that the task needs to wait for another task to finish anyways, so it will always run again, only after the dependee task is finished.


## Define the config

A config needs to be provided when starting Crystal. The config should be a PHP array. Here is an example config:

```
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

[
    'phpExecutable' => 'php',
    'applicationPhpFile' => '/app/index.php',
    'maxExecutionSlots' => 10,
    'sleepTimeSeconds' => 5,
    'runTimeSeconds' => 60,
    'maxErrorTries' => 5,
    'priorityStrategy' => DivideTotalValueEquallyPriorityStrategy::class,
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

```

| Key                   | Description     |
| :-------------        | :---------- |
| phpExecutable         | The location of the php executable file.
| applicationPhpFile    | The location of the php file that is served by your application.
| maxExecutionSlots     | Total number of tasks to be executed simultaneously.
| sleepTimeSeconds      | Heartbeat tempo, of queueing/executing/rescheduling new tasks.
| runTimeSeconds        | Heartbeat total running time, of queueing/executing/rescheduling new tasks. This should be the same as your cronjob timing (60 when the cronjob runs every minute).
| maxErrorTries         | After this number of error tries we set the state to "error", no further processing.
| prioritizeStrategy    | The strategy to use of how to prioritize the tasks. Currently there are 2 options: **SortByDateCreatedPriorityStrategy**: will fetch all tasks by earliest date_created. **DivideTotalValueEquallyPriorityStrategy**: will fetch all tasks prioritized by a "priority" key set on the task. This strategy will also avoid starvation.

### MainProcesses

A mainProcess is a set of tasks that need to be executed. A mainProcess consists of multiple tasks, that can have dependencies between each other. It can also simply be one task.


Example:

```
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
        ...
```

#### Dependencies between tasks in a mainProcess

If a task should always run after another one, the key "dependOn" can be set for the task that needs to finish first. In this case, the DependeeTask will always run again, after the DependeeTask has state "completed".

### Tasks

Tasks are defined separately with all their options. This way they can be re-used in multiple mainProcesses.

```
    'tasks' => [
        'task1' => [
            'class' => DependentTask::class,
            'timeout' => 5,
            'cooldown' => 5,
            'resources' => 4,
            'entityUid' => 'some.id',
            'rangeStrategy' => UniqueIdRangeStrategy::class,
            'priority' => 20,
        ],
        ...
```

| Key                   | Description     |
| :-------------        | :---------- |
| class                 | The FQDN of the class name of the task
| timeout               | The time in seconds the task allowed to run before the cooldown period starts. Set this to how long you want your task to be running (more or less).
| cooldown              | In the cooldown period, the task can gracefully exit. Set this to the *minimal overall time* a task can run.
| resources             | Only needed for HashRangeStrategy. Split this task up into x number of tasks, where each task will process a unique part of it.
| entityUid             | Only needed for HashRangeStrategy. Entity unique ID, is a table name and column where a unique identifiable value is stored. Example: "customers.id".
| rangeStrategy         | The strategy to determine which unique entities this task should process. HashRangeStrategy: will take the table name + column out of the "entityUid" option, hash that value to base16 and divide that by the "resources" given to split the task into multiple tasks. UniqueIdRangeStrategy: will just process 1 task and not split anything up.
| priority              | Only needed for DivideTotalValueEquallyPriorityStrategy. This is mandatory. The higher the priority, the more of these tasks will be processed simultaneously.


### Timeout + cooldown (gracefully exit)

When the timeout of a task is reached, the function `$task->isTimeoutReached()` will return true. This can be used together with the cooldown period to gracefully exit a loop or the task.

Example of how to create a gracefully exit script.

```
foreach ($transactions as $transaction) {
    if ($task->isTimeoutReached()) {
        // do some stuff before exitting
        exit();
    }
    
    $data = $transaction->fetchData();
    $this->processData($data);
}

```

Calling `exit()` while executing a task will stop the execution and set the state to "not_completed".

When the timeout + cooldown both run out and `exit()` hasn't been called, an error is written to the logger and the state will be "not_completed".

Configure this task's cooldown option to the maximum time the functions `fetchData` and `processData` can run.