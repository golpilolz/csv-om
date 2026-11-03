<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Metadata;

/**
 * Mapping of one relation to another entity.
 *
 * - owning to-one ({@see self::MANY_TO_ONE}, owning {@see self::ONE_TO_ONE}): stored in a join column of the
 *   entity's own file ({@see self::$joinMapping}, typed like the target id);
 * - owning {@see self::MANY_TO_MANY}: stored in a join file ({@see self::$joinFile}), or in a directory of join files when {@see self::$joinDirectory} (then `joinFile` holds the directory);
 * - inverse sides ({@see self::ONE_TO_MANY}, `mappedBy` set): nothing stored, read from the owning side.
 */
final readonly class AssociationMapping
{
    public const string ONE_TO_ONE = 'one_to_one';
    public const string MANY_TO_ONE = 'many_to_one';
    public const string ONE_TO_MANY = 'one_to_many';
    public const string MANY_TO_MANY = 'many_to_many';

    /**
     * @param class-string $targetEntity
     * @param bool $nullable to-one only: whether the property accepts null
     * @param FieldMapping|null $joinMapping owning to-one only: the join column, named after the property
     * @param string $onDelete owning to-one only: RESTRICT, SET NULL or CASCADE
     */
    public function __construct(
        public string $fieldName,
        public string $type,
        public string $targetEntity,
        public ?string $mappedBy = null,
        public ?string $inversedBy = null,
        public bool $cascadePersist = false,
        public bool $nullable = true,
        public ?FieldMapping $joinMapping = null,
        public string $onDelete = 'RESTRICT',
        public ?string $joinFile = null,
        public ?string $joinOwnerColumn = null,
        public ?string $joinTargetColumn = null,
        public bool $joinDirectory = false,
        public bool $joinRecursive = true,
        public ?string $joinNewFile = null,
    ) {
    }

    public function isToOne(): bool
    {
        return $this->type === self::MANY_TO_ONE || $this->type === self::ONE_TO_ONE;
    }

    public function isToMany(): bool
    {
        return !$this->isToOne();
    }

    public function isOwning(): bool
    {
        return match ($this->type) {
            self::MANY_TO_ONE => true,
            self::ONE_TO_MANY => false,
            default => $this->mappedBy === null,
        };
    }

    /** Whether the relation is stored as a column of the entity's own file. */
    public function hasJoinColumn(): bool
    {
        return $this->joinMapping !== null;
    }
}
