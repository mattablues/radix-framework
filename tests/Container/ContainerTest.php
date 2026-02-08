<?php

declare(strict_types=1);

namespace Radix\Tests\Container;

use Countable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Radix\Container\Container;
use Radix\Container\Definition;
use Radix\Container\Exception\ContainerDependencyInjectionException;
use Radix\Container\Exception\ContainerNotFoundException;
use stdClass;

class ContainerTest extends TestCase
{
    public function testAddAndGetService(): void
    {
        $container = new Container();
        $object = new stdClass();

        $container->add('test.service', $object);
        $retrievedService = $container->get('test.service');

        $this->assertSame($object, $retrievedService);
    }

    public function testHasService(): void
    {
        $container = new Container();
        $container->add('test.service', new stdClass());

        $this->assertTrue($container->has('test.service'));
        $this->assertFalse($container->has('non.existent.service'));
    }

    public function testContainerIsRegisteredAsServiceInConstructor(): void
    {
        $container = new Container();

        $resolved = $container->get(Container::class);

        $this->assertSame($container, $resolved);
    }

    public function testResolveAliasIsProtectedSoItCanBeUsedBySubclasses(): void
    {
        $container = new class extends Container {
            public function exposeResolveAlias(string $id): string
            {
                return $this->resolveAlias($id);
            }
        };

        $service = new stdClass();
        $container->add('real.service', $service);
        $container->setAlias('alias.service', 'real.service');

        $this->assertSame('real.service', $container->exposeResolveAlias('alias.service'));
    }

    public function testResolveInstanceIsProtectedSoItCanBeUsedBySubclasses(): void
    {
        $container = new class extends Container {
            public function exposeResolveInstance(string $id): object
            {
                return $this->resolveInstance($id);
            }
        };

        $service = new stdClass();
        $container->add('my.service', $service);

        $resolved = $container->exposeResolveInstance('my.service');

        $this->assertSame($service, $resolved);
    }

    public function testAutoRegisterAbstractClassThrowsDependencyInjectionException(): void
    {
        $container = new Container();

        $this->expectException(ContainerDependencyInjectionException::class);
        $this->expectExceptionMessage(
            sprintf('Cannot auto-register abstract class or interface "%s".', AbstractTestService::class)
        );

        $container->get(AbstractTestService::class);
    }

    public function testGetNonExistentServiceThrowsException(): void
    {
        $container = new Container();

        $this->expectException(ContainerNotFoundException::class);
        $this->expectExceptionMessage('There is no definition named "non.existent.service"');

        $container->get('non.existent.service');
    }

    public function testCircularAliasThrowsException(): void
    {
        $container = new Container();

        // Lägg till giltiga tjänster som kan aliasas
        $container->add('alias1', new stdClass());
        $container->add('alias2', new stdClass());

        // Skapa alias
        $container->setAlias('alias1', 'alias2');
        $container->setAlias('alias2', 'alias1'); // Skapar en cirkulär alias

        // Förvänta att undantag kastas
        $this->expectException(ContainerDependencyInjectionException::class);
        $this->expectExceptionMessage('Circular alias detected for "alias1".');

        $container->get('alias1');
    }

    public function testAddSharedService(): void
    {
        $container = new Container();
        $container->addShared('shared.service', function () {
            return new stdClass();
        });

        $service1 = $container->get('shared.service');
        $service2 = $container->get('shared.service');

        $this->assertSame($service1, $service2); // Tjänsten är delad
    }

    public function testAddNonSharedService(): void
    {
        $container = new Container();
        $container->add('non.shared.service', function () {
            return new stdClass();
        });

        $service1 = $container->get('non.shared.service');
        $service2 = $container->get('non.shared.service');

        $this->assertNotSame($service1, $service2); // Tjänsten är inte delad
    }

    public function testSetDefinition(): void
    {
        $container = new Container();
        $definition = new Definition(stdClass::class);

        $result = $container->setDefinition('test.service', $definition);

        $this->assertSame($definition, $result);
        $this->assertTrue($container->has('test.service'));
        $this->assertSame($definition, $container->extend('test.service'));
    }

    public function testAddDefinitions(): void
    {
        $container = new Container();
        $definitions = [
            'service1' => new Definition(stdClass::class),
            'service2' => new Definition(Container::class),
        ];

        $container->addDefinitions($definitions);

        $this->assertTrue($container->has('service1'));
        $this->assertTrue($container->has('service2'));
        $this->assertInstanceOf(Definition::class, $container->extend('service1'));
        $this->assertInstanceOf(Definition::class, $container->extend('service2'));
    }

    public function testParameters(): void
    {
        $container = new Container();

        // Testa att lägga till en parameter
        $container->setParameter('db.host', 'localhost');
        $this->assertSame('localhost', $container->getParameter('db.host'));

        // Testa att lägga till flera parametrar
        $parameters = [
            'db.name' => 'test_db',
            'db.port' => 3306,
        ];

        $container->setParameters($parameters);

        $this->assertSame($parameters, $container->getParameters());
        $this->assertSame('test_db', $container->getParameter('db.name'));
        $this->assertSame(3306, $container->getParameter('db.port'));

        // Testa att lägga till extra parametrar
        $container->addParameters(['db.user' => 'root']);
        $this->assertSame('root', $container->getParameter('db.user'));

        // Testa standardvärde för ej existerande parameter
        $this->assertSame('default', $container->getParameter('db.password', 'default'));
    }

