<?php

declare(strict_types=1);

namespace Radix\Tests\Console;

use PHPUnit\Framework\TestCase;
use Radix\Console\Commands\BaseCommand;
use ReflectionMethod;
use Throwable;

interface BaseCommandTestBridge
{
    /**
     * @param array<string,string> $options
     * @param list<string> $examples
     */
    public function callDisplayHelp(string $usage, array $options, bool $asMarkdown, array $examples = []): void;

    /**
     * @param array<string,string> $options
     */
    public function callDisplayHelpDefault(string $usage, array $options): void;
}

final class BaseCommandTest extends TestCase
{
    /**
     * @return BaseCommand&BaseCommandTestBridge
     */
    private function makeCommand(): BaseCommand
    {
        return new class extends BaseCommand implements BaseCommandTestBridge {
            public function execute(array $args): void {}

            /**
             * @param array<string,string> $options
             * @param list<string> $examples
             */
            public function callDisplayHelp(string $usage, array $options, bool $asMarkdown, array $examples = []): void
            {
                $this->displayHelp($usage, $options, $asMarkdown, $examples);
            }

            /**
             * @param array<string,string> $options
             */
            public function callDisplayHelpDefault(string $usage, array $options): void
            {
                $this->displayHelp($usage, $options);
            }
        };
    }

    public function testDisplayHelpDefaultIsPlainNotMarkdown(): void
    {
        $cmd = $this->makeCommand();

        $out = $this->captureOutput(function () use ($cmd): void {
            $cmd->callDisplayHelpDefault('u', ['--a' => 'b']);
        });

        self::assertStringContainsString('Usage: u', $out);
        self::assertStringContainsString('Options:', $out);
        self::assertStringNotContainsString('## Usage', $out);
    }

    public function testHandleHelpFlagIsPublic(): void
    {
        $ref = new ReflectionMethod(BaseCommand::class, 'handleHelpFlag');
        self::assertTrue($ref->isPublic(), 'handleHelpFlag() måste vara public.');
    }

    public function testDisplayHelpPlainPrintsUsageOptionsExamplesAndItems(): void
    {
        $cmd = $this->makeCommand();

        $usage = 'make:view <path> [--ext=ratio.php]';
        $options = [
            '<path>' => 'View path',
            '--ext=ratio.php' => 'File extension',
        ];
        $examples = [
            'make:view about/index --ext=ratio.php',
            'make:view docs/guide --ext=php',
        ];

        $out = $this->captureOutput(function () use ($cmd, $usage, $options, $examples): void {
            $cmd->callDisplayHelp($usage, $options, false, $examples);
        });

        self::assertStringContainsString('Usage: ' . $usage, $out);
        self::assertStringContainsString('Options:', $out);
        self::assertStringContainsString('Examples:', $out);

        // options/items måste faktiskt skrivas ut (dödar Foreach_-mutanter)
        self::assertStringContainsString('<path>', $out);
        self::assertStringContainsString('--ext=ratio.php', $out);
        self::assertStringContainsString('make:view about/index --ext=ratio.php', $out);
        self::assertStringContainsString('make:view docs/guide --ext=php', $out);
    }

    public function testDisplayHelpMarkdownPrintsSectionsAndDoesNotFallThroughToPlainText(): void
    {
        $cmd = $this->makeCommand();

        $usage = 'make:view <path>';
        $options = [
            '<path>' => 'View path',
        ];
        $examples = [
            'make:view about/index',
        ];

        $out = $this->captureOutput(function () use ($cmd, $usage, $options, $examples): void {
            $cmd->callDisplayHelp($usage, $options, true, $examples);
        });

        self::assertStringContainsString('## Usage', $out);
        self::assertStringContainsString($usage, $out);
        self::assertStringContainsString('## Options', $out);
        self::assertStringContainsString('## Examples', $out);

        // Dödar ReturnRemoval i markdown-branch: annars kommer plain text efteråt
        self::assertStringNotContainsString("Tip: You can always use '--help' for more information.", $out);
        self::assertStringNotContainsString('Options:', $out);
        self::assertStringNotContainsString('Examples:', $out);
    }

    public function testHandleHelpFlagReturnsTrueForHelpAndPrintsPlainWhenNoMdFlag(): void
    {
        $cmd = $this->makeCommand();

        $out = $this->captureOutput(function () use ($cmd): void {
            $res = $cmd->handleHelpFlag(['--help'], 'x', ['--a' => 'b'], ['x y']);
            self::assertTrue($res);
        });

        self::assertStringContainsString('Usage: x', $out);
    }

    public function testHandleHelpFlagReturnsTrueAndPrintsMarkdownWhenMdAndHelpPresent(): void
    {
        $cmd = $this->makeCommand();

        $out = $this->captureOutput(function () use ($cmd): void {
            $res = $cmd->handleHelpFlag(['--md', '--help'], 'x', ['--a' => 'b'], ['x y']);
            self::assertTrue($res);
        });

        self::assertStringContainsString('## Usage', $out);
        self::assertStringContainsString('```bash', $out);
        self::assertStringNotContainsString('Options:', $out);
    }

    public function testDisplayHelpDefaultIsPlainWhenCalledWithThreeArguments(): void
    {
        $cmd = $this->makeCommand();

        // Vi anropar wrappern utan att skicka $asMarkdown explicit.
        // Då måste default vara false => plain-output (dödar FalseValue-mutanten).
        $out = $this->captureOutput(function () use ($cmd): void {
            $cmd->callDisplayHelp('x', ['--a' => 'b'], false, []);
        });

        self::assertStringContainsString('Usage: x', $out);
        self::assertStringContainsString('Options:', $out);
        self::assertStringNotContainsString('## Usage', $out);
    }

