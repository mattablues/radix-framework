<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait Bindings
{
    public function getBindings(): array
    {
        // Se till att bindings är uppdaterade från buckets
        if (method_exists($this, 'compileAllBindings')) {
            $this->compileAllBindings();
        }
        return $this->bindings;
    }

    public function clearBindings(): void
    {
        $this->bindings = [];
        $this->bindingsSelect = [];
        $this->bindingsWhere = [];
        $this->bindingsJoin = [];
        $this->bindingsHaving = [];
        $this->bindingsOrder = [];
        $this->bindingsUnion = [];
        $this->bindingsMutation = [];
    }

    public function mergeBindings(self $query): void
    {
        // Slå ihop buckets var för sig
        $this->bindingsSelect = array_merge($this->bindingsSelect, $query->bindingsSelect ?? []);
        $this->bindingsJoin   = array_merge($this->bindingsJoin,   $query->bindingsJoin   ?? []);
        $this->bindingsWhere  = array_merge($this->bindingsWhere,  $query->bindingsWhere  ?? []);
        $this->bindingsHaving = array_merge($this->bindingsHaving, $query->bindingsHaving ?? []);
        $this->bindingsOrder  = array_merge($this->bindingsOrder,  $query->bindingsOrder  ?? []);
        $this->bindingsUnion  = array_merge($this->bindingsUnion,  $query->bindingsUnion  ?? []);
        $this->bindingsMutation = array_merge($this->bindingsMutation, $query->bindingsMutation ?? []);
    }

    // Hjälpare för att lägga bindings i rätt bucket
    protected function addSelectBinding(mixed $value): void
    {
        $this->bindingsSelect[] = $value;
    }

    protected function addWhereBinding(mixed $value): void
    {
        $this->bindingsWhere[] = $value;
    }

    protected function addWhereBindings(array $values): void
    {
        foreach ($values as $v) {
            $this->bindingsWhere[] = $v;
        }
    }

    protected function addJoinBinding(mixed $value): void
    {
        $this->bindingsJoin[] = $value;
    }

    protected function addJoinBindings(array $values): void
    {
        foreach ($values as $v) {
            $this->bindingsJoin[] = $v;
        }
    }

    protected function addHavingBinding(mixed $value): void
    {
        $this->bindingsHaving[] = $value;
    }

    protected function addHavingBindings(array $values): void
    {
        foreach ($values as $v) {
            $this->bindingsHaving[] = $v;
        }
    }

    protected function addOrderBinding(mixed $value): void
    {
        $this->bindingsOrder[] = $value;
    }

    protected function addUnionBindings(array $values): void
    {
        foreach ($values as $v) {
            $this->bindingsUnion[] = $v;
        }
    }

    protected function addMutationBindings(array $values): void
    {
        foreach ($values as $v) {
            $this->bindingsMutation[] = $v;
        }
    }
}