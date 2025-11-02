<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

use Radix\Database\QueryBuilder\QueryBuilder;

trait Unions
{
    /**
     * Lägg till en UNION eller UNION ALL med en annan fråga.
     *
     * @param string|QueryBuilder $query SQL-sträng eller en QueryBuilder vars SQL används
     * @param bool $all true för UNION ALL, false för UNION
     * @return $this
     */
    public function union(string|QueryBuilder $query, bool $all = false): self
    {
        if ($query instanceof QueryBuilder) {
            $this->bindings = array_merge($this->bindings, $query->getBindings());
            $query = $query->toSql();
        }

        $this->unions[] = ($all ? 'UNION ALL ' : 'UNION ') . $query;
        return $this;
    }
}