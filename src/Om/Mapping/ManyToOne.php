<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;

/**
 * Many objects point to one target. Always the owning side: the join column holds the target id.
 * Configure the column with #[JoinColumn].
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ManyToOne implements Association
{
    /**
     * @param class-string $targetEntity
     * @param list<string> $cascade Only "persist" is supported: persisting this object also persists the related ones.
     * @param string|null $inversedBy Property of the target holding the matching #[OneToMany] collection.
     */
    public function __construct(
        public string $targetEntity,
        public ?string $inversedBy = null,
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
        return null;
    }

    public function getInversedBy(): ?string
    {
        return $this->inversedBy;
    }
}
