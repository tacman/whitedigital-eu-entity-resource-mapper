<?php

declare(strict_types = 1);

namespace WhiteDigital\EntityResourceMapper\Filters;

use ApiPlatform\Doctrine\Common\Filter\DateFilterInterface;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use WhiteDigital\EntityResourceMapper\Mapper\AccessClassMapperTrait;

class ResourceDateFilter implements FilterInterface, DateFilterInterface
{
    use AccessClassMapperTrait;
    use Traits\PropertyNameNormalizer;

    /**
     * @param array<string, mixed>|null $properties
     */
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?array $properties = null,
        private readonly ?NameConverterInterface $nameConverter = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $context
     *
     * @throws \Exception
     */
    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string|Operation $operation = null, ?array $context = null): void
    {
        $timeZone = new DateTimeZone(date_default_timezone_get());

        foreach ($context['filters'] as $property => $filter) {
            if (!array_key_exists($property, $this->properties)) {
                unset($context['filters'][$property]);
                continue;
            }
            foreach ($filter as $condition => $value) {
                // Try to instantiate DateTime object to validate filter value, otherwise it gets ignored later.
                // Also set default timezone if data is stored without TZ information in the database.
                $validDateTime = new DateTime($value);
                $context['filters'][$property][$condition] = $validDateTime->setTimezone($timeZone)->format(DateTimeInterface::ATOM);
            }
        }
        $dateFilter = new DateFilter(
            $this->managerRegistry,
            $this->logger,
            $this->properties,
            $this->nameConverter,
        );
        $resourceClass = $this->classMapper->byResource($resourceClass);

        $dateFilter->apply($queryBuilder, $queryNameGenerator, $resourceClass, $operation, $context);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     * @throws ReflectionException
     */
    public function getDescription(string $resourceClass): array
    {
        $description = [];
        if (null === $this->properties) {
            throw new Exception(sprintf('Please explicitly mark properties for %s class', self::class));
        }
        $reflection = new ReflectionClass($resourceClass);

        foreach ($this->properties as $property => $nullManagement) {
            $propertyReflection = $reflection->getProperty($property);
            $propertyType = $propertyReflection->getType();
            if ($propertyType instanceof ReflectionNamedType) {
                $typeName = $propertyType->getName();
            } else {
                throw new RuntimeException('Type without name encountered.');
            }
            if (!is_subclass_of($typeName, DateTimeInterface::class)) {
                continue;
            }

            $description += $this->getFilterDescription($property, self::PARAMETER_BEFORE);
            $description += $this->getFilterDescription($property, self::PARAMETER_STRICTLY_BEFORE);
            $description += $this->getFilterDescription($property, self::PARAMETER_AFTER);
            $description += $this->getFilterDescription($property, self::PARAMETER_STRICTLY_AFTER);
        }

        return $description;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getFilterDescription(string $property, string $period): array
    {
        $propertyName = $this->normalizePropertyName($property);

        return [
            sprintf('%s[%s]', $propertyName, $period) => [
                'property' => $propertyName,
                'type' => DateTimeInterface::class,
                'required' => false,
            ],
        ];
    }
}
