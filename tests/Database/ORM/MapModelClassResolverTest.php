<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\MapModelClassResolver;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\ModelClassResolverInterface;
use RuntimeException;

final class MapModelClassResolverTest extends TestCase
{
    public function testResolvesMapCaseInsensitively(): void
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = get_class(new class extends Model {
            protected string $table = 'users';
        });

        $fallback = new class implements ModelClassResolverInterface {
            public function resolve(string $classOrTable): string
            {
                throw new RuntimeException('Fallback ska inte anropas när map matchar.');
            }
        };

        $resolver = new MapModelClassResolver([
            'Users' => $modelClass,
        ], $fallback);

        $this->assertSame($modelClass, $resolver->resolve('users'));
        $this->assertSame($modelClass, $resolver->resolve('Users'));
        $this->assertSame($modelClass, $resolver->resolve('USERS'));
    }

    public function testReturnsExistingFqcnDirectlyEvenIfNotInMap(): void
    {
        /** @var class-string<Model> $existingModel */
        $existingModel = get_class(new class extends Model {
            protected string $table = 'something';
        });

        $fallback = new class implements ModelClassResolverInterface {
            public function resolve(string $classOrTable): string
            {
                throw new RuntimeException('Fallback ska inte anropas när FQCN redan finns.');
            }
        };

        $resolver = new MapModelClassResolver([], $fallback);

        $this->assertSame($existingModel, $resolver->resolve($existingModel));
    }

    public function testFallsBackWhenNotFqcnAndNotInMap(): void
    {
        /** @var class-string<Model> $fallbackClass */
        $fallbackClass = get_class(new class extends Model {
            protected string $table = 'fallback_dummy';
        });

        $fallback = new class ($fallbackClass) implements ModelClassResolverInterface {
            public int $calls = 0;
            public ?string $received = null;

            /** @var class-string<Model> */
            private string $fallbackClass;

            /**
             * @param class-string<Model> $fallbackClass
             */
            public function __construct(string $fallbackClass)
            {
                $this->fallbackClass = $fallbackClass;
            }

            public function resolve(string $classOrTable): string
            {
                $this->calls++;
                $this->received = $classOrTable;

                return $this->fallbackClass;
            }
        };

        $resolver = new MapModelClassResolver([
            'users' => $fallbackClass, // irrelevant här; vi vill INTE matcha
        ], $fallback);

        $input = 'totally_unknown_table';
        $this->assertSame($fallbackClass, $resolver->resolve($input));
        $this->assertSame(1, $fallback->calls, 'Fallback ska anropas exakt en gång.');
        $this->assertSame($input, $fallback->received, 'Fallback ska få original-input (ej lowercased).');
    }

    public function testConstructorIgnoresNonStringMapKeysAndDoesNotCrash(): void
    {
        /** @var class-string<Model> $fallbackClass */
        $fallbackClass = get_class(new class extends Model {
            protected string $table = 'fallback_dummy';
        });

        $fallback = new class ($fallbackClass) implements ModelClassResolverInterface {
            /** @var class-string<Model> */
            private string $fallbackClass;

            /**
             * @param class-string<Model> $fallbackClass
             */
            public function __construct(string $fallbackClass)
            {
                $this->fallbackClass = $fallbackClass;
            }

            public function resolve(string $classOrTable): string
            {
                return $this->fallbackClass;
            }
        };

        $map = [
            'users' => \Dummy\Models\User::class,
        ];

        $resolver = new MapModelClassResolver($map, $fallback);

        $this->assertSame($fallbackClass, $resolver->resolve('something_not_in_map'));
    }
}
