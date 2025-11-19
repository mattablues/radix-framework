<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

use InvalidArgumentException;

trait Locks
{
    /** @var null|'for_update'|'share' */
    protected ?string $lockMode = null;

    public function forUpdate(bool $enable = true): self
    {
        $this->lockMode = $enable ? 'for_update' : null;
        return $this;
    }

    public function lockInShareMode(bool $enable = true): self
    {
        $this->lockMode = $enable ? 'share' : null;
        return $this;
    }

    public function lock(string $mode): self
    {
        $mode = strtolower($mode);
        if (!in_array($mode, ['update', 'share'], true)) {
            throw new InvalidArgumentException("Ogiltigt låsläge: $mode");
        }
        $this->lockMode = $mode === 'update' ? 'for_update' : 'share';
        return $this;
    }

    protected function compileLockSuffix(): string
    {
        if ($this->lockMode === 'for_update') {
            return ' FOR UPDATE';
        }
        if ($this->lockMode === 'share') {
            return ' LOCK IN SHARE MODE';
        }
        return '';
    }
}
