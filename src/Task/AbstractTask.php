<?php

namespace Crystal\Task;

use Crystal\Entity\CrystalTask;

use Crystal\Config\TaskConfigInterface;

class AbstractTask {
    private $_taskConfig = null;
    private $_crystalTask = null;
    private $_timeoutReached = false;
    private $_data = null;

    public function setTaskConfig(TaskConfigInterface $taskConfig): void
    {
        $this->_taskConfig = $taskConfig;
    }

    public function getTaskConfig(): ?TaskConfigInterface
    {
        return $this->_taskConfig;
    }

    public function setCrystalTask(CrystalTask $crystalTask): void
    {
        $this->_crystalTask = $crystalTask;
    }

    public function getCrystalTask(): ?CrystalTask
    {
        return $this->_crystalTask;
    }

    public function setTimeoutReached(bool $timeoutReached): void
    {
        $this->_timeoutReached = $timeoutReached;
    }

    public function getTimeoutReached(): bool
    {
        return $this->_timeoutReached;
    }

    public function isTimeoutReached(): bool
    {
        return $this->getTimeoutReached();
    }

    public function getData()
    {
        return $this->_data;
    }

    public function setData($data): void
    {
        $this->_data = $data;
    }
}


