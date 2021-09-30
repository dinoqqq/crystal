<?php

namespace Crystal\RangeStrategy;

use Exception;

class HashRangeStrategy implements RangeStrategyInterface
{
    private const MANDATORY_KEYS = [
        'resources',
    ];

    private $_resources;
    private $_dataSet = false;

    /**
     * @throws Exception
     */
    public function setData(array $data = []): void
    {
        $this->validate($data);
        $this->_resources = $data['resources'];
    }

    /**
     * @throws Exception
     */
    public function validate(array $data): bool
    {
        if (count(array_intersect(array_keys($data), self::MANDATORY_KEYS)) !== count(self::MANDATORY_KEYS)) {
            throw new Exception('The HashRangeStrategy needs to contain the following keys: ' . implode(',', self::MANDATORY_KEYS));
        }

        if ($data['resources'] > 16) {
            throw new Exception('The HashRangeStrategy can currently not work with more than 16 resources');
        }

        if ($data['resources'] < 1) {
            throw new Exception('The HashRangeStrategy can currently not work with 0 resources');
        }

        $this->_dataSet = true;
        return true;
    }

    /**
     * The hash range will take all possible base 16 characters and divides them in ranges.
     * Every range should be non-overlapping.
     *
     * Example:
     *
     * input
     * resources: 3
     *
     * output
     * [
     * '01234'
     * '56789'
     * 'abcdef'
     * ]
     *
     * @throws Exception
     */
    public function calculateRange(): array
    {
        if (!$this->_dataSet) {
            throw new Exception('First run setData');
        }

        $base16 = '0123456789abcdef';

        $restLength = strlen($base16);
        $result = [];

        for ($i = 0; $i < $this->_resources; $i++) {
            $restResources = $this->_resources - $i;
            $chunkSize = ceil($restLength / $restResources);
            $result[] = substr($base16, 0, $chunkSize);
            $base16 = substr($base16, $chunkSize);
            $restLength = strlen($base16);
        }

        return $result;
    }
}
