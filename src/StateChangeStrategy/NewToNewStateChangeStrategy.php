<?php

namespace Crystal\StateChangeStrategy;

use Exception;
use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Entity\CrystalTask;

class NewToNewStateChangeStrategy implements StateChangeStrategyInterface
{
    /**
     * Someone else (QUEUE) picked it up already, just skip it
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
            $crystalTask->stateNewToNew();
        } catch (CrystalTaskStateErrorException $e) {
            return false;
        }
        return true;
    }
}
