<?php

namespace F3;

/**
 * Service Locator / DI Container
 * PSR-11 compatible
 */
class Service
{
    use Prefab;

    private array $factories = [];
    private array $singletons = [];

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
    public function get(string $id, $args = []): object
    {
        if (Registry::exists($id))
            return Registry::get($id);
        $out = $this->make($id, $args);
        if (array_key_exists($id, $this->singletons)
            || \in_array(Prefab::class, $this->f3->traits($out))) {
            Registry::set($id, $out);
        }
        return $out;
    }

    /**
     * check if object or factory is known
     */
    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || \class_exists($id);
    }

    /**
     * set object or object factory
     */
    public function set(string $id, object|string|null $obj = null): void
    {
        $this->factories[$id] = $obj ?? $id;
    }

    /**
     * set object or object factory
     */
    public function singleton(string $id, object|string|null $obj = null): void
    {
        $this->set($id, $obj);
        $this->singletons[$id] = true;
    }

    /**
     * Create new object instance
     * @template Class
     * @param class-string<Class> $id
     * @return Class
     */
    public function make(string $id, $args = []): object
    {
        if (!isset($this->factories[$id])) {
            $this->set($id);
        }
        /** @var class-string|object $class */
        $class = $this->factories[$id];
        // if referenced by other factory, take that instead
        if (\is_string($class) && isset($this->factories[$class])) {
            $class = $this->factories[$class];
        }
        if ($class instanceof \Closure) {
            return $class($this, $args);
        }
        if (is_object($class)) {
            return $class;
        }
        $ref = new \ReflectionClass($class);
        if (!$ref->isInstantiable()) {
            throw new \Exception("Class $class is not instantiable");
        }
        $cRef = $ref->getConstructor();
        if ($cRef === null) {
            return $ref->newInstance();
        }
        $dep = [];
        foreach ($cRef->getParameters() as $p) {
            $dep[$name = $p->getName()] = $args[$name] ?? $this->resolveParam($p);
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
