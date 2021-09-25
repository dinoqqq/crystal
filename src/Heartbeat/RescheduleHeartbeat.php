<?php

namespace Crystal\Heartbeat;

use Crystal\Crystal;
use Exception;
use Crystal\Entity\CrystalTask;
use Crystal\Service\CrystalTasksRescheduleService;
use Crystal\Service\CrystalTasksBaseService;
use Crystal\Config\Config;

class RescheduleHeartbeat implements HeartbeatInterface
{

    private $_config;
    private $_crystalTasksBaseService;
    private $_crystalTasksRescheduleService;

    /**
     * @throws Exception
     */
    public function __construct(
        Config $config,
        CrystalTasksBaseService $crystalTasksBaseService,
        CrystalTasksRescheduleService $crystalTasksRescheduleService
    ) {
        $this->_config = $config;
        $this->_crystalTasksBaseService = $crystalTasksBaseService;
        $this->_crystalTasksRescheduleService = $crystalTasksRescheduleService;
    }

    public function heartbeat(): bool
    {
        try {
            $startTime = microtime(true);
            $microSleepTimeSeconds = $this->_config->getConfigByKey('sleepTimeSeconds') * 1000000;
            $iterations = $this->_config->getConfigByKey('runTimeSeconds') / $this->_config->getConfigByKey('sleepTimeSeconds');

            for ($i = 0; $i < $iterations; $i++) {
                $this->rescheduleCrystalTasks();

                usleep($microSleepTimeSeconds);
                $endTime = microtime(true);
                if (($endTime - $startTime) >= $this->_config->getConfigByKey('runTimeSeconds')) {
                    // When here, we are > our runTimeSeconds, so abort
                    return true;
                }
            }
        } catch (Exception $e) {
            Crystal::$logger->error('CRYSTAL-0005: RescheduleHeartbeat heartbeat failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * @throws Exception
     */
    public function rescheduleCrystalTasks(): void
    {
        $crystalTasks = $this->_crystalTasksBaseService->getDeadOrNotCompletedCrystalTasks();

        /** @var CrystalTask $crystalTask */
        foreach ($crystalTasks ?? [] as $crystalTask) {
            if (
                !$crystalTask->isStateCrystalTaskDeadAndAfterRescheduleCooldown()
                && !$crystalTask->isStateCrystalTaskNotCompletedAndAfterRescheduleCooldown()
            ) {
                continue;
            }

            $this->_crystalTasksRescheduleService->rescheduleCrystalTask($crystalTask);
        }
    }
}
