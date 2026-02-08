<?php

declare(strict_types=1);

namespace Dummy\Models;

final class __NotAModelForDiag__ {}

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\ModelClassResolverInterface;

final class ModelResolveRelatedModelClassAutoloadGuardTest extends TestCase
{
    public function testThrowsReEntrantAutoloadDetectedWhenAutoloaderReenters(): void
    {
        $m = new class extends Model {
            protected string $table = 'dummy';

            public function callResolveRelatedModelClass(string $classOrTable): string
            {
                return $this->resolveRelatedModelClass($classOrTable);
            }
        };

        $fqcn = 'Radix\\Tests\\Database\\ORM\\__ReentrantModelResolveDummy__';

        $resolver = new class ($fqcn) implements ModelClassResolverInterface {
            private string $fqcn;

            public function __construct(string $fqcn)
            {
                $this->fqcn = $fqcn;
            }

            public function resolve(string $classOrTable): string
            {
                /** @phpstan-ignore-next-line */
                return $this->fqcn;
            }
        };
        Model::setModelClassResolver($resolver);

        $cb = function (string $class) use ($m, $fqcn): void {
            if ($class !== $fqcn) {
                return;
            }
            // re-entrant
            $m->callResolveRelatedModelClass('x');
        };

        spl_autoload_register($cb);
        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Re-entrant autoload detected for relation model.');

            $m->callResolveRelatedModelClass('x');
        } finally {
            spl_autoload_unregister($cb);
        }
    }

    public function testThrowsAutoloadFailedDiagIncludesInput(): void
    {
        $m = new class extends Model {
            protected string $table = 'dummy';

            public function callResolveRelatedModelClass(string $classOrTable): string
            {
                return $this->resolveRelatedModelClass($classOrTable);
            }
        };

        $fqcn = 'Radix\\Tests\\Database\\ORM\\__MissingForDiag__';

        $resolver = new class ($fqcn) implements ModelClassResolverInterface {
            private string $fqcn;

            public function __construct(string $fqcn)
            {
                $this->fqcn = $fqcn;
            }

            public function resolve(string $classOrTable): string
            {
                /** @phpstan-ignore-next-line */
                return $this->fqcn;
            }
        };
        Model::setModelClassResolver($resolver);

        // no-op autoload så class_exists($fqcn) förblir false
        $cb = static function (string $class): void {
            // no-op
        };

        spl_autoload_register($cb);
        try {
            $input = 'whatever_input';

            try {
                $m->callResolveRelatedModelClass($input);
                $this->fail('Expected exception not thrown.');
            } catch (\Exception $e) {
                $this->assertStringContainsString('"input":"' . $input . '"', $e->getMessage());
                $this->assertStringContainsString('"phase":"autoload-failed"', $e->getMessage());
            }
        } finally {
            spl_autoload_unregister($cb);
        }
    }

    public function testNotAModelErrorMessageContainsDiagJson(): void
    {
        $m = new class extends Model {
            protected string $table = 'dummy';

            public function callResolveRelatedModelClass(string $classOrTable): string
            {
                return $this->resolveRelatedModelClass($classOrTable);
            }
        };

        $fqcn = \Dummy\Models\__NotAModelForDiag__::class;

        $resolver = new class ($fqcn) implements ModelClassResolverInterface {
            private string $fqcn;

            public function __construct(string $fqcn)
            {
                $this->fqcn = $fqcn;
            }

            public function resolve(string $classOrTable): string
            {
                /** @phpstan-ignore-next-line */
                return $this->fqcn;
            }
        };
        Model::setModelClassResolver($resolver);

        try {
            $m->callResolveRelatedModelClass('x');
            $this->fail('Expected exception not thrown.');
        } catch (\LogicException $e) {
            $this->assertStringStartsWith(
                'Resolved relation model class is not a Model (wrong base class). diag=',
                $e->getMessage()
            );

            $this->assertStringContainsString('"expected_base"', $e->getMessage());
            $this->assertStringContainsString('"resolved_class"', $e->getMessage());
        }
    }

    public function testThrowsReEntrantAutoloadDetectedMessageStartsWithPrefixAndContainsDiagJson(): void
    {
        $m = new class extends Model {
            protected string $table = 'dummy';

            public function callResolveRelatedModelClass(string $classOrTable): string
            {
                return $this->resolveRelatedModelClass($classOrTable);
            }
        };

        $fqcn = 'Radix\\Tests\\Database\\ORM\\__ReentrantModelResolveDummy__';

        $resolver = new class ($fqcn) implements ModelClassResolverInterface {
            private string $fqcn;

            public function __construct(string $fqcn)
            {
                $this->fqcn = $fqcn;
            }

            public function resolve(string $classOrTable): string
            {
                /** @phpstan-ignore-next-line */
                return $this->fqcn;
            }
        };
        Model::setModelClassResolver($resolver);

        $cb = function (string $class) use ($m, $fqcn): void {
            if ($class !== $fqcn) {
                return;
            }
            // re-entrant
            $m->callResolveRelatedModelClass('x');
        };

        spl_autoload_register($cb);
        try {
            try {
                $m->callResolveRelatedModelClass('x');
                $this->fail('Expected exception not thrown.');
            } catch (\LogicException $e) {
                $this->assertStringStartsWith(
                    'Re-entrant autoload detected for relation model. diag=',
                    $e->getMessage()
                );
                $this->assertStringContainsString('"phase":"re-entrant-autoload-guard"', $e->getMessage());

                $this->assertStringContainsString('"resolved":' . json_encode($fqcn), $e->getMessage());
            }
        } finally {
            spl_autoload_unregister($cb);
        }
    }

    public function testThrowsAutoloadFailedMessageStartsWithPrefixAndContainsDiagJson(): void
    {
        $m = new class extends Model {
            protected string $table = 'dummy';

            public function callResolveRelatedModelClass(string $classOrTable): string
            {
                return $this->resolveRelatedModelClass($classOrTable);
            }
        };

        $fqcn = 'Radix\\Tests\\Database\\ORM\\__MissingForDiag__';

        $resolver = new class ($fqcn) implements ModelClassResolverInterface {
            private string $fqcn;

            public function __construct(string $fqcn)
            {
                $this->fqcn = $fqcn;
            }

            public function resolve(string $classOrTable): string
            {
                /** @phpstan-ignore-next-line */
                return $this->fqcn;
            }
        };
        Model::setModelClassResolver($resolver);

        // no-op autoload så class_exists($fqcn) förblir false
        $cb = static function (string $class): void {
            // no-op
        };

        spl_autoload_register($cb);
        try {
            $input = 'whatever_input';

            try {
                $m->callResolveRelatedModelClass($input);
                $this->fail('Expected exception not thrown.');
            } catch (\Exception $e) {
                $this->assertStringStartsWith(
                    'Relation model class could not be loaded/resolved. diag=',
                    $e->getMessage()
                );
                $this->assertStringContainsString('"phase":"autoload-failed"', $e->getMessage());
                $this->assertStringContainsString('"input":"' . $input . '"', $e->getMessage());
            }
        } finally {
            spl_autoload_unregister($cb);
        }
    }
}
