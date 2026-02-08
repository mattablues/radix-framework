<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\MapModelClassResolver;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\ModelClassResolverInterface;

final class MapModelClassResolverKeyValidationTest extends TestCase
{
    public function testIgnoresEmptyStringKeysInMap(): void
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = get_class(new class extends Model {
            protected string $table = 'dummy';
        });

        $fallback = new class implements ModelClassResolverInterface {
            public int $calls = 0;

            public function resolve(string $classOrTable): string
            {
                $this->calls++;

                /** @var class-string<Model> $fallbackClass */
                $fallbackClass = get_class(new class extends Model {
                    protected string $table = 'fallback';
                });

                return $fallbackClass;
            }
        };

        $resolver = new MapModelClassResolver([
            '' => $modelClass,        // ska ignoreras
            'users' => $modelClass,   // irrelevant här
        ], $fallback);

        // Om '' ignoreras ska fallback användas
        $resolver->resolve('');
        $this->assertSame(1, $fallback->calls);
    }
}
