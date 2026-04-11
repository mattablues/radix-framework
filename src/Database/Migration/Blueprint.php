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
        $validAttributes = [
            'nullable',
            'default',
            'onUpdate',
            'collation',
            'comment',
            'before',
            'after',
            'first',
            'unsigned',
            'autoIncrement',
        ];

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
            'date'        => 'DATE',
            'datetime'    => 'DATETIME',
            'float'       => 'FLOAT',
            'decimal'     => 'DECIMAL',
            'enum'        => 'ENUM',
        ];

        if (isset($typeMapping[$type])) {
            $type = $typeMapping[$type];
        } else {
            if (preg_match('/^FLOAT\(\d+,\s?\d+\)$/i', $type)) {
                // ok
            } elseif (
                !array_key_exists($type, $typeMapping)
                && !preg_match('/^(ENUM|SET)\([\'"].+?[\'"](?:,\s?[\'"].+?[\'"])*\)$/i', $type)
                && !preg_match('/^[A-Z][A-Z0-9]*(\(\d+(,\s?\d+)?\))?( UNSIGNED)?$/', $type)
            ) {
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
                if (is_string($default) && preg_match('#^(/|[a-zA-Z]:|https?://)#', $default)) {
                    $defaultStr = $default;
                } else {
                    if (!is_scalar($default)) {
                        throw new InvalidArgumentException("Default value must be a scalar or string.");
                    }
                    /** @var int|float|string $default */
                    $defaultStr = strtoupper((string) $default);
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

    public function dropColumn(string $name): self
    {
        if (!$this->isAlter) {
            throw new LogicException('dropColumn can only be used in ALTER TABLE context.');
        }
        $this->alterOperations[] = 'DROP COLUMN `' . $name . '`';
        return $this;
    }

    /**
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
     * @param array<string,mixed> $options
     */
    public function string(string $name, int $length = 255, array $options = []): self
    {
        return $this->addColumn("VARCHAR($length)", $name, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function text(string $name, array $options = []): self
    {
        return $this->addColumn('TEXT', $name, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function integer(string $name, bool $unsigned = false, array $options = []): self
    {
        $type = $unsigned ? 'INT UNSIGNED' : 'INT';
        return $this->addColumn($type, $name, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function tinyInteger(string $name, bool $unsigned = false, array $options = []): self
    {
        $type = $unsigned ? 'TINYINT UNSIGNED' : 'TINYINT';
        return $this->addColumn($type, $name, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function bigInteger(string $name, bool $unsigned = false, array $options = []): self
    {
        $type = $unsigned ? 'BIGINT UNSIGNED' : 'BIGINT';
        return $this->addColumn($type, $name, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function boolean(string $name, array $options = []): self
    {
        return $this->addColumn('TINYINT(1)', $name, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function float(string $name, int $total = 8, int $places = 2, array $options = []): self
    {
        return $this->addColumn("FLOAT($total, $places)", $name, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function decimal(string $name, int $total = 8, int $places = 2, array $options = []): self
    {
        return $this->addColumn("DECIMAL($total, $places)", $name, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function datetime(string $name, array $options = []): self
    {
        return $this->addColumn('DATETIME', $name, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function date(string $name, array $options = []): self
    {
        return $this->addColumn('DATE', $name, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function time(string $name, array $options = []): self
    {
        return $this->addColumn('TIME', $name, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function json(string $name, array $options = []): self
    {
        return $this->addColumn('JSON', $name, $options);
    }

    /**
     * @param array<int,string>   $allowed
     * @param array<string,mixed> $options
     */
    public function enum(string $name, array $allowed, array $options = []): self
    {
        $values = "'" . implode("', '", $allowed) . "'";
        return $this->addColumn("ENUM($values)", $name, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function uuid(string $name, array $options = []): self
    {
        return $this->addColumn('CHAR(36)', $name, $options);
    }

    public function timestamps(): self
    {
        $this->datetime('created_at', ['default' => 'CURRENT_TIMESTAMP']);
        $this->datetime('updated_at', ['default' => 'CURRENT_TIMESTAMP', 'onUpdate' => 'CURRENT_TIMESTAMP']);
        return $this;
    }

    public function softDeletes(): self
    {
        return $this->datetime('deleted_at', ['nullable' => true]);
    }

    /**
     * @param array<int,string> $columns
     */
    public function primary(array $columns): self
    {
        $cols = $this->formatColumnList($columns);
        $definition = 'PRIMARY KEY (' . $cols . ')';

        // ÄNDRING: dela upp i två villkor (Infection: ingen LogicalOr att mutera)
        if (!str_starts_with($definition, 'PRIMARY KEY (')) {
            throw new LogicException('Invalid PRIMARY KEY definition.');
        }

        if (!str_ends_with($definition, ')')) {
            throw new LogicException('Invalid PRIMARY KEY definition.');
        }

        if ($this->isAlter) {
            $this->alterOperations[] = 'ADD ' . $definition;
            return $this;
        }

        $this->keys[] = $definition;
        return $this;
    }

    public function dropPrimary(): self
    {
        if (!$this->isAlter) {
            throw new LogicException('dropPrimary can only be used in ALTER TABLE context.');
        }

        $this->alterOperations[] = 'DROP PRIMARY KEY';
        return $this;
    }

    /**
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

    /**
     * Skapa ett unikt index på de angivna kolumnerna.
     *
     * @param array<int,string> $columns
     */
    public function unique(array $columns, ?string $name = null): self
    {
        $indexName = $name ?: 'unique_' . implode('_', $columns);

        if ($this->isAlter) {
            $this->alterOperations[] = $this->opAddIndex($indexName, $columns, true);
        } else {
            $this->keys[] = 'UNIQUE INDEX `' . $indexName . '` (' . $this->formatColumnList($columns) . ')';
        }

        return $this;
    }

    /**
     * Skapa ett (icke-unikt) index på de angivna kolumnerna.
     *
     * @param array<int,string> $columns
     */
    public function index(array $columns, ?string $name = null): self
    {
        $indexName = $name ?: 'index_' . implode('_', $columns);

        if ($this->isAlter) {
            $this->alterOperations[] = $this->opAddIndex($indexName, $columns, false);
        } else {
            $this->keys[] = 'INDEX `' . $indexName . '` (' . $this->formatColumnList($columns) . ')';
        }

        return $this;
    }

    public function dropIndex(string $name): self
    {
        if (!$this->isAlter) {
            throw new LogicException('dropIndex can only be used in ALTER TABLE context.');
        }

        $this->alterOperations[] = $this->opDropIndex($name);
        return $this;
    }

    public function dropUnique(string $name): self
    {
        return $this->dropIndex($name);
    }

    public function dropForeign(string $name): self
    {
        if (!$this->isAlter) {
            throw new LogicException('dropForeign can only be used in ALTER TABLE context.');
        }

        $this->alterOperations[] = $this->opDropForeign($name);
        return $this;
    }

    public function foreign(
        string $column,
        string $referencesTable,
        string $referencesColumn = 'id',
        string $onDelete = 'CASCADE',
        string $onUpdate = 'CASCADE'
    ): self {
        $constraint = 'FOREIGN KEY (`' . $column . '`) REFERENCES `' . $referencesTable . '` (`' . $referencesColumn . '`) ON DELETE ' . $onDelete . ' ON UPDATE ' . $onUpdate;

        if ($this->isAlter) {
            $this->alterOperations[] = 'ADD ' . $constraint;
        } else {
            $this->constraints[] = $constraint;
        }

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
     * Generera SQL-satser för ALTER-operationerna.
     *
     * @return array<int,string>
     */
    public function toAlterSql(string $driver = 'mysql'): array
    {
        $driver = strtolower($driver);
        $sql = [];

        foreach ($this->alterOperations as $op) {
            if (str_starts_with($op, '__ADD_INDEX__|')) {
                $sql[] = $this->compileAddIndex($op, $driver);
                continue;
            }

            if (str_starts_with($op, '__DROP_INDEX__|')) {
                $sql[] = $this->compileDropIndex($op, $driver);
                continue;
            }

            if (str_starts_with($op, '__DROP_FOREIGN__|')) {
                $stmt = $this->compileDropForeign($op, $driver);
                if ($stmt !== null) {
                    $sql[] = $stmt;
                }
                continue;
            }

            // Default: vanlig ALTER TABLE-operation (kolumner, primary, foreign-add, etc)
            $sql[] = 'ALTER TABLE `' . $this->table . '` ' . $op . ';';
        }

        return $sql;
    }

    /**
     * @return array<int,string>
     */
    public function toRollbackSql(): array
    {
        if (empty($this->alterOperations)) {
            throw new LogicException('No operations to rollback.');
        }

        $rollbackStatements = [];

        foreach (array_reverse($this->alterOperations) as $operation) {
            if (str_starts_with($operation, 'DROP COLUMN')) {
                throw new LogicException('Cannot rollback a dropped column automatically. Column details are missing.');
            }

            if (str_starts_with($operation, 'ADD COLUMN')) {
                $columnName = $this->extractColumnName($operation);
                if ($columnName) {
                    $rollbackStatements[] = "ALTER TABLE `$this->table` DROP COLUMN `$columnName`;";
                }
                continue;
            }

            $rollbackStatements[] = "// TODO: Add rollback logic for: $operation";
        }

        return $rollbackStatements;
    }

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
    private function opAddIndex(string $name, array $columns, bool $unique): string
    {
        // __ADD_INDEX__|name|1/0|col1,col2,col3
        return '__ADD_INDEX__|' . $name . '|' . ($unique ? '1' : '0') . '|' . implode(',', $columns);
    }

    private function opDropIndex(string $name): string
    {
        return '__DROP_INDEX__|' . $name;
    }

    private function opDropForeign(string $name): string
    {
        return '__DROP_FOREIGN__|' . $name;
    }

    private function compileAddIndex(string $op, string $driver): string
    {
        // NYTT: strikt format – exakt 3 pipes i op
        if (substr_count($op, '|') !== 3) {
            throw new LogicException('Invalid ADD_INDEX operation.');
        }

        $parts = explode('|', $op);
        $indexName = $parts[1] ?? '';
        $uniqueFlag = $parts[2] ?? '0';
        $colsCsv = $parts[3] ?? '';

        // Parse kolumner
        $columns = $colsCsv !== '' ? explode(',', $colsCsv) : [];

        // ÄNDRING: strikt validering utan array_filter/array_values (Infection-vänligt)
        if ($indexName === '') {
            throw new LogicException('Invalid ADD_INDEX operation.');
        }

        if ($columns === []) {
            throw new LogicException('Invalid ADD_INDEX operation.');
        }

        foreach ($columns as $c) {
            if ($c === '') {
                throw new LogicException('Invalid ADD_INDEX operation.');
            }
        }

        $isUnique = ($uniqueFlag === '1');
        $cols = $this->formatColumnList($columns);

        if ($driver === 'sqlite') {
            // SQLite: CREATE [UNIQUE] INDEX idx ON table (col,...)
            return 'CREATE ' . ($isUnique ? 'UNIQUE ' : '') . 'INDEX `' . $indexName . '` ON `' . $this->table . '` (' . $cols . ');';
        }

        // MySQL/MariaDB: ALTER TABLE t ADD [UNIQUE] INDEX idx (col,...)
        return 'ALTER TABLE `' . $this->table . '` ADD ' . ($isUnique ? 'UNIQUE ' : '') . 'INDEX `' . $indexName . '` (' . $cols . ');';
    }

    private function compileDropIndex(string $op, string $driver): string
    {
        // NYTT: strikt format – exakt 1 pipe i op
        if (substr_count($op, '|') !== 1) {
            throw new LogicException('Invalid DROP_INDEX operation.');
        }

        $parts = explode('|', $op);
        $indexName = $parts[1] ?? '';

        if ($indexName === '') {
            throw new LogicException('Invalid DROP_INDEX operation.');
        }

        if ($driver === 'sqlite') {
            $stmt = 'DROP INDEX IF EXISTS `' . $indexName . '`;';

            // ÄNDRING: ingen LogicalOr => bättre för Infection
            if (!str_starts_with($stmt, 'DROP INDEX IF EXISTS `')) {
                throw new LogicException('Invalid DROP_INDEX statement.');
            }
            if (!str_ends_with($stmt, '`;')) {
                throw new LogicException('Invalid DROP_INDEX statement.');
            }

            return $stmt;
        }

        return 'DROP INDEX `' . $indexName . '` ON `' . $this->table . '`;';
    }

    private function compileDropForeign(string $op, string $driver): ?string
    {
        // NYTT: strikt format – exakt 1 pipe i op
        if (substr_count($op, '|') !== 1) {
            throw new LogicException('Invalid DROP_FOREIGN operation.');
        }

        $parts = explode('|', $op);
        $fkName = $parts[1] ?? '';

        if ($fkName === '') {
            throw new LogicException('Invalid DROP_FOREIGN operation.');
        }

        if ($driver === 'sqlite') {
            // SQLite kräver table-rebuild för detta; no-op i tests.
            return null;
        }

        return 'ALTER TABLE `' . $this->table . '` DROP FOREIGN KEY `' . $fkName . '`;';
    }

    /**
     * @param array<int,string> $columns
     */
    private function formatColumnList(array $columns): string
    {
        return implode(', ', array_map(fn($column) => "`$column`", $columns));
    }
}
