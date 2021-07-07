<?php

namespace Crystal\StateChangeStrategy;

use Exception;
use Crystal\Entity\CrystalTask;

class NewToRunningStateChangeStrategy implements StateChangeStrategyInterface
{
    /**
     * Someone else picked it up already, just skip
     */
    public function isDirtyShouldContinue(bool $isDirty): bool
    {
        if ($isDirty) {
            return false;
        }
        return true;
    }

    /**
     * Someone else picked it up already, just skip
     */
    public function stateNotChangedShouldContinue(bool $stateChanged): bool
    {
        if (!$stateChanged) {
            return false;
        }
        return true;
    }

    public function changeState(CrystalTask $crystalTask): bool
    {
        try {
            $crystalTask->stateNewToRunning();
        } catch (Exception $e) {
            return false;
        }
        return true;
    }
}
