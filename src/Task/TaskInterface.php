<?php

namespace Crystal\Task;

use Crystal\Config\TaskConfigInterface;
use Crystal\Entity\CrystalTask;

interface TaskInterface {
    public function setTaskConfig(TaskConfigInterface $taskConfig): void;
    public function getTaskConfig(): ?TaskConfigInterface;

    public function setCrystalTask(CrystalTask $crystalTask): void;
    public function getCrystalTask(): ?CrystalTask;

    public function setTimeoutReached(bool $timeoutReached): void;
    public function getTimeoutReached(): bool;
    public function isTimeoutReached(): bool;

    public function getData();
    public function setData($data): void;

    public function queue(): array;
    public function execute(): bool;
}

