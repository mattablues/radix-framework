<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\HasMany;

final class ModelToArrayTest extends TestCase
{
    public function testToArrayConvertsArrayRelationsOfModels(): void
    {
        $parent = new class extends Model {
            protected string $table = 'parents';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'name'];
        };
        $child1 = new class extends Model {
            protected string $table = 'children';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'value'];
        };
        $child2 = new class extends Model {
            protected string $table = 'children';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'value'];
        };

        $parent->forceFill(['id' => 1, 'name' => 'Parent']);
        $child1->forceFill(['id' => 10, 'value' => 'A']);
        $child2->forceFill(['id' => 11, 'value' => 'B']);

        // relations['children'] = array<Model>
        $parent->setRelation('children', [$child1, $child2]);

        $arr = $parent->toArray();

        $this->assertArrayHasKey('children', $arr);
        $this->assertIsArray($arr['children']);
        $this->assertCount(2, $arr['children']);

        // Varje element ska vara en array (toArray() på respektive child)
        $this->assertSame(['id' => 10, 'value' => 'A'], $arr['children'][0]);
        $this->assertSame(['id' => 11, 'value' => 'B'], $arr['children'][1]);
    }

    public function testToArrayIncludesAutoloadRelations(): void
    {
        $rows = [
            ['id' => 1, 'post_id' => 10, 'status' => 'published'],
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAll')->willReturn($rows);

        $post = new class extends Model {
            protected string $table = 'posts';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'title'];
            /** @var array<int, string> */
            protected array $autoloadRelations = ['comments'];
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
                    protected array $fillable = ['id', 'post_id', 'status'];
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

        $post->setConn($conn);
        $post->forceFill(['id' => 10, 'title' => 'Hello']);

        $arr = $post->toArray();

        // autoloadRelations ska ha sett till att 'comments' finns i arrayen
        $this->assertArrayHasKey('comments', $arr);
        $this->assertIsArray($arr['comments']);
        $this->assertCount(1, $arr['comments']);

        /** @var array<int, array<string, mixed>> $comments */
        $comments = $arr['comments'];

        $this->assertSame('published', $comments[0]['status']);
    }

    public function testToArrayFlattersSingleModelRelationWhitoutCycel(): void
    {
        $parent = new class extends Model {
            protected string $table = 'parents';
            protected array $fillable = ['id'];
        };
        $child = new class extends Model {
            protected string $table = 'children';
            protected array $fillable = ['id', 'parent_id'];
        };

        $parent->forceFill(['id' => 1]);
        $child->forceFill(['id' => 10, 'parent_id' => 1]);

        // simulera back-reference
        $parent->setRelation('child', $child);
        $child->setRelation('parent', $parent);

        $arr = $parent->toArray();

        // Viktigt: ingen recursion, och 'child' är bara attribut (inga egna relationer)
        $this->assertArrayHasKey('child', $arr);
        $this->assertSame(
            [
                'id'        => 1,
                'child'     => [
                    'id'        => 10,
                    'parent_id' => 1,
                ],
            ],
            $arr
        );

        // Och jsonSerialize ska också innehålla child, men utan cykler
        $json = $parent->jsonSerialize();
        $this->assertArrayHasKey('child', $json);
        $this->assertIsArray($json['child']);
        $this->assertArrayNotHasKey(
            'parent',
            $json['child'],
            'Child-relationen ska inte innehålla en parent-nyckel som skapar cykel.'
        );
    }

    public function testToArrayDoesNotReloadAutoloadRelationWhenAlreadyPresent(): void
    {
        $model = new class extends Model {
            protected string $table = 'items';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
            /** @var array<int, string> */
            protected array $autoloadRelations = ['foo'];

            public int $fooCalls = 0;

            public function foo(): string
            {
                $this->fooCalls++;
                return 'from_foo_method';
            }
        };

        // Attribut
        $model->forceFill(['id' => 1]);

        // Sätt en relation manuellt innan toArray()
        $model->setRelation('foo', 'preloaded_value');

        $arr = $model->toArray();

        // Autoload ska INTE ha laddat om 'foo' när den redan finns
        $this->assertSame('preloaded_value', $arr['foo']);
        $this->assertSame(
            0,
            $model->fooCalls,
            'autoloadRelations ska inte anropa relation-metoden när relationen redan finns i arrayen.'
        );
    }

    public function testAutoloadRelationsDoesNotCallGetOnClassString(): void
    {
        // Hjälparklass med get()-metod, men vi vill aldrig instansiera den här.
        $helperClass = new class {
            /**
             * @return array<int, string>
             */
            public function get(): array
            {
                return ['should_not_be_called'];
            }
        };

        $helperClassName = get_class($helperClass);

        $model = new class ($helperClassName) extends Model {
            protected string $table = 'items';
            /** @var array<int, string> */
            protected array $fillable = ['id'];
            /** @var array<int, string> */
            protected array $autoloadRelations = ['helper'];
            /** @var class-string */
            private string $cls;

            /**
             * @param class-string $cls
             */
            public function __construct(string $cls)
            {
                $this->cls = $cls;
                parent::__construct([]);
            }

            /**
             * Autoload-relation som RETURNERAR ett klassnamn (string),
             * inte ett objekt.
             *
             * @return class-string
             */
            public function helper(): string
            {
                return $this->cls;
            }
        };

        $model->forceFill(['id' => 1]);

        // Om mutanten 109 är aktiv kommer toArray() försöka anropa get() på en sträng
        // och kasta ett fel. Originalkoden ska INTE kasta.
        $arr = $model->toArray();

        // helper-relationen ska inte ha laddats till något vettigt (vi förväntar oss att den ignoreras)
        // Det viktiga är att vi inte får något undantag här.
        $this->assertArrayHasKey('id', $arr);
    }
}
