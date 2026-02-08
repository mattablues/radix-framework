<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;

final class ModelHydrateGuardTest extends TestCase
{
    public function testHydrateFromDatabaseRespectsGuardAllWildcard(): void
    {
        $model = new class extends Model {
            protected string $table = 'dummy';
            /** @var array<int,string> */
            protected array $guarded = ['*'];
        };

        $row = [
            'id' => 123,
            'name' => 'Secret',
        ];

        $model->hydrateFromDatabase($row);

        // När guarded = ['*'] ska inga attribut hydreras
        $this->assertNull($model->getAttribute('id'));
        $this->assertNull($model->getAttribute('name'));
    }
}
