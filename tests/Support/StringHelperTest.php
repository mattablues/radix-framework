<?php

declare(strict_types=1);

namespace Radix\Tests\Support;

use PHPUnit\Framework\TestCase;
use Radix\Support\StringHelper;
use RuntimeException;

final class StringHelperTest extends TestCase
{
    public function testSingularizeUsesIrregularMapCaseInsensitive(): void
    {
        $this->assertSame('status', StringHelper::singularize('Status'));
    }

    public function testPluralizeUsesIrregularMapCaseInsensitive(): void
    {
        $this->assertSame('status', StringHelper::pluralize('Status'));
    }

    public function testPluralizeFallsBackToRulesWhenIrregularKeyIsMissingAndDoesNotTouchUndefinedOffset(): void
    {
        $oldLevel = error_reporting(E_ALL);

        set_error_handler(
            static function (int $severity, string $message): bool {
                throw new RuntimeException("PHP warning/notice: {$message}", $severity);
            }
        );

        try {
            $out = StringHelper::pluralize('bus');
        } finally {
            restore_error_handler();
            error_reporting($oldLevel);
        }

        $this->assertSame('buses', $out);
    }

    public function testPluralizeDoesNotTreatInnerSAsEndSuffix(): void
    {
        $this->assertSame('basics', StringHelper::pluralize('basic'));
    }

    public function testPluralizeSuffixRuleIsCaseInsensitive(): void
    {
        $this->assertSame('BUSes', StringHelper::pluralize('BUS'));
    }

    public function testPluralizeYRuleDoesNotMatchConsonantYInTheMiddleOfWord(): void
    {
        // Dödar PregMatchRemoveDollar på /[^aeiou]y$/i: utan $ matchar "dy" i "bodyguard"
        // och ger felaktig "...ies"-plural.
        $this->assertSame('bodyguards', StringHelper::pluralize('bodyguard'));
    }

    public function testPluralizeYRuleIsCaseInsensitiveForTrailingY(): void
    {
        // Dödar PregMatchRemoveFlags: utan /i matchar inte trailing 'Y' och vi får "CANDYs".
        $this->assertSame('CANDies', StringHelper::pluralize('CANDY'));
    }
}
