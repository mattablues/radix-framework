<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Radix\Container\ApplicationContainer;
use Radix\Routing\Router;

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return ROOT_PATH.'/public'.($path ? '/'.ltrim($path, '/') : '');
    }
}

if (!function_exists('versioned_file')) {
    function versioned_file(
        string $path,
        string $default = '/images/graphics/avatar.png',
        bool $checkDirectories = false
    ): string {
        $fullPath = public_path($path);

        // Kontrollera om filen eller katalogen existerar beroende på flaggan
        if (($checkDirectories && is_dir($fullPath)) || file_exists($fullPath)) {
            return $path.'?v='.filemtime($fullPath);
        }

        return $default;
    }
}



if (!function_exists('app')) {
    function app(?string $abstract = null): mixed
    {
        $container = ApplicationContainer::get();

        return $abstract === null ? $container : $container->get($abstract);
    }
}

if (!function_exists('setAppContainer')) {
    function setAppContainer(ContainerInterface $container): void
    {
        ApplicationContainer::set($container);
    }
}

if (!function_exists('response')) {
    function response(string $content): \Radix\Http\Response
    {
        $response = app(\Radix\Http\Response::class);
        $response->setBody($content);

        return $response;
    }
}

if (!function_exists('request')) {
    function request(): \Radix\Http\Request
    {
        return \Radix\Http\Request::createFromGlobals();
    }
}

if (!function_exists('route')) {
    function route(string $name, array $data = []): string
    {
        return Router::routePathByName($name, $data);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url):  \Radix\Http\Response
    {
        return new \Radix\Http\RedirectResponse($url);
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = []): \Radix\Http\Response
    {
        $view = app(\Radix\Viewer\RadixTemplateViewer::class);

        return response($view->render($template, $data));
    }
}

if (!function_exists('array_merge_deep')) {
    function array_merge_deep(array $array1, array $array2): array
    {
        foreach ($array2 as $key => $value) {
            if (isset($array1[$key]) && is_array($array1[$key]) && is_array($value)) {
                $array1[$key] = array_merge_deep($array1[$key], $value);
            } else {
                $array1[$key] = $value;
            }
        }

        return $array1;
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        $session = app(\Radix\Session\SessionInterface::class); // Hämtar din session från DI
        $csrfToken = $session->csrf(); // Kommer nu endast generera ny token om nödvändigt
        return '<input type="hidden" name="csrf_token" value="' . secure_output($csrfToken) . '">';
    }
}

if (!function_exists('error')) {
    /**
     * Hämta valideringsfel för ett specifikt fält.
     *
     * @param array|null $errors Valideringsfelen (kan vara null).
     * @param string $field Fältet du vill hämta fel för.
     * @param bool $first Om endast det första felet ska returneras (standard: true).
     * @return string|array|null Retunerar första felet som en sträng, alla fel som array, eller null om inga fel finns.
     */
    function error(?array $errors, string $field, bool $first = true): string|array|null
    {
        if (empty($errors) || !isset($errors[$field])) {
            return null;
        }

        return $first ? ($errors[$field][0] ?? null) : $errors[$field];
    }
}

if (!function_exists('old')) {
    function old(string $key, string $default = ''): string
    {
        /** @var \Radix\Session\SessionInterface $session */
        $session = app(\Radix\Session\SessionInterface::class);

        // Använd sessionens `get`-metod för att hämta tidigare data
        $oldData = $session->get('old', []);

        // Kontrollera om nyckeln finns i den gamla datan
        return $oldData[$key] ?? $default;
    }
}

