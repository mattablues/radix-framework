<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\QueryBuilder\QueryBuilder;

final class SearchMutationTest extends TestCase
{
    public function testSearchUsesCloneSoCountQueryDoesNotMutateOriginalOrderByAndSelect(): void
    {
        $conn = $this->createMock(Connection::class);

        // 1) COUNT-queryn: måste innehålla COUNT(*)
        $conn->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('COUNT(*)', $sql, 'COUNT-queryn måste innehålla COUNT(*).');
                    $this->assertMatchesRegularExpression('/\bas\s+total\b/i', $sql, 'COUNT-queryn måste alias:a som total.');
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(['total' => 1]);

        // 2) Data-queryn: ska vara vanlig SELECT * och behålla ORDER BY från originalet.
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

        $qb->search('foo', ['name'], 10, 1);
    }

    public function testSearchCountQuerySelectsCountStarAsTotal(): void
    {
        $conn = $this->createMock(Connection::class);

        // Dödar MethodCallRemoval: utan selectRaw('COUNT(*) as total') saknas COUNT(*)
        $conn->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('COUNT(*)', $sql);
                    $this->assertMatchesRegularExpression('/\bas\s+total\b/i', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(['total' => 0]);

        // search() kommer fortfarande hämta data även om total=0; returnera tomt.
        $conn->expects($this->once())->method('fetchAll')->willReturn([]);

        $model = new class extends Model {
            protected string $table = 'users';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $res = $qb->search('foo', ['name'], 10, 1);

        $this->assertSame([], $res['data']);
        $this->assertSame(0, $res['search']['total']);
    }

    public function testSearchMissingTotalKeyDefaultsToZeroInMetadata(): void
    {
        $conn = $this->createMock(Connection::class);

        // Viktigt: fetchOne returnerar array utan 'total'
        $conn->method('fetchOne')->willReturn([]);

        // search() hämtar data oavsett; vi vill bara inspektera metadata.
        $conn->method('fetchAll')->willReturn([]);

        $model = new class extends Model {
            protected string $table = 'users';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $res = $qb->search('foo', ['name'], 10, 1);

        // Dödar IncrementInteger-mutanten (?? 1): total skulle bli 1 annars
        // Dödar DecrementInteger-mutanten (?? -1): total skulle bli -1 annars
        $this->assertSame(0, $res['search']['total'], 'När total saknas ska den behandlas som 0.');
        $this->assertSame(0, $res['search']['last_page'], 'last_page ska bli 0 när total=0.');
        $this->assertSame(1, $res['search']['first_page']);
    }
}
