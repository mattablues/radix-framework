<?php

declare(strict_types=1);

namespace Radix\Tests\Container;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Radix\Container\Container;
use Radix\Container\Definition;
use Radix\Container\Exception\ContainerConfigException;
use Radix\Container\Exception\ContainerDependencyInjectionException;
use Radix\Container\Reference;
use Radix\Container\Resolver;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use stdClass;

final class ResolverTest extends TestCase
{
    public function testInvokeMethodsAndPropertiesAndSetResolvedAreExecuted(): void
    {
        $container = new Container();
        $resolver  = new Resolver($container);

        // Låt definitionen peka direkt på vår testklass
        $definition = new Definition(TestServiceWithSetter::class);
        $definition->addMethodCall('setFlag', []);        // inga argument
        $definition->setProperties(['foo' => 'bar']);     // denna metod är inte fluent

        $instance = $resolver->resolve($definition);

        $this->assertInstanceOf(TestServiceWithSetter::class, $instance);
        /** @var TestServiceWithSetter $instance */
        $this->assertTrue($instance->flag, 'Metodanrop via invokeMethods ska ha kört setFlag().');
        $this->assertSame('bar', $instance->foo, 'Property-injektion via invokeProperties ska ha satt foo=bar.');
        $this->assertSame($instance, $definition->getResolved(), 'setResolved ska ha sparat samma instans.');
    }


    public function testBuiltinTypeWithDefaultIsNotResolvedFromContainer(): void
    {
        $container = new Container();
        $resolver  = new Resolver($container);

        $definition = new Definition(ServiceWithBuiltinDefault::class);
        $definition->setAutowired(true);

        /** @var ServiceWithBuiltinDefault $instance */
        $instance = $resolver->resolve($definition);

        $this->assertSame(42, $instance->value);
    }

    public function testResolveArgumentsResolvesAllReferencesAndPreservesAllItems(): void
    {
        $container = new Container();
        $resolver  = new Resolver($container);

        $container->add('one', stdClass::class);
        $container->add('two', ArrayObject::class);

        $arguments = [
            new Reference('one'),
            'plain',
            new Reference('two'),
        ];

        $resolved = $this->callResolveArguments($resolver, $arguments);

        $this->assertInstanceOf(stdClass::class, $resolved[0]);
        $this->assertSame('plain', $resolved[1]);
        $this->assertInstanceOf(ArrayObject::class, $resolved[2]);
        $this->assertCount(3, $resolved, 'resolveArguments får inte kapa argumentlistan.');
    }

    /**
     * Anropar private resolveArguments via Reflection.
     *
     * @param array<int|string, mixed> $arguments
     * @return array<int|string, mixed>
     */
    private function callResolveArguments(Resolver $resolver, array $arguments): array
    {
        $refClass = new ReflectionClass($resolver);
        $method   = $refClass->getMethod('resolveArguments');
        $method->setAccessible(true);

        /** @var array<int|string, mixed> $result */
        $result = $method->invoke($resolver, $arguments);

        return $result;
    }

    public function testResolveDependenciesCachesByParameterNameAndDeclaringClass(): void
    {
        $container = new Container();
        $resolver  = new Resolver($container);

        $params = (new ReflectionMethod(ResolverCacheFixture::class, '__construct'))->getParameters();

        $first  = new stdClass();
        $second = new stdClass();

        $resolved1 = $this->callResolveDependencies($resolver, $params, [0 => $first]);
        $this->assertCount(1, $resolved1);
        $this->assertSame($first, $resolved1[0]);

        // Andra körningen försöker “byta ut” argumentet.
        // Om cacheKey/declaringClassName byggs fel kommer detta INTE bli en cache-hit.
        $resolved2 = $this->callResolveDependencies($resolver, $params, [0 => $second]);
        $this->assertCount(1, $resolved2);
        $this->assertSame(
            $first,
            $resolved2[0],
            'resolveDependencies ska använda cache baserat på parameter + declaring class (inte låta nytt argument ersätta efter cache-hit).'
        );
    }

