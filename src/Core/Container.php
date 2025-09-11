<?php
namespace App\Core;

use ReflectionClass;
use ReflectionNamedType;

class Container {
    protected array $bindings = [];
    protected array $instances = [];

    /** Register a factory (defaults to singleton behavior if you return same instance). */
    public function bind(string $abstract, callable $factory): void {
        $this->bindings[$abstract] = $factory;
    }

    /** Optionally preload a ready instance. */
    public function instance(string $abstract, $object): void {
        $this->instances[$abstract] = $object;
    }

    /** Make a class with simple autowiring of constructor dependencies. */
    public function make(string $abstract) {
        // Return cached instance if any
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Use a factory binding if present
        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])();
        }

        // Autowire class via reflection
        if (!class_exists($abstract)) {
            throw new \RuntimeException("Cannot resolve {$abstract}: class does not exist");
        }

        $ref = new ReflectionClass($abstract);
        if (!$ref->isInstantiable()) {
            throw new \RuntimeException("Cannot instantiate {$abstract}");
        }

        $ctor = $ref->getConstructor();
        if (!$ctor || $ctor->getNumberOfParameters() === 0) {
            return new $abstract();
        }

        $deps = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();

            // If no type or union/complex types -> try default or fail
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                if ($param->isDefaultValueAvailable()) {
                    $deps[] = $param->getDefaultValue();
                    continue;
                }
                throw new \RuntimeException("Unresolvable parameter \${$param->getName()} for {$abstract}");
            }

            $depClass = $type->getName();
            $deps[] = $this->make($depClass); // recursive resolve (will use binding if provided)
        }

        return $ref->newInstanceArgs($deps);
    }
}
