<?php

namespace Crystal\PriorityStrategy;

use Exception;

/**
 * No priority strategy, just fetch by date_created DESC
 */
class SortByDateCreatedPriorityStrategy implements PriorityStrategyInterface
{
    private const MANDATORY_KEYS = [
        'tasks',
    ];

    private const MANDATORY_KEYS_TASKS = [
        'class',
    ];

    /**
     * @var array
     */
    private $_config;

    /**
     * @throws Exception
     */
    public function __construct(
        array $config
    )
    {
        $this->_config = $config;

        $this->validate();
    }

    /**
     * @throws Exception
     */
    public function validate(): bool
    {
        foreach (self::MANDATORY_KEYS as $mandatoryKey) {
            $keyNames = explode('.', $mandatoryKey);
            $this->iterateArrayAndCheckKeysExist($this->_config, $keyNames);
        }

        foreach (self::MANDATORY_KEYS_TASKS as $mandatoryKeyTask) {
            $keyNamesTask = explode('.', $mandatoryKeyTask);
            foreach ($this->_config['tasks'] ?? [] as $taskConfig) {
                $this->iterateArrayAndCheckKeysExist($taskConfig, $keyNamesTask);
            }
        }

        return true;
    }

    /**
     * @throws Exception
     */
    private function iterateArrayAndCheckKeysExist(array $array, array $keyNames): void
    {
        foreach ($keyNames as $keyName) {
            if (!array_key_exists($keyName, $array)) {
                throw new Exception('Key not found in array: "' . $keyName . '"');
            }

            $array = $array[$keyName];
        }
    }
}

