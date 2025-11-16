<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

use Radix\Database\QueryBuilder\QueryBuilder;
use Radix\Database\ORM\Model;

trait EagerLoad
{
    /**
     * Registrera relationer som ska eager-loadas.
     *
     * Stödjer:
     * - with('comments')
     * - with(['comments', 'author'])
     * - with(['comments' => function (QueryBuilder $q) { ... }])
     *
     * @param array<int,string>|array<string,\Closure>|string $relations
     */
    public function with(array|string $relations): self
    {
        // Normalisera till array
        if (is_string($relations)) {
            $relations = [$relations];
        }

        $normalized = [];

        foreach ($relations as $key => $value) {
            $relation = null;
            $constraint = null;

            // Assoc-array: ['relation' => Closure]
            if (is_string($key) && $value instanceof \Closure) {
                $relation = $key;
                $constraint = $value;
            } else {
                // Vanlig lista: ['relation1', 'relation2']
                $relation = $value;
            }

            // Hoppa över icke-sträng-relationer
            if (!is_string($relation) || $relation === '') {
                continue;
            }

            // Kontrollera att relationen finns på modelklassen
            $modelClass = $this->modelClass ?? null;
            if ($modelClass === null || !is_subclass_of($modelClass, Model::class)) {
                throw new \LogicException('Model class must be set and extend ' . Model::class . ' before calling with().');
            }

            if (!method_exists($modelClass, $relation)) {
                throw new \InvalidArgumentException(
                    sprintf("Relation '%s' is not defined on model '%s'.", $relation, $modelClass)
                );
            }

            $normalized[] = $relation;

            if ($constraint instanceof \Closure) {
                $this->eagerLoadConstraints[$relation] = $constraint;
            }
        }

        /** @var list<non-empty-string> $normalized */
        $this->eagerLoadRelations = $normalized;

        return $this;
    }

    /**
     * Lägg till/ändra constraint för en given relation.
     */
    public function withConstraint(string $relation, \Closure $constraint): self
    {
        $this->eagerLoadConstraints[$relation] = $constraint;

        // Se till att relationen också är med i eagerLoadRelations
        if (!in_array($relation, $this->eagerLoadRelations, true)) {
            $this->eagerLoadRelations[] = $relation;
        }

        return $this;
    }
}