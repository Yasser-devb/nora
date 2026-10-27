<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Container\Container;
use Nora\Container\ResolutionException;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function testResolvesInterfaceBindingAndConstructorDependencies(): void
    {
        $container = new Container();
        $container->bind(GreetingService::class, EnglishGreeting::class);

        $service = $container->get(GreetingConsumer::class);

        self::assertSame('hello', $service->greeting->greet());
    }

    public function testSingletonIsSharedAndTransientBindingIsRebuilt(): void
    {
        $container = new Container();
        $container->singleton('shared', static fn (): object => new \stdClass());
        $container->bind('transient', static fn (): object => new \stdClass());

        self::assertSame($container->get('shared'), $container->get('shared'));
        self::assertNotSame($container->get('transient'), $container->get('transient'));
    }

    public function testDetectsCircularDependencies(): void
    {
        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('Circular dependency detected');

        (new Container())->get(CircularA::class);
    }

    public function testRejectsFactoryReturningNonObject(): void
    {
        $container = new Container();
        $container->bind('invalid', static fn (): string => 'not a service');

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('must return an object');
        $container->get('invalid');
    }
}

interface GreetingService
{
    public function greet(): string;
}

final class EnglishGreeting implements GreetingService
{
    public function greet(): string
    {
        return 'hello';
    }
}

final class GreetingConsumer
{
    public function __construct(public readonly GreetingService $greeting)
    {
    }
}

final class CircularA
{
    public function __construct(CircularB $service)
    {
    }
}

final class CircularB
{
    public function __construct(CircularA $service)
    {
    }
}
