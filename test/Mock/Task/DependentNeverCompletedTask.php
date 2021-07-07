<?php

namespace Crystal\Test\Mock\Task;

use Exception;

use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Entity\CrystalTask;
use Crystal\Task\TaskInterface;
use Crystal\Task\AbstractTask;

class DependentNeverCompletedTask extends AbstractTask implements TaskInterface
{
    /**
     * @throws Exception
     */
    public function queue(): array
    {
        $rangeStrategy = $this->getTaskConfig()->getRangeStrategy();
        $rangeStrategy->setData([
            'resources' => $this->getTaskConfig()->getResources()
        ]);

        $ranges = $rangeStrategy->calculateRange();

        $queue = [];

        foreach ($ranges as $range) {
            $crystalTask = new CrystalTask([
                'class' => self::class,
                'timeout' => $this->getTaskConfig()->getTimeout(),
                'cooldown' => $this->getTaskConfig()->getCooldown(),
                'entity_uid' => $this->getTaskConfig()->getEntityUid(),
                'range' => $range,
            ]);

            $queue[] = $crystalTask;
        }

        return $queue;
    }

    /**
     * @throws Exception
     */
    public function execute(): bool
    {
        $crystalTask = $this->getCrystalTask();
        if (!$crystalTask) {
            throw new CrystalTaskStateErrorException('The CrystalTask is not set while executing, weird');
        }

        sleep(rand(0, 2));

        // I'm done!
        return true;
    }
}
