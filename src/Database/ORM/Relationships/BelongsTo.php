<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Exception;
use LogicException;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Support\StringHelper;

class BelongsTo
{
    private Connection $connection;
    private string $relatedTable;
    private string $foreignKey;
    private string $ownerKey;
    private Model $parentModel;
    private bool $useDefault = false;
    /** @var array<string, mixed>|callable|null */
    private $defaultAttributes = null;

    public function __construct(
        Connection $connection,
        string $relatedTable,
        string $foreignKey,
        string $ownerKey,
        Model $parentModel
    ) {
        $this->connection = $connection;
        $this->relatedTable = $relatedTable;
        $this->foreignKey = $foreignKey;
        $this->ownerKey = $ownerKey;
        $this->parentModel = $parentModel;
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
        // Hämta värdet av foreignKey från den aktuella modellens attribut
        $foreignKeyValue = $this->getParentModelAttribute($this->foreignKey);

        if ($foreignKeyValue === null) {
            return $this->returnDefaultOrNull();
        }

        $query = "SELECT * FROM `$this->relatedTable` WHERE `$this->ownerKey` = ? LIMIT 1";
        $result = $this->connection->fetchOne($query, [$foreignKeyValue]); // Använder rätt värde här

        if ($result === null) {
            return $this->returnDefaultOrNull(); // Inget resultat från databasen
        }

        return $this->createModelInstance($result, $this->relatedTable); // Skapa modellinstans
    }

    public function first(): ?Model
    {
        $result = $this->get(); // Hämta en enda relaterad post

        if (!$result) {
            return $this->returnDefaultOrNull();
        }

        return $result; // Returera modellen direkt
    }

    private function returnDefaultOrNull(): ?Model
    {
        if (!$this->useDefault) {
            return null;
        }

        $modelClass = $this->resolveModelClass($this->relatedTable);
        /** @var Model $model */
        $model = new $modelClass();

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

    private function getParentModelAttribute(string $attribute): mixed
    {
        // Försäkra att modellen har attributet
        if (property_exists($this, 'parentModel')) {
            return $this->parentModel->getAttribute($attribute);
        }

        throw new Exception("Unable to access the foreign key attribute '$attribute' on the parent model.");
    }

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

    /**
     * @param array<string, mixed> $data
     */
    private function createModelInstance(array $data, string $classOrTable): Model
    {
        $modelClass = $this->resolveModelClass($classOrTable);

        if (!is_subclass_of($modelClass, Model::class)) {
            throw new LogicException(
                "BelongsTo relation resolved model class '$modelClass' måste ärva " . Model::class . "."
            );
        }

        /** @var class-string<Model> $modelClass */
        $model = new $modelClass();
        /** @var Model $model */
        $model->hydrateFromDatabase($data);
        $model->markAsExisting();

        return $model;
    }
}
