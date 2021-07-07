<?php

namespace Crystal\Entity;

class CrystalTaskDependency implements EntityInterface
{
    public $id;
    public $class;
    public $depend_on;

    /**
     * A task is considered a duplicate when all these columns are the same
     */
    public const UNIQUE_INDEX_CRYSTAL_TASK_DEPENDENCY = [
        'class', 
        'depend_on' 
    ];

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->exchangeArray($data);
        }
    }

    private function exchangeArray(array $data)
    {
        $this->id = (! empty($data['id'])) ? $data['id'] : null;
        $this->class = (! empty($data['class'])) ? $data['class'] : null;
        $this->depend_on = (! empty($data['depend_on'])) ? $data['depend_on'] : null;
    }

    public function getValuesUniqueIndexAsArray(): array
    {
        $result = [];
        foreach ($this as $key => $value) {
            if (in_array($key, self::UNIQUE_INDEX_CRYSTAL_TASK_DEPENDENCY)) {
                $result[$key] = $value;
            }

        }

        return $result;
    }

}

