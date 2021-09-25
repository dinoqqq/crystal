<?php

namespace Crystal\StateChangeStrategy;

use Crystal\Crystal;
use Exception;
use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Entity\CrystalTask;

class RunningToCompletedStateChangeStrategy implements StateChangeStrategyInterface
{
    public function isDirtyShouldContinue(bool $isDirty): bool
    {
        return !$isDirty;
    }

    /**
     * This would be weird, as EXECUTE is the only one using this
     */
    public function stateNotChangedShouldContinue(bool $stateChanged): bool
    {
        return $stateChanged;
    }

    /**
     * @throws Exception
     */
    public function changeState(CrystalTask $crystalTask): bool
    {
        try {
            $crystalTask->stateRunningToCompleted();
        } catch (CrystalTaskStateErrorException $e) {
            Crystal::$logger->error('CRYSTAL-0020: RunningToCompletedStateChangeStrategy state changed', [
                'crystalTask' => $crystalTask,
                'errorMessage' => $e->getMessage(),
            ]);
            return false;
        }
        return true;
    }
}
