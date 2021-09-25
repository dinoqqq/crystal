<?php

namespace Crystal\Executor;

use Crystal\Crystal;
use Crystal\Database\CrystalTasksTable;
use Crystal\Entity\CrystalTask;
use Crystal\Exception\CrystalTaskIncreaseErrorTriesException;
use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Service\CrystalTasksBaseService;
use Crystal\Service\CrystalTasksExecuteService;
use Crystal\StateChangeStrategy\StateChangeStrategyFactory;
use Crystal\Task\TaskInterface;
use Exception;

class Executor implements ExecutorInterface
{
    private $_task;
    private $_timeout;
    private $_cooldown;
    private $_crystalTaskId;
    private $_crystalTasksTable;
    private $_crystalTasksBaseService;
    private $_crystalTasksExecuteService;
    private $_stateChangeStrategyFactory;

    public function __construct(
        CrystalTasksBaseService $crystalTasksBaseService,
        CrystalTasksExecuteService $crystalTasksExecuteService,
        StateChangeStrategyFactory $stateChangeStrategyFactory,
        CrystalTasksTable $crystalTasksTable
    )
    {
        $this->_crystalTasksBaseService = $crystalTasksBaseService;
        $this->_crystalTasksExecuteService = $crystalTasksExecuteService;
        $this->_stateChangeStrategyFactory = $stateChangeStrategyFactory;
        $this->_crystalTasksTable = $crystalTasksTable;
    }

    /**
     * Execute a task that was requested
     *
     * @throws Exception
     */
    public function validatePrepareAndExecuteCrystalTask(
        TaskInterface $task,
        string $class,
        int $crystalTaskId,
        int $timeout,
        int $cooldown
    ): bool
    {
        try {
            $this->validateFirstTask();

            $this->_task = $task;
            $this->_timeout = $timeout;
            $this->_cooldown = $cooldown;

            $this->validateClassMatchesTaskClass($class, $task);

            $this->prepareExecuteCrystalTask();

            // Needed in shutdown/timeout functions
            $this->_crystalTaskId = $crystalTaskId;

            return $this->executeCrystalTask($crystalTaskId, $task);

        } catch (Exception $e) {
            Crystal::$logger->error('CRYSTAL-0011: ' . $e->getMessage(), [
                'data' => [
                    'class' => $class,
                    'crystalTaskId' => $crystalTaskId,
                    'timeout' => $timeout,
                    'cooldown' => $cooldown,
                ],
                'task' => $task,
            ]);
        }
        return false;
    }

    /**
     * @throws Exception
     */
    private function validateFirstTask()
    {
        if ($this->_task !== null) {
            throw new Exception('Trying to executeCrystalTask for the second time, weird');
        }
    }

    /**
     * @throws Exception
     */
    private function validateClassMatchesTaskClass($class, TaskInterface $task): void
    {
        if ($class !== get_class($task)) {
            throw new CrystalTaskStateErrorException('Request input class and task class do not match, weird');
        }
    }

    /**
     * Setup the shutdown function and the wall time
     */
    private function prepareExecuteCrystalTask(): void
    {
        // We register this as first, so we can abort all other register_shutdown_function calls
        // The script needs a hard timeout, and a shutdown function might jeopardize this.
        register_shutdown_function([$this, "crystalTaskExecuteShutdown"]);

        $this->setExecuteWallTimeTimeout();
    }

