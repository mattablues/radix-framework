<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query\Concerns;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\QueryBuilder\QueryBuilder;

final class PaginationMutationTest extends TestCase
{
    public function testPaginateUsesCloneSoCountQueryDoesNotMutateOriginalOrderByAndColumns(): void
    {
        $conn = $this->createMock(Connection::class);

        // 1) COUNT-queryn ska innehålla COUNT(*) och får gärna sakna ORDER BY (countQuery nollas)
        $conn->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('COUNT(*)', $sql, 'COUNT-queryn måste innehålla COUNT(*).');
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(['total' => 1]);

        // 2) Data-queryn ska fortfarande vara SELECT * och behålla ORDER BY från originalbyggaren.
        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('SELECT *', $sql, 'Data-queryn ska vara en vanlig SELECT *.');
                    $this->assertStringContainsString('ORDER BY', $sql, 'Data-queryn ska behålla ORDER BY från originalet.');
                    $this->assertStringNotContainsString('COUNT(*)', $sql, 'Data-queryn får inte vara en COUNT-query.');
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([['id' => 1]]);

        $model = new class extends Model {
            protected string $table = 'users';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model))
            ->orderBy('id', 'ASC');

        $qb->paginate(10, 1);
    }

    public function testPaginateCountQuerySelectsCountStar(): void
    {
        $conn = $this->createMock(Connection::class);

        // Dödar MethodCallRemoval: utan selectRaw('COUNT(*) as total') saknas COUNT(*)
        $conn->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('COUNT(*)', $sql);

                    // Din SQL använder "as total" (utan backticks). Gör matchen robust:
                    $this->assertMatchesRegularExpression('/\bas\s+total\b/i', $sql);

                    return true;
                }),
                $this->anything()
            )
            ->willReturn(['total' => 0]);

        // total=0 => early return => får inte hämta data
        $conn->expects($this->never())->method('fetchAll');

        $model = new class extends Model {
            protected string $table = 'users';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $res = $qb->paginate(10, 1);

        $this->assertSame([], $res['data']);
        $this->assertSame(0, $res['pagination']['total']);
    }

    public function testPaginateMissingTotalKeyDefaultsToZeroAndNeverLoadsData(): void
    {
        $conn = $this->createMock(Connection::class);

        // Viktigt: fetchOne returnerar array utan 'total'
        $conn->method('fetchOne')->willReturn([]);

        // Original: rawTotal => 0 => totalRecords===0 => early return => INGEN fetchAll.
        // Mutant (?? -1 / ?? 1): totalRecords blir -1 eller 1 => INTE early return => försöker hämta data => fetchAll anropas => test fail.
        $conn->expects($this->never())->method('fetchAll');

        $model = new class extends Model {
            protected string $table = 'users';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $res = $qb->paginate(10, 1);

        $this->assertSame([], $res['data']);
        $this->assertSame(0, $res['pagination']['total']);
        $this->assertSame(0, $res['pagination']['last_page']);
        $this->assertSame(1, $res['pagination']['first_page']);
    }
}
