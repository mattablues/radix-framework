<?php

declare(strict_types=1);

namespace Radix\Tests\Database\ORM;

use PHPUnit\Framework\TestCase;
use Radix\Database\ORM\Relationships\HasOneThrough;
use ReflectionClass;

final class RelationVisibilityTest extends TestCase
{
    public function testHasOneThroughKeysAreNotPublic(): void
    {
        $ref = new ReflectionClass(HasOneThrough::class);

        foreach (['firstKey', 'secondKey', 'secondLocal'] as $prop) {
            $p = $ref->getProperty($prop);
            $this->assertFalse(
                $p->isPublic(),
                "Property $prop ska inte vara public, annars blir setAccessible(true) i praktiken redundant."
            );
        }
    }
}
