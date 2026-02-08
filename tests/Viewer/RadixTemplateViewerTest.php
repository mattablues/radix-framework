<?php

declare(strict_types=1);

namespace Radix\Tests\Viewer;

use Exception;
use PHPUnit\Framework\TestCase;
use Radix\Viewer\RadixTemplateViewer;
use ReflectionClass;
use RuntimeException;

class TestViewLogger extends \Radix\Support\Logger
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct()
    {
        // Hoppa över förälderns konstruktor för att undvika fil-I/O
    }

    /**
     * @param string $message
     * @param array<string,mixed> $context
     */
    public function debug($message, array $context = []): void
    {
        $this->messages[] = $this->stringifyMixed($message);
    }

    private function stringifyMixed(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        $encoded = json_encode($value);
        return $encoded !== false ? $encoded : '';
    }
}

final class MarkableObject
{
    public bool $marked = false;
}

class RadixTemplateViewerTest extends TestCase
{
    private RadixTemplateViewer $viewer;
    private string $tempRootPath;
    private string $tempViewsPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Skapa temporär katalog för templates
        $this->tempRootPath = sys_get_temp_dir() . '/radix_test/';
        $this->tempViewsPath = $this->tempRootPath . 'views/';

        $this->createDirectoryIfNotExists($this->tempViewsPath);
        $this->createDirectoryIfNotExists($this->tempViewsPath . '/components');

