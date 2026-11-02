<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;

/**
 * One object is referenced by many others. Always the inverse side of a #[ManyToOne]: no column is stored,
 * the collection is loaded from the owning side. Changes made only to this collection are not written.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class OneToMany implements Association
{
    /**
     * @param class-string $targetEntity
     * @param list<string> $cascade Only "persist" is supported: persisting this object also persists the related ones.
     * @param string $mappedBy Property of the target holding the owning #[ManyToOne].
     */
    public function __construct(
        public string $targetEntity,
        public string $mappedBy,
        public array $cascade = [],
    ) {
    }

    public function getTargetEntity(): string
    {
        return $this->targetEntity;
    }

    public function getCascade(): array
    {
        return $this->cascade;
    }

    public function getMappedBy(): ?string
    {
        return $this->mappedBy;
    }

    public function getInversedBy(): ?string
    {
        return null;
    }
}
