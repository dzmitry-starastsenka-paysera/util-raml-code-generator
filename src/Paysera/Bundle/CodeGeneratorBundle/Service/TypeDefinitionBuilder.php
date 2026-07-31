<?php

namespace Paysera\Bundle\CodeGeneratorBundle\Service;

use Paysera\Bundle\CodeGeneratorBundle\Entity\Definition\ArrayPropertyDefinition;
use Paysera\Bundle\CodeGeneratorBundle\Entity\Definition\DateTimeTypeDefinition;
use Paysera\Bundle\CodeGeneratorBundle\Entity\Definition\PropertyDefinition;
use Paysera\Bundle\CodeGeneratorBundle\Entity\Definition\TypeDefinition;
use Paysera\Bundle\CodeGeneratorBundle\Service\TypeDefinitionBuilder\TypeDefinitionBuilderInterface;
use Paysera\Component\TypeHelper;
use Raml\ApiDefinition;

class TypeDefinitionBuilder
{
    /**
     * @var TypeDefinitionBuilderInterface[]
     */
    private $builders;
    private $dateTimeBuilder;
    private $constantBuilder;

    public function __construct(
        TypeDefinitionBuilderInterface $dateTimeBuilder,
        ConstantBuilder $constantBuilder
    ) {
        $this->builders = [];
        $this->dateTimeBuilder = $dateTimeBuilder;
        $this->constantBuilder = $constantBuilder;
    }

    public function addTypeDefinitionBuilder(TypeDefinitionBuilderInterface $builder, string $position)
    {
        $this->builders[$position] = $builder;
        ksort($this->builders);
    }

    /**
     * @param ApiDefinition $api
     *
     * @return TypeDefinition[]
     */
    public function buildTypeDefinitions(ApiDefinition $api)
    {
        /** @var TypeDefinition[] $types */
        $types = [];
        /** @var TypeDefinition[] $singles */
        $singles = [];

        $apiTypes = array_merge($api->getTypes()->toArray(), $api->getTraits()->toArray());

        $scalarAliases = $this->collectScalarAliases($apiTypes);
        $apiTypes = array_diff_key($apiTypes, $scalarAliases);

        foreach ($apiTypes as $name => $definition) {
            if ($this->dateTimeBuilder->supports($name, $definition)) {
                $type = $this->dateTimeBuilder->buildTypeDefinition($name, $definition);
                if ($type instanceof DateTimeTypeDefinition) {
                    $singles[DateTimeTypeDefinition::NAME] = $type;
                }
            }
            foreach ($this->builders as $builder) {
                if ($builder->supports($name, $definition)) {
                    $types[] = $builder->buildTypeDefinition($name, $definition);
                    break;
                }
            }
        }

        $types = array_filter($types);

        $this->resolveScalarAliases($types, $scalarAliases);

        foreach ($types as $type) {
            if (
                !TypeHelper::isPrimitiveType($type->getType())
                && $type->getParent() === null
            ) {
                foreach ($types as $typeInner) {
                    if ($type->getType() === $typeInner->getName()) {
                        $type->setParent($typeInner);
                    }
                }
            }
        }

        return array_merge($types, array_values($singles));
    }

    /**
     * A named scalar type is a primitive plus constraints under its own name, for example a
     * `#%RAML 1.0 DataType` fragment holding `type: string` and an `enum` list. It has no entity to
     * generate, so it is taken out of the type list before the builders run and the properties
     * pointing at it become the underlying primitive instead.
     *
     * @param array $apiTypes
     *
     * @return array name => definition
     */
    private function collectScalarAliases(array $apiTypes)
    {
        $scalarAliases = [];
        foreach ($apiTypes as $name => $definition) {
            if (
                is_array($definition)
                && !isset($definition['properties'])
                && !isset($definition['queryParameters'])
                && isset($definition['type'])
                && is_string($definition['type'])
                && TypeHelper::isPrimitiveType($definition['type'])
                && $definition['type'] !== TypeHelper::TYPE_OBJECT
                && $definition['type'] !== TypeHelper::TYPE_ARRAY
            ) {
                $scalarAliases[$name] = $definition;
            }
        }

        return $scalarAliases;
    }

    /**
     * @param TypeDefinition[] $types
     * @param array $scalarAliases
     */
    private function resolveScalarAliases(array $types, array $scalarAliases)
    {
        if (count($scalarAliases) === 0) {
            return;
        }

        foreach ($types as $type) {
            foreach ($type->getProperties() as $property) {
                $this->resolveScalarAlias($property, $scalarAliases);
            }
        }
    }

    private function resolveScalarAlias(PropertyDefinition $property, array $scalarAliases)
    {
        $reference = $property->getReference();
        if (
            $property->getType() === PropertyDefinition::TYPE_REFERENCE
            && $reference !== null
            && isset($scalarAliases[$reference])
        ) {
            $property
                ->setType($scalarAliases[$reference]['type'])
                ->setReference(null)
            ;
            $this->applyAliasConstants($property, $scalarAliases[$reference]);
        }

        $itemsType = $property instanceof ArrayPropertyDefinition ? $property->getItemsType() : null;
        if ($itemsType !== null && isset($scalarAliases[$itemsType])) {
            $property->setItemsType($scalarAliases[$itemsType]['type']);
            $this->applyAliasConstants($property, $scalarAliases[$itemsType]);
        }
    }

    /**
     * The alias carries the allowed values, so dropping the reference would silently drop the
     * `enum` with it. An enum declared on the property itself is more specific and wins.
     */
    private function applyAliasConstants(PropertyDefinition $property, array $alias)
    {
        if (!isset($alias['enum']) || count($property->getConstants()) > 0) {
            return;
        }

        $property->setConstants($this->constantBuilder->build($property->getName(), $alias['enum']));
    }
}
