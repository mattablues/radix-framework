<?php

declare(strict_types=1);

namespace Radix\Tests\Database\Query\Concerns;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\Database\QueryBuilder\Concerns\CompilesMutations;
use RuntimeException;

/**
 * Bas‑stub som uppfyller CompilesMutations‑traitens beroenden.
 *
 * Vi håller den här fristående från riktiga QueryBuilder för att kunna
 * testa compileMutationSql() mer isolerat (inklusive MethodCallRemoval
 * och ProtectedVisibility‑mutanter).
 */
class BaseCompilesMutationsUser
{
    use CompilesMutations;

    /** @var string */
    public string $type = 'INSERT';

    /** @var array<int|string, mixed> */
    public array $columns = [];

    /** @var string|null */
    public ?string $table = null;

    /** @var array<int, mixed> */
    public array $bindingsWhere = [];

    /** @var array<int, mixed> */
    public array $bindings = [];

    /**
     * Simulera QueryBuilders modelClass‑property så att setModelClass()
     * på traiten har något att skriva till.
     */
    protected ?string $modelClass = null;

    /** Flagga för att verifiera att compileAllBindings() faktiskt anropas. */
    public bool $compileAllBindingsCalled = false;

    /**
     * Hjälpmetoder för att nå trait:ens skyddade properties i testerna.
     */

    /**
     * @param array<int, mixed> $values
     */
    public function setBindingsMutation(array $values): void
    {
        // $bindingsMutation definieras i CompilesMutations‑traiten (protected)
        $this->bindingsMutation = $values;
    }

    /** @return array<int, mixed> */
    public function getBindingsMutation(): array
    {
        return $this->bindingsMutation;
    }

    /**
     * @param array<int, string>|null $unique
     */
    public function setUpsertUniqueForTest(?array $unique): void
    {
        // $upsertUnique definieras i CompilesMutations‑traiten (protected)
        $this->upsertUnique = $unique;
    }

    /**
     * Stubbar för hjälpfunktioner som används i traiten.
     */

    protected function normalizeColumnName(mixed $col): string
    {
        if (is_string($col)) {
            return $col;
        }

        throw new RuntimeException('Ogiltigt kolumnnamn i teststub: ' . get_debug_type($col));
    }

    protected function wrapColumn(string $name): string
    {
        return '`' . $name . '`';
    }

    /**
     * Minimal WHERE‑builder: använder bara bindingsWhere för att trigga grenarna.
     */
    protected function buildWhere(): string
    {
        if ($this->bindingsWhere === []) {
            return '';
        }

        // I riktiga koden byggs riktig WHERE‑SQL; här räcker en dummy.
        return 'WHERE dummy = ?';
    }

    /**
     * Stub för CTE‑bindningar – irrelevant här men krävs av compileAllBindings().
     *
     * @return array<int, mixed>
     */
    protected function compileCteBindings(): array
    {
        return [];
    }

    /**
     * Egen implementation för att kunna hävda att compileMutationSql()
     * MÅSTE anropa den (MethodCallRemoval‑mutanter).
     */
    protected function compileAllBindings(): void
    {
        $this->compileAllBindingsCalled = true;
        // Enkel sammanslagning: mutation först, sedan where – samma princip som QueryBuilder.
        $this->bindings = array_merge(
            $this->bindingsMutation,
            $this->bindingsWhere
        );
    }
}

/**
 * Underklass som exponerar compileMutationSql() via publika wrappers.
 *
 * Viktigt för ProtectedVisibility‑mutanten: om trait‑metoden görs private
 * kan inte den här subklassen anropa den → testet faller och mutanten dör.
 */
class ExtendedCompilesMutationsUser extends BaseCompilesMutationsUser
{
    public function compileInsert(): string
    {
        $this->type = 'INSERT';
        return $this->compileMutationSql();
    }

    public function compileUpdate(): string
    {
        $this->type = 'UPDATE';
        return $this->compileMutationSql();
    }

    public function compileDelete(): string
    {
        $this->type = 'DELETE';
        return $this->compileMutationSql();
    }

    /**
     * Rå wrapper som inte ändrar $type.
     * Används i tester som vill nå UNKNOWN/UPSERT‑grenarna.
     */
    public function compileRaw(): string
    {
        return $this->compileMutationSql();
    }
}

final class CompilesMutationsTest extends TestCase
{
    public function testCompileMutationSqlRemainsCallableFromSubclassForInsert(): void
    {
        $user = new ExtendedCompilesMutationsUser();
        $user->table = '`users`';
        $user->columns = ['name', 'email'];
        $user->setBindingsMutation(['John Doe', 'john@example.com']);

        $sql = $user->compileInsert();

        // ProtectedVisibility‑mutanten: om compileMutationSql() blir private
        // kan ExtendedCompilesMutationsUser inte anropa den och testet kraschar.
        self::assertSame(
            'INSERT INTO `users` (`name`, `email`) VALUES (?, ?)',
            $sql
        );
        self::assertTrue(
            $user->compileAllBindingsCalled,
            'compileInsert() ska anropa compileAllBindings()'
        );
        self::assertSame(
            ['John Doe', 'john@example.com'],
            $user->bindings,
            'Bindings ska komma från bindingsMutation efter compileAllBindings().'
        );
    }

