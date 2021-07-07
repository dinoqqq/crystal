<?php

namespace Crystal\PriorityStrategy;

interface PriorityStrategyFactoryInterface {
    public function create(string $className): PriorityStrategyInterface;
}

