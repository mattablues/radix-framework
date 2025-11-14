<?php

declare(strict_types=1);

namespace Radix\Routing;

use Closure;
use InvalidArgumentException;

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
            $pattern = $this->patternFromRoutePath($route['path']);

            if (preg_match($pattern, $path, $matches)) {
                $matches = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                $params = array_merge($matches, $route['params']);

                if (isset($route['middlewares'])) {
                    $params['middlewares'] = $route['middlewares'];
                }

                if ($method && array_key_exists('method', $params)) {
                    if (mb_strtolower($method) !== mb_strtolower($params['method'])) {
                        // Hantera HEAD som fallback för GET
                        if (!($method === 'HEAD' && mb_strtolower($params['method']) === 'get')) {
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
        $groupPath = trim($options['path'] ?? '', '/'); // Exempel: 'admin'
        $this->path = $currentPath . ($groupPath ? '/' . $groupPath : ''); // Exempel: '/admin'

        $groupMiddleware = $options['middleware'] ?? []; // Gruppens middleware
        $existingRoutes = array_keys($this->routes); // Befintliga rutter

        // Kör Closure som skapar nya rutter
        $routes($this);

        // Hitta nya rutter och uppdatera deras path och middleware
        $newRouteKeys = array_diff(array_keys($this->routes), $existingRoutes);

        foreach ($newRouteKeys as $key) {
            // Tillämpa gruppens middleware
            $this->routes[$key]['middlewares'] = array_merge(
                $groupMiddleware,
                $this->routes[$key]['middlewares'] ?? []
            );

            // Tillämpa gruppens path (om det inte redan finns)
            if (!str_starts_with($this->routes[$key]['path'], $this->path)) {
                $this->routes[$key]['path'] = trim($this->path, '/') . '/' . ltrim($this->routes[$key]['path'], '/');
            }

            // Rensa eventuella dubbla snedstreck
            $this->routes[$key]['path'] = preg_replace('#/+#', '/', $this->routes[$key]['path']);
        }

        // Återställ den globala pathen
        $this->path = $currentPath;
    }

    /**
     * @param array<int,mixed> $data
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
        $fullPath = $this->routes[$this->index]['path'];

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

        $this->routes[$this->index]['middlewares'] = array_merge(
            $this->routes[$this->index]['middlewares'] ?? [],
            (array) $middleware
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
     * @param array<string,mixed> $params
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
            if ($route['path'] === $fullPath && $route['params']['method'] === $method) {
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
     * @param array<int,mixed> $data
     */
    private static function extractRoute(string $url, array $data): string
    {
        if ($data) {
            foreach ($data as $replace) {
                $url = preg_replace('/{([a-z]+):([^}]+)}/', (string)$replace, $url, 1);
            }
        }

        return $url;
    }

    private function patternFromRoutePath(string $routePath): string
    {
        $routePath = trim($routePath, '/');
        $segments = explode('/', $routePath);

        $segments = array_map(function (string $segment): string {
            if(preg_match('#^\{([a-z][a-z0-9]*)}$#', $segment, $matches)) {
                return '(?<' . $matches[1] . '>[^/]*)';
            }

            if(preg_match('#^\{([a-z][a-z0-9]*):(.+)}$#', $segment, $matches)) {
                return '(?<' . $matches[1] . '>' . $matches[2] . ')';
            }

            return $segment;
        }, $segments);

        return '#^' . implode('/', $segments) . '$#iu';
    }
}