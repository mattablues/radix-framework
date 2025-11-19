<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

use InvalidArgumentException;
use Radix\Database\QueryBuilder\QueryBuilder;

trait InsertSelect
{
    /**
     * @param array<int, string> $columns
     */
    public function insertSelect(string $table, array $columns, QueryBuilder $select): self
    {
        if (empty($table) || empty($columns)) {
            throw new InvalidArgumentException('Table and columns are required for insertSelect.');
        }

        $this->type = 'INSERT';
        $this->table = $this->wrapColumn($table);
        // Flytta select-bindningar till mutation-bucket
        $this->addMutationBindings($select->getBindings());

        $cols = implode('`, `', array_map('trim', $columns));
        $this->mutationSql = "INSERT INTO {$this->table} (`{$cols}`) {$select->toSql()}";

        return $this;
    }
}
