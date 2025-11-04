<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait CompilesMutations
{
    protected array $withAggregateExpressions = [];
    protected ?array $upsertUnique = null; // för UPSERT
    protected ?array $upsertUpdate = null;  // för UPSERT

    protected function compileMutationSql(): string
    {
        if ($this->type === 'INSERT') {
            $columns = implode(', ', array_map(fn($col) => $this->wrapColumn($col), $this->columns));
            $placeholders = implode(', ', array_fill(0, count($this->columns), '?'));
            if (method_exists($this, 'compileAllBindings')) {
                $this->compileAllBindings();
            }
            return "INSERT INTO $this->table ($columns) VALUES ($placeholders)";
        }

        if ($this->type === 'UPDATE') {
            $setClause = implode(', ',
                array_map(fn($col) => "{$this->wrapColumn($col)} = ?", array_keys($this->columns))
            );

            $sql = "UPDATE $this->table SET $setClause";

            $where = $this->buildWhere();
            if (!empty($where)) {
                $sql .= " $where";
            }

            if (method_exists($this, 'compileAllBindings')) {
                $this->compileAllBindings();
            }
            return $sql;
        }

        if ($this->type === 'DELETE') {
            $sql = "DELETE FROM $this->table";
            $where = $this->buildWhere();

            if (!empty($where)) {
                $sql .= " $where";
            }

            if (method_exists($this, 'compileAllBindings')) {
                $this->compileAllBindings();
            }
            return $sql;
        }

        if ($this->type === 'INSERT_IGNORE') {
            $columns = implode(', ', array_map(fn($col) => $this->wrapColumn($col), $this->columns));
            $placeholders = implode(', ', array_fill(0, count($this->columns), '?'));
            if (method_exists($this, 'compileAllBindings')) {
                $this->compileAllBindings();
            }
            return "INSERT OR IGNORE INTO $this->table ($columns) VALUES ($placeholders)";
        }

        if ($this->type === 'UPSERT') {
            if (empty($this->upsertUnique)) {
                throw new \RuntimeException('Upsert kräver unika kolumner.');
            }
            $columns = implode(', ', array_map(fn($col) => $this->wrapColumn($col), $this->columns));
            $placeholders = implode(', ', array_fill(0, count($this->columns), '?'));
            $conflict = implode(', ', array_map(fn($col) => $this->wrapColumn($col), $this->upsertUnique));
            $updates = $this->upsertUpdate;
            if ($updates === null || $updates === []) {
                $updates = array_combine($this->columns, array_fill(0, count($this->columns), null));
            }
            $updateSql = implode(', ', array_map(
                fn($col) => $this->wrapColumn($col) . ' = EXCLUDED.' . $this->wrapColumn($col),
                array_keys($updates)
            ));
            if (method_exists($this, 'compileAllBindings')) {
                $this->compileAllBindings();
            }
            return "INSERT INTO $this->table ($columns) VALUES ($placeholders) ON CONFLICT ($conflict) DO UPDATE SET $updateSql";
        }

        throw new \RuntimeException("Query type '$this->type' är inte implementerad.");
    }

    public function insert(array $data): self
    {
        if (empty($data)) {
            throw new \InvalidArgumentException("Data for INSERT cannot be empty.");
        }

        $this->type = 'INSERT';
        $this->columns = array_keys($data);
        $this->bindingsMutation = array_values($data);

        return $this;
    }

    public function update(array $data): self
    {
        $this->type = 'UPDATE';
        $this->columns = $data;

        // mutation-bucket: först set-värden, sedan where-bucket hanteras via compileAllBindings()
        $this->bindingsMutation = array_values($data);
        return $this;
    }


    public function delete(): self
    {
        if (empty($this->where)) {
            throw new \RuntimeException("DELETE operation requires a WHERE clause.");
        }

        $this->type = 'DELETE';
        return $this;
    }

    public function insertOrIgnore(array $data): self
    {
        if (empty($data)) {
            throw new \InvalidArgumentException("Data for INSERT OR IGNORE cannot be empty.");
        }
        $this->type = 'INSERT_IGNORE';
        $this->columns = array_keys($data);
        $this->bindingsMutation = array_values($data);
        return $this;
    }

    public function upsert(array $data, array $uniqueBy, ?array $update = null): self
    {
        if (empty($data) || empty($uniqueBy)) {
            throw new \InvalidArgumentException('Upsert kräver data och uniqueBy.');
        }
        $this->type = 'UPSERT';
        $this->columns = array_keys($data);
        $this->bindingsMutation = array_values($data);
        $this->upsertUnique = $uniqueBy;
        $this->upsertUpdate = $update;
        return $this;
    }

    public function setModelClass(string $modelClass): self
    {
        if (!class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class '$modelClass' does not exist.");
        }
        $this->modelClass = $modelClass;
        return $this;
    }
}