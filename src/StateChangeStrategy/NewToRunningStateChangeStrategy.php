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
        return !$isDirty;
    }

    /**
     * Someone else picked it up already, just skip
     */
    public function stateNotChangedShouldContinue(bool $stateChanged): bool
    {
        return $stateChanged;
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
