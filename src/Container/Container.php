<?php

declare(strict_types=1);

namespace Nora\Container;

use Closure;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

final class Container
{
    /**
     * @var array<string, array{concrete: string|Closure, shared: bool}>
     */
    private array $bindings = [];

    /**
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * @var list<string>
     */
    private array $resolving = [];

    public function bind(string $id, string|Closure $concrete, bool $shared = false): void
    {
        $this->assertIdentifier($id);
        $this->bindings[$id] = ['concrete' => $concrete, 'shared' => $shared];
        unset($this->instances[$id]);
    }

    public function singleton(string $id, string|Closure $concrete): void
    {
        $this->bind($id, $concrete, true);
    }

    public function instance(string $id, object $instance): void
    {
        $this->assertIdentifier($id);
        $this->assertCompatible($id, $instance);
        unset($this->bindings[$id]);
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || isset($this->bindings[$id])
            || class_exists($id);
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (in_array($id, $this->resolving, true)) {
            $chain = implode(' -> ', [...$this->resolving, $id]);
            throw new ResolutionException('Circular dependency detected: ' . $chain);
        }

        $binding = $this->bindings[$id] ?? null;
        $concrete = $binding['concrete'] ?? $id;
        $shared = $binding['shared'] ?? false;

        if (!is_string($concrete) && !$concrete instanceof Closure) {
            throw new ResolutionException('No service is registered for "' . $id . '".');
        }

        $this->resolving[] = $id;

        try {
            $service = $concrete instanceof Closure
                ? $this->createFromFactory($id, $concrete)
                : $this->build($concrete);
            $this->assertCompatible($id, $service);

            if ($shared) {
                $this->instances[$id] = $service;
            }

            return $service;
        } finally {
            array_pop($this->resolving);
        }
    }

    private function createFromFactory(string $id, Closure $factory): object
    {
        try {
            $service = $factory($this);
        } catch (Throwable $exception) {
            throw new ResolutionException(
                'The factory for "' . $id . '" failed: ' . $exception->getMessage(),
                $exception,
            );
        }

        if (!is_object($service)) {
            throw new ResolutionException('The factory for "' . $id . '" must return an object.');
        }

        return $service;
    }

    private function build(string $class): object
    {
        if (!class_exists($class)) {
            throw new ResolutionException('Cannot resolve "' . $class . '": the class does not exist.');
        }

        try {
            $reflection = new ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                throw new ResolutionException('Cannot resolve "' . $class . '": the class is not instantiable.');
            }

            $constructor = $reflection->getConstructor();
            if ($constructor === null) {
                return $reflection->newInstance();
            }

            $arguments = [];
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->isVariadic()) {
                    continue;
                }

                $arguments[] = $this->resolveParameter($class, $parameter);
            }

            return $reflection->newInstanceArgs($arguments);
        } catch (ResolutionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ResolutionException(
                'Failed to build "' . $class . '": ' . $exception->getMessage(),
                $exception,
            );
        }
    }

    private function resolveParameter(string $class, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $type->getName();
            if ($this->has($dependency)) {
                return $this->get($dependency);
            }
        } elseif ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            if (!$parameter->isDefaultValueAvailable()) {
                throw new ResolutionException(
                    'Cannot resolve "' . $class . '": parameter "$' . $parameter->getName()
                    . '" uses a union or intersection type.',
                );
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new ResolutionException(
            'Cannot resolve "' . $class . '": parameter "$' . $parameter->getName()
            . '" must have a class type or a default value.',
        );
    }

    private function assertCompatible(string $id, object $service): void
    {
        if ((class_exists($id) || interface_exists($id)) && !($service instanceof $id)) {
            throw new ResolutionException(
                'The service registered for "' . $id . '" must be an instance of that type.',
            );
        }
    }

    private function assertIdentifier(string $id): void
    {
        if ($id === '') {
            throw new ResolutionException('A service identifier cannot be empty.');
        }
    }
}
