<?php

declare(strict_types=1);

namespace Radix\Database\Migration;

use InvalidArgumentException;
use LogicException;

class Blueprint
{
    private string $table;
    /** @var array<int,string> */
    private array $columns = [];
    /** @var array<int,string> */
    private array $alterOperations = [];
    /** @var array<int,string> */
    private array $keys = [];
    /** @var array<int,string> */
    private array $constraints = [];
    /** @var array<int,string> */
    private array $tableOptions = [];
    private bool $isAlter;

    public function __construct(string $table, bool $isAlter = false)
    {
        $this->table = $table;
        $this->isAlter = $isAlter;
    }

    /**
     * Lägg till en kolumn på tabellen.
     *
     * @param array<string,mixed> $options
     */
    public function addColumn(string $type, string $name, array $options = []): self
    {
        $validAttributes = ['nullable', 'default', 'onUpdate', 'collation', 'comment', 'before', 'after', 'first', 'unsigned', 'autoIncrement'];

        $typeMapping = [
            'string'      => 'VARCHAR(255)',
            'integer'     => 'INT',
            'unsignedInt' => 'INT UNSIGNED',
            'tinyInteger' => 'TINYINT',
            'bigInteger'  => 'BIGINT',
            'boolean'     => 'TINYINT(1)',
            'uuid'        => 'CHAR(36)',
            'text'        => 'TEXT',
            'json'        => 'JSON',
            'time'        => 'TIME',
            'datetime'    => 'DATETIME',
            'float'       => 'FLOAT',
            'decimal'     => 'DECIMAL',
            'enum'        => 'ENUM',
        ];

        // Först, mappa typer via $typeMapping om möjligt.
        if (isset($typeMapping[$type])) {
            $type = $typeMapping[$type];
        } else {
            if (preg_match('/^FLOAT\(\d+,\s?\d+\)$/i', $type)) {
                // Exempel: 'FLOAT(10, 2)' är giltig
            } elseif (!array_key_exists($type, $typeMapping)
                && !preg_match('/^(ENUM|SET)\([\'"].+?[\'"](?:,\s?[\'"].+?[\'"])*\)$/i', $type)
                && !preg_match('/^[A-Z][A-Z0-9]*(\(\d+(,\s?\d+)?\))?( UNSIGNED)?$/', $type)) {
                throw new InvalidArgumentException("Unsupported column type: '$type'");
            }
        }

        foreach (array_keys($options) as $attribute) {
            if (!in_array($attribute, $validAttributes, true)) {
                throw new InvalidArgumentException("Unsupported column attribute: '$attribute'");
            }
        }

        $definition = "`$name` $type";
        $definition .= empty($options['nullable']) ? ' NOT NULL' : ' NULL';

        if (isset($options['default'])) {
            $default = $options['default'];

            if (is_bool($default)) {
                $defaultStr = $default ? '1' : '0';
            } else {
                // Kontrollera om det är en sökväg, annars gör det VERSALER.
                if (is_string($default) && preg_match('#^(/|[a-zA-Z]:|https?://)#', $default)) {
                    $defaultStr = $default; // Behåll originalform.
                } else {
                    if (!is_scalar($default)) {
                        throw new InvalidArgumentException("Default value must be a scalar or string.");
                    }
                    /** @var int|float|string $default */
                    $defaultStr = strtoupper((string) $default); // Konvertera till versaler.
                }
            }

            $definition .= ($defaultStr === 'CURRENT_TIMESTAMP')
                ? " DEFAULT $defaultStr"
                : " DEFAULT '" . addslashes($defaultStr) . "'";
        }

        if (isset($options['autoIncrement']) && $options['autoIncrement'] === true) {
            $definition .= ' AUTO_INCREMENT';
        }

        if (isset($options['onUpdate'])) {
            if (!is_string($options['onUpdate'])) {
                throw new InvalidArgumentException("Option 'onUpdate' must be a string.");
            }
            $definition .= ' ON UPDATE ' . $options['onUpdate'];
        }

        if (isset($options['collation'])) {
            if (!is_string($options['collation'])) {
                throw new InvalidArgumentException("Option 'collation' must be a string.");
            }
            $definition .= ' COLLATE ' . $options['collation'];
        }

        if (isset($options['comment'])) {
            if (!is_string($options['comment'])) {
                throw new InvalidArgumentException("Option 'comment' must be a string.");
            }
            $definition .= " COMMENT '" . addslashes($options['comment']) . "'";
        }

        if (isset($options['before'])) {
            if (!is_string($options['before'])) {
                throw new InvalidArgumentException("Option 'before' must be a string.");
            }
            $definition .= ' BEFORE `' . $options['before'] . '`';
        } elseif (isset($options['after'])) {
            if (!is_string($options['after'])) {
                throw new InvalidArgumentException("Option 'after' must be a string.");
            }
            $definition .= ' AFTER `' . $options['after'] . '`';
        } elseif (!empty($options['first'])) {
            $definition .= ' FIRST';
        }

        if ($this->isAlter) {
            $this->alterOperations[] = 'ADD COLUMN ' . $definition;
        } else {
            $this->columns[] = $definition;
        }

        return $this;
    }