    public function testCompileMutationSqlCallsCompileAllBindingsForUpdate(): void
    {
        $user = new ExtendedCompilesMutationsUser();
        $user->table = '`users`';
        // columns: kolumn => värde (precis som i riktiga update())
        $user->columns = [
            'name'  => 'Jane Doe',
            'email' => 'jane@example.com',
        ];
        $user->setBindingsMutation(array_values($user->columns));
        // Simulera ett WHERE‑villkor
        $user->bindingsWhere = [1];

        $sql = $user->compileUpdate();

        self::assertSame(
            'UPDATE `users` SET `name` = ?, `email` = ? WHERE dummy = ?',
            $sql
        );

        // MethodCallRemoval‑mutant på UPDATE‑grenen
        self::assertTrue(
            $user->compileAllBindingsCalled,
            'compileUpdate() ska anropa compileAllBindings()'
        );
        self::assertSame(
            ['Jane Doe', 'jane@example.com', 1],
            $user->bindings,
            'Bindings ska slå ihop mutation- och where‑bucket i rätt ordning.'
        );
    }

    public function testCompileMutationSqlCallsCompileAllBindingsForDelete(): void
    {
        $user = new ExtendedCompilesMutationsUser();
        $user->table = '`users`';
        $user->columns = []; // DELETE använder inte columns
        $user->setBindingsMutation([]); // inga SET‑värden
        $user->bindingsWhere = [1];     // WHERE dummy = ?

        $sql = $user->compileDelete();

        self::assertSame(
            'DELETE FROM `users` WHERE dummy = ?',
            $sql
        );

        // MethodCallRemoval‑mutant på DELETE‑grenen
        self::assertTrue(
            $user->compileAllBindingsCalled,
            'compileDelete() ska anropa compileAllBindings()'
        );
        self::assertSame(
            [1],
            $user->bindings,
            'Bindings ska innehålla where‑värden efter compileAllBindings().'
        );
    }

    public function testCompileMutationSqlThrowsOnUnsupportedType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Query type 'UNKNOWN' är inte implementerad.");

        $user = new ExtendedCompilesMutationsUser();
        $user->table = '`users`';
        $user->type = 'UNKNOWN';

        // Ska slå i sista throw‑grenen i compileMutationSql()
        // (även denna gren blir då täckt, vilket hjälper Infection).
        $user->compileRaw();
    }

    public function testInsertGuardRejectsEmptyData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Data for INSERT cannot be empty.');

        $user = new ExtendedCompilesMutationsUser();
        $user->insert([]);
    }

    public function testInsertOrIgnoreGuardRejectsEmptyData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Data for INSERT OR IGNORE cannot be empty.');

        $user = new ExtendedCompilesMutationsUser();
        $user->insertOrIgnore([]);
    }

    public function testUpsertGuardRequiresDataAndUniqueBy(): void
    {
        $user = new ExtendedCompilesMutationsUser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Upsert kräver data och uniqueBy.');

        // empty uniqueBy ska trigga guard
        $user->upsert(['email' => 'a@example.com'], []);
    }

    public function testUpsertThrowsWhenUniqueColumnsMissingOnCompile(): void
    {
        $user = new ExtendedCompilesMutationsUser();
        $user->table = '`users`';
        $user->type = 'UPSERT';
        $user->columns = ['email'];
        $user->setBindingsMutation(['a@example.com']);
        // upsertUnique är null → compileMutationSql() ska kasta
        $user->setUpsertUniqueForTest(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Upsert kräver unika kolumner.');

        // type = 'UPSERT' styr att UPSERT‑grenen tas i compileMutationSql()
        $user->compileRaw();
    }

    public function testUpsertDoesNotThrowWhenDataAndUniqueByProvided(): void
    {
        $user = new ExtendedCompilesMutationsUser();

        // Både data och uniqueBy icke-tomma → ska INTE kasta.
        $user->upsert(['email' => 'a@example.com'], ['email']);

        // Säkerställ att state faktiskt uppdaterades (dvs. vi passerade guarden).
        self::assertSame('UPSERT', $user->type);
    }

    public function testUpsertUsesArrayKeysForColumnsAndArrayValuesForBindings(): void
    {
        $user = new ExtendedCompilesMutationsUser();

        $data = [
            'email' => 'a@example.com',
            'name'  => 'John',
        ];

        $user->upsert($data, ['email']);

        // UnwrapArrayKeys-mutanten: skulle sätta columns = $data (assoc),
        // inte ['email', 'name'] – detta test skiljer på dem.
        self::assertSame(
            ['email', 'name'],
            $user->columns,
            'upsert() ska lagra kolumnnamn som array_keys($data) i $columns.'
        );

        // UnwrapArrayValues-mutanten: skulle sätta bindingsMutation = $data (assoc),
        // inte array_values($data).
        self::assertSame(
            array_values($data),
            $user->getBindingsMutation(),
            'upsert() ska lagra värdena som array_values($data) i bindingsMutation.'
        );
    }
}
