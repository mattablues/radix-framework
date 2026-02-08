<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\QueryBuilder\QueryBuilder;

final class QueryBuilderUtilsTest extends TestCase
{
    public function testDebugSqlInterpolatedReplacesEachPlaceholderExactlyOnce(): void
    {
        // Minimal “riktig” connection behövs för toSql(), men vi kör aldrig execute().
        $pdo = new PDO('sqlite::memory:');
        $conn = new Connection($pdo);

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->whereRaw('id = ? AND status = ? ORDER BY ?', [1, 'active', 'name']);

        // Säkerställ att toSql() verkligen har tre frågetecken
        $this->assertSame(
            'SELECT * FROM `users` WHERE (id = ? AND status = ? ORDER BY ?)',
            $qb->toSql()
        );

        $sql = $qb->debugSqlInterpolated();

        // Alla tre ? ska vara ersatta med värden, med korrekt quoting
        $this->assertSame(
            "SELECT * FROM `users` WHERE (id = 1 AND status = 'active' ORDER BY 'name')",
            $sql
        );
    }

    public function testDebugSqlInterpolatedFormatsDateTimeAndJsonFallback(): void
    {
        $pdo  = new PDO('sqlite::memory:');
        $conn = new Connection($pdo);

        $dt   = new DateTimeImmutable('2024-01-02 03:04:05');
        $data = ['a' => 1];

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('events')
            ->whereRaw(
                'created_at > ? AND payload = ?',
                [$dt, $data]
            );

        $sql = $qb->debugSqlInterpolated();

        // DateTimeInterface ska formatteras som 'Y-m-d H:i:s'
        // Fallback-typer (array här) ska json_encodas och sedan köras genom addslashes().
        $this->assertSame(
            "SELECT * FROM `events` WHERE (created_at > '2024-01-02 03:04:05' AND payload = '{\\\"a\\\":1}')",
            $sql
        );
    }
}
