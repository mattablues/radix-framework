<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use Dummy\Models\Status;
use Dummy\Models\TeamMember;
use Dummy\Models\User;
use Exception;
use LogicException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\ConventionModelClassResolver;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\BelongsTo;
use Radix\Database\ORM\Relationships\BelongsToMany;
use Radix\Database\ORM\Relationships\HasMany;
use Radix\Database\ORM\Relationships\HasOne;
use Radix\Database\ORM\Relationships\HasOneThrough;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use stdClass;

class RelationshipsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // ORM i tester: konfigurera resolver (motsvarar det appen gör i services.php)
        Model::setModelClassResolver(new ConventionModelClassResolver('Dummy\\Models\\'));

        \Radix\Container\ApplicationContainer::reset();
        $container = new \Radix\Container\Container();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Minimal users-tabell för testet
        $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT,
            avatar TEXT NOT NULL DEFAULT '/images/graphics/avatar.png',
            role TEXT NOT NULL DEFAULT 'user',
            created_at TEXT NULL,
            updated_at TEXT NULL,
            deleted_at TEXT NULL
        );
    ");

        // Registrera i containern
        $container->addShared(PDO::class, fn() => $pdo);
        $container->add('Psr\Container\ContainerInterface', fn() => $container);
        \Radix\Container\ApplicationContainer::set($container);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $user = new User();
        $user->setFillable(['id', 'first_name', 'last_name', 'email', 'avatar']);
        $user->setGuarded(['password', 'role']);
    }

    public function testPasswordAttribute(): void
    {
        $user = new User();
        $user->fill([
            'first_name' => 'Mats',
            'last_name' => 'Åkebrand',
            'email' => 'malle@akebrands.se',
        ]);

        // Testa att sätta lösenord
        $user->password = 'korvar65';

        $this->assertNotNull($user->password);

        // Kontrollera om password finns i attributlistan (använd publik API)
        $this->assertArrayHasKey('password', $user->getAttributes());
    }

    public function testGuardedOverridesFillable(): void
    {
        $user = new User();
        $user->setFillable(['email', 'first_name']); // Tillåt dessa attribut
        $user->setGuarded(['email']); // Blockera email specifikt

        $user->fill([
            'email' => 'blocked@example.com',
            'first_name' => 'Allowed',
        ]);

        $this->assertNull($user->getAttribute('email')); // Ska vara blockerat
        $this->assertSame('Allowed', $user->getAttribute('first_name')); // Ska vara tillåtet
    }

    public function testWithCountAddRelationCountSelectIsProtected(): void
    {
        $ref = new ReflectionMethod(\Radix\Database\QueryBuilder\QueryBuilder::class, 'addRelationCountSelect');

        $this->assertTrue(
            $ref->isProtected(),
            'addRelationCountSelect ska vara protected (inte private/public) för att kunna överskuggas men inte vara publik API‑yta.'
        );
    }

    public function testWithAggregateHasOneThroughReadsPrivateRelationPropertiesViaReflection(): void
    {
        $conn = $this->createMock(\Radix\Database\Connection::class);
        $conn->method('fetchAll')->willReturn([]); // vi verifierar bara SQL

        $rel = (new ReflectionClass(\Radix\Database\ORM\Relationships\HasOneThrough::class))
            ->newInstanceWithoutConstructor();

        $refRel = new ReflectionClass($rel);

        foreach ([
            'related' => 'votes',
            'through' => 'subjects',
            'firstKey' => 'category_id',
            'secondKey' => 'subject_id',
            'secondLocal' => 'id',
        ] as $prop => $value) {
            $p = $refRel->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($rel, $value);
        }

        // Parent-modell som QueryBuilder kan instansiera utan ctor-argument
        $parentClass = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'categories';

            public static ?\Radix\Database\Connection $connForTest = null;
            public static ?object $relForTest = null;

            protected function getConnection(): \Radix\Database\Connection
            {
                if (self::$connForTest === null) {
                    throw new LogicException('Test connection not configured.');
                }
                return self::$connForTest;
            }

            public function topVote(): object
            {
                if (self::$relForTest === null) {
                    throw new LogicException('Test relation not configured.');
                }
                return self::$relForTest;
            }
        };

        $cls = get_class($parentClass);
        $cls::$connForTest = $conn;
        $cls::$relForTest = $rel;

        $sql = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($conn)
            ->setModelClass($cls)
            ->from('categories')
            ->withSum('topVote', 'points')
            ->toSql();

        $this->assertStringContainsString('FROM `categories`', $sql);
        $this->assertStringContainsString(
            'FROM `votes` AS r INNER JOIN `subjects` AS t ON t.`id` = r.`subject_id`',
            $sql
        );
        $this->assertStringContainsString('WHERE t.`category_id` = `categories`.`id`', $sql);
        $this->assertStringContainsString('SELECT SUM(`r`.`points`)', $sql);
    }

    public function testUserMassFilling(): void
    {
        $user = new User();

        // Massfyllning av tillåtna fält
        $user->fill([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'avatar' => '/images/john.png',
            'password' => 'secret', // Skyddat fält, ska ignoreras
        ]);

        $this->assertSame('John', $user->getAttribute('first_name'));
        $this->assertSame('Doe', $user->getAttribute('last_name'));
        $this->assertSame('john.doe@example.com', $user->getAttribute('email'));
        $this->assertSame('/images/john.png', $user->getAttribute('avatar'));
        $this->assertNull($user->getAttribute('password')); // Skyddat av `guarded`
    }

    public function testConstructorMassFillsAttributes(): void
    {
        // Här skickar vi data direkt till konstruktorn
        $user = new User([
            'first_name' => 'MutationKiller',
            'email' => 'killer@example.com',
        ]);

        $this->assertSame('MutationKiller', $user->getAttribute('first_name'));
        $this->assertSame('killer@example.com', $user->getAttribute('email'));
    }

    public function testAllAttributesGuarded(): void
    {
        $user = new User();
        $user->setFillable([]); // Töm alla fillable-attribut
        $user->setGuarded(['*']); // Blockera alla attribu

        $user->fill([
            'first_name' => 'Markus',
            'last_name' => 'Jones',
            'email' => 'markus@example.com',
        ]);

        // Kontrollera att skyddade attribut inte är fyllda
        $this->assertNull($user->getAttribute('first_name'));
        $this->assertNull($user->getAttribute('last_name'));
        $this->assertNull($user->getAttribute('email'));
    }

    public function testWithAggregateHasManyThroughReadsPrivateRelationPropertiesViaReflection(): void
    {
        $conn = $this->createMock(\Radix\Database\Connection::class);
        $conn->method('fetchAll')->willReturn([]); // vi verifierar bara SQL

        // HasManyThrough utan ctor så att WithAggregate MÅSTE läsa privata props via Reflection + setAccessible(true)
        $rel = (new ReflectionClass(\Radix\Database\ORM\Relationships\HasManyThrough::class))
            ->newInstanceWithoutConstructor();

        $refRel = new ReflectionClass($rel);

        foreach ([
            'related' => 'votes',
            'through' => 'subjects',
            'firstKey' => 'category_id',
            'secondKey' => 'subject_id',
            'secondLocal' => 'id',
        ] as $prop => $value) {
            $p = $refRel->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($rel, $value);
        }

        // Parent-modell utan ctor-argument (QueryBuilder instansierar utan args)
        $parentClass = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'categories';

            public static ?\Radix\Database\Connection $connForTest = null;
            public static ?object $relForTest = null;

            protected function getConnection(): \Radix\Database\Connection
            {
                if (self::$connForTest === null) {
                    throw new LogicException('Test connection not configured.');
                }
                return self::$connForTest;
            }

            public function votes(): object
            {
                if (self::$relForTest === null) {
                    throw new LogicException('Test relation not configured.');
                }
                return self::$relForTest;
            }
        };

        $cls = get_class($parentClass);
        $cls::$connForTest = $conn;
        $cls::$relForTest = $rel;

        $sql = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($conn)
            ->setModelClass($cls)
            ->from('categories')
            ->withSum('votes', 'points')
            ->toSql();

        // Om setAccessible(true) tas bort/false: ReflectionProperty::getValue() på private props ska krascha
        // och testet failar (mutanterna dör). Med originalkod ska SQL innehålla JOIN-strukturen.
        $this->assertStringContainsString('FROM `categories`', $sql);
        $this->assertStringContainsString(
            'FROM `votes` AS r INNER JOIN `subjects` AS t ON t.`id` = r.`subject_id`',
            $sql
        );
        $this->assertStringContainsString('WHERE t.`category_id` = `categories`.`id`', $sql);
        $this->assertStringContainsString('SELECT SUM(`r`.`points`)', $sql);
    }

    public function testWithAggregateResolveTableDoesNotInstantiateExistingNonModelClass(): void
    {
        $conn = $this->createMock(\Radix\Database\Connection::class);
        $conn->method('fetchAll')->willReturn([]);

        $rel = (new ReflectionClass(\Radix\Database\ORM\Relationships\HasManyThrough::class))
            ->newInstanceWithoutConstructor();

        $refRel = new ReflectionClass($rel);

        foreach ([
            'related' => stdClass::class,  // class_exists=true men INTE Model
            'through' => stdClass::class,  // class_exists=true men INTE Model
            'firstKey' => 'category_id',
            'secondKey' => 'subject_id',
            'secondLocal' => 'id',
        ] as $prop => $value) {
            $p = $refRel->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($rel, $value);
        }

        $parentClass = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'categories';

            public static ?\Radix\Database\Connection $connForTest = null;
            public static ?object $relForTest = null;

            protected function getConnection(): \Radix\Database\Connection
            {
                if (self::$connForTest === null) {
                    throw new LogicException('Test connection not configured.');
                }
                return self::$connForTest;
            }

            public function votes(): object
            {
                if (self::$relForTest === null) {
                    throw new LogicException('Test relation not configured.');
                }
                return self::$relForTest;
            }
        };

        $cls = get_class($parentClass);
        $cls::$connForTest = $conn;
        $cls::$relForTest = $rel;

        $sql = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($conn)
            ->setModelClass($cls)
            ->from('categories')
            ->withSum('votes', 'points')
            ->toSql();

        // Original: ska INTE instansiera stdClass, utan använda strängen som tabellnamn
        // Mutant: försöker instansiera stdClass och anropa getTable() => crash => testet dör mutanten
        $this->assertStringContainsString('FROM `stdClass` AS r', $sql);
        $this->assertStringContainsString('INNER JOIN `stdClass` AS t', $sql);
    }

    public function testFillableEmptyAllowsAllAttributes(): void
    {
        $user = new User();

        // Sätt både fillable och guarded till tomt
        $user->setFillable([]);
        $user->setGuarded([]);

        $user->fill([
            'first_name' => 'Lisa',
            'last_name' => 'Johnson',
            'email' => 'lisa@example.com',
        ]);

        // Kontrollera att alla attribut fylldes
        $this->assertSame('Lisa', $user->getAttribute('first_name'));
        $this->assertSame('Johnson', $user->getAttribute('last_name'));
        $this->assertSame('lisa@example.com', $user->getAttribute('email'));
    }

    public function testGuardedAndFillableAttributesMixed(): void
    {
        $user = new User();
        $user->setFillable(['first_name', 'email']);
        $user->setGuarded(['password']);

        $user->fill([
            'first_name' => 'Ella',
            'last_name' => 'Williamson', // Ej definierad i fillable, ignoreras
            'email' => 'ella@example.com',
            'password' => 'secretpassword', // Skyddat, ignoreras
        ]);

        // Kontrollera att endast tillåtna attribut fylldes
        $this->assertSame('Ella', $user->getAttribute('first_name'));
        $this->assertNull($user->getAttribute('last_name'));
        $this->assertSame('ella@example.com', $user->getAttribute('email'));
        $this->assertNull($user->getAttribute('password'));
    }

    public function testUnknownAttributesIgnored(): void
    {
        $user = new User();

        $user->fill([
            'nickname' => 'Johnny', // Ej existerande attribut i modellen
            'email' => 'johnny@example.com',
        ]);

        // Kontrollera att bara giltiga attribut fylldes
        $this->assertNull($user->getAttribute('nickname')); // Ignoreras
        $this->assertSame('johnny@example.com', $user->getAttribute('email'));
    }

    public function testDynamicGuardedAttributes(): void
    {
        $user = new User();
        $user->setGuarded(['email']); // Skydda "email"

        $user->fill([
            'first_name' => 'Anna',
            'email' => 'anna@example.com', // Ska ignoreras
        ]);

        $this->assertSame('Anna', $user->getAttribute('first_name'));
        $this->assertNull($user->getAttribute('email')); // Nu korrekt!
    }

    public function testDynamicGuardedAndFillable(): void
    {
        $user = new User();
        $user->setGuarded(['*']); // Skydda allt
        $user->setFillable(['first_name']); // Tillåt endast first_name

        $user->fill([
            'first_name' => 'Ella',
            'last_name' => 'Smith',
            'email' => 'ella@example.com',
        ]);

        $this->assertEquals('Ella', $user->getAttribute('first_name'), "Attributet first_name borde vara 'Ella'");
        $this->assertNull($user->getAttribute('last_name'), 'Attributet last_name borde vara null');
        $this->assertNull($user->getAttribute('email'), 'Attributet email borde vara null');
    }

    public function testStatusMassFilling(): void
    {
        $status = new Status();

        // Massfyllning av tillåtna fält
        $status->fill([
            'password_reset' => 'abc123',
            'reset_expires_at' => '2025-12-01 00:00:00',
            'user_id' => 5, // Skyddat fält, ska ignoreras
        ]);

        $this->assertSame('abc123', $status->getAttribute('password_reset'));
        $this->assertSame('2025-12-01 00:00:00', $status->getAttribute('reset_expires_at'));
        //$this->assertNull($status->getAttribute('user_id')); // Skyddat av `guarded`
    }

    public function testFillableOverridesGuarded(): void
    {
        $user = new User();

        // Gör "password" både guarded och fillable
        $user->setGuarded(['password']);
        $user->setFillable(['password']);

        $user->fill([
            'password' => 'not_so_secret',
        ]);

        // Då "password" är guarded ska det inte fyllas
        $this->assertNull($user->getAttribute('password'));
    }

    public function testGuardedAttributesAreIgnored(): void
    {
        $user = new User();

        // Försök fylla ett skyddat fält
        $user->fill([
            'first_name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret', // Ska ignoreras enligt `guarded`
        ]);

        // Kontrollera att skyddade fält ignorerades
        $this->assertSame('Alice', $user->getAttribute('first_name')); // Tillåtet
        $this->assertSame('alice@example.com', $user->getAttribute('email')); // Tillåtet
        $this->assertNull($user->getAttribute('password')); // Ignorerat
    }

    public function testUnknownFieldsAreIgnored(): void
    {
        $user = new User();

        // Försök fylla fält som inte är definierade i `fillable` eller `guarded`
        $user->fill([
            'first_name' => 'Bob',
            'nickname' => 'Bobby', // Okänt fält
        ]);

        // Kontrollera att endast tillåtna fält fylls
        $this->assertSame('Bob', $user->getAttribute('first_name'));
        $this->assertNull($user->getAttribute('nickname')); // Ignoreras helt
    }

    public function testDynamicFillableAttributes(): void
    {
        $user = new User();

        // Tillåt massfyllning av ett nytt attribut
        $user->setFillable(['first_name', 'last_name', 'nickname']);

        $user->fill([
            'first_name' => 'Clara',
            'last_name' => 'Smith',
            'nickname' => 'Clary', // Dynamiskt tillagt
        ]);

        // Kontrollera att alla tillagda fält är fyllda
        $this->assertSame('Clara', $user->getAttribute('first_name'));
        $this->assertSame('Smith', $user->getAttribute('last_name'));
        $this->assertSame('Clary', $user->getAttribute('nickname'));
    }

    public function testModelExistsFlag(): void
    {
        $model = new User();
        $this->assertFalse($model->isExisting());

        $model->markAsExisting();
        $this->assertTrue($model->isExisting());
    }

    public function testHasOneThroughGetCallsFirst(): void
    {
        $rel = $this->createPartialMock(\Radix\Database\ORM\Relationships\HasOneThrough::class, ['first']);
        $rel->expects($this->once())->method('first');

        $rel->get();
    }

    public function testHasManyReturnsRelatedRecords(): void
    {
        // Förvänta oss att dessa värden returneras från databasen
        $expectedResults = [
            ['id' => 1, 'post_id' => 10, 'content' => 'First comment'],
            ['id' => 2, 'post_id' => 10, 'content' => 'Second comment'],
        ];

        // Mocka Connection och simulera fetchAll
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAll')->willReturn($expectedResults);

        // Simulera dataelement till HasMany utan att röra skyddade egenskaper
        $results = array_map(function ($record) {
            $m = new class extends Model {
                protected string $table = 'comments';
                /** array<int, string> */
                protected array $fillable = ['id', 'post_id', 'content'];
            };
            $m->forceFill($record);
            return $m;
        }, $expectedResults);

        // Validera antal resultat
        $this->assertCount(2, $results);

        // Kontrollera första resultatet
        $this->assertInstanceOf(Model::class, $results[0]);
        $this->assertEquals(1, $results[0]->getAttribute('id'));
        $this->assertEquals(10, $results[0]->getAttribute('post_id'));
        $this->assertEquals('First comment', $results[0]->getAttribute('content'));

        // Kontrollera andra resultatet
        $this->assertInstanceOf(Model::class, $results[1]);
        $this->assertEquals(2, $results[1]->getAttribute('id'));
        $this->assertEquals(10, $results[1]->getAttribute('post_id'));
        $this->assertEquals('Second comment', $results[1]->getAttribute('content'));
    }

    public function testHasOneReturnsSingleRelatedRecord(): void
    {
        // Mocka data från databasen
        $expectedResult = ['id' => 1, 'user_id' => 5, 'profile' => 'Profile details'];

        // Mocka databasanslutningen och simulera fetchOne
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->isType('string'),
                $this->equalTo([5])
            )
            ->willReturn($expectedResult);

        // Dynamisk modell för profilen
        $profileClass = new class extends Model {
            protected string $table = 'profiles';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'user_id', 'profile'];
        };

        // Skapa en HasOne-relation
        $hasOne = new HasOne(
            $connection,
            get_class($profileClass),
            'user_id',
            '5'
        );

        // Hämta relaterad modell via first()
        $result = $hasOne->first();

        // Verifiera att resultatet är av rätt klass
        $this->assertInstanceOf(get_class($profileClass), $result);

        // Kontrollera att attributen innehåller förväntade värden
        $this->assertEquals($expectedResult['id'], $result->getAttribute('id'));
        $this->assertEquals($expectedResult['user_id'], $result->getAttribute('user_id'));
        $this->assertEquals($expectedResult['profile'], $result->getAttribute('profile'));

        // Hydrerad HasOne-modell ska markeras som existerande
        $this->assertTrue($result->isExisting());
    }

    public function testHasManyWith(): void
    {
        $expectedResults = [
            ['id' => 1, 'post_id' => 10, 'content' => 'First comment'],
            ['id' => 2, 'post_id' => 10, 'content' => 'Second comment'],
        ];

        // Mocka anslutningen
        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('fetchAll')->willReturn($expectedResults);

        // Parent-modell med injicerad connection
        $post = new class ($connectionMock) extends Model {
            protected string $table = 'posts';
            /** array<int, string> */
            protected array $fillable = ['id', 'title'];
            private Connection $conn;

            public function __construct(Connection $c)
            {
                $this->conn = $c;
                parent::__construct([]);
            }

            protected function getConnection(): Connection
            {
                return $this->conn;
            }

            // Relaterad modellklass utan ctor-krav (HasMany instansierar den med new $class())
            public function comments(): HasMany
            {
                $comment = new class extends Model {
                    protected string $table = 'comments';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'content'];
                };
                $rel = new HasMany(
                    $this->getConnection(),
                    get_class($comment),
                    'post_id',
                    'id'
                );
                $rel->setParent($this);
                return $rel;
            }
        };

        $post->forceFill(['id' => 10]);

        // Hämta relaterade kommentarer
        $comments = $post->comments()->get();

        $this->assertCount(2, $comments);
        $this->assertInstanceOf(Model::class, $comments[0]);
        $this->assertEquals('First comment', $comments[0]->getAttribute('content'));
    }

    public function testBelongsToManyReturnsRelatedRecords(): void
    {
        $expectedResults = [
            ['id' => 1, 'first_name' => 'John'],
            ['id' => 2, 'first_name' => 'Jane'],
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAll')
            ->willReturn($expectedResults);

        $belongsToMany = new BelongsToMany(
            $connection,
            \Dummy\Models\User::class,    // Korrekt klassnamn
            'role_user',                // Pivot table
            'role_id',                  // Foreign pivot key
            'user_id',                  // Related pivot key
            '1'                         // Parent key
        );

        $results = $belongsToMany->get();

        $this->assertCount(2, $results);

        foreach ($results as $index => $result) {
            $this->assertInstanceOf(\Dummy\Models\User::class, $result);
            $this->assertEquals($expectedResults[$index]['id'], $result->getAttribute('id'));
            $this->assertEquals($expectedResults[$index]['first_name'], $result->getAttribute('first_name'));
            $this->assertTrue(
                $result->isExisting(),
                'BelongsToMany-hydrerade modeller ska markeras som existerande.'
            );
        }
    }

    public function testUserJsonSerialization(): void
    {
        // Skapa en tom modell och använd forceFill för all data
        // Detta garanterar att ordningen på nycklarna blir som du förväntar dig
        $user = new User();
        $user->forceFill([
            'id' => 1,
            'first_name' => 'John',
            'email' => 'john.doe@example.com',
        ]);

        // Mocka `Post`-instanser med `toArray` och `jsonSerialize`-stöd
        $post1 = $this->createMock(Model::class);
        $post1->method('toArray')->willReturn([
            'id' => 1, 'title' => 'First Post', 'content' => 'This is the first post.',
        ]);
        $post1->method('jsonSerialize')->willReturn([
            'id' => 1, 'title' => 'First Post', 'content' => 'This is the first post.',
        ]);

        $post2 = $this->createMock(Model::class);
        $post2->method('toArray')->willReturn([
            'id' => 2, 'title' => 'Second Post', 'content' => 'This is the second post.',
        ]);
        $post2->method('jsonSerialize')->willReturn([
            'id' => 2, 'title' => 'Second Post', 'content' => 'This is the second post.',
        ]);

        // Sätt relation på mockade postar
        $user->setRelation('posts', [$post1, $post2]);

        // Debugga relationen innan testning
        $this->assertNotEmpty($user->getRelation('posts'), 'Posts relation is empty.');

        $expectedJson = json_encode([
            'id' => 1,
            'first_name' => 'John',
            'email' => 'john.doe@example.com',
            'posts' => [
                [
                    'id' => 1,
                    'title' => 'First Post',
                    'content' => 'This is the first post.',
                ],
                [
                    'id' => 2,
                    'title' => 'Second Post',
                    'content' => 'This is the second post.',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $actualJson = json_encode($user, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Jämför förväntad och faktisk JSON
        $this->assertSame($expectedJson, $actualJson, 'JSON serialization does not match expected output.');
    }

    public function testHasManyFirstReturnsRelatedRecord(): void
    {
        // Mocka Comment-modellen
        $commentMock = $this->createMock(Model::class);
        $commentMock->method('getAttribute')->willReturnMap([
            ['id', 1],
            ['post_id', 10],
            ['content', 'First comment'],
        ]);

        // Mocka HasMany-relationen
        $hasManyMock = $this->createMock(HasMany::class);
        $hasManyMock->method('first')->willReturn($commentMock);

        // Kalla på `first` och kontrollera resultatet
        $result = $hasManyMock->first();

        $this->assertInstanceOf(Model::class, $result);
        $this->assertEquals('First comment', $result->getAttribute('content'));
    }

    public function testBelongsToManyFirstReturnsRecord(): void
    {
        // Mockat resultat från databasen
        $expectedResults = [
            ['id' => 1, 'first_name' => 'John'],
            ['id' => 2, 'first_name' => 'Jane'],
        ];

        // Mocka databasanslutningen
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAll')->willReturn($expectedResults);

        // Skapa en instans av BelongsToMany
        $belongsToMany = new BelongsToMany(
            $connection,
            \Dummy\Models\User::class,
            'role_user',          // Pivot-tabell
            'role_id',            // Foreign pivot key
            'user_id',            // Related pivot key
            '1'                   // Parent key som sträng
        );

        // Kör first() för att få första modellen
        $result = $belongsToMany->first();

        // Validera att resultatet är en instans av rätt modellklass
        $this->assertInstanceOf(\Dummy\Models\User::class, $result);

        // Kontrollera att attributen stämmer överens
        $this->assertEquals('John', $result->getAttribute('first_name'));
        $this->assertTrue(
            $result->isExisting(),
            'BelongsToMany::first()-resultat ska markeras som existerande.'
        );
    }

    public function testHydrateFromDatabaseIncludesTimestampsSkipsGuarded(): void
    {
        $row = [
            'id' => 42,
            'first_name' => 'Mats',
            'last_name' => 'Åkebrand',
            'email' => 'mats@example.com',
            'avatar' => '/images/user/42/avatar.jpg',
            'password' => 'hashed', // guarded
            'role' => 'admin',      // guarded
            'deleted_at' => null,   // guarded
            'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-02 11:00:00',
        ];

        $u = new User();
        $u->hydrateFromDatabase($row)->markAsExisting();

        // timestamps ska vara läsbara
        $this->assertSame('2025-01-01 10:00:00', $u->getAttribute('created_at'));
        $this->assertSame('2025-01-02 11:00:00', $u->getAttribute('updated_at'));

        // guarded ska inte vara hydrerade in i attributes
        $this->assertNull($u->getAttribute('password'));
        $this->assertNull($u->getAttribute('role'));
        $this->assertNull($u->getAttribute('deleted_at'));

        // övriga vanliga fält finns
        $this->assertSame(42, $u->getAttribute('id'));
        $this->assertSame('Mats', $u->getAttribute('first_name'));
        $this->assertSame('Åkebrand', $u->getAttribute('last_name'));
        $this->assertSame('mats@example.com', $u->getAttribute('email'));
        $this->assertSame('/images/user/42/avatar.jpg', $u->getAttribute('avatar'));
    }

    public function testFillDoesNotMassAssignTimestamps(): void
    {
        $u = new User();
        $u->fill([
            'first_name' => 'Clara',
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-02 00:00:00',
        ]);

        $this->assertSame('Clara', $u->getAttribute('first_name'));
        $this->assertNull($u->getAttribute('created_at'));
        $this->assertNull($u->getAttribute('updated_at'));
    }

    public function testHasOneThroughFirstReturnsSingleRelatedRecord(): void
    {
        // Simulerad rad från relaterad tabell (votes)
        $expectedRow = ['id' => 7, 'subject_id' => 3, 'points' => 42];

        $connection = $this->createMock(Connection::class);
        // Förvänta att fetchOne anropas med en bind-array som innehåller parent-värdet (1)
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->isType('string'),
                $this->equalTo([1])
            )
            ->willReturn($expectedRow);

        // Parent: categories (utan ctor-argument)
        $category = new class extends Model {
            protected string $table = 'categories';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'name'];
            private ?Connection $conn = null;

            public function setTestConnection(Connection $c): void
            {
                $this->conn = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->conn ?? parent::getConnection();
            }
        };
        $category->forceFill(['id' => 1]);

        // Through: subjects
        $subjectClass = new class extends Model {
            protected string $table = 'subjects';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'category_id', 'name'];
        };

        // Related: votes
        $voteClass = new class extends Model {
            protected string $table = 'votes';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'subject_id', 'points'];
        };

        // Koppla test-connection till parent
        $category->setTestConnection($connection);

        $rel = new HasOneThrough(
            $connection,
            get_class($voteClass),     // related
            get_class($subjectClass),  // through
            'category_id',             // subjects.category_id
            'subject_id',              // votes.subject_id
            'id',                      // categories.id
            'id'                       // subjects.id
        );
        $rel->setParent($category);

        $result = $rel->first();

        $this->assertNotNull($result);
        $this->assertEquals(7, $result->getAttribute('id'));
        $this->assertEquals(3, $result->getAttribute('subject_id'));
        $this->assertEquals(42, $result->getAttribute('points'));

        // NYTT: modellen ska markeras som existerande
        $this->assertTrue($result->isExisting(), 'HasOneThrough-hydrerad modell ska markeras som existerande.');
    }

    public function testQueryBuilderAggregateAndCountWithHasOneThrough(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAll')->willReturn([]); // vi behöver bara SQL

        // Parentmodell med relation topVote() som HasOneThrough
        $parent = new class extends Model {
            protected string $table = 'categories';
            /** array<int, string> */
            protected array $fillable = ['id', 'name'];
            private ?Connection $conn = null;

            public function setTestConnection(Connection $c): void
            {
                $this->conn = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->conn ?? parent::getConnection();
            }

            public function topVote(): HasOneThrough
            {
                $through = new class extends Model {
                    protected string $table = 'subjects';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'category_id'];
                };
                $related = new class extends Model {
                    protected string $table = 'votes';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'subject_id', 'points'];
                };

                return (new HasOneThrough(
                    $this->getConnection(),
                    get_class($related),
                    get_class($through),
                    'category_id', // subjects.category_id
                    'subject_id',  // votes.subject_id
                    'id',          // categories.id
                    'id'           // subjects.id
                ))->setParent($this);
            }
        };
        // Sätt connection via en instans (QueryBuilder kommer instansiera utan args)
        $parent->setTestConnection($connection);

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($connection)
            ->setModelClass(get_class($parent))
            ->from('categories')
            ->withAggregate('topVote', 'points', 'MAX', 'topVote_max')
            ->withCount('topVote');

        $sql = $qb->toSql();

        // Kontrollera att subqueries för både aggregat och count byggs korrekt
        $this->assertStringContainsString('FROM `categories`', $sql);
        $this->assertStringContainsString('SELECT MAX(`r`.`points`)', $sql);
        $this->assertStringContainsString(
            'FROM `votes` AS r INNER JOIN `subjects` AS t ON t.`id` = r.`subject_id`',
            $sql
        );
        $this->assertStringContainsString('WHERE t.`category_id` = `categories`.`id`', $sql);
        $this->assertStringContainsString('SELECT COUNT(*)', $sql);
    }


    public function testWithCountWhereSupportsHasOneThrough(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAll')->willReturn([]); // endast SQL-verifiering

        // Parent-modell utan ctor-argument + relation topVote()
        $parent = new class extends Model {
            protected string $table = 'categories';
            /** array<int, string> */
            protected array $fillable = ['id', 'name'];
            private ?Connection $conn = null;

            public function setTestConnection(Connection $c): void
            {
                $this->conn = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->conn ?? parent::getConnection();
            }

            public function topVote(): HasOneThrough
            {
                $through = new class extends Model {
                    protected string $table = 'subjects';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'category_id'];
                };
                $related = new class extends Model {
                    protected string $table = 'votes';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'subject_id', 'points', 'status'];
                };

                return (new HasOneThrough(
                    $this->getConnection(),
                    get_class($related),
                    get_class($through),
                    'category_id', // subjects.category_id
                    'subject_id',  // votes.subject_id
                    'id',          // categories.id
                    'id'           // subjects.id
                ))->setParent($this);
            }
        };
        $parent->setTestConnection($connection);

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($connection)
            ->setModelClass(get_class($parent))
            ->from('categories')
            ->withCountWhere('topVote', 'status', 'approved', 'topVote_approved');

        $sql = $qb->toSql();

        // SQL ska innehålla subquery med JOIN subjects -> votes och filter på r.status = 'approved'
        $this->assertStringContainsString('FROM `categories`', $sql);
        $this->assertStringContainsString('SELECT COUNT(*)', $sql);
        $this->assertStringContainsString(
            'FROM `votes` AS r INNER JOIN `subjects` AS t ON t.`id` = r.`subject_id`',
            $sql
        );
        $this->assertStringContainsString('WHERE t.`category_id` = `categories`.`id`', $sql);
        $this->assertStringContainsString("AND r.`status` = 'approved'", $sql);
    }

    public function testWithAggregateHasManyThroughUsesModelTablesViaResolveTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAll')->willReturn([]); // endast SQL-verifiering

        // Parent-modell med HasManyThrough-relation votes()
        $parent = new class extends Model {
            protected string $table = 'categories';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
            private ?Connection $conn = null;

            public function setTestConnection(Connection $c): void
            {
                $this->conn = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->conn ?? parent::getConnection();
            }

            public function votes(): \Radix\Database\ORM\Relationships\HasManyThrough
            {
                // Medvetet “udda” tabellnamn så vi ser att resolveTable använder modellens getTable()
                $through = new class extends Model {
                    protected string $table = 'subjects_custom';
                    /** @var array<int,string> */
                    protected array $fillable = ['id', 'category_id'];
                };

                $related = new class extends Model {
                    protected string $table = 'votes_custom';
                    /** @var array<int,string> */
                    protected array $fillable = ['id', 'subject_id', 'points'];
                };

                return (new \Radix\Database\ORM\Relationships\HasManyThrough(
                    $this->getConnection(),
                    get_class($related),   // related MODEL-klass
                    get_class($through),   // through MODEL-klass
                    'category_id',         // subjects.category_id
                    'subject_id',          // votes.subject_id
                    'id',                  // categories.id
                    'id'                   // subjects.id
                ))->setParent($this);
            }
        };

        $parent->setTestConnection($connection);

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($connection)
            ->setModelClass(get_class($parent))
            ->from('categories')
            ->withAggregate('votes', 'points', 'SUM', 'votes_sum');

        $sql = $qb->toSql();

        // Rätt beteende: resolveTable() ska ta tabellnamn från modellernas getTable()
        $this->assertStringContainsString('FROM `categories`', $sql);
        $this->assertStringContainsString(
            'FROM `votes_custom` AS r INNER JOIN `subjects_custom` AS t ON t.`id` = r.`subject_id`',
            $sql
        );
        $this->assertStringContainsString(
            'WHERE t.`category_id` = `categories`.`id`',
            $sql
        );
    }

    public function testWithAggregateAcceptsLowercaseFunctionName(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAll')->willReturn([]);

        // Enkel parent-modell med HasMany-relation comments()
        $parent = new class extends Model {
            protected string $table = 'posts';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'title'];
            private ?Connection $conn = null;

            public function setTestConnection(Connection $c): void
            {
                $this->conn = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->conn ?? parent::getConnection();
            }

            public function comments(): HasMany
            {
                $comment = new class extends Model {
                    protected string $table = 'comments';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'points'];
                };

                return $this->hasMany(get_class($comment), 'post_id', 'id');
            }
        };

        $parent->setTestConnection($connection);

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($connection)
            ->setModelClass(get_class($parent))
            ->from('posts')
            // Viktigt: fn i gemener – ska ändå fungera tack vare strtoupper($fn)
            ->withAggregate('comments', 'points', 'sum');

        $sql = $qb->toSql();

        // Vi förväntar oss att funktionen i SQL är normaliserad till SUM(...)
        $this->assertStringContainsString(
            'SELECT SUM(`comments`.`points`)',
            $sql,
            'withAggregate ska acceptera "sum" (gemener) och normalisera till SUM(...) i SQL.'
        );
    }

    public function testWithAggregateHasOneThroughUsesModelTablesViaResolveTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAll')->willReturn([]); // endast SQL-verifiering

        // Parent-modell med HasOneThrough-relation topVote()
        $parent = new class extends Model {
            protected string $table = 'categories';
            /** @var array<int,string> */
            protected array $fillable = ['id', 'name'];
            private ?Connection $conn = null;

            public function setTestConnection(Connection $c): void
            {
                $this->conn = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->conn ?? parent::getConnection();
            }

            public function topVote(): \Radix\Database\ORM\Relationships\HasOneThrough
            {
                // Udda tabellnamn så vi verifierar resolveTable() för HasOneThrough‑varianten
                $through = new class extends Model {
                    protected string $table = 'subjects_custom_one';
                    /** @var array<int,string> */
                    protected array $fillable = ['id', 'category_id'];
                };

                $related = new class extends Model {
                    protected string $table = 'votes_custom_one';
                    /** @var array<int,string> */
                    protected array $fillable = ['id', 'subject_id', 'points'];
                };

                return (new \Radix\Database\ORM\Relationships\HasOneThrough(
                    $this->getConnection(),
                    get_class($related),   // related MODEL-klass
                    get_class($through),   // through MODEL-klass
                    'category_id',         // subjects.category_id
                    'subject_id',          // votes.subject_id
                    'id',                  // categories.id
                    'id'                   // subjects.id
                ))->setParent($this);
            }
        };

        $parent->setTestConnection($connection);

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($connection)
            ->setModelClass(get_class($parent))
            ->from('categories')
            ->withAggregate('topVote', 'points', 'SUM', 'topVote_points_sum');

        $sql = $qb->toSql();

        // resolveTable() i HasOneThrough‑grenen ska också använda modellernas getTable()
        $this->assertStringContainsString('FROM `categories`', $sql);
        $this->assertStringContainsString(
            'FROM `votes_custom_one` AS r INNER JOIN `subjects_custom_one` AS t ON t.`id` = r.`subject_id`',
            $sql
        );
        $this->assertStringContainsString(
            'WHERE t.`category_id` = `categories`.`id`',
            $sql
        );
        // För extra säkerhet kan vi även verifiera att LIMIT 1 finns med i subqueryn
        $this->assertStringContainsString('LIMIT 1', $sql);
    }

    public function testWithCountWhereUsesSnakeCasedAliasForCamelCaseRelation(): void
    {
        $conn = $this->createMock(\Radix\Database\Connection::class);
        $conn->method('fetchAll')->willReturn([]);

        $parent = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'posts';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'title'];
            private ?\Radix\Database\Connection $c = null;

            public function setConn(\Radix\Database\Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): \Radix\Database\Connection
            {
                return $this->c ?? parent::getConnection();
            }

            /**
             * Medvetet camelCase-namn så att alias ska bli "top_comments_count_approved".
             */
            public function topComments(): \Radix\Database\ORM\Relationships\HasMany
            {
                $comment = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'comments';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'status'];
                };

                return new \Radix\Database\ORM\Relationships\HasMany(
                    $this->getConnection(),
                    get_class($comment),
                    'post_id',
                    'id'
                );
            }
        };

        $parent->setConn($conn);

        $sql = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($conn)
            ->setModelClass(get_class($parent))
            ->from('posts')
            ->withCountWhere('topComments', 'status', 'approved')
            ->toSql();

        // Standard-alias: snake_case(topComments) + "_count_" + "approved"
        $this->assertStringContainsString('AS `top_comments_count_approved`', $sql);
    }

    public function testWithCountSupportsHasOneThroughUsesModelTable(): void
    {
        $connection = $this->createMock(\Radix\Database\Connection::class);
        $connection->method('fetchAll')->willReturn([]); // vi verifierar bara SQL

        $parent = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'categories';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'name'];
            private ?\Radix\Database\Connection $conn = null;

            public function setTestConnection(\Radix\Database\Connection $c): void
            {
                $this->conn = $c;
            }

            protected function getConnection(): \Radix\Database\Connection
            {
                return $this->conn ?? parent::getConnection();
            }

            public function topVote(): \Radix\Database\ORM\Relationships\HasOneThrough
            {
                $through = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'subjects';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'category_id'];
                };

                // Medvetet “udda” tabellnamn så vi ser skillnad på klass vs table
                $related = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'votes_custom';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'subject_id', 'points'];
                };

                return (new \Radix\Database\ORM\Relationships\HasOneThrough(
                    $this->getConnection(),
                    get_class($related),
                    get_class($through),
                    'category_id', // subjects.category_id
                    'subject_id',  // votes.subject_id
                    'id',          // categories.id
                    'id'           // subjects.id
                ))->setParent($this);
            }
        };

        $parent->setTestConnection($connection);

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($connection)
            ->setModelClass(get_class($parent))
            ->from('categories')
            ->withCount('topVote');

        $sql = $qb->toSql();

        // Nyckel: relaterad tabell ska tas från modellens getTable() => `votes_custom`
        $this->assertStringContainsString('FROM `categories`', $sql);
        $this->assertStringContainsString(
            'FROM `votes_custom` AS r INNER JOIN `subjects` AS t ON t.`id` = r.`subject_id`',
            $sql
        );
    }

    public function testWithCountWhereSupportsHasMany(): void
    {
        $conn = $this->createMock(\Radix\Database\Connection::class);
        $conn->method('fetchAll')->willReturn([]);

        $parent = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'posts';
            /** array<int, string> */
            protected array $fillable = ['id', 'title'];
            private ?\Radix\Database\Connection $c = null;

            public function setConn(\Radix\Database\Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): \Radix\Database\Connection
            {
                return $this->c ?? parent::getConnection();
            }

            public function comments(): \Radix\Database\ORM\Relationships\HasMany
            {
                $comment = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'comments';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'status'];
                };
                return new \Radix\Database\ORM\Relationships\HasMany(
                    $this->getConnection(),
                    get_class($comment),
                    'post_id',
                    'id'
                );
            }
        };
        $parent->setConn($conn);

        $sql = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($conn)
            ->setModelClass(get_class($parent))
            ->from('posts')
            ->withCountWhere('comments', 'status', 'approved', 'comments_approved')
            ->toSql();

        $this->assertStringContainsString('FROM `posts`', $sql);
        $this->assertStringContainsString('SELECT COUNT(*) FROM `comments`', $sql);
        $this->assertStringContainsString('`comments`.`post_id` = `posts`.`id`', $sql);
        $this->assertStringContainsString("`comments`.`status` = 'approved'", $sql);
    }

    public function testWithCountWhereSupportsBelongsToMany(): void
    {
        $conn = $this->createMock(\Radix\Database\Connection::class);
        $conn->method('fetchAll')->willReturn([]);

        $parent = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'roles';
            /** array<int, string> */
            protected array $fillable = ['id', 'name'];
            private ?\Radix\Database\Connection $c = null;

            public function setConn(\Radix\Database\Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): \Radix\Database\Connection
            {
                return $this->c ?? parent::getConnection();
            }

            public function users(): \Radix\Database\ORM\Relationships\BelongsToMany
            {
                return new \Radix\Database\ORM\Relationships\BelongsToMany(
                    $this->getConnection(),
                    \Dummy\Models\User::class,
                    'role_user',
                    'role_id',
                    'user_id',
                    'id'
                );
            }
        };
        $parent->setConn($conn);

        $sql = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($conn)
            ->setModelClass(get_class($parent))
            ->from('roles')
            ->withCountWhere('users', 'status', 'active', 'users_active')
            ->toSql();

        $this->assertStringContainsString('FROM `roles`', $sql);
        $this->assertStringContainsString('FROM `role_user` AS pivot', $sql);
        $this->assertStringContainsString('INNER JOIN `users` AS related', $sql);
        $this->assertStringContainsString('pivot.`role_id` = `roles`.`id`', $sql);
        $this->assertStringContainsString("related.`status` = 'active'", $sql);
    }

    public function testWithCountWhereSupportsBelongsTo(): void
    {
        $connection = $this->createMock(\Radix\Database\Connection::class);
        $connection->method('fetchAll')->willReturn([]); // vi verifierar bara SQL

        // Parent-modell: comments som "tillhör" posts
        $comment = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'comments';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'post_id', 'status'];

            private ?\Radix\Database\Connection $conn = null;

            public function setTestConnection(\Radix\Database\Connection $c): void
            {
                $this->conn = $c;
            }

            protected function getConnection(): \Radix\Database\Connection
            {
                return $this->conn ?? parent::getConnection();
            }

            public function post(): \Radix\Database\ORM\Relationships\BelongsTo
            {
                $related = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'posts';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'title', 'status'];
                };

                return new \Radix\Database\ORM\Relationships\BelongsTo(
                    $this->getConnection(),
                    $related->getTable(), // relatedTable
                    'post_id',            // foreignKey
                    'id',                 // ownerKey
                    $this                 // parent
                );
            }
        };
        $comment->setTestConnection($connection);

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($connection)
            ->setModelClass(get_class($comment))
            ->from('comments')
            ->withCountWhere('post', 'status', 'published', 'post_published');

        $sql = $qb->toSql();

        // Kontrollera att SQL:en ser rimlig ut och binder mot rätt tabell/nycklar
        $this->assertStringContainsString('FROM `comments`', $sql);
        $this->assertStringContainsString('SELECT COUNT(*) FROM `posts`', $sql);
        $this->assertStringContainsString('`posts`.`id` = `comments`.`post_id`', $sql);
        $this->assertStringContainsString("`posts`.`status` = 'published'", $sql);
        $this->assertStringContainsString('AS `post_published`', $sql);
    }

    public function testWithCountWhereUsesModelTableWhenDifferentFromRelationName(): void
    {
        $conn = $this->createMock(\Radix\Database\Connection::class);
        $conn->method('fetchAll')->willReturn([]);

        $parent = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'posts';
            /** array<int, string> */
            protected array $fillable = ['id', 'title'];
            private ?\Radix\Database\Connection $c = null;

            public function setConn(\Radix\Database\Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): \Radix\Database\Connection
            {
                return $this->c ?? parent::getConnection();
            }

            /**
             * Relationsnamnet skiljer sig från tabellnamnet:
             *  - metodnamn: approvedComments
             *  - tabellnamn: post_comments
             */
            public function approvedComments(): \Radix\Database\ORM\Relationships\HasMany
            {
                $comment = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'post_comments';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'status'];
                };

                return new \Radix\Database\ORM\Relationships\HasMany(
                    $this->getConnection(),
                    get_class($comment),
                    'post_id',
                    'id'
                );
            }
        };

        $parent->setConn($conn);

        $sql = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($conn)
            ->setModelClass(get_class($parent))
            ->from('posts')
            ->withCountWhere('approvedComments', 'status', 'approved', 'approvedComments_approved')
            ->toSql();

        // Här är nyckeln:
        // - RÄTT beteende: använda modellens tabellnamn `post_comments`
        // - Muterad kod som faller tillbaka på relationsnamnet skulle använda `approvedComments`
        $this->assertStringContainsString('FROM `posts`', $sql);
        $this->assertStringContainsString('SELECT COUNT(*) FROM `post_comments`', $sql);
        $this->assertStringContainsString('`post_comments`.`post_id` = `posts`.`id`', $sql);
        $this->assertStringContainsString("`post_comments`.`status` = 'approved'", $sql);
    }

    public function testWithCountUsesSnakeCasedAliasForCamelCaseRelation(): void
    {
        $conn = $this->createMock(\Radix\Database\Connection::class);
        $conn->method('fetchAll')->willReturn([]);

        $parent = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'categories';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'name'];
            private ?\Radix\Database\Connection $c = null;

            public function setConn(\Radix\Database\Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): \Radix\Database\Connection
            {
                return $this->c ?? parent::getConnection();
            }

            /**
             * Medvetet camelCase-namn så att alias ska bli "top_vote_count".
             */
            public function topVote(): \Radix\Database\ORM\Relationships\HasMany
            {
                $vote = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'votes';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'category_id', 'points'];
                };

                return new \Radix\Database\ORM\Relationships\HasMany(
                    $this->getConnection(),
                    get_class($vote),
                    'category_id',
                    'id'
                );
            }
        };

        $parent->setConn($conn);

        $sql = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($conn)
            ->setModelClass(get_class($parent))
            ->from('categories')
            ->withCount('topVote')
            ->toSql();

        // Viktigt: aliaset ska vara snake_case: "top_vote_count"
        $this->assertStringContainsString('AS `top_vote_count`', $sql);
    }

    public function testWithCountHasManyResolvesAppModelTableNameViaConvention(): void
    {
        $conn = $this->createMock(\Radix\Database\Connection::class);
        $conn->method('fetchAll')->willReturn([]); // vi verifierar bara genererad SQL

        // Parent-modell med HasMany-relation "teamMembers"
        $parent = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'teams';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'name'];

            private ?\Radix\Database\Connection $c = null;

            public function setConn(\Radix\Database\Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): \Radix\Database\Connection
            {
                return $this->c ?? parent::getConnection();
            }

            /**
             * Relationsnamn: teamMembers
             *  - singularize('teamMembers') => 'teamMember'
             *  - ucfirst(...) => 'TeamMember'
             *  - konvention: Dummy\Models\TeamMember (med tabell 'members')
             */
            public function teamMembers(): \Radix\Database\ORM\Relationships\HasMany
            {
                return new \Radix\Database\ORM\Relationships\HasMany(
                    $this->getConnection(),
                    \Dummy\Models\TeamMember::class,
                    'team_id',
                    'id'
                );
            }
        };

        $parent->setConn($conn);

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($conn)
            ->setModelClass(get_class($parent))
            ->from('teams')
            ->withCount('teamMembers');

        $sql = $qb->toSql();

        // Rätt beteende: tabell tas från Dummy\Models\TeamMember::getTable() => 'members'
        $this->assertStringContainsString('FROM `teams`', $sql);
        $this->assertStringContainsString('SELECT COUNT(*) FROM `members`', $sql);
        $this->assertStringContainsString('`members`.`team_id` = `teams`.`id`', $sql);
    }

    public function testWithAggregateDefaultAliasForHasMany(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAll')->willReturn([]); // vi bryr oss bara om SQL

        // Parent-modell med en enkel HasMany-relation comments()
        $parent = new class extends Model {
            protected string $table = 'posts';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'title'];
            private ?Connection $conn = null;

            public function setTestConnection(Connection $c): void
            {
                $this->conn = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->conn ?? parent::getConnection();
            }

            public function comments(): HasMany
            {
                $comment = new class extends Model {
                    protected string $table = 'comments';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'points'];
                };

                // Använd hasMany-helpern så relationen sätts upp som i produktion
                return $this->hasMany(
                    get_class($comment),
                    'post_id',
                    'id'
                );
            }
        };

        $parent->setTestConnection($connection);

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($connection)
            ->setModelClass(get_class($parent))
            ->from('posts')
            // Viktigt: inget alias anges → default-alias ska användas
            ->withSum('comments', 'points');

        $sql = $qb->toSql();

        // 1) SUM ska användas som funktion i subqueryn
        $this->assertStringContainsString('SELECT SUM(`comments`.`points`)', $sql);

        // 2) Default-aliaset ska vara relation_namn + '_' + strtolower(fn)
        //    dvs "comments_sum"
        $this->assertStringContainsString(
            'AS `comments_sum`',
            $sql,
            'Default-alias för withSum("comments", ...) ska vara `comments_sum`.'
        );
    }

    public function testWithAggregateStoresComputedAliasWhenAliasIsNull(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAll')->willReturn([]);

        // Parent-modell med enkel HasMany-relation comments()
        $parent = new class extends Model {
            protected string $table = 'posts';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'title'];
            private ?Connection $conn = null;

            public function setTestConnection(Connection $c): void
            {
                $this->conn = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->conn ?? parent::getConnection();
            }

            public function comments(): HasMany
            {
                $comment = new class extends Model {
                    protected string $table = 'comments';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'points'];
                };

                return $this->hasMany(get_class($comment), 'post_id', 'id');
            }
        };

        $parent->setTestConnection($connection);

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($connection)
            ->setModelClass(get_class($parent))
            ->from('posts')
            // Viktigt: inget alias → ska trigga default-alias-logiken i withAggregate()
            ->withSum('comments', 'points');

        // Plocka ut withAggregateExpressions via reflection
        $ref = new ReflectionClass(\Radix\Database\QueryBuilder\QueryBuilder::class);
        $prop = $ref->getProperty('withAggregateExpressions');
        $prop->setAccessible(true);
        /** @var array<int, string> $aliases */
        $aliases = $prop->getValue($qb);

        $this->assertSame(
            ['comments_sum'],
            $aliases,
            'withSum("comments", "points") utan alias ska spara default-alias "comments_sum" i withAggregateExpressions.'
        );
    }

    public function testWithAggregateAddRelationAggregateSelectIsProtected(): void
    {
        $ref = new ReflectionMethod(
            \Radix\Database\QueryBuilder\QueryBuilder::class,
            'addRelationAggregateSelect'
        );

        $this->assertTrue(
            $ref->isProtected(),
            'addRelationAggregateSelect ska vara protected (inte private/public) för att kunna överskuggas men inte vara del av det publika API:t.'
        );
    }

    public function testWithAggregateDefaultAliasUsesSnakeCaseForCamelCaseRelation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAll')->willReturn([]); // endast SQL-verifiering

        // Parent-modell med HasOneThrough-relation topVote()
        $parent = new class extends Model {
            protected string $table = 'categories';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'name'];
            private ?Connection $conn = null;

            public function setTestConnection(Connection $c): void
            {
                $this->conn = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->conn ?? parent::getConnection();
            }

            public function topVote(): \Radix\Database\ORM\Relationships\HasOneThrough
            {
                $through = new class extends Model {
                    protected string $table = 'subjects';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'category_id'];
                };

                $related = new class extends Model {
                    protected string $table = 'votes';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'subject_id', 'points'];
                };

                return (new \Radix\Database\ORM\Relationships\HasOneThrough(
                    $this->getConnection(),
                    get_class($related),
                    get_class($through),
                    'category_id', // subjects.category_id
                    'subject_id',  // votes.subject_id
                    'id',          // categories.id
                    'id'           // subjects.id
                ))->setParent($this);
            }
        };

        $parent->setTestConnection($connection);

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($connection)
            ->setModelClass(get_class($parent))
            ->from('categories')
            // Inget alias – vi vill testa default-snake_case
            ->withSum('topVote', 'points');

        $sql = $qb->toSql();

        // Originalkodens snake_case: "top_vote_sum"
        // Mutanten ger "topvote_sum" → asserten nedan faller då.
        $this->assertStringContainsString(
            'AS `top_vote_sum`',
            $sql,
            'Default-alias för relationen "topVote" och SUM ska vara `top_vote_sum` (snake_case + _sum).'
        );
    }


    public function testModelLoadLoadsRelations(): void
    {
        $rows = [
            ['id' => 1, 'post_id' => 10, 'status' => 'published'],
            ['id' => 2, 'post_id' => 10, 'status' => 'draft'],
        ];

        $conn = $this->createMock(\Radix\Database\Connection::class);
        $conn->method('fetchAll')->willReturn($rows);

        // Parent-modell med hasMany relation "comments"
        $post = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'posts';
            /** array<int, string> */
            protected array $fillable = ['id', 'title'];
            private ?\Radix\Database\Connection $c = null;

            public function setConn(\Radix\Database\Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): \Radix\Database\Connection
            {
                return $this->c ?? parent::getConnection();
            }

            public function comments(): \Radix\Database\ORM\Relationships\HasMany
            {
                $comment = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'comments';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'status'];
                };
                $rel = new \Radix\Database\ORM\Relationships\HasMany(
                    $this->getConnection(),
                    get_class($comment),
                    'post_id',
                    'id'
                );
                $rel->setParent($this);
                return $rel;
            }
        };

        $post->setConn($conn);
        $post->forceFill(['id' => 10]);

        // Ladda enkel relation
        $post->load('comments');
        $loaded = $post->getRelation('comments');

        /** @var array<int,\Radix\Database\ORM\Model> $loaded */
        $loaded = $loaded;
        $this->assertCount(2, $loaded);
        $this->assertSame('published', $loaded[0]->getAttribute('status'));

        // Ladda flera (idempotent)
        $post->load(['comments']);
        // räcker att det inte kastar något här
    }


    public function testModelLoadMissingSkipsAlreadyLoaded(): void
    {
        $rowsInitial = [
            ['id' => 1, 'post_id' => 10, 'status' => 'published'],
        ];
        $rowsSecond = [
            ['id' => 2, 'post_id' => 10, 'status' => 'draft'],
        ];

        $conn = $this->createMock(\Radix\Database\Connection::class);
        // Första anropet returnerar initiala rader, andra anropet skulle returnera andra rader
        $conn->method('fetchAll')->willReturnOnConsecutiveCalls($rowsInitial, $rowsSecond);

        $post = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'posts';
            /** array<int, string> */
            protected array $fillable = ['id', 'title'];
            private ?\Radix\Database\Connection $c = null;

            public function setConn(\Radix\Database\Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): \Radix\Database\Connection
            {
                return $this->c ?? parent::getConnection();
            }

            public function comments(): \Radix\Database\ORM\Relationships\HasMany
            {
                $comment = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'comments';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'status'];
                };
                $rel = new \Radix\Database\ORM\Relationships\HasMany(
                    $this->getConnection(),
                    get_class($comment),
                    'post_id',
                    'id'
                );
                $rel->setParent($this);
                return $rel;
            }
        };

        $post->setConn($conn);
        $post->forceFill(['id' => 10]);

        // Ladda en gång
        $post->load('comments');
        $loadedFirst = $post->getRelation('comments');
        /** @var array<int,\Radix\Database\ORM\Model> $loadedFirst */
        $loadedFirst = $loadedFirst;
        $this->assertCount(1, $loadedFirst);

        // loadMissing ska inte ladda om "comments"
        $post->loadMissing('comments');
        $loadedAfter = $post->getRelation('comments');
        /** @var array<int,\Radix\Database\ORM\Model> $loadedAfter */
        $loadedAfter = $loadedAfter;
        $this->assertCount(1, $loadedAfter);
        $this->assertSame(
            $loadedFirst[0]->getAttribute('status'),
            $loadedAfter[0]->getAttribute('status')
        );
    }

    public function testModelLoadWithConstraintClosure(): void
    {
        // Vi verifierar att load() inte kraschar när constraint passar en QueryBuilder
        // och att relationen sätts. Vi behöver inte validera exakt SQL här.
        $rows = [
            ['id' => 1, 'post_id' => 10, 'status' => 'published'],
        ];
        $conn = $this->createMock(\Radix\Database\Connection::class);
        $conn->method('fetchAll')->willReturn($rows);

        $post = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'posts';
            /** array<int, string> */
            protected array $fillable = ['id'];
            private ?\Radix\Database\Connection $c = null;

            public function setConn(\Radix\Database\Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): \Radix\Database\Connection
            {
                return $this->c ?? parent::getConnection();
            }

            public function comments(): \Radix\Database\ORM\Relationships\HasMany
            {
                $comment = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'comments';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'status'];
                };
                $rel = new \Radix\Database\ORM\Relationships\HasMany(
                    $this->getConnection(),
                    get_class($comment),
                    'post_id',
                    'id'
                );
                $rel->setParent($this);
                return $rel;
            }
        };

        $post->setConn($conn);
        $post->forceFill(['id' => 10]);

        $post->load([
            'comments' => function (\Radix\Database\QueryBuilder\QueryBuilder $q) {
                $q->where('status', '=', 'published')->orderBy('id', 'DESC');
            },
        ]);

        $loaded = $post->getRelation('comments');


        $this->assertInstanceOf(\Radix\Collection\Collection::class, $loaded);
        $this->assertCount(1, $loaded);
        $first = $loaded->first();
        $this->assertInstanceOf(\Radix\Database\ORM\Model::class, $first);
        $this->assertSame('published', $first->getAttribute('status'));
    }

    public function testHasOneWithDefaultReturnsEmptyModelWhenMissing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(null); // inget resultat

        // Parent-modell
        $user = new class extends Model {
            protected string $table = 'users';
            /** array<int, string> */
            protected array $fillable = ['id', 'first_name'];
            private ?Connection $conn = null;

            public function setConn(Connection $c): void
            {
                $this->conn = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->conn ?? parent::getConnection();
            }

            public function profile(): HasOne
            {
                $profile = new class extends Model {
                    protected string $table = 'profiles';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'user_id', 'avatar'];
                };

                $rel = new HasOne($this->getConnection(), get_class($profile), 'user_id', 'id');
                $rel->setParent($this);
                return $rel;
            }
        };
        $user->setConn($connection);
        $user->forceFill(['id' => 10]);

        $result = $user->profile()->withDefault()->first();

        $this->assertInstanceOf(Model::class, $result);
        $this->assertFalse($result->isExisting(), 'Default-modell ska markeras som ny (isExisting=false)');
    }

    public function testHasOneWithDefaultArrayAttributes(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(null);

        $user = new class extends Model {
            protected string $table = 'users';
            /** array<int, string> */
            protected array $fillable = ['id'];
            private ?Connection $c = null;

            public function setConn(Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->c ?? parent::getConnection();
            }

            public function profile(): HasOne
            {
                $profile = new class extends Model {
                    protected string $table = 'profiles';
                    /** array<int, string> */
                    protected array $fillable = ['avatar'];
                };
                $rel = new HasOne($this->getConnection(), get_class($profile), 'user_id', 'id');
                $rel->setParent($this);
                return $rel;
            }
        };
        $user->setConn($connection);
        $user->forceFill(['id' => 15]);

        $result = $user->profile()->withDefault(['avatar' => '/img/default.png'])->first();

        $this->assertInstanceOf(Model::class, $result);
        $this->assertSame('/img/default.png', $result->getAttribute('avatar'));
        $this->assertFalse($result->isExisting());
    }

    public function testBelongsToWithDefaultCallable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(null); // inget parent-resultat

        // Child-modell med belongsTo user()
        $status = new class extends Model {
            protected string $table = 'statuses';
            /** array<int, string> */
            protected array $fillable = ['id', 'user_id'];
            private ?Connection $c = null;

            public function setConn(Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->c ?? parent::getConnection();
            }

            public function user(): BelongsTo
            {
                $user = new class extends Model {
                    protected string $table = 'users';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'first_name'];
                };

                return new BelongsTo(
                    $this->getConnection(),
                    get_class($user), // <-- FQCN istället för $user->getTable()
                    'user_id',
                    'id',
                    $this
                );
            }
        };

        $status->setConn($connection);
        $status->forceFill(['user_id' => 999]);

        $u = $status->user()->withDefault(function (Model $m) {
            $m->forceFill(['first_name' => 'Unknown']);
        })->first();

        $this->assertInstanceOf(Model::class, $u);
        $this->assertSame('Unknown', $u->getAttribute('first_name'));
        $this->assertFalse($u->isExisting());
    }

    public function testModelLoadRespectsWithDefaultForHasOne(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(null);

        $parent = new class extends Model {
            protected string $table = 'users';
            /** array<int, string> */
            protected array $fillable = ['id'];
            private ?Connection $c = null;

            public function setConn(Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->c ?? parent::getConnection();
            }

            public function profile(): HasOne
            {
                $profile = new class extends Model {
                    protected string $table = 'profiles';
                    /** array<int, string> */
                    protected array $fillable = ['avatar'];
                };
                $rel = new HasOne($this->getConnection(), get_class($profile), 'user_id', 'id');
                $rel->setParent($this);
                return $rel;
            }
        };
        $parent->setConn($connection);
        $parent->forceFill(['id' => 77]);

        $parent->load([
            'profile' => function (HasOne $rel) {
                // relation-objekt (HasOne)
                $rel->withDefault(['avatar' => '/img/default.png']);
            },
        ]);

        $profile = $parent->getRelation('profile');
        $this->assertInstanceOf(Model::class, $profile);
        $this->assertSame('/img/default.png', $profile->getAttribute('avatar'));
        $this->assertFalse($profile->isExisting());
    }

    public function testModelLoadMissingRespectsWithDefaultForBelongsTo(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(null);

        $child = new class extends Model {
            protected string $table = 'statuses';
            /** array<int, string> */
            protected array $fillable = ['id', 'user_id'];
            private ?Connection $c = null;

            public function setConn(Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->c ?? parent::getConnection();
            }

            public function user(): BelongsTo
            {
                $user = new class extends Model {
                    protected string $table = 'users';
                    /** array<int, string> */
                    protected array $fillable = ['first_name'];
                };

                return new BelongsTo(
                    $this->getConnection(),
                    get_class($user), // <-- FQCN istället för $user->getTable()
                    'user_id',
                    'id',
                    $this
                );
            }
        };

        $child->setConn($connection);
        $child->forceFill(['user_id' => 5]);

        $child->loadMissing([
            'user' => function (BelongsTo $rel): void {
                $rel->withDefault(['first_name' => 'N/A']);
            },
        ]);

        $user = $child->getRelation('user');
        $this->assertInstanceOf(Model::class, $user);
        $this->assertSame('N/A', $user->getAttribute('first_name'));
        $this->assertFalse($user->isExisting());
    }

    public function testHasManySetsParentRelationWithLowercasedShortName(): void
    {
        // Mock-resultat från databasen
        $row = ['id' => 1, 'post_id' => 10, 'content' => 'First comment'];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAll')->willReturn([$row]);

        // Definiera en NAMNGIVEN parent-klass så shortName är stabilt
        $parent = new class ($conn) extends Model {
            protected string $table = 'posts';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'title'];

            private Connection $c;

            public function __construct(Connection $c)
            {
                $this->c = $c;
                parent::__construct([]);
            }

            protected function getConnection(): Connection
            {
                return $this->c;
            }

            public function comments(): HasMany
            {
                $comment = new class extends Model {
                    protected string $table = 'comments';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'content'];
                };

                $rel = new HasMany(
                    $this->getConnection(),
                    get_class($comment),
                    'post_id',
                    'id'
                );
                $rel->setParent($this);
                return $rel;
            }
        };

        $parent->forceFill(['id' => 10]);

        $comments = $parent->comments()->get();
        $this->assertCount(1, $comments);

        /** @var Model $comment */
        $comment = $comments[0];

        // ShortName för parent-klassen, lowercased
        $shortName = (new ReflectionClass($parent))->getShortName();
        $expectedRelationKey = strtolower($shortName);

        $this->assertSame(
            $parent,
            $comment->getRelation($expectedRelationKey),
            'HasMany ska sätta parent-modellen som relation med lowercased shortName.'
        );
    }

    public function testHasOneReturnsNullWhenParentLocalKeyIsNullAndNoDefault(): void
    {
        $connection = $this->createMock(Connection::class);
        // Ingen query ska göras när localKey är null
        $connection->expects($this->never())
            ->method('fetchOne');

        // Parent-modell utan id (localKey = 'id' är null)
        $parent = new class extends Model {
            protected string $table = 'users';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'first_name'];
            private ?Connection $c = null;

            public function setConn(Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->c ?? parent::getConnection();
            }

            public function profile(): HasOne
            {
                $profile = new class extends Model {
                    protected string $table = 'profiles';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'user_id', 'avatar'];
                };

                $rel = new HasOne($this->getConnection(), get_class($profile), 'user_id', 'id');
                $rel->setParent($this);
                return $rel;
            }
        };
        $parent->setConn($connection);
        // OBS: vi fyller INTE 'id', så localKey 'id' blir null
        $parent->forceFill(['first_name' => 'NoId']);

        $result = $parent->profile()->first();

        $this->assertNull($result, 'HasOne utan default och null localKey ska returnera null.');
    }

    public function testBelongsToManyRejectsNonScalarParentId(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAll')->willReturn([]);

        // Parent-modell där primaryKey-värdet är en array (ogiltigt)
        $parent = new class extends Model {
            protected string $table = 'roles';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'name'];
            private ?Connection $c = null;

            public function setConn(Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->c ?? parent::getConnection();
            }
        };
        $parent->setConn($conn);
        // Primary key blir array, vilket är ogiltigt
        $parent->forceFill(['id' => ['not', 'scalar']]);

        $belongsToMany = new BelongsToMany(
            $conn,
            \Dummy\Models\User::class,
            'role_user',
            'role_id',
            'user_id',
            'id'
        );
        $belongsToMany->setParent($parent);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Parent-id måste vara int eller string, fick: array'
        );

        // Trigga requireParentId via en pivot-operation
        $belongsToMany->attach([1]); // eller attach(1) beroende på din signatur
    }

    public function testHasManyThrowsIfRelatedClassIsNotModel(): void
    {
        $conn = $this->createMock(Connection::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            "Model class '" . stdClass::class . "' must exist and extend " . \Radix\Database\ORM\Model::class . '.'
        );

        new HasMany(
            $conn,
            stdClass::class, // existerande klass som inte är en Model
            'post_id',
            'id'
        );
    }

    public function testHasManyHydratedModelsAreMarkedAsExisting(): void
    {
        $expectedResults = [
            ['id' => 1, 'post_id' => 10, 'content' => 'First comment'],
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAll')->willReturn($expectedResults);

        $parent = new class extends Model {
            protected string $table = 'posts';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'title'];
            private ?Connection $c = null;

            public function setConn(Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): Connection
            {
                return $this->c ?? parent::getConnection();
            }

            public function comments(): HasMany
            {
                $comment = new class extends Model {
                    protected string $table = 'comments';
                    /** @var array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'content'];
                };
                $rel = new HasMany(
                    $this->getConnection(),
                    get_class($comment),
                    'post_id',
                    'id'
                );
                $rel->setParent($this);
                return $rel;
            }
        };

        $parent->setConn($conn);
        $parent->forceFill(['id' => 10]);

        $comments = $parent->comments()->get();
        $this->assertCount(1, $comments);

        /** @var Model $comment */
        $comment = $comments[0];
        $this->assertTrue($comment->isExisting(), 'HasMany-hydrerad modell ska markeras som existerande.');
    }

    public function testBelongsToManySyncDetachesMissingIdsByDefault(): void
    {
        // Logg för alla execute-anrop
        $executed = [];

        $conn = $this->createMock(Connection::class);
        // getExistingRelatedIds() -> fetchAll()
        $conn->method('fetchAll')
            ->willReturn([
                ['rid' => 1],
                ['rid' => 2],
            ]);

        // Skapa en PDOStatement-mock att returnera från execute()
        $statementMock = $this->createMock(PDOStatement::class);

        // Logga alla SQL-anrop istället för att göra riktig DB
        $conn->method('execute')
            ->willReturnCallback(
                function (string $sql, array $params) use (&$executed, $statementMock): PDOStatement {
                    $executed[] = [$sql, $params];
                    return $statementMock;
                }
            );

        // Parent-modell med id = 10
        $parent = new class ($conn) extends Model {
            protected string $table = 'roles';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'name'];
            private Connection $c;

            public function __construct(Connection $c)
            {
                $this->c = $c;
                parent::__construct([]);
            }

            protected function getConnection(): Connection
            {
                return $this->c;
            }
        };
        $parent->forceFill(['id' => 10]);

        // BelongsToMany-relation mot users via role_user
        $rel = new BelongsToMany(
            $conn,
            \Dummy\Models\User::class,
            'role_user',
            'role_id',
            'user_id',
            'id'
        );
        $rel->setParent($parent);

        // existing: [1,2], target: [2,3] => id 1 ska detach:as när $detaching = true (default)
        $rel->sync([2, 3]);

        // Kontrollera att ett DELETE mot pivot-tabellen har körts
        $deleteCalls = array_filter(
            $executed,
            static function (array $call): bool {
                [$sql, $params] = $call;
                return str_contains($sql, 'DELETE FROM `role_user`')
                    && str_contains($sql, 'IN (?');
            }
        );

        $this->assertNotEmpty(
            $deleteCalls,
            'BelongsToMany::sync ska som standard detacha saknade relaterade ids.'
        );
    }

    public function testBelongsToManyDetachAllWithNullPerformsSingleDeleteWithoutInClause(): void
    {
        $executed = [];

        $conn = $this->createMock(Connection::class);
        $statementMock = $this->createMock(PDOStatement::class);

        $conn->method('execute')
            ->willReturnCallback(
                function (string $sql, array $params) use (&$executed, $statementMock): PDOStatement {
                    $executed[] = [$sql, $params];
                    return $statementMock;
                }
            );

        $parent = new class ($conn) extends Model {
            protected string $table = 'roles';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'name'];
            private Connection $c;

            public function __construct(Connection $c)
            {
                $this->c = $c;
                parent::__construct([]);
            }

            protected function getConnection(): Connection
            {
                return $this->c;
            }
        };
        $parent->forceFill(['id' => 10]);

        $rel = new BelongsToMany(
            $conn,
            \Dummy\Models\User::class,
            'role_user',
            'role_id',
            'user_id',
            'id'
        );
        $rel->setParent($parent);

        // ids = null => ta bort alla kopplingar för parent
        $rel->detach();

        // Endast ett DELETE, utan IN (...)
        $this->assertCount(1, $executed, 'detach(null) ska bara utföra ett delete-statement.');

        [$sql, $params] = $executed[0];

        $this->assertStringContainsString('DELETE FROM `role_user`', $sql);
        $this->assertStringNotContainsString('IN (', $sql, 'detach(null) ska inte ha IN-klausul.');
        $this->assertSame([10], $params);
    }

    public function testBelongsToManyDetachSingleIdDeletesThatId(): void
    {
        $executed = [];

        $conn = $this->createMock(Connection::class);
        $statementMock = $this->createMock(PDOStatement::class);

        $conn->method('execute')
            ->willReturnCallback(
                function (string $sql, array $params) use (&$executed, $statementMock): PDOStatement {
                    $executed[] = [$sql, $params];
                    return $statementMock;
                }
            );

        $parent = new class ($conn) extends Model {
            protected string $table = 'roles';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'name'];
            private Connection $c;

            public function __construct(Connection $c)
            {
                $this->c = $c;
                parent::__construct([]);
            }

            protected function getConnection(): Connection
            {
                return $this->c;
            }
        };
        $parent->forceFill(['id' => 10]);

        $rel = new BelongsToMany(
            $conn,
            \Dummy\Models\User::class,
            'role_user',
            'role_id',
            'user_id',
            'id'
        );
        $rel->setParent($parent);

        // Ta bort EN användare (id 5) från pivot-tabellen
        $rel->detach(5);

        $this->assertCount(1, $executed, 'detach(5) ska leda till exakt ett delete-statement.');

        [$sql, $params] = $executed[0];

        $this->assertStringContainsString('DELETE FROM `role_user`', $sql);
        $this->assertStringContainsString('IN (?)', $sql);
        $this->assertSame([10, 5], $params, 'Första param är parent-id, andra är det relaterade id:t.');
    }

    public function testBelongsToManyGetUsesBuilderAndReturnsAllModels(): void
    {
        // Connection-mock: fallback-vägen i get() får INTE anropas
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->never())
            ->method('fetchAll');

        // Skapa relation (parent behövs inte i builder-vägen)
        $rel = new BelongsToMany(
            $conn,
            \Dummy\Models\User::class,
            'role_user',
            'role_id',
            'user_id',
            'id'
        );

        // Två modellinstanser att returnera från buildern
        $user1 = $this->createMock(Model::class);
        $user2 = $this->createMock(Model::class);

        $collection = new \Radix\Collection\Collection([$user1, $user2]);

        // Stubba en QueryBuilder som bara returnerar vår collection
        $builderStub = new class extends \Radix\Database\QueryBuilder\QueryBuilder {
            private \Radix\Collection\Collection $collection;

            public function setCollection(\Radix\Collection\Collection $c): void
            {
                $this->collection = $c;
            }

            public function get(): \Radix\Collection\Collection
            {
                return $this->collection;
            }
        };
        $builderStub->setCollection($collection);

        // Sätt den privata $builder-egenskapen via reflection
        $ref = new ReflectionClass(BelongsToMany::class);
        $prop = $ref->getProperty('builder');
        $prop->setAccessible(true);
        $prop->setValue($rel, $builderStub);

        $result = $rel->get();

        // Borde vara exakt de två modellerna vi stoppade in i collection
        $this->assertCount(2, $result);
        $this->assertSame($user1, $result[0]);
        $this->assertSame($user2, $result[1]);
    }

    public function testBelongsToReturnsRelatedParentRecord(): void
    {
        $expectedResult = ['id' => 5, 'first_name' => 'Mats'];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')
            ->willReturn($expectedResult);

        $parentModel = $this->createMock(\Dummy\Models\Status::class);
        $parentModel->method('getAttribute')->willReturnMap([['user_id', 5]]);

        $belongsTo = new BelongsTo(
            $connection,
            \Dummy\Models\User::class, // eller 'users' om din resolveModelClass hanterar tabell
            'user_id',               // foreignKey kolumn på child
            'id',                    // ownerKey kolumn på parent (users.id)
            $parentModel
        );

        $result = $belongsTo->get();

        $this->assertInstanceOf(\Dummy\Models\User::class, $result);
        $this->assertEquals($expectedResult['id'], $result->getAttribute('id'));
        $this->assertEquals($expectedResult['first_name'], $result->getAttribute('first_name'));
        $this->assertTrue(
            $result->isExisting(),
            'BelongsTo-hydrerad modell ska markeras som existerande.'
        );
    }

    public function testBelongsToPassesForeignKeyValueToConnection(): void
    {
        $connection = $this->createMock(\Radix\Database\Connection::class);

        // Förvänta exakt ett anrop med rätt bind-parametrar
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('FROM `users`'),
                $this->equalTo([5])
            )
            ->willReturn(['id' => 5, 'first_name' => 'Mats']);

        // Child-modell som har foreign key user_id = 5
        $child = $this->createMock(\Radix\Database\ORM\Model::class);
        $child->method('getAttribute')->willReturnMap([
            ['user_id', 5],
        ]);

        $rel = new \Radix\Database\ORM\Relationships\BelongsTo(
            $connection,
            \Dummy\Models\User::class, // <-- FQCN istället för 'users'
            'user_id', // foreignKey på child
            'id',      // ownerKey på parent
            $child
        );

        $result = $rel->first();

        $this->assertInstanceOf(\Dummy\Models\User::class, $result);
        $this->assertSame(5, $result->getAttribute('id'));
    }

    public function testBelongsToWithDefaultDoesNotOverrideExistingRecord(): void
    {
        $connection = $this->createMock(\Radix\Database\Connection::class);
        $connection->method('fetchOne')
            ->willReturn([
                'id' => 10,
                'first_name' => 'RealUser',
            ]);

        // Child-modell med foreignKey user_id = 10
        $child = $this->createMock(\Radix\Database\ORM\Model::class);
        $child->method('getAttribute')->willReturnMap([
            ['user_id', 10],
        ]);

        $rel = (new \Radix\Database\ORM\Relationships\BelongsTo(
            $connection,
            \Dummy\Models\User::class, // <-- FQCN istället för 'users'
            'user_id',
            'id',
            $child
        ))->withDefault(['first_name' => 'DefaultName']);

        $result = $rel->first();

        // Ska vara den riktiga användaren, inte default
        $this->assertInstanceOf(\Dummy\Models\User::class, $result);
        $this->assertSame(10, $result->getAttribute('id'));
        $this->assertSame('RealUser', $result->getAttribute('first_name'));
        $this->assertTrue($result->isExisting(), 'BelongsTo ska markera hittad modell som existerande.');
    }

    public function testBelongsToResolvesModelClassFromTableName(): void
    {
        $connection = $this->createMock(\Radix\Database\Connection::class);
        $connection->method('fetchOne')
            ->willReturn(['id' => 1, 'first_name' => 'Resolved']);

        // Child med foreign key user_id = 1
        $child = $this->createMock(\Radix\Database\ORM\Model::class);
        $child->method('getAttribute')->willReturnMap([
            ['user_id', 1],
        ]);

        $resolver = new \Radix\Database\ORM\ConventionModelClassResolver('Dummy\\Models\\');

        // Skicka in tabellnamn 'users' + resolver så BelongsTo kan mappa till Dummy\Models\User
        $rel = new \Radix\Database\ORM\Relationships\BelongsTo(
            $connection,
            'users',
            'user_id',
            'id',
            $child,
            $resolver // <-- viktigt
        );

        $result = $rel->first();

        $this->assertInstanceOf(\Dummy\Models\User::class, $result);
        $this->assertSame(1, $result->getAttribute('id'));
        $this->assertSame('Resolved', $result->getAttribute('first_name'));
    }

    public function testModelLoadMissingActuallyLoadsWhenRelationIsMissing(): void
    {
        $rows = [
            ['id' => 1, 'post_id' => 10, 'status' => 'published'],
        ];

        $conn = $this->createMock(\Radix\Database\Connection::class);
        $conn->method('fetchAll')->willReturn($rows);

        $post = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'posts';
            /** array<int, string> */
            protected array $fillable = ['id', 'title'];
            private ?\Radix\Database\Connection $c = null;

            public function setConn(\Radix\Database\Connection $c): void
            {
                $this->c = $c;
            }

            protected function getConnection(): \Radix\Database\Connection
            {
                return $this->c ?? parent::getConnection();
            }

            public function comments(): \Radix\Database\ORM\Relationships\HasMany
            {
                $comment = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'comments';
                    /** array<int, string> */
                    protected array $fillable = ['id', 'post_id', 'status'];
                };
                $rel = new \Radix\Database\ORM\Relationships\HasMany(
                    $this->getConnection(),
                    get_class($comment),
                    'post_id',
                    'id'
                );
                $rel->setParent($this);
                return $rel;
            }
        };

        $post->setConn($conn);
        $post->forceFill(['id' => 10]);

        // comments är ännu inte laddad
        $this->assertNull($post->getRelation('comments'));

        // loadMissing med sträng ska ladda relationen
        $post->loadMissing('comments');

        // Casta till array (relationen är normalt en array av modeller)
        $loaded = (array) $post->getRelation('comments');

        /** @var array<int,\Radix\Database\ORM\Model> $loaded */
        $loaded = $loaded;

        $this->assertCount(1, $loaded);

        foreach ($loaded as $model) {
            $this->assertInstanceOf(\Radix\Database\ORM\Model::class, $model);
            $this->assertSame('published', $model->getAttribute('status'));
            break; // vi behöver bara första posten
        }
    }

    public function testWithCountConvertsCamelCaseRelationNameToLowerSnakeCaseAlias(): void
    {
        $connection = $this->createMock(\Radix\Database\Connection::class);

        $model = new class extends \Radix\Database\ORM\Model {
            protected string $table = 'parents';
            /** @var array<int,string> */
            protected array $fillable = ['id'];

            public function userRoles(): \Radix\Database\ORM\Relationships\HasMany
            {
                $child = new class extends \Radix\Database\ORM\Model {
                    protected string $table = 'user_roles';
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

        $qb = (new \Radix\Database\QueryBuilder\QueryBuilder())
            ->setConnection($connection)
            ->setModelClass(get_class($model))
            ->from('parents')
            ->withCount('userRoles');

        $sql = $qb->toSql();

        $this->assertStringContainsString(
            'AS `user_roles_count`',
            $sql,
            'withCount() ska normalisera camelCase-relationer till lowercase snake_case i alias.'
        );

        $this->assertStringNotContainsString(
            'AS `user_Roles_count`',
            $sql,
            'Alias får inte behålla versaler efter snake_case-konvertering.'
        );
    }

    public function testLoadDoesNotCallGetOnClassStringRelation(): void
    {
        // Hjälparklass med get()-metod som INTE ska köras
        $helper = new class {
            /**
             * @return array<int,\Radix\Database\ORM\Model>
             */
            public function get(): array
            {
                throw new RuntimeException('get() should not be called on class-string relation');
            }
        };
        $helperClass = get_class($helper);

        $model = new class ($helperClass) extends Model {
            protected string $table = 'items';
            /** @var array<int, string> */
            protected array $fillable = ['id'];

            /** @var class-string */
            private string $cls;

            /**
             * @param  class-string  $cls
             */
            public function __construct(string $cls)
            {
                $this->cls = $cls;
                parent::__construct([]);
            }

            /**
             * Relationsmetod som returnerar en KLASS-STRÄNG, inte ett objekt.
             *
             * @return class-string
             */
            public function strange(): string
            {
                return $this->cls;
            }
        };

        $model->forceFill(['id' => 1]);

        // Originalkoden ska klara detta utan att anropa get(),
        // och därmed heller inte kasta RuntimeException.
        $model->load('strange');

        // Relation ska sättas till null (eftersom vi inte kan hämta data ur en class-string)
        $this->assertNull(
            $model->getRelation('strange'),
            'Relationen "strange" ska bli null när relationsmetoden returnerar en klass-sträng utan att get() anropas.'
        );
    }
}
