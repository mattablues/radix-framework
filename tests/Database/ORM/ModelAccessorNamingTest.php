<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;

final class ModelAccessorNamingTest extends TestCase
{
    public function testGetAttributeUsesUcWordsConventionForAccessor(): void
    {
        $model = new class extends Model {
            protected string $table = 'dummy';
            /** @var array<int,string> */
            protected array $fillable = ['first_name'];

            public function getFirstNameAttribute(?string $value): ?string
            {
                return $value !== null ? strtoupper($value) : null;
            }
        };

        $model->forceFill(['first_name' => 'john']);

        $this->assertSame(
            'JOHN',
            $model->getAttribute('first_name'),
            'getAttribute ska mappa first_name → getFirstNameAttribute, inte getfirstnameAttribute.'
        );
    }

    public function testSetAttributeUsesUcWordsConventionForMutator(): void
    {
        $model = new class extends Model {
            protected string $table = 'dummy';
            /** @var array<int,string> */
            protected array $fillable = ['first_name'];

            public function setFirstNameAttribute(string $value): void
            {
                $this->attributes['first_name'] = strtoupper($value);
            }
        };

        $model->setAttribute('first_name', 'john');

        $this->assertSame(
            'JOHN',
            $model->getAttribute('first_name'),
            'setAttribute ska mappa first_name → setFirstNameAttribute, inte setfirstnameAttribute.'
        );
    }
}
