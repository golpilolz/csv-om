<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;

/**
 * Many-to-many relation, stored in a join file (see #[JoinTable]). Owning side by default;
 * with `mappedBy` it is the inverse side and its changes are not written.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ManyToMany implements Association
{
    /**
     * @param class-string $targetEntity
     * @param list<string> $cascade Only "persist" is supported: persisting this object also persists the related ones.
     * @param string|null $mappedBy Set on the inverse side: property of the target holding the owning #[ManyToMany].
     * @param string|null $inversedBy Set on the owning side: property of the target holding the inverse #[ManyToMany].
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
