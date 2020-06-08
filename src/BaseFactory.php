<?php

namespace Christophrumpel\LaravelFactoriesReloaded;

use Faker\Factory as FakerFactory;
use Faker\Generator;
use ReflectionClass;

abstract class BaseFactory implements FactoryInterface
{
    use TranslatesFactoryData;

    protected $modelClass;

    protected $relatedModelFactories;

    protected $relatedModelRelationshipName;

    protected $faker;

    protected $overwriteDefaults = [];

    public function __construct(Generator $faker)
    {
        $this->faker = $faker;
        $this->relatedModelFactories = collect();
    }

    /** @return static */
    public static function new(): self
    {
        $faker = FakerFactory::create(config('app.faker_locale', 'en_US'));

        return new static($faker);
    }

    protected function build(array $extra = [], string $creationType = 'create')
    {
        $modelData = $this->prepareModelData($creationType, $this->getDefaults($this->faker));
        $model = $this->unguardedIfNeeded(function () use ($modelData, $extra, $creationType) {
            $data = array_merge($modelData, $this->overwriteDefaults, $extra);
            foreach ($data as $key => $value) {
                if (is_callable($value)) {
                    $data[$key] = $value();
                }
            }
            return $this->modelClass::$creationType($data);
        });

        if ($this->relatedModelFactories->isEmpty()) {
            return $model;
        }

        $relatedModels = $this->relatedModelFactories->map->make();

        if ($creationType === 'create') {
            $model->{$this->relatedModelRelationshipName}()
                ->saveMany($relatedModels);

            return $model;
        }

        return $model->setRelation($this->relatedModelRelationshipName, $relatedModels);
    }

    protected function unguardedIfNeeded(\Closure $closure)
    {
        if (!config('factories-reloaded.unguard_models')) {
            return $closure();
        }

        return $this->modelClass::unguarded($closure);
    }

    public function times(int $times = 1): MultiFactoryCollection
    {
        return new MultiFactoryCollection(collect()->times($times, function () {
            return clone $this;
        }));
    }

    public function with(string $relatedModelClass, string $relationshipName, int $times = 1): self
    {
        $clone = clone $this;

        $clone->relatedModelFactories = collect()->times($times, function () use ($relatedModelClass) {
            return $this->getFactoryFromClassName($relatedModelClass);
        });

        $clone->relatedModelRelationshipName = $relationshipName;

        return $clone;
    }

    /**
     * @param array|callable $attributes
     *
     * @return $this
     */
    public function overwriteDefaults($attributes): self
    {
        if (is_callable($attributes)) {
            $attributes = $attributes();
        }

        $this->overwriteDefaults = array_merge($this->overwriteDefaults, $attributes);

        return $this;
    }

    protected function getFactoryFromClassName(string $className): FactoryInterface
    {
        $baseClassName = (new ReflectionClass($className))->getShortName();
        $factoryClass = config('factories-reloaded.factories_namespace') . '\\' . $baseClassName . 'Factory';

        return new $factoryClass($this->faker);
    }

    /**
     * Creates in DB and returns the new fake model
     *
     * @param array $extra
     *
     * @return mixed
     */
    public function create(array $extra = [])
    {
        return $this->build($extra);
    }

    /**
     * Returns the new fake model
     *
     * @param array $extra
     *
     * @return mixed
     */
    public function make(array $extra = [])
    {
        return $this->build($extra, 'make');
    }
}
