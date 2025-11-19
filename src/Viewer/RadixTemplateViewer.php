<?php

declare(strict_types=1);

namespace Radix\Viewer;

use RuntimeException;
use Throwable;

class RadixTemplateViewer implements TemplateViewerInterface
{
    /** @var array<string,mixed> */
    private array $globals = [];
    private string $cachePath;
    private string $viewsDirectory;
    private bool $debug = false;
    /**
     * @var array<string,array{callback:callable,type:string,identifier:string}>
     */
    private array $filters = [];
    private ?\Radix\Support\Logger $logger = null;

    public function __construct(string $viewsDirectory = null)
    {
        $this->viewsDirectory = $viewsDirectory ?? dirname(__DIR__, 3) . '/views/';
        $envCachePath = getenv('CACHE_PATH') ?: '';
        $root = defined('ROOT_PATH') ? (string) ROOT_PATH : (string) dirname(__DIR__, 4);
        if ($root === '' || $root === DIRECTORY_SEPARATOR) {
            $root = sys_get_temp_dir();
        }

        if ($envCachePath !== '') {
            $isAbsolute = str_starts_with($envCachePath, DIRECTORY_SEPARATOR)
                || preg_match('#^[A-Za-z]:[\\\\/]#', $envCachePath) === 1;
            $this->cachePath = $isAbsolute
                ? rtrim($envCachePath, '/\\') . DIRECTORY_SEPARATOR
                : rtrim($root, '/\\') . DIRECTORY_SEPARATOR . ltrim($envCachePath, '/\\') . DIRECTORY_SEPARATOR;
        } else {
            $this->cachePath = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR;
        }
    }

    /**
     * Render a template with given data.
     */
    public function render(string $template, array $data = [], string $version = ''): string
    {
        $this->debug("Attempting to render template: $template");
        $data = $this->mergeData($data);

        // Rensa gamla cachefiler
        $this->clearOldCacheFiles(3600);

        $templatePath = $this->resolveTemplatePath($template);
        $this->debug("Template resolved to: $templatePath");

        $data = $this->applyFilters($data);

        // Disable cache i development
        $appEnv = strtolower((string) (getenv('APP_ENV') ?: 'production'));
        $disableCache = in_array($appEnv, ['dev', 'development'], true);

        // Generera unik cache-nyckel baserat på data och version
        $cacheKey = $this->generateCacheKey($templatePath, $data, $version);
        $cachedFile = $disableCache ? null : $this->getCachedTemplate($cacheKey);

        if ($cachedFile !== null) {
            $this->debug("Using cached template: $cachedFile");
            extract($data, EXTR_SKIP);

            ob_start();
            include $cachedFile;


            $output = ob_get_clean();

            if ($output === false) {
                throw new RuntimeException("Failed to get output from cached file: $cachedFile");
            }

            $this->debug("Output from cached file: " . substr($output, 0, 100));
            return $output;
        }

        $filePath = $this->viewsDirectory . $templatePath;
        if (!file_exists($filePath)) {
            throw new RuntimeException("Template file not found: $filePath");
        }

        $code = $this->loadTemplate($filePath);
        $code = $this->processExtends($code, $this->viewsDirectory);
        $code = $this->loadIncludes($this->viewsDirectory, $code);
        $code = $this->replacePlaceholders($code);

        if (!$disableCache) {
            $this->cacheTemplate($cacheKey, $code);
            $this->debug("Compiled template cached under key: $cacheKey");
        } else {
            $this->debug("Cache disabled in development");
        }

        return $this->evaluateTemplate($code, $data);
    }