    public function testAddTagAndFindTaggedServiceIds(): void
    {
        $container = new Container();
        $container->add('service1', new stdClass());
        $container->add('service2', new stdClass());

        // Lägg till taggar på tjänster
        $container->addTag('service1', 'tag1', ['key' => 'value']);
        $container->addTag('service2', 'tag1');

        // Testa taggsökning
        $tags = $container->findTaggedServiceIds('tag1');
        $this->assertArrayHasKey('service1', $tags);
        $this->assertArrayHasKey('service2', $tags);

        // Verifiera taggat värde
        $this->assertSame([['key' => 'value']], $tags['service1']);
        $this->assertSame([[]], $tags['service2']); // Tomma attribut i detta fall
    }

    public function testValidAlias(): void
    {
        $container = new Container();
        $service = new stdClass();

        $container->add('actual.service', $service);
        $container->setAlias('alias.service', 'actual.service');

        $this->assertSame($service, $container->get('alias.service'));
    }

    public function testAliasToNonExistentService(): void
    {
        $container = new Container();

        $this->expectException(ContainerDependencyInjectionException::class);
        $this->expectExceptionMessage('Cannot alias "alias.service" to "non.existent.service" because "non.existent.service" is not defined.');

        $container->setAlias('alias.service', 'non.existent.service');
    }

    public function testAutoRegisterService(): void
    {
        $container = new Container();
        // Ingen explicita registreringar här
        $service = $container->get(stdClass::class);

        $this->assertInstanceOf(stdClass::class, $service);
    }

    public function testAutoRegisterAbstractClassThrowsException(): void
    {
        $container = new Container();

        $this->expectException(ContainerNotFoundException::class);
        $this->expectExceptionMessage('There is no definition named "Countable"');

        $container->get(Countable::class);
    }

    public function testGetNewAlwaysReturnsNewInstance(): void
    {
        $container = new Container();
        $container->addShared('shared.service', function () {
            return new stdClass();
        });

        $instance1 = $container->getNew('shared.service');
        $instance2 = $container->getNew('shared.service');

        $this->assertNotSame($instance1, $instance2);
    }

    public function testSetDefaultsAndApply(): void
    {
        $container = new Container();
        $container->setDefaults(['autoregister' => false]);

        $this->assertFalse($container->getDefault('autoregister'));
        $this->assertTrue($container->getDefault('autowire')); // Kontrollera att andra defaults behålls
    }

    public function testServiceUsesParameter(): void
    {
        $container = new Container();
        $container->setParameter('name', 'Radix');

        $container->add('greeting.service', function (Container $c): stdClass {
            $name = $c->getParameter('name');

            if (!is_string($name)) {
                $this->fail('Parameter "name" måste vara en sträng.');
            }

            /** @var string $name */
            $service = new stdClass();
            $service->greeting = sprintf('Hello, %s!', $name);

            return $service;
        });

        $service = $container->get('greeting.service');

        // Narrowa typen för PHPStan
        assert($service instanceof stdClass);

        $this->assertSame('Hello, Radix!', $service->greeting);
    }

    public function testProcessTaggedServices(): void
    {
        $container = new Container();

        // Registrera och tagga tjänster
        $container->add('service1', new stdClass())->addTag('process');
        $container->add('service2', new stdClass())->addTag('process');
        $container->add('service3', new stdClass());

        // Hämta och loopa genom taggade tjänster
        $taggedServices = $container->findTaggedServiceIds('process');
        foreach ($taggedServices as $serviceId => $tags) {
            $service = $container->get($serviceId);
            $this->assertInstanceOf(stdClass::class, $service);
        }

        $this->assertCount(2, $taggedServices); // Endast 2 är taggade med "process"
    }

    public function testScalability(): void
    {
        $container = new Container();

        $totalServices = 1000;
        for ($i = 0; $i < $totalServices; $i++) {
            $container->add("service_$i", new stdClass());
        }

        for ($i = 0; $i < $totalServices; $i++) {
            $this->assertTrue($container->has("service_$i"));
            $this->assertInstanceOf(stdClass::class, $container->get("service_$i"));
        }
    }

    public function testSetConcreteAcceptsValidTypes(): void
    {
        $def = new Definition(stdClass::class);

        // Ska inte kasta
        $def->setConcrete(stdClass::class);
        $def->setConcrete(new stdClass());
        $callable = function (): void {};
        $def->setConcrete($callable);

        // Enkel assertion så att testet inte är "risky"
        $this->assertSame($callable, $def->getConcrete());
    }

    public function testSetConcreteRejectsInvalidType(): void
    {
        $def = new Definition(stdClass::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Concrete must be a class name, an object, or a callable.');

        $def->setConcrete(123); // ogiltig typ
    }

    public function testSetDefaultsAlwaysCastsValuesToBool(): void
    {
        $container = new Container();

        /** @phpstan-ignore-next-line  intentionally pass scalar to test bool casting */
        $container->setDefaults([
            'autoregister' => '0',  // sträng "0" → (bool)"0" === false
        ]);

        $this->assertFalse($container->getDefault('autoregister'));
    }
}

abstract class AbstractTestService
{
    public function foo(): void {}
}
