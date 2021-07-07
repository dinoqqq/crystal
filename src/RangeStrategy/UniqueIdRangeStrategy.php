<?php

namespace Crystal\RangeStrategy;

use Exception;

class UniqueIdRangeStrategy implements RangeStrategyInterface
{
    private const MANDATORY_KEYS = [
        'uid',
    ];

    private $_uid;
    private $_dataSet = false;

    /**
     * @throws Exception
     */
    public function setData(array $data = []): void
    {
        $this->validate($data);
        $this->_uid = $data['uid'];
    }

    /**
     * @throws Exception
     */
    public function validate(array $data): bool
    {
        if (count(array_intersect(array_keys($data), self::MANDATORY_KEYS)) !== count(self::MANDATORY_KEYS)) {
            throw new Exception('The UniqueIdRangeStrategy needs to contain the following keys: ' . implode(self::MANDATORY_KEYS));
        }

        $this->_dataSet = true;
        return true;
    }

    /**
     * @throws Exception
     */
    public function calculateRange(): array
    {
        if (!$this->_dataSet) {
            throw new Exception('First run setData');
        }
        return [$this->_uid];
    }
}
