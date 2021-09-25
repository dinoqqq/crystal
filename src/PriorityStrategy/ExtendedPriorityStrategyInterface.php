<?php

namespace Crystal\PriorityStrategy;

interface ExtendedPriorityStrategyInterface
{
    public function getTaskClassesAndGrantedExecutionSlots(int $availableExecutionSlots): ?array;
}
