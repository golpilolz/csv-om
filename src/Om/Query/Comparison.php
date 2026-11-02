<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Query;

/**
 * One field compared to a value. Build it with {@see Expr}.
 */
final readonly class Comparison implements Expression
{
    public function __construct(
        public string $field,
        public Operator $operator,
        public mixed $value,
    ) {
    }
}
