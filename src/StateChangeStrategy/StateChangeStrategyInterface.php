<?php

namespace Crystal\StateChangeStrategy;

use Crystal\Entity\CrystalTask;

interface StateChangeStrategyInterface {
    public function isDirtyShouldContinue(bool $isDirty): bool;
    public function stateNotChangedShouldContinue(bool $stateChanged): bool;
    public function changeState(CrystalTask $crystalTask): bool;
}

