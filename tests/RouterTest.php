<?php

declare(strict_types=1);

namespace Radix\Tests;

use ErrorException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\Routing\Router;
use ReflectionClass;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testCanAddGetRoute(): void
    {
        $this->router->get('/test', function () {
            return 'Test passed';
        });

        $routes = $this->router->routes();

        /** @var array{path:string,params:array<string,mixed>} $firstRoute */
        $firstRoute = $routes[0];

        /** @var array<string,mixed> $params */
        $params = $firstRoute['params'];

        $this->assertCount(1, $routes);
        $this->assertEquals('/test', $firstRoute['path']);
        $this->assertEquals('GET', $params['method']);
    }

    public function testGroupPathTrimsSlashes(): void
    {
        $router = new Router();

        $router->group(['path' => '/admin/'], function (Router $router): void {
            $router->get('/users', function () {
                return 'ok';
            });
        });

        $routes = $router->routes();
        $this->assertCount(1, $routes);

        $this->assertSame('/admin/users', $routes[0]['path']);
    }

    public function testAddRouteSkipsInvalidRouteEntriesWhenParamsIsNotArray(): void
    {
        $router = new Router();

        // Lägg till en giltig route så routes-arrayen inte är tom
        $router->get('/valid', function () {
            return 'ok';
        });

        // Injicera en korrupt route: params är string, inte array
        $ref = new ReflectionClass($router);
        $routesProp = $ref->getProperty('routes');
        $routesProp->setAccessible(true);

        $routes = $routesProp->getValue($router);
        if (!is_array($routes)) {
            $routes = [];
        }

        $routes[] = [
            'path' => '/duplicate',
            'params' => 'not-an-array',
        ];

        $routesProp->setValue($router, $routes);

        // Gör warnings till undantag så LogicalOr-mutanten avslöjas
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            // Ska inte trigga varningar även om korrupt route finns
            $router->get('/duplicate', function () {
                return 'dup';
            });
        } finally {
            restore_error_handler();
        }

        $this->assertNotEmpty($router->routes());
    }

    public function testRoutePathByNameReplacesOnlyOnePlaceholderPerValue(): void
    {
        // Rensa statiska routeNames
        $ref = new ReflectionClass(Router::class);
        $namesProp = $ref->getProperty('routeNames');
        $namesProp->setAccessible(true);
        $namesProp->setValue(null, []);

        $router = new Router();
        $router->get('/user/{id:\d+}/post/{postId:\d+}', function () {
            return 'User post';
        })->name('user.post.show');

        $path = Router::routePathByName('user.post.show', [10]);

        // Bara första placeholdern ersätts
        $this->assertSame('/user/10/post/{postId:\d+}', $path);
    }

    public function testRoutePathByNameThrowsExceptionIfRouteNotFound(): void
    {
        // Rensa statiska routeNames för ett rent test
        $ref = new ReflectionClass(Router::class);
        $namesProp = $ref->getProperty('routeNames');
        $namesProp->setAccessible(true);
        $namesProp->setValue(null, []);

        $routeName = 'non.existent.route';

        // Vi kräver exakt matchning på felmeddelandet för att döda Concat-mutanter
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route name ' . $routeName . ' does not exist');

        Router::routePathByName($routeName);
    }

    public function testCanMatchSimpleParameterRoute(): void
    {
        $this->router->get('/user/{id}', function () {
            return 'User detail';
        });

        $route = $this->router->match('/user/42', 'GET');

        $this->assertNotFalse($route);
        $this->assertSame('42', $route['id']);
    }

    public function testCanMatchRoute(): void
    {
        $this->router->get('/test/{id:\d+}', function () {
            return 'Test passed';
        });

        $route = $this->router->match('/test/123', 'GET');

        $this->assertNotFalse($route);
        $this->assertEquals('123', $route['id']);
    }

    public function testMatchSkipsRoutesWithWrongMethodAndFindsLaterMatch(): void
    {
        $router = new Router();

        // Först en POST-route
        $router->post('/same', function () {
            return 'POST';
        });

        // Sedan en GET-route med samma path
        $router->get('/same', function () {
            return 'GET';
        });

        $params = $router->match('/same', 'GET');

        $this->assertNotFalse($params);
        $this->assertSame('GET', $params['method']);
    }

    public function testMatchSkipsRoutesWithoutMethodParamWithoutWarnings(): void
    {
        $router = new Router();

        // Injicera en route med path men UTAN 'method' i params
        $ref        = new ReflectionClass($router);
        $routesProp = $ref->getProperty('routes');
        $routesProp->setAccessible(true);

        $routes = $routesProp->getValue($router);
        if (!is_array($routes)) {
            $routes = [];
        }

        $routes[] = [
            'path'   => '/no-method',
            'params' => [], // ingen 'method'-nyckel
        ];

        $routesProp->setValue($router, $routes);

        // Gör warnings till undantag
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            $result = $router->match('/no-method', 'GET');
        } finally {
            restore_error_handler();
        }

        // Viktigt: vi ska inte få någon varning när 'method' saknas.
        // Originalkoden returnerar då helt enkelt params (här tom array).
        $this->assertSame([], $result);
    }

    public function testRouteNotFound(): void
    {
        $this->router->get('/test', function () {
            return 'Test passed';
        });

        $route = $this->router->match('/non-existent', 'GET');

        $this->assertFalse($route);
    }

    public function testCannotAddDuplicateRoute(): void
    {
        $this->router->get('/test', function () {
            return 'Test passed';
        });

        $this->expectException(InvalidArgumentException::class);
        $this->router->get('/test', function () {
            return 'Duplicate';
        });
    }

    public function testCanAddMiddlewareToRoute(): void
    {
        $this->router->get('/test', function () {
            return 'Test passed';
        })->middleware(['auth', 'logging']);

        $routes = $this->router->routes();

        /** @var array<string,mixed> $route */
        $route = $routes[0];

        if (!array_key_exists('middlewares', $route)) {
            self::fail('Route is expected to have middlewares key');
        }

        $this->assertSame(['auth', 'logging'], $route['middlewares']);
    }

    public function testCanAddMiddlewareToGroup(): void
    {
        $this->router->group(['middleware' => ['auth', 'logging']], function (Router $router): void {
            $router->get('/route1', function () {
                return 'Route 1';
            });

            $router->post('/route2', function () {
                return 'Route 2';
            });
        });

        $routes = $this->router->routes();

        /** @var array{middlewares?:array<int,string>} $route0 */
        $route0 = $routes[0];
        /** @var array{middlewares?:array<int,string>} $route1 */
        $route1 = $routes[1];

        if (!array_key_exists('middlewares', $route0)) {
            self::fail('First route is expected to have middlewares key');
        }
        if (!array_key_exists('middlewares', $route1)) {
            self::fail('Second route is expected to have middlewares key');
        }

        $this->assertSame(['auth', 'logging'], $route0['middlewares']);
        $this->assertSame(['auth', 'logging'], $route1['middlewares']);
    }

    public function testGroupMiddlewareDoesNotAffectOtherRoutes(): void
    {
        $this->router->group(['middleware' => ['auth']], function (Router $router): void {
            $router->get('/secured', function () {
                return 'Secured route';
            });
        });

        $this->router->get('/public', function () {
            return 'Public route';
        });

        $routes = $this->router->routes();

        /** @var array<string,mixed> $secured */
        $secured = $routes[0];
        /** @var array<string,mixed> $public */
        $public = $routes[1];

        // secured ska ha middlewares
        $this->assertArrayHasKey('middlewares', $secured);
        /** @var array<int,string> $middlewares */
        $middlewares = $secured['middlewares'];
        $this->assertSame(['auth'], $middlewares);

        // public ska inte ha middlewares
        $this->assertArrayNotHasKey('middlewares', $public);
    }

    public function testParameterPlaceholderMustMatchWholeSegment(): void
    {
        // Här är segmentet "{id}extra" – det ska INTE tolkas som en parameter
        $this->router->get('/test/{id}extra', function () {
            return 'Should not match as parameter';
        });

        $route = $this->router->match('/test/123extra', 'GET');

        // Rätt beteende: ingen match alls
        $this->assertFalse($route);
    }

    public function testConstrainedParameterMustMatchWholeSegment(): void
    {
        // Segmentet "{id:\d+}suffix" ska inte tolkas som en ren parameter
        $this->router->get('/test/{id:\d+}suffix', function () {
            return 'Should not match as parameter';
        });

        // Den här URL:en matchar bara om "{id:\d+}" felaktigt får vara en del av segmentet
        $route = $this->router->match('/test/123', 'GET');

        // Korrekt beteende: ingen match
        $this->assertFalse($route);
    }

    public function testSimpleParameterCannotBeOnlySuffixInSegment(): void
    {
        // Segmentet "extra{id}" ska inte behandlas som en parameter
        $this->router->get('/test/extra{id}', function () {
            return 'Should not match as parameter';
        });

        $route = $this->router->match('/test/extra123', 'GET');

        $this->assertFalse($route);
    }

    public function testConstrainedParameterCannotBeOnlySuffixInSegment(): void
    {
        // Segmentet "prefix{id:\d+}" ska inte tolkas som en parameter
        $this->router->get('/test/prefix{id:\d+}', function () {
            return 'Should not match as parameter';
        });

        // Med muterad regex blir mönstret "^test/(?<id>\d+)$" och detta matchar,
        // men korrekt implementation ska ge ingen match.
        $route = $this->router->match('/test/123', 'GET');

        $this->assertFalse($route);
    }

    public function testCallableHandlerStoredAtIndexZero(): void
    {
        $handler = function () {
            return 'Callable';
        };

        $this->router->get('/callable-test', $handler);

        $routes = $this->router->routes();

        /** @var array{path:string,params:array<string,mixed>} $firstRoute */
        $firstRoute = $routes[0];

        /** @var array<string|int,mixed> $params */
        $params = $firstRoute['params'];

        // Callablen ska ligga på index 0
        $this->assertArrayHasKey(0, $params);
        $this->assertIsCallable($params[0]);

        // Vi förväntar oss INTE att den ligger på index 1
        $this->assertArrayNotHasKey(1, $params);
    }

    public function testCannotAddDuplicateRouteWithinPathGroup(): void
    {
        // Första gruppen: definiera /admin/users (GET)
        $this->router->group(['path' => '/admin'], function (Router $router): void {
            $router->get('/users', function () {
                return 'First';
            });
        });

        // Andra gruppen: försöker definiera samma /admin/users (GET) igen → ska kasta
        $this->expectException(InvalidArgumentException::class);

        $this->router->group(['path' => '/admin'], function (Router $router): void {
            $router->get('/users', function () {
                return 'Second';
            });
        });
    }

    public function testRouterSkipsInvalidRouteEntriesWithoutWarnings(): void
    {
        // Lägg till en giltig route så att $routes inte är tom
        $this->router->get('/valid', function () {
            return 'ok';
        });

        // Injicera en felaktig route-post där 'params' inte är en array
        $reflection = new ReflectionClass($this->router);
        $routesProp = $reflection->getProperty('routes');
        $routesProp->setAccessible(true);
        $routes = $routesProp->getValue($this->router);

        if (!is_array($routes)) {
            $routes = [];
        }

        $routes[] = [
            'path'   => '/duplicate',
            'params' => 'not-an-array', // ska normalt filtreras bort av type-kollen
        ];

        $routesProp->setValue($this->router, $routes);

        // Konvertera PHP-warnings till undantag så vi märker skillnad
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            // Detta anropar addRoute() med samma path/method.
            $this->router->get('/duplicate', function () {
                return 'dup';
            });
        } finally {
            restore_error_handler();
        }

        // Kontrollera att routes() fortfarande går att anropa utan att state gått sönder
        $this->assertNotEmpty($this->router->routes());
    }

    public function testInvalidRouteEntryDoesNotPreventDuplicateDetectionAndDoesNotTriggerWarnings(): void
    {
        // Skapa en första giltig route så att routes-arrayen inte är tom
        $this->router->get('/existing', function () {
            return 'ok';
        });

        // Reflektera fram och injicera en FEL route först i listan
        $reflection = new ReflectionClass($this->router);
        $routesProp = $reflection->getProperty('routes');
        $routesProp->setAccessible(true);

        $routes = $routesProp->getValue($this->router);
        if (!is_array($routes)) {
            $routes = [];
        }

        // Felaktig post: params är en sträng, inte en array
        array_unshift($routes, [
            'path'   => '/duplicate',
            'params' => 'not-an-array',
        ]);

        // En giltig post vi ska försöka duplicera
        $routes[] = [
            'path'   => '/duplicate',
            'params' => ['method' => 'GET'],
        ];

        $routesProp->setValue($this->router, $routes);

        // Konvertera warnings till undantag för att fånga LogicalOr-mutanten
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            // Nu försöker vi lägga till en duplicerad route
            $this->expectException(InvalidArgumentException::class);

            $this->router->get('/duplicate', function () {
                return 'dup';
            });
        } finally {
            restore_error_handler();
        }
    }

    public function testMultipleMiddlewareCallsAreMerged(): void
    {
        $this->router->get('/multi', function () {
            return 'Test';
        })
            ->middleware(['auth'])
            ->middleware(['logging']);

        $route = $this->router->routes()[0];

        /** @var array<string,mixed> $route */
        $this->assertArrayHasKey('middlewares', $route);
        $this->assertSame(['auth', 'logging'], $route['middlewares']);
    }

    public function testMiddlewareMergesNonArrayExistingWithoutWarnings(): void
    {
        // Skapa en route så vi har något på aktuellt index
        $this->router->get('/corrupt', function () {
            return 'X';
        });

        // Sabba befintlig middlewares till en sträng
        $reflection = new ReflectionClass($this->router);
        $routesProp = $reflection->getProperty('routes');
        $routesProp->setAccessible(true);

        $routes = $routesProp->getValue($this->router);
        if (!is_array($routes)) {
            $routes = [];
        }

        /** @var array<int,array<string,mixed>> $routes */

        // Sätt middlewares till sträng på den sista (aktuella) rutten
        $lastKey = array_key_last($routes);
        if ($lastKey === null) {
            $this->fail('No routes available to corrupt.');
        }

        $routes[$lastKey]['middlewares'] = 'auth';
        $routesProp->setValue($this->router, $routes);

        // Konvertera warnings till undantag
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            // Original: castar 'auth' till ['auth'] och mergar med ['logging']
            $this->router->middleware(['logging']);
        } finally {
            restore_error_handler();
        }

        // Verifiera att båda ligger kvar
        $routes = $this->router->routes();

        /** @var array{middlewares?:array<int,string>} $route */
        $route  = $routes[$lastKey];

        if (!array_key_exists('middlewares', $route)) {
            self::fail('Route is expected to have middlewares key');
        }

        $this->assertSame(['auth', 'logging'], $route['middlewares']);
    }

    public function testNamedRouteStoresAndReturnsCorrectAbsolutePath(): void
    {
        // Säkerställ att statiska routeNames är tomma för detta test
        $ref = new ReflectionClass(Router::class);
        $namesProp = $ref->getProperty('routeNames');
        $namesProp->setAccessible(true);
        $namesProp->setValue(null, []);

        $this->router->get('/test', function () {
            return 'Named route';
        })->name('test.route');

        $names = Router::routeNames();

        $this->assertArrayHasKey('test.route', $names);
        $this->assertSame('/test', $names['test.route']);

        $path = Router::routePathByName('test.route');
        $this->assertSame('/test', $path);
    }

    public function testNamedRouteWithGroupPathStoresFullPath(): void
    {
        // Rensa routeNames även här
        $ref = new ReflectionClass(Router::class);
        $namesProp = $ref->getProperty('routeNames');
        $namesProp->setAccessible(true);
        $namesProp->setValue(null, []);

        $this->router->group(['path' => '/admin'], function (Router $router): void {
            $router->get('/users', function () {
                return 'Admin users';
            })->name('admin.users');
        });

        $names = Router::routeNames();

        $this->assertArrayHasKey('admin.users', $names);
        $this->assertSame('/admin/users', $names['admin.users']);

        $path = Router::routePathByName('admin.users');
        $this->assertSame('/admin/users', $path);
    }

    public function testRoutePathByNameReplacesSingleParameter(): void
    {
        // Rensa statiska routeNames
        $ref = new ReflectionClass(Router::class);
        $namesProp = $ref->getProperty('routeNames');
        $namesProp->setAccessible(true);
        $namesProp->setValue(null, []);

        // Skapa en named route med parameter-placeholder
        $this->router->get('/user/{id:\d+}', function () {
            return 'User';
        })->name('user.show');

        // Generera path med ersatt parameter
        $path = Router::routePathByName('user.show', [42]);

        $this->assertSame('/user/42', $path);
    }

    public function testRoutePathByNameJsonEncodesNonScalarValues(): void
    {
        // Rensa statiska routeNames
        $ref = new ReflectionClass(Router::class);
        $namesProp = $ref->getProperty('routeNames');
        $namesProp->setAccessible(true);
        $namesProp->setValue(null, []);

        // Skapa named route med en "fri" placeholder (.+) så JSON-strängen är tillåten
        $this->router->get('/values/{ids:.+}', function () {
            return 'Values';
        })->name('values.index');

        // Skicka in en array som ska json-kodas
        $path = Router::routePathByName('values.index', [[1, 2]]);

        // Korrekt: /values/[1,2] (från json_encode)
        $this->assertSame('/values/[1,2]', $path);
    }

    public function testGroupMiddlewareIsMergedWithRouteSpecificMiddleware(): void
    {
        $this->router->group(['middleware' => ['auth']], function (Router $router): void {
            $router->get('/with-extra', function () {
                return 'With extra';
            })->middleware(['logging']);
        });

        $routes = $this->router->routes();

        /** @var array<string,mixed> $route */
        $route = $routes[0];

        if (!array_key_exists('middlewares', $route)) {
            self::fail('Route is expected to have middlewares key');
        }

        $this->assertSame(['auth', 'logging'], $route['middlewares']);
    }

    public function testGroupMiddlewareCastsNonArrayRouteMiddlewaresWithoutWarnings(): void
    {
        // Vi omsluter group() i en error handler som kastar på warnings
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            $this->router->group(['middleware' => ['group']], function (Router $router): void {
                // Skapa en route i gruppen
                $router->get('/corrupt-group', function () {
                    return 'X';
                });

                // Korrumpera dess middlewares-fält till en sträng innan group() gör sin merge
                $ref        = new ReflectionClass($router);
                $routesProp = $ref->getProperty('routes');
                $routesProp->setAccessible(true);
                $routes     = $routesProp->getValue($router);

                if (!is_array($routes)) {
                    $routes = [];
                }

                $lastKey = array_key_last($routes);
                if ($lastKey === null) {
                    throw new ErrorException('No routes available to corrupt.', E_USER_WARNING);
                }

                /** @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible */
                $routes[$lastKey]['middlewares'] = 'route-mw';
                $routesProp->setValue($router, $routes);
            });
        } finally {
            restore_error_handler();
        }

        // Om vi kommer hit utan ErrorException så har group()
        // castat strängen till array och mergat utan warnings
        $routes = $this->router->routes();

        $route = end($routes);
        if ($route === false) {
            self::fail('No routes available after group().');
        }

        /** @var array<string,mixed> $route */
        $this->assertArrayHasKey('middlewares', $route);
        $this->assertSame(['group', 'route-mw'], $route['middlewares']);
    }

    public function testGroupPathAndMiddlewareDoNotAffectRoutesCreatedBeforeGroup(): void
    {
        // Route skapad innan någon grupp
        $this->router->get('/public-before', function () {
            return 'Public before';
        });

        // Grupp med path + middleware
        $this->router->group(['path' => '/admin', 'middleware' => ['auth']], function (Router $router): void {
            $router->get('/dashboard', function () {
                return 'Dashboard';
            });
        });

        $routes = $this->router->routes();

        /** @var array<string,mixed> $first */
        $first = $routes[0];
        /** @var array<string,mixed> $second */
        $second = $routes[1];

        // Första route: skapad före gruppen → ska INTE påverkas
        $this->assertSame('/public-before', $first['path']);
        $this->assertArrayNotHasKey('middlewares', $first);

        // Andra route: skapad i gruppen → ska ha path-prefix + middleware
        $this->assertSame('/admin/dashboard', $second['path']);
        $this->assertArrayHasKey('middlewares', $second);
        /** @var array<int,string> $middlewares */
        $middlewares = $second['middlewares'];
        $this->assertSame(['auth'], $middlewares);
    }

    public function testNestedGroupPathsAreCombined(): void
    {
        $this->router->group(['path' => '/api'], function (Router $router): void {
            $router->group(['path' => '/admin'], function (Router $router): void {
                $router->get('/users', function () {
                    return 'Nested users';
                });
            });
        });

        $routes = $this->router->routes();

        // Enda route ska ha både /api och /admin i sitt path
        $this->assertCount(1, $routes);
        $this->assertSame('/api/admin/users', $routes[0]['path']);
    }

    public function testGroupScalarMiddlewareIsCastedToArrayWithoutWarnings(): void
    {
        // Konvertera warnings till undantag
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            $this->router->group(['middleware' => 'auth'], function (Router $router): void {
                $router->get('/secure', function () {
                    return 'Secure';
                });
            });
        } finally {
            restore_error_handler();
        }

        $routes = $this->router->routes();
        $this->assertCount(1, $routes);

        /** @var array<string,mixed> $route */
        $route = $routes[0];

        if (!array_key_exists('middlewares', $route)) {
            self::fail('Route is expected to have middlewares key');
        }

        $this->assertSame(['auth'], $route['middlewares']);
    }

    public function testHeadDoesNotMatchPostRouteInRouter(): void
    {
        $router = new Router();

        // Endast POST-route, ingen GET med samma path
        $router->post('/contact', function () {
            return 'Contact POST';
        });

        $params = $router->match('/contact', 'HEAD');

        // Korrekt: ingen match för HEAD mot en ren POST-route
        $this->assertFalse($params);
    }

    public function testRoutePathByNameUsesObjectToStringWhenAvailable(): void
    {
        // Rensa statiska routeNames
        $ref = new ReflectionClass(Router::class);
        $namesProp = $ref->getProperty('routeNames');
        $namesProp->setAccessible(true);
        $namesProp->setValue(null, []);

        // Named route med en placeholder för slug
        $this->router->get('/articles/{slug:[^/]+}', function () {
            return 'Article';
        })->name('article.show');

        // Objekt med __toString
        $slugObject = new class {
            public function __toString(): string
            {
                return 'my-article';
            }
        };

        // Generera path med objektet som parameter
        $path = Router::routePathByName('article.show', [$slugObject]);

        // Korrekt: objektet castas via __toString till "my-article" utan JSON-kodning
        $this->assertSame('/articles/my-article', $path);
    }
}
