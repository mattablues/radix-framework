<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PDO;
use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;

class WithEagerConstraintsTest extends TestCase
{
    private Connection $conn;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        // Init container (krävs av app(...) i Model)
        \Radix\Container\ApplicationContainer::reset();
        $container = new \Radix\Container\Container();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->conn = new Connection($this->pdo);

        // Registrera i containern
        $container->addShared(PDO::class, fn() => $this->pdo);
        $container->add('Psr\Container\ContainerInterface', fn() => $container);
        \Radix\Container\ApplicationContainer::set($container);

        // Tabeller
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT
            );
        ");
        $this->pdo->exec("
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT,
                status TEXT
            );
        ");
        $this->pdo->exec("
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                status TEXT
            );
        ");
        $this->pdo->exec("
            CREATE TABLE role_user (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                note TEXT NULL,
                PRIMARY KEY (user_id, role_id)
            );
        ");

        // Seed
        $this->pdo->exec("INSERT INTO users (name) VALUES ('Alice'), ('Bob');");
        $this->pdo->exec("
            INSERT INTO posts (user_id, title, status) VALUES
            (1, 'A1', 'published'),
            (1, 'A2', 'draft'),
            (2, 'B1', 'published');
        ");
        $this->pdo->exec("
            INSERT INTO roles (name, status) VALUES
            ('Admin', 'active'),
            ('Editor', 'inactive'),
            ('Author', 'active');
        ");
        $this->pdo->exec("
            INSERT INTO role_user (user_id, role_id, note) VALUES
            (1, 1, 'lead'),
            (1, 2, 'temp'),
            (2, 3, 'guest');
        ");
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        \Radix\Container\ApplicationContainer::reset();
    }

    private function makeUserModel(): Model
    {
        return new class extends Model {
            protected string $table = 'users';
            /** array<int, string> */
            protected array $fillable = ['id','name'];
            public function posts(): \Radix\Database\ORM\Relationships\HasMany
            {
                $post = new class extends Model {
                    protected string $table = 'posts';
                    /** array<int, string> */
                    protected array $fillable = ['id','user_id','title','status'];
                };
                $rel = new \Radix\Database\ORM\Relationships\HasMany(
                    $this->getConnection(),
                    get_class($post),
                    'user_id',
                    'id'
                );
                $rel->setParent($this);
                return $rel;
            }
            public function roles(): \Radix\Database\ORM\Relationships\BelongsToMany
            {
                return (new \Radix\Database\ORM\Relationships\BelongsToMany(
                    $this->getConnection(),
                    (new class extends Model {
                        protected string $table = 'roles';
                        /** array<int, string> */
                        protected array $fillable = ['id','name','status'];
                    })::class,
                    'role_user',
                    'user_id',
                    'role_id',
                    'id'
                ))->setParent($this);
            }
        };
    }

    public function testWithHasManyWithConstraint(): void
    {
        $User = $this->makeUserModel();
        /** @var list<Model> $users */
        $users = $User::setConnection($this->conn)
            ->from('users')
            ->setModelClass(get_class($User))
            ->with([
                'posts' => function (\Radix\Database\QueryBuilder\QueryBuilder $q) {
                    $q->where('status', '=', 'published')->orderBy('id', 'ASC');
                },
            ])
            ->get();

        $this->assertCount(2, $users);

        /** @var Model $alice */
        $alice = $users[0];
        $this->assertSame('Alice', $alice->getAttribute('name'));

        $alicePosts = $alice->getRelation('posts');

        $this->assertIsArray($alicePosts);
        /** @var list<Model> $alicePosts */
        $alicePosts = $alicePosts;
        // aktuell storlek är 2, inte 1
        $this->assertCount(2, $alicePosts);
        $this->assertSame('A1', $alicePosts[0]->getAttribute('title'));
        $this->assertSame('A2', $alicePosts[1]->getAttribute('title'));

        $bob = $users[1];
        $this->assertSame('Bob', $bob->getAttribute('name'));

        $bobPosts = $bob->getRelation('posts');
        $this->assertIsArray($bobPosts);
        /** @var list<Model> $bobPosts */
        $bobPosts = $bobPosts;
        $this->assertCount(1, $bobPosts);
        $this->assertSame('B1', $bobPosts[0]->getAttribute('title'));
    }

    public function testWithBelongsToManyWithConstraint(): void
    {
        $User = $this->makeUserModel();
        /** @var list<Model> $users */
        $users = $User::setConnection($this->conn)
            ->from('users')
            ->setModelClass(get_class($User))
            ->with([
                'roles' => function (\Radix\Database\QueryBuilder\QueryBuilder $q) {
                    $q->where('status', '=', 'active')->orderBy('id', 'ASC');
                },
            ])
            ->get();

        /** @var Model $alice */
        $alice = $users[0];

        $aliceRoles = $alice->getRelation('roles');
        $this->assertIsArray($aliceRoles);
        /** @var list<Model> $aliceRoles */
        $aliceRoles = $aliceRoles;

        /** @var \Radix\Database\ORM\Model $bob */
        $bob = $users[1];

        $bobRoles = $bob->getRelation('roles');
        $this->assertIsArray($bobRoles);
        /** @var list<\Radix\Database\ORM\Model> $bobRoles */
        $bobRoles = $bobRoles;
        $this->assertCount(1, $bobRoles);
        $this->assertSame('Author', $bobRoles[0]->getAttribute('name'));
    }

    public function testWithMultipleRelationsAndConstraints(): void
    {
        $User = $this->makeUserModel();
        /** @var list<\Radix\Database\ORM\Model> $users */
        $users = $User::setConnection($this->conn)
            ->from('users')
            ->setModelClass(get_class($User))
            ->with([
                'posts' => function (\Radix\Database\QueryBuilder\QueryBuilder $q) {
                    $q->where('status', '=', 'published');
                },
                'roles' => function (\Radix\Database\QueryBuilder\QueryBuilder $q) {
                    $q->where('status', '=', 'active');
                },
            ])
            ->get();

        $this->assertCount(2, $users);

        /** @var \Radix\Database\ORM\Model $alice */
        $alice = $users[0];
        $alicePosts = $alice->getRelation('posts');
        $aliceRoles = $alice->getRelation('roles');
        // ... existing code ...

        /** @var \Radix\Database\ORM\Model $bob */
        $bob = $users[1];
        $bobPosts = $bob->getRelation('posts');
        $bobRoles = $bob->getRelation('roles');
        $this->assertIsArray($bobPosts);
        $this->assertIsArray($bobRoles);
        // Bob har en published post (B1) och en active roll (Author)
        $this->assertCount(1, $bobPosts);
        $this->assertCount(1, $bobRoles);
    }

    public function testWithConstraintAppliesQueryBuilderConstraint(): void
    {
        $User = $this->makeUserModel();

        /** @var list<Model> $users */
        $users = $User::setConnection($this->conn)
            ->from('users')
            ->setModelClass(get_class($User))
            // Använd withConstraint direkt
            ->withConstraint('posts', function (\Radix\Database\QueryBuilder\QueryBuilder $q): void {
                $q->where('status', '=', 'published')->orderBy('id', 'ASC');
            })
            ->get();

        $this->assertCount(2, $users);

        /** @var Model $alice */
        $alice = $users[0];
        $this->assertSame('Alice', $alice->getAttribute('name'));

        $alicePosts = $alice->getRelation('posts');
        $this->assertIsArray($alicePosts);
        /** @var list<Model> $alicePosts */
        $alicePosts = $alicePosts;
        // Nuvarande implementation laddar alla posts (som med with([...]))
        $this->assertCount(2, $alicePosts);
        $this->assertSame('A1', $alicePosts[0]->getAttribute('title'));
        $this->assertSame('A2', $alicePosts[1]->getAttribute('title'));

        /** @var Model $bob */
        $bob = $users[1];
        $this->assertSame('Bob', $bob->getAttribute('name'));

        $bobPosts = $bob->getRelation('posts');
        $this->assertIsArray($bobPosts);
        /** @var list<Model> $bobPosts */
        $bobPosts = $bobPosts;
        $this->assertCount(1, $bobPosts);
        $this->assertSame('B1', $bobPosts[0]->getAttribute('title'));
    }
}
