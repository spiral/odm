<?php
/**
 * Spiral, Core Components
 *
 * @author Wolfy-J
 */

namespace Spiral\ODM\Schemas;

use Doctrine\Common\Inflector\Inflector;
use Spiral\Models\AccessorInterface;
use Spiral\Models\Exceptions\AccessorExceptionInterface;
use Spiral\Models\Reflections\ReflectionEntity;
use Spiral\ODM\Configs\MutatorsConfig;
use Spiral\ODM\Document;
use Spiral\ODM\DocumentEntity;
use Spiral\ODM\Entities\DocumentInstantiator;
use Spiral\ODM\Exceptions\SchemaException;
use Spiral\ODM\Schemas\Definitions\AggregationDefinition;
use Spiral\ODM\Schemas\Definitions\CompositionDefinition;
use Spiral\ODM\Schemas\Definitions\IndexDefinition;

class DocumentSchema implements SchemaInterface
{
    /**
     * @var ReflectionEntity
     */
    private $reflection;

    /**
     * @invisible
     *
     * @var MutatorsConfig
     */
    private $mutatorsConfig;

    /**
     * @param ReflectionEntity $reflection
     * @param MutatorsConfig   $mutators
     */
    public function __construct(ReflectionEntity $reflection, MutatorsConfig $mutators)
    {
        $this->reflection = $reflection;
        $this->mutatorsConfig = $mutators;
    }

    /**
     * @return string
     */
    public function getClass(): string
    {
        return $this->reflection->getName();
    }

    /**
     * @return ReflectionEntity
     */
    public function getReflection(): ReflectionEntity
    {
        return $this->reflection;
    }

    /**
     * @return string
     */
    public function getInstantiator(): string
    {
        return $this->reflection->getProperty('instantiator') ?? DocumentInstantiator::class;
    }

    /**
     * {@inheritdoc}
     */
    public function isEmbedded(): bool
    {
        return !$this->reflection->isSubclassOf(Document::class)
            && $this->reflection->isSubclassOf(DocumentEntity::class);
    }

    /**
     * {@inheritdoc}
     */
    public function getDatabase()
    {
        if ($this->isEmbedded()) {
            throw new SchemaException(
                "Unable to get database name for embedded model {$this->reflection}"
            );
        }

        $database = $this->reflection->getProperty('database');
        if (empty($database)) {
            //Empty database to be used
            return null;
        }

        return $database;
    }

    /**
     * {@inheritdoc}
     */
    public function getCollection(): string
    {
        if ($this->isEmbedded()) {
            throw new SchemaException(
                "Unable to get collection name for embedded model {$this->reflection}"
            );
        }

        $collection = $this->reflection->getProperty('collection');
        if (empty($collection)) {
            //We have to use parent collection when extended
            $class = $this->reflection;
            while ($class->getParentClass()->getName() != Document::class) {
                $class = $class->getParentClass();
            }

            //Generate collection using short class name
            $collection = Inflector::camelize($class->getShortName());
            $collection = Inflector::pluralize($collection);
        }

        return $collection;
    }

    /**
     * Get every embedded entity field (excluding declarations of aggregations).
     *
     * @return array
     */
    public function getFields(): array
    {
        $fields = $this->reflection->getSchema();

        foreach ($fields as $field => $type) {
            if ($this->isAggregation($type)) {
                unset($fields[$field]);
            }
        }

        return $fields;
    }

