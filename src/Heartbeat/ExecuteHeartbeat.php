<?php

namespace Crystal\Heartbeat;

use Crystal\Crystal;
use Exception;
use Crystal\Entity\CrystalTask;
use Crystal\Service\CrystalTasksExecuteService;
use Crystal\Service\CrystalTasksBaseService;
use Crystal\Config\Config;
use Crystal\PriorityStrategy\PriorityStrategyInterface;
use Crystal\PriorityStrategy\SortByDateCreatedPriorityStrategy;
use Crystal\PriorityStrategy\PriorityStrategyFactory;

class ExecuteHeartbeat implements HeartbeatInterface
{
    private $_config;
    private $_crystalTasksBaseService;
    private $_crystalTasksExecuteService;
    private $_priorityStrategyFactory;

    /**
     * @throws Exception
     */
    public function __construct(
        Config $config,
        CrystalTasksBaseService $crystalTasksBaseService,
        CrystalTasksExecuteService $crystalTasksExecuteService,
        PriorityStrategyFactory $priorityStrategyFactory
    )
    {
        $this->_config = $config;
        $this->_crystalTasksBaseService = $crystalTasksBaseService;
        $this->_crystalTasksExecuteService = $crystalTasksExecuteService;
        $this->_priorityStrategyFactory = $priorityStrategyFactory;
    }

    public function heartbeat(): bool
    {
        try {
            $startTime = microtime(true);
            $microSleepTimeSeconds = $this->_config->getConfigByKey('sleepTimeSeconds') * 1000000;
            $iterations = $this->_config->getConfigByKey('runTimeSeconds') / $this->_config->getConfigByKey('sleepTimeSeconds');

            for ($i = 0; $i < $iterations; $i++) {
                $this->processCrystalTasks();

                usleep($microSleepTimeSeconds);
                $endTime = microtime(true);
                if (($endTime - $startTime) >= $this->_config->getConfigByKey('runTimeSeconds')) {
                    // When here, we are > our runTimeSeconds, so abort
                    return true;
                }
            }
        } catch (Exception $e) {
            Crystal::$logger->error('CRYSTAL-0006: ExecuteHeartbeat heartbeat failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * @throws Exception
     */
    private function getPriorityStrategy(?string $priorityStrategyClassName): PriorityStrategyInterface
    {
        return $this->_priorityStrategyFactory->create(
            $priorityStrategyClassName
        );
    }

    /**
     * @throws Exception
     */
    public function processCrystalTasks(): bool
    {
        $availableExecutionSlots = $this->_crystalTasksBaseService->countAvailableExecutionSlots();
        $crystalTasks = $this->getNextToBeExecutedCrystalTasksByPriorityStrategy($availableExecutionSlots);

        $this->checkNextToBeExecutedCrystalTasksDoNotExceedMaxExecutionSlots($crystalTasks);

        return $this->spawnCrystalTasks($crystalTasks);
    }

    /**
     * @throws Exception
     */
    public function getNextToBeExecutedCrystalTasksByPriorityStrategy(int $availableExecutionSlots): array
    {
        try {
            $priorityStrategyClassName = $this->_config->getConfigByKey('priorityStrategy');
            $priorityStrategy = $this->getPriorityStrategy($priorityStrategyClassName);
            $crystalTasks = $this->_crystalTasksExecuteService
                ->getNextToBeExecutedCrystalTasksByPriorityStrategyAndChangeState(
                    $priorityStrategy,
                    $availableExecutionSlots
                );
        } catch (Exception $e) {
            Crystal::$logger->error(
                'CRYSTAL-0007: PriorityStrategy failed, falling back to SortByDateCreatedPriorityStrategy',
                [
                    'errorMessage' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            );

            $priorityStrategy = $this->getPriorityStrategy(SortByDateCreatedPriorityStrategy::class);
            $crystalTasks = $this->_crystalTasksExecuteService
                ->getNextToBeExecutedCrystalTasksByPriorityStrategyAndChangeState(
                    $priorityStrategy,
                    $availableExecutionSlots
                );
        }

        return $crystalTasks;
    }

    private function spawnCrystalTasks($crystalTasks): bool
    {
        try {
            foreach ($crystalTasks ?? [] as $crystalTask) {
                $this->spawnCrystalTask($crystalTask);
            }
        } catch (Exception $e) {
            Crystal::$logger->error(
                'CRYSTAL-0007: ProcessCrystalTasks failed',
                [
                    'errorMessage' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            );
            return false;
        }
        return true;
    }

    /**
     * Extra check to see if the priorityStrategy didn't pick up more than the allowed tasks
     *
     * @throws Exception
     */
    public function checkNextToBeExecutedCrystalTasksDoNotExceedMaxExecutionSlots(array $crystalTasks): void
    {
        if ($this->_config->getConfigByKey('maxExecutionSlots') < count($crystalTasks)) {
            throw new Exception('The nextToBeExecutedCrystalTasks was > than the allowed maxExecutionSlots, weird');
        }
    }

    /**
     * @throws Exception
     */
    public function spawnCrystalTask(CrystalTask $crystalTask): bool
    {
        try {
            $php = 'php -f ';
            $path = escapeshellarg($this->_config->getConfigByKey('applicationPhpFile'))  . ' crystaltaskexecute ';

            $idString  = ' --id=' . escapeshellarg($crystalTask->id);
            $classString  = ' --class=' . escapeshellarg($crystalTask->class);
            $rangeString  = ' --range=' . escapeshellarg($crystalTask->range);
            $timeoutString  = ' --timeout=' . escapeshellarg($crystalTask->timeout);
            $cooldownString  = ' --cooldown=' . escapeshellarg($crystalTask->cooldown);

            // Redirect only stdOut (not stdErr) + background job
            $outputRedirect = ' 1>/dev/null &';

            $exec = $php . $path . $idString . $classString . $rangeString . $timeoutString . $cooldownString . $outputRedirect;

            $this->executePhp($exec);
        } catch (Exception $e) {
            Crystal::$logger->error('CRYSTAL-0008: Could not spawn crystalTask', [
                'crystalTask' => $crystalTask,
                'errorMessage' => $e->getMessage(),
            ]);

            $this->_crystalTasksBaseService->saveCrystalTaskAndIncreaseErrorTries($crystalTask);
            return false;
        }

        return true;
    }

    public function executePhp(string $exec): void
    {
        exec($exec);
    }

}