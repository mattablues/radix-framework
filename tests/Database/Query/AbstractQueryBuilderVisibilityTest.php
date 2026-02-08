<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query;

use PHPUnit\Framework\TestCase;
use Radix\Database\QueryBuilder\AbstractQueryBuilder;
use ReflectionMethod;

final class AbstractQueryBuilderVisibilityTest extends TestCase
{
    public function testAbstractQueryBuilderGetIsPublic(): void
    {
        $ref = new ReflectionMethod(AbstractQueryBuilder::class, 'get');

        $this->assertTrue(
            $ref->isPublic(),
            'AbstractQueryBuilder::get() måste vara public (annars kan kod som typ-hintar AbstractQueryBuilder inte anropa get()).'
        );
    }
}