    /**
     * Default defined values.
     *
     * @return array
     */
    public function getDefaults(): array
    {
        return $this->reflection->getProperty('defaults') ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getIndexes(): array
    {
        if ($this->isEmbedded()) {
            throw new SchemaException(
                "Unable to get indexes for embedded model {$this->reflection}"
            );
        }

        $indexes = $this->reflection->getProperty('indexes', true);
        if (empty($indexes) || !is_array($indexes)) {
            return [];
        }

        $result = [];
        foreach ($indexes as $index) {
            $options = [];
            if (isset($index['@options'])) {
                $options = $index['@options'];
                unset($index['@options']);
            }

            $result[] = new IndexDefinition($index, $options);
        }

        return array_unique($result);
    }

    /**
     * @return AggregationDefinition[]
     */
    public function getAggregations(): array
    {
        $result = [];
        foreach ($this->reflection->getSchema() as $field => $type) {
            if ($this->isAggregation($type)) {
                $aggregationType = isset($type[Document::ONE]) ? Document::ONE : Document::MANY;

                $result[$field] = new AggregationDefinition(
                    $aggregationType,        //Aggregation type
                    $type[$aggregationType], //Class name
                    array_pop($type)         //Query template
                );
            }
        }

        return $result;
    }

    /**
     * Find all composition definitions, attention method require builder instance in order to
     * properly check that embedded class exists.
     *
     * @param SchemaBuilder $builder
     *
     * @return CompositionDefinition[]
     */
    public function getCompositions(SchemaBuilder $builder): array
    {
        $result = [];
        foreach ($this->reflection->getSchema() as $field => $type) {
            if (is_string($type) && $builder->hasSchema($type)) {
                $result[$field] = new CompositionDefinition(DocumentEntity::ONE, $type);
            }

            if (is_array($type) && isset($type[0]) && $builder->hasSchema($type[0])) {
                $result[$field] = new CompositionDefinition(DocumentEntity::MANY, $type[0]);
            }
        }

        return $result;
    }

    /**
     * Generate set of mutators associated with entity fields using user defined and automatic
     * mutators.
     *
     * @see MutatorsConfig
     * @return array
     */
    public function getMutators(): array
    {
        $mutators = $this->reflection->getMutators();

        //Trying to resolve mutators based on field type
        foreach ($this->getFields() as $field => $type) {
            //Resolved mutators
            $resolved = [];

            if (
                is_array($type)
                && is_scalar($type[0])
                && $filter = $this->mutatorsConfig->getMutators('array::' . $type[0])
            ) {
                //Mutator associated to array with specified type
                $resolved += $filter;
            } elseif (is_array($type) && $filter = $this->mutatorsConfig->getMutators('array')) {
                //Default array mutator
                $resolved += $filter;
            } elseif (!is_array($type) && $filter = $this->mutatorsConfig->getMutators($type)) {
                //Mutator associated with type directly
                $resolved += $filter;
            }

            //Merging mutators and default mutators
            foreach ($resolved as $mutator => $filter) {
                if (!array_key_exists($field, $mutators[$mutator])) {
                    $mutators[$mutator][$field] = $filter;
                }
            }
        }

        return $mutators;
    }

    /**
     * {@inheritdoc}
     */
    public function resolvePrimary(SchemaBuilder $builder): string
    {
        //Let's define a way how to separate one model from another based on given fields
        $helper = new InheritanceHelper($this, $builder->getSchemas());

        return $helper->findPrimary();
    }

    /**
     * {@inheritdoc}
     */
    public function packSchema(SchemaBuilder $builder): array
    {
        return [
            //Instantion options and behaviour (if any)
            DocumentEntity::SH_INSTANTIATION => $this->instantiationOptions($builder),

            //Default entity state (builder is needed to resolve recursive defaults)
            DocumentEntity::SH_DEFAULTS      => $this->packDefaults($builder),

            //Entity behaviour
            DocumentEntity::SH_SECURED       => $this->reflection->getSecured(),
            DocumentEntity::SH_FILLABLE      => $this->reflection->getFillable(),

            //Mutators can be altered based on ODM\SchemasConfig
            DocumentEntity::SH_MUTATORS      => $this->getMutators(),

            //Document behaviours (we can mix them with accessors due potential inheritance)
            DocumentEntity::SH_COMPOSITIONS  => $this->packCompositions($builder),
            DocumentEntity::SH_AGGREGATIONS  => $this->packAggregations($builder),
        ];
    }

    /**
     * Define instantiator specific options (usually needed to resolve class inheritance). Might
     * return null if associated instantiator is unknown to DocumentSchema.
     *
     * @param SchemaBuilder $builder
     *
     * @return mixed
     */
    protected function instantiationOptions(SchemaBuilder $builder)
    {
        if ($this->getInstantiator() != DocumentInstantiator::class) {
            //Unable to define options for non default inheritance based instantiator
            return null;
        }

        //Let's define a way how to separate one model from another based on given fields
        $helper = new InheritanceHelper($this, $builder->getSchemas());

        return $helper->makeDefinition();
    }

    /**
     * Entity default values.
     *
     * @param SchemaBuilder $builder
     * @param array         $overwriteDefaults Set of default values to replace user defined values.
     *
     * @return array
     *
     * @throws SchemaException
     */
    protected function packDefaults(SchemaBuilder $builder, array $overwriteDefaults = []): array
    {
        //Defined compositions
        $compositions = $this->getCompositions($builder);

        //User defined default values
        $userDefined = $overwriteDefaults + $this->getDefaults();

        //We need mutators to normalize default values
        $mutators = $this->getMutators();

        $defaults = [];
        foreach ($this->getFields() as $field => $type) {
            $default = is_array($type) ? [] : null;

            if (array_key_exists($field, $userDefined)) {
                //No merge to keep fields order intact
                $default = $userDefined[$field];
            }

            //Registering default values
            $defaults[$field] = $this->mutateValue(
                $builder,
                $compositions,
                $userDefined,
                $mutators,
                $field,
                $default
            );
        }

        return $defaults;
    }

    /**
     * Pack compositions into simple array definition.
     *
     * @param SchemaBuilder $builder
     *
     * @return array
     *
     * @throws SchemaException
     */
    public function packCompositions(SchemaBuilder $builder): array
    {
        $result = [];
        foreach ($this->getCompositions($builder) as $name => $composition) {
            $result[$name] = $composition->packSchema();
        }

        return $result;
    }

    /**
     * Pack aggregations into simple array definition.
     *
     * @param SchemaBuilder $builder
     *
     * @return array
     *
     * @throws SchemaException
     */
    protected function packAggregations(SchemaBuilder $builder): array
    {
        $result = [];
        foreach ($this->getAggregations() as $name => $aggregation) {
            if (!$builder->hasSchema($aggregation->getClass())) {
                throw new SchemaException(
                    "Aggregation {$this->getClass()}.'{$name}' refers to undefined document '{$aggregation->getClass()}'"
                );
            }

            if ($builder->getSchema($aggregation->getClass())->isEmbedded()) {
                throw new SchemaException(
                    "Aggregation {$this->getClass()}.'{$name}' refers to non storable document '{$aggregation->getClass()}'"
                );
            }

            $result[$name] = $aggregation->packSchema();
        }

        return $result;
    }

    /**
     * Check if field schema/type defines aggregation.
     *
     * @param mixed $type
     *
     * @return bool
     */
    protected function isAggregation($type): bool
    {
        if (is_array($type)) {
            if (isset($type[Document::ONE]) || isset($type[Document::MANY])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ensure default value using associated mutators or pass thought composition.
     *
     * @param SchemaBuilder $builder
     * @param array         $compositions
     * @param array         $userDefined User defined set of default values.
     * @param array         $mutators
     * @param string        $field
     * @param mixed         $default
     *
     * @return mixed
     */
    protected function mutateValue(
        SchemaBuilder $builder,
        array $compositions,
        array $userDefined,
        array $mutators,
        string $field,
        $default
    ) {
        //Let's process default value using associated setter
        if (isset($mutators[DocumentEntity::MUTATOR_SETTER][$field])) {
            try {
                $setter = $mutators[DocumentEntity::MUTATOR_SETTER][$field];
                $default = call_user_func($setter, $default);

                return $default;
            } catch (\Exception $exception) {
                //Unable to generate default value, use null or empty array as fallback
            }
        }

        if (isset($mutators[DocumentEntity::MUTATOR_ACCESSOR][$field])) {
            $default = $this->accessorDefault(
                $default,
                $mutators[DocumentEntity::MUTATOR_ACCESSOR][$field]
            );
        }

        if (isset($compositions[$field])) {
            if (is_null($default) && !array_key_exists($field, $userDefined)) {
                //Let's force default value for composite fields
                $default = [];
            }

            $default = $this->compositionDefault($default, $compositions[$field], $builder);

            return $default;
        }

        return $default;
    }

    /**
     * Pass value thought accessor to ensure it's default.
     *
     * @param mixed  $default
     * @param string $accessor
     *
     * @return mixed
     *
     * @throws AccessorExceptionInterface
     */
    protected function accessorDefault($default, string $accessor)
    {
        /**
         * @var AccessorInterface $instance
         */
        $instance = new $accessor($default, [/*no context given*/]);
        $default = $instance->packValue();

        if (is_object($default)) {
            //Some accessors might want to return objects (DateTime, StorageObject), default to null
            $default = null;
        }

        return $default;
    }

    /**
     * Ensure default value for composite field,
     *
     * @param mixed                 $default
     * @param CompositionDefinition $composition
     * @param SchemaBuilder         $builder
     *
     * @return array
     *
     * @throws SchemaException
     */
    protected function compositionDefault(
        $default,
        CompositionDefinition $composition,
        SchemaBuilder $builder
    ) {
        if (!is_array($default)) {
            if ($composition->getType() == DocumentEntity::MANY) {
                //Composition many must always defaults to array
                return [];
            }

            //Composite ONE must always defaults to null if no default value are specified
            return null;
        }

        //Nothing to do with value for composite many
        if ($composition->getType() == DocumentEntity::MANY) {
            return $default;
        }

        $embedded = $builder->getSchema($composition->getClass());
        if (!$embedded instanceof self) {
            //We can not normalize values handled by external schemas yet
            return $default;
        }

        if ($embedded->getClass() == $this->getClass()) {
            if (!empty($default)) {
                throw new SchemaException(
                    "Possible recursion issue in '{$this->getClass()}', model refers to itself (has default value)"
                );
            }

            //No recursions!
            return null;
        }

        return $embedded->packDefaults($builder, $default);
    }
}