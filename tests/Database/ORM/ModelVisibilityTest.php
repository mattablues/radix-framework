<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;

final class ModelVisibilityTest extends TestCase
{
    public function testRelationExistsIsCallablePublicly(): void
    {
        $model = new class extends Model {
            protected string $table = 'dummy';
            public function fooRelation(): void {}
        };

        $this->assertTrue($model->relationExists('fooRelation'));
        $this->assertFalse($model->relationExists('barRelation'));
    }

    public function testBlockUndefinableAttributesIsCallablePublicly(): void
    {
        $model = new class extends Model {
            protected string $table = 'dummy';
            /** @var array<int,string> */
            protected array $fillable = [];
            /** @var array<int,string> */
            protected array $guarded = [];
        };

        // Ska gå att anropa utan fel
        $model->blockUndefinableAttributes();

        $this->addToAssertionCount(1);
    }

    public function testIsFillableIsCallablePublicly(): void
    {
        $model = new class extends Model {
            protected string $table = 'dummy';
            /** @var array<int,string> */
            protected array $fillable = ['name'];
            /** @var array<int,string> */
            protected array $guarded = [];
        };

        $this->assertTrue($model->isFillable('name'));
        $this->assertFalse($model->isFillable('other'));
    }
}
