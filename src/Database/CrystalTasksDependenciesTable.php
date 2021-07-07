<?php

namespace Crystal\Database;

use Crystal\Entity\CrystalTaskDependency;
use Medoo\Medoo;

class CrystalTasksDependenciesTable extends AbstractTable
{
    use TableTrait;

    private const PRIMARY_KEY = 'id';
    private const TABLE_NAME = 'crystal_tasks_dependencies';
    private const TABLE_COLUMNS = [
        'id',
        'class',
        'depend_on',
    ];

    private $_database;

    protected const ENTITY = CrystalTaskDependency::class;

    public function __construct(
        Medoo $database
    )
    {
        $this->_database = $database;
    }

    public function hasDependOnDependency(string $class): bool
    {
        return !!$this->_database->count(self::TABLE_NAME, [
            'class' => $class
        ]);
    }
}