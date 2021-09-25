<?php

namespace Crystal\RangeStrategy;

interface RangeStrategyInterface
{
    public function calculateRange(): array;
    public function validate(array $data): bool;
    public function setData(array $data = []): void;
}
