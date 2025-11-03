<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait EagerLoad
{
    public function with(array|string $relations): self
    {
        if (!$this->modelClass) {
            throw new \LogicException('Model class is not set. Use setModelClass() to assign a model.');
        }

        if (!is_array($relations)) {
            $relations = func_get_args();
        }

        foreach ($relations as $key => $value) {
            if (is_int($key)) {
                $relation = $value;
                if (!method_exists($this->modelClass, $relation)) {
                    throw new \InvalidArgumentException("Relation '$relation' is not defined in the model '$this->modelClass'.");
                }
                $this->eagerLoadRelations[] = $relation;
            } else {
                $relation = $key;
                if (!method_exists($this->modelClass, $relation)) {
                    throw new \InvalidArgumentException("Relation '$relation' is not defined in the model '$this->modelClass'.");
                }
                $this->eagerLoadRelations[] = $relation;

                if ($value instanceof \Closure) {
                    $this->eagerLoadConstraints[$relation] = $value;
                } else {
                    throw new \InvalidArgumentException("The value for with('$relation') must be a Closure.");
                }
            }
        }

        $this->eagerLoadRelations = array_values(array_unique($this->eagerLoadRelations));
        return $this;
    }
}