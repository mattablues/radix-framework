<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait SoftDeletes
{
    public function withSoftDeletes(): self
    {
        $this->withSoftDeletes = true;

        $this->where = array_filter($this->where, function ($condition) {
            return !(
                $condition['type'] === 'raw' &&
                $condition['column'] === $this->wrapColumn('deleted_at') &&
                $condition['operator'] === 'IS NULL'
            );
        });

        return $this;
    }

    public function getWithSoftDeletes(): bool
    {
        return $this->withSoftDeletes;
    }

    public function getOnlySoftDeleted(): self
    {
        return $this->whereNotNull('deleted_at');
    }

    public function onlyTrashed(): self
    {
        return $this->getOnlySoftDeleted();
    }

    public function withoutTrashed(): self
    {
        // default-beteende: se endast ej soft-deletade
        return $this->whereNull('deleted_at');
    }
}