        // Definiera ROOT_PATH om ej definierad
        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', $this->tempRootPath);
        }

        // Rikta CACHE_PATH till testets temporära cachekatalog
        putenv('CACHE_PATH=' . $this->tempRootPath . 'cache/views');

        $this->viewer = new RadixTemplateViewer($this->tempViewsPath);
        $this->viewer->enableDebugMode(false);
    }

    protected function tearDown(): void
    {
        // Städa upp env så tester inte läcker till varandra
        putenv('VIEWS_CACHE_PATH');
        // Rensa temporära kataloger och filer
        $this->deleteDirectory($this->tempRootPath);
        parent::tearDown();
    }

    public function testViewsCachePathEnvVarRelativeIsAppliedInsteadOfDefault(): void
    {
        $original = getenv('VIEWS_CACHE_PATH');

        try {
            // Sätt en RELATIV path så vi testar branch: envCachePath !== ''
            putenv('VIEWS_CACHE_PATH=custom/views-cache');

            $viewer = new RadixTemplateViewer($this->tempViewsPath);

            $reflection = new ReflectionClass($viewer);
            $cachePathProperty = $reflection->getProperty('cachePath');
            $cachePathProperty->setAccessible(true);

            $cachePathMixed = $cachePathProperty->getValue($viewer);
            $this->assertIsString($cachePathMixed);

            /** @var string $cachePath */
            $cachePath = $cachePathMixed;

            // Normalisera separators (Windows kan få mix av "\" och "/")
            $normalizedCachePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cachePath);
            $normalizedCachePath = rtrim($normalizedCachePath, '/\\') . DIRECTORY_SEPARATOR;

            $expectedTail = 'custom' . DIRECTORY_SEPARATOR . 'views-cache' . DIRECTORY_SEPARATOR;

            $this->assertStringEndsWith(
                $expectedTail,
                $normalizedCachePath,
                'När VIEWS_CACHE_PATH är satt (relativ) ska cachePath sluta med den konfigurerade sökvägen.'
            );

            $defaultTail = DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR;
            $this->assertFalse(
                str_ends_with($normalizedCachePath, $defaultTail),
                'När VIEWS_CACHE_PATH är satt ska default cache/views inte användas.'
            );
        } finally {
            if ($original === false) {
                putenv('VIEWS_CACHE_PATH');
            } else {
                putenv('VIEWS_CACHE_PATH=' . $original);
            }
        }
    }

    public function testAdvancedAlertComponent(): void
    {
        $componentPath = "{$this->tempViewsPath}components/alert.ratio.php";
        file_put_contents(
            $componentPath,
            '
            <div class="alert alert-{{ $type }} {{ $class ?? \'\' }}" style="{{ $style ?? \'\' }}">
                {% if(isset($header)) : %}
                    <div class="alert-header">{{ $header }}</div>
                {% endif; %}
                <div class="alert-body">{{ $slot }}</div>
                {% if(isset($footer)) : %}
                    <div class="alert-footer">{{ $footer }}</div>
                {% endif; %}
            </div>'
        );

        $this->assertFileExists($componentPath, '[DEBUG] Komponentfilen saknas: ' . $componentPath);

        $templatePath = "{$this->tempViewsPath}test_template.ratio.php";
        file_put_contents(
            $templatePath,
            '
            <x-alert type="warning" class="my-4" style="color: red;">
                <x-slot:header>This is the header</x-slot:header>
                This is the alert content.
                <x-slot:footer>This is the footer</x-slot:footer>
            </x-alert>'
        );

        $output = $this->viewer->render('test_template');

        // Förväntad HTML-output, utan fokus på mönster av radbrytningar
        $expectedOutput = '<div class="alert alert-warning my-4" style="color: red;">
            <div class="alert-header">This is the header</div>
            <div class="alert-body">This is the alert content.</div>
            <div class="alert-footer">This is the footer</div>
        </div>';

        // Normalisera både förväntad och faktisk output
        $normalizedExpectedOutput = preg_replace('/\s+/', ' ', trim($expectedOutput));
        $normalizedOutput = preg_replace('/\s+/', ' ', trim($output));

        // Jämför normaliserad output
        $this->assertSame(
            $normalizedExpectedOutput,
            $normalizedOutput,
            '[DEBUG] Alert-komponenten renderades inte korrekt.'
        );
    }

    public function testGenerateCacheKeyChangesWhenTemplateMtimeChangesButContentDoesNot(): void
    {
        $reflection = new ReflectionClass($this->viewer);

        $resolveTemplatePath = $reflection->getMethod('resolveTemplatePath');
        $resolveTemplatePath->setAccessible(true);

        $generateCacheKey = $reflection->getMethod('generateCacheKey');
        $generateCacheKey->setAccessible(true);

        $templateLogicalName = 'template_mtime_key_test';

        /** @var string $resolved */
        $resolved = $resolveTemplatePath->invoke($this->viewer, $templateLogicalName);

        $templatePath = $this->tempViewsPath . $resolved;
        $this->createDirectoryIfNotExists(dirname($templatePath));
        file_put_contents($templatePath, 'Hello {{ $name }}');

        $data = ['name' => 'User'];

        // Stabil start-mtime
        $t1 = time();
        touch($templatePath, $t1);

        /** @var string $key1 */
        $key1 = $generateCacheKey->invoke($this->viewer, $resolved, $data);

        // Ändra ENDAST mtime (inte innehållet)
        $t2 = $t1 + 10;
        touch($templatePath, $t2);

        /** @var string $key2 */
        $key2 = $generateCacheKey->invoke($this->viewer, $resolved, $data);

        $this->assertNotSame(
            $key1,
            $key2,
            'generateCacheKey ska påverkas av template-filens mtime när filen existerar.'
        );
    }


    public function testComponentPropsIgnoreEmptyNames(): void
    {
        $componentPath = "{$this->tempViewsPath}components/empty_prop.ratio.php";
        // Vi lägger till en tagg runt proppen för att kunna söka efter den
        file_put_contents($componentPath, '{% props(["", "label" => "Default"]) %}[{{ $label }}]');

        $templatePath = "{$this->tempViewsPath}empty_test.ratio.php";
        file_put_contents($templatePath, '<x-empty_prop />');

        $output = $this->viewer->render('empty_test');

        // 1. Verifiera att värdet är korrekt
        $this->assertStringContainsString('[Default]', $output);

        // 2. KRITISKT: Verifiera att själva props-taggen har raderats helt
        // Detta dödar ReturnRemoval eftersom muterad kod skulle lämna kvar taggen i output.
        $this->assertStringNotContainsString('{% props', $output);
    }

    public function testPropsTagIsRemovedFromOutput(): void
    {
        $componentPath = "{$this->tempViewsPath}components/props_removal.ratio.php";
        // Vi sätter props på en egen rad med whitespace runt
        file_put_contents($componentPath, "
            {% props(['title' => 'Hello']) %}
            <h1>{{ \$title }}</h1>
        ");

        $templatePath = "{$this->tempViewsPath}props_removal_test.ratio.php";
        file_put_contents($templatePath, '<x-props_removal />');

        $output = $this->viewer->render('props_removal_test');

        // Om mutanten ReturnRemoval lever, kommer hela strängen '{% props(['title' => 'Hello']) %}'
        // att finnas kvar i $componentCode när den skickas till replacePlaceholders.
        // Det gör att den renderade outputen kommer innehålla den råa taggen.
        $this->assertStringNotContainsString('{% props', $output);

        // Vi kollar också att titeln faktiskt renderades (bevisar att eval() fungerade)
        $this->assertStringContainsString('<h1>Hello</h1>', $output);

        // För att verkligen döda mutanten: kontrollera att vi inte har några oväntade
        // rester av PHP-taggar om mutanten returnerade null (vilket lämnar kvar texten)
        $this->assertStringNotContainsString('props([', $output);
    }

    public function testPropsCastingKillsMutants(): void
    {
        $componentPath = "{$this->tempViewsPath}components/props_cast.ratio.php";
        // Vi testar:
        // 1. KV-par där värdet är ett nummer (ska castas till sträng "1")
        // 2. Ett ensamt värde "simple" (ska bli variabelnamnet $simple via castToString)
        file_put_contents($componentPath, '{% props(["active" => 1, "simple"]) %}[{{ $active }}][{{ $simple }}]');

        $templatePath = "{$this->tempViewsPath}props_cast_test.ratio.php";
        file_put_contents($templatePath, '<x-props_cast />');

        $output = $this->viewer->render('props_cast_test');

        // Om castToString fungerar korrekt ska vi se värdet "1"
        $this->assertStringContainsString('[1]', $output);
        // "simple" ska finnas som en tom variabel
        $this->assertStringContainsString('[]', $output);

        // Verifiera att taggen är helt borta
        $this->assertStringNotContainsString('{% props', $output);
    }

    public function testComponentWithPropsAndDefaults(): void
    {
        $componentPath = "{$this->tempViewsPath}components/button.ratio.php";
        file_put_contents(
            $componentPath,
            '{% props(["type" => "button", "label", "class" => "btn-primary"]) %}
             <button type="{{ $type }}" class="{{ $class }}">{{ $label }}</button>'
        );

        // Test 1: Använd standardvärden för typ och klass, skicka bara label
        $templatePath = "{$this->tempViewsPath}props_test.ratio.php";
        file_put_contents($templatePath, '<x-button label="Spara"></x-button>');

        $output = $this->viewer->render('props_test');
        $this->assertSame('<button type="button" class="btn-primary">Spara</button>', trim($output));

        // Test 2: Skriv över ett standardvärde
        file_put_contents($templatePath, '<x-button label="Radera" class="btn-danger" type="submit"></x-button>');

        $output = $this->viewer->render('props_test');
        $this->assertSame('<button type="submit" class="btn-danger">Radera</button>', trim($output));
    }

    public function testItInjectsBlocksIntoDataArray(): void
    {
        // 1. Skapa en layout som använder variablerna
        $layoutPath = $this->tempViewsPath . 'layout.ratio.php';
        file_put_contents($layoutPath, 'ID:{{ $pageId }} CLASS:{{ $pageClass }} SEARCH:{{ $searchId }}');

        // 2. Skapa en mall som ärver layouten och definierar blocken med extra mellanslag/radbrytningar
        $templatePath = $this->tempViewsPath . 'inject-test.ratio.php';
        file_put_contents(
            $templatePath,
            '{% extends "layout.ratio.php" %}
             {% block pageId %}
                777 
             {% endblock %}
             {% block pageClass %}  my-class  {% endblock %}
             {% block searchId %}	search-99	{% endblock %}'
        );

        $result = $this->viewer->render('inject-test');

        // Verifiera att värdena injicerats korrekt OCH att de har trimmats.
        // Om trim() tas bort kommer strängen innehålla radbrytningar och tabbar,
        // vilket gör att denna assertion failar.
        $this->assertStringContainsString('ID:777 CLASS:my-class SEARCH:search-99', $result);
    }

    public function testItSetsDefaultEmptyStringForMissingInjectableBlocks(): void
    {
        // En mall utan block och utan extends (så vi slipper syntaxfel från kvarlämnade block)
        $templatePath = $this->tempViewsPath . 'empty-inject.ratio.php';
        file_put_contents(
            $templatePath,
            'VALUES:{{ $pageId }}{{ $pageClass }}{{ $searchId }}'
        );

        $result = $this->viewer->render('empty-inject');

        // Detta dödar LogicalNot-mutanten.
        // Om variablerna inte sätts till '' kommer eval() kasta ett fel (Undefined variable).
        $this->assertStringContainsString('VALUES:', $result);
    }

    public function testComponentWithNamedSlotsAndDynamicAttributes(): void
    {
        $componentPath = "{$this->tempViewsPath}components/card_with_slots.ratio.php";
        file_put_contents(
            $componentPath,
            '<div class="card {{ $class }}" style="{{ $style }}">
                <div class="header">{{ $header }}</div>
                <div class="content">{{ $slot }}</div>
                <div class="footer">{{ $footer }}</div>
            </div>'
        );

        $this->assertFileExists($componentPath, '[DEBUG] Komponentfilen saknas: ' . $componentPath);

        $templatePath = "{$this->tempViewsPath}test_template.ratio.php";
        file_put_contents($templatePath, '
            <x-card_with_slots class="highlight" style="color: red;">
                <x-slot:header>Header Content</x-slot:header>
                Main Content Here
                <x-slot:footer>Footer Content</x-slot:footer>
            </x-card_with_slots>
        ');

        $output = $this->viewer->render('test_template');

        $expectedOutput = '<div class="card highlight" style="color: red;"><div class="header">Header Content</div><div class="content">Main Content Here</div><div class="footer">Footer Content</div></div>';

        // Använd normalizeOutput för att jämföra formateringen
        $this->assertSame(
            $this->normalizeOutput($expectedOutput),
            $this->normalizeOutput($output),
            'Named slots och dynamiska attribut fungerar inte korrekt.'
        );
    }

    public function testEmptySlot(): void
    {
        // Skapa en enkel kort komponent
        $cardPath = "{$this->tempViewsPath}components/card.ratio.php";
        file_put_contents(
            $cardPath,
            '<div class="card">
                <div class="header">{{ $header }}</div>
                <div class="content">{{ $slot }}</div>
            </div>'
        );

        $this->assertFileExists($cardPath, '[DEBUG] Komponentfilen saknas: ' . $cardPath);

        $templatePath = "{$this->tempViewsPath}empty_slot_test_template.ratio.php";
        file_put_contents(
            $templatePath,
            '<x-card>
                <x-slot:header></x-slot:header>
                Main content here.
            </x-card>'
        );

        $output = $this->viewer->render('empty_slot_test_template');

        // Förväntad HTML-output
        $expectedOutput = '<div class="card"><div class="header"></div><div class="content">Main content here.</div></div>';

        // Jämför normaliserad output
        $this->assertSame(
            $this->normalizeOutput($expectedOutput),
            $this->normalizeOutput($output),
            '[DEBUG] Empty slot rendered incorrectly.'
        );
    }

    public function testDynamicAttributes(): void
    {
        $alertPath = "{$this->tempViewsPath}components/alert.ratio.php";
        file_put_contents(
            $alertPath,
            '<div class="alert <?php echo $type; ?>">{{ $slot }}</div>' // Dynamiska PHP-attribut i komponenten
        );

        $templatePath = "{$this->tempViewsPath}dynamic_attribute_test_template.ratio.php";
        file_put_contents(
            $templatePath,
            '<x-alert type="info">Content with dynamic attributes.</x-alert>'
        );

        $output = $this->viewer->render('dynamic_attribute_test_template', ['type' => 'info']);

        // Förväntad output direkt från templatemotorn
        $expectedOutput = '<div class="alert info">Content with dynamic attributes.</div>';

        // Testa med exakt output utan normalisering
        $this->assertSame(
            $expectedOutput,
            $output,
            '[DEBUG] Dynamic attributes rendered incorrectly.'
        );
    }

    public function testGlobalDataRendering(): void
    {
        $cardPath = "{$this->tempViewsPath}components/card.ratio.php";
        file_put_contents(
            $cardPath,
            '<div class="card">
                <div class="header">{{ $header }}</div>
                <div class="content">{{ $slot }}</div>
            </div>'
        );

        $this->assertFileExists($cardPath, '[DEBUG] Komponentfilen saknas: ' . $cardPath);

        $templatePath = "{$this->tempViewsPath}global_data_test_template.ratio.php";
        file_put_contents(
            $templatePath,
            '<x-card>
                <x-slot:header>This is a global title</x-slot:header>
                Main content with global data.
            </x-card>'
        );

        // Registrera globala data
        $this->viewer->shared('header', 'This is a global title');

        // Rendera output
        $output = $this->viewer->render('global_data_test_template');

        // Förväntad HTML-output
        $expectedOutput = '<div class="card"><div class="header">This is a global title</div><div class="content">Main content with global data.</div></div>';

        // Normalisera förväntad och faktisk output
        $this->assertSame(
            $this->normalizeOutput($expectedOutput),
            $this->normalizeOutput($output),
            '[DEBUG] Global data rendered incorrectly.'
        );
    }

    public function testNestedComponentsWithSlots(): void
    {
        $cardPath = "{$this->tempViewsPath}components/card.ratio.php";
        file_put_contents(
            $cardPath,
            '<div class="card">
                <div class="header">{{ $header }}</div>
                <div class="content">{{ $slot }}</div>
            </div>'
        );

        $alertPath = "{$this->tempViewsPath}components/alert.ratio.php";
        file_put_contents(
            $alertPath,
            '<div class="alert">{{ $slot }}</div>'
        );

        $templatePath = "{$this->tempViewsPath}nested_test_template.ratio.php";
        file_put_contents(
            $templatePath,
            '<x-card>
                <x-slot:header>
                    <x-alert>This is an alert in the header</x-alert>
                </x-slot:header>
                Nested content here.
            </x-card>'
        );

        $output = $this->viewer->render('nested_test_template');

        // Förväntad HTML-output exakt som den genereras
        $expectedOutput = '<div class="card">
                <div class="header">&lt;div class=&quot;alert&quot;&gt;This is an alert in the header&lt;/div&gt;</div>
                <div class="content">Nested content here.</div>
            </div>';

        // Jämförelse utan normalisering
        $this->assertSame(
            $expectedOutput,
            $output,
            '[DEBUG] Nested components with slots rendered incorrectly.'
        );
    }

    public function testCacheInvalidation(): void
    {
        // Tvinga cache-läge
        $originalEnv = getenv('APP_ENV');
        putenv('APP_ENV=production');

        // Steg 1: Simulera cache-lagring
        $templatePath = $this->tempViewsPath . 'invalidate_test/template.ratio.php';
        $this->createDirectoryIfNotExists(dirname($templatePath));
        file_put_contents($templatePath, 'Hello {{ $name }}');

        $reflection = new ReflectionClass($this->viewer);
        $resolveTemplatePath = $reflection->getMethod('resolveTemplatePath');
        $resolveTemplatePath->setAccessible(true);
        $generateCacheKey = $reflection->getMethod('generateCacheKey');
        $generateCacheKey->setAccessible(true);

        // Pekar cache till tempRootPath/cache/views/
        $cachePathProperty = $reflection->getProperty('cachePath');
        $cachePathProperty->setAccessible(true);
        $adjustedCachePath = $this->tempRootPath . 'cache/views/';
        $cachePathProperty->setValue($this->viewer, $adjustedCachePath);
        $this->createDirectoryIfNotExists($adjustedCachePath);

        $resolvedTemplatePath = $resolveTemplatePath->invoke($this->viewer, 'invalidate_test/template');
        $data = ['name' => 'InitialName'];
        $cacheKey = $generateCacheKey->invoke($this->viewer, $resolvedTemplatePath, $data);

        if (!is_string($cacheKey)) {
            $this->fail('cacheKey() must return string.');
        }

        /** @var string $cacheKey */
        $cachedFile = "$adjustedCachePath{$cacheKey}.php";

        // Rendera → cache ska skapas
        $this->viewer->render('invalidate_test/template', $data);
        $this->assertFileExists($cachedFile, "DEBUG: Cache file not created at expected path: {$cachedFile}");

        // Steg 2: Invalidera cachen
        $this->viewer->invalidateCache('invalidate_test/template', $data);
        $this->assertFileDoesNotExist($cachedFile, "DEBUG: Cache file was not deleted at path: {$cachedFile}");

        // Steg 3: Rendera om (med uppdaterad data)
        file_put_contents($templatePath, 'Hello {{ $name }}'); // säkerställ att template finns kvar
        $updatedData = ['name' => 'UpdatedName'];
        $output = $this->viewer->render('invalidate_test/template', $updatedData);
        $this->assertSame('Hello UpdatedName', $output);

        // Återställ APP_ENV
        if ($originalEnv === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $originalEnv);
        }
    }

    public function testRenderThrowsExceptionIfTemplateNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Template file not found');

        $this->viewer->render('nonexistent_view');
    }

    // tests/Feature/ViewTest.php
    public function testAlpineSyntaxIsRenderedCorrectly(): void
    {
        // Simulera rendering av vyn
        $templatePath = $this->tempViewsPath . 'example.ratio.php';
        file_put_contents($templatePath, '
            <div x-data="{ count: 0 }">
                <button x-on:click="count++">Click me</button>
                <span x-text="count"></span>
            </div>
        ');

        $html = $this->viewer->render('example');

        // Kontrollera att HTML-output innehåller Alpine.js-syntax
        $this->assertStringContainsString('x-data="{ count: 0 }"', $html);
        $this->assertStringContainsString('x-on:click="count++"', $html);
        $this->assertStringContainsString('x-text="count"', $html);
    }

    public function testRenderReturnsRenderedTemplate(): void
    {
        // Skapa en temporär template
        $templatePath = $this->tempViewsPath . 'temp_view.ratio.php';
        file_put_contents($templatePath, 'Hello {{ $name }}!');

        $output = $this->viewer->render('temp_view', ['name' => 'World']);
        $this->assertSame('Hello World!', $output);
    }

    public function testSharedDataIsAvailableGlobally(): void
    {
        // Skapa en temporär template
        $templatePath = $this->tempViewsPath . 'shared_data.ratio.php';
        file_put_contents($templatePath, 'Global variable: {{ $globalVar }}');

        $this->viewer->shared('globalVar', 'GlobalValue'); // Registrera global variabel

        // Verifiera att datan injiceras korrekt
        $output = $this->viewer->render('shared_data');
        $this->assertSame('Global variable: GlobalValue', $output, '[DEBUG] Global variabeln är otillgänglig.');
    }

    public function testGlobalDataAndSlotsWorkTogether(): void
    {
        // Skapa komponentfil
        $componentPath = "{$this->tempViewsPath}components/test_component.ratio.php";
        file_put_contents($componentPath, '<div class="container">{{ $globalVar }} - {{ slot }}</div>');

        // Registrera global variabel
        $this->viewer->shared('globalVar', 'GlobalValue');

        // Skapa template med komponent och slot
        $templatePath = "{$this->tempViewsPath}test_template.ratio.php";
        file_put_contents($templatePath, '<x-test_component>Slot Content</x-test_component>');

        // Rendera templaten
        $output = $this->viewer->render('test_template');

        $expectedOutput = '<div class="container">GlobalValue - Slot Content</div>';
        $this->assertSame($expectedOutput, $output, 'Globala data eller slot fungerar inte i komponenter.');
    }

    public function testExtendsDirectiveCombinesTemplates(): void
    {
        // Skapa layout och child templates
        $layoutPath = $this->tempViewsPath . 'layouts/main.ratio.php';
        $childPath = $this->tempViewsPath . 'home.ratio.php';

        mkdir(dirname($layoutPath), 0o755, true);
        file_put_contents($layoutPath, '<html>{% yield body %}</html>');
        file_put_contents($childPath, '{% extends "layouts/main.ratio.php" %}{% block body %}<h1>Hello!</h1>{% endblock %}');

        $output = $this->viewer->render('home');
        $this->assertSame('<html><h1>Hello!</h1></html>', $output);
    }

    public function testSlotsWorkWithAlpineSyntax(): void
    {
        // Skapa en temporär komponent med Alpine.js och slot
        $componentPath = "{$this->tempViewsPath}components/alert.ratio.php";
        file_put_contents($componentPath, '
            <div class="alert" x-data="{ show: true }">
                <button x-on:click="show = !show">Toggle</button>
                <div x-show="show">{{ slot }}</div>
            </div>
        ');

        // Kontrollera att komponentfilen skapades korrekt
        $this->assertFileExists($componentPath);

        // Skapa huvudtemplate som använder komponenten och skickar in en slot
        $templatePath = "{$this->tempViewsPath}test_template.ratio.php";
        file_put_contents($templatePath, '<x-alert>Slot Content Here</x-alert>');

        // Rendera din huvudtemplate
        $output = $this->viewer->render('test_template');

        // Kontrollera att både Alpine.js och slot-innehållet är korrekt renderade
        $this->assertStringContainsString('<div class="alert" x-data="{ show: true }">', $output);
        $this->assertStringContainsString('<button x-on:click="show = !show">Toggle</button>', $output);
        $this->assertStringContainsString('<div x-show="show">Slot Content Here</div>', $output);
    }

    public function testCachedTemplateIsUsedIfAvailable(): void
    {
        // Tvinga cache-läge
        $originalEnv = getenv('APP_ENV');
        putenv('APP_ENV=production');

        try {
            $mockCacheDir = $this->tempRootPath . 'cache/views/';
            if (!is_dir($mockCacheDir)) {
                mkdir($mockCacheDir, 0o755, true);
            }

            $reflection = new ReflectionClass($this->viewer);
            $cachePathProperty = $reflection->getProperty('cachePath');
            $cachePathProperty->setAccessible(true);
            $cachePathProperty->setValue($this->viewer, $mockCacheDir);

            // Slå på debug och injicera logger så vi kan verifiera debug-raden
            $this->viewer->enableDebugMode(true);

            $loggerProp = $reflection->getProperty('logger');
            $loggerProp->setAccessible(true);

            $testLogger = new TestViewLogger();
            $loggerProp->setValue($this->viewer, $testLogger);

            $resolveTemplatePath = $reflection->getMethod('resolveTemplatePath');
            $resolveTemplatePath->setAccessible(true);

            $generateCacheKey = $reflection->getMethod('generateCacheKey');
            $generateCacheKey->setAccessible(true);

            $mockTemplateName = 'test_template_key';

            // Skapa en minimal template-fil som render() kan hitta
            $resolvedTemplatePath = $resolveTemplatePath->invoke($this->viewer, $mockTemplateName);

            if (!is_string($resolvedTemplatePath)) {
                $this->fail('resolvedTemplatePath() must return string.');
            }

            /** @var string $resolvedTemplatePath */
            $templateFullPath = $this->tempViewsPath . $resolvedTemplatePath;

            if (!is_dir(dirname($templateFullPath))) {
                mkdir(dirname($templateFullPath), 0o755, true);
            }
            file_put_contents($templateFullPath, '<div>ORIGINAL</div>');

            $data = [];
            $mockCacheKey = $generateCacheKey->invoke($this->viewer, $resolvedTemplatePath, $data);

            if (!is_string($mockCacheKey)) {
                $this->fail('generateCacheKey() must return string.');
            }

            /** @var string $mockCacheKey */
            $mockCacheFile = $mockCacheDir . $mockCacheKey . '.php';

            // Skapa cachefilen som ska användas
            file_put_contents($mockCacheFile, '<div>Cached Content</div>');

            $output = $this->viewer->render($mockTemplateName, $data);
            $this->assertSame('<div>Cached Content</div>', $output, 'Cache användes inte korrekt.');

            // Dödar MethodCallRemoval-mutanten: debug-raden måste finnas
            $all = implode("\n", $testLogger->messages);
            $this->assertStringContainsString('Using cached template:', $all);
            $this->assertStringContainsString($mockCacheFile, $all);
        } finally {
            // Återställ APP_ENV
            if ($originalEnv === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $originalEnv);
            }
        }
    }

    public function testGenerateCacheKeyTreatsEmptyVersionAsDefaultVersion(): void
    {
        $reflection = new ReflectionClass($this->viewer);

        $resolveTemplatePath = $reflection->getMethod('resolveTemplatePath');
        $resolveTemplatePath->setAccessible(true);

        $generateCacheKey = $reflection->getMethod('generateCacheKey');
        $generateCacheKey->setAccessible(true);

        $templateLogicalName = 'version_default_test';
        /** @var string $resolved */
        $resolved = $resolveTemplatePath->invoke($this->viewer, $templateLogicalName);

        $templatePath = $this->tempViewsPath . $resolved;
        $this->createDirectoryIfNotExists(dirname($templatePath));
        file_put_contents($templatePath, 'Hello {{ $name }}');

        $data = ['name' => 'User'];

        // Tom versionssträng ska vara ekvivalent med 'default_version'
        /** @var string $keyEmpty */
        $keyEmpty = $generateCacheKey->invoke($this->viewer, $resolved, $data, '');
        /** @var string $keyDefault */
        $keyDefault = $generateCacheKey->invoke($this->viewer, $resolved, $data, 'default_version');

        $this->assertIsString($keyEmpty);
        $this->assertIsString($keyDefault);
        $this->assertSame(
            $keyEmpty,
            $keyDefault,
            'generateCacheKey ska behandla tom versionssträng som "default_version".'
        );
    }

    public function testGenerateCacheKeyDependsOnRunIdValueWhenSet(): void
    {
        $reflection = new ReflectionClass($this->viewer);

        $resolveTemplatePath = $reflection->getMethod('resolveTemplatePath');
        $resolveTemplatePath->setAccessible(true);

        $generateCacheKey = $reflection->getMethod('generateCacheKey');
        $generateCacheKey->setAccessible(true);

        $templateLogicalName = 'runid_diff_test';
        /** @var string $resolved */
        $resolved = $resolveTemplatePath->invoke($this->viewer, $templateLogicalName);

        $templatePath = $this->tempViewsPath . $resolved;
        $this->createDirectoryIfNotExists(dirname($templatePath));
        file_put_contents($templatePath, 'Hello {{ $name }}');

        $data = ['name' => 'User'];

        // Två olika RADIX_RUN_ID ska ge två olika nycklar
        putenv('RADIX_RUN_ID=run_1');
        /** @var string $key1 */
        $key1 = $generateCacheKey->invoke($this->viewer, $resolved, $data);

        putenv('RADIX_RUN_ID=run_2');
        /** @var string $key2 */
        $key2 = $generateCacheKey->invoke($this->viewer, $resolved, $data);

        // Städa upp
        putenv('RADIX_RUN_ID');

        $this->assertIsString($key1);
        $this->assertIsString($key2);
        $this->assertNotSame(
            $key1,
            $key2,
            'generateCacheKey ska påverkas av värdet i RADIX_RUN_ID.'
        );
    }

    public function testGlobalFiltersAreApplied(): void
    {
        $templatePath = $this->tempViewsPath . 'filter_view.ratio.php';
        file_put_contents($templatePath, '<p>{{ $message }}</p>');

        // Registrera ett globalt filter som gör texten versaler
        $this->viewer->registerFilter('uppercase', function (string $value): string {
            return strtoupper($value);
        });

        $output = $this->viewer->render('filter_view', ['message' => 'hello']);
        $this->assertSame('<p>HELLO</p>', $output);
    }

    public function testRenderComponentWithAttributesAndSlot(): void
    {
        $componentPath = "{$this->tempViewsPath}components/alert.ratio.php";
        $this->createDirectoryIfNotExists(dirname($componentPath));
        file_put_contents($componentPath, '<div class="alert {{ $type }}">{{ $slot }}</div>');

        $this->assertFileExists($componentPath, '[DEBUG] Komponentfilen saknas: ' . $componentPath);

        $templatePath = "{$this->tempViewsPath}test_template.ratio.php";
        file_put_contents($templatePath, '<x-alert type="warning">This is an alert</x-alert>');

        $output = $this->viewer->render('test_template');
        $expectedOutput = '<div class="alert warning">This is an alert</div>';

        // Lägg till diff-verktyg för felutskrifter
        $this->assertSame(
            $expectedOutput,
            $output,
            sprintf(
                "[DEBUG] Mismatch mellan förväntad och faktisk output.\nFörväntat:\n%s\nFaktiskt:\n%s",
                htmlspecialchars($expectedOutput),
                htmlspecialchars($output)
            )
        );
    }

    public function testRenderComponentWithoutSlot(): void
    {
        // Placera komponentfilen i views/components/
        $componentPath = "{$this->tempViewsPath}components/button.ratio.php";
        $this->createDirectoryIfNotExists(dirname($componentPath));
        file_put_contents($componentPath, '<button class="btn {{ $type }}">{{ $label }}</button>');

        // Kontrollera att filen skapades
        $this->assertFileExists($componentPath, '[DEBUG] Komponentfilen saknas: ' . $componentPath);

        // Skapa huvudtemplate
        $templatePath = "{$this->tempViewsPath}test_template.ratio.php";
        file_put_contents($templatePath, '<x-button type="primary" label="Click Me"></x-button>');

        // Rendera template
        $output = $this->viewer->render('test_template');

        // Kontrollera renderingens resultat
        $expectedOutput = '<button class="btn primary">Click Me</button>';
        $this->assertSame($expectedOutput, $output, '[DEBUG] Rendering av <x-button> är felaktig.');
    }

    public function testNestedComponentsRenderCorrectly(): void
    {
        $wrapperPath = "{$this->tempViewsPath}components/wrapper.ratio.php";
        file_put_contents(
            $wrapperPath,
            '<div class="wrapper">{{ slot }}</div>'
        );

        $alertPath = "{$this->tempViewsPath}components/alert.ratio.php";
        file_put_contents(
            $alertPath,
            '<div class="alert {{ $type }}">{{ slot }}</div>'
        );

        $templatePath = "{$this->tempViewsPath}test_template.ratio.php";
        file_put_contents(
            $templatePath,
            '<x-wrapper><x-alert type="info">Nested content</x-alert></x-wrapper>'
        );

        $output = $this->viewer->render('test_template');

        // Ändra den förväntade outputen till en kompakt sträng
        $expectedOutput = '<div class="wrapper"><div class="alert info">Nested content</div></div>';

        $this->assertSame(
            $expectedOutput,
            trim($output),
            'Nested components render incorrectly in wrapper.'
        );
    }

    public function testComponentWithDynamicAttributes(): void
    {
        // Placera komponentfilen i views/components/
        $componentPath = "{$this->tempViewsPath}components/card.ratio.php";
        $this->createDirectoryIfNotExists(dirname($componentPath));
        file_put_contents($componentPath, '<div class="card {{ $class }}" style="{{ $style }}">{{ $slot }}</div>');

        // Kontrollera att filen skapades
        $this->assertFileExists($componentPath, '[DEBUG] Komponentfilen saknas: ' . $componentPath);

        // Skapa huvudtemplate
        $templatePath = "{$this->tempViewsPath}test_template.ratio.php";
        file_put_contents($templatePath, '<x-card class="highlight" style="color: red;">This is a card</x-card>');

        // Rendera template
        $output = $this->viewer->render('test_template');

        // Kontrollera renderingens resultat
        $expectedOutput = '<div class="card highlight" style="color: red;">This is a card</div>';
        $this->assertSame($expectedOutput, $output, '[DEBUG] Rendering av <x-card> är felaktig.');
    }

    public function testEvaluateTemplateThrowsRuntimeExceptionWithClearMessage(): void
    {
        $templatePath = $this->tempViewsPath . 'broken.ratio.php';
        file_put_contents($templatePath, '<h1>{{ $message }}</h1><?php throw new \Exception("Boom"); ?>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Template evaluation failed: Boom');

        $this->viewer->render('broken', ['message' => 'Hello']);
    }

    public function testMissingComponentThrowsClearRuntimeException(): void
    {
        $templatePath = $this->tempViewsPath . 'uses_missing_component.ratio.php';
        file_put_contents($templatePath, '<x-nonexistent>Content</x-nonexistent>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Komponent fil saknas:');

        $this->viewer->render('uses_missing_component');
    }

    public function testCacheDisabledInDevelopmentEnv(): void
    {
        // Ställ om APP_ENV till development och säkerställ att cache inte används
        $originalEnv = getenv('APP_ENV');
        putenv('APP_ENV=development');

        $template = $this->tempViewsPath . 'nocache.ratio.php';
        file_put_contents($template, 'Value: {{ $val }}');

        $out1 = $this->viewer->render('nocache', ['val' => 'A']);
        $this->assertSame('Value: A', $out1);

        // Ändra template-innehåll – om cache är avstängd ska vi få nytt resultat direkt
        file_put_contents($template, 'Value: {{ $val }}-X');

        $out2 = $this->viewer->render('nocache', ['val' => 'B']);
        $this->assertSame('Value: B-X', $out2);

        // Återställ APP_ENV
        if ($originalEnv === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $originalEnv);
        }
    }

    public function testClearOldCacheFilesRemovesOnlyTooOldFilesAndSkipsDirectoriesAndBoundary(): void
    {
        $reflection = new ReflectionClass($this->viewer);

        // Sätt cachePath till en separat tempkatalog för just det här testet
        $cachePathProperty = $reflection->getProperty('cachePath');
        $cachePathProperty->setAccessible(true);

        $cacheDir = $this->tempRootPath . 'cache/views/';
        $this->createDirectoryIfNotExists($cacheDir);
        $cachePathProperty->setValue($this->viewer, $cacheDir);

        $now = time();
        $maxAge = 10;

        // 1) Fil äldre än maxAge -> ska tas bort
        $oldFile = $cacheDir . 'old.php';
        file_put_contents($oldFile, 'old');
        touch($oldFile, $now - 11);

        // 2) Fil exakt på gränsen -> ska INTE tas bort (dödar GreaterThan-mutanten)
        $borderFile = $cacheDir . 'border.php';
        file_put_contents($borderFile, 'border');
        touch($borderFile, $now - 10);

        // 3) Ny fil -> ska INTE tas bort
        $youngFile = $cacheDir . 'young.php';
        file_put_contents($youngFile, 'young');
        touch($youngFile, $now - 5);

        // 4) Undermapp med fil -> katalogen ska ignoreras (dödar IfNegation-mutanten)
        $subDir = $cacheDir . 'subdir';
        $this->createDirectoryIfNotExists($subDir);
        $insideFile = $subDir . '/inside.php';
        file_put_contents($insideFile, 'inside');
        touch($insideFile, $now - 20);

        // Anropa privata clearOldCacheFiles(int $maxAgeInSeconds, ?int $now) via reflection
        $clearMethod = $reflection->getMethod('clearOldCacheFiles');
        $clearMethod->setAccessible(true);
        $clearMethod->invoke($this->viewer, $maxAge, $now);

        // Om continue->break eller LogicalOrNegation-mutanten aktiveras
        // kommer ingen riktig fil rensas -> old.php finns kvar -> testet failar.

        // 1) Gammal fil ska vara borttagen
        $this->assertFileDoesNotExist(
            $oldFile,
            'Filer äldre än maxAge ska tas bort av clearOldCacheFiles().'
        );

        // 2) Fil exakt på gränsen ska finnas kvar
        $this->assertFileExists(
            $borderFile,
            'Filer med ålder exakt lika med maxAge ska inte tas bort.'
        );

        // 3) Ny fil ska finnas kvar
        $this->assertFileExists(
            $youngFile,
            'Filer yngre än maxAge ska inte tas bort.'
        );

        // 4) Undermapp och dess fil ska finnas kvar
        $this->assertDirectoryExists(
            $subDir,
            'Kataloger i cache-katalogen ska inte tas bort av clearOldCacheFiles().'
        );
        $this->assertFileExists(
            $insideFile,
            'Filer i undermappar ska lämnas orörda av clearOldCacheFiles().'
        );
    }

    public function testStringFilterIsNotAppliedToNonStringValues(): void
    {
        // Registrera ett filter som ENDAST accepterar strängar
        $this->viewer->registerFilter('string_only', function (string $value): string {
            // Om detta någonsin får en icke-sträng kommer PHP att kasta TypeError,
            // vilket gör att mutanten (&& -> ||) avslöjas.
            return 'FILTERED:' . $value;
        });

        // Template som använder en array-nyckel från $arr
        $templatePath = $this->tempViewsPath . 'string_filter_non_string.ratio.php';
        file_put_contents(
            $templatePath,
            'Value: {{ $arr["name"] }}'
        );

        // $arr är en ARRAY – filtret med type 'string' ska INTE appliceras på hela arrayen
        $output = $this->viewer->render('string_filter_non_string', [
            'arr' => ['name' => 'John'],
        ]);

        // Om filtret felaktigt appliceras på arrayen kastas TypeError och testet failar.
        $this->assertSame('Value: John', $output);
    }

    public function testApplyFiltersKeepsAllDataEntries(): void
    {
        // Filter som gör alla strängvärden versaler
        $this->viewer->registerFilter('uppercase', function (string $value): string {
            return strtoupper($value);
        });

        $templatePath = $this->tempViewsPath . 'multi_filter_view.ratio.php';
        file_put_contents(
            $templatePath,
            'A: {{ $a }}, B: {{ $b }}'
        );

        $output = $this->viewer->render('multi_filter_view', [
            'a' => 'foo',
            'b' => 'bar',
        ]);

        // ArrayOneItem-mutanten skulle kapa bort ena nyckeln ur $data efter filtrering.
        $this->assertSame('A: FOO, B: BAR', $output);
    }

    public function testClearOldCacheFilesHasDefaultMaxAgeOfOneDay(): void
    {
        $reflection = new ReflectionClass($this->viewer);

        $m = $reflection->getMethod('clearOldCacheFiles');

        $params = $m->getParameters();
        $this->assertCount(2, $params);

        $maxAgeParam = $params[0];
        $this->assertTrue($maxAgeParam->isDefaultValueAvailable());

        // Dödar IncrementInteger/DecrementInteger på defaultvärdet 86400
        $this->assertSame(
            86400,
            $maxAgeParam->getDefaultValue(),
            'Default maxAgeInSeconds ska vara 86400 (1 dag).'
        );
    }

    public function testApplyFiltersRespectValueTypesForStringArrayAndObject(): void
    {
        $reflection = new ReflectionClass($this->viewer);
        $applyFilters = $reflection->getMethod('applyFilters');
        $applyFilters->setAccessible(true);

        // 1) String-filter – ska bara appliceras på strängar
        $this->viewer->registerFilter(
            'string_filter',
            function (string $value): string {
                return 'S:' . $value;
            },
            'string'
        );

        // 2) Array-filter – ska bara appliceras på arrays
        $this->viewer->registerFilter(
            'array_filter',
            function (array $value): array {
                $value['__filtered'] = true;
                return $value;
            },
            'array'
        );

        // 3) Object-filter – ska bara appliceras på objekt
        $this->viewer->registerFilter(
            'object_filter',
            /**
             * @param MarkableObject $value
             */
            function (MarkableObject $value): MarkableObject {
                $value->marked = true;
                return $value;
            },
            'object'
        );

        $obj = new MarkableObject();

        $input = [
            'str' => 'hello',
            'arr' => ['x' => 1],
            'obj' => $obj,
        ];

        /** @var array<string,mixed> $result */
        $result = $applyFilters->invoke($this->viewer, $input);

        // Stringfilter ska ha applicerats ENDAST på strängvärdet
        $this->assertSame('S:hello', $result['str']);

        // Arrayfilter ska ha applicerats ENDAST på array-värdet
        $this->assertIsArray($result['arr']);
        $this->assertArrayHasKey('__filtered', $result['arr']);
        $this->assertTrue($result['arr']['__filtered']);

        // Objectfilter ska ha applicerats ENDAST på objekt-värdet
        $this->assertInstanceOf(MarkableObject::class, $result['obj']);
        /** @var MarkableObject $markedObj */
        $markedObj = $result['obj'];
        $this->assertTrue($markedObj->marked);

        // Viktigt: om LogicalAnd-mutanten gör att object-grenen
        // körs även när expectedType != 'object', så kommer t.ex.
        // string_filter att få ett objekt och kasta TypeError ->
        // detta test kommer att fallera och döda mutanten.
    }

    public function testGenerateCacheKeyDependsOnBothTemplateDataAndAssetTimestamps(): void
    {
        $reflection = new ReflectionClass($this->viewer);

        $resolveTemplatePath = $reflection->getMethod('resolveTemplatePath');
        $resolveTemplatePath->setAccessible(true);

        $generateCacheKey = $reflection->getMethod('generateCacheKey');
        $generateCacheKey->setAccessible(true);

        // 1) Skapa en enkel template-fil
        $templateLogicalName = 'cache_key_test';
        /** @var string $resolved */
        $resolved = $resolveTemplatePath->invoke($this->viewer, $templateLogicalName);

        $templatePath = $this->tempViewsPath . $resolved;
        $this->createDirectoryIfNotExists(dirname($templatePath));
        file_put_contents($templatePath, 'Hello {{ $name }}');

        // 2) Skapa CSS/JS under ROOT_PATH/public
        $publicCssDir = ROOT_PATH . '/public/css';
        $publicJsDir  = ROOT_PATH . '/public/js';
        $this->createDirectoryIfNotExists($publicCssDir);
        $this->createDirectoryIfNotExists($publicJsDir);

        $cssPath = $publicCssDir . '/app.css';
        $jsPath  = $publicJsDir . '/app.js';

        file_put_contents($cssPath, 'body {}');
        file_put_contents($jsPath, 'console.log("x");');

        // Sätt stabil tidsstämpel
        $t1 = time();
        touch($cssPath, $t1);
        touch($jsPath, $t1);

        // 3) Nyckel med första datamängden – pagination page = 1
        $data1 = [
            'name' => 'User',
            'pagination' => ['page' => 1],
        ];
        /** @var string $key1 */
        $key1 = $generateCacheKey->invoke($this->viewer, $resolved, $data1);

        // 4) Ändra endast pagination (relevantParts) och beräkna nyckel igen – page = 2
        $data2 = [
            'name' => 'User',
            'pagination' => ['page' => 2],
        ];
        /** @var string $key2 */
        $key2 = $generateCacheKey->invoke($this->viewer, $resolved, $data2);

        $this->assertNotSame(
            $key1,
            $key2,
            'generateCacheKey ska påverkas av template-data (t.ex. pagination).'
        );

        // 5) Samma data som key2, men ändra bara CSS-mtime (additionalHashes)
        $t2 = $t1 + 10;
        touch($cssPath, $t2);
        // JS lämnas oförändrad – det räcker att en av dem ändras
        /** @var string $key3 */
        $key3 = $generateCacheKey->invoke($this->viewer, $resolved, $data2);

        $this->assertNotSame(
            $key2,
            $key3,
            'generateCacheKey ska påverkas av CSS/JS-tidsstämplar (additionalHashes).'
        );
    }

    public function testGenerateCacheKeyChangesWhenVersionChanges(): void
    {
        $reflection = new ReflectionClass($this->viewer);

        $resolveTemplatePath = $reflection->getMethod('resolveTemplatePath');
        $resolveTemplatePath->setAccessible(true);

        $generateCacheKey = $reflection->getMethod('generateCacheKey');
        $generateCacheKey->setAccessible(true);

        $templateLogicalName = 'version_key_test';
        /** @var string $resolved */
        $resolved = $resolveTemplatePath->invoke($this->viewer, $templateLogicalName);

        $templatePath = $this->tempViewsPath . $resolved;
        $this->createDirectoryIfNotExists(dirname($templatePath));
        file_put_contents($templatePath, 'Hello {{ $name }}');

        $data = ['name' => 'User'];

        /** @var string $keyDefault */
        $keyDefault = $generateCacheKey->invoke($this->viewer, $resolved, $data, '');
        /** @var string $keyV1 */
        $keyV1 = $generateCacheKey->invoke($this->viewer, $resolved, $data, 'v1');

        $this->assertIsString($keyDefault);
        $this->assertIsString($keyV1);
        $this->assertNotSame(
            $keyDefault,
            $keyV1,
            'generateCacheKey ska påverkas av versionssträngen.'
        );
    }

    public function testGenerateCacheKeyIncludesRunIdWhenSet(): void
    {
        $reflection = new ReflectionClass($this->viewer);

        $resolveTemplatePath = $reflection->getMethod('resolveTemplatePath');
        $resolveTemplatePath->setAccessible(true);

        $generateCacheKey = $reflection->getMethod('generateCacheKey');
        $generateCacheKey->setAccessible(true);

        $templateLogicalName = 'runid_key_test';
        /** @var string $resolved */
        $resolved = $resolveTemplatePath->invoke($this->viewer, $templateLogicalName);

        $templatePath = $this->tempViewsPath . $resolved;
        $this->createDirectoryIfNotExists(dirname($templatePath));
        file_put_contents($templatePath, 'Hello {{ $name }}');

        $data = ['name' => 'User'];

        // Ingen RADIX_RUN_ID
        putenv('RADIX_RUN_ID');
        /** @var string $keyWithoutRunId */
        $keyWithoutRunId = $generateCacheKey->invoke($this->viewer, $resolved, $data);

        // Med RADIX_RUN_ID
        putenv('RADIX_RUN_ID=test-run-123');
        /** @var string $keyWithRunId */
        $keyWithRunId = $generateCacheKey->invoke($this->viewer, $resolved, $data);

        // Städa upp env
        putenv('RADIX_RUN_ID');

        $this->assertIsString($keyWithoutRunId);
        $this->assertIsString($keyWithRunId);
        $this->assertNotSame(
            $keyWithoutRunId,
            $keyWithRunId,
            'generateCacheKey ska påverkas av RADIX_RUN_ID när den är satt.'
        );
    }

    public function testClearOldCacheFilesHonorsExplicitNowParameter(): void
    {
        $reflection = new ReflectionClass($this->viewer);

        $cachePathProperty = $reflection->getProperty('cachePath');
        $cachePathProperty->setAccessible(true);

        $cacheDir = $this->tempRootPath . 'cache/honors_now/';
        $this->createDirectoryIfNotExists($cacheDir);
        $cachePathProperty->setValue($this->viewer, $cacheDir);

        // Skapa en fil som ENDAST rensas om clearOldCacheFiles använder det explicita $now-värdet
        $file = $cacheDir . 'keep.php';
        file_put_contents($file, 'x');

        // Sätt mtime så att filen är yngre än maxAge relativt vårt "fakeNow"
        $fakeNow = 1000;
        touch($file, $fakeNow - 5); // ålder 5 sekunder relativt fakeNow
        $maxAge = 10;

        $clearMethod = $reflection->getMethod('clearOldCacheFiles');
        $clearMethod->setAccessible(true);

        // Om implementationen använder $now-parametern korrekt (1000),
        // ska filen INTE rensas (5 <= 10).
        // Mutanten ($now = time() ?? $now) kommer istället att använda time(),
        // vilket ger en mycket större ålder och därmed rensar filen.
        $clearMethod->invoke($this->viewer, $maxAge, $fakeNow);

        $this->assertFileExists(
            $file,
            'clearOldCacheFiles() ska respektera explicit $now-parameter och inte använda time() istället.'
        );
    }

    public function testApplyFiltersWithoutFiltersReturnsDataUnchanged(): void
    {
        $reflection = new ReflectionClass($this->viewer);
        $applyFilters = $reflection->getMethod('applyFilters');
        $applyFilters->setAccessible(true);

        $input = ['a' => 'one', 'b' => 'two'];

        /** @var array<string,mixed> $result */
        $result = $applyFilters->invoke($this->viewer, $input);

        // ArrayOneItem-mutanten skulle plocka bort alla utom första nyckeln
        $this->assertSame($input, $result);
    }

    public function testGetSearchKeyReturnsTermAndPageWhenSearchArrayPresent(): void
    {
        $reflection = new ReflectionClass($this->viewer);
        $getSearchKey = $reflection->getMethod('getSearchKey');
        $getSearchKey->setAccessible(true);

        $data = [
            'search' => [
                'term' => 'foo',
                'current_page' => 3,
            ],
        ];

        /** @var array<string,mixed> $result */
        $result = $getSearchKey->invoke($this->viewer, $data);

        // Rätt beteende: term + current_page returneras
        $this->assertSame(
            ['term' => 'foo', 'current_page' => 3],
            $result
        );
    }

    public function testGetSearchKeyDefaultsCurrentPageToOneWhenMissing(): void
    {
        $reflection = new ReflectionClass($this->viewer);
        $getSearchKey = $reflection->getMethod('getSearchKey');
        $getSearchKey->setAccessible(true);

        // current_page saknas → ska defaulta till 1
        $data = [
            'search' => [
                'term' => 'bar',
                // ingen current_page
            ],
        ];

        /** @var array<string,mixed> $result */
        $result = $getSearchKey->invoke($this->viewer, $data);

        $this->assertSame('bar', $result['term'] ?? null);
        $this->assertSame(
            1,
            $result['current_page'] ?? null,
            'current_page ska defaulta till 1 när den saknas.'
        );
    }

    public function testGetPaginationKeyReturnsEmptyArrayWhenPaginationMissingOrInvalid(): void
    {
        $reflection = new ReflectionClass($this->viewer);
        $getPaginationKey = $reflection->getMethod('getPaginationKey');
        $getPaginationKey->setAccessible(true);

        // 1) Ingen pagination-nyckel alls
        $noPagination = $getPaginationKey->invoke($this->viewer, []);
        $this->assertSame(
            [],
            $noPagination,
            'Utan pagination-nyckel ska getPaginationKey returnera tom array.'
        );

        // 2) pagination finns men är inte array
        $invalidPagination = $getPaginationKey->invoke($this->viewer, ['pagination' => 'not-an-array']);
        $this->assertSame(
            [],
            $invalidPagination,
            'Om pagination inte är en array ska getPaginationKey returnera tom array.'
        );
    }

    public function testGetPaginationKeyDefaultsPageToOneWhenMissing(): void
    {
        $reflection = new ReflectionClass($this->viewer);
        $getPaginationKey = $reflection->getMethod('getPaginationKey');
        $getPaginationKey->setAccessible(true);

        $data = [
            'pagination' => [
                // ingen 'page' -> ska defaulta till 1
            ],
        ];

        /** @var array<string,int> $result */
        $result = $getPaginationKey->invoke($this->viewer, $data);

        $this->assertSame(
            ['page' => 1],
            $result,
            'page ska defaulta till 1 när den saknas i pagination.'
        );
    }

    public function testGetPaginationKeyCastsNumericStringPageToInt(): void
    {
        $reflection = new ReflectionClass($this->viewer);
        $getPaginationKey = $reflection->getMethod('getPaginationKey');
        $getPaginationKey->setAccessible(true);

        $data = [
            'pagination' => [
                'page' => '5', // numerisk sträng
            ],
        ];

        /** @var array<string,int> $result */
        $result = $getPaginationKey->invoke($this->viewer, $data);

        // Vi kräver att 'page' är en INT 5, inte en sträng '5'
        $this->assertSame(
            ['page' => 5],
            $result,
            'Numeric string för page ska castas till int 5.'
        );
    }

    public function testDebugLogsMessagesOnlyWhenDebugModeEnabled(): void
    {
        $this->viewer->enableDebugMode(true);

        $reflection = new ReflectionClass($this->viewer);
        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);

        $testLogger = new TestViewLogger();
        $loggerProp->setValue($this->viewer, $testLogger);

        $debugMethod = $reflection->getMethod('debug');
        $debugMethod->setAccessible(true);
        $debugMethod->invoke($this->viewer, 'TEST-MESSAGE');

        // När debug=true ska debug() logga något
        $this->assertNotEmpty(
            $testLogger->messages,
            'debug() ska logga när debug-läget är aktiverat.'
        );
        $this->assertSame('TEST-MESSAGE', $testLogger->messages[0] ?? null);
    }

    public function testDebugDoesNotLogWhenDebugModeDisabled(): void
    {
        $this->viewer->enableDebugMode(false);

        $reflection = new ReflectionClass($this->viewer);
        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);

        $testLogger = new TestViewLogger();
        $loggerProp->setValue($this->viewer, $testLogger);

        $debugMethod = $reflection->getMethod('debug');
        $debugMethod->setAccessible(true);
        $debugMethod->invoke($this->viewer, 'SHOULD-NOT-LOG');

        // När debug=false ska debug() INTE logga något
        $this->assertSame(
            [],
            $testLogger->messages,
            'debug() ska inte logga när debug-läget är av.'
        );
    }

    private function createDirectoryIfNotExists(string $path): void
    {
        if (!is_dir($path)) {
            $ok = @mkdir($path, 0o755, true);
            if (!$ok && !is_dir($path)) {
                throw new RuntimeException('Kunde inte skapa katalog: ' . $path);
            }
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($p)) {
                $this->deleteDirectory($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    private function normalizeOutput(string $output): string
    {
        // Tar bort överflödiga radbrytningar och mellanslag mellan HTML-taggar
        $normalized = preg_replace('/\s*(<[^>]+>)\s*/', '$1', trim($output));

        // preg_replace kan returnera null vid regex-fel, säkerställ alltid string
        if ($normalized === null) {
            return '';
        }

        return $normalized;
    }

    public function testCachedTemplateCanAccessDataVariablesViaExtract(): void
    {
        // Tvinga cache-läge
        $originalEnv = getenv('APP_ENV');
        putenv('APP_ENV=production');

        try {
            $cacheDir = $this->tempRootPath . 'cache/views/';
            $this->createDirectoryIfNotExists($cacheDir);

            $reflection = new ReflectionClass($this->viewer);

            $cachePathProperty = $reflection->getProperty('cachePath');
            $cachePathProperty->setAccessible(true);
            $cachePathProperty->setValue($this->viewer, $cacheDir);

            $resolveTemplatePath = $reflection->getMethod('resolveTemplatePath');
            $resolveTemplatePath->setAccessible(true);

            $generateCacheKey = $reflection->getMethod('generateCacheKey');
            $generateCacheKey->setAccessible(true);

            $templateLogicalName = 'cached_uses_data_vars';

            /** @var string $resolved */
            $resolved = $resolveTemplatePath->invoke($this->viewer, $templateLogicalName);

            // Template-filen måste finnas så render() tar sig fram till cache-logiken
            $templatePath = $this->tempViewsPath . $resolved;
            $this->createDirectoryIfNotExists(dirname($templatePath));
            file_put_contents($templatePath, 'ORIGINAL');

            $data = ['name' => 'Alice'];

            /** @var string $key */
            $key = $generateCacheKey->invoke($this->viewer, $resolved, $data);

            $cachedFile = $cacheDir . $key . '.php';

            // Cachefilen använder $name som kommer från $data via extract()
            file_put_contents($cachedFile, '<?php echo "Hello " . $name;');

            $out = $this->viewer->render($templateLogicalName, $data);

            $this->assertSame('Hello Alice', $out);
        } finally {
            // Återställ APP_ENV
            if ($originalEnv === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $originalEnv);
            }
        }
    }

    public function testViewsCachePathEnvVarAbsoluteIsUsedAsIs(): void
    {
        $original = getenv('VIEWS_CACHE_PATH');

        $abs = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'radix_views_cache_' . uniqid('', true);
        $expected = rtrim($abs, '/\\') . DIRECTORY_SEPARATOR;

        try {
            putenv('VIEWS_CACHE_PATH=' . $abs);

            $viewer = new RadixTemplateViewer($this->tempViewsPath);

            $reflection = new ReflectionClass($viewer);
            $cachePathProperty = $reflection->getProperty('cachePath');
            $cachePathProperty->setAccessible(true);

            $cachePathMixed = $cachePathProperty->getValue($viewer);
            $this->assertIsString($cachePathMixed);

            /** @var string $cachePath */
            $cachePath = $cachePathMixed;

            // Normalisera separators för stabil jämförelse på Windows
            $normalizedCachePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cachePath);
            $normalizedCachePath = rtrim($normalizedCachePath, '/\\') . DIRECTORY_SEPARATOR;

            $this->assertSame(
                $expected,
                $normalizedCachePath,
                'När VIEWS_CACHE_PATH är absolut ska cachePath användas som den är (normaliserad, med trailing separator).'
            );

            $defaultTail = DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR;
            $this->assertFalse(
                str_ends_with($normalizedCachePath, $defaultTail),
                'När VIEWS_CACHE_PATH är absolut ska default cache/views inte användas.'
            );
        } finally {
            if ($original === false) {
                putenv('VIEWS_CACHE_PATH');
            } else {
                putenv('VIEWS_CACHE_PATH=' . $original);
            }

            // Städa upp om katalogen råkat skapas någonstans
            if (is_dir($abs)) {
                @rmdir($abs);
            }
        }
    }

    public function testViewsCachePathEnvVarCacheIsRedirectedToCacheViewsDirectory(): void
    {
        $original = getenv('VIEWS_CACHE_PATH');

        try {
            // Detta gör att första beräkningen hamnar på ROOT_PATH/cache/
            // och då ska skyddet i konstruktorn styra om till ROOT_PATH/cache/views/
            putenv('VIEWS_CACHE_PATH=cache');

            $viewer = new RadixTemplateViewer($this->tempViewsPath);

            $reflection = new ReflectionClass($viewer);
            $cachePathProperty = $reflection->getProperty('cachePath');
            $cachePathProperty->setAccessible(true);

            $cachePathMixed = $cachePathProperty->getValue($viewer);
            $this->assertIsString($cachePathMixed);

            /** @var string $cachePath */
            $cachePath = $cachePathMixed;

            $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cachePath);
            $normalized = rtrim($normalized, '/\\') . DIRECTORY_SEPARATOR;

            $expectedTail = DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR;

            $this->assertStringEndsWith(
                $expectedTail,
                $normalized,
                'Om VIEWS_CACHE_PATH="cache" ska konstruktorn styra om cachePath till .../cache/views/.'
            );
        } finally {
            if ($original === false) {
                putenv('VIEWS_CACHE_PATH');
            } else {
                putenv('VIEWS_CACHE_PATH=' . $original);
            }
        }
    }

    public function testCacheTemplateDebugMessageContainsTargetPathAndMinifyFlag(): void
    {
        $originalEnv = getenv('APP_ENV');

        try {
            putenv('APP_ENV=production');

            $viewer = new RadixTemplateViewer($this->tempViewsPath);
            $viewer->enableDebugMode(true);

            // Injicera logger som samlar debug-meddelanden (ingen fil-I/O)
            $reflection = new ReflectionClass($viewer);
            $loggerProp = $reflection->getProperty('logger');
            $loggerProp->setAccessible(true);

            $testLogger = new TestViewLogger();
            $loggerProp->setValue($viewer, $testLogger);

            // Skapa template så render() går hela vägen till cacheTemplate()
            $templatePath = $this->tempViewsPath . 'dbg_cache_write.ratio.php';
            file_put_contents($templatePath, 'Hello');

            // Rendera så att cache skrivs
            $viewer->render('dbg_cache_write');

            $all = implode("\n", $testLogger->messages);

            $this->assertStringContainsString(
                'Writing cache file to:',
                $all,
                'cacheTemplate() ska logga vart den skriver cachefilen.'
            );

            // Den här delen dödar ConcatOperandRemoval-mutanten som tappar prefixet
            $this->assertStringContainsString(
                '(Minify: YES)',
                $all,
                'cacheTemplate() ska logga "(Minify: YES)" i production.'
            );
        } finally {
            if ($originalEnv === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $originalEnv);
            }
        }
    }

    public function testMinifyPhpPreservesPhpBlocksVerbatim(): void
    {
        $viewer = new RadixTemplateViewer($this->tempViewsPath);

        $reflection = new ReflectionClass($viewer);
        $m = $reflection->getMethod('minifyPHP');
        $m->setAccessible(true);

        $phpBlock = '<?php echo "X"; ?>';
        $input = "<div>\n  A\n  {$phpBlock}\n  B\n</div>\n";

        $outMixed = $m->invoke($viewer, $input);
        $this->assertIsString($outMixed);

        /** @var string $out */
        $out = $outMixed;

        // Om $matches[0] muteras till $matches[1] blir php-blocket inte bevarat korrekt
        $this->assertStringContainsString(
            $phpBlock,
            $out,
            'minifyPHP() måste bevara hela PHP-blocket exakt.'
        );
    }

    public function testExtractNamedSlotsDebugLogIncludesSlotsDump(): void
    {
        $viewer = new RadixTemplateViewer($this->tempViewsPath);
        $viewer->enableDebugMode(true);

        $reflection = new ReflectionClass($viewer);

        // Injicera testlogger så vi kan läsa debug-output
        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);

        $testLogger = new TestViewLogger();
        $loggerProp->setValue($viewer, $testLogger);

        // Anropa privata extractNamedSlots()
        $m = $reflection->getMethod('extractNamedSlots');
        $m->setAccessible(true);

        $slotContent = '<x-slot:header>Header Content</x-slot:header>Body';

        /** @var array<string,string> $slots */
        $slots = $m->invokeArgs($viewer, [&$slotContent]);

        // Sanity: vi fick faktiskt ut sloten
        $this->assertSame(['header' => 'Header Content'], $slots);

        $all = implode("\n", $testLogger->messages);

        // Dödar MethodCallRemoval på per-slot debug-raden
        $this->assertStringContainsString('Extraherad slot: header', $all);
        $this->assertStringContainsString('Header Content', $all);

        // Dödar ConcatOperandRemoval på slutdumpen (print_r)
        $this->assertStringContainsString('Extraherade slots:', $all);
        $this->assertStringContainsString('header', $all);
    }

    public function testGetFilterKeyDependsOnFilterNamesNotOnlyTypes(): void
    {
        $viewerA = new RadixTemplateViewer($this->tempViewsPath);
        $viewerB = new RadixTemplateViewer($this->tempViewsPath);

        // Samma type ("string"), men olika namn => ska ge olika filterKey
        $viewerA->registerFilter('uppercase_a', fn(string $v): string => strtoupper($v), 'string');
        $viewerB->registerFilter('uppercase_b', fn(string $v): string => strtoupper($v), 'string');

        $refA = new ReflectionClass($viewerA);
        $mA = $refA->getMethod('getFilterKey');
        $mA->setAccessible(true);

        $refB = new ReflectionClass($viewerB);
        $mB = $refB->getMethod('getFilterKey');
        $mB->setAccessible(true);

        $keyAMixed = $mA->invoke($viewerA);
        $keyBMixed = $mB->invoke($viewerB);

        $this->assertIsString($keyAMixed);
        $this->assertIsString($keyBMixed);

        /** @var string $keyA */
        $keyA = $keyAMixed;
        /** @var string $keyB */
        $keyB = $keyBMixed;

        $this->assertNotSame(
            $keyA,
            $keyB,
            'getFilterKey() ska påverkas av filternamn. Annars kan olika filter kollidera i cache.'
        );
    }

    public function testDefaultCachePathIsUnderRootPathWhenViewsCachePathEnvMissing(): void
    {
        $original = getenv('VIEWS_CACHE_PATH');

        try {
            // Säkerställ default-branch
            putenv('VIEWS_CACHE_PATH');

            $viewer = new RadixTemplateViewer($this->tempViewsPath);

            $reflection = new ReflectionClass($viewer);
            $cachePathProperty = $reflection->getProperty('cachePath');
            $cachePathProperty->setAccessible(true);

            $cachePathMixed = $cachePathProperty->getValue($viewer);
            $this->assertIsString($cachePathMixed);

            /** @var string $cachePath */
            $cachePath = $cachePathMixed;

            $normalizedCachePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cachePath);
            $normalizedCachePath = rtrim($normalizedCachePath, '/\\') . DIRECTORY_SEPARATOR;

            // ROOT_PATH kan redan vara definierad av bootstrap och går inte att re-definiera här.
            $expectedRoot = defined('ROOT_PATH')
                ? (string) ROOT_PATH
                : $this->tempRootPath;

            $normalizedRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $expectedRoot);
            $normalizedRoot = rtrim($normalizedRoot, '/\\') . DIRECTORY_SEPARATOR;

            // KRITISKT: dödar mutanten som gör att cachePath börjar med bara DIRECTORY_SEPARATOR
            $this->assertStringStartsWith(
                $normalizedRoot,
                $normalizedCachePath,
                'Default cachePath måste ligga under ROOT_PATH.'
            );

            $expectedTail = DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR;
            $this->assertStringEndsWith($expectedTail, $normalizedCachePath);
        } finally {
            if ($original === false) {
                putenv('VIEWS_CACHE_PATH');
            } else {
                putenv('VIEWS_CACHE_PATH=' . $original);
            }
        }
    }

    public function testEvaluateTemplateWrapExceptionUsesZeroCode(): void
    {
        $viewer = new RadixTemplateViewer($this->tempViewsPath);

        $templatePath = $this->tempViewsPath . 'throws_in_eval.ratio.php';
        file_put_contents($templatePath, '<?php throw new \Exception("Boom"); ?>');

        try {
            $viewer->render('throws_in_eval', []);
            $this->fail('render() borde kasta RuntimeException när eval() kastar.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Template evaluation failed: Boom',
                $e->getMessage()
            );

            // Dödar IncrementInteger-mutanten (0 -> 1)
            $this->assertSame(
                0,
                $e->getCode(),
                'Wrapper-exception code ska vara 0.'
            );

            $this->assertInstanceOf(Exception::class, $e->getPrevious());
        }
    }

    public function testGetFilterKeyDependsOnFilterTypesNotOnlyNames(): void
    {
        $viewerA = new RadixTemplateViewer($this->tempViewsPath);
        $viewerB = new RadixTemplateViewer($this->tempViewsPath);

        // Samma namn, olika type => ska ge olika filterKey
        $viewerA->registerFilter('same_name', static fn($v) => $v, 'string');
        $viewerB->registerFilter('same_name', static fn($v) => $v, 'array');

        $refA = new ReflectionClass($viewerA);
        $mA = $refA->getMethod('getFilterKey');
        $mA->setAccessible(true);

        $refB = new ReflectionClass($viewerB);
        $mB = $refB->getMethod('getFilterKey');
        $mB->setAccessible(true);

        $keyAMixed = $mA->invoke($viewerA);
        $keyBMixed = $mB->invoke($viewerB);

        $this->assertIsString($keyAMixed);
        $this->assertIsString($keyBMixed);

        /** @var string $keyA */
        $keyA = $keyAMixed;
        /** @var string $keyB */
        $keyB = $keyBMixed;

        // Dödar mutanten som tappar serialize($filterTypes)
        $this->assertNotSame(
            $keyA,
            $keyB,
            'getFilterKey() ska påverkas av filtertyper, annars kan cache kollidera.'
        );
    }

    public function testGenerateCacheKeyCastsAssetMtimesToStringForStableSerialization(): void
    {
        $reflection = new ReflectionClass($this->viewer);

        $resolveTemplatePath = $reflection->getMethod('resolveTemplatePath');
        $resolveTemplatePath->setAccessible(true);

        $generateCacheKey = $reflection->getMethod('generateCacheKey');
        $generateCacheKey->setAccessible(true);

        // 1) Skapa en template med stabilt innehåll + mtime
        $templateLogicalName = 'asset_mtime_string_cast_test';
        /** @var string $resolved */
        $resolved = $resolveTemplatePath->invoke($this->viewer, $templateLogicalName);

        $templateFullPath = $this->tempViewsPath . $resolved;
        $this->createDirectoryIfNotExists(dirname($templateFullPath));

        file_put_contents($templateFullPath, 'Hello {{ $name }}');
        $tTemplate = 123456;
        touch($templateFullPath, $tTemplate);

        // 2) Skapa CSS/JS under ROOT_PATH/public med stabil mtime
        $publicCssDir = rtrim((string) ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'css';
        $publicJsDir  = rtrim((string) ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js';
        $this->createDirectoryIfNotExists($publicCssDir);
        $this->createDirectoryIfNotExists($publicJsDir);

        $cssPath = $publicCssDir . DIRECTORY_SEPARATOR . 'app.css';
        $jsPath  = $publicJsDir . DIRECTORY_SEPARATOR . 'app.js';

        file_put_contents($cssPath, 'body {}');
        file_put_contents($jsPath, 'console.log("x");');

        $tAssets = 222222;
        touch($cssPath, $tAssets);
        touch($jsPath, $tAssets);

        $data = ['name' => 'User'];
        $version = '';

        $actualMixed = $generateCacheKey->invoke($this->viewer, $resolved, $data, $version);
        $this->assertIsString($actualMixed);

        $actual = $actualMixed;

        // 3) Bygg EXAKT samma struktur som i generateCacheKey(), inkl. (string) casts
        $absoluteTemplateFile = rtrim($this->tempViewsPath, '/\\') . DIRECTORY_SEPARATOR . ltrim($resolved, '/\\');

        $templateSig = [
            'path' => realpath($absoluteTemplateFile) ?: $absoluteTemplateFile,
            'mtime' => file_exists($absoluteTemplateFile) ? (int) filemtime($absoluteTemplateFile) : 0,
            'hash' => file_exists($absoluteTemplateFile)
                ? md5((string) file_get_contents($absoluteTemplateFile))
                : 'missing',
        ];

        $relevantParts = [
            'template' => $templateSig,
            'pagination' => [], // vi skickar ingen pagination
            'search' => [],     // vi skickar ingen search
            'filters' => md5(serialize([]) . serialize([])), // inga filter registrerade
            'version' => 'default_version', // eftersom $version är ''
        ];

        $runId = getenv('RADIX_RUN_ID') ?: '';
        if ($runId !== '') {
            $relevantParts['run_id'] = $runId;
        }

        $additionalHashes = [
            'css' => file_exists($cssPath) ? (string) filemtime($cssPath) : 'no-css',
            'js' => file_exists($jsPath) ? (string) filemtime($jsPath) : 'no-js',
        ];

        $expected = md5(serialize($relevantParts) . serialize($additionalHashes));

        // KRITISKT: dödar CastString-mutanten på css-mtime (int vs string ger annan serialize())
        $this->assertSame(
            $expected,
            $actual,
            'generateCacheKey() måste serialisera asset-mtimes som string för stabil hash (int vs string ska inte smyga in).'
        );
    }

    public function testReplacePlaceholdersDebugLogIncludesEscapedOriginalCode(): void
    {
        $viewer = new RadixTemplateViewer($this->tempViewsPath);
        $viewer->enableDebugMode(true);

        $reflection = new ReflectionClass($viewer);

        // Injicera testlogger så vi kan läsa debug-output utan fil-I/O
        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);

        $testLogger = new TestViewLogger();
        $loggerProp->setValue($viewer, $testLogger);

        // Anropa privata replacePlaceholders()
        $m = $reflection->getMethod('replacePlaceholders');
        $m->setAccessible(true);

        $input = "<div>&</div>";
        $m->invoke($viewer, $input);

        $all = implode("\n", $testLogger->messages);

        // Prefixet måste finnas
        $this->assertStringContainsString(
            "Original kod före placeholder-bearbetning:\n",
            $all
        );

        // KRITISKT: dödar ConcatOperandRemoval-mutanten genom att kräva att
        // htmlspecialchars($code) faktiskt loggas (inte bara prefixet).
        $this->assertStringContainsString('&lt;div&gt;&amp;&lt;/div&gt;', $all);
    }
}