if (!function_exists('is_running_from_console')) {
    function is_running_from_console(): bool
    {
        return (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
    }
}

if (!function_exists('mb_ucwords')) {
    function mb_ucwords(string $string): string
    {
        return mb_convert_case($string, MB_CASE_TITLE, 'UTF-8');
    }
}

if (!function_exists('starts_with_uppercase')) {
    function starts_with_uppercase(string $string): bool
    {
        $char = mb_substr($string, 0, 1, "UTF-8");
        return $char !== mb_strtolower($char, "UTF-8");
    }
}

if (!function_exists('is_assoc')) {
    function is_assoc(array $data): bool
    {
        return array_keys($data) !== range(0, count($data) - 1);
    }
}

if (!function_exists('mb_ucfirst')) {
    function mb_ucfirst(string $str, string $encoding = "UTF-8", bool $lower_str_end = false): string
    {
        $first_letter = mb_strtoupper(mb_substr($str, 0, 1, $encoding), $encoding);
        $str_end = $lower_str_end
            ? mb_strtolower(mb_substr($str, 1, null, $encoding), $encoding)
            : mb_substr($str, 1, null, $encoding);

        return $first_letter . $str_end;
    }
}

if (!function_exists('matches')) {
    function matches(array $data): int
    {
        return array_reduce($data, fn($carry, $item) => $carry + strlen($item), 0);
    }
}

if (!function_exists('has_whitespace')) {
    function has_whitespace(string $string): bool
    {
        return (bool) preg_match('/\s/', $string);
    }
}

if (!function_exists('remove_whitespace')) {
    function remove_whitespace(string $string): string
    {
        return preg_replace('/\s+/', '', $string) ?? '';
    }
}

if (!function_exists('replace_placeholder')) {
    function replace_placeholder(string $url, array $data): string
    {
        $placeholders = array_combine(
            array_map(fn($placeholder) => "{{$placeholder}}", array_keys($data)),
            $data
        );

        return strtr($url, $placeholders);
    }
}

if (!function_exists('thumb')) {
    function thumb(string $path): string
    {
        extract(pathinfo($path));
        return "$dirname/$filename.thumb.$extension";
    }
}

if (!function_exists('clean_text')) {
    function clean_text(string $text): string
    {
        $text = preg_replace("/[\r\n]+/", " ", $text);
        return trim(preg_replace("/^\s+|\s+$|\s+(?=\s)/", "", $text));
    }
}

if (!function_exists('camel_case_to_hyphen')) {
    function camel_case_to_hyphen($camel_case): string
    {
        $result = '';

        for ($i = 0; $i < strlen($camel_case); $i++) {
            $char = $camel_case[$i];

            if (ctype_upper($char)) {
                $result .= '-' . mb_strtolower($char);
            } else {
                $result .= $char;
            }
        }

        return mb_strtolower(ltrim($result, '-'));
    }
}

if (!function_exists('get_object_public_fields')) {
    function get_object_public_fields(object $obj): array
    {
        return get_object_vars($obj);
    }
}

if (!function_exists('studly_to_snake')) {
    function studly_to_snake(string $string): string
    {
        $pattern = '/([a-z])([A-Z])/';
        $snakeCase = preg_replace($pattern, '$1_$2', $string);

        return mb_strtolower($snakeCase);
    }
}

if (!function_exists('camel_to_snake')) {
    function camel_to_snake(string $string): string
    {
        $pattern = '/(?<=\\w)(?=[A-Z])|(?<=[a-z])(?=[0-9])/';
        $snakeCase = preg_replace($pattern, '_', $string);

        return mb_strtolower($snakeCase);
    }
}

if (!function_exists('hyphen_to_studly')) {
    function hyphen_to_studly(string $string): string
    {
        return str_replace(' ', '', mb_ucwords(str_replace('-', ' ', $string)));
    }
}

if (!function_exists('hyphen_to_camel')) {
    function hyphen_to_camel(string $string): string
    {
        return mb_lcfirst(hyphen_to_studly($string));
    }
}

if (!function_exists('studly_to_dashed')) {
    function studly_to_dashed(string $string): string
    {
        $pattern = '/([a-zA-Z])(?=[A-Z])/';
        $dashed = preg_replace($pattern, '$1-', $string);

        return mb_strtolower($dashed);
    }
}

if (!function_exists('copyright')) {
    function copyright(string $string, string $year): string
    {
        $to_year = date('Y', time());

        if ($to_year > (int)$year) {
            $year = $year . ' - ' . $to_year;
        }

        return "$year $string";
    }
}

if (!function_exists('part_of_string')) {
    function part_of_string(string $string, int $length, int $break): string
    {
        return (strlen($string) > $length) ? substr($string,0,$break) . '...' : $string;
    }
}

if (!function_exists('generate_password')) {
    function generate_password(int $length = 10, string $available_sets = 'luds'): string
    {
        $sets = [];

        if(str_contains($available_sets, 'l')) {
            $sets[] = 'abcdefghjkmnpqrstuvwxyz';
        }
        if(str_contains($available_sets, 'u')) {
            $sets[] = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        }
        if(str_contains($available_sets, 'd')) {
            $sets[] = '123456789';
        }
        if(str_contains($available_sets, 's')) {
            $sets[] = '!@#$%&*?';
        }

        $all = '';
        $password = '';

        foreach($sets as $set) {
            $password .= $set[array_rand(str_split($set))];
            $all .= $set;
        }

        $all = str_split($all);

        for($i = 0; $i < $length - count($sets); $i++) {
            $password .= $all[array_rand($all)];
        }

        return str_shuffle($password);
    }
}

if (!function_exists('encrypt')) {
    /**
     * @throws \Random\RandomException
     */
    function encrypt(string $text): string
    {
        $key = getenv('SECURE_ENCRYPTION_KEY') ?? null;

        if (!$key) {
            throw new RuntimeException('Krypteringsnyckeln saknas.');
        }

        // Decodera Base64 om nyckeln har prefixet "base64:"
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        if (!$key || strlen($key) < 32) {
            throw new RuntimeException('Krypteringsnyckeln är ogiltig eller för kort (förväntar minst 256-bitars).');
        }

        // Generera en slumpmässig IV (initialiseringsvektor)
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));

        // Kryptera texten
        $encrypted = openssl_encrypt($text, 'aes-256-cbc', substr($key, 0, 32), 0, $iv);

        if ($encrypted === false) {
            throw new RuntimeException('Kryptering misslyckades.');
        }

        // Kombinera IV och den krypterade texten och returnera Base64-kodat resultat
        return base64_encode($iv . $encrypted);
    }
}

