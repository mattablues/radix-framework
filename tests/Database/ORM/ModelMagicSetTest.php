<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;

final class ModelMagicSetTest extends TestCase
{
    public function testMagicSetUsesCamelCaseMutator(): void
    {
        $model = new /**
         * @property-write string $firstName
         */ class extends Model {
            protected string $table = 'items';
            /** @var array<int, string> */
            protected array $fillable = ['first_name'];

            public bool $mutatorCalled = false;

            // OBS: namn matchar exakt vad originalkoden bygger: set + ucfirst('first_name') + Attribute
            public function setFirst_nameAttribute(string $value): void
            {
                $this->mutatorCalled = true;
                $this->attributes['first_name'] = strtoupper($value);
            }
        };

        // Simulera __set('firstName', ...) → camel_to_snake('firstName') = 'first_name'
        $model->__set('firstName', 'john');

        $this->assertTrue(
            $model->mutatorCalled,
            '__set ska hitta och anropa setFirst_nameAttribute för egenskapen firstName.'
        );
        $this->assertSame('JOHN', $model->getAttribute('first_name'));
    }

    public function testMagicSetUsesMutatorWithoutOverwritingAttribute(): void
    {
        $model = new /**
         * @property-write string $foo
         */ class extends Model {
            protected string $table = 'dummy';
            /** @var array<int,string> */
            protected array $fillable = ['foo'];

            public function setFooAttribute(string $value): void
            {
                // Mutatorn normaliserar värdet
                $this->attributes['foo'] = strtoupper($value);
            }
        };

        $model->foo = 'bar';

        $this->assertSame(
            'BAR',
            $model->getAttribute('foo'),
            '__set ska inte skriva över mutatorns värde med råvärdet.'
        );
    }

    public function testMagicSetViaPropertyUsesCamelToSnakeMutator(): void
    {
        $model = new /**
         * @property-write string $firstName
         */ class extends Model {
            protected string $table = 'items';
            /** @var array<int, string> */
            protected array $fillable = ['first_name'];

            public bool $mutatorCalled = false;

            public function setFirst_nameAttribute(string $value): void
            {
                $this->mutatorCalled = true;
                $this->attributes['first_name'] = strtoupper($value);
            }
        };

        $model->firstName = 'john'; // Använder magiska __set

        $this->assertTrue($model->mutatorCalled);
        $this->assertSame('JOHN', $model->getAttribute('first_name'));
    }
}
