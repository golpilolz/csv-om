<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

/**
 * Marker for the association attributes ({@see OneToOne}, {@see ManyToOne}, {@see OneToMany}, {@see ManyToMany}).
 */
interface Association
{
    /**
     * @return class-string
     */
    public function getTargetEntity(): string;

    /**
     * @return list<string>
     */
    public function getCascade(): array;

    public function getMappedBy(): ?string;

    public function getInversedBy(): ?string;
}
