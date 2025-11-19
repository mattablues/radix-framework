<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Exception;
use LogicException;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Support\StringHelper;

class HasOne
{
    private Connection $connection;
    private string $modelClass;
    private string $foreignKey;
    private string $localKeyName;
    private ?Model $parent = null;
    private bool $useDefault = false;
    /**
     * @var null|array<string, mixed>|callable
     */
    private $defaultAttributes = null;

    public function __construct(Connection $connection, string $modelClass, string $foreignKey, string $localKeyName)
    {
        $resolvedClass = $this->resolveModelClass($modelClass);
        if (!class_exists($resolvedClass)) {
            throw new Exception("Model class '$resolvedClass' not found.");
        }

        $this->connection = $connection;
        /** @var class-string<Model> $resolvedClass */
        $this->modelClass = $resolvedClass;
        $this->foreignKey = $foreignKey;
        $this->localKeyName = $localKeyName;
    }

    public function setParent(Model $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * @param array<string, mixed>|callable|null $attributes
     */
    public function withDefault(array|callable|null $attributes = null): self
    {
        $this->useDefault = true;
        $this->defaultAttributes = $attributes;
        return $this;
    }

    public function get(): ?Model
    {
        // Om parent finns: hämta värdet från parent
        if ($this->parent !== null) {
            $localValue = $this->parent->getAttribute($this->localKeyName);
            if ($localValue === null) {
                return $this->returnDefaultOrNull();
            }
        } else {
            // Backwards compatibility: tillåt att localKeyName redan är ett värde ('id' numeriskt)
            $localValue = $this->localKeyName;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $this->modelClass;
        $modelInstance = new $modelClass();
        /** @var Model $modelInstance */
        $table = $modelInstance->getTable();

        $query = "SELECT * FROM `$table` WHERE `$this->foreignKey` = ? LIMIT 1";
        $result = $this->connection->fetchOne($query, [$localValue]);

        if ($result !== null) {
            /** @var array<string, mixed> $result */
            return $this->createModelInstance($result, $this->modelClass);
        }

        return $this->returnDefaultOrNull();
    }

    public function first(): ?Model
    {
        return $this->get();
    }

    private function returnDefaultOrNull(): ?Model
    {
        if (!$this->useDefault) {
            return null;
        }

        /** @var class-string<Model> $class */
        $class = $this->modelClass;
        $model = new $class();
        /** @var Model $model */

        // Applicera default
        if (is_array($this->defaultAttributes)) {
            /** @var array<string, mixed> $defaults */
            $defaults = $this->defaultAttributes;
            $model->forceFill($defaults);
        } elseif (is_callable($this->defaultAttributes)) {
            ($this->defaultAttributes)($model);
        }

        // Denna är en ny, icke-existerande post
        $model->markAsNew();

        return $model;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createModelInstance(array $data, string $classOrTable): Model
    {
        $modelClass = $this->resolveModelClass($classOrTable);

        if (!is_subclass_of($modelClass, Model::class)) {
            throw new LogicException(
                "HasOne relation resolved model class '$modelClass' must extend " . Model::class . "."
            );
        }

        /** @var class-string<Model> $modelClass */
        $model = new $modelClass();
        /** @var Model $model */
        $model->hydrateFromDatabase($data);
        $model->markAsExisting();
        return $model;
    }

    /**
     * Hjälpmetod för att lösa det fullständiga modellklassnamnet.
     */
    private function resolveModelClass(string $classOrTable): string
    {
        if (class_exists($classOrTable)) {
            return $classOrTable; // Returnera direkt
        }

        // Använd den delade singulariseringen
        $singularClass = 'App\\Models\\' . ucfirst(StringHelper::singularize($classOrTable));

        if (class_exists($singularClass)) {
            return $singularClass;
        }

        throw new Exception("Model class '$classOrTable' not found. Expected '$singularClass'.");
    }
}
