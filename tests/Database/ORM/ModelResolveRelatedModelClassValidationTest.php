<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use LogicException;
use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\ModelClassResolverInterface;
use stdClass;

final class ModelResolveRelatedModelClassValidationTest extends TestCase
{
    public function testAcceptsBaseModelClassItself(): void
    {
        $m = new class extends Model {
            protected string $table = 'dummy';

            public function callResolveRelatedModelClass(string $classOrTable): string
            {
                return $this->resolveRelatedModelClass($classOrTable);
            }
        };

        $resolver = new class implements ModelClassResolverInterface {
            public function resolve(string $classOrTable): string
            {
                return Model::class;
            }
        };

        Model::setModelClassResolver($resolver);

        $resolved = $m->callResolveRelatedModelClass('anything');
        $this->assertSame(Model::class, $resolved);
    }

    public function testRejectsNonModelClass(): void
    {
        $m = new class extends Model {
            protected string $table = 'dummy';

            public function callResolveRelatedModelClass(string $classOrTable): string
            {
                return $this->resolveRelatedModelClass($classOrTable);
            }
        };

        $resolver = new class implements ModelClassResolverInterface {
            public function resolve(string $classOrTable): string
            {
                /** @phpstan-ignore-next-line */
                return stdClass::class;
            }
        };

        Model::setModelClassResolver($resolver);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Resolved relation model class is not a Model');

        $m->callResolveRelatedModelClass('anything');
    }
}
