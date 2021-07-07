<?php

namespace Crystal\Database;

use Crystal\Entity\EntityInterface;
use Exception;
use PDO;

trait TableTrait
{
    public function returnOneOrNull(array $result): ?EntityInterface
    {
        if (!count($result)) {
            return null;
        }

        return array_pop($result);
    }

    public function getAll(): array
    {
        $query = 'SELECT ' . $this->escapeTableColumns(self::TABLE_COLUMNS)
            . ' FROM <' . self::TABLE_NAME . '>';

        return $this->_database->query($query)->fetchAll(PDO::FETCH_CLASS, self::ENTITY);
    }

    public function insertAll(array $inserts): void
    {
        foreach ($inserts as $insert) {
            $this->_database->insert(self::TABLE_NAME, $insert);
        }
    }

    public function get(
        $where = null
    )
    {
        $query = 'SELECT ' . $this->escapeTableColumns(self::TABLE_COLUMNS)
            . ' FROM <' . self::TABLE_NAME . '>'
            . ' WHERE ' . $this->whereToQueryString($where);

        return $this->_database->query($query)->fetchAll(PDO::FETCH_CLASS, self::ENTITY);
    }

    public function getByPK($value): ?EntityInterface
    {
        $query = 'SELECT ' . $this->escapeTableColumns(self::TABLE_COLUMNS)
            . ' FROM <' . self::TABLE_NAME . '>'
            . ' WHERE <' . self::PRIMARY_KEY . '>=' . $this->_database->quote($value)
            . ' LIMIT 1';

        $result = $this->_database->query($query)->fetchAll(PDO::FETCH_CLASS, self::ENTITY);
        return $this->returnOneOrNull($result);
    }

    /**
     * Update or insert an entity to the database without a transaction.
     *
     * @throws Exception
     */
    public function save(EntityInterface $entity): bool
    {
        $pk = self::PRIMARY_KEY;
        $data = (array)$entity;

        try {
            if (isset($data[$pk])) {
                // Remove the PK
                unset($data[$pk]);

                $this->_database->update(
                    self::TABLE_NAME,
                    $data,
                    [$pk => $entity->{$pk}]
                );
            } else {
                $this->_database->insert(
                    self::TABLE_NAME,
                    $data
                );
            }
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    public function deleteByPK(int $id): void
    {
        $this->_database->delete(self::TABLE_NAME, [
            self::PRIMARY_KEY => $id
        ]);
    }

    public function insert(EntityInterface $crystalTask): void
    {
        $this->_database->insert(
            self::TABLE_NAME,
            (array)$crystalTask
        );
    }

    private function whereToQueryString(array $where): string
    {
        $predicates = [];
        foreach ($where as $column => $value) {
            $predicates[] = '`' . $column . '`=' . $this->_database->quote($value);
        }

        return implode(' AND ', $predicates);
    }

}