    /**
     * @throws Exception
     */
    public function executeCrystalTask(int $crystalTaskId, TaskInterface $task): bool
    {
        $crystalTask = null;
        try {
            $crystalTask = $this->_crystalTasksTable->getByPK($crystalTaskId);
            if (!$crystalTask instanceof CrystalTask) {
                throw new Exception('Could not get CrystalTask with id: "' . $crystalTaskId . '"');
            }

            // Second check if the class matches
            $this->validateClassMatchesTaskClass($crystalTask->class, $task);

            // CrystalTask should have state RUNNING, else something is wrong
            if (!$crystalTask->isStateCrystalTaskRunning()) {
                throw new Exception('Trying to executeCrystalTask with no RUNNING state, weird');
            }

            $task->setCrystalTask($crystalTask);

            $executed = $task->execute();

            $stateFrom = CrystalTask::STATE_CRYSTAL_TASK_RUNNING;
            $stateTo = CrystalTask::STATE_CRYSTAL_TASK_NOT_COMPLETED;

            // If executed === true, we might need to set the state to COMPLETED
            if ($executed) {
                $stateTo = $this->_crystalTasksExecuteService->getCrystalTaskStateAfterExecution($crystalTask);
            }

            $stateChangeStrategy = $this->_stateChangeStrategyFactory->create(
                $stateFrom,
                $stateTo
            );

            $success = $this->_crystalTasksBaseService->saveStateChange($crystalTask, $stateChangeStrategy);
            if (!$success) {
                throw new Exception('Could not save state change from "' . $stateFrom . '" to "' . $stateTo . '" after execution, weird');
            }
        } catch (CrystalTaskStateErrorException $e) {
            $this->_crystalTasksBaseService->saveAndSetErrorState($crystalTask);

            Crystal::$logger->error('CRYSTAL-0012: CrystalTaskErrorException, setting state to ERROR', [
                'crystalTask' => $crystalTask ?? [],
                'errorMessage' => $e->getMessage(),
            ]);
            return false;
        } catch (CrystalTaskIncreaseErrorTriesException $e) {
            $this->_crystalTasksBaseService->saveCrystalTaskAndIncreaseErrorTries($crystalTask);

            Crystal::$logger->error('CRYSTAL-0013: CrystalTaskIncreaseErrorTriesException, increasing errorTries', [
                'crystalTask' => $crystalTask ?? [],
                'errorMessage' => $e->getMessage(),
            ]);
            return false;
        } catch (Exception $e) {
            if ($crystalTask) {
                $this->_crystalTasksBaseService->saveCrystalTaskAndIncreaseErrorTries($crystalTask);
            }

            Crystal::$logger->error('CRYSTAL-0014: Could not execute CrystalTask', [
                'crystalTask' => $crystalTask ?? [],
                'errorMessage' => $e->getMessage(),
            ]);
            return false;
        }

        return $executed;
    }


    /**
     * Note: When exit is called, no other call to register_shutdown_function will be executed.
     *
     * If this function is called, it means someone called exit() in the script, which is used to signal a successful stop.
     * But we are not COMPLETED, else exit never would've been called. So as a small optimization, already try to set the status to
     * NOT_COMPLETED. If not, no worries, just wait out the timeout+cooldown and will be rescheduled.
     *
     * Shutdown function
     */
    public function crystalTaskExecuteShutdown()
    {
        try {
            $crystalTask = $this->_crystalTasksTable->getByPK($this->_crystalTaskId);
            if (!$crystalTask instanceof CrystalTask) {
                throw new Exception();
            }

            $crystalTask->stateRunningToNotCompleted();
            $this->_crystalTasksBaseService->saveWithoutTransaction($crystalTask);
        } catch (Exception $e) {
            // no worries
        }

        // Hurray! Graceful exit!
        exit(0);
    }

    /**
     * Timeout function
     *
     * @throws Exception
     */
    public function crystalTaskExecuteTimeout(): void
    {
        $this->_task->setTimeoutReached(true);

        if ($this->_cooldown > 0) {
            $this->setExecuteWallTimeCooldown();
            return;
        }

        $this->crystalTaskExecuteWriteError();

        exit();
    }

    /**
     * Cooldown function
     *
     * @throws Exception
     */
    public function crystalTaskExecuteCooldown()
    {
        $this->crystalTaskExecuteWriteError();

        exit();
    }

    /**
     * @throws Exception
     */
    private function crystalTaskExecuteWriteError(): void
    {
        throw new Exception('CRYSTAL-0022: timeout + cooldown reached for crystalTask with id "'
            . $this->_crystalTaskId . '", this should never happen, investigate');
    }

    /**
     * Set the wall time of this process
     *
     * Note: contrary to max_execution_time, this will also take into account DB calls/HTTP request etc.
     */
    private function setExecuteWallTimeTimeout(): void
    {
        // Also set the time limit to something higher than the timeout, so it will never interfere.
        set_time_limit(($this->_timeout+$this->_cooldown+1));

        // This will make sure this is always called async
        pcntl_async_signals(1);
        pcntl_signal(SIGALRM, [$this, "crystalTaskExecuteTimeout"]);
        pcntl_alarm($this->_timeout);
    }

    /**
     * Set the wall time of this process
     *
     * Note: contrary to max_execution_time, this will also take into account DB calls/HTTP request etc.
     */
    private function setExecuteWallTimeCooldown(): void
    {
        // This will make sure this is always called async
        pcntl_async_signals(1);
        pcntl_signal(SIGALRM, [$this, "crystalTaskExecuteCooldown"]);
        pcntl_alarm($this->_cooldown);
    }


}