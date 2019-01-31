<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\Hydra\Serializer;

use ApiPlatform\Core\Api\OperationMethodResolverInterface;
use ApiPlatform\Core\Api\OperationType;
use ApiPlatform\Core\Api\ResourceClassResolverInterface;
use ApiPlatform\Core\Api\UrlGeneratorInterface;
use ApiPlatform\Core\Documentation\Documentation;
use ApiPlatform\Core\JsonLd\ContextBuilderInterface;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Core\Metadata\Property\PropertyMetadata;
use ApiPlatform\Core\Metadata\Property\SubresourceMetadata;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use ApiPlatform\Core\Metadata\Resource\ResourceMetadata;
use ApiPlatform\Core\Operation\Factory\SubresourceOperationFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\PropertyInfo\Type;
use ApiPlatform\Core\Bridge\Symfony\Routing\IriConverter;
// use ApiPlatform\Core\Api\IriConverterInterface;
use ApiPlatform\Core\Hydra\Serializer\CollectionFiltersNormalizer;

/**
 * Creates a machine readable Hydra API documentation.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class DocumentationNormalizer implements NormalizerInterface, CacheableSupportsMethodInterface
{
    const FORMAT = 'jsonld';

    private $resourceMetadataFactory;
    private $propertyNameCollectionFactory;
    private $propertyMetadataFactory;
    private $resourceClassResolver;
    private $operationMethodResolver;
    private $urlGenerator;
    private $subresourceOperationFactory;
    private $nameConverter;
    private $iriConverter;
    private $collectionFiltersNormalizer;

    public function __construct(ResourceMetadataFactoryInterface $resourceMetadataFactory, PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory, PropertyMetadataFactoryInterface $propertyMetadataFactory, ResourceClassResolverInterface $resourceClassResolver, OperationMethodResolverInterface $operationMethodResolver, UrlGeneratorInterface $urlGenerator, SubresourceOperationFactoryInterface $subresourceOperationFactory = null, NameConverterInterface $nameConverter = null, IriConverter $iriConverter = null, CollectionFiltersNormalizer $collectionFiltersNormalizer = null)
    {
        $this->resourceMetadataFactory = $resourceMetadataFactory;
        $this->propertyNameCollectionFactory = $propertyNameCollectionFactory;
        $this->propertyMetadataFactory = $propertyMetadataFactory;
        $this->resourceClassResolver = $resourceClassResolver;
        $this->operationMethodResolver = $operationMethodResolver;
        $this->urlGenerator = $urlGenerator;
        $this->subresourceOperationFactory = $subresourceOperationFactory;
        $this->nameConverter = $nameConverter;
        $this->iriConverter = $iriConverter;
        $this->collectionFiltersNormalizer = $collectionFiltersNormalizer;
    }

    /**
     * {@inheritdoc}
     */
    public function normalize($object, $format = null, array $context = [])
    {
        $classes = [];
        $entrypointProperties = [];

        foreach ($object->getResourceNameCollection() as $resourceClass) {
            $resourceMetadata = $this->resourceMetadataFactory->create($resourceClass);
            $shortName = $resourceMetadata->getShortName();
            $prefixedShortName = $resourceMetadata->getIri() ?? "vocab:#$shortName";

            $this->populateEntrypointProperties($resourceClass, $resourceMetadata, $shortName, $prefixedShortName, $entrypointProperties);
            $classes[] = $this->getClass($resourceClass, $resourceMetadata, $shortName, $prefixedShortName, $context);
        }

        return $this->computeDoc($object, $this->getClasses($entrypointProperties, $classes));
    }

    /**
     * Populates entrypoint properties.
     */
    private function populateEntrypointProperties(string $resourceClass, ResourceMetadata $resourceMetadata, string $shortName, string $prefixedShortName, array &$entrypointProperties)
    {
        $hydraCollectionOperations = $this->getHydraOperations($resourceClass, $resourceMetadata, $prefixedShortName, true);
        if (empty($hydraCollectionOperations)) {
            return;
        }

        $resourceFilters = $resourceMetadata->getCollectionOperationAttribute('get', 'filters', [], true);
        if ([] === $resourceFilters) {
            $temp[0] = null;
        } else {
            $currentFilters = $this->collectionFiltersNormalizer->filters;
            $temp[0] = $this->collectionFiltersNormalizer->getSearch($resourceClass, ['path'=>$this->iriConverter->getIriFromResourceClass($resourceClass)], ['0'=>$currentFilters[$resourceFilters[0]]]);
        }


        $entrypointProperty = [
            '@type' => 'hydra:SupportedProperty',
            'hydra:property' => [
                // '@id' => sprintf('#Entrypoint/%s', lcfirst($shortName)),
                '@id' => $this->iriConverter->getIriFromResourceClass($resourceClass),
                //sprintf('#Entrypoint/%s', lcfirst($shortName)),
                '@type' => 'hydra:Link',
                'domain' => '#Entrypoint',
                'rdfs:label' => "The collection of $shortName resources",
                'rdfs:range' => [
                    '@type' => 'hydra:Collection',
                    'hydra:member' => [
                        '@type' => "vocab:#$shortName"
                    ],
                    'hydra:search' => $temp,
                    'owl:equivalentClass' => [
                        'owl:onProperty' => ['@id' => 'hydra:member'],
                        'owl:allValuesFrom' => ['@id' => "vocab:#$shortName"],
                    ],
                ],
                'hydra:supportedOperation' => $hydraCollectionOperations,
            ],
            'hydra:title' => "The collection of $shortName resources",
            'hydra:readable' => true,
            'hydra:writable' => false,
        ];

        if ($resourceMetadata->getCollectionOperationAttribute('GET', 'deprecation_reason', null, true)) {
            $entrypointProperty['owl:deprecated'] = true;
        }

        $entrypointProperties[] = $entrypointProperty;
    }

    /**
     * Gets a Hydra class.
     */
    private function getClass(string $resourceClass, ResourceMetadata $resourceMetadata, string $shortName, string $prefixedShortName, array $context): array
    {
        $class = [
            '@id' => "vocab:#$shortName",
            '@type' => $resourceMetadata->getType() ? ['hydra:Class',$resourceMetadata->getType()] : 'hydra:Class',
            'rdfs:label' => $shortName,
            'hydra:title' => $shortName,
            'hydra:supportedProperty' => $this->getHydraProperties($resourceClass, $resourceMetadata, $shortName, $prefixedShortName, $context),
            'hydra:supportedOperation' => $this->getHydraOperations($resourceClass, $resourceMetadata, $prefixedShortName, false),
        ];

        if (null !== $description = $resourceMetadata->getDescription()) {
            $class['hydra:description'] = $description;
        }

        if ($resourceMetadata->getAttribute('deprecation_reason')) {
            $class['owl:deprecated'] = true;
        }

        return $class;
    }

    /**
     * Gets the context for the property name factory.
     */
    private function getPropertyNameCollectionFactoryContext(ResourceMetadata $resourceMetadata): array
    {
        $attributes = $resourceMetadata->getAttributes();
        $context = [];

        if (isset($attributes['normalization_context'][AbstractNormalizer::GROUPS])) {
            $context['serializer_groups'] = (array) $attributes['normalization_context'][AbstractNormalizer::GROUPS];
        }

        if (!isset($attributes['denormalization_context'][AbstractNormalizer::GROUPS])) {
            return $context;
        }

        if (isset($context['serializer_groups'])) {
            foreach ((array) $attributes['denormalization_context'][AbstractNormalizer::GROUPS] as $groupName) {
                $context['serializer_groups'][] = $groupName;
            }

            return $context;
        }

        $context['serializer_groups'] = (array) $attributes['denormalization_context'][AbstractNormalizer::GROUPS];

        return $context;
    }

    /**
     * Gets Hydra properties.
     */
    private function getHydraProperties(string $resourceClass, ResourceMetadata $resourceMetadata, string $shortName, string $prefixedShortName, array $context): array
    {
        $classes = [];
        foreach ($resourceMetadata->getCollectionOperations() as $operationName => $operation) {
            if (false !== $class = $resourceMetadata->getCollectionOperationAttribute($operationName, 'input_class', $resourceClass, true)) {
                $classes[$class] = true;
            }

            if (false !== $class = $resourceMetadata->getCollectionOperationAttribute($operationName, 'output_class', $resourceClass, true)) {
                $classes[$class] = true;
            }
        }

        $properties = [];
        foreach ($classes as $class => $v) {
            foreach ($this->propertyNameCollectionFactory->create($class, $this->getPropertyNameCollectionFactoryContext($resourceMetadata)) as $propertyName) {
                $propertyMetadata = $this->propertyMetadataFactory->create($class, $propertyName);
                if (true === $propertyMetadata->isIdentifier() && false === $propertyMetadata->isWritable()) {
                    continue;
                }

                if ($this->nameConverter) {
                    $propertyName = $this->nameConverter->normalize($propertyName, $class, self::FORMAT, $context);
                }

                $properties[] = $this->getProperty($propertyMetadata, $propertyName, $prefixedShortName, $shortName);
            }
        }

        return $properties;
    }

    /**
     * Gets Hydra operations.
     */
    private function getHydraOperations(string $resourceClass, ResourceMetadata $resourceMetadata, string $prefixedShortName, bool $collection): array
    {
        if (null === $operations = $collection ? $resourceMetadata->getCollectionOperations() : $resourceMetadata->getItemOperations()) {
            return [];
        }

        $hydraOperations = [];
        foreach ($operations as $operationName => $operation) {
            $hydraOperations[] = $this->getHydraOperation($resourceClass, $resourceMetadata, $operationName, $operation, $prefixedShortName, $collection ? OperationType::COLLECTION : OperationType::ITEM);
        }

        if (null !== $this->subresourceOperationFactory) {
            foreach ($this->subresourceOperationFactory->create($resourceClass) as $operationId => $operation) {
                $subresourceMetadata = $this->resourceMetadataFactory->create($operation['resource_class']);
                $propertyMetadata = $this->propertyMetadataFactory->create(end($operation['identifiers'])[1], $operation['property']);
                $hydraOperations[] = $this->getHydraOperation($resourceClass, $subresourceMetadata, $operation['route_name'], $operation, "#{$subresourceMetadata->getShortName()}", OperationType::SUBRESOURCE, $propertyMetadata->getSubresource());
            }
        }

        // foreach ($this->propertyNameCollectionFactory->create($resourceClass, $this->getPropertyNameCollectionFactoryContext($resourceMetadata)) as $propertyName) {
        //     $propertyMetadata = $this->propertyMetadataFactory->create($resourceClass, $propertyName);

        //     if (!$propertyMetadata->hasSubresource()) {
        //         continue;
        //     }

        //     $subresourceMetadata = $this->resourceMetadataFactory->create($propertyMetadata->getSubresource()->getResourceClass());
        //     $prefixedShortName = "#{$subresourceMetadata->getShortName()}";

        //     $hydraOperations[] = $this->getHydraOperation($resourceClass, $subresourceMetadata, $operationName, $operation, $prefixedShortName, OperationType::SUBRESOURCE, $propertyMetadata->getSubresource());
        // }

        return $hydraOperations;
    }

    /**
     * Gets and populates if applicable a Hydra operation.
     *
     * @param string              $resourceClass
     * @param ResourceMetadata    $resourceMetadata
     * @param string              $operationName
     * @param array               $operation
     * @param string              $prefixedShortName
     * @param string              $operationType
     * @param SubresourceMetadata $operationType
     *
     * @return array
     */
    private function getHydraOperation(string $resourceClass, ResourceMetadata $resourceMetadata, string $operationName, array $operation, string $prefixedShortName, string $operationType, SubresourceMetadata $subresourceMetadata = null): array
    {
        if (OperationType::COLLECTION === $operationType) {
            $method = $this->operationMethodResolver->getCollectionOperationMethod($resourceClass, $operationName);
        } elseif (OperationType::ITEM === $operationType) {
            $method = $this->operationMethodResolver->getItemOperationMethod($resourceClass, $operationName);
        } else {
            $method = 'GET';
        }

        $classMapping = [
            "@type" => "hydra:IriTemplate",
            "hydra:template" => $this->iriConverter->getIriFromResourceClass($resourceClass)."{?id,id[]}",
            "hydra:variableRepresentation" => "BasicRepresentation",
            "hydra:mapping"=> [
                [
                    "@type" => "hydra:IriTemplateMapping",
                    "hydra:variable" => "id",
                    "hydra:property" => "id",
                    "hydra:required" => true
                ],
                [
                    "@type" => "hydra:IriTemplateMapping",
                    "hydra:variable" => "id[]",
                    "hydra:property" => "id",
                    "hydra:required" => false
                ],
            ]
        ];

        $schemaEntrypoint = [
            "@type" => "schema:EntryPoint",
            "contentType" => "application/json+ld",
            "httpMethod" => $method,
            "urlTemplate" => $classMapping
        ];

        $hydraOperation = $operation['hydra_context'] ?? [];
        if ($resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'deprecation_reason', null, true)) {
            $hydraOperation['owl:deprecated'] = true;
        }

        $shortName = $resourceMetadata->getShortName();
        $inputClass = $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'input_class', null, true);
        $outputClass = $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'output_class', null, true);

        if ('GET' === $method && OperationType::COLLECTION === $operationType) {
            $hydraOperation += [
                '@type' => ['hydra:Operation', 'schema:SearchAction'],
                'hydra:title' => "Retrieves the collection of $shortName resources.",
                'returns' => 'hydra:Collection',
                'schema:result' => 'hydra:Collection',
                'schema:object' => "vocab:#$shortName",
                'schema:target' => $this->iriConverter->getIriFromResourceClass($resourceClass)
            ];
        } elseif ('GET' === $method && OperationType::SUBRESOURCE === $operationType) {
            $hydraOperation += [
                '@type' => ['hydra:Operation', 'schema:FindAction'],
                'hydra:title' => $subresourceMetadata->isCollection() ? "Retrieves the collection of $shortName resources." : "Retrieves a $shortName resource.",
                'returns' => "vocab:#$shortName",
                'schema:target' => $this->urlGenerator->generate('api_doc', ['_format' => self::FORMAT], UrlGeneratorInterface::ABS_URL).'#',
                #'hydra:title' => $subresourceMetadata && $subresourceMetadata->isCollection() ? "Retrieves the collection of $shortName resources." : "Retrieves a $shortName resource.",
                #'returns' => false === $outputClass ? 'owl:Nothing' : "#$shortName",
            ];
        } elseif ('GET' === $method) {
            $hydraOperation += [
                '@type' => ['hydra:Operation', 'schema:FindAction'],
                'hydra:title' => "Retrieves $shortName resource.",
                'returns' => "vocab:#$shortName",
                'schema:result' => "vocab:#$shortName",
                'schema:object' => "vocab:#$shortName",
                'schema:target' => $classMapping
        //         'returns' => false === $outputClass ? 'owl:Nothing' : $prefixedShortName,
        //     ];
        // } elseif ('PATCH' === $method) {
        //     $hydraOperation += [
        //         '@type' => 'hydra:Operation',
        //         'hydra:title' => "Updates the $shortName resource.",
        //         'returns' => false === $outputClass ? 'owl:Nothing' : $prefixedShortName,
        //         'expects' => false === $inputClass ? 'owl:Nothing' : $prefixedShortName,
            ];
        } elseif ('POST' === $method) {
            $hydraOperation += [
                '@type' => ['hydra:Operation', 'schema:CreateAction'],
                'hydra:title' => "Creates a $shortName resource.",
                'returns' => "vocab:#$shortName",
                'schema:result' => "vocab:#$shortName",
                'expects' => "vocab:#$shortName",
                'schema:object' => "vocab:#$shortName",
                'schema:target' => $this->iriConverter->getIriFromResourceClass($resourceClass),
                // 'returns' => false === $outputClass ? 'owl:Nothing' : $prefixedShortName,
                // 'expects' => false === $inputClass ? 'owl:Nothing' : $prefixedShortName,
            ];
        } elseif ('PUT' === $method) {
            $hydraOperation += [
                '@type' => ['hydra:Operation', 'schema:ReplaceAction'],
                'hydra:title' => "Replaces the $shortName resource.",
                'returns' => "vocab:#$shortName",
                'expects' => "vocab:#$shortName",
                'schema:object' => "vocab:#$shortName",
                'schema:result' => "vocab:#$shortName",
                'schema:target' => $classMapping,
                // 'returns' => false === $outputClass ? 'owl:Nothing' : $prefixedShortName,
                // 'expects' => false === $inputClass ? 'owl:Nothing' : $prefixedShortName,
            ];
        } elseif ('DELETE' === $method) {
            $hydraOperation += [
                '@type' => ['hydra:Operation', 'schema:DeleteAction'],
                'hydra:title' => "Deletes the $shortName resource.",
                'returns' => 'owl:Nothing',
                'schema:object' => "vocab:#$shortName",
                'schema:result' => 'owl:Nothing',
                'schema:target' => $classMapping
            ];
        }

        $hydraOperation['hydra:method'] ?? $hydraOperation['hydra:method'] = $method;

        if (!isset($hydraOperation['rdfs:label']) && isset($hydraOperation['hydra:title'])) {
            $hydraOperation['rdfs:label'] = $hydraOperation['hydra:title'];
        }

        ksort($hydraOperation);

        return $hydraOperation;
    }

    /**
     * Gets the range of the property.
     *
     * @return string|null
     */
    private function getRange(PropertyMetadata $propertyMetadata)
    {
        $jsonldContext = $propertyMetadata->getAttributes()['jsonld_context'] ?? [];

        if (isset($jsonldContext['@type'])) {
            return $jsonldContext['@type'];
        }

        if (null === $type = $propertyMetadata->getType()) {
            return null;
        }

        if ($type->isCollection() && null !== $collectionType = $type->getCollectionValueType()) {
            $type = $collectionType;
        }

        switch ($type->getBuiltinType()) {
            case Type::BUILTIN_TYPE_STRING:
                return 'xmls:string';
            case Type::BUILTIN_TYPE_INT:
                return 'xmls:integer';
            case Type::BUILTIN_TYPE_FLOAT:
                return 'xmls:decimal';
            case Type::BUILTIN_TYPE_BOOL:
                return 'xmls:boolean';
            case Type::BUILTIN_TYPE_OBJECT:
                if (null === $className = $type->getClassName()) {
                    return null;
                }

                if (is_a($className, \DateTimeInterface::class, true)) {
                    return 'xmls:dateTime';
                }

                if ($this->resourceClassResolver->isResourceClass($className)) {
                    $resourceMetadata = $this->resourceMetadataFactory->create($className);

                    return $resourceMetadata->getIri() ?? "#{$resourceMetadata->getShortName()}";
                }
                break;
        }

        return null;
    }

    /**
     * Builds the classes array.
     */
    private function getClasses(array $entrypointProperties, array $classes): array
    {
        $classes[] = [
            '@id' => '#Entrypoint',
            '@type' => 'hydra:Class',
            'hydra:title' => 'The API entrypoint',
            'hydra:supportedProperty' => $entrypointProperties,
            'hydra:supportedOperation' => [
                '@type' => 'hydra:Operation',
                'hydra:method' => 'GET',
                'rdfs:label' => 'The API entrypoint.',
                'returns' => '#EntryPoint',
            ],
        ];

        // Constraint violation
        $classes[] = [
            '@id' => '#ConstraintViolation',
            '@type' => 'hydra:Class',
            'hydra:title' => 'A constraint violation',
            'hydra:supportedProperty' => [
                [
                    '@type' => 'hydra:SupportedProperty',
                    'hydra:property' => [
                        '@id' => '#ConstraintViolation/propertyPath',
                        '@type' => 'rdf:Property',
                        'rdfs:label' => 'propertyPath',
                        'domain' => '#ConstraintViolation',
                        'range' => 'xmls:string',
                    ],
                    'hydra:title' => 'propertyPath',
                    'hydra:description' => 'The property path of the violation',
                    'hydra:readable' => true,
                    'hydra:writable' => false,
                ],
                [
                    '@type' => 'hydra:SupportedProperty',
                    'hydra:property' => [
                        '@id' => '#ConstraintViolation/message',
                        '@type' => 'rdf:Property',
                        'rdfs:label' => 'message',
                        'domain' => '#ConstraintViolation',
                        'range' => 'xmls:string',
                    ],
                    'hydra:title' => 'message',
                    'hydra:description' => 'The message associated with the violation',
                    'hydra:readable' => true,
                    'hydra:writable' => false,
                ],
            ],
        ];

        // Constraint violation list
        $classes[] = [
            '@id' => '#ConstraintViolationList',
            '@type' => 'hydra:Class',
            'subClassOf' => 'hydra:Error',
            'hydra:title' => 'A constraint violation list',
            'hydra:supportedProperty' => [
                [
                    '@type' => 'hydra:SupportedProperty',
                    'hydra:property' => [
                        '@id' => '#ConstraintViolationList/violations',
                        '@type' => 'rdf:Property',
                        'rdfs:label' => 'violations',
                        'domain' => '#ConstraintViolationList',
                        'range' => '#ConstraintViolation',
                    ],
                    'hydra:title' => 'violations',
                    'hydra:description' => 'The violations',
                    'hydra:readable' => true,
                    'hydra:writable' => false,
                ],
            ],
        ];

        return $classes;
    }

    /**
     * Gets a property definition.
     */
    private function getProperty(PropertyMetadata $propertyMetadata, string $propertyName, string $prefixedShortName, string $shortName): array
    {
        $propertyData = [
            '@id' => $propertyMetadata->getIri() ?? "#$shortName/$propertyName",
            '@type' => $propertyMetadata->isReadableLink() ? 'rdf:Property' : 'hydra:Link',
            'rdfs:label' => $propertyName,
            'domain' => $prefixedShortName,
        ];

        $type = $propertyMetadata->getType();

        if (null !== $type && !$type->isCollection() && (null !== $className = $type->getClassName()) && $this->resourceClassResolver->isResourceClass($className)) {
            $propertyData['owl:maxCardinality'] = 1;
        }

        // if (!is_null($propertyMetadata->getvocabType())) {
        //     $propType = [$propertyMetadata->isReadableLink() ? 'rdf:Property' : 'hydra:Link',$propertyMetadata->getvocabType()];
        // } else {
        //     $propType = [
        //         $propertyMetadata->isReadableLink() ? 'rdf:Property' : 'hydra:Link',
        //         $propertyMetadata->getIri() ?? ''
        //     ];
        // }
        // $propertyData = [
        //     '@id' => "vocab:#$shortName/$propertyName",
        //     '@type' => $propType,
        //     'rdfs:label' => $propertyName,
        //     'domain' => $prefixedShortName,
        // ];

        $property = [
            '@type' => 'hydra:SupportedProperty',
            'hydra:property' => $propertyData,
            'hydra:title' => $propertyName,
            'hydra:required' => $propertyMetadata->isRequired(),
            'hydra:readable' => $propertyMetadata->isReadable(),
            'hydra:writable' => $propertyMetadata->isWritable() || $propertyMetadata->isInitializable(),
        ];

        if (null !== $range = $this->getRange($propertyMetadata)) {
            $property['hydra:property']['range'] = $range;
        }

        if (null !== $description = $propertyMetadata->getDescription()) {
            $property['hydra:description'] = $description;
        }

        if ($propertyMetadata->getAttribute('deprecation_reason')) {
            $property['owl:deprecated'] = true;
        }

        return $property;
    }

    /**
     * Computes the documentation.
     */
    private function computeDoc(Documentation $object, array $classes): array
    {
        $doc = ['@context' => $this->getContext(), '@id' => $this->urlGenerator->generate('api_doc', ['_format' => self::FORMAT]), '@type' => 'hydra:ApiDocumentation'];

        if ('' !== $object->getTitle()) {
            $doc['hydra:title'] = $object->getTitle();
        }

        if ('' !== $object->getDescription()) {
            $doc['hydra:description'] = $object->getDescription();
        }

        $doc['hydra:entrypoint'] = $this->urlGenerator->generate('api_entrypoint');
        $doc['hydra:supportedClass'] = $classes;

        return $doc;
    }

    /**
     * Builds the JSON-LD context for the API documentation.
     */
    private function getContext(): array
    {
        return [
            // '@vocab' => $this->urlGenerator->generate('api_doc', ['_format' => self::FORMAT], UrlGeneratorInterface::ABS_URL).'#',
            'vocab' => $this->urlGenerator->generate('api_doc', ['_format' => self::FORMAT], UrlGeneratorInterface::ABS_URL),
            '@base' => rtrim($this->urlGenerator->generate('api_entrypoint', [], UrlGeneratorInterface::ABS_URL),'/'),
            'hydra' => ContextBuilderInterface::HYDRA_NS,
            'rdf' => ContextBuilderInterface::RDF_NS,
            'rdfs' => ContextBuilderInterface::RDFS_NS,
            'xmls' => ContextBuilderInterface::XML_NS,
            'owl' => ContextBuilderInterface::OWL_NS,
            'schema' => ContextBuilderInterface::SCHEMA_ORG_NS,
            'domain' => ['@id' => 'rdfs:domain', '@type' => '@id'],
            'range' => ['@id' => 'rdfs:range', '@type' => '@id'],
            'subClassOf' => ['@id' => 'rdfs:subClassOf', '@type' => '@id'],
            'expects' => ['@id' => 'hydra:expects', '@type' => '@id'],
            'returns' => ['@id' => 'hydra:returns', '@type' => '@id'],
            'property' => ['@id' => 'hydra:property'],
            'variable' => ['@id' => 'hydra:variable'],
            'required' => ['@id' => 'hydra:required'],
            'target' => ['@id' => 'schema:target', '@type' => '@id'],
            'query' => ['@id' => 'schema:query'],
            'schema:object' => ['@type' => '@id'],
            'schema:result' => ['@type' => '@id'],
            'schema:target' => ['@type' => '@id']
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null, array $context = [])
    {
        return self::FORMAT === $format && $data instanceof Documentation;
    }

    /**
     * {@inheritdoc}
     */
    public function hasCacheableSupportsMethod(): bool
    {
        return true;
    }
}
