<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

use Golpilolz\CsvOm\Client\CSVClient;
use Golpilolz\CsvOm\Client\CSVClientInterface;
use Golpilolz\CsvOm\Om\Mapping\Entity;

final class CSVObjectManager
{
    /** @var array<string, Entity> */
    private array $entityMapperCache = [];

    /** @var array<string, array<string|int, object>> Identity map: className -> id -> object */
    private array $identityMap = [];

    private CSVClientInterface $csvClient;

    public function __construct(?CSVClientInterface $csvClient = null)
    {
        $this->csvClient = $csvClient ?? new CsvClient();
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
            throw new \InvalidArgumentException('The object must be annotated with #[RedisOm\Entity] attribute');
        }

        /** @var Entity $csvEntity */
        $csvEntity = $attributes[0]->newInstance();

//        $redisEntity->repository->setPrefix($redisEntity->prefix ?? $reflectionClass->getName());
//        $redisEntity->repository->setClassName($reflectionClass->getName());
//        $redisEntity->repository->setConverter($redisEntity->converter ?? ($redisEntity->format === RedisFormat::HASH->value ? new HashObjectConverter() : new JsonObjectConverter()));

//        $redisEntity->repository->setRedisClient($this->redisClient);
//        $redisEntity->repository->setFormat($redisEntity->format);

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

        // Store in identity map and take snapshot for dirty tracking
        if ($object !== null) {
            $this->identityMap[$className][$id] = $object;
            $snapshotKey = $className . ':' . $id;
            $this->snapshots[$snapshotKey] = $objectMapper->converter->convert($object);
        }

        return $object;
    }
}