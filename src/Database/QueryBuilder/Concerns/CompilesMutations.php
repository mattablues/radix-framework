<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait CompilesMutations
{
    protected array $withAggregateExpressions = [];
    protected ?array $upsertUnique = null; // för UPSERT
    protected ?array $upsertUpdate = null;  // för UPSERT
    protected array $bindingsUnion = [];
    protected array $bindingsMutation = [];

    // Håller färdigbyggd SQL för mutations (används av t.ex. InsertSelect)
    protected ?string $mutationSql = null;

    protected function compileMutationSql(): string
    {
        if ($this->type === 'INSERT') {
            $columns = implode(
                ', ',
                array_map(
                    function ($col): string {
                        $name = $this->normalizeColumnName($col);
                        return $this->wrapColumn($name);
                    },
                    $this->columns
                )
            );
            $placeholders = implode(', ', array_fill(0, count($this->columns), '?'));
            $this->compileAllBindings();
            return "INSERT INTO $this->table ($columns) VALUES ($placeholders)";
        }

        if ($this->type === 'UPDATE') {
            $setClause = implode(
                ', ',
                array_map(
                    function ($col): string {
                        $name = $this->normalizeColumnName($col);
                        return $this->wrapColumn($name) . ' = ?';
                    },
                    array_keys($this->columns)
                )
            );

            $sql = "UPDATE $this->table SET $setClause";

            $where = $this->buildWhere();
            if (!empty($where)) {
                $sql .= " $where";
            }

            $this->compileAllBindings();
            return $sql;
        }

        if ($this->type === 'DELETE') {
            $sql = "DELETE FROM $this->table";
            $where = $this->buildWhere();

            if (!empty($where)) {
                $sql .= " $where";
            }

            $this->compileAllBindings();
            return $sql;
        }

        if ($this->type === 'INSERT_IGNORE') {
            $columns = implode(
                ', ',
                array_map(
                    function ($col): string {
                        $name = $this->normalizeColumnName($col);
                        return $this->wrapColumn($name);
                    },
                    $this->columns
                )
            );
            $placeholders = implode(', ', array_fill(0, count($this->columns), '?'));
            $this->compileAllBindings();
            return "INSERT OR IGNORE INTO $this->table ($columns) VALUES ($placeholders)";
        }

        if ($this->type === 'UPSERT') {
            if (empty($this->upsertUnique)) {
                throw new \RuntimeException('Upsert kräver unika kolumner.');
            }

            $columns = implode(
                ', ',
                array_map(
                    function ($col): string {
                        $name = $this->normalizeColumnName($col);
                        return $this->wrapColumn($name);
                    },
                    $this->columns
                )
            );
            $placeholders = implode(', ', array_fill(0, count($this->columns), '?'));

            $conflict = implode(
                ', ',
                array_map(
                    function ($col): string {
                        $name = $this->normalizeColumnName($col);
                        return $this->wrapColumn($name);
                    },
                    $this->upsertUnique
                )
            );

            $updates = $this->upsertUpdate;
            if ($updates === null || $updates === []) {
                /** @var array<int,string> $columnNames */
                $columnNames = array_values(array_map(
                    function ($col): string {
                        return $this->normalizeColumnName($col);
                    },
                    $this->columns
                ));
                $updates = array_combine($columnNames, array_fill(0, count($columnNames), null));
            }

            $updateSql = implode(
                ', ',
                array_map(
                    function ($col): string {
                        $name = $this->normalizeColumnName($col);
                        return $this->wrapColumn($name) . ' = EXCLUDED.' . $this->wrapColumn($name);
                    },
                    array_keys($updates)
                )
            );

            $this->compileAllBindings();
            return "INSERT INTO $this->table ($columns) VALUES ($placeholders) ON CONFLICT ($conflict) DO UPDATE SET $updateSql";
        }

        throw new \RuntimeException("Query type '$this->type' är inte implementerad.");
    }

    /**
     * Normalisera ett kolumnnamn till en sträng på ett typesäkert sätt.
     */
    private function normalizeColumnName(mixed $col): string
    {
        if (is_string($col)) {
            return $col;
        }

        if ($col instanceof \Stringable) {
            return (string) $col;
        }

        // Om du vill tillåta int som indexerade kolumner kan du göra t.ex. "col_$col",
        // men i din kod används kolumner som faktiska namnssträngar.
        throw new \RuntimeException('Ogiltigt kolumnnamn: ' . get_debug_type($col));
    }

    /**
     * @param array<string, mixed> $data Data för INSERT (kolumn => värde)
     */
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

    /**
     * @param array<string, mixed> $data Data för UPDATE (kolumn => värde)
     */
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

    /**
     * @param array<string, mixed> $data Data för INSERT OR IGNORE (kolumn => värde)
     */
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

    /**
     * @param array<string, mixed>     $data      Rad att upserta (kolumn => värde)
     * @param array<int, string>       $uniqueBy  Kolumner/nycklar som definierar unikhet
     * @param array<string, mixed>|null $update   Kolumner att uppdatera vid konflikt (null = alla kolumner)
     */
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