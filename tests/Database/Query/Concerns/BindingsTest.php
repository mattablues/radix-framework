<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query\Concerns;

use PHPUnit\Framework\TestCase;
use Radix\Database\QueryBuilder\Concerns\Bindings;

class BaseBindingsUser
{
    use Bindings;

    /** @var array<int, mixed> */
    public array $bindings = [];

    /** @var array<int, mixed> */
    public array $bindingsSelect = [];

    /** @var array<int, mixed> */
    public array $bindingsWhere = [];

    /** @var array<int, mixed> */
    public array $bindingsJoin = [];

    /** @var array<int, mixed> */
    public array $bindingsHaving = [];

    /** @var array<int, mixed> */
    public array $bindingsOrder = [];

    /** @var array<int, mixed> */
    public array $bindingsUnion = [];

    /** @var array<int, mixed> */
    public array $bindingsMutation = [];

    /**
     * Stubbar för att uppfylla Bindings‑traitens beroenden i testerna.
     * De behöver inte göra något, eftersom vi inte testar compileAllBindings här.
     */

    /** @return array<int, mixed> */
    protected function compileCteBindings(): array
    {
        return [];
    }

    protected function compileAllBindings(): void
    {
        // noop i testklass – riktiga implementationen finns på QueryBuilder
    }
}

/**
 * Viktigt: Denna klass ärver från BaseBindingsUser och anropar trait-metoderna.
 * Om Infection ändrar metoderna i traiten till private så kan
 * denna underklass INTE längre anropa dem → mutanten dör.
 */
class ExtendedBindingsUser extends BaseBindingsUser
{
    public function addSelectSingle(mixed $value): void
    {
        $this->addSelectBinding($value);
    }

    public function addWhereSingle(mixed $value): void
    {
        $this->addWhereBinding($value);
    }

    /**
     * @param array<int, mixed> $values
     */
    public function addWhereList(array $values): void
    {
        $this->addWhereBindings($values);
    }

    /**
     * @param array<int, mixed> $values
     */
    public function addJoinList(array $values): void
    {
        $this->addJoinBindings($values);
    }

    public function addHavingSingle(mixed $value): void
    {
        $this->addHavingBinding($value);
    }

    /**
     * @param array<int, mixed> $values
     */
    public function addHavingList(array $values): void
    {
        $this->addHavingBindings($values);
    }

    public function addOrderSingle(mixed $value): void
    {
        $this->addOrderBinding($value);
    }

    /**
     * @param array<int, mixed> $values
     */
    public function addUnionList(array $values): void
    {
        $this->addUnionBindings($values);
    }

    /**
     * @param array<int, mixed> $values
     */
    public function addMutationList(array $values): void
    {
        $this->addMutationBindings($values);
    }
}

final class BindingsTest extends TestCase
{
    public function testMergeBindingsMergesSelectBucketFromOtherQuery(): void
    {
        $first  = new ExtendedBindingsUser();
        $second = new ExtendedBindingsUser();

        $first->bindingsSelect  = ['col1'];
        $second->bindingsSelect = ['col2'];

        $first->mergeBindings($second);

        self::assertSame(
            ['col1', 'col2'],
            $first->bindingsSelect
        );
    }

    public function testMergeBindingsMergesMutationBucketFromOtherQuery(): void
    {
        $first  = new ExtendedBindingsUser();
        $second = new ExtendedBindingsUser();

        $first->bindingsMutation  = ['a'];
        $second->bindingsMutation = ['b'];

        $first->mergeBindings($second);

        self::assertSame(['a', 'b'], $first->bindingsMutation);
    }

    public function testMergeBindingsMergesOrderBucketFromOtherQuery(): void
    {
        $first  = new ExtendedBindingsUser();
        $second = new ExtendedBindingsUser();

        $first->bindingsOrder  = ['name ASC'];
        $second->bindingsOrder = ['created_at DESC'];

        $first->mergeBindings($second);

        self::assertSame(
            ['name ASC', 'created_at DESC'],
            $first->bindingsOrder
        );
    }

    public function testMergeBindingsMergesUnionBucketFromOtherQuery(): void
    {
        $first  = new ExtendedBindingsUser();
        $second = new ExtendedBindingsUser();

        $first->bindingsUnion  = ['u1'];
        $second->bindingsUnion = ['u2'];

        $first->mergeBindings($second);

        self::assertSame(
            ['u1', 'u2'],
            $first->bindingsUnion
        );
    }

    public function testMergeBindingsMergesJoinBucketFromOtherQuery(): void
    {
        $first  = new ExtendedBindingsUser();
        $second = new ExtendedBindingsUser();

        $first->bindingsJoin  = ['join1'];
        $second->bindingsJoin = ['join2'];

        $first->mergeBindings($second);

        self::assertSame(
            ['join1', 'join2'],
            $first->bindingsJoin
        );
    }

    public function testMergeBindingsMergesHavingBucketFromOtherQuery(): void
    {
        $first  = new ExtendedBindingsUser();
        $second = new ExtendedBindingsUser();

        $first->bindingsHaving  = ['h1'];
        $second->bindingsHaving = ['h2'];

        $first->mergeBindings($second);

        self::assertSame(
            ['h1', 'h2'],
            $first->bindingsHaving
        );
    }

    public function testAddSelectBindingIsAccessibleFromSubclassAndFillBucket(): void
    {
        $user = new ExtendedBindingsUser();

        $user->addSelectSingle('col1');

        self::assertSame(['col1'], $user->bindingsSelect);
    }

    public function testAddWhereBindingIsAccessibleFromSubclassAndFillBucket(): void
    {
        $user = new ExtendedBindingsUser();

        $user->addWhereSingle(42);

        self::assertSame([42], $user->bindingsWhere);
    }

    public function testAddWhereBindingsAreAccessibleFromSubclassAndFillBucket(): void
    {
        $user = new ExtendedBindingsUser();

        $values = [1, 2, 3];
        $user->addWhereList($values);

        self::assertSame($values, $user->bindingsWhere);
    }

    public function testAddJoinBindingsAreAccessibleFromSubclassAndFillBucket(): void
    {
        $user = new ExtendedBindingsUser();

        $values = ['j1', 'j2'];
        $user->addJoinList($values);

        self::assertSame($values, $user->bindingsJoin);
    }

    public function testAddHavingBindingIsAccessibleFromSubclassAndFillBucket(): void
    {
        $user = new ExtendedBindingsUser();

        $user->addHavingSingle('count > 0');

        self::assertSame(['count > 0'], $user->bindingsHaving);
    }

    public function testAddHavingBindingsAreAccessibleFromSubclassAndFillBucket(): void
    {
        $user = new ExtendedBindingsUser();

        $values = [1, 2, 3];
        $user->addHavingList($values);

        self::assertSame($values, $user->bindingsHaving);
    }

    public function testAddOrderBindingIsAccessibleFromSubclassAndFillBucket(): void
    {
        $user = new ExtendedBindingsUser();

        $user->addOrderSingle('name DESC');

        self::assertSame(['name DESC'], $user->bindingsOrder);
    }

    public function testAddUnionBindingsAreAccessibleFromSubclassAndFillBucket(): void
    {
        $user = new ExtendedBindingsUser();

        $values = ['u1', 'u2'];
        $user->addUnionList($values);

        self::assertSame($values, $user->bindingsUnion);
    }

    public function testAddMutationBindingsAreAccessibleFromSubclassAndFillBucket(): void
    {
        $user = new ExtendedBindingsUser();

        $values = ['v1', 'v2'];
        $user->addMutationList($values);

        self::assertSame($values, $user->bindingsMutation);
    }
}