    public function testParseConcreteWhenConcreteIsObjectMakesDefinitionSharedAndResolved(): void
    {
        $container = new Container();
        $resolver  = new Resolver($container);

        $obj = new stdClass();

        // När concrete är ett objekt ska parseConcrete frysa det som resolved + shared.
        $definition = new Definition($obj);

        $resolverRef = new ReflectionClass($resolver);
        $method      = $resolverRef->getMethod('parseConcrete');
        $method->setAccessible(true);
        $method->invoke($resolver, $definition);

        $this->assertTrue(
            $definition->isShared(),
            'När Definition konstrueras med ett objekt ska Resolver markera den som shared (annars är den inte en “frozen instance”).'
        );

        $this->assertSame(
            $obj,
            $definition->getResolved(),
            'Objektet ska sparas som resolved instans.'
        );
    }

    public function testResolveDependenciesDoesNotBreakLoopOnCacheHitAndReturnsAllDependencies(): void
    {
        $container = new Container();
        $resolver  = new Resolver($container);

        $params = (new ReflectionMethod(ResolverCacheFixtureWithTwoParams::class, '__construct'))->getParameters();

        $cached = new stdClass();

        // Första körningen: cache:a param #0.
        $resolved1 = $this->callResolveDependencies($resolver, $params, [0 => $cached]);
        $this->assertCount(2, $resolved1);
        $this->assertSame($cached, $resolved1[0]);
        $this->assertSame(7, $resolved1[1], 'Andra parametern ska falla tillbaka på defaultvärdet.');

        // Andra körningen: inga arguments => param #0 ska tas från cache,
        // men loopen måste fortsätta så param #1 också löses (default).
        $resolved2 = $this->callResolveDependencies($resolver, $params, []);
        $this->assertCount(
            2,
            $resolved2,
            'Cache-hit på första dependency får inte stoppa loopen (continue krävs; break-mutanten ska dö).'
        );
        $this->assertSame($cached, $resolved2[0]);
        $this->assertSame(7, $resolved2[1]);
    }

    /**
     * Anropar private resolveDependencies via Reflection.
     *
     * @param array<int, ReflectionParameter> $dependencies
     * @param array<int|string, mixed> $arguments
     * @return array<int, mixed>
     */
    private function callResolveDependencies(Resolver $resolver, array $dependencies, array $arguments): array
    {
        $refClass = new ReflectionClass($resolver);
        $method   = $refClass->getMethod('resolveDependencies');
        $method->setAccessible(true);

        /** @var array<int, mixed> $result */
        $result = $method->invoke($resolver, $dependencies, $arguments);

        return $result;
    }

    public function testResolveDependenciesStoresCacheKeyAsNameColonDeclaringClass(): void
    {
        $container = new Container();
        $resolver  = new Resolver($container);

        $params = (new ReflectionMethod(ResolverCacheFixture::class, '__construct'))->getParameters();

        $obj = new stdClass();

        // Kör så att cachen fylls.
        $resolved = $this->callResolveDependencies($resolver, $params, [0 => $obj]);
        $this->assertCount(1, $resolved);
        $this->assertSame($obj, $resolved[0]);

        $cache = $this->getResolvedDependenciesCache($resolver);

        $expectedKey = 'a:' . ResolverCacheFixture::class;

        $this->assertArrayHasKey(
            $expectedKey,
            $cache,
            'Resolver ska cacha dependencies med nyckeln "{paramName}:{declaringClassName}".'
        );
        $this->assertSame(
            $obj,
            $cache[$expectedKey],
            'Cachevärdet för nyckeln ska vara det lösta argumentet.'
        );
    }

    public function testFactoryArrayWithNonStringSecondElementIsRejectedAsNotCallable(): void
    {
        $container = new Container();
        $resolver  = new Resolver($container);

        $definition = new Definition(TestServiceWithSetter::class);

        // Forcea in “nästan-rätt” factory: [string, int]
        $defRef = new ReflectionClass($definition);
        $prop   = $defRef->getProperty('factory');
        $prop->setAccessible(true);
        $prop->setValue($definition, [stdClass::class, 123]);

        $resolverRef = new ReflectionClass($resolver);
        $method      = $resolverRef->getMethod('createFromFactory');
        $method->setAccessible(true);

        $this->expectException(ContainerConfigException::class);
        $this->expectExceptionMessage('The factory provided is not callable.');

        $method->invoke($resolver, $definition);
    }

