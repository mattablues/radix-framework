<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\Collection\Collection;
use Radix\Database\QueryBuilder\QueryBuilder;
use RuntimeException;

final class QueryBuilderLazyTest extends TestCase
{
    public function testLazyStopsAfterLastIncompleteBatchAndAdvancesOffset(): void
    {
        $size = 3;

        $builder = new class extends QueryBuilder {
            /** @var array<int,int> */
            private static array $offsets = [];
            /** @var array<int,int> */
            private array $instanceOffsets = [];
            private static int $calls = 0;

            /**
             * @return array<int,int>
             */
            public function getOffsets(): array
            {
                return self::$offsets;
            }

            /**
             * Offsets loggade på just denna instans (inte delade statiskt).
             *
             * @return array<int,int>
             */
            public function getInstanceOffsets(): array
            {
                return $this->instanceOffsets;
            }

            public function getCalls(): int
            {
                return self::$calls;
            }

            public function get(): Collection
            {
                self::$calls++;

                if (self::$calls === 1) {
                    // Första batchen: full storlek
                    return new Collection([1, 2, 3]);
                }

                if (self::$calls === 2) {
                    // Andra (sista) batchen: färre än $size
                    return new Collection([4]);
                }

                // En tredje get() här skulle indikera att lazy() inte bryter korrekt
                throw new RuntimeException('lazy() called get() more times than expected');
            }

            public function limit(int $limit): static
            {
                // Ignoreras i stubben – påverkar inte get()
                return $this;
            }

            public function offset(int $offset): static
            {
                // Registrera alla offsetar som används av lazy()
                self::$offsets[]         = $offset;
                $this->instanceOffsets[] = $offset;
                return $this;
            }
        };

        $collected = [];
        foreach ($builder->lazy($size) as $value) {
            $collected[] = $value;
        }

        // Vi ska ha 3 + 1 = 4 element totalt
        $this->assertSame([1, 2, 3, 4], $collected);
        // get() ska ha kallats exakt två gånger (2 sidor)
        $this->assertSame(2, $builder->getCalls());
        // offset ska vara 0 för första sidan, 3 för andra (size = 3)
        $this->assertSame([0, 3], $builder->getOffsets());
        // Viktigt: original-instansen ska inte ha några egen-loggade offsets efter lazy()
        // (de ska ligga på klonerna). CloneRemoval-mutationen gör att denna assertion bryts.
        $this->assertSame([], $builder->getInstanceOffsets());
    }

    public function testChunkUsesCorrectOffsetsAndStopsAfterIncompleteBatch(): void
    {
        $size = 3;

        $builder = new class extends QueryBuilder {
            /** @var array<int,int> */
            private static array $offsets = [];
            /** @var array<int,int> */
            private array $instanceOffsets = [];
            private static int $calls = 0;

            /**
             * @return array<int,int>
             */
            public function getOffsets(): array
            {
                return self::$offsets;
            }

            /**
             * Offsets loggade på just denna instans (inte delade statiskt).
             *
             * @return array<int,int>
             */
            public function getInstanceOffsets(): array
            {
                return $this->instanceOffsets;
            }

            public function getCalls(): int
            {
                return self::$calls;
            }

            public function get(): Collection
            {
                self::$calls++;

                if (self::$calls === 1) {
                    // Första chunken: full storlek
                    return new Collection([1, 2, 3]);
                }

                if (self::$calls === 2) {
                    // Andra (sista) chunken: färre än $size
                    return new Collection([4]);
                }

                // En tredje get() skulle indikera att chunk() inte bryter korrekt
                throw new RuntimeException('chunk() called get() more times than expected');
            }

            public function limit(int $limit): static
            {
                // Ignoreras i stubben – påverkar inte get()
                return $this;
            }

            public function offset(int $offset): static
            {
                self::$offsets[]          = $offset;
                $this->instanceOffsets[] = $offset;
                return $this;
            }
        };

        $pages = [];
        $builder->chunk($size, function (Collection $chunk, int $page) use (&$pages): void {
            $pages[] = $chunk->toArray();
        });

        // Vi ska få två chunkar: [1,2,3] och [4]
        $this->assertSame([[1, 2, 3], [4]], $pages);
        // get() ska ha kallats exakt två gånger
        $this->assertSame(2, $builder->getCalls());
        // offset ska vara 0 för första chunken, 3 för andra (size = 3)
        $this->assertSame([0, 3], $builder->getOffsets());
        // Viktigt: original-instansen ska inte ha några egen-loggade offsets efter chunk()
        // (de ska ligga på klonerna). CloneRemoval-mutationen gör att denna assertion bryts.
        $this->assertSame([], $builder->getInstanceOffsets());
    }

    public function testChunkRejectsNonPositiveSize(): void
    {
        $builder = new class extends QueryBuilder {
            // Vi kommer aldrig förbi size-kollen, så dessa metoder anropas inte.
            public function get(): Collection
            {
                throw new RuntimeException('chunk() should fail before calling get() when size <= 0');
            }

            public function limit(int $limit): static
            {
                return $this;
            }

            public function offset(int $offset): static
            {
                return $this;
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk size must be greater than 0.');

        $builder->chunk(0, function (Collection $c, int $page): void {
            // ska aldrig nå hit
        });
    }
}
