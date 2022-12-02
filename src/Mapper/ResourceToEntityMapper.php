<?php

declare(strict_types = 1);

namespace WhiteDigital\EntityResourceMapper\Mapper;

use Countable;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Error;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\String\Inflector\EnglishInflector;
use WhiteDigital\EntityResourceMapper\Entity\BaseEntity;
use WhiteDigital\EntityResourceMapper\Resource\BaseResource;

class ResourceToEntityMapper
{
    public const CONDITION_CONTEXT = 'condition_context';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClassMapper $classMapper,
    ) {
        BaseEntity::setResourceToEntityMapper($this);
    }

    /**
     * Api Resource mapper to convert Api Resource to Entity (existing or new).
     *
     * @param array<string, mixed> $context
     */
    public function map(BaseResource $object, array $context, ?BaseEntity $existingEntity = null): BaseEntity
    {
        $reflection = new ReflectionClass($object);
        $targetEntityClass = $this->classMapper->byResource($reflection->getName(), $context[self::CONDITION_CONTEXT] ?? null);

        $properties = $reflection->getProperties();
        $output = $existingEntity ?? new $targetEntityClass();

        foreach ($properties as $property) {
            $propertyName = $property->getName();
            if (!property_exists($targetEntityClass, $propertyName)) {
                continue; // Silently skip properties which do not exist on target class.
            }
            /** @phpstan-ignore-next-line */
            $propertyType = $property->getType()?->getName();
            try {
                $propertyValue = $property->getValue($object);
            } catch (Error) {
                $propertyValue = null;
            }

            //  DateTimeInterface implementations are converted to DateTimeImmutable in entities
            if (is_subclass_of($propertyType, DateTimeInterface::class)
                && $propertyValue instanceof DateTimeInterface) {
                $propertyValue = DateTimeImmutable::createFromInterface($propertyValue);
            }

            // For existing entities, lets not update anything, if value not changed
            if (null !== $existingEntity && $this->compareValues($this->callMethod($output, 'get', $propertyName), $propertyValue)) {
                continue; // No updated needed, skip it
            }

            // 1A. Normalize relations for Array<BaseResource> properties
            if ('array' === $propertyType && $this->isRelationProperty($object, $propertyName)) { // array of entities
                // First, remove all of existing collection values, then add new ones
                foreach ($this->callMethod($output, 'get', $propertyName) as $valueToRemove) {
                    $this->callMethod($output, 'remove', $propertyName, $valueToRemove);
                }
                if (null === $propertyValue || 0 === count($propertyValue)) {
                    continue;
                }
                $targetClass = $this->classMapper->byResource(get_class($propertyValue[0]), $object::class); // assume equal data types in array
                foreach ($propertyValue as $value) {
                    if (isset($value->id)) { // entity already exists, lets fetch it from DB
                        $repository = $this->entityManager->getRepository($targetClass);
                        $entity = $repository->find($value->id);
                        if (null === $entity) {
                            throw new RuntimeException("$targetClass entity with id $value->id not found!");
                        }
//                        $output[$propertyName]->add($entity);
                        $this->callMethod($output, 'add', $propertyName, $entity);
                        continue;
                    }
                    $this->callMethod($output, 'add', $propertyName, $this->map($value, $context));
                }
                continue;
            }

            // 1B. Normalize relations for BaseResource properties
            /* @var BaseResource $propertyValue */
            if ($this->isPropertyBaseDto($propertyType)) {
                $target_class = $this->classMapper->byResource($propertyType);
                if (isset($propertyValue->id)) { // entity already exists, lets fetch it from DB
                    $repository = $this->entityManager->getRepository($target_class);
                    $entity = $repository->find($propertyValue->id);
                    if (null === $entity) {
                        throw new RuntimeException("$target_class entity with id $propertyValue->id not found!");
                    }
                    $this->callMethod($output, 'set', $propertyName, $entity);
                    continue;
                }
                if (null !== $propertyValue) { // Null property will be set in step 2.
                    $this->callMethod($output, 'set', $propertyName, $this->map($propertyValue, $context));
                    continue;
                }
            }

            // 2. Finally, map output value to input
            if (null !== $existingEntity || null !== $propertyValue) { // for existing entities set any value, for new entities only non-null
                $this->callMethod($output, 'set', $propertyName, $propertyValue);
            }
        }

        return $output;
    }

    /**
     * Checks, if given object is inherited from BaseResource class.
     */
    private function isPropertyBaseDto(string $class): bool
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return false; // Property is not a (known) class
        }
        while ($reflection = $reflection->getParentClass()) {
            if (BaseResource::class === $reflection->getName()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks DocBlock if property is child of Collection<mixed, BaseResource>.
     */
    private function isRelationProperty(BaseResource $object, string $propertyName): bool
    {
        $phpDocExtractor = new PhpDocExtractor();
        $types = $phpDocExtractor->getTypes($object::class, $propertyName);
        if (null === $types) {
            return false;
        }
        $type = $types[0];
        if ($type->isCollection()) {
            $collectionValueType = $type->getCollectionValueTypes()[0] ?? null;

            return is_subclass_of($collectionValueType?->getClassName(), BaseResource::class);
        }

        return false;
    }

    /**
     * Dynamically call entity->addProperty, removeProperty, setProperty, getProperty.
     */
    private function callMethod(BaseEntity $object, string $method, string $property, mixed $value = null): mixed
    {
        $property = ucfirst($property);
        $propertySingular = '';
        foreach ((new EnglishInflector())->singularize($property) as $singularValue) {
            if (strlen($singularValue) > strlen($propertySingular)) {
                $propertySingular = $singularValue;
            }
        }
        try {
            return $object->{"$method$propertySingular"}($value);
        } catch (Error $e) { // Catch only one type of errors
            if (!str_contains($e->getMessage(), 'Call to undefined method')) {
                throw $e;
            }

            return $object->{"$method$property"}($value);
        }
    }

    private function compareValues(mixed $value1, mixed $value2): bool
    {
        // Scalar types
        if ($value1 === $value2) {
            return true;
        }
        // \DateTimeInterface implementations
        if ($value1 instanceof DateTimeInterface && $value2 instanceof DateTimeInterface) {
            return $value1->getTimestamp() === $value2->getTimestamp();
        }
        // Doctrine Collection and array, both empty
        if ($value1 instanceof Countable && null === $value2) {
            $value2 = [];
        }
        if ($value1 instanceof Countable && (0 === count($value1) && 0 === count($value2))) {
            return true;
        }
        // Doctrine collection and array, both identical
        if ($value1 instanceof PersistentCollection && is_array($value2) && count($value1) === count($value2)) {
            /** @var BaseEntity[] $firstSet */
            $firstSet = $value1->getValues();
            /** @var BaseResource[] $secondSet */
            $secondSet = $value2;
            $equal = true;
            for ($i = 0, $iMax = count($firstSet); $i < $iMax; $i++) {
                $classesAreEqual = get_class($firstSet[$i]) === $this->classMapper->byResource(get_class($secondSet[$i]), get_class($firstSet[$i]));
                $idsAreEqual = $firstSet[$i]->getId() === $secondSet[$i]->id;
                $equal = $classesAreEqual && $idsAreEqual;
                if (!$equal) {
                    break;
                }
            }
            if ($equal) {
                return true;
            }
        }

        return false;
    }
}
