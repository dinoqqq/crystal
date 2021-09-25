<?php

namespace Crystal\StateChangeStrategy;

use Exception;

use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Entity\CrystalTask;

class CompletedToNewStateChangeStrategy implements StateChangeStrategyInterface
{
    /**
     * Object is always dirty
     */
    public function isDirtyShouldContinue(bool $isDirty): bool
    {
        return true;
    }

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
            $crystalTask->stateCompletedToNew();
        } catch (CrystalTaskStateErrorException $e) {
            return false;
        }
        return true;
    }
}
