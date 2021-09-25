<?php

namespace Crystal\Database;

class AbstractTable
{
    protected function escapeTableColumns(array $tableColumns): string
    {
        $string = implode('>,<', $tableColumns);
        return '<' . $string . '>';
    }
}
