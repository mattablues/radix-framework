<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;

final class ModelMutatorNamingTest extends TestCase
{
    public function testSetAttributeCallsSnakeCaseMutator(): void
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

        $model->fill(['first_name' => 'john']);

        $this->assertTrue(
            $model->mutatorCalled,
            'Mutatorn setFirstNameAttribute ska anropas för nyckeln first_name.'
        );
        $this->assertSame('JOHN', $model->getAttribute('first_name'));
    }
}
