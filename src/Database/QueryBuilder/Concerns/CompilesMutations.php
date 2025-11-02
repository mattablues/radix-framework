<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait CompilesMutations
{
    protected function compileMutationSql(): string
    {
        // INSERT
        if ($this->type === 'INSERT') {
            $columns = implode(', ', array_map(fn($col) => $this->wrapColumn($col), $this->columns));
            $placeholders = implode(', ', array_fill(0, count($this->columns), '?'));
            return "INSERT INTO {$this->table} ($columns) VALUES ($placeholders)";
        }

        // UPDATE
        if ($this->type === 'UPDATE') {
            $setClause = implode(', ',
                array_map(fn($col) => "{$this->wrapColumn($col)} = ?", array_keys($this->columns))
            );

            $sql = "UPDATE {$this->table} SET {$setClause}";

            $where = $this->buildWhere();
            if (!empty($where)) {
                $sql .= " {$where}";
            }

            return $sql;
        }

        // DELETE
        if ($this->type === 'DELETE') {
            $sql = "DELETE FROM {$this->table}";
            $where = $this->buildWhere();

            if (!empty($where)) {
                $sql .= " {$where}";
            }

            return $sql;
        }

        throw new \RuntimeException("Query type '{$this->type}' är inte implementerad.");
    }

    public function insert(array $data): self
    {
        if (empty($data)) {
            throw new \InvalidArgumentException("Data for INSERT cannot be empty.");
        }

        $this->type = 'INSERT';
        $this->columns = array_keys($data);
        $this->setBindings($data);

        return $this;
    }

    public function update(array $data): self
    {
        $this->type = 'UPDATE';
        $this->columns = $data;

        $this->bindings = array_merge(
            array_values($data),
            $this->getWhereBindings()
        );

        return $this;
    }

    public function delete(): self
    {
        if (empty($this->where)) {
            throw new \RuntimeException("DELETE operation requires a WHERE clause.");
        }

        $this->type = 'DELETE';
        $this->setWhereBindings();

        return $this;
    }
}