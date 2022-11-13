<?php

namespace F3;

/**
 * Service Locator / DI Container
 * PSR-11 compatible
 */
class Service {

    use Prefab;

    private array $factories = [];

    protected Base $f3;

    public function __construct()
    {
        $this->f3 = Base::instance();
    }

    /**
     * Retrieve object instance, create if not existing
     * @template Class
     * @param class-string<Class> $id
     * @return Class
     */
    public function get(string $id, $args = []): object {
        if (Registry::exists($id))
            return Registry::get($id);
        return Registry::set($id, $this->make($id, $args));
    }

    /**
     * check if object or factory is known
     */
    public function has(string $id): bool {
        return isset($this->factories[$id]) || \class_exists($id);
    }

    /**
     * set object or object factory
     */
    public function set(string $id, object|string|null $obj = null): void {
        $this->factories[$id] = $obj ?? $id;
    }

    /**
     * Create new object instance
     * @template Class
     * @param class-string<Class> $id
     * @return Class
     */
    public function make(string $id, $args = []): object {
        if (!isset($this->factories[$id])) {
            $this->set($id);
        }
        /** @var class-string $class */
        $class = $this->factories[$id];
        // if referenced by other factory, take that instead
        if (\is_string($class) && isset($this->factories[$class])) {
            $class = $this->factories[$class];
        }
        if ($class instanceof \Closure) {
            return $class($this, $args);
        }
        $ref = new \ReflectionClass($class);
        if (!$ref->isInstantiable()) {
            throw new \Exception("Class $class is not instantiable");
        }
        $cRef = $ref->getConstructor();
        if ($cRef === NULL) {
            return $ref->newInstance();
        }
        $dep = [];
        foreach ($cRef->getParameters() as $p) {
            $dep[$name=$p->getName()] = $args[$name] ?? $this->resolveParam($p);
        }
        return $ref->newInstanceArgs($dep);
    }

    /**
     * get resolved parameter dependency
     */
    public function resolveParam(\ReflectionParameter $parameter): mixed
    {
        $refType = $parameter->getType();
        if ($refType instanceof \ReflectionNamedType) {
            if (!$refType->isBuiltin() && !$refType->allowsNull()) {
                return $this->f3->make($refType->getName());
            }
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            } elseif (!$refType->allowsNull()) {
                throw new \Exception("Cannot resolve class dependency {$parameter->name}");
            }
        }
        return null;
    }
}
