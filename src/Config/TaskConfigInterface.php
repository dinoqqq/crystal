<?php

namespace Crystal\Config;

use Crystal\RangeStrategy\RangeStrategyInterface;

interface TaskConfigInterface
{
    public function setClass(string $class): void;
    public function getClass(): string;

    public function setResources(int $number): void;
    public function getResources(): int;

    public function setTimeout(int $seconds): void;
    public function getTimeout(): int;

    public function setEntityUid(?string $entityUid): void;
    public function getEntityUid(): ?string;

    public function setRangeStrategy(?string $rangeStrategyClassName): void;
    public function getRangeStrategy(): ?RangeStrategyInterface;

    public function setDependOn(?string $dependOn): void;
    public function getDependOn(): ?string;
}
