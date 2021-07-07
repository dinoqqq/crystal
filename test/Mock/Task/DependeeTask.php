<?php

namespace Crystal\Test\Mock\Task;

use Exception;
use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Entity\CrystalTask;
use Crystal\Task\AbstractTask;
use Crystal\Task\TaskInterface;

class DependeeTask extends AbstractTask implements TaskInterface
{
    /**
     * @throws CrystalTaskStateErrorException
     * @throws Exception
     */
    public function queue(): array
    {
        if (!$this->getTaskConfig()) {
            throw new CrystalTaskStateErrorException('The CrystalTask is not set while queueing, weird');
        }

        $uid = 1;
        $rangeStrategy = $this->getTaskConfig()->getRangeStrategy();
        $rangeStrategy->setData([
            'uid' => $uid
        ]);

        $crystalTask = new CrystalTask([
            'class' => self::class,
            'timeout' => $this->getTaskConfig()->getTimeout(),
            'cooldown' => $this->getTaskConfig()->getCooldown(),
            'entity_uid' => $this->getTaskConfig()->getEntityUid(),
            'range' => $rangeStrategy->calculateRange()[0],
        ]);

        return [$crystalTask];
    }

    /**
     * @throws CrystalTaskStateErrorException
     * @throws Exception
     */
    public function execute(): bool
    {
        $crystalTask = $this->getCrystalTask();
        if (!$crystalTask) {
            throw new CrystalTaskStateErrorException('The CrystalTask is not set while executing, weird');
        }

        sleep(rand(0, 2));

        return true;
    }
}
