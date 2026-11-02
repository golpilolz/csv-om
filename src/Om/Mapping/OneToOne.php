<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;

/**
 * One-to-one relation. Owning side by default (the join column holds the target id, see #[JoinColumn]);
 * with `mappedBy` it is the inverse side: no column, loaded from the owning side.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class OneToOne implements Association
{
    /**
     * @param class-string $targetEntity
     * @param list<string> $cascade Only "persist" is supported: persisting this object also persists the related ones.
     * @param string|null $mappedBy Set on the inverse side: property of the target holding the owning #[OneToOne].
     * @param string|null $inversedBy Set on the owning side: property of the target holding the inverse #[OneToOne].
     */
    public function __construct(
        public string $targetEntity,
        public ?string $mappedBy = null,
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
        return $this->mappedBy;
    }

    public function getInversedBy(): ?string
    {
        return $this->inversedBy;
    }
}
