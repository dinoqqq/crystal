<?php

namespace Crystal\Config;
use Exception;

use Crystal\RangeStrategy\RangeStrategyInterface;

class TaskConfig implements TaskConfigInterface
{
    private const MANDATORY_KEYS = [
        'entityUid', 
        'resources', 
        'timeout',
        'cooldown',
        'class',
        'rangeStrategy',
    ];

    private $_config;
    private $_timeout;
    private $_cooldown;
    private $_resources;
    private $_entityUid;
    private $_class;
    private $_dependOn;
    private $_rangeStrategy;

    /**
     * @throws Exception
     */
    public function __construct(array $config)
    {
        $this->validate($config);

        $this->setResources($config['resources']);
        $this->setRangeStrategy($config['rangeStrategy']);

        $this->setClass($config['class']);
        $this->setTimeout($config['timeout']);
        $this->setCooldown($config['cooldown']);
        $this->setEntityUid($config['entityUid']);

        $this->setDependOn($config['dependOn'] ?? null);

        $this->_config = $config;
    }

    public function setClass(string $class): void
    {
        $this->_class = $class;
    }

    public function getClass(): string
    {
        return $this->_class;
    }

    public function setEntityUid(?string $entityUid): void
    {
        if (!empty($entityUid)) {
            $this->_entityUid = $entityUid;
        }
    }

    public function getEntityUid(): ?string
    {
        return $this->_entityUid;
    }

    public function setResources(int $number): void
    {
        $this->_resources = $number;
    }

    public function getResources(): int
    {
        return $this->_resources;
    }

    public function setCooldown(int $seconds): void
    {
        $this->_cooldown = $seconds;
    }

    public function getCooldown(): int
    {
        return $this->_cooldown;
    }

    public function setTimeout(int $seconds): void
    {
        $this->_timeout = $seconds;
    }

    public function getTimeout(): int
    {
        return $this->_timeout;
    }

    public function setRangeStrategy(?string $rangeStrategyClassName, array $data = []): void
    {
        if (!empty($rangeStrategyClassName)) {
            $this->_rangeStrategy = new $rangeStrategyClassName($data);
        }
    }

    public function getRangeStrategy(): ?RangeStrategyInterface
    {
        return $this->_rangeStrategy;
    }

    public function setDependOn(?string $dependOn): void
    {
        $this->_dependOn = $dependOn;
    }

    public function getDependOn(): ?string
    {
        return $this->_dependOn;
    }

    /**
     * @throws Exception
     */
    public function validate(array $config): bool
    {
        if (count(array_intersect(array_keys($config), self::MANDATORY_KEYS)) !== count(self::MANDATORY_KEYS)) {
            throw new Exception('The task config needs to contain the following keys: '
                . implode(',', self::MANDATORY_KEYS));
        }

        return true;
    }

    public function get(): array
    {
        return $this->_config;
    }
}
