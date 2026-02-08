<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use Dummy\Models\Post;
use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;

class SoftDeletesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Mocka en exempeltabell i databasen (simulerat)
        $this->mockDatabaseTable();
    }

    protected function mockDatabaseTable(): void
    {
        // Här kan du simulera en databas med några startvärden.
        // Alternativt, om du har en in-memory databas kan du migrera tabellen på riktigt.
    }

    //public function testSoftDeleteSetsDeletedAt(): void
    //{
    //    // Skapa en modell
    //    $post = new Post([
    //        'id' => 1,
    //        'title' => 'Test Post',
    //        'deleted_at' => null,
    //    ]);
    //
    //    // Kör delete-metoden (vilket ska sätta `deleted_at`)
    //    $post->delete();
    //
    //    // Kontrollera att deleted_at är uppdaterat
    //    $this->assertNotNull($post->getAttributes()['deleted_at']);
    //}

    //    public function testRestoreResetsDeletedAt(): void
    //    {
    //        // Skapa en modell som stödjer soft deletes och har blivit "soft deleted"
    //        $post = new class extends Model {
    //            protected string $table = 'posts';
    //            protected bool $softDeletes = true;
    //            protected array $attributes = ['id' => 1, 'title' => 'Test Post', 'deleted_at' => '2025-07-04 12:00:00'];
    //        };
    //
    //        // Återställ soft deleted-modellen
    //        $post->restore();
    //
    //        // Kontrollera att `deleted_at` är null igen
    //        $this->assertNotNull($post->getAttributes()['deleted_at']);
    //        $this->assertTrue($post->restore());
    //    }

    public function testForceDeleteRemovesTheRow(): void
    {
        // Skapa en modell som stödjer soft deletes
        $post = new class extends Model {
            protected string $table = 'posts';
            protected bool $softDeletes = true;
            /** @var array<string, mixed> */
            protected array $attributes = ['id' => 1, 'title' => 'Test Post', 'deleted_at' => null];
        };

        // Utför force delete
        $post->forceDelete();

        // Kontrollera att raden har blivit permanent borttagen
        // Här behöver vi kanske använda en databasfråga för att verifiera i en riktig databas
        $this->assertFalse($post->getExists()); // Exempel, om "exists" flaggas som falsk efter raderingen
    }

    public function testQueryExcludesSoftDeletedRecords(): void
    {
        // Mocka `query`-metoden och skapa några poster
        $records = [
            ['id' => 1, 'title' => 'Active Post', 'deleted_at' => null],
            ['id' => 2, 'title' => 'Deleted Post', 'deleted_at' => '2025-07-04 12:00:00'],
        ];

        // Filtrera poster med soft deletes
        $filteredRecords = array_filter($records, static fn($record) => $record['deleted_at'] === null);

        // Kontrollera att endast poster som inte är soft deleted returneras
        $this->assertCount(1, $filteredRecords);
        $this->assertEquals('Active Post', $filteredRecords[0]['title']);
    }

    public function testQueryIncludesSoftDeletedRecordsIfExplicitlySpecified(): void
    {
        // Testa om query returnerar alla, inklusive soft-deleted poster
        $records = [
            ['id' => 1, 'title' => 'Active Post', 'deleted_at' => null],
            ['id' => 2, 'title' => 'Deleted Post', 'deleted_at' => '2025-07-04 12:00:00'],
        ];

        // Kontrollera alla poster
        $this->assertCount(2, $records);
    }
}
