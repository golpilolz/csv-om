<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om;

use Golpilolz\CsvOm\Client\CSVClient;
use Golpilolz\CsvOm\Client\CSVClientInterface;
use Golpilolz\CsvOm\Om\Metadata\ClassMetadata;
use Golpilolz\CsvOm\Om\Metadata\MetadataFactory;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;

final class CSVObjectManager implements CSVObjectManagerInterface
{
    /** @var array<class-string, RepositoryInterface> */
    private array $repositories = [];

    private CSVClientInterface $csvClient;
    private MetadataFactory $metadataFactory;

    public function __construct(?CSVClientInterface $csvClient = null, ?MetadataFactory $metadataFactory = null)
    {
        $this->csvClient = $csvClient ?? new CSVClient();
        $this->metadataFactory = $metadataFactory ?? new MetadataFactory();
    }

    /**
     * @inheritdoc
     */
    public function find(string $className, $id): ?object
    {
        return $this->getRepository($className)->find($id);
    }

    /**
     * @inheritdoc
     */
    public function getRepository(string $className): RepositoryInterface
    {
        $metadata = $this->getClassMetadata($className);

        if (!isset($this->repositories[$metadata->className])) {
            // Temporary wiring: repositories get storage, hydration and the unit of work in step 5.
            $repository = new ($metadata->repositoryClass)();
            $repository->className = $metadata->className;
            $repository->prefix = $metadata->file;
            $this->repositories[$metadata->className] = $repository;
        }

        return $this->repositories[$metadata->className];
    }

    /**
     * @inheritdoc
     */
    public function getClassMetadata(string $className): ClassMetadata
    {
        return $this->metadataFactory->getMetadataFor($className);
    }
}
