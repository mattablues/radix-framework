<?php

declare(strict_types=1);

namespace Radix\Tests\Routing;

use ErrorException;
use PHPUnit\Framework\TestCase;
use Radix\Routing\Router;
use ReflectionClass;

final class RouterMutationTest extends TestCase
{
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

    public function testAddRouteSkipsInvalidRouteEntriesWithoutWarnings(): void
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
        $router->get('/user/{id:\d+}/post/{postid:\d+}', function () {
            return 'User post';
        })->name('user.post.show');

        $path = Router::routePathByName('user.post.show', [10]);

        // Bara första placeholdern ersätts (dödar IncrementInteger 1->2)
        $this->assertSame('/user/10/post/{postid:\d+}', $path);
    }
}
