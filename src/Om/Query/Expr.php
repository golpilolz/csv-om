<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Query;

/**
 * Factory of filter expressions, to be used with {@see Criteria} or passed to `count()`.
 *
 * Fields are property names. Operands are typed like `findBy()` criteria (`'7'` is accepted for an int field,
 * an object for an owning to-one association). Ordering operators (`gt`, `gte`, `lt`, `lte`, `between`) compare
 * typed values, so numbers compare numerically and dates chronologically; they never match an empty cell, and
 * do not apply to associations. `contains`, `startsWith` and `endsWith` are case-sensitive and compare the cell
 * text. `neq` and `notIn` do match empty cells.
 */
final class Expr
{
    public static function eq(string $field, mixed $value): Comparison
    {
        return new Comparison($field, Operator::EQ, $value);
    }

    public static function neq(string $field, mixed $value): Comparison
    {
        return new Comparison($field, Operator::NEQ, $value);
    }

    public static function gt(string $field, mixed $value): Comparison
    {
        return new Comparison($field, Operator::GT, $value);
    }

    public static function gte(string $field, mixed $value): Comparison
    {
        return new Comparison($field, Operator::GTE, $value);
    }

    public static function lt(string $field, mixed $value): Comparison
    {
        return new Comparison($field, Operator::LT, $value);
    }

    public static function lte(string $field, mixed $value): Comparison
    {
        return new Comparison($field, Operator::LTE, $value);
    }

    /**
     * @param list<mixed> $values
     */
    public static function in(string $field, array $values): Comparison
    {
        return new Comparison($field, Operator::IN, array_values($values));
    }

    /**
     * @param list<mixed> $values
     */
    public static function notIn(string $field, array $values): Comparison
    {
        return new Comparison($field, Operator::NIN, array_values($values));
    }

    public static function isNull(string $field): Comparison
    {
        return new Comparison($field, Operator::EQ, null);
    }

    public static function isNotNull(string $field): Comparison
    {
        return new Comparison($field, Operator::NEQ, null);
    }

    /** Inclusive on both ends. */
    public static function between(string $field, mixed $min, mixed $max): Composite
    {
        return new Composite(Composite::AND, [self::gte($field, $min), self::lte($field, $max)]);
    }

    public static function contains(string $field, string $text): Comparison
    {
        return new Comparison($field, Operator::CONTAINS, $text);
    }

    public static function startsWith(string $field, string $text): Comparison
    {
        return new Comparison($field, Operator::STARTS_WITH, $text);
    }

    public static function endsWith(string $field, string $text): Comparison
    {
        return new Comparison($field, Operator::ENDS_WITH, $text);
    }

    public static function andX(Expression ...$expressions): Composite
    {
        return new Composite(Composite::AND, array_values($expressions));
    }

    public static function orX(Expression ...$expressions): Composite
    {
        return new Composite(Composite::OR, array_values($expressions));
    }

    public static function not(Expression $expression): Composite
    {
        return new Composite(Composite::NOT, [$expression]);
    }
}
