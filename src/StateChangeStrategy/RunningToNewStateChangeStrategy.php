<?php

namespace Crystal\StateChangeStrategy;

use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Entity\CrystalTask;
use Crystal\Service\CrystalTasksBaseService;

/**
 * Note: This is an erroneous state change, but needs to be handled correct!
 */
class RunningToNewStateChangeStrategy implements StateChangeStrategyInterface
{
    private $_crystalTasksBaseService;

    public function __construct(
        CrystalTasksBaseService $crystalTasksBaseService
    )
    {
        $this->_crystalTasksBaseService = $crystalTasksBaseService;
    }

    /**
     * Just continue and handle all in the changeState function
     */
    public function isDirtyShouldContinue(bool $isDirty): bool
    {
        return true;
    }

    public function stateNotChangedShouldContinue(bool $stateChanged): bool
    {
        return false;
    }

    /**
     * @throws CrystalTaskStateErrorException
     */
    public function changeState(CrystalTask $crystalTask): bool
    {
        // For not dependOn tasks we need to signal back that EXECUTE already picked it up
        // Throwing this error allows us to save all the other tasks in a queue, and ignore the dependent ones that
        // are running
        if (!$this->_crystalTasksBaseService->hasDependOnDependency($crystalTask)) {
            $message = CrystalTaskStateErrorException::$errorCodesMessages[100];
            throw new CrystalTaskStateErrorException($message, 100);
        }

        return false;
    }
}
