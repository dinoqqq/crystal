<?php

namespace Crystal\Config;

use Exception;

class Config implements ConfigInterface
{
    private const MANDATORY_KEYS = [
        'phpExecutable',
        'applicationPhpFile',
        'maxExecutionSlots',
        'sleepTimeSeconds',
        'runTimeSeconds',
        'maxErrorTries',
    ];

    private $_config;

    /**
     * @throws Exception
     */
    public function __construct(array $config)
    {
        $this->_config = $config;
    }

    /**
     * @throws Exception
     */
    public function validate(): void
    {
        $config = $this->_config;
        if (count(array_intersect(self::MANDATORY_KEYS, array_keys($config))) !== count(self::MANDATORY_KEYS)) {
            throw new Exception('The config needs to contain the following keys: '
                . implode(self::MANDATORY_KEYS, ','));
        }
        if (!is_int($config['maxExecutionSlots'] ?? false)) {
            throw new Exception('Config maxExecutionSlots should be an int');
        }
        if (!is_numeric($config['sleepTimeSeconds'] ?? false)) {
            throw new Exception('Config sleepTimeSeconds should be an int');
        }
        if (!is_numeric($config['runTimeSeconds'] ?? false)) {
            throw new Exception('Config runTimeSeconds should be an int');
        }
        if ($config['sleepTimeSeconds'] <= 0) {
            throw new Exception('The sleepTimeSeconds need to be a float > 0');
        }
        if ($config['runTimeSeconds'] <= 0) {
            throw new Exception('The runTimeSeconds need to be a float > 0');
        }
    }

    public function getTasks(): array
    {
        return $this->_config['tasks'];
    }

    /**
     * @throws Exception
     */
    public function getConfigByKey(string $key)
    {
        if (!array_key_exists($key, $this->_config)) {
            throw new Exception('Key in config does not exist');
        }
        return $this->_config[$key] ?? null;
    }

    public function getMainProcessNames(): array
    {
        $mainProcessNames = [];
        foreach (($this->_config['mainProcesses'] ?? []) as $mainProcess) {
            if ($mainProcess['disabled'] ?? false) {
                continue;
            }
            $mainProcessNames[] = $mainProcess['name'];
        }

        return $mainProcessNames;
    }

    /**
     * @throws Exception
     */
    public function mainProcessNameToConfigArray(string $mainProcessName): array
    {
        $mainProcesses = [];
        foreach (($this->_config['mainProcesses'] ?? []) as $mainProcess) {
            if ($mainProcess['disabled'] ?? false) {
                continue;
            }
            if ($mainProcess['name'] === $mainProcessName) {
                $mainProcesses[] = $mainProcess;
            }
        }

        if (count($mainProcesses) > 1) {
            throw new Exception('Found more than 1 main process name in crystal config');
        }

        if (count($mainProcesses) === 0) {
            return [];
        }

        return array_shift($mainProcesses);
    }

    /**
     * @throws Exception
     */
    public function getTaskClassesByMainProcessNameAndRangeStrategy(string $mainProcessName, string $rangeStrategy): array
    {
        $classes = [];
        $mainProcessConfig = $this->mainProcessNameToConfigArray($mainProcessName);

        foreach ($mainProcessConfig['tasks'] ?? [] as $mainProcessTaskConfig) {
            $taskConfig = $this->mergeBaseTaskConfigWithMainProcessTaskConfig($mainProcessTaskConfig);
            if ($taskConfig['rangeStrategy'] !== $rangeStrategy) {
                continue;
            }

            $classes[] = $taskConfig['class'];
        }

        return $classes;
    }

    /**
     * @throws Exception
     */
    public function isMainProcessNameInConfig(string $mainProcessName): bool
    {
        return !empty($this->mainProcessNameToConfigArray($mainProcessName));
    }

    /**
     * @throws Exception
     */
    public function mergeBaseTaskConfigWithMainProcessTaskConfig(array $mainProcessTaskConfig): array
    {
        $baseTaskConfig = $this->getBaseTask($mainProcessTaskConfig);
        return array_merge($baseTaskConfig, $mainProcessTaskConfig);
    }

    /**
     * @throws Exception
     */
    public function getBaseTask(array $mainProcessTaskConfig): array
    {
        foreach ($this->getTasks() as $baseTaskConfig) {
            if ($baseTaskConfig['class'] === $mainProcessTaskConfig['class']) {
                return $baseTaskConfig;
            }
        }

        throw new Exception('Task not found: ' . $mainProcessTaskConfig['class']);
    }

    /**
     * @throws Exception
     */
    public function getDependencies(): array
    {
        $dependencies = [];
        foreach (($this->_config['mainProcesses'] ?? []) as $mainProcessConfig) {
            if ($mainProcess['disabled'] ?? false) {
                continue;
            }

            foreach ($mainProcessConfig['tasks'] ?? [] as $mainProcessTaskConfig) {
                $taskConfig = $this->mergeBaseTaskConfigWithMainProcessTaskConfig($mainProcessTaskConfig);
                if (!isset($taskConfig['dependOn'])) {
                    continue;
                }

                $dependency = [
                    'class' => $taskConfig['class'],
                    'depend_on' => $taskConfig['dependOn'],
                ];
                $dependencies[] = $dependency;
            }
        }

        return $dependencies;
    }

}
