<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait JsonFunctions
{
    protected ?string $whereRawString = null;

    // Säkert sätt att hämta driver-namn via PDO::getAttribute
    protected function getDriverName(): string
    {
        try {
            $connection = $this->getConnection(); // garanterar Connection, ej null

            /** @var \PDO $pdo */
            $pdo = $connection->getPDO(); // Connection har alltid getPDO()
            $name = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            return is_string($name) && $name !== '' ? $name : 'mysql';
        } catch (\Throwable) {
            // Vid fel: fallback till mysql
            return 'mysql';
        }
    }

    public function jsonExtract(string $column, string $path, ?string $alias = null): self
    {
        $driver = $this->getDriverName();
        $wrapped = $this->wrapColumn($column);

        // MySQL/MariaDB resp. SQLite
        $expr = ($driver === 'sqlite')
            ? "json_extract($wrapped, ?)"
            : "JSON_EXTRACT($wrapped, ?)";

        $this->addSelectBinding($path);
        $this->columns[] = $alias ? ($expr . ' AS ' . $this->wrapAlias($alias)) : $expr;
        return $this;
    }

    public function whereJsonContains(string $column, mixed $needle, string $boolean = 'AND'): self
    {
        $driver = $this->getDriverName();
        $wrapped = $this->wrapColumn($column);

        if ($driver === 'sqlite') {
            $expr = "$wrapped LIKE ?";
            $binding = '%' . (string)$needle . '%';
        } else {
            $expr = "$wrapped JSON_CONTAINS ?";
            $binding = json_encode($needle, JSON_UNESCAPED_UNICODE);
        }

        // Skriv rå WHERE-del (utan parenteser)
        $this->whereRawString = is_string($this->whereRawString ?? null) ? $this->whereRawString : '';
        $this->whereRawString = $this->whereRawString === ''
            ? $expr
            : ($this->whereRawString . ' ' . strtoupper($boolean) . ' ' . $expr);

        // Viktigt: lägg needle i select-bucket för att komma efter path i bindings
        $this->addSelectBinding($binding);

        return $this;
    }

    public function whereJsonPath(string $column, string $path, string $operator, mixed $value, string $boolean = 'AND'): self
    {
        $driver = $this->getDriverName();
        $wrapped = $this->wrapColumn($column);
        $op = strtoupper($operator);

        $func = $driver === 'sqlite' ? 'json_extract' : 'JSON_EXTRACT';
        $expr = "$func($wrapped, ?) $op ?";

        $this->whereRawString = is_string($this->whereRawString ?? null) ? $this->whereRawString : '';
        if ($this->whereRawString === '') {
            $this->whereRawString = $expr;
        } else {
            $this->whereRawString .= ' ' . strtoupper($boolean) . ' ' . $expr;
        }

        $this->addWhereBinding($path);
        $this->addWhereBinding($value);
        return $this;
    }
}