    /**
     * Ta bort en kolumn från tabellen.
     */
    public function dropColumn(string $name): self
    {
        if (!$this->isAlter) {
            throw new LogicException('dropColumn can only be used in ALTER TABLE context.');
        }
        $this->alterOperations[] = 'DROP COLUMN `' . $name . '`';
        return $this;
    }

    /**
     * Ta bort flera kolumner.
     *
     * @param array<int,string> $columns
     */
    public function dropColumns(array $columns): self
    {
        foreach ($columns as $column) {
            $this->dropColumn($column);
        }
        return $this;
    }

    public function id(string $name = 'id'): self
    {
        return $this->addColumn('INT UNSIGNED', $name, [
            'nullable' => false,
            'autoIncrement' => true,
        ])->primary([$name]);
    }

    /**
     * Lägg till en VARCHAR‑kolumn.
     *
     * @param array<string,mixed> $options
     */
    public function string(string $name, int $length = 255, array $options = []): self
    {
        return $this->addColumn("VARCHAR($length)", $name, $options);
    }

    /**
     * Lägg till en TEXT‑kolumn.
     *
     * @param array<string,mixed> $options
     */
    public function text(string $name, array $options = []): self
    {
        return $this->addColumn('TEXT', $name, $options);
    }

    /**
     * Lägg till en INT‑kolumn.
     *
     * @param array<string,mixed> $options
     */
    public function integer(string $name, bool $unsigned = false, array $options = []): self
    {
        $type = $unsigned ? 'INT UNSIGNED' : 'INT';
        return $this->addColumn($type, $name, $options);
    }

    /**
     * Lägg till en TINYINT‑kolumn.
     *
     * @param array<string,mixed> $options
     */
    public function tinyInteger(string $name, bool $unsigned = false, array $options = []): self
    {
        $type = $unsigned ? 'TINYINT UNSIGNED' : 'TINYINT';
        return $this->addColumn($type, $name, $options);
    }

    /**
     * Lägg till en BIGINT‑kolumn.
     *
     * @param array<string,mixed> $options
     */
    public function bigInteger(string $name, bool $unsigned = false, array $options = []): self
    {
        $type = $unsigned ? 'BIGINT UNSIGNED' : 'BIGINT';
        return $this->addColumn($type, $name, $options);
    }

    /**
     * Lägg till en BOOLEAN‑kolumn (TINYINT(1)).
     *
     * @param array<string,mixed> $options
     */
    public function boolean(string $name, array $options = []): self
    {
        return $this->addColumn('TINYINT(1)', $name, $options);
    }

    /**
     * Lägg till en FLOAT‑kolumn.
     *
     * @param array<string,mixed> $options
     */
    public function float(string $name, int $total = 8, int $places = 2, array $options = []): self
    {
        return $this->addColumn("FLOAT($total, $places)", $name, $options);
    }

    /**
     * Lägg till en DECIMAL‑kolumn.
     *
     * @param array<string,mixed> $options
     */
    public function decimal(string $name, int $total = 8, int $places = 2, array $options = []): self
    {
        return $this->addColumn("DECIMAL($total, $places)", $name, $options);
    }

    /**
     * Lägg till en DATETIME‑kolumn.
     *
     * @param array<string,mixed> $options
     */
    public function datetime(string $name, array $options = []): self
    {
        return $this->addColumn('DATETIME', $name, $options);
    }

    /**
     * Lägg till en TIME‑kolumn.
     *
     * @param array<string,mixed> $options
     */
    public function time(string $name, array $options = []): self
    {
        return $this->addColumn('TIME', $name, $options);
    }

    /**
     * Lägg till en JSON‑kolumn.
     *
     * @param array<string,mixed> $options
     */
    public function json(string $name, array $options = []): self
    {
        return $this->addColumn('JSON', $name, $options);
    }

    /**
     * Lägg till en ENUM‑kolumn.
     *
     * @param array<int,string>   $allowed Lista av tillåtna värden.
     * @param array<string,mixed> $options Ytterligare kolumn‑optioner.
     */
    public function enum(string $name, array $allowed, array $options = []): self
    {
        $values = "'" . implode("', '", $allowed) . "'";
        return $this->addColumn("ENUM($values)", $name, $options);
    }

    /**
     * Lägg till en UUID‑kolumn (CHAR(36)).
     *
     * @param array<string,mixed> $options
     */
    public function uuid(string $name, array $options = []): self
    {
        return $this->addColumn('CHAR(36)', $name, $options);
    }

    /**
     * Lägg till timestamps-kolumner.
     */
    public function timestamps(): self
    {
        $this->datetime('created_at', ['default' => 'CURRENT_TIMESTAMP']);
        $this->datetime('updated_at', ['default' => 'CURRENT_TIMESTAMP', 'onUpdate' => 'CURRENT_TIMESTAMP']);
        return $this;
    }

    /**
     * Lägg till en kolumn för soft deletes.
     */
    public function softDeletes(): self
    {
        return $this->datetime('deleted_at', ['nullable' => true]);
    }

