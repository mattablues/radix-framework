<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

use Radix\Database\QueryBuilder\QueryBuilder;

trait Unions
{
    public function union(string|QueryBuilder $query, bool $all = false): self
    {
        if ($query instanceof QueryBuilder) {
            $this->addUnionBindings($query->getBindings());
            $query = $query->toSql();
        }

        $this->unions[] = ($all ? 'UNION ALL ' : 'UNION ') . $query;
        return $this;
    }

    public function unionAll(string|QueryBuilder $query): self
    {
        return $this->union($query, true);
    }
}