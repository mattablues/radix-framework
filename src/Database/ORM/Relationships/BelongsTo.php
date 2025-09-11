<?php

declare(strict_types=1);

namespace Radix\Database\ORM\Relationships;

use Radix\Database\Connection;
use Radix\Database\ORM\Model;

class BelongsTo
{
    private Connection $connection;
    private string $relatedTable;
    private string $foreignKey;
    private string $ownerKey;
    private Model $parentModel;

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

    public function get(): ?Model
    {
        // Hämta värdet av foreignKey från den aktuella modellens attribut
        $foreignKeyValue = $this->getParentModelAttribute($this->foreignKey);

        $query = "SELECT * FROM `$this->relatedTable` WHERE `$this->ownerKey` = ? LIMIT 1";
        $result = $this->connection->fetchOne($query, [$foreignKeyValue]); // Använder rätt värde här

        if ($result === null) {
            return null; // Inget resultat från databasen
        }

        return $this->createModelInstance($result, $this->relatedTable); // Skapa modellinstans
    }

    public function first(): ?Model
    {
        $result = $this->get(); // Hämta en enda relaterad post

        if (!$result) {
            return null; // Ingen data hittades
        }

        return $result; // Returera modellen direkt
    }


    private function getParentModelAttribute(string $attribute): mixed
    {
        // Försäkra att modellen har attributet
        if (property_exists($this, 'parentModel')) {
            return $this->parentModel->getAttribute($attribute);
        }

        throw new \Exception("Unable to access the foreign key attribute '$attribute' on the parent model.");
    }

    private function resolveModelClass(string $classOrTable): string
    {
        // Om klassnamnet redan är ett FQCN
        if (class_exists($classOrTable)) {
            return $classOrTable;
        }

        // Försök singularisera och tolka modellen
        $singularClass = 'App\\Models\\' . ucfirst(rtrim($classOrTable, 's')); // Exempel: 'tokens' → 'Token'
        $pluralClass = 'App\\Models\\' . ucfirst($classOrTable); // Exempel: 'status'

        if (class_exists($singularClass)) {
            return $singularClass;
        }

        if (class_exists($pluralClass)) {
            return $pluralClass;
        }

        // Om ingen klass hittas, kasta ett undantag
        throw new \Exception("Model class '$classOrTable' not found.");
    }

    private function createModelInstance(array $data, string $classOrTable): Model
    {
        $modelClass = $this->resolveModelClass($classOrTable);
        $model = new $modelClass();
        $model->fill($data);
        $model->markAsExisting();

        return $model;
    }
}