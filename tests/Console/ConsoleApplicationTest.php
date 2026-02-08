<?php

declare(strict_types=1);

namespace Radix\Tests\Console;

use Exception;
use PHPUnit\Framework\TestCase;
use Radix\Console\ConsoleApplication;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class ConsoleApplicationTest extends TestCase
{
    public function testRunWithoutCommandDisplaysGlobalHelpPlainText(): void
    {
        $app = new ConsoleApplication();
        $app->addCommand('make:view', static function (): void {});
        $app->addCommand('migrations:rollback', static function (): void {});

        $out = $this->captureOutput(static function () use ($app): void {
            $app->run(['radix']);
        });

        self::assertStringContainsString('Usage: php radix [command] [arguments]', $out);
        self::assertStringContainsString('Available commands:', $out);
        self::assertStringContainsString('- make:view', $out);
        self::assertStringContainsString('- migrations:rollback', $out);
        self::assertStringContainsString("Tip: Use '[command] --help' for more information", $out);
    }

    public function testRunWithoutCommandDisplaysGlobalHelpInMarkdownWhenMdFlagIsPresent(): void
    {
        $app = new ConsoleApplication();
        $app->addCommand('make:view', static function (): void {});
        $app->addCommand('migrations:rollback', static function (): void {});

        $out = $this->captureOutput(static function () use ($app): void {
            $app->run(['radix', '--md']);
        });

        self::assertStringContainsString('# Radix CLI', $out);
        self::assertStringContainsString('## Usage', $out);
        self::assertStringContainsString('```bash', $out);
        self::assertStringContainsString('## Tillgängliga kommandon', $out);

        // Listan ska vara i markdown-format med backticks
        self::assertStringContainsString('- `make:view`', $out);
        self::assertStringContainsString('- `migrations:rollback`', $out);

        self::assertStringContainsString('## Tips', $out);
        self::assertStringContainsString('Kör `php radix --md`', $out);
    }

    public function testUnknownCommandDisplaysMessageAndHelpPlainText(): void
    {
        $app = new ConsoleApplication();
        $app->addCommand('make:view', static function (): void {});

        $out = $this->captureOutput(static function () use ($app): void {
            $app->run(['radix', 'nope']);
        });

        self::assertStringContainsString("Unknown command: 'nope'.", $out);
        self::assertStringContainsString('Available commands:', $out);
        self::assertStringContainsString('- make:view', $out);
    }

    public function testUnknownCommandDisplaysMessageAndHelpInMarkdownWhenMdFlagIsPresent(): void
    {
        $app = new ConsoleApplication();
        $app->addCommand('make:view', static function (): void {});

        $out = $this->captureOutput(static function () use ($app): void {
            $app->run(['radix', '--md', 'nope']);
        });

        self::assertStringContainsString('# Radix CLI', $out);
        self::assertStringContainsString('## Okänt kommando', $out);
        self::assertStringContainsString("Unknown command: 'nope'.", $out);
        self::assertStringContainsString('## Tillgängliga kommandon', $out);
        self::assertStringContainsString('- `make:view`', $out);
    }

    public function testKnownCommandIsExecutedAndReceivesArgsAfterCommand(): void
    {
        $app = new ConsoleApplication();

        $called = false;
        $receivedArgs = null;

        $app->addCommand('make:view', static function (array $args) use (&$called, &$receivedArgs): void {
            $called = true;
            $receivedArgs = $args;
        });

        $this->captureOutput(static function () use ($app): void {
            // --md är en flagga och ska ignoreras vid "vilket kommando?"
            $app->run(['radix', '--md', 'make:view', 'User', '--flag']);
        });

        self::assertTrue($called);
        self::assertSame(['User', '--flag'], $receivedArgs);
    }

    public function testKnownCommandWithHelpFlagCallsCommandWithHelpAndMdWhenMarkdownEnabled(): void
    {
        $app = new ConsoleApplication();

        $receivedArgs = null;

        $app->addCommand('make:view', static function (array $args) use (&$receivedArgs): void {
            $receivedArgs = $args;
        });

        $this->captureOutput(static function () use ($app): void {
            $app->run(['radix', '--md', 'make:view', '--help']);
        });

        self::assertSame(['--help', '--md'], $receivedArgs);
    }

    public function testKnownCommandWithHelpFlagCallsCommandWithHelpOnlyWhenMarkdownNotEnabled(): void
    {
        $app = new ConsoleApplication();

        $receivedArgs = null;

        $app->addCommand('make:view', static function (array $args) use (&$receivedArgs): void {
            $receivedArgs = $args;
        });

        $this->captureOutput(static function () use ($app): void {
            $app->run(['radix', 'make:view', '--help']);
        });

        self::assertSame(['--help'], $receivedArgs);
    }

    public function testExceptionInCommandIsPrintedAsPlainTextWhenMarkdownNotEnabled(): void
    {
        $app = new ConsoleApplication();

        $app->addCommand('boom', static function (): void {
            throw new Exception('Boom');
        });

        $out = $this->captureOutput(static function () use ($app): void {
            $app->run(['radix', 'boom']);
        });

        self::assertSame("Error: Boom\n", $out);
    }

    public function testExceptionInCommandIsPrintedAsMarkdownWhenMarkdownEnabled(): void
    {
        $app = new ConsoleApplication();

        $app->addCommand('boom', static function (): void {
            throw new Exception('Boom');
        });

        $out = $this->captureOutput(static function () use ($app): void {
            $app->run(['radix', '--md', 'boom']);
        });

        self::assertStringContainsString('## Error', $out);
        self::assertStringContainsString("```text\nBoom\n```", $out);
    }


    public function testRunNormalizesArgvIndexesSoNonSequentialKeysStillWork(): void
    {
        $app = new ConsoleApplication();

        $called = false;

        $app->addCommand('make:view', static function () use (&$called): void {
            $called = true;
        });

        $argv = [
            0 => 'radix',
            2 => 'make:view',
        ];

        $this->captureOutput(static function () use ($app, $argv): void {
            // Avsiktligt "inte list" för att verifiera normalisering via array_values() (dödar mutant).
            /** @phpstan-ignore-next-line */
            $app->run($argv);
        });

        self::assertTrue($called, 'ConsoleApplication måste normalisera argv med array_values() så att kommandot hittas.');
    }

    public function testRunWithoutCommandDoesNotAlsoPrintUnknownCommand(): void
    {
        $app = new ConsoleApplication();
        $app->addCommand('make:view', static function (): void {});

        $out = $this->captureOutput(static function () use ($app): void {
            $app->run(['radix']); // inget kommando
        });

        // Dödar ReturnRemoval vid "no command": utan return fortsätter den och skriver "Unknown command: ''"
        self::assertStringNotContainsString('Unknown command:', $out);
        self::assertStringContainsString('Usage: php radix [command] [arguments]', $out);
    }

    public function testUnknownCommandInMarkdownDoesNotAlsoPrintPlainUnknownCommandHelp(): void
    {
        $app = new ConsoleApplication();
        $app->addCommand('make:view', static function (): void {});

        $out = $this->captureOutput(static function () use ($app): void {
            $app->run(['radix', '--md', 'nope']);
        });

        // Markdown-varianten ska finnas
        self::assertStringContainsString("```text\nUnknown command: 'nope'.\n```", $out);

        // Dödar ReturnRemoval i markdown-okänt-kommando-grenen:
        // utan return skrivs även plain-varianten ut efteråt.
        self::assertStringNotContainsString("Unknown command: 'nope'.\n\n", $out);
        self::assertStringNotContainsString('Usage: php radix [command] [arguments]', $out);
        self::assertStringNotContainsString('Available commands:', $out);
    }

    public function testDisplayHelpDefaultIsPlainTextWhenInvokedWithoutArgs(): void
    {
        $app = new ConsoleApplication();
        $app->addCommand('make:view', static function (): void {});

        $ref = new ReflectionMethod(ConsoleApplication::class, 'displayHelp');
        $ref->setAccessible(true);

        $out = $this->captureOutput(static function () use ($ref, $app): void {
            // Anropa UTAN argument => testar defaultvärdet (dödar FalseValue-mutanten)
            $ref->invoke($app);
        });

        self::assertStringContainsString('Usage: php radix [command] [arguments]', $out);
        self::assertStringNotContainsString('# Radix CLI', $out);
    }

    public function testDisplayHelpMarkdownDoesNotFallThroughToPlainText(): void
    {
        $app = new ConsoleApplication();
        $app->addCommand('make:view', static function (): void {});

        $ref = new ReflectionMethod(ConsoleApplication::class, 'displayHelp');
        $ref->setAccessible(true);

        $out = $this->captureOutput(static function () use ($ref, $app): void {
            $ref->invoke($app, true);
        });

        self::assertStringContainsString('# Radix CLI', $out);
        self::assertStringContainsString('## Tillgängliga kommandon', $out);

        // Dödar ReturnRemoval i markdown-branch i displayHelp():
        // utan return kommer plain-help också skrivas ut.
        self::assertStringNotContainsString('Usage: php radix [command] [arguments]', $out);
        self::assertStringNotContainsString('Available commands:', $out);
    }

    public function testMaxArgScanConstantIsExactly1000(): void
    {
        $ref = new ReflectionClass(ConsoleApplication::class);
        $const = $ref->getReflectionConstant('MAX_ARG_SCAN');

        self::assertNotFalse($const, 'ConsoleApplication::MAX_ARG_SCAN måste finnas.');
        self::assertSame(1000, $const->getValue(), 'MAX_ARG_SCAN ska vara exakt 1000.');
    }

    public function testCommandAtExactlyScanBoundaryIsStillFoundAndExecuted(): void
    {
        $app = new ConsoleApplication();

        $called = false;
        $app->addCommand('make:view', static function () use (&$called): void {
            $called = true;
        });

        // Bygg argv: 999 flaggor (som ignoreras) + kommandot som token #1000 efter scriptnamnet.
        // Dvs: steps=1000 ska fortfarande skannas.
        $argv = array_merge(['radix'], array_fill(0, 999, '--flag'), ['make:view']);

        $this->captureOutput(static function () use ($app, $argv): void {
            $app->run($argv);
        });

        self::assertTrue($called, 'Kommandot på exakt scan-gränsen ska fortfarande hittas och köras.');
    }

    public function testCommandAfterScanBoundaryIsNotScannedAndGlobalHelpIsShown(): void
    {
        $app = new ConsoleApplication();

        $called = false;
        $app->addCommand('make:view', static function () use (&$called): void {
            $called = true;
        });

        // 1000 flaggor => steps når 1000 utan att hitta kommando,
        // kommandot ligger som token #1001 och ska därför INTE hittas.
        $argv = array_merge(['radix'], array_fill(0, 1000, '--flag'), ['make:view']);

        $out = $this->captureOutput(static function () use ($app, $argv): void {
            $app->run($argv);
        });

        self::assertFalse($called, 'Kommandot efter scan-gränsen ska inte köras.');
        self::assertStringContainsString('Usage: php radix [command] [arguments]', $out);
    }

    public function testRunWithOnlyScriptNamePrintsHelpOnlyOnce(): void
    {
        $app = new ConsoleApplication();
        $app->addCommand('make:view', static function (): void {});

        $out = $this->captureOutput(static function () use ($app): void {
            $app->run(['radix']);
        });

        // Dödar ReturnRemoval i "if ($command === null) { displayHelp(); return; }"
        // Om return tas bort -> displayHelp körs igen i nästa guard och "Usage:" syns två gånger.
        self::assertSame(1, substr_count($out, 'Usage: php radix [command] [arguments]'));
    }

    public function testRunWithOnlyFlagsPrintsHelpOnlyOnceInMarkdown(): void
    {
        $app = new ConsoleApplication();
        $app->addCommand('make:view', static function (): void {});

        $out = $this->captureOutput(static function () use ($app): void {
            // Inget kommando, bara flagga => command förblir null
            $app->run(['radix', '--md']);
        });

        // Dödar ReturnRemoval i "if ($command === null) ..."
        self::assertSame(1, substr_count($out, '# Radix CLI'));

        // Extra skydd: om den skulle falla igenom och skriva plain help också
        self::assertStringNotContainsString('Usage: php radix [command] [arguments]', $out);
    }

    /**
     * @param callable():void $fn
     */
    private function captureOutput(callable $fn): string
    {
        $levelBefore = ob_get_level();

        ob_start();
        $levelAfterStart = ob_get_level();

        try {
            $fn();

            // Om tested code stängde vår buffer (eller fler), fånga det direkt och faila tydligt.
            if (ob_get_level() < $levelAfterStart) {
                $this->fail('Tested code stängde output-buffer som skapades av testet.');
            }

            $out = ob_get_clean();

            // Säkerställ att vi bara gick tillbaka exakt en nivå (den vi startade).
            self::assertSame(
                $levelBefore,
                ob_get_level(),
                'Output-buffer-nivån efter captureOutput() ska vara samma som innan.'
            );

            return is_string($out) ? $out : '';
        } catch (Throwable $e) {
            // Städa bara vår egen buffer om den fortfarande finns kvar.
            if (ob_get_level() >= $levelAfterStart) {
                ob_end_clean();
            }

            // Återställ nivå-check (ska vara samma som innan).
            self::assertSame(
                $levelBefore,
                ob_get_level(),
                'Output-buffer-nivån efter exception i captureOutput() ska vara samma som innan.'
            );

            throw $e;
        }
    }

    public function testRunRequiresArrayValuesSoScriptNameEndsUpAtIndexZero(): void
    {
        $app = new ConsoleApplication();

        $called = false;
        $app->addCommand('make:view', static function () use (&$called): void {
            $called = true;
        });

        $argv = [
            1 => 'radix',
            2 => 'make:view',
        ];

        $this->captureOutput(static function () use ($app, $argv): void {
            // Avsiktligt "holey" argv (inte list) för att testa att array_values() krävs.
            /** @phpstan-ignore-next-line */
            $app->run($argv);
        });

        self::assertTrue($called, 'array_values() krävs för att argv ska normaliseras till en riktig lista.');
    }

    public function testScanHardCapMustBreakNotContinueSoCommandAfterCapIsNotFound(): void
    {
        $app = new ConsoleApplication();

        $called = false;
        $app->addCommand('make:view', static function () use (&$called): void {
            $called = true;
        });

        // 1000 flaggor = når cap utan kommando.
        // Kommandot ligger EFTER capen och ska därför inte hittas om vi "break":ar.
        // Om mutanten gör break->continue kommer loopen fortsätta och till slut hitta make:view => $called=true => test fail.
        $argv = array_merge(['radix'], array_fill(0, 1000, '--flag'), ['make:view']);

        $out = $this->captureOutput(static function () use ($app, $argv): void {
            $app->run($argv);
        });

        self::assertFalse($called, 'Kommandon efter MAX_ARG_SCAN ska aldrig kunna hittas.');
        self::assertStringContainsString('Usage: php radix [command] [arguments]', $out);
        self::assertStringNotContainsString("Unknown command: 'make:view'", $out);
    }

    public function testRunRequiresArrayValuesSoScriptNameExistsAtIndexZero(): void
    {
        $app = new ConsoleApplication();

        $called = false;
        $app->addCommand('make:view', static function () use (&$called): void {
            $called = true;
        });

        $argv = [
            1 => 'radix',
            2 => 'make:view',
        ];

        $this->captureOutput(static function () use ($app, $argv): void {
            // Avsiktligt "holey" argv (inte list) för att testa script-index-kontraktet.
            /** @phpstan-ignore-next-line */
            $app->run($argv);
        });

        self::assertTrue($called);
    }

    public function testCapMustBreakSoCommandAfterCapIsNotFound(): void
    {
        $app = new ConsoleApplication();

        $called = false;
        $app->addCommand('make:view', static function () use (&$called): void {
            $called = true;
        });

        // tail = argv[1..]
        // steps 1..1000: flaggor (ignoreras)
        // steps 1001: cap-checken triggar (MAX+1) och originalkod break:ar
        // kommandot ligger efter => hittas bara om mutanten gör break->continue
        $argv = array_merge(
            ['radix'],
            array_fill(0, 1000, '--flag'),
            ['--extra-after-cap'],
            ['make:view']
        );

        $out = $this->captureOutput(static function () use ($app, $argv): void {
            $app->run($argv);
        });

        self::assertFalse($called, 'Kommandot efter cap ska inte kunna hittas när capen bryter loopen.');
        self::assertStringContainsString('Usage: php radix [command] [arguments]', $out);
    }

    public function testScriptNameIsReadFromIndexZeroNotIndexOne(): void
    {
        $app = new ConsoleApplication();

        $called = false;
        $app->addCommand('make:view', static function () use (&$called): void {
            $called = true;
        });

        // argv[0] är korrekt, argv[1] är tom (ska INTE användas som script-namn),
        // kommandot ligger på argv[2].
        $argv = ['radix', '', 'make:view'];

        $this->captureOutput(static function () use ($app, $argv): void {
            $app->run($argv);
        });

        self::assertTrue(
            $called,
            'Script-namn ska läsas från argv[0]. Om argv[1] används felaktigt ska kommandot inte köras.'
        );
    }

    public function testNonStringScriptNameMustShowHelpAndNotExecuteCommands(): void
    {
        $app = new ConsoleApplication();

        $called = false;
        $app->addCommand('make:view', static function () use (&$called): void {
            $called = true;
        });

        $argv = [123, 'make:view'];

        $out = $this->captureOutput(static function () use ($app, $argv): void {
            // Avsiktligt fel typ i argv[0] för att testa defensiv kod (mutation-test).
            /** @phpstan-ignore-next-line */
            $app->run($argv);
        });

        self::assertFalse($called, 'När argv[0] inte är en sträng ska inga kommandon köras.');
        self::assertStringContainsString('Usage: php radix [command] [arguments]', $out);
    }
}
