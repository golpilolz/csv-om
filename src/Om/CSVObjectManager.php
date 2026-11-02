<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

use Golpilolz\CsvOm\Client\CSVClient;
use Golpilolz\CsvOm\Client\CSVClientInterface;
use Golpilolz\CsvOm\Om\Mapping\Entity;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;

final class CSVObjectManager implements CSVObjectManagerInterface
{
    /** @var array<string, Entity> */
    private array $entityMapperCache = [];

    /** @var array<string, array<string|int, object>> Identity map: className -> id -> object */
    private array $identityMap = [];

    private CSVClientInterface $csvClient;

    public function __construct(?CSVClientInterface $csvClient = null)
    {
        $this->csvClient = $csvClient ?? new CSVClient();
    }

    protected function getEntityMapper(string|object $object): Entity
    {
        $className = is_string($object) ? $object : get_class($object);

        if (array_key_exists($className, $this->entityMapperCache)) {
            return $this->entityMapperCache[$className];
        }

        $reflectionClass = new \ReflectionClass($className);
        $attributes = $reflectionClass->getAttributes(Entity::class);
        if ($attributes === []) {
            throw new \InvalidArgumentException('The object must be annotated with #[CsvOm\Entity] attribute');
        }

        /** @var Entity $csvEntity */
        $csvEntity = $attributes[0]->newInstance();

        $this->entityMapperCache[$className] = $csvEntity;

        return $csvEntity;
    }

    /**
     * @inheritdoc
     */
    public function find(string $className, $id): ?object
    {
        // Check identity map first
        if (isset($this->identityMap[$className][$id])) {
            return $this->identityMap[$className][$id];
        }

        $objectMapper = $this->getEntityMapper($className);
        $object = $objectMapper->repository->find((string)$id);

        return $object;
    }

    /**
     * @inheritdoc
     */
    public function getRepository(string $className): RepositoryInterface
    {
        $objectMapper = $this->getEntityMapper($className);

        return $objectMapper->repository;
    }
}