    public function testFactoryArrayWithNonStringFirstElementIsRejectedAsNotCallable(): void
    {
        $container = new Container();
        $resolver  = new Resolver($container);

        $definition = new Definition(TestServiceWithSetter::class);

        // Forcea in “nästan-rätt” factory: [int, string]
        $defRef = new ReflectionClass($definition);
        $prop   = $defRef->getProperty('factory');
        $prop->setAccessible(true);
        $prop->setValue($definition, [123, 'create']);

        $resolverRef = new ReflectionClass($resolver);
        $method      = $resolverRef->getMethod('createFromFactory');
        $method->setAccessible(true);

        $this->expectException(ContainerConfigException::class);
        $this->expectExceptionMessage('The factory provided is not callable.');

        $method->invoke($resolver, $definition);
    }

    public function testExistingFactoryClassButMissingMethodThrowsMethodDoesNotExistMessage(): void
    {
        $container = new Container();
        $resolver  = new Resolver($container);

        $definition = new Definition(TestServiceWithSetter::class);

        $defRef = new ReflectionClass($definition);
        $prop   = $defRef->getProperty('factory');
        $prop->setAccessible(true);

        // Klass finns (stdClass), metod finns inte
        $prop->setValue($definition, [stdClass::class, 'nope']);

        $resolverRef = new ReflectionClass($resolver);
        $method      = $resolverRef->getMethod('createFromFactory');
        $method->setAccessible(true);

        $this->expectException(ContainerDependencyInjectionException::class);
        $this->expectExceptionMessage("Factory method 'nope' does not exist in class '" . stdClass::class . "'.");

        $method->invoke($resolver, $definition);
    }

    public function testInvalidStaticFactoryArrayThrowsDependencyInjectionException(): void
    {
        $container = new Container();
        $resolver  = new Resolver($container);

        $definition = new Definition(TestServiceWithSetter::class);

        $defRef = new ReflectionClass($definition);
        $prop   = $defRef->getProperty('factory');
        $prop->setAccessible(true);
        $prop->setValue($definition, ['NonExistingClass', 'create']);

        $resolverRef = new ReflectionClass($resolver);
        $method      = $resolverRef->getMethod('createFromFactory');
        $method->setAccessible(true);

        $this->expectException(ContainerDependencyInjectionException::class);
        $this->expectExceptionMessage("Factory class 'NonExistingClass' does not exist.");

        $method->invoke($resolver, $definition);
    }

    /**
     * @return array<string, mixed>
     */
    private function getResolvedDependenciesCache(Resolver $resolver): array
    {
        $refClass = new ReflectionClass($resolver);
        $prop     = $refClass->getProperty('resolvedDependenciesCache');
        $prop->setAccessible(true);

        /** @var array<string, mixed> $cache */
        $cache = $prop->getValue($resolver);

        return $cache;
    }
}

/**
 * Hjälpklass för att testa invokeMethods + invokeProperties.
 */
final class TestServiceWithSetter
{
    public bool $flag = false;
    public string $foo = '';

    public function setFlag(): void
    {
        $this->flag = true;
    }
}

/**
 * Hjälpklass: inbyggd typ med default, för att testa resolveDependencies-logiken.
 */
final class ServiceWithBuiltinDefault
{
    public function __construct(public int $value = 42) {}
}


/**
 * Fixtureklass vars constructor-parametrar används för att få ReflectionParameter med declaring class.
 */
final class ResolverCacheFixture
{
    public function __construct(public stdClass $a) {}
}

final class ResolverCacheFixtureWithTwoParams
{
    public function __construct(public stdClass $a, public int $b = 7) {}
}