    /**
     * Aktivera eller inaktivera debug-läge.
     */
    public function enableDebugMode(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function registerFilter(string $name, callable $callback, string $type = 'string'): void
    {
        $this->filters[$name] = [
            'callback' => $callback, // Behåller callback för att kunna exekvera den
            'type' => $type,
            'identifier' => spl_object_hash((object) $callback), // Identifiering utan serialization
        ];
    }

    public function invalidateCache(string $template, array $data = [], string $version = ''): void
    {
        $templatePath = $this->resolveTemplatePath($template);
        $cacheKey = $this->generateCacheKey($templatePath, $data, $version);
        $cacheFile = $this->cachePath . $cacheKey . '.php';

        if (file_exists($cacheFile)) {
            unlink($cacheFile); // Ta bort filen
            $this->debug("Cache invalidated for key: $cacheKey");
        }
    }

    /**
     * Define a global shared variable.
     */
    public function shared(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
        $this->debug("Registrerad global variabel: $name => " . print_r($value, true));
    }

    /**
     * Create a new instance and render the template statically.
     */
    public static function view(string $template, array $data = []): string
    {
        $view = new self();

        return $view->render($template, $data);
    }

    /**
     * Resolve a template path from a dot-separated string.
     */
    private function resolveTemplatePath(string $template): string
    {
        if (str_contains($template, '.')) {
            // Byt ut punkter mot snedstreck för att spegla mappstrukturen
            $path = str_replace('.', '/', $template);
            return $path . '.ratio.php';
        }

        return $template . '.ratio.php';
    }

    /**
     * Load a template file, enforcing it exists.
     */
    private function loadTemplate(string $filePath): string
    {
        if (!file_exists($filePath)) {
            // Debug-log för sökvägar
            throw new RuntimeException("Template file not found: $filePath. Check if the directory and file exist.");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Failed to read template file: $filePath");
        }

        return $content;
    }

    /**
     * Process any `{% extends %}` directives for parent templates.
     */
    private function processExtends(string $code, string $viewsDirectory): string
    {
        // Samla block från alla nivåer, där barnets block har prioritet
        $accumulatedBlocks = [];

        // Utgå från child-koden först
        $currentCode = $code;
        $accumulatedBlocks = array_merge($this->getBlocks($currentCode), $accumulatedBlocks);

        // Iterera uppåt i hierarkin och merg:a block vid varje nivå
        while (preg_match('#^{% extends "(?<view>.*?)" %}#', $currentCode, $matches)) {
            $parentTemplate = $this->loadTemplate($viewsDirectory . $matches['view']);

            // Merg:a block från mellanlayouten (om den definierar t.ex. sidebar/hasSidebar)
            $parentBlocks = $this->getBlocks($currentCode);
            $accumulatedBlocks = array_merge($parentBlocks, $accumulatedBlocks);

            // Ersätt yields i parent med alla hittills kända block
            $currentCode = $this->replaceYields($parentTemplate, $accumulatedBlocks);
        }

        return $currentCode;
    }

    /**
     * Load and include templates referenced in `{% include %}` directives.
     */
    private function loadIncludes(string $viewsDirectory, string $code): string
    {
        preg_match_all('#{% include "(?<view>.*?)" %}#', $code, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $templateFilePath = $viewsDirectory . $match['view'];
            $includedContent = $this->loadTemplate($templateFilePath);

            $code = preg_replace(
                "#{% include \"{$match['view']}\" %}#",
                $includedContent,
                (string) $code
            ) ?? $code;
        }

        return $code;
    }

    /**
     * Convert placeholders like `{% %}` and `{{ }}` to PHP code.
     *
     * @return array<string,string>  Attributnamn => värde
     */
    private function parseAttributes(string $attributeString): array
    {
        $attributes = [];
        preg_match_all('/([\w\-:]+)="([^"]*)"/', $attributeString, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = $match[1];
            $value = preg_replace_callback(
                "#{{\s*(.+?)\s*}}#", // Matcha speciella placeholders i attribut
                function ($matches) {
                    return '<?php echo ' . $matches[1] . '; ?>';
                },
                $match[2]
            );

            // preg_replace_callback kan returnera null vid regex-fel – säkerställ alltid string
            $attributes[$key] = trim((string) $value);
        }

        return $attributes;
    }

    private function replacePlaceholders(string $code): string
    {
        $this->debug("Original kod före placeholder-bearbetning:\n" . htmlspecialchars($code));

        // 1. Hantera komponentinstanser (<x-komponent>)
        $code = preg_replace_callback(
            '#<x-([\w\.\-]+)([^>]*)>(.*?)<\/x-\1>#s',
            function (array $matches) {
                $componentName = str_replace('.', '/', $matches[1]);
                $attributes = $this->parseAttributes(trim($matches[2]));
                $content = trim($matches[3]);

                return $this->renderComponent($componentName, $attributes, $this->replacePlaceholders($content));
            },
            (string) $code
        ) ?? $code;

        // 2. Specifik hantering av globala variabler (t.ex., {{ $globalVar }})
        $code = preg_replace_callback(
            "#{{\s*\\$(global\w+)\s*}}#", // Matchar globala variabler med `$`-prefix
            function ($matches) {
                return '<?php echo $' . $matches[1] . '; ?>';
            },
            (string) $code
        ) ?? $code;

        // 3. Bearbeta PHP-direktiv `{% ... %}`
        $code = $this->replacePHPDirectives($code);

        // 4. Bearbeta variabler och uttryck (gäller generiska placeholders som `{{ variabel }}`)
        $code = $this->replaceVariableOutput($code);

        $this->debug("Kod efter placeholder-bearbetning:\n" . htmlspecialchars($code));
        return $code;
    }

    /**
     * @param array<string,string> $attributes
     */
    private function renderComponent(string $componentPath, array $attributes, string $slotContent): string
    {
        $this->debug("Renderar komponent från path: $componentPath");
        $this->debug("Attribut: " . print_r($attributes, true));
        $this->debug("SlotInnehåll: " . htmlspecialchars($slotContent));

        $componentFilePath = "{$this->viewsDirectory}components/$componentPath.ratio.php";

        if (!file_exists($componentFilePath)) {
            // Lägg till tydligare info vid saknad komponent
            throw new RuntimeException("Komponent fil saknas: $componentFilePath (komponent: $componentPath)");
        }

        $componentCode = $this->loadTemplate($componentFilePath);
        $slots = $this->extractNamedSlots($slotContent);

        // Justera attribut med att lägga till slots
        $attributes = array_map(
            /**
             * @param string|null $value
             */
            fn($value): string => trim((string) $this->replaceVariableOutput((string) $value)),
            $attributes
        );

        // Kombinera data (inklusive tom slot) som skickas till komponenten
        $data = array_merge(['slot' => trim($slotContent)], $slots, $attributes);
        $processedCode = trim($this->replacePlaceholders($componentCode));

        $result = trim($this->evaluateTemplate($processedCode, $this->mergeData($data)));
        $this->debug("Renderad komponent ut data:\n" . htmlspecialchars($result));

        return $result;
    }


    /**
     * Extracts named slots from the given slotContent.
     * Supports syntax like `<x-slot:name>content</x-slot:name>`.
     *
     * @param string $slotContent
     * @return array<string,string>  Slotnamn => slotinnehåll
     */
    private function extractNamedSlots(string &$slotContent): array
    {
        $this->debug("Extraherar slots från innehåll:\n" . htmlspecialchars($slotContent));

        $slots = [];
        preg_match_all('#<x-slot:([\w\-]+)>(.*?)</x-slot:\1>#s', $slotContent, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $slotName = $match[1];
            // Hantera tomma slots genom att sätta ett standardvärde som tom sträng
            $slotValue = trim($this->replacePlaceholders($match[2]));
            $slots[$slotName] = $slotValue;

            $this->debug("Extraherad slot: $slotName, Innehåll:\n" . htmlspecialchars($slotValue));

            // Ta bort matchade <x-slot> från originalinnehållet
            $slotContent = str_replace($match[0], '', $slotContent);
        }

        $this->debug("Extraherade slots:\n" . print_r($slots, true));
        return $slots;
    }

    /**
     * Convert `{% directive %}` to PHP code.
     */
    private function replacePHPDirectives(?string $code): string
    {
        $code = (string) $code;

        return preg_replace("#{%\s*(.+?)\s*%}#", "<?php $1 ?>", $code) ?? $code;
    }

    /**
     * Bearbeta och escapa variabelbaserade placeholders.
     */
    private function replaceVariableOutput(string $code): string
    {
        // 1. Placeholder för slot
        $code = preg_replace_callback(
            "#{{\s*slot\s*}}#",
            function () {
                return '<?php echo trim($slot); ?>';
            },
            $code
        ) ?? $code;

        // 2. Bearbeta uttryck med "|raw" för att undvika HTML-escaping
        $code = preg_replace_callback(
            "#{{\s*(.+?)\|raw\s*}}#",
            function ($matches) {
                // Casta till string så secure_output inte får mixed
                return '<?php echo secure_output((string) (' . $matches[1] . '), true); ?>';
            },
            $code
        ) ?? $code;

        // 3. Alla andra placeholders (inklusive variabler och funktioner)
        $code = preg_replace_callback(
            "#{{\s*(.+?)\s*}}#",
            function ($matches) {
                // Casta till string för säkert, escapat output
                return '<?php echo secure_output((string) (' . $matches[1] . ')); ?>';
            },
            $code
        ) ?? $code;

        return $code;
    }

    /**
     * Extract blocks from a given template string.
     *
     * @return array<string,string>  Blocknamn => blockinnehåll
     */
    private function getBlocks(string $code): array
    {
        preg_match_all("#{% block (?<name>\w+) %}(?<content>.*?){% endblock %}#s", $code, $matches, PREG_SET_ORDER);
        $blocks = [];

        foreach ($matches as $match) {
            $blocks[$match["name"]] = $match["content"];
        }

        return $blocks;
    }

    /**
     * Replace `{% yield %}` directives with corresponding block content.
     *
     * @param array<string,string> $blocks
     */
    private function replaceYields(string $code, array $blocks): string
    {
        // Hantera block-yield med fallback:
        // {% yield name %} ...fallback... {% endyield name %}
        $code = preg_replace_callback(
            "#{%\s*yield\s+(?<name>\w+)\s*%}(?<fallback>.*?){%\s*endyield\s+\k<name>\s*%}#s",
            function (array $m) use ($blocks): string {
                $name = $m['name'];
                $fallback = (string) $m['fallback'];
                return array_key_exists($name, $blocks) ? (string) $blocks[$name] : $fallback;
            },
            $code
        ) ?? $code;

        // Hantera enkla yield-taggar utan fallback: {% yield name %}
        preg_match_all("#{%\s*yield\s+(?<name>\\w+)\s*%}#", $code, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $yieldName = $match['name'];
            $replacement = $blocks[$yieldName] ?? '';
            $code = str_replace($match[0], $replacement, $code);
        }

        return $code;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function evaluateTemplate(string $code, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            eval('?>' . $code);
        } catch (Throwable $e) {
            // Behåll tydlig felhantering utan att påverka lyckade körningar
            ob_end_clean();
            throw new RuntimeException('Template evaluation failed: ' . $e->getMessage(), 0, $e);
        }
        $output = ob_get_clean();

        if ($output === false) {
            // Ingen output producerades (eller något gick fel med output-buffer),
            // men vi vill fortfarande returnera en sträng enligt signaturen.
            $output = '';
        }

        // Behåll outputen som den är utan normalisering
        $this->debug("Evaluations resultat:\n" . htmlspecialchars($output));

        return $output;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function mergeData(array $data): array
    {
        $this->debug("Globala variabler:\n" . print_r($this->globals, true));
        $this->debug("Lokala data:\n" . print_r($data, true));

        // Kombinera globala variabler och lokala data
        return array_merge($this->globals, $data);
    }

    private function cacheTemplate(string $key, string $compiledCode): void
    {
        $cacheFile = $this->cachePath . $key . '.php';

        if (!is_dir($this->cachePath)) {
            $this->debug("Cache directory not found. Creating: $this->cachePath");
            mkdir($this->cachePath, 0o755, true);
        }

        // Kontrollera miljön från APP_ENV: Minifiera endast i production
        $appEnv = strtolower((string) (getenv('APP_ENV') ?: 'production'));
        $codeToWrite = $appEnv === 'production' ? $this->minifyPHP($compiledCode) : $compiledCode;

        $this->debug("Writing cache file to: $cacheFile (Minify: " . ($appEnv === 'production' ? 'YES' : 'NO') . ")");

        // Skriv ut koden till fil
        if (file_put_contents($cacheFile, $codeToWrite) === false) {
            $this->debug("Failed to write cache file to: $cacheFile");
        } else {
            $this->debug("Cache file successfully written to: $cacheFile");
        }
    }

    private function minifyPHP(string $code): string
    {
        // 1. Matcha och skydda PHP-taggar och innehåll
        $preservedPHP = [];

        $code = preg_replace_callback(
            '/(<\?php.*?\?>)/s', // Matcha PHP-block
            function ($matches) use (&$preservedPHP) {
                $key = '###PHP' . count($preservedPHP) . '###'; // Generera unikt nyckelord
                $preservedPHP[$key] = $matches[0]; // Behåll PHP-originalkoden
                return $key; // Ersätt PHP med nyckeln
            },
            $code
        );

        // 2. Utför lätt minifiering för HTML utanför PHP-block
        // Ta bort onödiga radbrytningar och blanksteg i HTML
        $code = preg_replace('/^\h*\R+/m', '', (string) $code); // Ta bort tomma rader
        $code = preg_replace('/>\s+</', ">\n<", (string) $code); // Behåll radbrytningar mellan HTML-taggar
        $code = preg_replace('/\s+/', ' ', (string) $code); // Komprimera mellanslag till ett enda

        // 3. Återställ skyddad PHP tillbaka till koden
        foreach ($preservedPHP as $key => $originalPHP) {
            $code = str_replace($key, $originalPHP, (string) $code);
        }

        // 4. Trimma kod (ta bort whitespace i början och slutet)
        return trim((string) $code);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function generateCacheKey(string $templatePath, array $data, string $version = ''): string
    {
        // Skapa en liten representation av relevanta delar
        $relevantParts = [
            'template' => $templatePath,
            'pagination' => $this->getPaginationKey($data),
            'search' => $this->getSearchKey($data), // Inkludera sök-data
            'filters' => $this->getFilterKey(),
            'version' => $version ?: 'default_version',
        ];

        // Lägg till ändringstider från CSS och JS
        $cssPath = ROOT_PATH . '/public/css/app.css';
        $jsPath = ROOT_PATH . '/public/js/app.js';
        $additionalHashes = [
            'css' => file_exists($cssPath) ? (string) filemtime($cssPath) : 'no-css',
            'js' => file_exists($jsPath) ? (string) filemtime($jsPath) : 'no-js',
        ];

        // Kombinera allt till en cache-nyckel
        return md5(serialize($relevantParts) . serialize($additionalHashes));
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function getSearchKey(array $data): array
    {
        // Isolera söktermer och relevant data från "search"
        if (isset($data['search']) && is_array($data['search'])) {
            return [
                'term' => $data['search']['term'] ?? '', // Sökterm som viktiga nyckel
                'current_page' => $data['search']['current_page'] ?? 1, // För paginerad sökning
            ];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,int>
     */
    private function getPaginationKey(array $data): array
    {
        if (!isset($data['pagination']) || !is_array($data['pagination'])) {
            return [];
        }

        $rawPage = $data['pagination']['page'] ?? 1;

        if (!is_int($rawPage)) {
            if (is_numeric($rawPage)) {
                $rawPage = (int) $rawPage;
            } else {
                $rawPage = 1;
            }
        }

        /** @var int $rawPage */
        return ['page' => $rawPage];
    }

    private function getFilterKey(): string
    {
        // Skapa en representation av filtren (namn och typer)
        $filterNames = array_keys($this->filters);
        $filterTypes = array_map(fn($filter) => $filter['type'], $this->filters);

        // Skapa hash för aktiva filter
        return md5(serialize($filterNames) . serialize($filterTypes));
    }

    /**
     * Debug-loggning (kan anpassas att logga i filer eller visa på skärmen).
     */
    private function debug(string $message): void
    {
        if (!$this->debug) {
            return;
        }
        // Lazy-init logger med kanal "view"
        if ($this->logger === null) {
            $this->logger = new \Radix\Support\Logger('view');
        }
        $this->logger->debug($message);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function applyFilters(array $data): array
    {
        if ($this->filters === []) {
            return $data;
        }

        foreach ($data as $key => $value) {
            foreach ($this->filters as $filter) {
                $expectedType = $filter['type'];

                // Applicera filtret endast om typen matchar
                if ($expectedType === 'string' && is_string($value)) {
                    $value = $filter['callback']($value); // Uppdatera värdet
                } elseif ($expectedType === 'array' && is_array($value)) {
                    $value = $filter['callback']($value);
                } elseif ($expectedType === 'object' && is_object($value)) {
                    $value = $filter['callback']($value);
                }
            }
            $data[$key] = $value; // Sätt det transformerade värdet
        }

        return $data;
    }

    private function getCachedTemplate(string $key): ?string
    {
        $cacheFile = $this->cachePath . $key . '.php';

        if (file_exists($cacheFile)) {
            return $cacheFile;
        }

        return null;
    }

    /**
     * Removes older cache files based on their last modification time.
     *
     * @param int $maxAgeInSeconds The maximum age (in seconds) a cache file is allowed to have.
     */
    private function clearOldCacheFiles(int $maxAgeInSeconds = 86400): void // 1 day by default
    {
        // Kontrollera om cache-katalogen finns innan du rensar
        if (!is_dir($this->cachePath)) {
            return;
        }

        $now = time();

        // Loopa igenom alla filer i cachekatalogen
        foreach (scandir($this->cachePath) as $file) {
            $filePath = "$this->cachePath/$file";

            // Hoppa över "." och ".."
            if ($file === '.' || $file === '..') {
                continue;
            }

            // Kontrollera om det är en giltig fil
            if (is_file($filePath)) {
                $fileAge = $now - filemtime($filePath);

                // Ta bort filen om den är äldre än tillåten ålder
                if ($fileAge > $maxAgeInSeconds) {
                    unlink($filePath);
                }
            }
        }
    }
}
