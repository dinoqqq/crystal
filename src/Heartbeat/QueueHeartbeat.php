<?php

namespace Crystal\Heartbeat;

use Crystal\Crystal;
use Exception;

use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Config\Config;
use Crystal\Service\CrystalTasksBaseService;
use Crystal\Service\CrystalTasksQueueService;
use Crystal\MainProcess\MainProcessInterface;
use Crystal\Task\TaskInterface;

class QueueHeartbeat implements HeartbeatInterface {

    private $_config;
    private $_queuer;
    private $_crystalTasksBaseService;
    private $_crystalTasksQueueService;

    /**
     * @throws Exception
     */
    public function __construct(
        Config $config,
        CrystalTasksBaseService $crystalTasksBaseService,
        CrystalTasksQueueService $crystalTasksQueueService,
        QueuerInterface $queuer
    )
    {
        $this->_config = $config;
        $this->_crystalTasksBaseService = $crystalTasksBaseService;
        $this->_crystalTasksQueueService = $crystalTasksQueueService;
        $this->_queuer = $queuer;
    }

    public function heartbeat(): bool
    {
        try {
            $startTime = microtime(true);
            $microSleepTimeSeconds = $this->_config->getConfigByKey('sleepTimeSeconds') * 1000000;
            $iterations = $this->_config->getConfigByKey('runTimeSeconds') / $this->_config->getConfigByKey('sleepTimeSeconds');

            // Only on startup, update all dependencies
            $this->updateDependencies();

            for ($i = 0; $i < $iterations; $i++) {
                $this->queueMainProcesses();
                usleep($microSleepTimeSeconds);

                $endTime = microtime(true);
                if (($endTime - $startTime) >= $this->_config->getConfigByKey('runTimeSeconds')) {
                    // When here, we are > our runTimeSeconds, so abort
                    return true;
                }
            }
        } catch (Exception $e) {
            Crystal::$logger->error('CRYSTAL-0001: QueueHeartbeat heartbeat failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * @throws Exception
     */
    public function updateDependencies(): void
    {
        $crystalTaskDependencies = $this->_crystalTasksBaseService->dependenciesConfigToCrystalTasksDependencies($this->_config->getDependencies());
        $this->_crystalTasksBaseService->updateCrystalTasksDependencies($crystalTaskDependencies);
    }

    /**
     * @throws Exception
     */
    public function queueMainProcesses(): void
    {
        $mainProcesses = $this->_queuer->getNextMainProcesses();

        foreach ($mainProcesses as $mainProcess) {
            if (!$mainProcess instanceof MainProcessInterface) {
                $e = new Exception('getNextMainProcesses needs to return an array of MainProcessInterfaces');
                $this->_queuer->queueingFailed($mainProcess, $e);
                continue;
            }

            $this->queueMainProcessTasks($mainProcess);
        }
    }

    /**
     * @throws CrystalTaskStateErrorException
     * @throws Exception
     */
    private function queueMainProcessTasks(MainProcessInterface $mainProcess): void
    {
        $success = $this->_queuer->queueingStart($mainProcess);
        if (!$success) {
            return;
        }

        try {
            $queue = $this->createTaskQueue($mainProcess);
        } catch (CrystalTaskStateErrorException $e) {
            // When something critical happens simply stop processing the task and set to NOT COMPLETED.
            // Communicate a normal exception, so the error tries is increased.
            // Will be cleaned up after 5 times.
            $e2 = new Exception($e->getMessage());
            $this->_queuer->queueingFailed($mainProcess, $e2);
            return;
        } catch (Exception $e) {
            $this->_queuer->queueingFailed($mainProcess, $e);
            return;
        }

        // Saving the queue in 1 transaction
        try {
            $this->_crystalTasksQueueService->saveQueue($queue);
        } catch (CrystalTaskStateErrorException $e) {
            // This means the state changed already (it was picked up by EXECUTE), just continue without setting error
            $this->_queuer->queueingFailed($mainProcess, $e);
            return;
        } catch (Exception $e) {
            $this->_queuer->queueingFailed($mainProcess, $e);
            return;
        }

        $this->_queuer->queueingStop($mainProcess);
    }

    /**
     * Queue all tasks in an array
     *
     * @throws Exception
     */
    private function createTaskQueue(MainProcessInterface $mainProcess): array
    {
        $queue = [];
        /** @var TaskInterface $task */
        foreach ($mainProcess->getTasks() as $task) {
            $queue = array_merge($queue, $task->queue());
        }
        return $queue;
    }
}