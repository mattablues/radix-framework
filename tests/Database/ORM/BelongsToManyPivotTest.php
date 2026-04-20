<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\BelongsToMany;
use ReflectionClass;

class BelongsToManyPivotTest extends TestCase
{
    private Connection $conn;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->conn = new Connection($this->pdo);

        // Skapa tabeller: roles, users, pivot: role_user
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT,
                status TEXT
            );
        ");
        $this->pdo->exec("
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT
            );
        ");
        $this->pdo->exec("
            CREATE TABLE role_user (
                role_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                note TEXT NULL,
                PRIMARY KEY (role_id, user_id)
            );
        ");

        // Seed data
        $this->pdo->exec("INSERT INTO users (first_name, status) VALUES ('John','active'), ('Jane','inactive'), ('Sara','active');");
        $this->pdo->exec("INSERT INTO roles (name) VALUES ('Admin'), ('Editor');");
    }

    private function makeUserModel(): Model
    {
        return new class extends Model {
            protected string $table = 'users';
            /** array<int, string> */
            protected array $fillable = ['id', 'first_name', 'status'];
        };
    }

    private function makeRoleModel(): Model
    {
        return new class extends Model {
            protected string $table = 'roles';
            /** array<int, string> */
            protected array $fillable = ['id', 'name'];
        };
    }

    public function testAttachAndGet(): void
    {
        // Hämta role Admin (id=1)
        $role = $this->makeRoleModel();
        $role->forceFill(['id' => 1])->markAsExisting();

        // BelongsToMany: roles->users
        $rel = new BelongsToMany(
            $this->conn,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',  // parent key i pivot
            'user_id',  // related key i pivot
            'id'        // parent key name på role-modellen
        );
        $rel->setParent($role);

        // attach single
        $rel->attach(1); // John
        // attach multiple
        $rel->attach([2, 3]);

        // Validera att pivot har rader
        $rows = $this->conn->fetchAll("SELECT * FROM role_user WHERE role_id = 1");
        $this->assertCount(3, $rows);

        // get() ska returnera user-modellerna
        $users = $rel->get();
        $this->assertCount(3, $users);
        $this->assertSame('John', $users[0]->getAttribute('first_name'));
    }

    public function testWithPivotNormalizesIndicesAfterUniquing(): void
    {
        $connection = $this->createMock(Connection::class);

        $rel = new BelongsToMany(
            $connection,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',
            'user_id',
            'id'
        );

        // Duplicerade kolumner + tomma som ska filtreras bort
        $rel->withPivot('note', '', 'note', 'extra');

        $ref = new ReflectionClass($rel);
        $prop = $ref->getProperty('pivotColumns');
        $prop->setAccessible(true);

        /** @var array<int, string> $pivotColumns */
        $pivotColumns = $prop->getValue($rel);

        $this->assertSame(
            ['note', 'extra'],
            $pivotColumns,
            'withPivot() ska normalisera index efter unique/filter (array_values).'
        );
        $this->assertSame(
            [0, 1],
            array_keys($pivotColumns),
            'Indexen i pivotColumns ska vara 0..n-1 efter withPivot().'
        );
    }

    public function testGetWithQueryBuilderMapsPivotDataWhenWithPivotUsed(): void
    {
        // Setup: samma DB som i övriga tester (users, roles, role_user)
        $role = $this->makeRoleModel();
        $role->forceFill(['id' => 1])->markAsExisting();

        $rel = (new BelongsToMany(
            $this->conn,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',
            'user_id',
            'id'
        ))->setParent($role)
          ->withPivot('note');

        // Skapa pivot-data med olika notes
        $rel->attach(1, ['note' => 'alpha']);
        $rel->attach(2, ['note' => 'beta']);

        // Bygg QueryBuildern (sätter $this->builder internt)
        $rel->query();

        // Nu ska get() använda QueryBuilder-grenen och mappa pivot_* alias → relation "pivot"
        $users = $rel->get();

        $this->assertCount(2, $users);

        $pivot1 = $users[0]->getRelation('pivot') ?? $users[0]->getAttribute('pivot');
        $pivot2 = $users[1]->getRelation('pivot') ?? $users[1]->getAttribute('pivot');

        $this->assertIsArray($pivot1);
        $this->assertIsArray($pivot2);

        $this->assertSame('alpha', $pivot1['note'] ?? null);
        $this->assertSame('beta', $pivot2['note'] ?? null);
    }

    public function testAttachWithAttributesAndWithPivot(): void
    {
        $role = $this->makeRoleModel();
        $role->forceFill(['id' => 2])->markAsExisting();

        $rel = (new BelongsToMany(
            $this->conn,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',
            'user_id',
            'id'
        ))->setParent($role)
          ->withPivot('note');

        // attach med pivot-attribut
        $rel->attach(1, ['note' => 'primary']);
        $rel->attach([2 => ['note' => 'backup']]);

        $rows = $this->conn->fetchAll("SELECT * FROM role_user WHERE role_id = 2 ORDER BY user_id ASC");
        $this->assertCount(2, $rows);
        $this->assertSame('primary', $rows[0]['note']);
        $this->assertSame('backup', $rows[1]['note']);

        // withPivot ska mappa pivot_note -> pivot relation/attribut
        $users = $rel->get();
        $this->assertCount(2, $users);

        // Förvänta sig att pivot-data finns som relation/attribut "pivot"
        $pivot1 = $users[0]->getRelation('pivot') ?? $users[0]->getAttribute('pivot');
        $pivot2 = $users[1]->getRelation('pivot') ?? $users[1]->getAttribute('pivot');

        $this->assertIsArray($pivot1);
        $this->assertEquals('primary', $pivot1['note']);
        $this->assertIsArray($pivot2);
        $this->assertEquals('backup', $pivot2['note']);
    }

    public function testAttachIgnoreDuplicatesUpdatesAttributes(): void
    {
        $role = $this->makeRoleModel();
        $role->forceFill(['id' => 1])->markAsExisting();

        $rel = (new BelongsToMany(
            $this->conn,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',
            'user_id',
            'id'
        ))->setParent($role);

        // Först insert
        $rel->attach(1, ['note' => 'v1']);
        // Försök igen med annan note (ska uppdatera istället för att duplicera)
        $rel->attach(1, ['note' => 'v2'], true);

        $rows = $this->conn->fetchAll("SELECT * FROM role_user WHERE role_id = 1 AND user_id = 1");
        $this->assertCount(1, $rows);
        $this->assertSame('v2', $rows[0]['note']);
    }

    public function testDetachSpecificAndAll(): void
    {
        $role = $this->makeRoleModel();
        $role->forceFill(['id' => 1])->markAsExisting();

        $rel = (new BelongsToMany(
            $this->conn,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',
            'user_id',
            'id'
        ))->setParent($role);

        $rel->attach([1, 2, 3]);

        // Ta bort specifika
        $rel->detach([1, 3]);
        /** @var array<int, array{role_id:int, user_id:int, note: ?string}> $rows */
        $rows = $this->conn->fetchAll("SELECT * FROM role_user WHERE role_id = 1");
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['user_id']);

        // Ta bort alla kvar
        $rel->detach();
        /** @var array<int, array{role_id:int, user_id:int, note: ?string}> $rows */
        $rows = $this->conn->fetchAll("SELECT * FROM role_user WHERE role_id = 1");
        $this->assertCount(0, $rows);
    }

    public function testSyncWithDetaching(): void
    {
        $role = $this->makeRoleModel();
        $role->forceFill(['id' => 2])->markAsExisting();

        $rel = (new BelongsToMany(
            $this->conn,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',
            'user_id',
            'id'
        ))->setParent($role);

        // init: [1,2]
        $rel->attach([1 => ['note' => 'old1'], 2 => ['note' => 'old2']]);

        // sync till [2,3] och uppdatera attrs
        $rel->sync([
            2 => ['note' => 'new2'],
            3 => ['note' => 'new3'],
        ], true);

        /** @var array<int, array{role_id:int, user_id:int, note: ?string}> $rows */
        $rows = $this->conn->fetchAll("SELECT * FROM role_user WHERE role_id = 2 ORDER BY user_id ASC");
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]['user_id']);
        $this->assertSame('new2', $rows[0]['note']);
        $this->assertSame(3, $rows[1]['user_id']);
        $this->assertSame('new3', $rows[1]['note']);
    }

    public function testSyncWithoutDetaching(): void
    {
        $role = $this->makeRoleModel();
        $role->forceFill(['id' => 1])->markAsExisting();

        $rel = (new BelongsToMany(
            $this->conn,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',
            'user_id',
            'id'
        ))->setParent($role);

        // init: [1]
        $rel->attach(1, ['note' => 'keep']);

        // sync utan detaching: lägg till 2, uppdatera 1
        $rel->sync([
            1 => ['note' => 'updated'],
            2 => ['note' => 'added'],
        ], false);

        /** @var array<int, array{role_id:int, user_id:int, note: ?string}> $rows */
        $rows = $this->conn->fetchAll("SELECT * FROM role_user WHERE role_id = 1 ORDER BY user_id ASC");
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['user_id']);
        $this->assertSame('updated', $rows[0]['note']);
        $this->assertSame(2, $rows[1]['user_id']);
        $this->assertSame('added', $rows[1]['note']);
    }

    public function testFirstReturnsSingleModelOrNull(): void
    {
        $role = $this->makeRoleModel();
        $role->forceFill(['id' => 2])->markAsExisting();

        $rel = (new BelongsToMany(
            $this->conn,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',
            'user_id',
            'id'
        ))->setParent($role);

        // Empty -> null
        $this->assertNull($rel->first());

        // Attach och testa first()
        $rel->attach([1, 2]);
        $first = $rel->first();
        $this->assertNotNull($first);
        $this->assertInstanceOf($this->makeUserModel()::class, $first);
        $this->assertNotNull($first->getAttribute('id'));
    }

    public function testAttachIgnoresDuplicatesByDefault(): void
    {
        // Role Admin (id=1)
        $role = $this->makeRoleModel();
        $role->forceFill(['id' => 1])->markAsExisting();

        $rel = new BelongsToMany(
            $this->conn,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',
            'user_id',
            'id'
        );
        $rel->setParent($role);

        // Första attach
        $rel->attach(1);
        // Försök att attach:a samma user igen utan extra attribut
        $rel->attach(1);

        $rows = $this->conn->fetchAll("SELECT * FROM role_user WHERE role_id = 1");
        $this->assertCount(1, $rows, 'attach() ska som default inte skapa dubletter i pivot-tabellen.');
        $this->assertSame(1, $rows[0]['user_id']);
    }

    public function testAttachSkipsExistingButContinuesWithRemainingIds(): void
    {
        // Role Admin (id=1)
        $role = $this->makeRoleModel();
        $role->forceFill(['id' => 1])->markAsExisting();

        $rel = new BelongsToMany(
            $this->conn,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',
            'user_id',
            'id'
        );
        $rel->setParent($role);

        // Skapa en initial rad för user_id = 1
        $rel->attach(1);

        // Nu finns redan 1, men 2 och 3 ska fortfarande läggas till
        $rel->attach([1, 2, 3]);

        $rows = $this->conn->fetchAll("SELECT user_id FROM role_user WHERE role_id = 1 ORDER BY user_id ASC");
        /** @var array<int, array{user_id:int}> $rows */
        $rows = $rows;

        $this->assertCount(
            3,
            $rows,
            'När en av ids redan finns ska attach() ändå fortsätta med resterande ids.'
        );

        $userIds = array_map(
            static fn(array $r): int => $r['user_id'],
            $rows
        );
        $this->assertSame([1, 2, 3], $userIds);
    }

    public function testGetUsesPrebuiltQueryBuilderBranchWhenBuilderIsAlreadySet(): void
    {
        $role = $this->makeRoleModel();
        $role->forceFill(['id' => 1])->markAsExisting();

        $rel = (new BelongsToMany(
            $this->conn,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',
            'user_id',
            'id'
        ))->setParent($role);

        // Lägg en rad i pivot-tabellen som fallback-SQL-vägen annars skulle hitta.
        $rel->attach(1);

        $builder = $this->createMock(\Radix\Database\QueryBuilder\QueryBuilder::class);

        $builder->expects($this->once())
            ->method('get')
            ->willReturn(new \Radix\Collection\Collection([]));

        $ref = new ReflectionClass($rel);
        $prop = $ref->getProperty('builder');
        $prop->setAccessible(true);
        $prop->setValue($rel, $builder);

        $result = $rel->get();

        $this->assertSame(
            [],
            $result,
            'BelongsToMany::get() ska använda den redan satta QueryBuilder-instansen och returnera dess resultat.'
        );
    }

    public function testBelongsToManyQueryIncludesPivotColumnsWhenUsingWithPivot(): void
    {
        $role = $this->makeRoleModel();
        $role->forceFill(['id' => 1])->markAsExisting();

        $rel = (new BelongsToMany(
            $this->conn,
            get_class($this->makeUserModel()),
            'role_user',
            'role_id',
            'user_id',
            'id'
        ))->setParent($role)
          ->withPivot('note');

        // query() ska bygga en SELECT som inkluderar pivot.`note` AS `pivot_note`
        $qb  = $rel->query();
        $sql = $qb->toSql();

        $this->assertStringContainsString(
            'pivot.`note` AS `pivot_note`',
            $sql,
            'BelongsToMany::query() ska inkludera pivot-kolumner när withPivot() använts.'
        );
    }
}
