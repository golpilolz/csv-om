<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

use Golpilolz\CsvOm\Exception\MappingException;
use Golpilolz\CsvOm\Om\Hydrator\ObjectHydrator;
use Golpilolz\CsvOm\Om\Metadata\ClassMetadata;
use Golpilolz\CsvOm\Om\Metadata\MetadataFactory;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;
use Golpilolz\CsvOm\Om\Type\TypeConverter;
use Golpilolz\CsvOm\Storage\CsvStorageInterface;
use Psr\Container\ContainerInterface;

// Implements the deprecated interface too, so existing type-hints keep receiving this service.
final class ObjectManager implements CSVObjectManagerInterface
{
    /** @var array<class-string, RepositoryInterface<object>> */
    private array $repositories = [];

    private readonly MetadataFactory $metadataFactory;
    private readonly UnitOfWork $unitOfWork;

    /**
     * @param ContainerInterface|null $repositoryLocator repository services keyed by repository class
     */
    public function __construct(
        CsvStorageInterface $storage,
        ?MetadataFactory $metadataFactory = null,
        ?TypeConverter $converter = null,
        private readonly ?ContainerInterface $repositoryLocator = null,
    ) {
        $converter ??= new TypeConverter();
        $this->metadataFactory = $metadataFactory ?? new MetadataFactory();
        $this->unitOfWork = new UnitOfWork($storage, new ObjectHydrator($converter), $converter, $this->metadataFactory);
    }

    public function find(string $className, mixed $id): ?object
    {
        return $this->unitOfWork->find($this->getClassMetadata($className), $id);
    }

    public function getRepository(string $className): RepositoryInterface
    {
        $metadata = $this->getClassMetadata($className);

        return $this->repositories[$metadata->className] ??= $this->createRepository($metadata);
    }

    public function getClassMetadata(string $className): ClassMetadata
    {
        return $this->metadataFactory->getMetadataFor($className);
    }

    public function persist(object $object): void
    {
        $this->unitOfWork->persist($this->getClassMetadata($object::class), $object);
    }

    public function remove(object $object): void
    {
        $this->unitOfWork->remove($this->getClassMetadata($object::class), $object);
    }

    public function flush(): void
    {
        $this->unitOfWork->flush();
    }

    public function clear(): void
    {
        $this->unitOfWork->clear();
    }

    public function contains(object $object): bool
    {
        return $this->unitOfWork->contains($object);
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWork;
    }

    /**
     * Uses the repository service when there is one (so it is the same instance autowiring injects),
     * otherwise instantiates the repository class with (objectManager, className).
     *
     * @param ClassMetadata<object> $metadata
     * @return RepositoryInterface<object>
     */
    private function createRepository(ClassMetadata $metadata): RepositoryInterface
    {
        if ($this->repositoryLocator?->has($metadata->repositoryClass)) {
            $repository = $this->repositoryLocator->get($metadata->repositoryClass);
            if ($repository->getClassName() !== $metadata->className) {
                throw MappingException::repositoryEntityMismatch($metadata->repositoryClass, $metadata->className, $repository->getClassName());
            }

            return $repository;
        }

        return new ($metadata->repositoryClass)($this, $metadata->className);
    }
}
