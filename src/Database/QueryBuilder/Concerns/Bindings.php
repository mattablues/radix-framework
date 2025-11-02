<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait Bindings
{
    public function getBindings(): array
    {
        return $this->bindings;
    }

    protected function setBindings(array $data): void
    {
        $this->bindings = array_values($data);
    }

    protected function setWhereBindings(): void
    {
        $whereBindings = $this->extractWhereBindings();

        $this->bindings = array_merge(
            $this->bindings,
            array_filter($whereBindings, fn($binding) => !in_array($binding, $this->bindings, true))
        );
    }

    protected function getWhereBindings(): array
    {
        $whereBindings = [];
        foreach ($this->where as $condition) {
            if (isset($condition['value']) && $condition['value'] === '?') {
                $whereBindings = array_merge($whereBindings, $this->bindings);
            }
        }
        return $whereBindings;
    }

    protected function extractWhereBindings(): array
    {
        $bindings = [];

        foreach ($this->where as $condition) {
            if ($condition['type'] === 'basic' && $condition['value'] === '?') {
                $bindings[] = $this->bindings[count($bindings)] ?? null;
            }
        }

        return $bindings;
    }
}