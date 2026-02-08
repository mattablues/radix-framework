<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\Connection;
use Radix\Database\ORM\ConventionModelClassResolver;
use Radix\Database\ORM\Model;
use Radix\Database\ORM\Relationships\HasMany;
use ReflectionMethod;

final class ModelHasManyMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Test-bootstrap: behövs för Model::hasMany() (använder Model::modelClassResolver()).
        Model::setModelClassResolver(new ConventionModelClassResolver('Dummy\\Models\\'));
    }

    public function testHasManyIsPublic(): void
    {
        $ref = new ReflectionMethod(Model::class, 'hasMany');
        self::assertTrue($ref->isPublic(), 'Model::hasMany() måste vara public (publikt API för relationer).');
    }

    public function testHasManyRespectsProvidedLocalKeyAndSetsParentSoBindingUsesParentValue(): void
    {
        $conn = $this->createMock(Connection::class);

        $uuid = 'abc-123';

        // Vi vill se att HasMany::get() binder parentens uuid-värde (inte strängen "uuid").
        $conn->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(function (string $sql): bool {
                    self::assertStringContainsString('WHERE', $sql);
                    return true;
                }),
                self::identicalTo([$uuid])
            )
            ->willReturn([]);

        $parent = new class ($conn) extends Model {
            protected string $table = 'posts';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'uuid'];

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

        // Sätt localKey-värde som HasMany ska läsa från parent
        $parent->forceFill(['uuid' => $uuid]);

        // relaterad modellklass (måste vara en Model-subklass pga HasMany::resolveModelClass-check)
        $related = new class extends Model {
            protected string $table = 'comments';
            /** @var array<int, string> */
            protected array $fillable = ['id', 'post_uuid'];
        };

        // Act: localKey='uuid' ska respekteras (dödar AssignCoalesce),
        // och setParent() måste köras (dödar MethodCallRemoval).
        $rel = $parent->hasMany(get_class($related), 'post_uuid', 'uuid');

        self::assertInstanceOf(HasMany::class, $rel);

        $rel->get();
    }
}
