<?php

namespace Crystal\StateChangeStrategy;

use Exception;
use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Entity\CrystalTask;

class NotCompletedToNewStateChangeStrategy implements StateChangeStrategyInterface
{
    public function isDirtyShouldContinue(bool $isDirty): bool
    {
        if ($isDirty) {
            return false;
        }
        return true;
    }

    public function stateNotChangedShouldContinue(bool $stateChanged): bool
    {
        if (!$stateChanged) {
            return false;
        }
        return true;
    }

    /**
     * @throws Exception
     */
    public function changeState(CrystalTask $crystalTask): bool
    {
        try {
            $crystalTask->stateNotCompletedToNew();
        } catch (CrystalTaskStateErrorException $e) {
            return false;
        }
        return true;
    }
}
