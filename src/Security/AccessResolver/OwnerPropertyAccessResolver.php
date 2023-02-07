<?php

declare(strict_types = 1);

namespace WhiteDigital\EntityResourceMapper\Security\AccessResolver;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use WhiteDigital\EntityResourceMapper\Entity\BaseEntity;
use WhiteDigital\EntityResourceMapper\Security\Attribute\AccessResolverConfiguration;
use WhiteDigital\EntityResourceMapper\Security\Interface\AccessResolverInterface;

class OwnerPropertyAccessResolver implements AccessResolverInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    public function isObjectAccessGranted(AccessResolverConfiguration $accessResolverAttribute, object $object): bool
    {
        $propertyPath = $this->retrieveOwnerPropertyPathFromConfig($accessResolverAttribute->getConfig());
        $topElement = $object;
        $isCollection = false;
        foreach (explode('.', $propertyPath) as $node) {
            if (str_ends_with($node, '[]')  // Collection as NON-LAST item in property chain
                && !str_ends_with($propertyPath, $node)) {
                throw new InvalidArgumentException('Collection is not supported as non-last element.');
            }
            if (str_ends_with($node, '[]')) {
                $node = substr($node, 0, -2);
                $isCollection = true;
            }
            $topElement = $this->propertyAccessor->getValue($topElement, $node);
        }
        if ($isCollection) {
            /* @var Collection<int, BaseEntity> $topElement */
            return $topElement->contains($this->security->getUser());
        }
        $isObject = is_object($topElement); // handle scalar or object
        $authorizedValueId = $this->propertyAccessor->getValue($this->security->getUser(), 'id');

        return $isObject ? ($this->propertyAccessor->getValue($topElement, 'id') === $authorizedValueId)
            : ($topElement === $authorizedValueId);
    }

    public function limitCollectionQuery(AccessResolverConfiguration $accessResolverAttribute, QueryBuilder $queryBuilder): void
    {
        $propertyPath = $this->retrieveOwnerPropertyPathFromConfig($accessResolverAttribute->getConfig());
        if ($this->isOwnerPropertyNested($propertyPath)) {
            $this->applyNestedPropertyConstraints($propertyPath, $queryBuilder);
        } else {
            $this->applyRegularPropertyConstraints($propertyPath, $queryBuilder);
        }
        $queryBuilder->setParameter('ownerValue', $this->security->getUser());
    }

    protected function isOwnerPropertyNested(string $property): bool
    {
        return str_contains($property, '.');
    }

    /**
     * Because owner property value is a nested property, we need to add joins to query builder.
     */
    protected function applyNestedPropertyConstraints(string $propertyPath, QueryBuilder $queryBuilder): void
    {
        [$propertyName, $joins] = $this->validateAndCreateJoins($propertyPath);
        $tableAlias = $queryBuilder->getRootAliases()[0];
        foreach ($joins as $join) {
            // check if join already exists
            foreach ($queryBuilder->getDQLPart('join') as $joinPart) {
                if ($joinPart[0]->getJoin() === "$tableAlias.$join") {
                    continue 2;
                }
            }
            $queryBuilder->join("$tableAlias.$join", $join);
            $tableAlias = $join;
        }
        $propertyPath = str_ends_with($propertyPath, '[]') ? $tableAlias : "$tableAlias.$propertyName";
        $queryBuilder->andWhere("$propertyPath = :ownerValue");
    }

    protected function validateAndCreateJoins(string $propertyPath): array
    {
        $propertyName = null;
        $joins = explode('.', $propertyPath);
        if (!str_ends_with($propertyPath, '[]')) {
            $propertyName = array_pop($joins);
        }
        foreach ($joins as &$join) {
            if (str_ends_with($join, '[]')) {
                if (!str_ends_with($propertyPath, $join)) {
                    throw new InvalidArgumentException('Collection is not supported as non-last element.');
                }
                $join = substr($join, 0, -2);
            }
        }

        return [$propertyName, $joins];
    }

    protected function applyRegularPropertyConstraints(string $propertyPath, QueryBuilder $queryBuilder): void
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];
        if (str_ends_with($propertyPath, '[]')) { // owner property is to-many association
            $propertyPath = substr($propertyPath, 0, -2);
            $queryBuilder->join("$rootAlias.$propertyPath", $propertyPath);
            $queryBuilder->andWhere("$propertyPath = :ownerValue");
        } else {
            $queryBuilder->andWhere("$rootAlias.$propertyPath = :ownerValue");
        }
    }

    protected function retrieveOwnerPropertyPathFromConfig(?array $config)
    {
        if (!$config || !isset($config['ownerPropertyPath'])) {
            throw new InvalidArgumentException(sprintf('Access resolver configuration for "%s" does not contain required "ownerPropertyPath" entry', self::class));
        }

        return $config['ownerPropertyPath'];
    }
}
