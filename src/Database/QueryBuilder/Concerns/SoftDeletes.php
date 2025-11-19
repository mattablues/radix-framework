<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait SoftDeletes
{
    public function withSoftDeletes(): self
    {
        $this->withSoftDeletes = true;

        $this->where = array_filter(
            $this->where,
            function ($condition): bool {
                // Säkerställ att vi bara jobbar med "where"-villkor som är arrayer
                if (!is_array($condition)) {
                    return true;
                }

                // Kräv nödvändiga nycklar, annars behåll villkoret
                if (
                    !array_key_exists('type', $condition)
                    || !array_key_exists('column', $condition)
                    || !array_key_exists('operator', $condition)
                ) {
                    return true;
                }

                return !(
                    $condition['type'] === 'raw'
                    && $condition['column'] === $this->wrapColumn('deleted_at')
                    && $condition['operator'] === 'IS NULL'
                );
            }
        );

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
