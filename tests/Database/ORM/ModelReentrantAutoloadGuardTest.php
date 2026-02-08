<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use LogicException;
use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\ModelClassResolverInterface;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class ModelReentrantAutoloadGuardTest extends TestCase
{
    public function testResolveRelatedModelClassGuardsAgainstReentrantAutoload(): void
    {
        /** @var class-string<Model> $resolvedFqcn */
        $resolvedFqcn = 'Radix\\Tests\\Database\\ORM\\__ReentrantAutoloadTarget_' . bin2hex(random_bytes(8));

        $resolver = new class ($resolvedFqcn) implements ModelClassResolverInterface {
            /** @var class-string<Model> */
            private string $fqcn;

            /**
             * @param class-string<Model> $fqcn
             */
            public function __construct(string $fqcn)
            {
                $this->fqcn = $fqcn;
            }

            public function resolve(string $classOrTable): string
            {
                return $this->fqcn;
            }
        };

        // Spara och återställ global state (Model har statisk resolver)
        $ref = new ReflectionClass(Model::class);
        $prop = $ref->getProperty('modelClassResolver');
        $prop->setAccessible(true);
        $previousResolver = $prop->getValue(null);

        Model::setModelClassResolver($resolver);

        $model = new class extends Model {
            protected string $table = 'x';

            public function publicResolveRelatedModelClass(string $classOrTable): string
            {
                return $this->resolveRelatedModelClass($classOrTable);
            }
        };

        $autoloadCalls = 0;

        $autoload = function (string $class) use ($model, $resolvedFqcn, &$autoloadCalls): void {
            if ($class !== $resolvedFqcn) {
                return;
            }

            $autoloadCalls++;

            // Endast första autoload-callen gör re-entrant-anropet, annars no-op.
            if ($autoloadCalls !== 1) {
                return;
            }

            try {
                // Detta ska i originalkoden träffa re-entrant-guard och kasta LogicException.
                $model->publicResolveRelatedModelClass('whatever');
            } catch (Throwable $t) {
                // Viktigt: bubbla upp exakt samma exception till yttersta anropet,
                // så Infection ser skillnaden mellan "guard" och "autoload-failed".
                throw $t;
            }

            // Om vi kommer hit betyder det att re-entrant-anropet INTE kastade,
            // vilket är fel (både logiskt och för att döda mutanten).
            throw new RuntimeException('Re-entrant call did not throw as expected.');
        };

        spl_autoload_register($autoload, true, true);

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Re-entrant autoload detected');

            $model->publicResolveRelatedModelClass('whatever');
        } finally {
            spl_autoload_unregister($autoload);

            if ($previousResolver instanceof ModelClassResolverInterface) {
                Model::setModelClassResolver($previousResolver);
            }
        }
    }
}