if (!function_exists('decrypt')) {
    function decrypt(string $text): string|false
    {
        $key = getenv('SECURE_ENCRYPTION_KEY') ?? null;

        if (!$key) {
            throw new RuntimeException('Krypteringsnyckeln saknas.');
        }

        // Decodera Base64 om nyckeln har prefixet "base64:"
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        if (!$key || strlen($key) < 32) {
            throw new RuntimeException('Krypteringsnyckeln är ogiltig eller för kort (mindre än 256-bitars).');
        }

        $data = base64_decode($text);
        $ivSize = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivSize);
        $encrypted = substr($data, $ivSize);

        if (!$iv || !$encrypted) {
            throw new RuntimeException('Dekryptering misslyckades. Datan är ogiltig.');
        }

        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', substr($key, 0, 32), 0, $iv);

        if ($decrypted === false) {
            throw new RuntimeException('Dekryptering misslyckades.');
        }

        return $decrypted;
    }
}

if (!function_exists('prefix_message')) {
    function prefix_message(
        int|float $number,
        string $message,
        string $suffix = '',
        int|float $compareTo = 1,
        ?string $charsToTrim = null,
        bool $hideNumber = false
    ): string {
        // Trimma onödiga tecken från meddelandet, om specificerat
        if ($charsToTrim !== null) {
            $message = rtrim($message, $charsToTrim);
        }

        // Dölj siffran om $hideNumber är TRUE
        $prefix = $hideNumber ? '' : (string) $number;

        // Jämför och lägg till suffix om det behövs
        if ($number > $compareTo) {
            return trim($prefix . ' ' . $message . $suffix);
        }

        return trim($prefix . ' ' . $message);
    }
}

