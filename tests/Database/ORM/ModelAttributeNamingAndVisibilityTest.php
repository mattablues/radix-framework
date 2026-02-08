<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;

final class ModelAttributeNamingAndVisibilityTest extends TestCase
{
    public function testSetAttributeIsPublicAndUsableFromOutside(): void
    {
        $model = new class extends Model {
            protected string $table = 'items';
            /** @var array<int, string> */
            protected array $fillable = ['foo'];
        };

        // Direkta anrop ska vara tillåtna – detta dödar PublicVisibility‑mutanten.
        $model->setAttribute('foo', 'bar');

        $this->assertSame('bar', $model->getAttribute('foo'));
    }

    public function testGetAttributeCallsSnakeCaseAccessorUsingUcWords(): void
    {
        $model = new class extends Model {
            protected string $table = 'items';
            /** @var array<int, string> */
            protected array $fillable = ['full_name'];

            public function getFullNameAttribute(string $value): string
            {
                // tydlig skillnad mellan rå-attribut & accessor
                return strtoupper($value);
            }
        };

        // forceFill kringgår fillable-logik, vi vill bara ha ett rått värde i attributes
        $model->forceFill(['full_name' => 'mats']);

        $this->assertSame(
            'MATS',
            $model->getAttribute('full_name'),
            'getAttribute ska bygga getFullNameAttribute med hjälp av ucwords().'
        );
    }

    public function testFillUsesSnakeCaseMutatorNameUsingUcWords(): void
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

        // fill() → setAttribute('first_name', 'john') → mutator byggs med ucwords
        $model->fill(['first_name' => 'john']);

        $this->assertTrue(
            $model->mutatorCalled,
            'setAttribute ska bygga setFirstNameAttribute med ucwords() för first_name.'
        );
        $this->assertSame('JOHN', $model->getAttribute('first_name'));
    }
}
