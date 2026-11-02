<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Query;

/**
 * A reusable query: a filter, an order and a window, run with `$repository->matching($criteria)`.
 *
 * ```php
 * $criteria = Criteria::create()
 *     ->where(Expr::gte('price', 10))
 *     ->andWhere(Expr::orX(Expr::eq('status', BookStatus::Published), Expr::startsWith('title', 'The ')))
 *     ->orderBy(['price' => 'DESC'])
 *     ->setMaxResults(20);
 * ```
 *
 * Mutable and fluent, like Doctrine's `Criteria`. Like `findBy()`, it reads the data as loaded from the file.
 */
final class Criteria
{
    private ?Expression $where = null;

    /** @var array<string, string> */
    private array $orderBy = [];

    private int $firstResult = 0;

    private ?int $maxResults = null;

    public static function create(): self
    {
        return new self();
    }

    public function where(Expression $expression): self
    {
        $this->where = $expression;

        return $this;
    }

    public function andWhere(Expression $expression): self
    {
        $this->where = $this->where === null ? $expression : Expr::andX($this->where, $expression);

        return $this;
    }

    public function orWhere(Expression $expression): self
    {
        $this->where = $this->where === null ? $expression : Expr::orX($this->where, $expression);

        return $this;
    }

    /**
     * @param array<string, string> $orderBy field name => 'ASC' | 'DESC'
     */
    public function orderBy(array $orderBy): self
    {
        $this->orderBy = $orderBy;

        return $this;
    }

    public function setFirstResult(int $firstResult): self
    {
        $this->firstResult = $firstResult;

        return $this;
    }

    public function setMaxResults(?int $maxResults): self
    {
        $this->maxResults = $maxResults;

        return $this;
    }

    public function getWhereExpression(): ?Expression
    {
        return $this->where;
    }

    /**
     * @return array<string, string>
     */
    public function getOrderings(): array
    {
        return $this->orderBy;
    }

    public function getFirstResult(): int
    {
        return $this->firstResult;
    }

    public function getMaxResults(): ?int
    {
        return $this->maxResults;
    }
}
