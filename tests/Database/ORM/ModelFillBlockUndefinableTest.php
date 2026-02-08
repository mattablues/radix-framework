<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;

final class ModelFillBlockUndefinableTest extends TestCase
{
    public function testFillRemovesUndefinedTimestampAttributes(): void
    {
        $model = new class extends Model {
            protected string $table = 'dummy';
            /** @var array<int,string> */
            protected array $fillable = ['name']; // timestamps EJ fillable
        };

        // Simulera att created_at/updated_at finns i attributen före fill()
        $model->forceFill([
            'name' => 'Old',
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ]);

        // Kör fill – ska anropa blockUndefinableAttributes()
        $model->fill(['name' => 'New']);

        $this->assertSame('New', $model->getAttribute('name'));
        $this->assertNull(
            $model->getAttribute('created_at'),
            'created_at ska ha tagits bort av blockUndefinableAttributes() när det inte är fillable.'
        );
        $this->assertNull(
            $model->getAttribute('updated_at'),
            'updated_at ska ha tagits bort av blockUndefinableAttributes() när det inte är fillable.'
        );
    }
}
