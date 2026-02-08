<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\QueryBuilder\QueryBuilder;
use ReflectionObject;

final class PaginationCloneRemovalTest extends TestCase
{
    public function testPaginateDoesNotMutateOriginalQueryWhenBuildingCountQuery(): void
    {
        $spyConnection = new class (new PDO('sqlite::memory:')) extends Connection {
            /** @var array<int, array{query: string, params: array<int|string, mixed>}> */
            public array $fetchOneCalls = [];

            /**
             * Gör det *möjligt* att returnera null så att signaturen ?array är meningsfull
             * och PHPStan inte klagar på "never returns null".
             */
            public bool $returnNull = false;

            public function fetchOne(string $query, array $params = []): ?array
            {
                $this->fetchOneCalls[] = ['query' => $query, 'params' => $params];

                if ($this->returnNull) {
                    return null;
                }

                // total=0 => paginate() returnerar tidigt och anropar inte get()
                return ['total' => 0];
            }
        };

        $qb = (new QueryBuilder())
            ->setConnection($spyConnection)
            ->from('users')
            ->orderBy('name', 'ASC')
            ->limit(5)
            ->offset(10);

        // Försanity: vi vill ha state som skulle nollas om clone tas bort.
        $this->assertNotEmpty($this->getProp($qb, 'orderBy'));
        $this->assertSame(5, $this->getProp($qb, 'limit'));
        $this->assertSame(10, $this->getProp($qb, 'offset'));

        $result = $qb->paginate(perPage: 10, currentPage: 1);

        // paginate() med total=0 ska ge tom data
        $this->assertSame([], $result['data'] ?? null);

        // Det viktiga: original-queryn får INTE ha blivit muterad av countQuery-städningen
        $this->assertNotEmpty(
            $this->getProp($qb, 'orderBy'),
            'orderBy på originalqueryn får inte nollas av countQuery (dödar CloneRemoval)'
        );
        $this->assertSame(
            5,
            $this->getProp($qb, 'limit'),
            'limit på originalqueryn får inte nollas av countQuery (dödar CloneRemoval)'
        );
        $this->assertSame(
            10,
            $this->getProp($qb, 'offset'),
            'offset på originalqueryn får inte nollas av countQuery (dödar CloneRemoval)'
        );

        // Extra: COUNT(*) måste faktiskt finnas i count-queryn (bra som skydd när du tar bort regex-ignore)
        $this->assertNotEmpty($spyConnection->fetchOneCalls, 'paginate() ska göra ett fetchOne() för count');
        $this->assertStringContainsString('COUNT(*)', $spyConnection->fetchOneCalls[0]['query']);
    }

    private function getProp(object $obj, string $name): mixed
    {
        $ref = new ReflectionObject($obj);
        while (!$ref->hasProperty($name) && ($ref = $ref->getParentClass()) !== false) {
            // loopar uppåt i arvshierarkin
        }

        if ($ref === false || !$ref->hasProperty($name)) {
            $this->fail("Hittar inte property \$$name på " . $obj::class);
        }

        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($obj);
    }
}
