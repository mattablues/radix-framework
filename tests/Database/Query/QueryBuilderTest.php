<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

use Closure;
use ErrorException;
use Exception;
use Generator;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\QueryBuilder\QueryBuilder;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use TypeError;

class QueryBuilderTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        // Använd SQLite i minnet för testerna
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->connection = new Connection($pdo);
    }

    public function testNestedWhere(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('status', '=', 'active')
            ->where(function (QueryBuilder $q): void {
                $q->where('age', '>=', 18)
                  ->where('country', '=', 'Sweden');
            })
            ->where('deleted_at', 'IS', null);

        $this->assertEquals(
            'SELECT * FROM `users` WHERE `status` = ? AND (`age` >= ? AND `country` = ?) AND `deleted_at` IS NULL',
            $query->toSql()
        );

        $this->assertEquals(
            ['active', 18, 'Sweden'],
            $query->getBindings()
        );
    }

    public function testOrWhereAcceptsClosureAndMergesBindingsAsNestedOrGroup(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('status', '=', 'active')
            ->orWhere(function (QueryBuilder $q): void {
                $q->where('age', '>=', 18)
                  ->where('country', '=', 'Sweden');
            });

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? OR (`age` >= ? AND `country` = ?)',
            $query->toSql()
        );

        $this->assertSame(
            ['active', 18, 'Sweden'],
            $query->getBindings()
        );
    }

    public function testExistsUsesCloneLimitOneSelectOneAndDoesNotMutateOriginalBuilder(): void
    {
        $conn = $this->createMock(Connection::class);

        $conn->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(function (string $sql): bool {
                    // exists() ska använda SELECT 1 och LIMIT 1 på en klon,
                    // inte på originalbyggaren.
                    $this->assertStringContainsString('SELECT 1', $sql, 'exists() ska använda SELECT 1');
                    $this->assertStringContainsString('LIMIT 1', $sql, 'exists() ska alltid använda LIMIT 1');
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(['1']);

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->where('id', '=', 123);

        $originalSql = $qb->toSql();

        $this->assertTrue($qb->exists(), 'exists() ska returnera true när fetchOne() inte är null.');

        // Om CloneRemoval-mutanten slår till (ingen clone) kommer exists()
        // att mutera builderns state (columns/limit/offset) och ändra SQL:n.
        $this->assertSame(
            $originalSql,
            $qb->toSql(),
            'exists() ska inte mutera original-builderns SQL.'
        );
    }

    public function testSimpleSelectQuery(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        $this->assertEquals(
            'SELECT * FROM `users`',
            $query->toSql(),
            'QueryBuilder should generate a simple SELECT query.'
        );
    }

    public function testFromRejectsWhitespaceOnlyTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Table name cannot be empty.');

        (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('   '); // endast whitespace ska behandlas som tomt tabellnamn
    }

    public function testSelectWithAlias(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users AS u');

        $this->assertEquals(
            'SELECT * FROM `users` AS `u`',
            $query->toSql(),
            'QueryBuilder should handle table aliases correctly in a SELECT query.'
        );
    }

    public function testSelectWithLowercaseAsAlias(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users as u');

        // Beteendet ska vara identiskt oavsett gemener/VERSALER i "as"
        $this->assertSame(
            'SELECT * FROM `users` AS `u`',
            $query->toSql(),
            'from() ska behandla "as" case-insensitivt när alias parseas.'
        );
    }

    public function testSelectWithAliasAndWhitespaceIsTrimmed(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            // Extra mellanrum runt både tabellnamn och alias
            ->from('  users   AS   u   ');

        $this->assertSame(
            'SELECT * FROM `users` AS `u`',
            $query->toSql(),
            'from() ska trimma table/alias-delarna även när det finns extra whitespace runt AS.'
        );
    }

    public function testWithSoftDeletesRemovesDeletedAtFilter(): void
    {
        // Setup QueryBuilder och aktivera withSoftDeletes
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->withSoftDeletes() // Aktivera soft deletes
            ->where('email', '=', 'test@example.com');

        // Kontrollera att 'deleted_at IS NULL' inte inkluderas i SQL-frågan
        $this->assertEquals(
            'SELECT * FROM `users` WHERE `email` = ?',
            $query->toSql(),
            'QueryBuilder should not include deleted_at filter when withSoftDeletes is active.'
        );

        // Kontrollera att bindningarna endast innehåller parametern för email
        $this->assertEquals(
            ['test@example.com'],
            $query->getBindings(),
            'QueryBuilder should bind the WHERE clause values correctly.'
        );
    }

    public function testSelectWithWhereClause(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('id', '=', 1);

        $this->assertEquals(
            'SELECT * FROM `users` WHERE `id` = ?',
            $query->toSql(),
            'QueryBuilder should generate a SELECT query with WHERE clause.'
        );

        $this->assertEquals(
            [1],
            $query->getBindings(),
            'QueryBuilder should bind the WHERE clause values correctly.'
        );
    }

    public function testJoinQuery(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->join('profiles', 'users.id', '=', 'profiles.user_id');

        $this->assertEquals(
            'SELECT * FROM `users` INNER JOIN `profiles` ON `users`.`id` = `profiles`.`user_id`',
            $query->toSql(),
            'QueryBuilder should generate a SELECT query with JOIN clause.'
        );
    }

    public function testPaginateMethodIsLoadedFromExpectedFile(): void
    {
        $ref = new ReflectionMethod(\Radix\Database\QueryBuilder\QueryBuilder::class, 'paginate');

        $file = $ref->getFileName();
        $this->assertIsString($file, 'ReflectionMethod::getFileName() ska returnera en sträng i detta test.');
        $file = $file; // nu säkert string för PHPStan

        $normalized = str_replace(['/', '\\'], '/', $file);

        $this->assertStringEndsWith(
            '/src/Database/QueryBuilder/Concerns/Pagination.php',
            $normalized,
            'paginate() laddas inte från förväntad trait-fil. Fil: ' . $file
        );
    }

    public function testPaginateUsesCloneForCountQueryAndSelectsCountStar(): void
    {
        $conn = $this->createMock(Connection::class);

        $conn->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Dödar MethodCallRemoval: COUNT(*) måste finnas
                    $this->assertStringContainsString('COUNT(*)', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(['total' => 1]);

        // Data-hämtningen ska ske efteråt
        $conn->method('fetchAll')->willReturn([['id' => 1, 'name' => 'A']]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model))
            ->orderBy('id'); // viktigt: vi vill se att originalet behåller sin order

        $sqlBefore = $qb->toSql();
        $qb->paginate(10, 1);
        $sqlAfter = $qb->toSql();

        // Dödar CloneRemoval: om $countQuery inte är clone så nollas orderBy/columns på originalet
        $this->assertStringContainsString('ORDER BY', $sqlBefore);
        $this->assertStringContainsString('ORDER BY', $sqlAfter);
    }

    public function testPaginateDoesNotMutateOriginalColumnsOrOrderByWhenBuildingCountQuery(): void
    {
        $conn = $this->createMock(Connection::class);

        // total>0 så att paginate() går hela vägen till "get()"
        $conn->method('fetchOne')->willReturn(['total' => 1]);
        $conn->method('fetchAll')->willReturn([['id' => 1, 'name' => 'A']]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model))
            ->orderBy('id');

        // Läs properties före
        $refColumns = new ReflectionProperty(QueryBuilder::class, 'columns');
        $refColumns->setAccessible(true);

        $refOrderBy = new ReflectionProperty(QueryBuilder::class, 'orderBy');
        $refOrderBy->setAccessible(true);

        $columnsBefore = $refColumns->getValue($qb);
        $orderByBefore = $refOrderBy->getValue($qb);

        $qb->paginate(10, 1);

        $columnsAfter = $refColumns->getValue($qb);
        $orderByAfter = $refOrderBy->getValue($qb);

        // Dödar CloneRemoval: utan clone nollas originalets columns/orderBy av count-queryn
        $this->assertSame($columnsBefore, $columnsAfter, 'paginate() får inte mutera originalets columns när count query byggs.');
        $this->assertSame($orderByBefore, $orderByAfter, 'paginate() får inte mutera originalets orderBy när count query byggs.');
        $this->assertNotEmpty($orderByAfter, 'orderBy ska vara kvar på original-queryn.');
    }

    public function testPaginateCountQuerySelectsCountStar(): void
    {
        $conn = $this->createMock(Connection::class);

        $conn->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('COUNT(*)', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(['total' => 1]);

        // Behövs för att paginate() ska kunna hämta data utan att krascha
        $conn->method('fetchAll')->willReturn([['id' => 1, 'name' => 'A']]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model))
            ->paginate(10, 1);
    }

    public function testPaginateMissingTotalKeyMustEarlyReturnAndNeverCallGet(): void
    {
        $conn = $this->createMock(Connection::class);

        // Viktigt: returnera en array utan 'total' (inte null)
        $conn->method('fetchOne')->willReturn([]);

        $builder = new class ($conn) extends QueryBuilder {
            private Connection $conn;

            /** @var class-string<\Radix\Database\ORM\Model>|null */
            protected ?string $modelClass = \Radix\Database\ORM\Model::class;

            public function __construct(Connection $conn)
            {
                $this->conn = $conn;
            }

            protected function getConnection(): Connection
            {
                return $this->conn;
            }

            public function get(): \Radix\Collection\Collection
            {
                // Original-kod ska ALDRIG hamna här när total saknas (total=0 => early return).
                throw new RuntimeException('get() får inte anropas när total saknas (ska bli 0 och early-return).');
            }
        };

        $builder->from('users');

        $res = $builder->paginate(10, 1);

        // Om mutanten ??1 är aktiv kommer paginate() försöka hämta data => get() kastar och testet failar (mutanten dör).
        $this->assertSame([], $res['data']);
        $this->assertSame(0, $res['pagination']['total']);
        $this->assertSame(0, $res['pagination']['last_page']);
        $this->assertSame(1, $res['pagination']['first_page']);
    }

    public function testPaginateMissingTotalKeyReturnsZeroAndDoesNotCallGet(): void
    {
        $conn = $this->createMock(Connection::class);

        // saknar total-nyckeln helt
        $conn->method('fetchOne')->willReturn([]);

        $builder = new class ($conn) extends QueryBuilder {
            private Connection $conn;

            /** @var class-string<\Radix\Database\ORM\Model>|null */
            protected ?string $modelClass = \Radix\Database\ORM\Model::class;

            public function __construct(Connection $conn)
            {
                $this->conn = $conn;
            }

            protected function getConnection(): Connection
            {
                return $this->conn;
            }

            public function get(): \Radix\Collection\Collection
            {
                throw new RuntimeException('get() får inte anropas när total saknas (ska bli 0 och early-return).');
            }
        };

        $builder->from('users');

        $res = $builder->paginate(10, 1);

        // Dödar ?? -1 och ?? 1: de skulle annars gå vidare och försöka hämta data (=> get() => exception)
        $this->assertSame([], $res['data']);
        $this->assertSame(0, $res['pagination']['total']);
        $this->assertSame(0, $res['pagination']['last_page']);
        $this->assertSame(1, $res['pagination']['first_page']);
    }

    public function testSimplePaginateUsesPerPagePlusOneLimitAndCorrectOffset(): void
    {
        $conn = $this->createMock(Connection::class);

        // Kontrollera att SQL:en innehåller LIMIT perPage+1 (=3) och OFFSET 0 för första sidan.
        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // simplePaginate(2,1) ska generera LIMIT 3 OFFSET 0
                    // Mutanter:
                    //  - +0  => LIMIT 2 OFFSET 0
                    //  - +2  => LIMIT 4 OFFSET 0
                    //  - -1  => LIMIT 1 OFFSET 0
                    //  - MethodCallRemoval => ingen LIMIT/OFFSET alls
                    $this->assertStringContainsString('LIMIT 3 OFFSET 0', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([
                ['id' => 1, 'name' => 'A'],
                ['id' => 2, 'name' => 'B'],
                ['id' => 3, 'name' => 'C'],
            ]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $page = $qb->simplePaginate(2, 1);

        // Bas-sanity: samma förväntningar som i ditt befintliga test
        $this->assertArrayHasKey('data', $page);
        $this->assertIsArray($page['data']);
        $this->assertCount(2, $page['data']);
        $this->assertArrayHasKey('pagination', $page);
        $this->assertTrue($page['pagination']['has_more']);
    }

    public function testPaginateNormalizesZeroCurrentPageToOneEvenWhenLastPageIsAtLeastTwo(): void
    {
        $conn = $this->createMock(Connection::class);

        // total=20, perPage=10 => last_page=2 (så mutantens "2" klampas INTE bort)
        $conn->method('fetchOne')->willReturn(['total' => 20]);

        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Original: currentPage=0 => 1 => OFFSET 0
                    // Mutant:   currentPage=0 => 2 => OFFSET 10
                    $this->assertStringContainsString('LIMIT 10 OFFSET 0', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([['id' => 1, 'name' => 'A']]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $res = $qb->paginate(10, 0);

        $this->assertSame(1, $res['pagination']['current_page']);
        $this->assertSame(2, $res['pagination']['last_page']);
    }

    public function testPaginateUsesCloneForCountQueryAndFetchAllUsesSelectStarNotCount(): void
    {
        $conn = $this->createMock(Connection::class);

        // COUNT-query måste innehålla COUNT(*)
        $conn->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('COUNT(*)', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(['total' => 1]);

        // Data-queryn (fetchAll via AbstractQueryBuilder/get()) måste vara en vanlig SELECT *, inte COUNT.
        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('SELECT *', $sql, 'fetchAll ska hämta data via SELECT *.');
                    $this->assertStringNotContainsString('COUNT(*)', $sql, 'fetchAll ska inte råka köra count-queryn.');
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([['id' => 1, 'name' => 'A']]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model))
            ->orderBy('id');

        $qb->paginate(10, 1);
    }

    public function testPaginateTreatsMissingTotalKeyAsZeroWithModelClassAndSkipsFetchAll(): void
    {
        $conn = $this->createMock(Connection::class);

        // Saknar 'total'
        $conn->method('fetchOne')->willReturn([]);

        // Om rawTotal-mutanten ger 1/-1 så ska paginate() försöka hämta data => fetchAll anropas (och testet faller).
        // Original: total=0 => early return => fetchAll ska aldrig anropas.
        $conn->expects($this->never())->method('fetchAll');

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
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

    public function testSimplePaginateSetsHasMoreFalseWhenItemsEqualPerPage(): void
    {
        $conn = $this->createMock(Connection::class);

        // Returnera exakt perPage rader – INGEN extra rad.
        $conn->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
        ]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $perPage = 2;
        $page = $qb->simplePaginate($perPage, 1);

        $this->assertArrayHasKey('data', $page);
        $this->assertIsArray($page['data']);

        // Originalkod:
        //  - items = 2, perPage = 2 => count(items) > perPage => false
        //  - has_more = false, ingen array_pop => data har fortfarande 2 rader.
        //
        // Mutant (GreaterThan -> >=):
        //  - count(items) >= perPage => true
        //  - has_more = true och array_pop() tar bort en rad => data har 1 rad.
        $this->assertCount(
            $perPage,
            $page['data'],
            'simplePaginate() ska inte ta bort någon rad när antalet items = perPage.'
        );

        $this->assertArrayHasKey('pagination', $page);
        $this->assertFalse(
            $page['pagination']['has_more'],
            'has_more ska vara false när count(items) == perPage.'
        );
    }

    public function testSimplePaginateUsesDefaultPerPageAndCurrentPageWhenNotProvided(): void
    {
        $conn = $this->createMock(Connection::class);

        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Default perPage=10 => LIMIT 11 (perPage+1), default currentPage=1 => OFFSET 0
                    // Dödar:
                    //  - perPage default 9  => LIMIT 10
                    //  - perPage default 11 => LIMIT 12
                    //  - currentPage default 2 => OFFSET 10
                    $this->assertStringContainsString('LIMIT 11 OFFSET 0', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(array_map(
                static fn(int $i): array => ['id' => $i, 'name' => 'U' . $i],
                range(1, 11) // 11 rader så has_more blir true och sista poppas
            ));

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $page = $qb->simplePaginate(); // <— använder defaults

        $this->assertSame(10, $page['pagination']['per_page']);
        $this->assertSame(1, $page['pagination']['current_page']);
        $this->assertTrue($page['pagination']['has_more']);
        $this->assertCount(10, $page['data']);
    }

    public function testSimplePaginateNormalizesNonPositiveCurrentPageToOne(): void
    {
        $conn = $this->createMock(Connection::class);

        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // currentPage=0 ska normaliseras till 1 => OFFSET 0, inte OFFSET -10
                    // Dödar:
                    //  - default currentPage=0 i signaturen (mutant #3) om du anropar simplePaginate(10) i andra testet
                    //  - GreaterThan->>= mutanten (#5): 0 skulle då accepteras och ge OFFSET -10
                    $this->assertStringContainsString('LIMIT 11 OFFSET 0', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(array_map(
                static fn(int $i): array => ['id' => $i, 'name' => 'U' . $i],
                range(1, 11)
            ));

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $page = $qb->simplePaginate(10, 0);

        $this->assertSame(1, $page['pagination']['current_page'], 'current_page ska klampas till 1 när currentPage <= 0.');
        $this->assertSame(10, $page['pagination']['per_page']);
    }

    public function testSimplePaginateDefaultCurrentPageIsOne(): void
    {
        $ref = new ReflectionMethod(QueryBuilder::class, 'simplePaginate');
        $params = $ref->getParameters();

        $this->assertCount(2, $params);

        $currentPageParam = $params[1];
        $this->assertSame('currentPage', $currentPageParam->getName());
        $this->assertTrue($currentPageParam->isDefaultValueAvailable());
        $this->assertSame(
            1,
            $currentPageParam->getDefaultValue(),
            'simplePaginate() ska ha default currentPage=1 (dödar signatur-mutanten currentPage=0).'
        );
    }

    public function testSearchDefaultCurrentPageIsOne(): void
    {
        $ref = new ReflectionMethod(QueryBuilder::class, 'search');
        $params = $ref->getParameters();

        // search(term, columns, perPage, currentPage)
        $this->assertCount(4, $params);

        $currentPageParam = $params[3];
        $this->assertSame('currentPage', $currentPageParam->getName());
        $this->assertTrue($currentPageParam->isDefaultValueAvailable());
        $this->assertSame(
            1,
            $currentPageParam->getDefaultValue(),
            'search() ska ha default currentPage=1 (dödar signatur-mutanten currentPage=0).'
        );
    }

    public function testSearchCurrentPageNonPositiveBecomesOneEvenWhenLastPageIsAtLeastTwo(): void
    {
        $conn = $this->createMock(Connection::class);

        // total=20, perPage=10 => last_page=2 (så mutantens 2 klampas INTE bort)
        $conn->method('fetchOne')->willReturn(['total' => 20]);

        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Original: currentPage<=0 => current_page=1 => offset=0
                    // Mutant:  currentPage<=0 => current_page=2 => offset=10
                    $this->assertStringContainsString('LIMIT 10 OFFSET 0', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([['id' => 1, 'name' => 'A']]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $res = $qb->search('foo', ['name'], 10, 0);

        $this->assertSame(1, $res['search']['current_page']);
        $this->assertSame(2, $res['search']['last_page']);
    }

    public function testSearchBuildsNestedWhereWithFirstAndThenOrBooleansInInternalStructure(): void
    {
        $conn = $this->createMock(Connection::class);

        // Se till att search() inte early-returnar p.g.a. total=0
        $conn->method('fetchOne')->willReturn(['total' => 1]);
        $conn->method('fetchAll')->willReturn([['id' => 1, 'name' => 'A']]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $qb->search('foo', ['name', 'email'], 10, 1);

        // 1) Läs ut outer where-arrayen
        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);
        /** @var array<int, mixed> $outerWheres */
        $outerWheres = $refWhere->getValue($qb);

        // 2) Hitta den "nested/grouped where" som skapats av where(function($q){...})
        $nestedEntry = null;
        foreach ($outerWheres as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // Vanliga format är t.ex:
            //  - ['type' => 'nested', 'query' => QueryBuilder, 'boolean' => 'AND']
            //  - ['type' => 'group',  'query' => QueryBuilder, ...]
            if (isset($entry['query']) && $entry['query'] instanceof QueryBuilder) {
                $nestedEntry = $entry;
                break;
            }
        }

        $this->assertNotNull($nestedEntry, 'search() ska skapa en nested/grouped where-entry som innehåller en QueryBuilder.');

        /** @var QueryBuilder $nestedQb */
        $nestedQb = $nestedEntry['query'];

        // 3) Läs wheres inne i den nested queryn
        /** @var array<int, mixed> $nestedWheres */
        $nestedWheres = $refWhere->getValue($nestedQb);

        $this->assertGreaterThanOrEqual(2, count($nestedWheres), 'Nested where ska innehålla minst två villkor för två sökkolumner.');

        $first = $nestedWheres[0];
        $second = $nestedWheres[1];

        $this->assertIsArray($first);
        $this->assertIsArray($second);

        $this->assertArrayHasKey('boolean', $first);
        $this->assertArrayHasKey('boolean', $second);

        // Dödar:
        //  - TrueValue (#2): $first startar som false => första blir OR-variant
        //  - IfNegation (#3): if (!$first) flippar grenen => första blir OR-variant
        $this->assertSame('AND', $first['boolean'], 'Första villkoret i nested where ska ha boolean AND.');
        $this->assertSame('OR', $second['boolean'], 'Andra villkoret i nested where ska ha boolean OR.');
    }

    public function testSearchNormalizesCurrentPageZeroToOneAndNeverUsesNegativeOffset(): void
    {
        $conn = $this->createMock(Connection::class);

        // total > 0 så att search() går vidare till fetchAll
        $conn->method('fetchOne')->willReturn(['total' => 1]);

        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // currentPage=0 ska normaliseras till 1 => OFFSET 0 (inte OFFSET -10)
                    // Dödar GreaterThan-mutanten (>= 0) som skulle ge OFFSET -10.
                    $this->assertStringContainsString('OFFSET 0', $sql);
                    $this->assertStringNotContainsString('OFFSET -', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([
                ['id' => 1, 'name' => 'A'],
            ]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $res = $qb->search('foo', ['name'], 10, 0);

        $this->assertSame(1, $res['search']['current_page'], 'currentPage=0 ska bli current_page=1 i metadata.');
    }

    public function testSearchClampsNonPositiveCurrentPageToOneAndBuildsGroupedOrLikesInOrder(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(['total' => 1]);

        $builder = new class ($conn) extends QueryBuilder {
            private Connection $conn;

            /** @var class-string<\Radix\Database\ORM\Model>|null */
            protected ?string $modelClass = \Radix\Database\ORM\Model::class;

            public function __construct(Connection $conn)
            {
                $this->conn = $conn;
            }

            protected function getConnection(): Connection
            {
                return $this->conn;
            }

            public function get(): \Radix\Collection\Collection
            {
                // Undvik riktig DB – search() ska ändå bygga SQL + metadata.
                return new \Radix\Collection\Collection([]);
            }
        };

        $builder
            ->from('users')
            ->select(['*']); // se till att toSql() funkar stabilt

        $res = $builder->search('foo', ['name', 'email'], 10, -1);

        // Dödar mutant #1: else=2 skulle annars ge current_page=2
        $this->assertSame(1, $res['search']['current_page']);

        // Dödar #2/#3: $first ska ge "LIKE ? OR LIKE ?" i rätt ordning
        $sql = $builder->toSql();

        $posFirst = strpos($sql, 'LIKE ?');
        $posOr = strpos($sql, ' OR ');
        $posSecondLike = strpos($sql, 'LIKE ?', $posOr !== false ? $posOr : 0);

        $this->assertNotFalse($posFirst, 'SQL ska innehålla LIKE ?');
        $this->assertNotFalse($posOr, 'SQL ska innehålla OR mellan sökkolumnerna.');
        $this->assertNotFalse($posSecondLike, 'SQL ska innehålla ett andra LIKE ? efter OR.');
        $this->assertTrue($posFirst < $posOr, 'Första LIKE måste komma före OR.');
        $this->assertTrue($posOr < $posSecondLike, 'OR måste komma före andra LIKE.');
    }

    public function testSearchNormalizesNegativeCurrentPageToOne(): void
    {
        $conn = $this->createMock(Connection::class);

        $conn->method('fetchOne')->willReturn(['total' => 1]);

        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // currentPage=-1 ska normaliseras till 1 => OFFSET 0
                    // Dödar ternary-mutanterna som returnerar 0 eller 2 i else-grenen.
                    $this->assertStringContainsString('OFFSET 0', $sql);
                    $this->assertStringNotContainsString('OFFSET -', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([
                ['id' => 1, 'name' => 'A'],
            ]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $res = $qb->search('foo', ['name'], 10, -1);

        $this->assertSame(1, $res['search']['current_page'], 'Negativ currentPage ska bli current_page=1 i metadata.');
    }

    public function testComplexQueryWithPagination(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['users.id', 'users.name'])
            ->from('users')
            ->where('status', '=', 'active')
            ->orderBy('users.name')
            ->limit(10)
            ->offset(20);

        $this->assertEquals(
            'SELECT `users`.`id`, `users`.`name` FROM `users` WHERE `status` = ? ORDER BY `users`.`name` ASC LIMIT 10 OFFSET 20',
            $query->toSql(),
            'QueryBuilder should generate a SELECT query with WHERE, ORDER BY, LIMIT, and OFFSET clauses.'
        );

        $this->assertEquals(
            ['active'],
            $query->getBindings(),
            'QueryBuilder should bind the WHERE clause values correctly for a complex query.'
        );
    }

    public function testCaseWhenCastsScalarBindingsToSingleBinding(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->select([])
            ->caseWhen([
                // Avsiktligt: bindings som scalar (string), inte array
                ['cond' => '`role` = ?', 'bindings' => 'admin', 'then' => "'A'"],
            ], "'Z'", 'rnk');

        $sql = $q->toSql();

        // Kontrollera att CASE-uttrycket genereras som förväntat
        $this->assertStringContainsString(
            "SELECT CASE WHEN (`role` = ?) THEN 'A' ELSE 'Z' END AS `rnk` FROM `users`",
            $sql
        );

        // Viktigt: bindings ska vara exakt ['admin'], inte ['a','d','m','i','n']
        $this->assertSame(['admin'], $q->getBindings());
    }

    public function testOrderByCaseCastsRankEscapesElseAndNormalizesDirection(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            // rank som sträng, och else med enkelfnutt + riktning i gemener
            ->orderByCase('role', ['admin' => '01'], "O'Reilly", 'desc');

        $sql = $q->toSql();

        // 1) CastInt-mutanten: vi kräver THEN 1, inte THEN 01
        $this->assertStringContainsString(
            "ORDER BY CASE `role` WHEN ? THEN 1",
            $sql
        );

        // 2) UnwrapStrReplace-mutanten: vi kräver korrekt escapad ELSE-sträng
        $this->assertStringContainsString(
            "ELSE 'O''Reilly' END",
            $sql
        );

        // 3) UnwrapStrToUpper-mutanten: 'desc' ska bli 'DESC'
        $this->assertStringEndsWith(
            'DESC',
            $sql
        );

        // Bindningen i order-bucket ska vara själva värdet ('admin')
        $this->assertSame(['admin'], $q->getBindings());
    }

    public function testSearchQuery(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['users.id', 'users.name'])
            ->from('users')
            ->whereNull('deleted_at')
            ->where('users.name', 'LIKE', '%John%')
            ->orWhere('users.email', 'LIKE', '%john@example.com%')
            ->limit(10)
            ->offset(0);

        $this->assertEquals(
            'SELECT `users`.`id`, `users`.`name` FROM `users` WHERE `deleted_at` IS NULL AND `users`.`name` LIKE ? OR `users`.`email` LIKE ? LIMIT 10 OFFSET 0',
            $query->toSql(),
            'QueryBuilder should generate a SELECT query with WHERE, OR WHERE, LIMIT, and OFFSET clauses.'
        );

        $this->assertEquals(
            ['%John%', '%john@example.com%'],
            $query->getBindings(),
            'QueryBuilder should bind the search query values correctly.'
        );
    }

    public function testSearchBuildsGroupedWhereLikeThenOrWhereLikeForMultipleColumns(): void
    {
        $conn = $this->createMock(\Radix\Database\Connection::class);

        // COUNT(*) behöver returnera >0 så att search() går vidare till fetchAll
        $conn->method('fetchOne')->willReturn(['total' => 1]);

        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Vi vill bevisa:
                    //  - loopen körs (annars finns inga LIKE alls)
                    //  - första blir where(), andra blir orWhere() => "... LIKE ? OR ... LIKE ?"
                    $this->assertStringContainsString('LIKE ?', $sql);
                    $this->assertStringContainsString(' OR ', $sql);

                    // Vanligtvis blir det en grupperad WHERE via closure: "WHERE (... OR ...)"
                    $this->assertStringContainsString('WHERE (', $sql);

                    // Om $first-mutationen slår till kan du få ett "OR" som första operatorn.
                    $this->assertStringNotContainsString('WHERE OR', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([
                ['id' => 1, 'name' => 'A'],
            ]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $result = $qb->search('foo', ['name', 'email'], 10, 1);

        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
        $this->assertArrayHasKey('search', $result);
        $this->assertSame('foo', $result['search']['term']);
    }

    public function testSearchWithSingleColumnDoesNotUseOrWhere(): void
    {
        $conn = $this->createMock(\Radix\Database\Connection::class);

        $conn->method('fetchOne')->willReturn(['total' => 1]);

        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // En kolumn => ska inte finnas något OR i gruppen
                    $this->assertStringContainsString('LIKE ?', $sql);
                    $this->assertStringNotContainsString(' OR ', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([
                ['id' => 1, 'name' => 'A'],
            ]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $qb->search('foo', ['name'], 10, 1);
    }

    public function testComplexConditions(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('age', '>=', 18)
            ->orWhere('country', 'LIKE', 'Sweden')
            ->whereNotNull('joined_at');

        $this->assertEquals(
            "SELECT * FROM `users` WHERE `age` >= ? OR `country` LIKE ? AND `joined_at` IS NOT NULL",
            $query->toSql()
        );

        $this->assertEquals(
            [18, 'Sweden'],
            $query->getBindings()
        );
    }

    public function testInsertQuery(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->insert(['name' => 'John Doe', 'email' => 'john@example.com']);

        $this->assertEquals(
            "INSERT INTO `users` (`name`, `email`) VALUES (?, ?)",
            $query->toSql()
        );

        $this->assertEquals(
            ['John Doe', 'john@example.com'],
            $query->getBindings()
        );
    }

    public function testUpdateQuery(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('id', '=', 1)
            ->update(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        $this->assertEquals(
            "UPDATE `users` SET `name` = ?, `email` = ? WHERE `id` = ?",
            $query->toSql(),
            'QueryBuilder ska generera korrekt UPDATE-syntax.'
        );

        $this->assertEquals(
            ['Jane Doe', 'jane@example.com', 1],
            $query->getBindings(),
            'QueryBuilder ska korrekt hantera bindningsvärden för UPDATE med WHERE-villkor.'
        );
    }

    public function testDeleteQuery(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('id', '=', 1)
            ->delete();

        $this->assertEquals(
            "DELETE FROM `users` WHERE `id` = ?",
            $query->toSql()
        );

        $this->assertEquals(
            [1],
            $query->getBindings()
        );
    }

    public function testDeleteQueryWithDuplicateBindings(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('id', '=', 1)
            ->where('id', '=', 1) // Dubblett
            ->delete();

        $this->assertEquals(
            'DELETE FROM `users` WHERE `id` = ? AND `id` = ?',
            $query->toSql()
        );

        $this->assertEquals(
            [1, 1],
            $query->getBindings(),
            'QueryBuilder ska hantera multiples bindningar korrekt, utan dubbelfiltering.'
        );
    }

    public function testUnionQueries(): void
    {
        $query1 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'name'])
            ->from('users')
            ->where('status', '=', 'active');

        $query2 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'name'])
            ->from('archived_users')
            ->where('status', '=', 'active');

        $unionQuery = $query1->union($query2);

        $this->assertEquals(
            'SELECT `id`, `name` FROM `users` WHERE `status` = ? UNION SELECT `id`, `name` FROM `archived_users` WHERE `status` = ?',
            $unionQuery->toSql(),
            'QueryBuilder should generate correct UNION syntax.'
        );

        $this->assertEquals(
            ['active', 'active'],
            $unionQuery->getBindings(),
            'QueryBuilder should merge bindings correctly for UNION queries.'
        );
    }

    public function testSubqueries(): void
    {
        $subQuery = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id'])
            ->from('payments')
            ->where('amount', '>', 100);

        $mainQuery = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['name'])
            ->from('users')
            ->where('id', 'IN', $subQuery)
            ->where('status', '=', 'active');

        $this->assertEquals(
            'SELECT `name` FROM `users` WHERE `id` IN (SELECT `id` FROM `payments` WHERE `amount` > ?) AND `status` = ?',
            $mainQuery->toSql(),
            'QueryBuilder should handle subqueries correctly.'
        );

        $this->assertEquals(
            [100, 'active'],
            $mainQuery->getBindings(),
            'QueryBuilder should merge bindings correctly for subqueries.'
        );
    }

    public function testWhereExistsAcceptsClosureAndMergesBindings(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->whereExists(function (QueryBuilder $sub): void {
                $sub->setConnection($this->connection)
                    ->select(['1'])
                    ->from('orders')
                    ->whereColumn('orders.user_id', '=', 'users.id')
                    ->where('status', '=', 'paid');
            });

        $this->assertSame(
            'SELECT * FROM `users` WHERE EXISTS (SELECT 1 FROM `orders` WHERE `orders`.`user_id` = `users`.`id` AND `status` = ?)',
            $query->toSql()
        );

        $this->assertSame(
            ['paid'],
            $query->getBindings()
        );
    }

    public function testWhereNotExistsAcceptsClosureAndMergesBindings(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->setConnection($this->connection)
                    ->select(['1'])
                    ->from('orders')
                    ->whereColumn('orders.user_id', '=', 'users.id')
                    ->where('status', '=', 'pending');
            });

        $this->assertSame(
            'SELECT * FROM `users` WHERE NOT EXISTS (SELECT 1 FROM `orders` WHERE `orders`.`user_id` = `users`.`id` AND `status` = ?)',
            $query->toSql()
        );

        $this->assertSame(
            ['pending'],
            $query->getBindings()
        );
    }

    public function testUnionAll(): void
    {
        $query1 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'name'])
            ->from('users')
            ->where('status', '=', 'active');

        $query2 = (new QueryBuilder())
            ->select(['id', 'name'])
            ->from('archived_users')
            ->where('status', '=', 'inactive');

        $unionAllQuery = $query1->union($query2, true);

        $this->assertEquals(
            'SELECT `id`, `name` FROM `users` WHERE `status` = ? UNION ALL SELECT `id`, `name` FROM `archived_users` WHERE `status` = ?',
            $unionAllQuery->toSql(),
            'QueryBuilder ska generera korrekt UNION ALL syntax.'
        );

        $this->assertEquals(
            ['active', 'inactive'],
            $unionAllQuery->getBindings(),
            'QueryBuilder ska hantera bindningar korrekt för UNION ALL.'
        );
    }

    public function testMultipleUnions(): void
    {
        $query1 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'name'])
            ->from('users')
            ->where('status', '=', 'active');

        $query2 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'name'])
            ->from('archived_users')
            ->where('status', '=', 'inactive');

        $query3 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'name'])
            ->from('deleted_users')
            ->where('status', '=', 'deleted');

        $unionQuery = $query1->union($query2)->union($query3);

        $this->assertEquals(
            'SELECT `id`, `name` FROM `users` WHERE `status` = ? UNION SELECT `id`, `name` FROM `archived_users` WHERE `status` = ? UNION SELECT `id`, `name` FROM `deleted_users` WHERE `status` = ?',
            $unionQuery->toSql(),
            'QueryBuilder ska stödja flera union-frågor.'
        );

        $this->assertEquals(
            ['active', 'inactive', 'deleted'],
            $unionQuery->getBindings(),
            'QueryBuilder ska korrekt hantera bindningar för flera union-frågor.'
        );
    }

    public function testUnionWithSubqueries(): void
    {
        $subQuery1 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id'])
            ->from('payments')
            ->where('amount', '>', 100);

        $query1 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'name'])
            ->from('users')
            ->where('id', 'IN', $subQuery1);

        $query2 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'name'])
            ->from('archived_users')
            ->where('status', '=', 'inactive');

        $unionQuery = $query1->union($query2);

        $this->assertEquals(
            'SELECT `id`, `name` FROM `users` WHERE `id` IN (SELECT `id` FROM `payments` WHERE `amount` > ?) UNION SELECT `id`, `name` FROM `archived_users` WHERE `status` = ?',
            $unionQuery->toSql(),
            'QueryBuilder ska hantera unioner korrekt med subqueries.'
        );

        $this->assertEquals(
            [100, 'inactive'],
            $unionQuery->getBindings(),
            'QueryBuilder ska hantera bindningar korrekt för unioner med subqueries.'
        );
    }

    public function testUnionWithInvalidType(): void
    {
        $this->expectException(TypeError::class);

        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'name'])->from('users');
        /** @var mixed $invalid */
        $invalid = 123; // avsiktligt fel typ
        // @phpstan-ignore-next-line Avsiktligt fel typ för att testa TypeError
        $query->union($invalid);
    }

    public function testUnionWithoutBindings(): void
    {
        $query1 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'name'])
            ->from('users');

        $query2 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'name'])
            ->from('archived_users');

        $unionQuery = $query1->union($query2);

        $this->assertEquals(
            'SELECT `id`, `name` FROM `users` UNION SELECT `id`, `name` FROM `archived_users`',
            $unionQuery->toSql(),
            'QueryBuilder ska generera korrekt UNION utan bindningsvärden.'
        );

        $this->assertEquals(
            [],
            $unionQuery->getBindings(),
            'QueryBuilder ska hantera tomma bindningar korrekt för unioner.'
        );
    }

    public function testAggregateFunctions(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->count('*', 'total_users')
            ->max('age', 'oldest_user')
            ->avg('age', 'average_age');

        $this->assertEquals(
            'SELECT COUNT(*) AS `total_users`, MAX(`age`) AS `oldest_user`, AVG(`age`) AS `average_age` FROM `users`',
            $query->toSql(),
            'QueryBuilder ska generera korrekt SQL med flera aggregatfunktioner.'
        );

        $this->assertEquals([], $query->getBindings(), 'QueryBuilder ska ha noll bindningar för rena aggregatfunktioner.');
    }

    public function testAvgResetsWildcardAndWrapsColumn(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->avg('age', 'avg_age');

        // Utgångsläget är columns = ['*']; avg() ska ta bort '*' och bara lägga till AVG().
        $this->assertSame(
            'SELECT AVG(`age`) AS `avg_age` FROM `users`',
            $query->toSql()
        );
    }

    public function testMaxResetsWildcardAndWrapsColumn(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->max('age', 'max_age');

        // Samma logik som avg(): inga '*', bara MAX().
        $this->assertSame(
            'SELECT MAX(`age`) AS `max_age` FROM `users`',
            $query->toSql()
        );
    }

    /**
     * Dödar mutanter 107–110 (PregMatchMatches/RemoveCaret/RemoveDollar/RemoveFlags)
     * i CompilesSelect::select för mönstret "FUNC(col) AS alias".
     */
    public function testSelectFunctionWithAliasWrapsParametersAndAlias(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('orders')
            ->select(['SUM(price) AS total_price']);

        $this->assertSame(
            'SELECT SUM(price) AS `total_price` FROM `orders`',
            $query->toSql(),
            'select() ska känna igen FUNKTION(col) AS alias och wrappa alias korrekt (parametern lämnas som rå i dagsläget).'
        );
    }

    public function testSelectAliasIsCaseInsensitive(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->select(['name as nick']);

        $this->assertSame(
            'SELECT `name` AS `nick` FROM `users`',
            $query->toSql()
        );
    }

    public function testSelectAliasTrimsColumnAndAliasParts(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->select(['  name   AS   nick  ']);

        $this->assertSame(
            'SELECT `name` AS `nick` FROM `users`',
            $query->toSql(),
            'select() ska trimma både kolumn- och alias-delarna runt AS.'
        );
    }

    public function testSelectFunctionWithAliasIsCaseInsensitive(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('orders')
            ->select(['sum(price) as total_price']);

        // Regexen i CompilesSelect::select har /i-flagga:
        //  - original: matchar "sum(" (gemener) => funktionsgrenen körs
        //  - mutant (utan i): matchar inte => går via wrapColumn och beter sig annorlunda.
        //
        // Implementationens gren uppercasar funktionsnamnet med strtoupper(),
        // så vi förväntar oss "SUM(...)" i utdata.
        $this->assertSame(
            'SELECT SUM(price) AS `total_price` FROM `orders`',
            $query->toSql(),
            'select() ska behandla funktionsnamn case-insensitivt när formen är funktionsalias (FUNC(col) AS alias).'
        );
    }

    public function testSelectDefaultColumnsParameterIsWildcardArray(): void
    {
        $ref = new ReflectionMethod(QueryBuilder::class, 'select');
        $params = $ref->getParameters();

        $this->assertCount(1, $params);

        $columnsParam = $params[0];
        $this->assertSame('columns', $columnsParam->getName());
        $this->assertTrue($columnsParam->isDefaultValueAvailable());

        // Dödar ArrayItemRemoval-mutanten: ['*'] -> []
        $this->assertSame(
            ['*'],
            $columnsParam->getDefaultValue(),
            "select() ska ha default columns=['*']."
        );
    }

    public function testSelectFunctionWithAliasTrimsCommaSeparatedParameters(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('orders')
            // Medvetet whitespace runt parametrar och komma
            ->select(['SUM( price ,  amount ) AS total']);

        // Dödar 102 (UnwrapArrayMap): utan trim blir det "SUM( price ,  amount )"
        $this->assertSame(
            'SELECT SUM(price, amount) AS `total` FROM `orders`',
            $query->toSql()
        );
    }

    public function testWhereRawStringIsTrimmedBeforeAppendingToSql(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->select(['*']);

        // whereRawString kommer från JsonFunctions-traiten; injicera den för att testa trim i toSql().
        $ref = new ReflectionProperty(QueryBuilder::class, 'whereRawString');
        $ref->setAccessible(true);
        $ref->setValue($qb, "   `age` > 18   ");

        // Dödar 105 (UnwrapTrim): utan trim blir "WHERE    `age` > 18   " (med spaces kvar).
        $this->assertSame(
            'SELECT * FROM `users` WHERE `age` > 18',
            $qb->toSql()
        );
    }

    public function testSelectFunctionAliasRegexMustMatchFromStartAndNotDropPrefixes(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('orders')
            // Prefixen "foo " gör att FUNKTION-regexen INTE ska matcha (pga ^)
            ->select(['foo SUM(price) AS total']);

        // Utan caret (mutant 101) matchar den mitt i strängen och tappar "foo ".
        $this->assertSame(
            'SELECT foo SUM(price) AS `total` FROM `orders`',
            $q->toSql()
        );
    }

    public function testSelectAliasRegexMustMatchFromStartAndNotDropPrefixes(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            // Prefix "foo " ska bevaras; alias-regexen ska inte få matcha från mitten.
            ->select(['foo `name` AS nick']);

        // Om ^ tas bort kan den matcha på "`name` AS nick" och tappa "foo ".
        $this->assertSame(
            'SELECT foo `name` AS `nick` FROM `users`',
            $q->toSql()
        );
    }

    public function testToSqlTreatsLowercaseFunctionLikeSumAsRawAndMustNotCallWrapColumn(): void
    {
        $builder = new class extends QueryBuilder {
            public bool $wrapColumnCalled = false;

            /** @param array<int,string> $cols */
            public function setRawColumnsForTest(array $cols): void
            {
                $this->columns = $cols;
            }

            protected function wrapColumn(string $column): string
            {
                $this->wrapColumnCalled = true;
                return 'WRAPPED(' . $column . ')';
            }
        };

        $builder->setConnection($this->connection);

        // Sätt tabellen utan att trigga from() -> wrapColumn()
        $refTable = new ReflectionProperty(QueryBuilder::class, 'table');
        $refTable->setAccessible(true);
        $refTable->setValue($builder, '`users`');

        $builder->setRawColumnsForTest(['sum(amount)']);

        $this->assertSame(
            'SELECT sum(amount) FROM `users`',
            $builder->toSql()
        );

        // Dödar PregMatchRemoveFlags: utan /i går koden in i wrapColumn()-grenen
        $this->assertFalse(
            $builder->wrapColumnCalled,
            'wrapColumn() får inte anropas för lowercase funktionsuttryck som ska behandlas som råa.'
        );
    }

    public function testConcatDoesNotCallWrapColumnForStringLiteralsButDoesForRealColumns(): void
    {
        $q = new class extends QueryBuilder {
            /** @var list<string> */
            public array $wrapped = [];

            protected function wrapColumn(string $column): string
            {
                $this->wrapped[] = $column;
                return parent::wrapColumn($column);
            }
        };

        $q->setConnection($this->connection);

        // Sätt tabellen utan att trigga from() -> wrapColumn('users')
        $refTable = new ReflectionProperty(QueryBuilder::class, 'table');
        $refTable->setAccessible(true);
        $refTable->setValue($q, '`users`');

        $q->concat(["' - '", 'name'], 'display_name');

        $this->assertSame(
            "SELECT CONCAT(' - ', `name`) AS `display_name` FROM `users`",
            $q->toSql()
        );

        // Nu ska wrapColumn() bara ha körts för "name" (inte för "' - '")
        $this->assertSame(
            ['name'],
            $q->wrapped,
            "concat() får inte skicka strängliteraler till wrapColumn()."
        );
    }

    public function testConcatDoesNotWrapPureStringLiteralThatContainsBackticks(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            // Om regex-ankare/return försvinner kan denna skickas in i wrapColumn()
            // och då förändras uttrycket (wrapColumn lämnar inte alltid detta stabilt).
            ->concat(["'`x`'"], 'lit');

        $this->assertSame(
            "SELECT CONCAT('`x`') AS `lit` FROM `users`",
            $q->toSql()
        );
    }

    public function testConcatLeavesPureStringLiteralsUnwrappedAndWrapsRealColumns(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->concat(["' - '", 'name'], 'display_name');

        // Dödar 106/107 (regex-ankare): om /^...$/ muteras kan "' - '" feltolkas och wrappas.
        // Dödar 108 (ReturnRemoval): om return tas bort så skickas literal in i wrapColumn() och SQL ändras.
        $this->assertSame(
            "SELECT CONCAT(' - ', `name`) AS `display_name` FROM `users`",
            $query->toSql()
        );
    }

    public function testSelectWithoutArgumentsSelectsStarAndResetsPreviousExplicitSelection(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->select(['id']);

        $this->assertSame(
            'SELECT `id` FROM `users`',
            $q->toSql()
        );

        // Anropa select() utan argument: ska betyda "SELECT *", inte tom kolumnlista.
        // Dödar ArrayItemRemoval i signaturen och i intern logik (99–101).
        $q->select();

        $this->assertSame(
            'SELECT * FROM `users`',
            $q->toSql()
        );
    }

    /**
     * Dödar UnwrapTrim-mutanten på compileWhere (112).
     * Vi kräver att whereRaw-strängen trimmas innan den sätts in i SQL.
     */
    public function testWhereRawTrimsExpressionAndCombinesWithNormalWhere(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('active', '=', 1)
            // Medvetet extra mellanslag runt uttrycket
            ->whereRaw('   age > 18   ');

        $sql = $query->toSql();

        // I nuvarande implementation wrappas uttrycket i parenteser och
        // mellanslagen inuti parentesen bevaras.
        $this->assertSame(
            'SELECT * FROM `users` WHERE `active` = ? AND (   age > 18   )',
            $sql
        );

        $this->assertSame(
            [1],
            $query->getBindings(),
            'Endast bindningen från den vanliga where()-delen ska finnas.'
        );
    }

    /**
     * Dödar PregMatchRemoveCaret / PregMatchRemoveDollar / ReturnRemoval (113–115)
     * i Functions::concat – strängliteraler ska lämnas orörda medan kolumner wrappas.
     */
    public function testConcatLeavesStringLiteralsUnwrappedAndWrapsColumns(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            // En ren strängliteral + en kolumn
            ->concat(["' - '", 'name'], 'display_name');

        $sql = $query->toSql();

        // Förväntat:
        //  - "' - '" ska INTE wrappas (lämnas som strängliteral)
        //  - name ska wrappas till `name`
        //
        // PregMatchRemoveCaret / RemoveDollar: regexen matchar fel/för brett
        // ReturnRemoval: strängliteral skickas vidare till wrapColumn()
        $this->assertSame(
            "SELECT CONCAT(' - ', `name`) AS `display_name` FROM `users`",
            $sql,
            'concat() ska lämna rena strängliteraler orörda och wrappa kolumnnamn.'
        );
    }

    public function testSelectRaw(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->selectRaw("COUNT(id) AS total, NOW() AS current_time")
            ->from('users');

        $this->assertEquals(
            'SELECT COUNT(id) AS total, NOW() AS current_time FROM `users`',
            $query->toSql(),
            'QueryBuilder ska hantera raw SQL expressions korrekt i SELECT.'
        );
    }

    public function testToSqlDoesNotWrapParenthesizedColumnsWhenInjectedRaw(): void
    {
        $builder = new class extends QueryBuilder {
            /** @param array<int,string> $cols */
            public function setRawColumnsForTest(array $cols): void
            {
                $this->columns = $cols;
            }
        };

        $builder
            ->setConnection($this->connection)
            ->from('users');

        // Kolumn som börjar med "(" ska behandlas som rå, inte wrappas
        $builder->setRawColumnsForTest(['(SELECT 1) AS one']);

        $sql = $builder->toSql();

        // Utan mutanter: "(SELECT 1) AS one" lämnas orört
        // Med LogicalOrSingleSubExprNegation/ReturnRemoval:
        //  -> hela uttrycket wrappas som kolumnnamn
        $this->assertSame(
            'SELECT (SELECT 1) AS one FROM `users`',
            $sql
        );
    }

    public function testToSqlTreatsLowercaseFunctionNamesAsRawExpressionsWhenInjectedRaw(): void
    {
        $builder = new class extends QueryBuilder {
            /** @param array<int,string> $cols */
            public function setRawColumnsForTest(array $cols): void
            {
                $this->columns = $cols;
            }
        };

        $builder
            ->setConnection($this->connection)
            ->from('users');

        // Lägre-case funktionsnamn ska fortfarande upptäckas som rå funktion, inte wrappas.
        $builder->setRawColumnsForTest(['sum(amount)']);

        $sql = $builder->toSql();

        // Utan mutanten PregMatchRemoveFlags på /[A-Z]+\(/i:
        //  - 'sum(' matchar regexen case-insensitivt => uttrycket lämnas orört.
        // Med mutanten (utan 'i'-flaggan) matchar inte 'sum(',
        //  och wrapColumn() wrappas => `sum(amount)` eller liknande.
        $this->assertSame(
            'SELECT sum(amount) FROM `users`',
            $sql
        );
    }

    public function testToSqlWrapsPlainColumnsWhenInjectedRaw(): void
    {
        $builder = new class extends QueryBuilder {
            /** @param array<int,string> $cols */
            public function setRawColumnsForTest(array $cols): void
            {
                $this->columns = $cols;
            }
        };

        $builder
            ->setConnection($this->connection)
            ->from('users');

        // En helt vanlig kolumn ska wrappas med backticks.
        $builder->setRawColumnsForTest(['foo']);

        $sql = $builder->toSql();

        // Original: `foo` wrappas.
        // Med LogicalOrAllSubExprNegation-mutanten blir villkoret i if-satsen
        // nästan alltid true, så foo behandlas som rå och lämnas owrappad.
        $this->assertSame(
            'SELECT `foo` FROM `users`',
            $sql
        );
    }

    public function testSelectDoesNotWrapParenthesizedExpressions(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            // Fullständigt uttryck som börjar med "(" ska lämnas orört
            ->select(['(SELECT 1) AS one']);

        $this->assertSame(
            'SELECT (SELECT 1) AS `one` FROM `users`',
            $query->toSql(),
            'Kolumner som börjar med "(" ska inte wrappas om av select-logiken.'
        );
    }

    public function testToSqlDoesNotWrapCountIdentifierWhenInjectedRaw(): void
    {
        $builder = new class extends QueryBuilder {
            /** @param array<int,string> $cols */
            public function setRawColumnsForTest(array $cols): void
            {
                $this->columns = $cols;
            }
        };

        $builder
            ->setConnection($this->connection)
            ->from('users');

        // En ren identifier "COUNT" (utan parentes) matchar INTE funktions-regexen,
        // men str_starts_with('COUNT') är true. Original-koden ska därför
        // behandla den som rå och inte wrappa.
        $builder->setRawColumnsForTest(['COUNT']);

        $sql = $builder->toSql();

        // Utan mutanter: ingen wrappning.
        // Med LogicalOr-mutanten (&& mellan preg_match och str_starts_with('COUNT'))
        // eller SingleSubExprNegation på COUNT: uttrycket wrappas -> `COUNT`.
        $this->assertSame(
            'SELECT COUNT FROM `users`',
            $sql
        );
    }

    public function testToSqlDoesNotWrapParenthesizedColumnWithoutFunctionWhenInjectedRaw(): void
    {
        $builder = new class extends QueryBuilder {
            /** @param array<int,string> $cols */
            public function setRawColumnsForTest(array $cols): void
            {
                $this->columns = $cols;
            }
        };

        $builder
            ->setConnection($this->connection)
            ->from('users');

        // Kolumn som börjar med "(" men utan funktionsnamn före "(".
        // Detta ska trigga just str_starts_with('(')-grenen i CompilesSelect.
        $builder->setRawColumnsForTest(['(foo)']);

        $sql = $builder->toSql();

        // Förväntat: lämna uttrycket orört.
        // Med LogicalOrSingleSubExprNegation-mutanten på "(" wrappas '(foo)' som kolumn
        // och testet faller.
        $this->assertSame(
            'SELECT (foo) FROM `users`',
            $sql
        );
    }

    public function testToSqlDoesNotWrapColumnsContainingNowWhenInjectedRaw(): void
    {
        $builder = new class extends QueryBuilder {
            /** @param array<int,string> $cols */
            public function setRawColumnsForTest(array $cols): void
            {
                $this->columns = $cols;
            }
        };

        $builder
            ->setConnection($this->connection)
            ->from('users');

        // Kolumn med "NOW" i sig – ska behandlas som råt uttryck, inte wrappas.
        $builder->setRawColumnsForTest(['foo_NOW_bar']);

        $sql = $builder->toSql();

        // Utan mutanter: inga backticks runt foo_NOW_bar
        $this->assertSame(
            'SELECT foo_NOW_bar FROM `users`',
            $sql
        );
    }

    public function testAliasHandling(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['u.id AS user_id', 'u.name AS user_name'])
            ->from('users AS u')
            ->where('u.status', '=', 'active');

        $this->assertEquals(
            'SELECT `u`.`id` AS `user_id`, `u`.`name` AS `user_name` FROM `users` AS `u` WHERE `u`.`status` = ?',
            $query->toSql(),
            'QueryBuilder ska korrekt hantera alias till tabeller och kolumner.'
        );

        $this->assertEquals(['active'], $query->getBindings(), 'Bindningarna ska vara korrekt extraherade vid aliasering.');
    }

    public function testJoins(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->join('profiles', 'users.id', '=', 'profiles.user_id')
            ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
            ->fullJoin('addresses', 'users.id', '=', 'addresses.user_id');

        $this->assertEquals(
            'SELECT * FROM `users` INNER JOIN `profiles` ON `users`.`id` = `profiles`.`user_id` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id` FULL OUTER JOIN `addresses` ON `users`.`id` = `addresses`.`user_id`',
            $query->toSql(),
            'QueryBuilder ska supporta olika typer av JOIN-klausuler.'
        );
    }

    public function testGroupByAndHaving(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['role', 'COUNT(*) AS total_employees'])
            ->from('users')
            ->groupBy('role')
            ->having('total_employees', '>', 10);

        $this->assertEquals(
            'SELECT `role`, COUNT(*) AS `total_employees` FROM `users` GROUP BY `role` HAVING `total_employees` > ?',
            $query->toSql(),
            'QueryBuilder ska generera korrekt SQL med GROUP BY och HAVING.'
        );

        $this->assertEquals([10], $query->getBindings());
    }

    public function testEmptyWhereClause(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')->where('', '=', 'active');
    }

    public function testInsertWithEmptyData(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QueryBuilder())->from('users')->insert([]);
    }

    public function testTransactionRollback(): void
    {
        // Skapa en mockad Connection
        $mockConnection = $this->createMock(\Radix\Database\Connection::class);

        // Förvänta att transaktionsmetoderna kallas i rätt ordning
        $mockConnection->expects($this->once())
            ->method('beginTransaction');

        $mockConnection->expects($this->once())
            ->method('rollbackTransaction');

        $mockConnection->expects($this->never())
            ->method('commitTransaction'); // commit ska inte anropas vid rollback

        // Skapa en QueryBuilder och sätt mockad Connection
        $query = (new QueryBuilder())->setConnection($mockConnection);

        // Verifiera att undantag kastas (för rollback)
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Simulated error");

        // Anropa metoden och simulera ett misslyckande
        $query->transaction(function () {
            throw new Exception("Simulated error");
        });
    }

    public function testTransactionUsesOverriddenStartAndRollbackTransactionInSubclass(): void
    {
        $mockConnection = $this->createMock(\Radix\Database\Connection::class);

        // Vi vill bara se att transaction()-flödet körs; commit ska inte ske p.g.a exception.
        $mockConnection->expects($this->never())->method('commitTransaction');
        // Låt rollback finnas (men vi behöver inte kräva att den anropas här, då override tar över).
        $mockConnection->method('rollbackTransaction');

        $builder = new class extends QueryBuilder {
            public bool $startCalled = false;
            public bool $rollbackCalled = false;

            protected function startTransaction(): void
            {
                $this->startCalled = true;
                parent::startTransaction();
            }

            protected function rollbackTransaction(): void
            {
                $this->rollbackCalled = true;
                parent::rollbackTransaction();
            }
        };

        $builder->setConnection($mockConnection);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('boom');

        try {
            $builder->transaction(function (): void {
                throw new Exception('boom');
            });
        } finally {
            $this->assertTrue($builder->startCalled, 'startTransaction() ska kunna override:as och anropas via transaction().');
            $this->assertTrue($builder->rollbackCalled, 'rollbackTransaction() ska kunna override:as och anropas via transaction().');
        }
    }

    public function testDistinctQuery(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->distinct()
            ->from('users')
            ->select(['name', 'email']);

        $this->assertEquals(
            'SELECT DISTINCT `name`, `email` FROM `users`',
            $query->toSql(),
            'QueryBuilder should generate a SQL query with DISTINCT keyword.'
        );
    }

    public function testConcatColumns(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->concat(['first_name', "' '", 'last_name'], 'full_name');

        $this->assertEquals(
            'SELECT CONCAT(`first_name`, \' \', `last_name`) AS `full_name` FROM `users`',
            $query->toSql(),
            'QueryBuilder should generate a SQL query using CONCAT for columns.'
        );
    }

    public function testConcatDoesNotWrapStringLiteralsWithDots(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            // Mellanargumentet är en ren strängliteral med punkt i sig
            ->concat(["'foo.bar'"], 'slug');

        // Funktionen ska lämna `'foo.bar'` orörd och inte wrappa den som kolumn.
        // PregMatchRemoveCaret/PregMatchRemoveDollar/ReturnRemoval-mutanter gör
        // att wrapColumn() anropas på denna literal, vilket ändrar SQL-strängen.
        $this->assertSame(
            "SELECT CONCAT('foo.bar') AS `slug` FROM `users`",
            $query->toSql()
        );
    }

    public function testWhereIn(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->whereIn('id', [1, 2, 3]);

        $this->assertEquals(
            'SELECT * FROM `users` WHERE `id` IN (?, ?, ?)',
            $query->toSql(),
            'QueryBuilder should generate a SQL query with WHERE IN clause.'
        );

        $this->assertEquals(
            [1, 2, 3],
            $query->getBindings(),
            'QueryBuilder should bind values correctly for WHERE IN clause.'
        );
    }

    public function testWhereNotNullAddsConditionForEachDistinctColumn(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->whereNotNull('joined_at')   // första kolumnen
            ->whereNotNull('deleted_at'); // andra kolumnen

        $this->assertSame(
            'SELECT * FROM `users` WHERE `joined_at` IS NOT NULL AND `deleted_at` IS NOT NULL',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function testWhereNotNullDoesNotDuplicateSameColumnAndBoolean(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->whereNotNull('joined_at')
            ->whereNotNull('joined_at'); // samma kolumn, samma boolean (AND)

        // Ska bara finnas EN IS NOT NULL‑klausul för joined_at
        $this->assertSame(
            'SELECT * FROM `users` WHERE `joined_at` IS NOT NULL',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function testWhereNotNullTreatsDifferentBooleansAsDistinctConditions(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->whereNotNull('joined_at', 'AND')
            ->orWhereNotNull('joined_at'); // boolean = OR

        // AND- och OR‑varianterna ska båda finnas
        $this->assertSame(
            'SELECT * FROM `users` WHERE `joined_at` IS NOT NULL OR `joined_at` IS NOT NULL',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function testWhereNullOnDeletedAtIsNoopWhenWithSoftDeletesIsEnabled(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->withSoftDeletes()
            // Denna ska vara NOOP när withSoftDeletes är aktiv
            ->whereNull('deleted_at')
            // Detta villkor ska däremot läggas till
            ->whereNull('created_at');

        $sql = $query->toSql();

        // Endast created_at-klausulen ska finnas, inte deleted_at
        $this->assertSame(
            'SELECT * FROM `users` WHERE `created_at` IS NULL',
            $sql
        );
        $this->assertSame([], $query->getBindings());
    }

    public function testWithSoftDeletesRemovesOnlyDeletedAtIsNullAndKeepsOtherIsNullConditions(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        // Vi injicerar where-arrayen direkt för att få exakt strukturen som SoftDeletes förväntar sig.
        // created_at IS NULL ska INTE tas bort av withSoftDeletes().
        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);

        $refWrap = new ReflectionMethod(QueryBuilder::class, 'wrapColumn');
        $refWrap->setAccessible(true);

        $deletedAt = $refWrap->invoke($qb, 'deleted_at');
        $createdAt = $refWrap->invoke($qb, 'created_at');

        $this->assertIsString($deletedAt, 'wrapColumn(deleted_at) ska returnera string i detta test.');
        $this->assertIsString($createdAt, 'wrapColumn(created_at) ska returnera string i detta test.');

        $refWhere->setValue($qb, [
            [
                'type' => 'raw',
                'column' => $deletedAt,
                'operator' => 'IS NULL',
                'boolean' => 'AND',
            ],
            [
                'type' => 'raw',
                'column' => $createdAt,
                'operator' => 'IS NULL',
                'boolean' => 'AND',
            ],
        ]);

        $qb->withSoftDeletes();

        $sql = $qb->toSql();

        // deleted_at-filter ska bort
        $this->assertStringNotContainsString(
            $deletedAt . ' IS NULL',
            $sql
        );

        // created_at-filter ska vara kvar (dödar LogicalAnd-mutanter som blir för breda)
        $this->assertStringContainsString(
            $createdAt . ' IS NULL',
            $sql
        );
    }

    public function testWithSoftDeletesDoesNotTriggerWarningsForMalformedWhereCondition(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);

        // Malformat villkor: saknar 'column' men har övriga.
        // Original-kod ska "baila" (behålla villkoret) utan att läsa saknad nyckel.
        $malformed = [
            'type' => 'raw',
            // 'column' saknas avsiktligt
            'operator' => 'IS NULL',
            'boolean' => 'AND',
        ];

        // Lägg även till ett “riktigt” villkor så vi kan se att arrayen inte blir tom av misstag.
        $valid = [
            'type' => 'raw',
            'column' => '`created_at`',
            'operator' => 'IS NULL',
            'boolean' => 'AND',
        ];

        $refWhere->setValue($qb, [$malformed, $valid]);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $qb->withSoftDeletes();

            /** @var array<int, mixed> $whereAfter */
            $whereAfter = $refWhere->getValue($qb);

            // Dödar TrueValue-mutanten: malformade villkor ska BEHÅLLAS (return true),
            // inte filtreras bort (return false).
            $this->assertCount(2, $whereAfter, 'withSoftDeletes() ska inte filtrera bort malformade where-villkor.');

            // Extra robust: säkerställ att just vår malformade entry är kvar (inte bara antal)
            $this->assertSame($malformed, $whereAfter[0]);
            $this->assertSame($valid, $whereAfter[1]);
        } finally {
            restore_error_handler();
        }
    }

    public function testGetOnlySoftDeletedIsPublic(): void
    {
        $ref = new ReflectionMethod(QueryBuilder::class, 'getOnlySoftDeleted');

        $this->assertTrue(
            $ref->isPublic(),
            'getOnlySoftDeleted() ska vara public (dödar PublicVisibility-mutanten).'
        );
    }

    public function testWhereNotNullRemovesOnlyNullConditionForSameColumnAndBoolean(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            // Två IS NULL-villkor: olika kolumner, samma boolean (AND)
            ->whereNull('deleted_at')
            ->whereNull('created_at')
            // whereNotNull ska bara ta bort deleted_at-NULL, inte created_at-NULL
            ->whereNotNull('deleted_at');

        $this->assertSame(
            'SELECT * FROM `users` WHERE `created_at` IS NULL AND `deleted_at` IS NOT NULL',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function testWhereNotNullDoesNotRemoveNullConditionWithDifferentBoolean(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            // AND-variant av IS NULL
            ->whereNull('deleted_at')
            // OR-variant av IS NOT NULL för samma kolumn
            ->orWhereNotNull('deleted_at');

        // Båda villkoren ska finnas kvar
        $this->assertSame(
            'SELECT * FROM `users` WHERE `deleted_at` IS NULL OR `deleted_at` IS NOT NULL',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function testSubqueryInWhere(): void
    {
        $subQuery = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id'])
            ->from('payments')
            ->where('amount', '>', 100);

        $mainQuery = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('id', 'IN', $subQuery);

        $this->assertEquals(
            'SELECT * FROM `users` WHERE `id` IN (SELECT `id` FROM `payments` WHERE `amount` > ?)',
            $mainQuery->toSql(),
            'QueryBuilder should generate subquery in WHERE clause correctly.'
        );

        $this->assertEquals(
            [100],
            $mainQuery->getBindings(),
            'QueryBuilder should bind subquery values correctly.'
        );
    }

    public function testLimitAndOffset(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('status', '=', 'active')
            ->orderBy('name')
            ->limit(10)
            ->offset(20);

        $this->assertEquals(
            'SELECT * FROM `users` WHERE `status` = ? ORDER BY `name` ASC LIMIT 10 OFFSET 20',
            $query->toSql(),
            'QueryBuilder should generate correct LIMIT and OFFSET clauses.'
        );

        $this->assertEquals(
            ['active'],
            $query->getBindings(),
            'QueryBuilder should bind values correctly for paginated queries.'
        );
    }

    public function testDeleteWithoutWhereThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("DELETE operation requires a WHERE clause.");

        $query = (new QueryBuilder())->from('users')->delete();
    }

    public function testWrapColumn(): void
    {
        $query = (new QueryBuilder())->setConnection($this->connection);

        $this->assertEquals('`users`.`id`', $query->testWrapColumn('users.id'));
        $this->assertEquals('`name`', $query->testWrapColumn('name'));
        $this->assertEquals('COUNT(*)', $query->testWrapColumn('COUNT(*)'));

        // Viktigt för explode-limit=2: bara första punkten ska splittras.
        $this->assertSame(
            '`users`.`profile.id`',
            $query->testWrapColumn('users.profile.id')
        );
    }

    public function testWrapAlias(): void
    {
        $query = (new QueryBuilder())->setConnection($this->connection);

        $this->assertEquals('`user_count`', $query->testWrapAlias('user_count'));
        $this->assertEquals('`count`', $query->testWrapAlias('count'));

        // Redan korrekt wrappat ska lämnas orört
        $this->assertSame('`u`', $query->testWrapAlias('`u`'));

        // PregMatchRemoveCaret: matchar felaktigt om det FINNS en ` någonstans och alias SLUTAR med `
        // Original: /^`.*`$/ matchar inte (börjar inte med `) => wrappas
        // Mutant:   /`.*`$/ matchar (från första ` till sista `) => lämnas
        $this->assertSame('`foo`bar``', $query->testWrapAlias('foo`bar`'));

        // PregMatchRemoveDollar: matchar felaktigt om alias BÖRJAR med ` och det finns en ` senare (utan krav på slut-`)
        // Original: /^`.*`$/ matchar inte (slutar inte med `) => wrappas
        // Mutant:   /^`.*`/ matchar => lämnas
        $this->assertSame('``foo`bar`', $query->testWrapAlias('`foo`bar'));
    }

    public function testWrapColumnCanBeOverriddenInSubclassAndIsUsedByFrom(): void
    {
        $builder = new class extends QueryBuilder {
            public bool $wrapColumnCalled = false;

            protected function wrapColumn(string $column): string
            {
                $this->wrapColumnCalled = true;
                return parent::wrapColumn($column);
            }
        };

        $builder
            ->setConnection($this->connection)
            ->from('users');

        $this->assertTrue(
            $builder->wrapColumnCalled,
            'from() ska anropa wrapColumn() polymorfiskt (protected), så att overrides i subklasser fungerar.'
        );
    }

    public function testUpperFunction(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->upper('name', 'upper_name');

        $this->assertEquals(
            'SELECT UPPER(`name`) AS `upper_name` FROM `users`',
            $query->toSql(),
            'QueryBuilder should generate correct SQL for UPPER function.'
        );
    }

    public function testYearFunction(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('orders')
            ->year('created_at', 'order_year');

        $this->assertEquals(
            'SELECT YEAR(`created_at`) AS `order_year` FROM `orders`',
            $query->toSql(),
            'QueryBuilder should generate correct SQL for YEAR function.'
        );
    }

    public function testMonthFunction(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('orders')
            ->month('created_at', 'order_month');

        $this->assertEquals(
            'SELECT MONTH(`created_at`) AS `order_month` FROM `orders`',
            $query->toSql(),
            'QueryBuilder should generate correct SQL for MONTH function.'
        );
    }

    public function testDateFunction(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('orders')
            ->date('created_at', 'order_date');

        $this->assertEquals(
            'SELECT DATE(`created_at`) AS `order_date` FROM `orders`',
            $query->toSql(),
            'QueryBuilder should generate correct SQL for DATE function.'
        );
    }

    public function testJoinSubQuery(): void
    {
        $subQuery = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'user_id'])
            ->from('orders')
            ->where('status', '=', 'completed');

        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->joinSub($subQuery, 'completed_orders', 'users.id', '=', 'completed_orders.user_id');

        $this->assertEquals(
            'SELECT * FROM `users` INNER JOIN (SELECT `id`, `user_id` FROM `orders` WHERE `status` = ?) AS `completed_orders` ON `users`.`id` = `completed_orders`.`user_id`',
            $query->toSql(),
            'QueryBuilder should generate correct SQL for joinSub with a subquery.'
        );

        $this->assertEquals(
            ['completed'],
            $query->getBindings(),
            'QueryBuilder should bind values correctly for joinSub.'
        );
    }

    public function testWhereLike(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->whereLike('name', '%John%');

        $this->assertEquals(
            'SELECT * FROM `users` WHERE `name` LIKE ?',
            $query->toSql(),
            'QueryBuilder ska generera en SQL-sökfråga med WHERE LIKE-klausul.'
        );

        $this->assertEquals(
            ['%John%'],
            $query->getBindings(),
            'QueryBuilder ska korrekt binda värden för WHERE LIKE-klausul.'
        );
    }

    public function testWhereLikeWithMultipleConditions(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->whereLike('email', 'john%@example.com')
            ->orWhere('status', '=', 'active');

        $this->assertEquals(
            'SELECT * FROM `users` WHERE `email` LIKE ? OR `status` = ?',
            $query->toSql(),
            'QueryBuilder ska korrekt kombinera WHERE LIKE och OR.'
        );

        $this->assertEquals(
            ['john%@example.com', 'active'],
            $query->getBindings(),
            'QueryBuilder ska korrekt hantera bindningar med flera villkor.'
        );
    }

    public function testWhereNotInBetweenColumnExists(): void
    {
        $sub = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id'])
            ->from('payments')
            ->where('amount', '>', 100);

        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->whereNotIn('role', ['admin', 'editor'])
            ->whereBetween('age', [18, 30])
            ->whereNotBetween('score', [50, 80])
            ->whereColumn('users.country_id', '=', 'countries.id')
            ->whereExists($sub);

        $this->assertEquals(
            'SELECT * FROM `users` WHERE `role` NOT IN (?, ?) AND `age` BETWEEN ? AND ? AND `score` NOT BETWEEN ? AND ? AND `users`.`country_id` = `countries`.`id` AND EXISTS (SELECT `id` FROM `payments` WHERE `amount` > ?)',
            $query->toSql()
        );
        $this->assertEquals(
            ['admin', 'editor', 18, 30, 50, 80, 100],
            $query->getBindings()
        );
    }

    public function testWhereRaw(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->whereRaw('(`first_name` LIKE ? OR `last_name` LIKE ?)', ['%ma%', '%ma%']);

        $this->assertEquals(
            'SELECT * FROM `users` WHERE ((`first_name` LIKE ? OR `last_name` LIKE ?))',
            $query->toSql()
        );
        $this->assertEquals(['%ma%', '%ma%'], $query->getBindings());
    }

    public function testOrderByRawAndHavingRaw(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['role', 'COUNT(*) AS total'])
            ->from('users')
            ->groupBy('role')
            ->havingRaw('COUNT(*) > ?', [5])
            ->orderByRaw('FIELD(role, "admin","editor","user")');

        $this->assertEquals(
            'SELECT `role`, COUNT(*) AS `total` FROM `users` GROUP BY `role` HAVING COUNT(*) > ? ORDER BY FIELD(role, "admin","editor","user")',
            $query->toSql()
        );
        $this->assertEquals([5], $query->getBindings());
    }

    public function testRightJoinAndJoinRaw(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->rightJoin('profiles', 'users.id', '=', 'profiles.user_id')
            ->joinRaw('INNER JOIN `roles` ON `roles`.`id` = `users`.`role_id` AND `roles`.`name` = ?', ['admin']);

        $this->assertEquals(
            'SELECT * FROM `users` RIGHT JOIN `profiles` ON `users`.`id` = `profiles`.`user_id` INNER JOIN `roles` ON `roles`.`id` = `users`.`role_id` AND `roles`.`name` = ?',
            $query->toSql()
        );
        $this->assertEquals(['admin'], $query->getBindings());
    }

    public function testUnionAllWrapper(): void
    {
        $q1 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id'])
            ->from('users')
            ->where('status', '=', 'active');

        $q2 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id'])
            ->from('archived_users')
            ->where('status', '=', 'active');

        $union = $q1->unionAll($q2);

        $this->assertEquals(
            'SELECT `id` FROM `users` WHERE `status` = ? UNION ALL SELECT `id` FROM `archived_users` WHERE `status` = ?',
            $union->toSql()
        );
        $this->assertEquals(['active', 'active'], $union->getBindings());
    }

    public function testSelectSub(): void
    {
        $sub = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['COUNT(*) as c'])
            ->from('orders')
            ->where('orders.user_id', '=', 10);

        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->selectSub($sub, 'order_count');

        $this->assertEquals(
            'SELECT (SELECT COUNT(*) AS `c` FROM `orders` WHERE `orders`.`user_id` = ?) AS `order_count` FROM `users`',
            $query->toSql()
        );
        $this->assertEquals([10], $query->getBindings());
    }

    public function testValueAndPluck(): void
    {
        // value(): vi kontrollerar endast genererad SQL före fetch, via debugSql-logik är svår utan stub.
        $qValue = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('id', '=', 1);
        // Bygg SQL för value (select+limit)
        $qValue->select(['email'])->limit(1);
        $this->assertEquals(
            'SELECT `email` FROM `users` WHERE `id` = ? LIMIT 1',
            $qValue->toSql()
        );
        $this->assertEquals([1], $qValue->getBindings());

        $qPluck = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('status', '=', 'active')
            ->select(['email']);
        $this->assertEquals(
            'SELECT `email` FROM `users` WHERE `status` = ?',
            $qPluck->toSql()
        );
        $this->assertEquals(['active'], $qPluck->getBindings());
    }

    public function testOnlyAndWithoutTrashed(): void
    {
        $q1 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->onlyTrashed();

        $this->assertEquals(
            'SELECT * FROM `users` WHERE `deleted_at` IS NOT NULL',
            $q1->toSql()
        );

        $q2 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->withoutTrashed();

        $this->assertEquals(
            'SELECT * FROM `users` WHERE `deleted_at` IS NULL',
            $q2->toSql()
        );
    }

    public function testWithCteSimpleSelect(): void
    {
        $sub = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'name'])
            ->from('users')
            ->where('status', '=', 'active');

        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('active_users') // huvuddelen ska läsa från CTE-alias
            ->withCte('active_users', $sub)
            ->select(['name'])
            ->orderBy('name');

        $this->assertEquals(
            'WITH `active_users` AS (SELECT `id`, `name` FROM `users` WHERE `status` = ?) SELECT `name` FROM `active_users` ORDER BY `name` ASC',
            $q->toSql()
        );
        $this->assertEquals(['active'], $q->getBindings());
    }

    public function testWithCteMultiple(): void
    {
        $totals = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['user_id', 'SUM(amount) AS total'])
            ->from('payments')
            ->where('status', '=', 'paid')
            ->groupBy('user_id');

        $users = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'email'])
            ->from('users')
            ->where('active', '=', 1);

        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->withCte('totals', $totals)
            ->withCte('u', $users)
            ->from('u')
            ->select(['u.email', 'totals.total'])
            ->join('totals', 'u.id', '=', 'totals.user_id');

        $this->assertEquals(
            'WITH `totals` AS (SELECT `user_id`, SUM(amount) AS `total` FROM `payments` WHERE `status` = ? GROUP BY `user_id`), `u` AS (SELECT `id`, `email` FROM `users` WHERE `active` = ?) SELECT `u`.`email`, `totals`.`total` FROM `u` INNER JOIN `totals` ON `u`.`id` = `totals`.`user_id`',
            $q->toSql()
        );

        // Dödar #21: bindings måste innehålla båda CTE:ernas bindings i ordning
        $this->assertSame(['paid', 1], $q->getBindings());
    }

    public function testWithRecursiveCteMergesBindingsFromAnchorAndRecursive(): void
    {
        $anchor = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id'])
            ->from('categories')
            ->where('id', '=', 123);

        $recursive = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['c.id'])
            ->from('categories AS c')
            ->join('parents AS p', 'p.id', '=', 'c.parent_id')
            ->where('c.active', '=', 1);

        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->withRecursive('parents', $anchor, $recursive, ['id'])
            ->from('parents')
            ->select(['id']);

        $sql = $q->toSql();

        // Vi behöver inte hela SQL-strängen, bara säkerställa att båda WHERE-villkoren finns.
        $this->assertStringContainsString(
            'SELECT `id` FROM `categories` WHERE `id` = ?',
            $sql
        );
        $this->assertStringContainsString(
            'SELECT `c`.`id` FROM `categories` AS `c` INNER JOIN parents AS p ON `p`.`id` = `c`.`parent_id` WHERE `c`.`active` = ?',
            $sql
        );

        // Viktigt: båda bindningarna (anchor + recursive) ska vara med, i rätt ordning.
        $this->assertSame(
            [123, 1],
            $q->getBindings()
        );
    }

    public function testRecursiveCtePlacesColumnListAfterNameBeforeAsAndIncludesRecursiveKeyword(): void
    {
        $anchor = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', '0 AS depth'])
            ->from('categories')
            ->where('id', '=', 123);

        $recursive = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['c.id', 'p.depth + 1'])
            ->from('categories AS c')
            ->join('parents AS p', 'p.id', '=', 'c.parent_id');

        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->withRecursive('parents', $anchor, $recursive, ['id', 'depth'])
            ->from('parents')
            ->select(['id', 'depth']);

        $sql = $q->toSql();

        // Dödar #18 (saknat RECURSIVE) och hjälper #17 (kolumner på rätt plats)
        $this->assertStringStartsWith(
            'WITH RECURSIVE ',
            $sql
        );

        // Dödar #17: kolumnlistan måste komma direkt efter CTE-namnet, före AS (
        $this->assertStringContainsString(
            'WITH RECURSIVE `parents` (`id`, `depth`) AS (',
            $sql
        );
    }

    public function testCompileLockSuffixCanBeOverriddenInSubclass(): void
    {
        // Subklass som override:ar compileLockSuffix för att se om QueryBuilder använder override:n.
        $builder = new class extends QueryBuilder {
            protected function compileLockSuffix(): string
            {
                // Särskiljbar suffix-sträng så vi ser att override:n används.
                return ' /*CUSTOM LOCK*/';
            }
        };

        $builder
            ->setConnection($this->connection)
            ->from('users')
            ->forUpdate(); // sätter lockMode, men compileLockSuffix() är override:ad

        $sql = $builder->toSql();

        // Original (protected): override:n ska användas => SQL:en innehåller vårt custom-suffix.
        // Mutant (private i traiten): override:n körs aldrig => " FOR UPDATE" används istället.
        $this->assertStringEndsWith(
            '/*CUSTOM LOCK*/',
            trim($sql),
            'QueryBuilder ska använda subklassens compileLockSuffix() när den är protected.'
        );
    }

    public function testSelectForUpdate(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('id', '=', 1)
            ->forUpdate();

        $this->assertEquals(
            'SELECT * FROM `users` WHERE `id` = ? FOR UPDATE',
            $q->toSql()
        );
        $this->assertEquals([1], $q->getBindings());
    }

    public function testSelectLockInShareModeWithLimit(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['name'])
            ->from('users')
            ->where('status', '=', 'active')
            ->orderBy('name')
            ->limit(10)
            ->lockInShareMode();

        $this->assertEquals(
            'SELECT `name` FROM `users` WHERE `status` = ? ORDER BY `name` ASC LIMIT 10 LOCK IN SHARE MODE',
            $q->toSql()
        );
        $this->assertEquals(['active'], $q->getBindings());
    }

    public function testRowNumberWindow(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('posts')
            ->select([]) // säkerställ att '*' inte används
            ->rowNumber('row_num', [], [['created_at', 'DESC']]);

        $this->assertEquals(
            'SELECT ROW_NUMBER() OVER (ORDER BY `created_at` DESC) AS `row_num` FROM `posts`',
            $q->toSql()
        );
    }

    public function testRowNumberWindowNormalizesLowercaseDescToDesc(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('posts')
            ->select([])
            ->rowNumber('row_num', [], [['created_at', 'desc']]);

        $sql = $q->toSql();

        // Dödar UnwrapStrToUpper-mutanten i Windows.php:
        // 'desc' ska normaliseras till 'DESC' (inte falla tillbaka till 'ASC').
        $this->assertStringContainsString(
            'ORDER BY `created_at` DESC',
            $sql
        );
    }

    public function testRankPartitionedWindow(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('scores')
            ->select(['user_id'])
            ->rank('r', ['user_id'], [['score', 'DESC']]);

        $this->assertEquals(
            'SELECT `user_id`, RANK() OVER (PARTITION BY `user_id` ORDER BY `score` DESC) AS `r` FROM `scores`',
            $q->toSql()
        );
    }

    public function testSumOverRunningTotal(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('payments')
            ->select(['user_id', 'created_at'])
            ->sumOver('amount', 'running_total', ['user_id'], [['created_at', 'ASC']]);

        $this->assertEquals(
            'SELECT `user_id`, `created_at`, SUM(`amount`) OVER (PARTITION BY `user_id` ORDER BY `created_at` ASC) AS `running_total` FROM `payments`',
            $q->toSql()
        );
    }

    public function testWindowRaw(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('leaderboard')
            ->select(['id'])
            ->windowRaw('NTILE(4) OVER (ORDER BY `score` DESC)', 'quart');

        $this->assertEquals(
            'SELECT `id`, NTILE(4) OVER (ORDER BY `score` DESC) AS `quart` FROM `leaderboard`',
            $q->toSql()
        );
    }

    public function testCteWithUpdateMutation(): void
    {
        $cte = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id'])
            ->from('users')
            ->where('email', 'LIKE', '%@example.com');

        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->withCte('to_fix', $cte)
            ->where(
                'id',
                'IN',
                (new QueryBuilder())
                ->setConnection($this->connection)
                ->select(['id'])
                ->from('to_fix')
            )
            ->update(['status' => 'inactive']);

        $this->assertEquals(
            'WITH `to_fix` AS (SELECT `id` FROM `users` WHERE `email` LIKE ?) UPDATE `users` SET `status` = ? WHERE `id` IN (SELECT `id` FROM `to_fix`)',
            $q->toSql()
        );
        $this->assertEquals(['%@example.com', 'inactive'], $q->getBindings());
    }

    public function testWithRecursiveBuildsUnionAllBetweenAnchorAndRecursiveInCorrectOrder(): void
    {
        $anchor = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id'])
            ->from('categories')
            ->where('id', '=', 123);

        $recursive = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['c.id'])
            ->from('categories AS c')
            ->join('parents AS p', 'p.id', '=', 'c.parent_id');

        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->withRecursive('parents', $anchor, $recursive, ['id'])
            ->from('parents')
            ->select(['id']);

        $sql = $q->toSql();

        $anchorSql = $anchor->toSql();
        $recursiveSql = $recursive->toSql();

        $posAnchor = strpos($sql, $anchorSql);
        $posUnion = strpos($sql, ' UNION ALL ');
        $posRecursive = strpos($sql, $recursiveSql);

        $this->assertNotFalse($posAnchor, 'SQL ska innehålla anchor-subqueryn.');
        $this->assertNotFalse($posUnion, 'SQL ska innehålla " UNION ALL ".');
        $this->assertNotFalse($posRecursive, 'SQL ska innehålla recursive-subqueryn.');

        // Dödar:
        //  - ConcatOperandRemoval: UNION ALL saknas => posUnion false
        //  - Concat-varianter: UNION ALL hamnar i början/slutet eller i fel ordning
        $this->assertTrue($posAnchor < $posUnion, 'Anchor måste komma före UNION ALL.');
        $this->assertTrue($posUnion < $posRecursive, 'UNION ALL måste komma före recursive-delen.');

        // Extra: UNION ALL får inte ligga allra först i CTE-kroppen
        $this->assertStringNotContainsString('AS ( UNION ALL ', $sql);

        // Extra: UNION ALL får inte ligga precis före stängning i CTE-kroppen
        $this->assertStringNotContainsString(' UNION ALL )', $sql);
    }

    public function testCaseWhenSelectAndOrderByCase(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->select([])
            ->caseWhen([
                ['cond' => '`role` = ?', 'bindings' => ['admin'], 'then' => "'A'"],
                ['cond' => '`role` = ?', 'bindings' => ['editor'], 'then' => "'B'"],
            ], "'Z'", 'rnk')
            ->orderByCase('role', ['admin' => 1, 'editor' => 2, 'user' => 3], '9', 'ASC');

        $sql = $q->toSql();
        $this->assertStringContainsString("SELECT CASE WHEN (`role` = ?) THEN 'A' WHEN (`role` = ?) THEN 'B' ELSE 'Z' END AS `rnk` FROM `users`", $sql);
        $this->assertStringContainsString("ORDER BY CASE `role` WHEN ? THEN 1 WHEN ? THEN 2 WHEN ? THEN 3 ELSE '9' END ASC", $sql);
        $this->assertEquals(['admin','editor','admin','editor','user'], $q->getBindings());
    }

    public function testInsertSelect(): void
    {
        $sel = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'email'])
            ->from('users')
            ->where('status', '=', 'active');

        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->insertSelect('newsletter', ['user_id', 'email'], $sel);

        $this->assertEquals(
            'INSERT INTO `newsletter` (`user_id`, `email`) SELECT `id`, `email` FROM `users` WHERE `status` = ?',
            $q->toSql()
        );
        $this->assertEquals(['active'], $q->getBindings());
    }

    public function testInsertSelectTrimsColumnNames(): void
    {
        $sel = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id', 'email'])
            ->from('users')
            ->where('status', '=', 'active');

        // Kolumnnamn med extra whitespace – ska trimmas bort
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->insertSelect('newsletter', ['  user_id  ', '  email  '], $sel);

        $this->assertSame(
            'INSERT INTO `newsletter` (`user_id`, `email`) SELECT `id`, `email` FROM `users` WHERE `status` = ?',
            $q->toSql(),
            'insertSelect() ska trimma kolumnnamn innan de wrappas med backticks.'
        );
    }

    public function testInsertSelectThrowsWhenTableIsEmpty(): void
    {
        $sel = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id'])
            ->from('users');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Table and columns are required for insertSelect.');

        // Tomt tabellnamn men icke-tomma kolumner ska ge exception
        (new QueryBuilder())
            ->setConnection($this->connection)
            ->insertSelect('', ['user_id'], $sel);
    }

    public function testInsertSelectThrowsWhenColumnsAreEmpty(): void
    {
        $sel = (new QueryBuilder())
            ->setConnection($this->connection)
            ->select(['id'])
            ->from('users');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Table and columns are required for insertSelect.');

        // Icke-tomt tabellnamn men tom kolumnlista ska ge exception
        (new QueryBuilder())
            ->setConnection($this->connection)
            ->insertSelect('newsletter', [], $sel);
    }

    public function testJsonExtractAndWhereJsonContains(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('products')
            ->select([])
            ->jsonExtract('meta', '$.brand', 'brand')
            ->whereJsonContains('tags', 'sale');

        $sql = $q->toSql();

        // Vi kör SQLite i testerna, så vi förväntar sqlite-syntax
        $this->assertStringContainsString(
            'SELECT json_extract(`meta`, ?) AS `brand` FROM `products`',
            $sql
        );
        $this->assertStringContainsString(
            'WHERE `tags` LIKE ?',
            $sql
        );

        // WHERE-bindningen ("%sale%") kommer före SELECT-bindningen ("$.brand")
        $this->assertEquals(
            ['%sale%', '$.brand'],
            $q->getBindings()
        );
    }

    public function testGetDriverNameFallsBackToMysqlWhenPdoReturnsNonString(): void
    {
        // Subklass av QueryBuilder som ger oss ett sätt att anropa getDriverName() via reflection.
        $builder = new class extends QueryBuilder {
            public function getDriverNameForTest(): string
            {
                $ref = new ReflectionMethod(QueryBuilder::class, 'getDriverName');
                $ref->setAccessible(true);
                /** @var string $name */
                $name = $ref->invoke($this);
                return $name;
            }
        };

        // PDO-mock där ATTR_DRIVER_NAME returnerar ett icke-strängvärde (int 123).
        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->method('getAttribute')
            ->willReturnCallback(static function ($attr) {
                if ($attr === PDO::ATTR_DRIVER_NAME) {
                    // Avsiktligt FEL typ: int 123, inte string
                    return 123;
                }
                return null;
            });

        // Connection-mock som alltid returnerar vår PDO-mock.
        $connMock = $this->createMock(Connection::class);
        $connMock->method('getPDO')->willReturn($pdoMock);

        $builder->setConnection($connMock);

        $driver = $builder->getDriverNameForTest();

        // Original: is_string(123) && 123 !== '' => false => 'mysql'
        // Mutant:   is_string(123) || 123 !== '' => true  => '123'
        $this->assertSame('mysql', $driver);
    }

    public function testWhereJsonContainsInMysqlCastsScalarNeedleToString(): void
    {
        // För detta test forcerar vi mysql-driver via override av getDriverName()
        $builder = new class extends QueryBuilder {
            protected function getDriverName(): string
            {
                return 'mysql';
            }
        };

        $builder
            ->setConnection($this->connection)
            ->from('products')
            ->select([])
            ->whereJsonContains('tags', 1);

        $sql = $builder->toSql();

        // Justera dessa asserts om din implementation använder annan exakt syntax,
        // t.ex. JSON_SEARCH eller liknande. Poängen är att vi hamnar i mysql-grenen.
        $this->assertStringContainsString(
            'tags',
            $sql,
            'whereJsonContains() ska använda JSON-funktion för mysql-driver.'
        );

        $bindings = $builder->getBindings();

        $this->assertNotEmpty($bindings);
        // I mysql-grenen används json_encode($needle) direkt:
        //  - needle = 1 => json_encode(1) === '1'
        //  så vi förväntar strängen '1' som binding.
        $this->assertSame(
            '1',
            $bindings[0],
            'whereJsonContains() i mysql-läge ska JSON-encoda värdet korrekt.'
        );
        $this->assertIsString($bindings[0]);
    }

    public function testRollupGroupBy(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('orders')
            ->select(['customer_id', 'COUNT(*) AS cnt'])
            ->rollup(['customer_id']);

        $this->assertEquals(
            'SELECT `customer_id`, COUNT(*) AS `cnt` FROM `orders` GROUP BY `customer_id` WITH ROLLUP',
            $q->toSql()
        );
    }

    public function testGroupingSetsProducesGroupingSetsClause(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('orders')
            ->select(['customer_id', 'product_id', 'SUM(amount) AS total'])
            // Använd grouping sets utan vanlig groupBy
            ->groupingSets([
                ['customer_id'],
                ['product_id'],
            ]);

        $sql = $q->toSql();

        $this->assertSame(
            'SELECT `customer_id`, `product_id`, SUM(amount) AS `total` FROM `orders` GROUP BY GROUPING SETS ((`customer_id`), (`product_id`))',
            $sql,
            'groupingSets() ska generera en exakt GROUP BY GROUPING SETS-klausul utan att förstöra SELECT-delen.'
        );
    }

    public function testDoesntExist(): void
    {
        $mock = $this->createMock(Connection::class);
        // exists() bygger en COUNT(1)-liknande SELECT 1; vi behöver bara returnera null för att simulera "inget resultat"
        $mock->method('fetchOne')->willReturn(null);

        $q = (new QueryBuilder())
            ->setConnection($mock)
            ->from('users')
            ->where('id', '=', -9999);

        $this->assertTrue($q->doesntExist());
    }

    public function testFirstOrFailThrows(): void
    {
        $mock = $this->createMock(Connection::class);
        // first() gör en SELECT LIMIT 1 och använder fetchAll/fetchOne via dina lager.
        // Mocka så att inga rader hittas.
        $mock->method('fetchAll')->willReturn([]);
        $mock->method('fetchOne')->willReturn(null);

        // Minimal modellklass som uppfyller kraven (extends Model)
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
        };

        $q = (new QueryBuilder())
            ->setConnection($mock)
            ->from('users')
            ->setModelClass(get_class($model))
            ->where('id', '=', -9999)
            ->limit(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No records found for firstOrFail().');
        $q->firstOrFail();
    }

    public function testWhenExecutesThenWhenConditionIsTrue(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->when(
                true,
                function (QueryBuilder $b): void {
                    $b->where('status', '=', 'active');
                },
                function (QueryBuilder $b): void {
                    $b->where('status', '=', 'inactive');
                }
            );

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ?',
            $q->toSql()
        );
        $this->assertSame(['active'], $q->getBindings());
    }

    public function testWhenExecutesElseWhenConditionIsFalse(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->when(
                false,
                function (QueryBuilder $b): void {
                    $b->where('status', '=', 'active');
                },
                function (QueryBuilder $b): void {
                    $b->where('status', '=', 'inactive');
                }
            );

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ?',
            $q->toSql()
        );
        $this->assertSame(['inactive'], $q->getBindings());
    }

    public function testWhenAndTap(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->when(true, function (QueryBuilder $b): void {
                // when-testet i sig är inte viktigt här – fokus ligger på tap()
            })
            ->tap(function (QueryBuilder $b): void {
                // tap ska faktiskt modifiera byggaren
                $b->where('active', '=', 1);
            });

        // Om FunctionCallRemoval-mutanten slår ut $callback($this) i tap()
        // kommer detta villkor aldrig att finnas i SQL:en.
        $this->assertStringContainsString('`active` = ?', $q->toSql());
    }

    public function testOrderByDescLatestOldest(): void
    {
        $q1 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->orderByDesc('created_at');
        $this->assertStringContainsString('ORDER BY `created_at` DESC', $q1->toSql());

        $q2 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->latest();
        $this->assertStringContainsString('ORDER BY `created_at` DESC', $q2->toSql());

        $q3 = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->oldest();
        $this->assertStringContainsString('ORDER BY `created_at` ASC', $q3->toSql());
    }

    public function testDebugSqlHelpers(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('email', '=', 'john@example.com');

        $paramSql = $q->debugSql();
        $interpSql = $q->debugSqlInterpolated();

        $this->assertSame('SELECT * FROM `users` WHERE `email` = ?', $paramSql);
        $this->assertSame("SELECT * FROM `users` WHERE `email` = 'john@example.com'", $interpSql);
    }

    public function testSimplePaginate(): void
    {
        $mock = $this->createMock(Connection::class);

        // Simulera tre rader (den tredje används bara för has_more)
        $mock->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
            ['id' => 3, 'name' => 'C'],
        ]);

        // Minimal modellklass för hydrering
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $q = (new QueryBuilder())
            ->setConnection($mock)
            ->from('users')
            ->setModelClass(get_class($model));

        $page = $q->simplePaginate(2, 1);

        $this->assertArrayHasKey('data', $page);
        $this->assertIsArray($page['data']);
        $this->assertCount(2, $page['data']); // extra raden ska kapas

        $this->assertArrayHasKey('pagination', $page);
        $this->assertTrue($page['pagination']['has_more']);

        // Nytt: dödar ArrayItemRemoval och first_page‑mutanter
        $this->assertSame(2, $page['pagination']['per_page'], 'per_page ska spegla angivet perPage.');
        $this->assertSame(1, $page['pagination']['current_page']);
        $this->assertSame(1, $page['pagination']['first_page'], 'first_page ska vara 1 i simplePaginate().');
    }

    public function testPaginateReturnsArrayData(): void
    {
        $mock = $this->createMock(Connection::class);

        // COUNT(*) as total
        $mock->method('fetchOne')->willReturn(['total' => 3]);

        // Data-hämtning
        $mock->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
        ]);

        // Minimal modellklass för hydrering
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $q = (new QueryBuilder())
            ->setConnection($mock)
            ->from('users')
            ->setModelClass(get_class($model));

        $page = $q->paginate(2, 1);

        $this->assertArrayHasKey('data', $page);
        $this->assertIsArray($page['data'], 'paginate() ska returnera data som array, ej Collection.');
        $this->assertCount(2, $page['data']);
        $this->assertArrayHasKey('pagination', $page);
        $this->assertSame(3, $page['pagination']['total']);
        $this->assertSame(2, $page['pagination']['per_page']);
        $this->assertSame(1, $page['pagination']['current_page']);
    }

    public function testPaginateTreatsNullCountResultAsZeroAndSkipsFetchAll(): void
    {
        $conn = $this->createMock(Connection::class);

        // COUNT-queryn gav ingen rad alls (fetchOne() => null)
        $conn->method('fetchOne')->willReturn(null);

        // Vid total = 0 (eller null->0) ska paginate() early-return:a och aldrig hämta data
        $conn->expects($this->never())->method('fetchAll');

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users');

        $result = $qb->paginate(10, 1);

        $this->assertSame([], $result['data']);

        $meta = $result['pagination'];
        $this->assertSame(0, $meta['total'], 'Null från COUNT ska tolkas som total=0.');
        $this->assertSame(10, $meta['per_page']);
        $this->assertSame(1, $meta['current_page']);
        $this->assertSame(0, $meta['last_page']);
        $this->assertSame(1, $meta['first_page']);
    }

    public function testPaginateCountsTotalUsingCloneAndDoesNotMutateOriginalQuery(): void
    {
        // Bygg upp en riktig tabell i SQLite‑minnet
        $pdo = $this->connection->getPDO();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, status TEXT)');
        $pdo->exec("INSERT INTO users (name, status) VALUES ('A', 'active')");
        $pdo->exec("INSERT INTO users (name, status) VALUES ('B', 'active')");
        $pdo->exec("INSERT INTO users (name, status) VALUES ('C', 'inactive')");

        // Minimal modellklass som pekar på "users"-tabellen
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name', 'status'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(get_class($model))
            ->from('users')
            ->where('status', '=', 'active')
            ->orderBy('id');

        // Spara SELECT/WHERE/ORDER-delen innan paginate() körs (utan LIMIT/OFFSET)
        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? ORDER BY `id` ASC',
            $qb->toSql()
        );

        // Kör riktig paginate mot SQLite
        $perPage = 1;
        $currentPage = 1;
        $result = $qb->paginate($perPage, $currentPage);

        // 1) MethodCallRemoval på selectRaw('COUNT(*) as total'):
        //    Utan SELECT COUNT(*) ska total inte bli 2.
        $this->assertArrayHasKey('pagination', $result);
        $meta = $result['pagination'];

        $this->assertSame(2, $meta['total'], 'paginate() ska räkna korrekt antal aktiva users via COUNT(*).');
        $this->assertSame(1, $meta['current_page']);
        $this->assertSame(1, $meta['first_page']);
        $this->assertSame(2, $meta['last_page'], 'last_page ska följa ceil(2/1) = 2.');

        // 2) CloneRemoval på $countQuery = clone $this:
        //    Om mutanten tar bort klonen muteras original‑builderns columns/orderBy när total räknas.
        //    Vi kräver att SELECT/WHERE/ORDER-delen fortfarande är intakt, och att ONLY limit/offset
        //    har lagts till för själva dataläsningen.
        $sqlAfterPaginate = $qb->toSql();

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? ORDER BY `id` ASC LIMIT 1 OFFSET 0',
            $sqlAfterPaginate,
            'paginate() ska behålla SELECT * och ORDER BY `id` ASC på original-queryn och bara lägga till LIMIT/OFFSET.'
        );
    }

    public function testPaginateUsesCountQueryAndDoesNotMutateOriginalOrderBy(): void
    {
        $conn = $this->createMock(Connection::class);

        // Kräver COUNT(*) i count-queryn (dödar MethodCallRemoval)
        $conn->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('COUNT(*)', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(['total' => 1]);

        $builder = new class ($conn) extends QueryBuilder {
            private Connection $conn;

            /** @var class-string<\Radix\Database\ORM\Model>|null */
            protected ?string $modelClass = \Radix\Database\ORM\Model::class;

            public function __construct(Connection $conn)
            {
                $this->conn = $conn;
            }

            protected function getConnection(): Connection
            {
                return $this->conn;
            }

            public function get(): \Radix\Collection\Collection
            {
                // Undvik DB. paginate() ska fortfarande lägga LIMIT/OFFSET på originalet.
                return new \Radix\Collection\Collection([]);
            }
        };

        $builder
            ->from('users')
            ->orderBy('id');

        $sqlBefore = $builder->toSql();
        $builder->paginate(10, 1);
        $sqlAfter = $builder->toSql();

        // Dödar CloneRemoval: utan clone nollas orderBy på originalet när COUNT byggs
        $this->assertStringContainsString('ORDER BY', $sqlBefore);
        $this->assertStringContainsString('ORDER BY', $sqlAfter);
    }

    public function testPaginateTreatsMissingTotalKeyAsZeroAndNeverLoadsData(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn([]); // saknar 'total'

        $builder = new class ($conn) extends QueryBuilder {
            private Connection $conn;

            /** @var class-string<\Radix\Database\ORM\Model>|null */
            protected ?string $modelClass = \Radix\Database\ORM\Model::class;

            public function __construct(Connection $conn)
            {
                $this->conn = $conn;
            }

            protected function getConnection(): Connection
            {
                return $this->conn;
            }

            public function get(): \Radix\Collection\Collection
            {
                throw new RuntimeException('get() ska inte anropas när total=0 (early return).');
            }
        };

        $builder->from('users');

        $res = $builder->paginate(10, 1);

        $this->assertSame([], $res['data']);
        $this->assertSame(0, $res['pagination']['total']);
    }

    public function testPaginateUsesDefaultPerPageAndCurrentPageWhenNotProvided(): void
    {
        $conn = $this->createMock(Connection::class);

        // COUNT(*) = 5 ⇒ last_page = ceil(5/10) = 1
        $conn->method('fetchOne')->willReturn(['total' => 5]);

        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Default perPage = 10, default currentPage = 1
                    $this->assertStringContainsString('LIMIT 10 OFFSET 0', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([
                ['id' => 1, 'name' => 'A'],
                ['id' => 2, 'name' => 'B'],
                ['id' => 3, 'name' => 'C'],
                ['id' => 4, 'name' => 'D'],
                ['id' => 5, 'name' => 'E'],
            ]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        // Anropa utan perPage/currentPage ⇒ default 10, 1
        $result = $qb->paginate();

        $this->assertArrayHasKey('pagination', $result);
        $meta = $result['pagination'];

        $this->assertSame(5, $meta['total']);
        $this->assertSame(10, $meta['per_page'], 'Default per_page ska vara 10.');
        $this->assertSame(1, $meta['current_page'], 'Default current_page ska vara 1.');
        $this->assertSame(1, $meta['first_page']);
        $this->assertSame(1, $meta['last_page']);
    }

    public function testPaginateDefaultCurrentPageIsOne(): void
    {
        $ref = new ReflectionMethod(QueryBuilder::class, 'paginate');
        $params = $ref->getParameters();

        $this->assertCount(2, $params);

        $currentPageParam = $params[1];
        $this->assertSame('currentPage', $currentPageParam->getName());
        $this->assertTrue($currentPageParam->isDefaultValueAvailable());
        $this->assertSame(
            1,
            $currentPageParam->getDefaultValue(),
            'paginate() ska ha default currentPage=1 (dödar signatur-mutanten currentPage=0/2).'
        );
    }

    public function testPaginateUsesDefaultCurrentPageWhenOmittedAndComputesOffsetZero(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(['total' => 5]); // så att paginate() inte early-returnar

        $builder = new class ($conn) extends QueryBuilder {
            private Connection $conn;

            public ?int $capturedLimit = null;
            public ?int $capturedOffset = null;

            /** @var class-string<\Radix\Database\ORM\Model>|null */
            protected ?string $modelClass = \Radix\Database\ORM\Model::class;

            public function __construct(Connection $conn)
            {
                $this->conn = $conn;
            }

            protected function getConnection(): Connection
            {
                return $this->conn;
            }

            public function get(): \Radix\Collection\Collection
            {
                $refLimit = new ReflectionProperty(QueryBuilder::class, 'limit');
                $refLimit->setAccessible(true);

                $refOffset = new ReflectionProperty(QueryBuilder::class, 'offset');
                $refOffset->setAccessible(true);

                $limit = $refLimit->getValue($this);
                $offset = $refOffset->getValue($this);

                $this->capturedLimit = is_int($limit) ? $limit : null;
                $this->capturedOffset = is_int($offset) ? $offset : null;

                return new \Radix\Collection\Collection([]);
            }
        };

        $builder->from('users');

        $res = $builder->paginate(10); // <-- currentPage utelämnas

        $this->assertSame(1, $res['pagination']['current_page'], 'När currentPage utelämnas ska current_page vara 1.');
        $this->assertSame(10, $builder->capturedLimit, 'paginate(10) ska sätta LIMIT 10.');
        $this->assertSame(0, $builder->capturedOffset, 'paginate(10) ska ge OFFSET 0 när currentPage=1.');
    }

    public function testPaginateNormalizesNonPositiveCurrentPageToOneAndNeverUsesNegativeOffset(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(['total' => 5]); // så att paginate() går vidare till "get()"

        $builder = new class ($conn) extends QueryBuilder {
            private Connection $conn;

            public ?int $capturedOffset = null;

            /** @var class-string<\Radix\Database\ORM\Model>|null */
            protected ?string $modelClass = \Radix\Database\ORM\Model::class;

            public function __construct(Connection $conn)
            {
                $this->conn = $conn;
            }

            protected function getConnection(): Connection
            {
                return $this->conn;
            }

            public function get(): \Radix\Collection\Collection
            {
                $refOffset = new ReflectionProperty(QueryBuilder::class, 'offset');
                $refOffset->setAccessible(true);

                $offset = $refOffset->getValue($this);
                $this->capturedOffset = is_int($offset) ? $offset : null;

                return new \Radix\Collection\Collection([]);
            }
        };

        $builder->from('users');

        $res = $builder->paginate(10, 0);

        $this->assertSame(1, $res['pagination']['current_page'], 'current_page ska klampas till 1 när currentPage <= 0.');
        $this->assertIsInt($builder->capturedOffset);
        $this->assertSame(0, $builder->capturedOffset, 'OFFSET ska vara 0 när currentPage <= 0 normaliseras till 1.');
        $this->assertGreaterThanOrEqual(0, $builder->capturedOffset, 'OFFSET får aldrig vara negativ.');
    }

    public function testSearchUsesDefaultPerPageAndCurrentPageWhenNotProvided(): void
    {
        $conn = $this->createMock(Connection::class);

        // COUNT(*) = 15 ⇒ last_page = ceil(15/10) = 2
        $conn->method('fetchOne')->willReturn(['total' => 15]);

        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Default perPage = 10, default currentPage = 1 ⇒ LIMIT 10 OFFSET 0
                    $this->assertStringContainsString('LIMIT 10 OFFSET 0', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(
                array_map(
                    static fn(int $i): array => ['id' => $i, 'name' => 'U' . $i],
                    range(1, 10)
                )
            );

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        // Anropa utan perPage/currentPage
        $result = $qb->search('foo', ['name']);

        $this->assertArrayHasKey('search', $result);
        $meta = $result['search'];

        $this->assertSame('foo', $meta['term']);
        $this->assertSame(15, $meta['total']);
        $this->assertSame(10, $meta['per_page'], 'Default per_page för search() ska vara 10.');
        $this->assertSame(1, $meta['current_page'], 'Default current_page för search() ska vara 1.');
        $this->assertSame(2, $meta['last_page'], 'last_page ska följa ceil(15/10) = 2.');
        $this->assertSame(1, $meta['first_page']);
    }

    public function testChunkIterates(): void
    {
        $mock = $this->createMock(Connection::class);

        // Första chunk (size=2): returnera 2 rader
        // Andra chunk: returnera 1 rad -> avsluta
        $mock->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                ['id' => 1, 'name' => 'A'],
                ['id' => 2, 'name' => 'B'],
            ],
            [
                ['id' => 3, 'name' => 'C'],
            ]
        );

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $q = (new QueryBuilder())
            ->setConnection($mock)
            ->from('users')
            ->setModelClass(get_class($model))
            ->orderBy('id'); // deterministisk ordning

        $countCalls = 0;
        $q->chunk(2, function (\Radix\Collection\Collection $chunk, int $page) use (&$countCalls) {
            $this->assertGreaterThan(0, $chunk->count());
            $this->assertSame($countCalls + 1, $page);
            $countCalls++;
        });

        $this->assertSame(2, $countCalls);
    }

    public function testLazyYields(): void
    {
        $mock = $this->createMock(Connection::class);

        // Simulera två batchar: första batchen 2 rader, andra batchen 1 rad, sedan tomt
        $mock->method('fetchAll')->willReturnOnConsecutiveCalls(
            [
                ['id' => 1, 'name' => 'A'],
                ['id' => 2, 'name' => 'B'],
            ],
            [
                ['id' => 3, 'name' => 'C'],
            ],
            [] // tredje anropet stoppar generatorn
        );

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $q = (new QueryBuilder())
            ->setConnection($mock)
            ->from('users')
            ->setModelClass(get_class($model))
            ->orderBy('id');

        $gen = $q->lazy(2);
        $this->assertInstanceOf(Generator::class, $gen);

        $collected = [];
        foreach ($gen as $m) {
            $collected[] = $m->getAttribute('name');
        }

        $this->assertSame(['A','B','C'], $collected);
    }

    public function testDebugSqlInterpolatedFormatsMixedBindingTypes(): void
    {
        $q = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('logs')
            ->whereRaw(
                'message = ? AND deleted_at IS ? AND is_error = ? AND attempts > ?',
                ['oops', null, true, 5]
            );

        $interp = $q->debugSqlInterpolated();

        $this->assertSame(
            "SELECT * FROM `logs` WHERE (message = 'oops' AND deleted_at IS NULL AND is_error = 1 AND attempts > 5)",
            $interp
        );
    }

    public function testFirstAlwaysUsesLimitOne(): void
    {
        $mock = $this->createMock(Connection::class);

        $mock->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // first() ska alltid begränsa med LIMIT 1 oavsett tidigare state
                    $this->assertStringContainsString('LIMIT 1', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([
                ['id' => 1, 'name' => 'A'],
            ]);

        // Minimal modellklass för hydrering
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $q = (new QueryBuilder())
            ->setConnection($mock)
            ->from('users')
            ->setModelClass(get_class($model));

        // Sätt en annan limit först för att säkerställa att first() skriver över den
        $q->limit(10);

        $result = $q->first();

        $this->assertInstanceOf(get_class($model), $result);
    }

    public function testFirstMarksModelAsExisting(): void
    {
        $mock = $this->createMock(Connection::class);

        $mock->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'A'],
        ]);

        // Minimal modellklass för hydrering
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $q = (new QueryBuilder())
            ->setConnection($mock)
            ->from('users')
            ->setModelClass(get_class($model));

        $result = $q->first();

        $this->assertInstanceOf(get_class($model), $result);

        // Antag att basmodellen har en intern "exists"-flagga som sätts av markAsExisting().
        // Justera propertynamn/metodnamn här om din Model använder något annat.
        $ref = new ReflectionProperty(\Radix\Database\ORM\Model::class, 'exists');
        $ref->setAccessible(true);
        $this->assertTrue(
            $ref->getValue($result),
            'first() ska markera modellen som befintlig (markAsExisting()).'
        );
    }

    public function testFirstSetsLimitToOneOnBuilderInstance(): void
    {
        $builder = new class extends QueryBuilder {
            // Se till att first() inte klagar på saknad modelClass
            /** @var class-string<\Radix\Database\ORM\Model>|null */
            protected ?string $modelClass = \Radix\Database\ORM\Model::class;

            public function setConnection(Connection $connection): static
            {
                // Ignorerar riktig connection i denna stub
                return $this;
            }

            public function get(): \Radix\Collection\Collection
            {
                // Bygg en Collection vars storlek beror på nuvarande $this->limit.
                // Vi behöver inte verklig hydrering här – bara något som uppfyller first():s krav.
                $count = $this->limit ?? 0;

                $models = [];
                for ($i = 0; $i < $count; $i++) {
                    $models[] = new class extends \Radix\Database\ORM\Model {
                        protected string $table = 'users';
                        /** @var array<int,string> */
                        protected array $fillable = [];
                    };
                }

                return new \Radix\Collection\Collection($models);
            }
        };

        // Sätt en annan limit först
        $builder->limit(5);

        // first() ska skriva över till 1
        $builder->first();

        $ref = new ReflectionProperty(QueryBuilder::class, 'limit');
        $ref->setAccessible(true);
        $this->assertSame(
            1,
            $ref->getValue($builder),
            'first() ska alltid sätta builderns limit till 1.'
        );
    }

    public function testFirstCallsMarkAsExistingOnReturnedModel(): void
    {
        $builder = new class extends QueryBuilder {
            /** @var \Radix\Database\ORM\Model|null */
            public ?\Radix\Database\ORM\Model $capturedModel = null;

            /** @var class-string<\Radix\Database\ORM\Model>|null */
            protected ?string $modelClass = \Radix\Database\ORM\Model::class;

            public function setConnection(Connection $connection): static
            {
                // Ignorera riktig connection i denna stub
                return $this;
            }

            public function get(): \Radix\Collection\Collection
            {
                $model = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'users';
                    /** @var array<int,string> */
                    protected array $fillable = [];
                    public int $markExistingCalls = 0;

                    public function markAsExisting(): void
                    {
                        $this->markExistingCalls++;
                        parent::markAsExisting();
                    }
                };

                $this->capturedModel = $model;

                return new \Radix\Collection\Collection([$model]);
            }
        };

        // Kör first(), som internt anropar get() och sedan markAsExisting() på resultatet
        $result = $builder->first();

        // Säkerställ att first() faktiskt returnerade något
        if ($result === null) {
            self::fail('first() ska inte returnera null i detta test.');
        }

        // Samma instans som skapades i get()
        $this->assertSame($builder->capturedModel, $result);

        // Läs markExistingCalls via reflection (extra property på anonym klass)
        $refCalls = new ReflectionProperty($result, 'markExistingCalls');
        $refCalls->setAccessible(true);
        $this->assertSame(
            1,
            $refCalls->getValue($result),
            'first() ska anropa markAsExisting() på den returnerade modellen exakt en gång.'
        );

        // Och modellen ska betraktas som existerande (metod finns på bas-Model)
        $this->assertTrue(
            $result->isExisting(),
            'first() ska markera modellen som existerande.'
        );
    }

    public function testWithStoresClosureConstraintForAssocRelation(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];

            public function comments(): void {}
        };

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(get_class($model))
            ->from('users');

        $constraint = function (QueryBuilder $q): void {
            $q->where('active', '=', 1);
        };

        $qb->with(['comments' => $constraint]);

        $refConstraints = new ReflectionProperty(QueryBuilder::class, 'eagerLoadConstraints');
        $refConstraints->setAccessible(true);
        /** @var array<string,Closure> $constraints */
        $constraints = $refConstraints->getValue($qb);

        $this->assertArrayHasKey('comments', $constraints);
        $this->assertSame($constraint, $constraints['comments']);
    }

    public function testWithThrowsWhenModelClassIsNotSet(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        $expectedMessage = 'Model class must be set and extend '
            . \Radix\Database\ORM\Model::class
            . ' before calling with().';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($expectedMessage);

        $qb->with('comments');
    }

    public function testWithSkipsEmptyRelationNamesSilently(): void
    {
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];

            public function comments(): void {}
        };

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(get_class($model))
            ->from('users');

        // Den tomma strängen ska ignoreras, "comments" ska tas med.
        $qb->with(['', 'comments']);

        $ref = new ReflectionProperty(QueryBuilder::class, 'eagerLoadRelations');
        $ref->setAccessible(true);
        /** @var array<int,string> $relations */
        $relations = $ref->getValue($qb);

        $this->assertSame(['comments'], $relations);
    }

    public function testWithAssocArrayWithNonClosureValueUsesValueAsRelationAndThrows(): void
    {
        // Modell har en relation "foo", men ingen "notClosure"
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];

            public function foo(): void {}
        };

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(get_class($model))
            ->from('users');

        // I originalkod: relation = 'notClosure' -> metod saknas -> InvalidArgumentException.
        // Med LogicalAnd-mutanten: relation = 'foo' -> finns -> ingen exception.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Relation 'notClosure' is not defined");

        $qb->with(['foo' => 'notClosure']);
    }

    public function testFromTrimsAliasBeforePassingToWrapAlias(): void
    {
        $builder = new class extends QueryBuilder {
            public ?string $capturedAlias = null;

            /**
             * Överskugga wrapAlias för att inspektera värdet som from() skickar in.
             */
            protected function wrapAlias(string $alias): string
            {
                $this->capturedAlias = $alias;

                // Anropa den riktiga implementationen så att övrig funktionalitet inte bryts
                return parent::wrapAlias($alias);
            }
        };

        $builder
            ->setConnection($this->connection)
            // Extra whitespace runt både tabellnamn och alias
            ->from('  users   AS   u   ');

        // from() ska trimma alias-delen innan den skickas till wrapAlias
        $this->assertSame(
            'u',
            $builder->capturedAlias,
            'from() ska skicka ett trimmat alias till wrapAlias().'
        );
    }

    public function testWithCountUsesOverriddenAddRelationCountSelectInSubclass(): void
    {
        $conn = $this->connection;

        // Minimal konkret modell för att QueryBuilder ska kunna instansiera den
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'parents';
            /** @var array<int,string> */
            protected array $fillable = ['id'];
            // En enkel relation som faktiskt existerar – men override:en kommer att ta över ändå.
            public function children(): \Radix\Database\ORM\Relationships\HasMany
            {
                $child = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'children';
                    /** @var array<int,string> */
                    protected array $fillable = ['id', 'parent_id'];
                };

                return new \Radix\Database\ORM\Relationships\HasMany(
                    $this->getConnection(),
                    get_class($child),
                    'parent_id',
                    'id'
                );
            }
        };

        $builder = new class extends QueryBuilder {
            public bool $called = false;

            /**
             * Override:as bara för test – vi vill se att withCount() anropar denna,
             * inte bas-implementationen, vilket kräver protected (inte private).
             */
            protected function addRelationCountSelect(string $relation): void
            {
                $this->called = true;
                // Lägg till en dummy-kolumn så att toSql() fortfarande fungerar.
                $this->columns[] = '1 AS `dummy_count`';
            }
        };

        $builder
            ->setConnection($conn)
            ->setModelClass(get_class($model))
            ->from('parents')
            ->withCount('children');

        $sql = $builder->toSql();

        $this->assertTrue(
            $builder->called,
            'withCount() ska använda den overridade addRelationCountSelect() i subklassen.'
        );
        $this->assertStringContainsString('dummy_count', $sql);
    }

    public function testWithStringRelationRegistersRelation(): void
    {
        // Minimal modell med en definierad relation "comments"
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id'];

            public function comments(): void
            {
                // För detta test spelar kroppens innehåll ingen roll.
            }
        };

        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->setModelClass(get_class($model))
            ->from('users')
            ->with('comments');

        $ref = new ReflectionProperty(QueryBuilder::class, 'eagerLoadRelations');
        $ref->setAccessible(true);
        /** @var array<int,string> $relations */
        $relations = $ref->getValue($qb);

        $this->assertSame(['comments'], $relations);
    }

    public function testWhereRawBooleanIsPreservedAndSqlIsTrimmed(): void
    {
        $query = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('id', '=', 1)
            // SQL med extra whitespace, och boolean OR
            ->whereRaw('   `x` = ?   ', [2], 'OR');

        $sql = $query->toSql();

        // Kontrollera att boolean OR finns kvar och att raw_sql-delen är omsluten av parenteser
        $this->assertStringContainsString(
            'WHERE `id` = ? OR (',
            $sql
        );
        $this->assertStringContainsString(
            '`x` = ?',
            $sql
        );
        $this->assertStringEndsWith(')', $sql);

        $this->assertSame(
            [1, 2],
            $query->getBindings()
        );
    }

    public function testPaginateWithZeroTotalReturnsZeroTotalAndFirstPageOne(): void
    {
        $conn = $this->createMock(Connection::class);

        // COUNT(*) = 0
        $conn->method('fetchOne')->willReturn(['total' => 0]);

        // paginate() ska i detta fall använda early return och aldrig anropa fetchAll
        $conn->expects($this->never())->method('fetchAll');

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users');

        $result = $qb->paginate(10, 1);

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['pagination']['total']);
        $this->assertSame(10, $result['pagination']['per_page']);
        $this->assertSame(1, $result['pagination']['current_page']);
        $this->assertSame(0, $result['pagination']['last_page']);
        $this->assertSame(1, $result['pagination']['first_page']);
    }



    public function testPaginateCastsNumericStringTotalUsesCeilAndClampsCurrentPage(): void
    {
        $conn = $this->createMock(Connection::class);

        // COUNT(*) = "4" (numerisk sträng) – ska castas till int 4.
        $conn->method('fetchOne')->willReturn(['total' => '4']);

        // Data-hämtning: vi verifierar att LIMIT/OFFSET motsvarar klampad sida.
        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // perPage = 3, begärd currentPage = 3
                    // total = 4 ⇒ last_page = ceil(4/3) = 2
                    // current_page ska klampas ned till 2
                    // offset ska därför bli (2 - 1) * 3 = 3
                    //
                    // Mutanter:
                    //  - round(): last_page = 1 ⇒ OFFSET 0
                    //  - LogicalAndAllSubExprNegation: ingen klampning ⇒ OFFSET 6
                    $this->assertStringContainsString(
                        'LIMIT 3 OFFSET 3',
                        $sql,
                        'paginate() ska klampa current_page och räkna om offset korrekt.'
                    );
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([
                ['id' => 3, 'name' => 'C'],
                ['id' => 4, 'name' => 'D'],
            ]);

        // Minimal modellklass för hydrering
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $perPage = 3;
        $currentPage = 3; // medvetet större än sista sidan

        $result = $qb->paginate($perPage, $currentPage);

        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
        $this->assertCount(2, $result['data']);

        $meta = $result['pagination'];

        // 1) Dödar LogicalNot-mutanten: total ska vara int 4, inte sträng '4'
        $this->assertSame(4, $meta['total'], 'total ska castas från numerisk sträng till int.');

        // 2) Dödar RoundingFamily-mutanten: last_page ska följa ceil, dvs 2
        $this->assertSame(2, $meta['last_page'], 'last_page ska baseras på ceil(total/per_page).');

        // 3) Dödar LogicalAndAllSubExprNegation: current_page ska klampas till 2, inte lämnas som 3
        $this->assertSame(2, $meta['current_page'], 'current_page ska klampas ned till sista sidan.');

        $this->assertSame($perPage, $meta['per_page']);
        $this->assertSame(1, $meta['first_page']);
    }

    public function testPaginateDefaultsToZeroWhenTotalKeyIsMissingAndSkipsFetchAll(): void
    {
        $conn = $this->createMock(Connection::class);

        // fetchOne returnerar ingen 'total'-nyckel alls
        $conn->method('fetchOne')->willReturn([]);

        // Vid total = 0 ska paginate() göra early return och ALDRIG anropa fetchAll.
        $conn->expects($this->never())->method('fetchAll');

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users');

        $result = $qb->paginate(10, 1);

        $this->assertSame([], $result['data']);

        $meta = $result['pagination'];
        $this->assertSame(0, $meta['total'], 'När total saknas ska den behandlas som 0.');
        $this->assertSame(10, $meta['per_page']);
        $this->assertSame(1, $meta['current_page']);
        $this->assertSame(0, $meta['last_page']);
        $this->assertSame(1, $meta['first_page']);
    }

    public function testSearchWithZeroTotalReturnsZeroTotalAndFirstPageOne(): void
    {
        $conn = $this->createMock(Connection::class);

        // COUNT(*) = 0 för search()-räkningen
        $conn->method('fetchOne')->willReturn(['total' => 0]);
        // Data-hämtning ska ge tom array
        $conn->method('fetchAll')->willReturn([]);

        // Minimal modellklass för hydrering
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $result = $qb->search('foo', ['name'], 10, 1);

        $this->assertSame([], $result['data']);

        $meta = $result['search'];
        $this->assertSame('foo', $meta['term']);
        $this->assertSame(0, $meta['total'], 'total ska vara 0 vid tomt resultat.');
        $this->assertSame(10, $meta['per_page']);
        $this->assertSame(1, $meta['current_page']);
        $this->assertSame(0, $meta['last_page']);
        $this->assertSame(1, $meta['first_page'], 'first_page ska vara 1, aldrig 0 eller 2.');
    }

    public function testSearchUsesCorrectOffsetForSecondPage(): void
    {
        $conn = $this->createMock(Connection::class);

        // COUNT(*) = 5 ⇒ last_page = ceil(5/2) = 3
        $conn->method('fetchOne')->willReturn(['total' => 5]);

        // fetchAll ska anropas exakt en gång med SQL som innehåller LIMIT 2 OFFSET 2
        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Rätt beräkning: (currentPage - 1) * perPage = (2 - 1) * 2 = 2
                    $this->assertStringContainsString(
                        'LIMIT 2 OFFSET 2',
                        $sql,
                        'search() ska använda offset = (current_page - 1) * per_page för sida 2.'
                    );
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([
                ['id' => 3, 'name' => 'C'],
                ['id' => 4, 'name' => 'D'],
            ]);

        // Minimal modellklass för hydrering
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        // perPage = 2, currentPage = 2
        $result = $qb->search('foo', ['name'], 2, 2);

        // Data ska komma tillbaka som array och ha 2 poster
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
        $this->assertCount(2, $result['data']);

        $meta = $result['search'];
        $this->assertSame('foo', $meta['term']);
        $this->assertSame(5, $meta['total']);
        $this->assertSame(2, $meta['per_page']);
        $this->assertSame(2, $meta['current_page']);
        $this->assertSame(3, $meta['last_page']);
        $this->assertSame(1, $meta['first_page']);
    }

    public function testSearchCastsNumericStringTotalUsesCeilAndClampsCurrentPage(): void
    {
        $conn = $this->createMock(Connection::class);

        // COUNT(*) = "4" (numerisk sträng) – ska castas till int 4.
        $conn->method('fetchOne')->willReturn(['total' => '4']);

        // Data-hämtning: vi verifierar att LIMIT/OFFSET motsvarar klampad sida.
        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // perPage = 3, begärd currentPage = 3
                    // total = 4 ⇒ last_page = ceil(4/3) = 2
                    // current_page ska klampas ned till 2
                    // offset ska därför bli (2 - 1) * 3 = 3
                    //
                    // Mutanter:
                    //  - round(): last_page = 1 ⇒ OFFSET 0
                    //  - LogicalAndAllSubExprNegation: ingen klampning ⇒ OFFSET 6
                    $this->assertStringContainsString(
                        'LIMIT 3 OFFSET 3',
                        $sql,
                        'search() ska klampa current_page och räkna om offset korrekt.'
                    );
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([
                ['id' => 3, 'name' => 'C'],
                ['id' => 4, 'name' => 'D'],
            ]);

        // Minimal modellklass för hydrering
        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $perPage = 3;
        $currentPage = 3; // medvetet större än sista sidan

        $result = $qb->search('foo', ['name'], $perPage, $currentPage);

        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
        $this->assertCount(2, $result['data']);

        $meta = $result['search'];

        // 1) Dödar LogicalNot-mutanten: total ska vara int 4, inte sträng '4'
        $this->assertSame(4, $meta['total'], 'total ska castas från numerisk sträng till int.');

        // 2) Dödar RoundingFamily-mutanten: last_page ska följa ceil, dvs 2
        $this->assertSame(2, $meta['last_page'], 'last_page ska baseras på ceil(total/per_page).');

        // 3) Dödar LogicalAndAllSubExprNegation: current_page ska klampas till 2, inte lämnas som 3
        $this->assertSame(2, $meta['current_page'], 'current_page ska klampas ned till sista sidan.');

        $this->assertSame($perPage, $meta['per_page']);
        $this->assertSame(1, $meta['first_page']);
    }

    public function testSearchClampsNegativeCurrentPageToOneAndUsesOffsetZero(): void
    {
        $conn = $this->createMock(Connection::class);

        $conn->method('fetchOne')->willReturn(['total' => 1]);

        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Om else-grenen muteras till 2 blir offset (2-1)*10 = 10.
                    $this->assertStringContainsString('LIMIT 10 OFFSET 0', $sql);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn([['id' => 1, 'name' => 'A']]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $res = $qb->search('foo', ['name'], 10, -1);

        $this->assertSame(1, $res['search']['current_page']);
    }

    public function testSearchUsesWhereThenOrWhereInsideGroupInCorrectOrder(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(['total' => 1]);

        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    // Kräver att första kolumnen blir "LIKE ?" och att andra kommer efter med "OR ... LIKE ?"
                    $this->assertStringContainsString('WHERE (', $sql);

                    $posFirst = strpos($sql, '`name` LIKE ?');
                    $posOrSecond = strpos($sql, ' OR `email` LIKE ?');

                    $this->assertNotFalse($posFirst, 'Första villkoret ska vara `name` LIKE ?');
                    $this->assertNotFalse($posOrSecond, 'Andra villkoret ska vara OR `email` LIKE ?');
                    $this->assertTrue($posFirst < $posOrSecond, 'OR-villkoret måste komma efter första LIKE-villkoret.');

                    return true;
                }),
                $this->anything()
            )
            ->willReturn([['id' => 1, 'name' => 'A']]);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'users';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
        };

        $qb = (new QueryBuilder())
            ->setConnection($conn)
            ->from('users')
            ->setModelClass(get_class($model));

        $qb->search('foo', ['name', 'email'], 10, 1);
    }

    public function testBuildWhereNestedMissingQueryKeyThrowsLogicExceptionAndDoesNotTriggerWarning(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        // Injicera ett nested-villkor som saknar 'query'
        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);
        $refWhere->setValue($qb, [
            [
                'type' => 'nested',
                'boolean' => 'AND',
                // 'query' saknas avsiktligt
            ],
        ]);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Nested where requires a QueryBuilder instance.');
            $qb->toSql();
        } finally {
            restore_error_handler();
        }
    }

    public function testBuildWhereTrimsLeadingWhitespaceSoAndOrPrefixIsRemoved(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        // Skapa ett raw-villkor men med boolean som har ledande whitespace.
        // Utan trim($sql) kommer preg_replace(/^(AND|OR)\s+/) inte matcha p.g.a. leading space.
        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);
        $refWhere->setValue($qb, [
            [
                'type' => 'raw',
                'column' => '`id`',
                'operator' => '=',
                'value' => '?',
                'boolean' => ' AND', // <- ledande space
            ],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = ?',
            $qb->toSql()
        );
    }

    public function testBuildWhereTrimsPerConditionForExistsSoSecondConditionDoesNotContainExtraLeadingSpace(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);

        // Andra villkoret har boolean med ledande whitespace.
        // Original: trim("{$boolean} {$operator} {$value}") => "OR EXISTS (SELECT 1)"
        // Mutant 93: ingen trim => " OR EXISTS (SELECT 1)" => extra whitespace mitt i SQL.
        $refWhere->setValue($qb, [
            [
                'type' => 'raw',
                'column' => '`id`',
                'operator' => '=',
                'value' => '?',
                'boolean' => 'AND',
            ],
            [
                'type' => 'exists',
                'operator' => 'EXISTS',
                'value' => '(SELECT 1)',
                'boolean' => ' OR', // <- ledande space är hela poängen
            ],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = ? OR EXISTS (SELECT 1)',
            $qb->toSql()
        );
    }

    public function testBuildWhereTrimsPerConditionForRawSqlSoSecondConditionDoesNotContainExtraLeadingSpace(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);

        // Original: trim("{$boolean} ({$sql})") => "OR (1 = 1)"
        // Mutant 94: ingen trim => " OR (1 = 1)" => extra whitespace mitt i SQL.
        $refWhere->setValue($qb, [
            [
                'type' => 'raw',
                'column' => '`id`',
                'operator' => '=',
                'value' => '?',
                'boolean' => 'AND',
            ],
            [
                'type' => 'raw_sql',
                'sql' => '1 = 1',
                'boolean' => ' OR', // <- ledande space
            ],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = ? OR (1 = 1)',
            $qb->toSql()
        );
    }

    public function testBuildWhereTrimsWholeSqlSoLeadingWhitespaceFromNestedBooleanIsRemovedAndPrefixIsStripped(): void
    {
        $outer = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        $nested = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('t')
            ->where('age', '>=', 18);

        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);

        // Nested-grenen gör INGEN trim på "{$boolean} ($nestedWhere)".
        // Så vi kan skapa ett leading space som bara trim($sql) på slutet tar bort.
        // Mutant 95 (tar bort trim($sql)) gör att preg_replace inte tar bort AND/OR-prefixet korrekt.
        $refWhere->setValue($outer, [
            [
                'type' => 'nested',
                'query' => $nested,
                'boolean' => ' AND', // <- ledande space
            ],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE (`age` >= ?)',
            $outer->toSql()
        );
    }

    public function testConcatLiteralDetectionUsesAnchorsSoNonLiteralsAreWrapped(): void
    {
        $q = new class extends QueryBuilder {
            /** @var list<string> */
            public array $wrapped = [];

            protected function wrapColumn(string $column): string
            {
                $this->wrapped[] = $column;
                return 'WRAPPED<' . $column . '>';
            }
        };

        $q->setConnection($this->connection);

        // Undvik from() (som annars också triggar wrapColumn) och sätt table direkt.
        $refTable = new ReflectionProperty(QueryBuilder::class, 'table');
        $refTable->setAccessible(true);
        $refTable->setValue($q, '`users`');

        // 1) Slutar med ' men börjar inte med ':
        //    - Original /^'.*'$/ => matchar INTE => wrapColumn() ska köras
        //    - Mutant (utan ^) kan matcha "'.*'$" i slutet => wrapColumn() körs INTE
        //
        // 2) Börjar med ' men slutar inte med ':
        //    - Original matchar INTE => wrapColumn() ska köras
        //    - Mutant (utan $) matchar "^'.*'" => wrapColumn() körs INTE
        $q->concat(["x'foo'", "'bar'baz"], 'x');

        $sql = $q->toSql();

        $this->assertStringContainsString(
            'WRAPPED<x\'foo\'>',
            $sql
        );
        $this->assertStringContainsString(
            'WRAPPED<\'bar\'baz>',
            $sql
        );

        // Extra: bevisa att wrapColumn verkligen användes för båda inputen
        $this->assertSame(
            ["x'foo'", "'bar'baz"],
            $q->wrapped
        );
    }

    public function testWhereRejectsWhitespaceOnlyColumnName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The column name in WHERE clause cannot be empty.');

        (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('   ', '=', 1);
    }

    public function testWhereAcceptsLowercaseOperatorsLikeAndNormalizesToUppercaseInSql(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users')
            ->where('name', 'like', '%a%');

        // Original: operator normaliseras via strtoupper => LIKE
        // Mutant (#81): behåller 'like' => valideringen misslyckas eller SQL blir fel.
        $this->assertSame(
            'SELECT * FROM `users` WHERE `name` LIKE ?',
            $qb->toSql()
        );
        $this->assertSame(['%a%'], $qb->getBindings());
    }

    public function testBuildWhereCanBeOverriddenInSubclassAndIsUsedByToSql(): void
    {
        $qb = new class extends QueryBuilder {
            protected function buildWhere(): string
            {
                return 'WHERE 1 = 1';
            }
        };

        $qb
            ->setConnection($this->connection)
            ->from('users');

        // Original (protected): override:n ska användas
        // Mutant (#86, private): override:n ignoreras och traitens buildWhere() körs istället
        $this->assertSame(
            'SELECT * FROM `users` WHERE 1 = 1',
            $qb->toSql()
        );
    }

    public function testBuildWhereInvalidConditionNonArrayThrowsLogicExceptionAndDoesNotTriggerWarning(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);
        $refWhere->setValue($qb, ['not-an-array-condition']);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Invalid where condition structure.');
            $qb->toSql();
        } finally {
            restore_error_handler();
        }
    }

    public function testBuildWhereInvalidConditionMissingTypeKeyThrowsLogicExceptionAndDoesNotTriggerWarning(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);

        // Array men saknar 'type' => ska fångas av guard och kasta LogicException
        $refWhere->setValue($qb, [
            [
                'boolean' => 'AND',
                'column' => '`id`',
                'operator' => '=',
                'value' => '?',
            ],
        ]);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Invalid where condition structure.');
            $qb->toSql();
        } finally {
            restore_error_handler();
        }
    }

    public function testBuildWhereTreatsLowercaseIsOperatorAsNullComparisonForListTypeWhenValueIsNull(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);

        // Vi injicerar en "list"-condition direkt för att hamna i buildWhere()-grenen
        // som använder strtoupper($operator). Det gör mutanten (#89) testbar.
        $refWhere->setValue($qb, [
            [
                'type' => 'list',
                'boolean' => 'AND',
                'column' => '`deleted_at`',
                'operator' => 'is',   // <- gemener
                'value' => null,      // <- ska ge "... IS NULL"
            ],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `deleted_at` is NULL',
            $qb->toSql(),
            'Observera: här verifierar vi beteendet "NULL-grenen" baserat på operatorn, inte casing i utskriften.'
        );
    }

    public function testBuildWhereTrimsPerConditionForBetweenSoLeadingWhitespaceBooleanDoesNotProduceExtraSpace(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);

        $refWhere->setValue($qb, [
            [
                'type' => 'raw',
                'column' => '`id`',
                'operator' => '=',
                'value' => '?',
                'boolean' => 'AND',
            ],
            [
                'type' => 'between',
                'column' => '`age`',
                'operator' => 'BETWEEN',
                'value' => '? AND ?',
                'boolean' => ' OR', // <- ledande space: kräver trim(...) i between-grenen
            ],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = ? OR `age` BETWEEN ? AND ?',
            $qb->toSql()
        );
    }

    public function testBuildWhereTrimsPerConditionForColumnSoLeadingWhitespaceBooleanDoesNotProduceExtraSpace(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);

        $refWhere->setValue($qb, [
            [
                'type' => 'raw',
                'column' => '`id`',
                'operator' => '=',
                'value' => '?',
                'boolean' => 'AND',
            ],
            [
                'type' => 'column',
                'column' => '`a`',
                'operator' => '=',
                'value' => '`b`',
                'boolean' => ' OR', // <- ledande space: kräver trim(...) i column-grenen
            ],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = ? OR `a` = `b`',
            $qb->toSql()
        );
    }

    public function testBuildWhereTrimsPerConditionForIsNullBranchSoSecondConditionDoesNotContainExtraLeadingSpace(): void
    {
        $qb = (new QueryBuilder())
            ->setConnection($this->connection)
            ->from('users');

        $refWhere = new ReflectionProperty(QueryBuilder::class, 'where');
        $refWhere->setAccessible(true);

        // Andra villkoret har boolean med ledande whitespace.
        // Original: trim(...) => "OR `deleted_at` IS NULL"
        // Mutant (#84): ingen trim => " OR `deleted_at` IS NULL" => extra whitespace mitt i SQL.
        $refWhere->setValue($qb, [
            [
                'type' => 'raw',
                'column' => '`id`',
                'operator' => '=',
                'value' => '?',
                'boolean' => 'AND',
            ],
            [
                'type' => 'raw',
                'column' => '`deleted_at`',
                'operator' => 'IS',
                'value' => null,
                'boolean' => ' OR', // <- ledande space är hela poängen
            ],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = ? OR `deleted_at` IS NULL',
            $qb->toSql()
        );
    }

}