    public function testDisplayHelpMarkdownIncludesAllExamplesEachOnOwnLineInsideFence(): void
    {
        $cmd = $this->makeCommand();

        $examples = ['one', 'two'];

        $out = $this->captureOutput(function () use ($cmd, $examples): void {
            $cmd->callDisplayHelp('u', ['--a' => 'b'], true, $examples);
        });

        // Dödar Foreach_ (examples) + Concat/ConcatOperandRemoval på echo $example . "\n"
        self::assertStringContainsString("```bash\n", $out);
        self::assertStringContainsString("one\n", $out);
        self::assertStringContainsString("two\n", $out);

        // Viktigt: ordningen och att det inte blir "\n" före example istället för efter
        $posFence = strpos($out, "```bash\n");
        $posOne = strpos($out, "one\n");
        $posTwo = strpos($out, "two\n");
        self::assertNotFalse($posFence);
        self::assertNotFalse($posOne);
        self::assertNotFalse($posTwo);
        self::assertTrue($posFence < $posOne);
        self::assertTrue($posOne < $posTwo);
    }

    public function testDisplayHelpMarkdownIncludesOptionsLines(): void
    {
        $cmd = $this->makeCommand();

        $out = $this->captureOutput(function () use ($cmd): void {
            $cmd->callDisplayHelp('u', ['--long' => 'L', '-s' => 'S'], true, []);
        });

        // Dödar Foreach_ (options) i markdown
        self::assertStringContainsString('- `--long`: L', $out);
        self::assertStringContainsString('- `-s`: S', $out);
    }

    public function testDisplayHelpPlainPadsOptionsUsingLongestKeyLength(): void
    {
        $cmd = $this->makeCommand();

        $options = [
            '-s' => 'Short',
            '--long' => 'Long',
        ];

        $out = $this->captureOutput(function () use ($cmd, $options): void {
            $cmd->callDisplayHelp('u', $options, false, []);
        });

        self::assertStringContainsString("  --long  Long\n", $out);

        // str_pad("-s", 6) ger "-s" + 4 spaces, och echo lägger dessutom "  " mellan opt och description
        // => totalt 6 spaces mellan -s och Short
        self::assertStringContainsString("  -s      Short\n", $out);
    }

    public function testHandleHelpFlagDetectsMarkdownEvenWhenMdIsNotFirstArg(): void
    {
        $cmd = $this->makeCommand();

        $out = $this->captureOutput(function () use ($cmd): void {
            $res = $cmd->handleHelpFlag(['x', '--md', '--help'], 'u', ['--a' => 'b'], ['ex']);
            self::assertTrue($res);
        });

        // Dödar loop-mutanter (for_) och Break_->continue i md-detektionen
        self::assertStringContainsString('## Usage', $out);
        self::assertStringContainsString('```bash', $out);
    }

    public function testColoredOutputUsesRedColorCodeAndResetsAtEnd(): void
    {
        $cmd = $this->makeCommand();

        $out = $this->captureOutput(function (): void {
            // Anropa protected via en liten bridge
            $bridge = new class extends BaseCommand {
                public function execute(array $args): void {}
                public function callColored(string $m, string $c): void
                {
                    $this->coloredOutput($m, $c);
                }
            };

            $bridge->callColored('X', 'red');
        });

        // Dödar ArrayItemRemoval ('red' saknas) + Coalesce-mutanten (fel fallback)
        // Dödar Concat-varianter: färgkod ska komma före message och reset före newline
        self::assertStringContainsString("\033[31m", $out);
        self::assertStringContainsString("X", $out);
        self::assertStringContainsString("\033[0m", $out);

        $posRed = strpos($out, "\033[31m");
        $posX = strpos($out, "X");
        $posReset = strpos($out, "\033[0m");

        self::assertNotFalse($posRed);
        self::assertNotFalse($posX);
        self::assertNotFalse($posReset);

        self::assertTrue($posRed < $posX, 'Färgkod ska komma före texten.');
        self::assertTrue($posX < $posReset, 'Reset ska komma efter texten.');
        self::assertStringEndsWith("\n", $out, 'Ska alltid avsluta med newline.');
    }

    public function testHandleHelpFlagReturnsFalseAndPrintsNothingWhenNoHelpFlag(): void
    {
        $cmd = $this->makeCommand();

        $out = $this->captureOutput(function () use ($cmd): void {
            $res = $cmd->handleHelpFlag(['--md'], 'x', ['--a' => 'b'], ['x y']);
            self::assertFalse($res);
        });

        self::assertSame('', $out);
    }

    private function captureOutput(callable $fn): string
    {
        $before = ob_get_level();

        ob_start();
        $afterStart = ob_get_level();

        try {
            $fn();

            if (ob_get_level() < $afterStart) {
                $this->fail('Tested code stängde output-buffer som skapades av testet.');
            }

            $out = ob_get_clean();

            self::assertSame($before, ob_get_level());

            return is_string($out) ? $out : '';
        } catch (Throwable $e) {
            if (ob_get_level() >= $afterStart) {
                ob_end_clean();
            }
            self::assertSame($before, ob_get_level());
            throw $e;
        }
    }
}
