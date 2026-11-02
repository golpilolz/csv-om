<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;

/**
 * Configures the column holding the target id of an owning #[ManyToOne] / #[OneToOne].
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class JoinColumn
{
    public const string RESTRICT = 'RESTRICT';
    public const string SET_NULL = 'SET NULL';
    public const string CASCADE = 'CASCADE';

    /**
     * @param string|null $name Column name. Defaults to "<property>_id".
     * @param bool|null $nullable Whether an empty cell maps to null. Inferred from the property type when null.
     * @param string $onDelete What happens to this object when the target is removed: RESTRICT (flush fails),
     *                         SET NULL (the property becomes null; requires a nullable association) or
     *                         CASCADE (this object is removed too).
     */
    public function __construct(
        public ?string $name = null,
        public ?bool $nullable = null,
        public string $onDelete = self::RESTRICT,
    ) {
    }
}
