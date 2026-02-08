<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;

final class ModelFillAndAccessorTest extends TestCase
{
    public function testFillDoesNotMassAssignTimestampsViaBlockUndefinableAttributes(): void
    {
        $model = new class extends Model {
            protected string $table = 'items';
            /** @var array<int, string> */
            protected array $fillable = ['first_name'];
        };

        // created_at & updated_at finns INTE i fillable → ska rensas bort i blockUndefinableAttributes()
        $model->fill([
            'first_name' => 'Mats',
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-02 00:00:00',
        ]);

        // Detta dödar MethodCallRemoval-mutanterna på $this->blockUndefinableAttributes()
        $this->assertSame('Mats', $model->getAttribute('first_name'));
        $this->assertNull(
            $model->getAttribute('created_at'),
            'created_at ska inte mass-assignas när det inte finns i fillable.'
        );
        $this->assertNull(
            $model->getAttribute('updated_at'),
            'updated_at ska inte mass-assignas när det inte finns i fillable.'
        );
    }

    public function testGetAttributeUsesUcWordsForSnakeCaseAccessor(): void
    {
        $model = new class extends Model {
            protected string $table = 'items';
            /** @var array<int, string> */
            protected array $fillable = ['full_name'];

            public function getFullNameAttribute(string $value): string
            {
                // tydlig skillnad mellan rått värde och accessor-värde
                return strtoupper($value);
            }
        };

        // forceFill sätter attributet rakt in, utan accessor/mutator
        $model->forceFill(['full_name' => 'mats']);

        // getAttribute ska bygga "getFullNameAttribute" via ucwords() på "full_name"
        $this->assertSame(
            'MATS',
            $model->getAttribute('full_name'),
            'getAttribute ska använda ucwords() för att bygga getFullNameAttribute från full_name.'
        );
    }

    public function testSetAttributeUsesUcWordsForSnakeCaseMutator(): void
    {
        $model = new class extends Model {
            protected string $table = 'items';
            /** @var array<int, string> */
            protected array $fillable = ['first_name'];

            public bool $mutatorCalled = false;

            public function setFirstNameAttribute(string $value): void
            {
                $this->mutatorCalled = true;
                $this->attributes['first_name'] = strtoupper($value);
            }
        };

        // fill → setAttribute('first_name', 'john') → ska bygga setFirstNameAttribute
        $model->fill(['first_name' => 'john']);

        $this->assertTrue(
            $model->mutatorCalled,
            'setAttribute ska bygga setFirstNameAttribute via ucwords() för nyckeln first_name.'
        );
        $this->assertSame('JOHN', $model->getAttribute('first_name'));
    }
}
