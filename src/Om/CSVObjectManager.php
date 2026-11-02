<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

use Golpilolz\CsvOm\Om\Hydrator\ObjectHydrator;
use Golpilolz\CsvOm\Om\Metadata\ClassMetadata;
use Golpilolz\CsvOm\Om\Metadata\MetadataFactory;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;
use Golpilolz\CsvOm\Om\Type\TypeConverter;
use Golpilolz\CsvOm\Storage\CsvStorageInterface;

final class CSVObjectManager implements CSVObjectManagerInterface
{
    /** @var array<class-string, RepositoryInterface<object>> */
    private array $repositories = [];

    private readonly MetadataFactory $metadataFactory;
    private readonly UnitOfWork $unitOfWork;

    public function __construct(
        CsvStorageInterface $storage,
        ?MetadataFactory $metadataFactory = null,
        ?TypeConverter $converter = null,
    ) {
        $converter ??= new TypeConverter();
        $this->metadataFactory = $metadataFactory ?? new MetadataFactory();
        $this->unitOfWork = new UnitOfWork($storage, new ObjectHydrator($converter), $converter);
    }

    public function find(string $className, mixed $id): ?object
    {
        return $this->unitOfWork->find($this->getClassMetadata($className), $id);
    }

    public function getRepository(string $className): RepositoryInterface
    {
        $metadata = $this->getClassMetadata($className);

        return $this->repositories[$metadata->className] ??= new ($metadata->repositoryClass)($this, $metadata->className);
    }

    public function getClassMetadata(string $className): ClassMetadata
    {
        return $this->metadataFactory->getMetadataFor($className);
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWork;
    }
}