if (!function_exists('generate_honeypot_id')) {
    function generate_honeypot_id(): string
    {
        return 'hp_' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('honeypot_field')) {
    function honeypot_field(string $formContext = ''): string
    {
        $honeypotId = generate_honeypot_id() . ($formContext ? '_' . $formContext : '');
        request()->session()->set('honeypot_id', $honeypotId);

        return '<label for="honeypot_' . secure_output($honeypotId) . '" style="display:none;"></label>' .
               '<input type="text" name="' . secure_output($honeypotId) . '" id="honeypot_' . secure_output($honeypotId) . '" style="display:none;" value="">';
    }
}

if (!function_exists('secure_output')) {
   function secure_output($content, $allowRaw = false): string
   {
    if ($allowRaw) {
        // Om rå data är tillåten, returnera direkt utan escaping
        // kan behövas (string)
        return $content;
    }

    // Konvertera innehållet till en sträng och escapa sedan för säkerhet
    return htmlspecialchars((string) $content, ENT_QUOTES, 'UTF-8');
   }
}


if (!function_exists('optional')) {
    /**
     * Hantera potentiellt null-värden.
     *
     * @param mixed $value   Objektet som kan vara null.
     * @param Closure|null $callback  (valfritt) Callback för att bearbeta objektet.
     *
     * @return mixed|null
     */
    function optional(mixed $value, Closure $callback = null): mixed
    {
        if (is_null($value)) {
            return null;
        }

        // Om en callback finns, skicka värdet till den
        if ($callback) {
            return $callback($value);
        }

        return $value;
    }
}

if (!function_exists('paginate_links')) {
    /**
     * Generera HTML för sidnavigering baserat på en pagineringsstruktur.
     *
     * @param  array  $pagination  Pagineringsdata (från t.ex. en paginate-metod).
     * @param  string  $route  Routens namn (t.ex. "admin.user.index").
     * @param  int|null  $interval  Antal sidor att visa som intervall runt den aktuella sidan.
     * @return string HTML-sträng för sidnavigeringen.
     */
    function paginate_links(array $pagination, string $route, ?int $interval = null): string
    {
        if ($pagination['total'] <= $pagination['per_page']) {
            return '';
        }

        $desktopInterval = $interval ?? 3;

        // Gemensamma delar
        $first  = render_first_link($pagination, $route);
        $prev   = render_previous_link($pagination, $route);
        $next   = render_next_link($pagination, $route);
        $last   = render_last_link($pagination, $route);

        // Innehåll per breakpoint
        $pagesMobile  = render_page_links_with_interval($pagination, $route, 1);
        $pagesDesktop = ($desktopInterval === null)
            ? render_page_links($pagination, $route)
            : render_page_links_with_interval($pagination, $route, $desktopInterval);

        $linksMobile  = $first . $prev . $pagesMobile . $next . $last;
        $linksDesktop = $first . $prev . $pagesDesktop . $next . $last;

        // Mobil: horisontell scroll som matchar innehållets bredd
        $mobile = '<div class="md:hidden w-full overflow-x-auto pb-2 snap-x" aria-label="Sidnavigering">'
                . '<div class="flex min-w-fit shrink-0 items-center justify-center gap-1.5 px-2 text-sm">'
                . $linksMobile
                . '</div></div>';

        // Desktop
        $desktop = '<div class="hidden md:flex items-center justify-center gap-1.5" aria-label="Sidnavigering">'
                 . $linksDesktop
                 . '</div>';

        return $mobile . $desktop;
    }

    // Gemensamma klassnamn för konsekvent höjd/bredd
    function _pager_btn_classes(bool $disabled = false, bool $active = false): string
    {
        // Öka höjd och padding för ~29-30px totalhöjd (matcha tidigare utseende)
        $base = 'h-7 min-w-7 px-2 py-1 inline-flex items-center justify-center align-middle border rounded text-sm';

        // Basfärger via variabler
        $baseColors = 'pager-base pager-hover';

        if ($active) {
            // Aktiva färger via variabler
            return $base . ' pager-active';
        }
        if ($disabled) {
            // Inaktiva färger via variabler
            return $base . ' pager-disabled';
        }

        // Standard (länkbar) via variabler
        return $base . ' ' . $baseColors;
    }

    // Liten, enhetlig SVG-storlek så den inte blir större än sidlänkarna
    function _icon_svg(string $which, int $size = 18): string
    {
        $common = 'class="pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"';
        return match ($which) {
            'chevrons-left' =>
                "<svg width=\"$size\" height=\"$size\" viewBox=\"0 0 24 24\" $common><polyline points=\"11 17 6 12 11 7\"></polyline><polyline points=\"18 17 13 12 18 7\"></polyline></svg>",
            'chevron-left' =>
                "<svg width=\"$size\" height=\"$size\" viewBox=\"0 0 24 24\" $common><polyline points=\"15 18 9 12 15 6\"></polyline></svg>",
            'chevron-right' =>
                "<svg width=\"$size\" height=\"$size\" viewBox=\"0 0 24 24\" $common><polyline points=\"9 18 15 12 9 6\"></polyline></svg>",
            'chevrons-right' =>
                "<svg width=\"$size\" height=\"$size\" viewBox=\"0 0 24 24\" $common><polyline points=\"13 17 18 12 13 7\"></polyline><polyline points=\"6 17 11 12 6 7\"></polyline></svg>",
            default => '',
        };
    }

   function render_first_link(array $pagination, string $route): string
    {
        $disabled = !($pagination['current_page'] > $pagination['first_page']);
        $cls = _pager_btn_classes($disabled);
        $icon = _icon_svg('chevrons-left', 18);
        if (!$disabled) {
            $url = route($route) . '?' . http_build_query(['page' => $pagination['first_page']]);
            return sprintf(
                '<a href="%s" class="%s" aria-label="Gå till första sidan" style="line-height:1">%s</a>',
                secure_output($url),
                $cls . ' rounded-l-lg',
                $icon
            );
        }
        return sprintf('<span class="%s" aria-hidden="true" style="line-height:1">%s</span>', $cls . ' rounded-l-lg', $icon);
    }

    function render_previous_link(array $pagination, string $route): string
    {
        $disabled = !($pagination['current_page'] > $pagination['first_page']);
        $cls = _pager_btn_classes($disabled);
        $icon = _icon_svg('chevron-left', 18);
        if (!$disabled) {
            $url = route($route) . '?' . http_build_query(['page' => $pagination['current_page'] - 1]);
            return sprintf(
                '<a href="%s" class="%s" aria-label="Föregående sida" style="line-height:1">%s</a>',
                secure_output($url),
                $cls,
                $icon
            );
        }
        return sprintf('<span class="%s" aria-hidden="true" style="line-height:1">%s</span>', $cls, $icon);
    }

    function render_page_links(array $pagination, string $route): string
    {
        $html = '';

        for ($page = $pagination['first_page']; $page <= $pagination['last_page']; $page++) {
            $url = route($route) . '?' . http_build_query(['page' => $page]);
            if ($page === $pagination['current_page']) {
                $html .= sprintf('<span class="%s" aria-current="page" style="line-height:1">%d</span>', _pager_btn_classes(false, true), $page);
            } else {
                $html .= sprintf(
                    '<a href="%s" class="%s" style="line-height:1">%d</a>',
                    secure_output($url),
                    _pager_btn_classes(),
                    $page
                );
            }
        }

        return $html;
    }

    function render_page_links_with_interval(array $pagination, string $route, int $interval): string
    {
        $html = '';
        $current = $pagination['current_page'];
        $first = $pagination['first_page'];
        $last = $pagination['last_page'];

        if ($current > $first + $interval) {
            $url = route($route) . '?' . http_build_query(['page' => $first]);
            $html .= sprintf(
                '<a href="%s" class="%s" style="line-height:1">%d</a>',
                secure_output($url),
                _pager_btn_classes(),
                $first
            );
            if ($current > $first + $interval + 1) {
                $html .= '<span class="h-6 min-w-6 px-1.5 py-0.5 inline-flex items-center justify-center align-middle pager-ellipsis" style="line-height:1">…</span>';
            }
        }

        for ($page = max($first, $current - $interval); $page <= min($last, $current + $interval); $page++) {
            $url = route($route) . '?' . http_build_query(['page' => $page]);
            if ($page === $current) {
                $html .= sprintf('<span class="%s" aria-current="page" style="line-height:1">%d</span>', _pager_btn_classes(false, true), $page);
            } else {
                $html .= sprintf(
                    '<a href="%s" class="%s" style="line-height:1">%d</a>',
                    secure_output($url),
                    _pager_btn_classes(),
                    $page
                );
            }
        }

        if ($current < $last - $interval) {
            if ($current < $last - $interval - 1) {
                $html .= '<span class="h-6 min-w-6 px-1.5 py-0.5 inline-flex items-center justify-center align-middle pager-ellipsis" style="line-height:1">…</span>';
            }
            $url = route($route) . '?' . http_build_query(['page' => $last]);
            $html .= sprintf(
                '<a href="%s" class="%s" style="line-height:1">%d</a>',
                secure_output($url),
                _pager_btn_classes(),
                $last
            );
        }

        return $html;
    }

    function render_next_link(array $pagination, string $route): string
    {
        $disabled = !($pagination['current_page'] < $pagination['last_page']);
        $cls = _pager_btn_classes($disabled);
        $icon = _icon_svg('chevron-right', 18);
        if (!$disabled) {
            $url = route($route) . '?' . http_build_query(['page' => $pagination['current_page'] + 1]);
            return sprintf(
                '<a href="%s" class="%s" aria-label="Nästa sida" style="line-height:1">%s</a>',
                secure_output($url),
                $cls,
                $icon
            );
        }
        return sprintf('<span class="%s" aria-hidden="true" style="line-height:1">%s</span>', $cls, $icon);
    }

    function render_last_link(array $pagination, string $route): string
    {
        $disabled = !($pagination['current_page'] < $pagination['last_page']);
        $cls = _pager_btn_classes($disabled);
        $icon = _icon_svg('chevrons-right', 18);
        if (!$disabled) {
            $url = route($route) . '?' . http_build_query(['page' => $pagination['last_page']]);
            return sprintf(
                '<a href="%s" class="%s" aria-label="Gå till sista sidan" style="line-height:1">%s</a>',
                secure_output($url),
                $cls . ' rounded-r-lg',
                $icon
            );
        }
        return sprintf('<span class="%s" aria-hidden="true" style="line-height:1">%s</span>', $cls . ' rounded-r-lg', $icon);
    }

    function calculate_total_pages(int $total, int $perPage): int
    {
        return (int) ceil($total / $perPage);
    }
}
