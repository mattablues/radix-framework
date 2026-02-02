<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use LogicException;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\ModelClassResolverInterface;
use Radix\Database\ORM\Relationships\Concerns\EnsuresModelClassLoaded;
use RuntimeException;

class HasOneThrough
{
    use EnsuresModelClassLoaded;

    private Connection $connection;
    private string $related;     // Klassnamn ELLER tabellnamn
    private string $through;     // Klassnamn ELLER tabellnamn
    private string $firstKey;    // t.ex. subjects.category_id
    private string $secondKey;   // t.ex. votes.subject_id
    private string $localKey;    // t.ex. categories.id
    private string $secondLocal; // t.ex. subjects.id
    private ?Model $parent = null;

    public function __construct(
        Connection $connection,
        string $related,
        string $through,
        string $firstKey,
        string $secondKey,
        string $localKey = 'id',
        string $secondLocal = 'id',
        private readonly ?ModelClassResolverInterface $modelClassResolver = null
    ) {
        $this->connection  = $connection;
        $this->related     = $related;
        $this->through     = $through;
        $this->firstKey    = $firstKey;
        $this->secondKey   = $secondKey;
        $this->localKey    = $localKey;
        $this->secondLocal = $secondLocal;
    }

    public function setParent(Model $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * Lägg till denna metod för att stödja eager loading via QueryBuilder
     */
    public function get(): ?Model
    {
        return $this->first();
    }

    public function first(): ?Model
    {
        if ($this->parent === null) {
            throw new LogicException('HasOneThrough parent saknas.');
        }

        /** @var Model $parent */
        $parent = $this->parent;

        $relatedClass = $this->resolveModelClass($this->related);
        $throughClass = $this->resolveModelClass($this->through);

        $this->ensureModelClassLoaded($relatedClass);
        $this->ensureModelClassLoaded($throughClass);

        // OBS: Ingen is_subclass_of-check här längre.
        // Validering sker centralt i Model::resolveRelatedModelClass() innan relationsobjektet skapas.

        /** @var class-string<Model> $relatedClass */
        /** @var class-string<Model> $throughClass */
        $relatedModel = new $relatedClass();
        $throughModel = new $throughClass();

        /** @var Model $relatedModel */
        /** @var Model $throughModel */
        $relatedTable = $relatedModel->getTable();
        $throughTable = $throughModel->getTable();

        $parentValue = $parent->getAttribute($this->localKey);
        if ($parentValue === null) {
            return null;
        }

        $sql = "SELECT r.*
                  FROM `$relatedTable` r
                  JOIN `$throughTable` t ON t.`$this->secondLocal` = r.`$this->secondKey`
                 WHERE t.`$this->firstKey` = ?
                 LIMIT 1";

        $row = $this->connection->fetchOne($sql, [$parentValue]);
        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $this->createModelInstance($row, $relatedClass);
    }

    private function resolveModelClass(string $classOrTable): string
    {
        if (strpos($classOrTable, '\\') !== false) {
            return $classOrTable;
        }

        if ($this->modelClassResolver !== null) {
            return $this->modelClassResolver->resolve($classOrTable);
        }

        throw new RuntimeException(
            'ModelClassResolverInterface is not configured for relationship. Provide a resolver or pass a FQCN.'
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param class-string<Model>  $modelClass
     */
    private function createModelInstance(array $data, string $modelClass): Model
    {
        /** @var class-string<Model> $modelClass */
        $model = new $modelClass();
        $model->hydrateFromDatabase($data);
        $model->markAsExisting();
        return $model;
    }
}
