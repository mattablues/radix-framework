<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Exception;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\ModelClassResolverInterface;
use Radix\Database\ORM\Relationships\Concerns\EnsuresModelClassLoaded;
use ReflectionClass;

class HasMany
{
    use EnsuresModelClassLoaded;

    private Connection $connection;
    /** @var class-string<Model> */
    private string $modelClass;
    private string $foreignKey;
    private string $localKeyName;
    private ?Model $parent = null;

    public function __construct(
        Connection $connection,
        string $modelClass,
        string $foreignKey,
        string $localKeyName,
        private readonly ?ModelClassResolverInterface $modelClassResolver = null
    ) {
        $resolvedClass = $this->resolveModelClass($modelClass);

        $this->ensureModelClassLoaded($resolvedClass);

        if (!is_subclass_of($resolvedClass, Model::class, true)) {
            throw new Exception(
                "Model class '" . $resolvedClass . "' must exist and extend " . Model::class . '.'
            );
        }

        $this->connection   = $connection;
        /** @var class-string<Model> $resolvedClass */
        $this->modelClass   = $resolvedClass;
        $this->foreignKey   = $foreignKey;
        $this->localKeyName = $localKeyName;
    }

    public function setParent(Model $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * @return array<int, Model>
     */
    public function get(): array
    {
        // Hämta local key-värde från parent om satt, annars använd localKeyName som värde (backcompat)
        if ($this->parent !== null) {
            $localValue = $this->parent->getAttribute($this->localKeyName);
            if ($localValue === null) {
                return [];
            }
        } else {
            $localValue = $this->localKeyName;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass   = $this->modelClass;
        $modelInstance = new $modelClass();
        /** @var Model $modelInstance */
        $table        = $modelInstance->getTable();

        $sql = "SELECT * FROM `$table` WHERE `$this->foreignKey` = ?";
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->connection->fetchAll($sql, [$localValue]);

        /** @var array<int, Model> $results */
        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->createModelInstance($row, $this->modelClass);
        }

        return $results;
    }

    public function first(): ?Model
    {
        $rows = $this->get();
        if ($rows === []) {
            return null;
        }
        return $rows[0];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createModelInstance(array $data, string $classOrTable): Model
    {
        $modelClass = $this->modelClass;

        /** @var class-string<Model> $modelClass */
        $model = new $modelClass();
        /** @var Model $model */
        $model->hydrateFromDatabase($data);
        $model->markAsExisting();

        if ($this->parent !== null) {
            $model->setRelation(
                strtolower((new ReflectionClass($this->parent))->getShortName()),
                $this->parent
            );
        }

        return $model;
    }

    /**
     * Hjälpmetod för att lösa modellklass från klassnamn eller tabellnamn.
     */
    private function resolveModelClass(string $classOrTable): string
    {
        // 0) Om klassen redan är laddad: använd den direkt (ingen autoload här)
        if (class_exists($classOrTable, false)) {
            return $classOrTable;
        }

        // 1) FQCN → direkt (ingen autoload här)
        if (strpos($classOrTable, '\\') !== false) {
            return $classOrTable;
        }

        // 2) Resolver (map/konvention)
        if ($this->modelClassResolver !== null) {
            return $this->modelClassResolver->resolve($classOrTable);
        }

        // 3) Fallback-konvention
        return 'App\\Models\\' . ucfirst(\Radix\Support\StringHelper::singularize($classOrTable));
    }
}
