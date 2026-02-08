<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\ConventionModelClassResolver;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\ModelClassResolverInterface;

final class ModelResolverVisibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        // Viktigt: återställ global resolver så andra tester inte påverkas.
        Model::setModelClassResolver(new ConventionModelClassResolver('Dummy\\Models\\'));
        parent::tearDown();
    }

    public function testProtectedModelClassResolverIsCallableFromSubclass(): void
    {
        $m = new class extends Model {
            protected string $table = 'dummy';

            public function callModelClassResolver(): ModelClassResolverInterface
            {
                return $this->modelClassResolver();
            }
        };

        Model::setModelClassResolver(new ConventionModelClassResolver('Dummy\\Models\\'));

        $this->assertInstanceOf(ModelClassResolverInterface::class, $m->callModelClassResolver());
    }

    public function testProtectedResolveRelatedModelClassIsCallableFromSubclass(): void
    {
        $m = new class extends Model {
            protected string $table = 'dummy';

            public function callResolveRelatedModelClass(string $classOrTable): string
            {
                return $this->resolveRelatedModelClass($classOrTable);
            }
        };

        /** @var class-string<Model> $dummyModelClass */
        $dummyModelClass = get_class(new class extends Model {
            protected string $table = 'dummy_related';
        });

        $resolver = new class ($dummyModelClass) implements ModelClassResolverInterface {
            /** @var class-string<Model> */
            private string $cls;

            /**
             * @param class-string<Model> $cls
             */
            public function __construct(string $cls)
            {
                $this->cls = $cls;
            }

            public function resolve(string $classOrTable): string
            {
                return $this->cls;
            }
        };

        Model::setModelClassResolver($resolver);

        $this->assertSame($dummyModelClass, $m->callResolveRelatedModelClass('whatever'));
    }
}
