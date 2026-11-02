<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Query;

/**
 * Expressions combined with AND / OR, or negated with NOT (which holds exactly one expression).
 * An empty AND matches every row, an empty OR matches none. Build it with {@see Expr}.
 */
final readonly class Composite implements Expression
{
    public const string AND = 'AND';
    public const string OR = 'OR';
    public const string NOT = 'NOT';

    /**
     * @param self::AND|self::OR|self::NOT $type
     * @param list<Expression> $expressions
     */
    public function __construct(
        public string $type,
        public array $expressions,
    ) {
    }
}
