<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Exception;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\ModelClassResolverInterface;
use RuntimeException;

class BelongsTo
{
    private Connection $connection;

    /**
     * Kan vara antingen:
     *  - class-string<Model> (t.ex. Domain\Models\User::class)
     *  - tabellnamn (t.ex. 'users')
     */
    private string $relatedModelOrTable;

    /**
     * Behövs p.g.a. QueryBuilder WithCount använder Reflection och förväntar sig propertyn `relatedTable`.
     * @phpstan-ignore property.onlyWritten
     */
    private string $relatedTable;

    private string $foreignKey;
    private string $ownerKey;
    private Model $parentModel;

    private bool $useDefault = false;

    /** @var array<string, mixed>|callable|null */
    private $defaultAttributes = null;

    public function __construct(
        Connection $connection,
        string $relatedModelOrTable,
        string $foreignKey,
        string $ownerKey,
        Model $parentModel,
        private readonly ?ModelClassResolverInterface $modelClassResolver = null
    ) {
        $this->connection = $connection;
        $this->relatedModelOrTable = $relatedModelOrTable;
        $this->foreignKey = $foreignKey;
        $this->ownerKey = $ownerKey;
        $this->parentModel = $parentModel;

        // Sätt relatedTable direkt så WithCount kan läsa den via Reflection
        if (strpos($relatedModelOrTable, '\\') !== false) {
            if (!class_exists($relatedModelOrTable)) {
                throw new Exception("Model class '{$relatedModelOrTable}' not found.");
            }
            if (!is_subclass_of($relatedModelOrTable, Model::class, true)) {
                throw new Exception("Model class '{$relatedModelOrTable}' must exist and extend " . Model::class . '.');
            }

            /** @var class-string<Model> $relatedModelOrTable */
            $tmp = new $relatedModelOrTable();
            /** @var Model $tmp */
            $this->relatedTable = $tmp->getTable();
        } else {
            $this->relatedTable = $relatedModelOrTable;
        }
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
        $foreignKeyValue = $this->parentModel->getAttribute($this->foreignKey);

        if ($foreignKeyValue === null) {
            return $this->returnDefaultOrNull();
        }

        [$relatedClass, $relatedTable] = $this->resolveRelatedClassAndTable();

        $query = "SELECT * FROM `$relatedTable` WHERE `$this->ownerKey` = ? LIMIT 1";
        $result = $this->connection->fetchOne($query, [$foreignKeyValue]);

        if ($result === null) {
            return $this->returnDefaultOrNull();
        }

        return $this->createModelInstance($result, $relatedClass);
    }

    public function first(): ?Model
    {
        $result = $this->get();
        return $result ?: $this->returnDefaultOrNull();
    }

    private function returnDefaultOrNull(): ?Model
    {
        if (!$this->useDefault) {
            return null;
        }

        [$relatedClass] = $this->resolveRelatedClassAndTable();

        /** @var class-string<Model> $relatedClass */
        $model = new $relatedClass();
        /** @var Model $model */

        if (is_array($this->defaultAttributes)) {
            /** @var array<string, mixed> $defaults */
            $defaults = $this->defaultAttributes;
            $model->forceFill($defaults);
        } elseif (is_callable($this->defaultAttributes)) {
            ($this->defaultAttributes)($model);
        }

        $model->markAsNew();
        return $model;
    }

    /**
     * @return array{0: class-string<Model>, 1: string} [relatedModelClass, relatedTable]
     */
    private function resolveRelatedClassAndTable(): array
    {
        // 1) Om vi redan fick en FQCN: använd den
        if (strpos($this->relatedModelOrTable, '\\') !== false) {
            $cls = $this->relatedModelOrTable;

            if (!class_exists($cls)) {
                throw new Exception("Model class '{$cls}' not found.");
            }
            if (!is_subclass_of($cls, Model::class, true)) {
                throw new Exception("Model class '{$cls}' must exist and extend " . Model::class . '.');
            }

            /** @var class-string<Model> $cls */
            $tmp = new $cls();
            /** @var Model $tmp */
            return [$cls, $tmp->getTable()];
        }

        // 2) Annars: vi fick tabellnamn
        $table = $this->relatedModelOrTable;
        $cls = $this->resolveModelClassFromTable($table);

        return [$cls, $table];
    }

    /**
     * @return class-string<Model>
     */
    private function resolveModelClassFromTable(string $table): string
    {
        if ($this->modelClassResolver !== null) {
            $cls = $this->modelClassResolver->resolve($table);
        } else {
            throw new RuntimeException(
                'ModelClassResolverInterface is not configured for relationship. Provide a resolver or pass a FQCN.'
            );
        }

        if (!class_exists($cls)) {
            throw new Exception("Model class '{$cls}' not found.");
        }
        if (!is_subclass_of($cls, Model::class, true)) {
            throw new Exception("Model class '{$cls}' must exist and extend " . Model::class . '.');
        }

        /** @var class-string<Model> $cls */
        return $cls;
    }

    /**
     * @param array<string, mixed> $data
     * @param class-string<Model>  $relatedClass
     */
    private function createModelInstance(array $data, string $relatedClass): Model
    {
        /** @var class-string<Model> $relatedClass */
        $model = new $relatedClass();
        /** @var Model $model */
        $model->hydrateFromDatabase($data);
        $model->markAsExisting();
        return $model;
    }
}
