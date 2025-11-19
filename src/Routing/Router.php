<?php

declare(strict_types=1);

namespace Radix\Routing;

use Closure;
use InvalidArgumentException;
use RuntimeException;

class Router
{
    /** @var array<int,array<string,mixed>> */

    private array $routes = [];
    private ?string $path = null;
    /** @var array<int,string> */
    private array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    /** @var array<string,string> */
    private static array $routeNames = [];
    private int $index = 0;
    /** @var array<string,array<int,string>> */
    private array $middlewareGroups = [];

    /**
     * Matcha en path och ev. metod mot definierade routes.
     *
     * @return array<int|string,mixed>|false
     */
    public function match(string $path, string $method = null): array|bool
    {
        $path = urldecode($path);
        $path = trim($path, '/');

        foreach ($this->routes as $route) {
            $routePath = $route['path'] ?? null;
            if (!is_string($routePath)) {
                continue;
            }

            $pattern = $this->patternFromRoutePath($routePath);

            if (preg_match($pattern, $path, $matches)) {
                $matches = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                $routeParams = $route['params'] ?? null;
                if (!is_array($routeParams)) {
                    continue;
                }

                $params = array_merge($matches, $routeParams);

                if (isset($route['middlewares'])) {
                    $params['middlewares'] = $route['middlewares'];
                }

                if ($method && array_key_exists('method', $params)) {
                    $routeMethod = $params['method'];

                    // Säkerställ att route-metoden verkligen är en sträng
                    if (!is_string($routeMethod)) {
                        continue;
                    }

                    $routeMethodLower = mb_strtolower($routeMethod);

                    if (mb_strtolower($method) !== $routeMethodLower) {
                        // Hantera HEAD som fallback för GET
                        if (!($method === 'HEAD' && $routeMethodLower === 'get')) {
                            continue; // Ignorera om HEAD inte kan mappas
                        }
                    }
                }

                return $params;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $options
     */
    public function group(array $options, Closure $routes): void
    {
        $currentPath = $this->path ?? ''; // Spara nuvarande path

        // Extrahera och använd gruppens path
        $pathOption = $options['path'] ?? '';
        if (!is_string($pathOption)) {
            throw new InvalidArgumentException('Group "path" option must be a string.');
        }
        $groupPath = trim($pathOption, '/'); // Exempel: 'admin'
        $this->path = $currentPath . ($groupPath ? '/' . $groupPath : ''); // Exempel: '/admin'

        $groupMiddleware = $options['middleware'] ?? []; // Gruppens middleware
        if (!is_array($groupMiddleware)) {
            $groupMiddleware = (array) $groupMiddleware;
        }

        $existingRoutes = array_keys($this->routes); // Befintliga rutter

        // Kör Closure som skapar nya rutter
        $routes($this);

        // Hitta nya rutter och uppdatera deras path och middleware
        $newRouteKeys = array_diff(array_keys($this->routes), $existingRoutes);

        // $this->path är ?string, efter ?? '' är det alltid string ⇒ ingen extra is_string‑kontroll behövs
        $basePath = $this->path ?? '';
        $basePathTrimmed = trim($basePath, '/');

        foreach ($newRouteKeys as $key) {
            $routeMiddlewares = $this->routes[$key]['middlewares'] ?? [];
            if (!is_array($routeMiddlewares)) {
                $routeMiddlewares = (array) $routeMiddlewares;
            }

            // Tillämpa gruppens middleware
            $this->routes[$key]['middlewares'] = array_merge(
                $groupMiddleware,
                $routeMiddlewares
            );

            // Typ‑säker path för denna route
            $rawPath = $this->routes[$key]['path'] ?? '';
            if (!is_string($rawPath)) {
                $encoded = json_encode($rawPath);
                $rawPath = $encoded === false ? '' : $encoded;
            }
            $routePath = $rawPath;

            // Tillämpa gruppens path (om det inte redan finns)
            if ($basePath !== '' && !str_starts_with($routePath, $basePath)) {
                $routePath = $basePathTrimmed . '/' . ltrim($routePath, '/');
            }

            // Rensa eventuella dubbla snedstreck
            $normalized = preg_replace('#/+#', '/', $routePath);
            if (!is_string($normalized)) {
                throw new RuntimeException('Misslyckades med att normalisera route-path.');
            }

            $this->routes[$key]['path'] = $normalized;
        }

        // Återställ den globala pathen
        $this->path = $currentPath;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function routePathByName(string $routeName, array $data = []): string
    {
        if (!array_key_exists($routeName, self::$routeNames)) {
            throw new InvalidArgumentException('Route name ' . $routeName . ' does not exist');
        }

        $path = self::$routeNames[$routeName];

        return self::extractRoute($path, $data);
    }

    public function name(string $name): Router
    {
        if (array_key_exists($name, self::$routeNames)) {
            throw new InvalidArgumentException('Route name ' . $name . ' already exists');
        }

        // Få det uppdaterade path som hanterar gruppens prefix
        $fullPath = $this->routes[$this->index]['path'] ?? null;
        if (!is_string($fullPath)) {
            throw new RuntimeException('Current route path must be a string before naming the route.');
        }

        // Lägg till det uppdaterade path i `routeNames`
        self::$routeNames[$name] = '/' . trim($fullPath, '/');

        // Tilldela namn till rutten
        $this->routes[$this->index]['name'] = $name;

        return $this;
    }

    /**
     * @param array<int,string>|string $middleware
     */
    public function middleware(array|string $middleware): Router
    {
        if (is_string($middleware)) {
            // Kontrollera om det är en grupp
            if (!array_key_exists($middleware, $this->middlewareGroups)) {
                throw new InvalidArgumentException("Middleware group '$middleware' does not exist");
            }

            $middleware = $this->middlewareGroups[$middleware];
        }

        // Typ‑säkra befintliga middlewares för den aktuella rutten
        $existing = $this->routes[$this->index]['middlewares'] ?? [];
        if (!is_array($existing)) {
            $existing = (array) $existing;
        }

        $this->routes[$this->index]['middlewares'] = array_merge(
            $existing,
            $middleware
        );

        return $this;
    }

    /**
     * @return array<string,string>
     */
    public static function routeNames(): array
    {
        return self::$routeNames;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @param array<int|string,mixed>|Closure $handler
     */
    public function get(string $path, Closure|array $handler): Router
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * @param array<int|string,mixed>|Closure $handler
     */
    public function post(string $path, Closure|array $handler): Router
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * @param array<int|string,mixed>|Closure $handler
     */
    public function put(string $path, Closure|array $handler): Router
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * @param array<int|string,mixed>|Closure $handler
     */
    public function patch(string $path, Closure|array $handler): Router
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * @param array<int|string,mixed>|Closure $handler
     */
    public function delete(string $path, Closure|array $handler): Router
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * @param array<int|string,mixed> $params
     * @phpstan-param array<int|string,mixed> $params
     */
    private function add(string $path, array $params = []): void
    {
        // Om gruppens prefix är definierat, lägg till det innan det nya path sätts
        $fullPath = $this->path ? rtrim($this->path, '/') . '/' . ltrim($path, '/') : $path;

        // Rensa dubbla snedstreck
        $fullPath = preg_replace('#/+#', '/', $fullPath);

        // Spara den fullständiga path för rutten
        $this->routes[] = [
            'path' => $fullPath,
            'params' => $params,
        ];

        // Uppdatera den aktuella indexen för att hänvisa till denna rutt
        $this->index = array_key_last($this->routes);
    }

    /**
     * @param array<int|string,mixed>|Closure $handler
     */
    private function addRoute(string $method, string $path, Closure|array $handler): Router
    {
        $method = mb_strtoupper($method);

        if (!in_array($method, $this->methods, true)) {
            throw new InvalidArgumentException("Method '$method' is not allowed");
        }

        // Beräkna det fullständiga pathet med gruppens prefix (samma som i add())
        $fullPath = $this->path ? rtrim($this->path, '/') . '/' . ltrim($path, '/') : $path;
        $fullPath = preg_replace('#/+#', '/', $fullPath);

        foreach ($this->routes as $route) {
            $routePath = $route['path'] ?? null;
            $routeParams = $route['params'] ?? null;

            if (!is_string($routePath) || !is_array($routeParams)) {
                continue;
            }

            $routeMethod = $routeParams['method'] ?? null;
            if (!is_string($routeMethod)) {
                continue;
            }

            if ($routePath === $fullPath && $routeMethod === $method) {
                throw new InvalidArgumentException("Route path '$fullPath' with method '$method' already exists");
            }
        }

        if (is_callable($handler)) {
            $callable = $handler;
            $handler = [];
            $handler[0] = $callable;
        }

        $handler['method'] = $method;

        // Registrera rutten via add(), men skicka in original-$path så add() bygger samma $fullPath
        $this->add($path, $handler);

        return $this;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function extractRoute(string $url, array $data): string
    {
        $currentUrl = $url;

        if ($data) {
            foreach ($data as $replace) {
                // Typ‑säker konvertering till string
                if (is_scalar($replace) || (is_object($replace) && method_exists($replace, '__toString'))) {
                    $replacement = (string) $replace;
                } else {
                    $encoded = json_encode($replace);
                    $replacement = $encoded === false ? '' : $encoded;
                }

                $result = preg_replace(
                    '/{([a-z]+):([^}]+)}/',
                    $replacement,
                    $currentUrl,
                    1
                );

                if ($result === null) {
                    throw new RuntimeException('Misslyckades med att ersätta route-parametrar i URL: ' . $currentUrl);
                }

                // preg_replace med string‑subject ger string|array, här alltid string
                if (!is_string($result)) {
                    throw new RuntimeException('Ovntat resultat från preg_replace vid route-generering.');
                }

                $currentUrl = $result;
            }
        }

        return $currentUrl;
    }

    private function patternFromRoutePath(string $routePath): string
    {
        $routePath = trim($routePath, '/');
        $segments = explode('/', $routePath);

        $segments = array_map(function (string $segment): string {
            if (preg_match('#^\{([a-z][a-z0-9]*)}$#', $segment, $matches)) {
                return '(?<' . $matches[1] . '>[^/]*)';
            }

            if (preg_match('#^\{([a-z][a-z0-9]*):(.+)}$#', $segment, $matches)) {
                return '(?<' . $matches[1] . '>' . $matches[2] . ')';
            }

            return $segment;
        }, $segments);

        return '#^' . implode('/', $segments) . '$#iu';
    }
}