    /**
     * Sätt primärnyckel på de angivna kolumnerna.
     *
     * @param array<int,string> $columns
     */
    public function primary(array $columns): self
    {
        $this->keys[] = 'PRIMARY KEY (' . $this->formatColumnList($columns) . ')';
        return $this;
    }

    /**
     * Skapa ett unikt index på de angivna kolumnerna.
     *
     * @param array<int,string> $columns
     */
    public function unique(array $columns, string $name = null): self
    {
        $indexName = $name ?: 'unique_' . implode('_', $columns);
        $this->keys[] = 'UNIQUE INDEX `' . $indexName . '` (' . $this->formatColumnList($columns) . ')';
        return $this;
    }

    /**
     * Skapa ett (icke-unikt) index på de angivna kolumnerna.
     *
     * @param array<int,string> $columns
     */
    public function index(array $columns, string $name = null): self
    {
        $indexName = $name ?: 'index_' . implode('_', $columns);
        $this->keys[] = 'INDEX `' . $indexName . '` (' . $this->formatColumnList($columns) . ')';
        return $this;
    }

    public function foreign(string $column, string $referencesTable, string $referencesColumn = 'id', string $onDelete = 'CASCADE', string $onUpdate = 'CASCADE'): self
    {
        $constraint = 'FOREIGN KEY (`' . $column . '`) REFERENCES `' . $referencesTable . '` (`' . $referencesColumn . '`) ON DELETE ' . $onDelete . ' ON UPDATE ' . $onUpdate;

        if ($this->isAlter) {
            $this->alterOperations[] = 'ADD ' . $constraint;
        } else {
            $this->constraints[] = $constraint;
        }

        return $this;
    }

    /**
     * Ändra primärnyckeln till de angivna kolumnerna.
     *
     * @param array<int,string> $columns
     */
    public function modifyPrimary(array $columns): self
    {
        if (!$this->isAlter) {
            throw new LogicException('modifyPrimary can only be used in ALTER TABLE context.');
        }
        $this->alterOperations[] = 'DROP PRIMARY KEY';
        $this->alterOperations[] = 'ADD PRIMARY KEY (' . $this->formatColumnList($columns) . ')';
        return $this;
    }

    public function engine(string $engine): self
    {
        $this->tableOptions[] = 'ENGINE=' . $engine;
        return $this;
    }

    public function autoIncrement(int $start): self
    {
        $this->tableOptions[] = 'AUTO_INCREMENT=' . $start;
        return $this;
    }

    public function tableComment(string $comment): self
    {
        $this->tableOptions[] = "COMMENT = '" . addslashes($comment) . "'";
        return $this;
    }

    public function toSql(): string
    {
        $definitions = array_merge($this->columns, $this->keys, $this->constraints);
        $options = !empty($this->tableOptions) ? ' ' . implode(' ', $this->tableOptions) : '';
        return 'CREATE TABLE `' . $this->table . '` (' . implode(', ', $definitions) . ')' . $options . ' DEFAULT CHARSET=utf8mb4;';
    }

    /**
     * Generera SQL‑satser för ALTER‑operationerna.
     *
     * @return array<int,string> Lista av SQL‑strängar.
     */
    public function toAlterSql(): array
    {
        return array_map(
            fn($operation) => 'ALTER TABLE `' . $this->table . '` ' . $operation . ';',
            $this->alterOperations
        );
    }

    /**
     * Generera SQL‑satser för att rulla tillbaka blueprintens ändringar.
     *
     * @return array<int,string> Lista av SQL‑strängar.
     */
    public function toRollbackSql(): array
    {
        if (empty($this->alterOperations)) {
            throw new LogicException('No operations to rollback.');
        }

        $rollbackStatements = [];

        foreach (array_reverse($this->alterOperations) as $operation) {
            // Kontrollera om vi försöker återställa borttagning av en kolumn.
            if (str_starts_with($operation, 'DROP COLUMN')) {
                throw new LogicException('Cannot rollback a dropped column automatically. Column details are missing.');
            }

            // Lägg till rollback-logik för andra operationer beroende på dina behov.
            // Här är ett exempel för att ta bort en tillagd kolumn:
            if (str_starts_with($operation, 'ADD COLUMN')) {
                $columnName = $this->extractColumnName($operation);
                if ($columnName) {
                    $rollbackStatements[] = "ALTER TABLE `$this->table` DROP COLUMN `$columnName`;";
                }
                continue;
            }

            // Hanterar rollback för andra typer av operationer.
            $rollbackStatements[] = "// TODO: Add rollback logic for: $operation";
        }

        return $rollbackStatements;
    }

    /**
     * Extrahera kolumnnamnet från en `ADD COLUMN`-operation.
     */
    private function extractColumnName(string $operation): ?string
    {
        if (preg_match('/ADD COLUMN `([^`]+)`/', $operation, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param array<int,string> $columns
     */
    private function formatColumnList(array $columns): string
    {
        return implode(', ', array_map(fn($column) => "`$column`", $columns));
    }
}
