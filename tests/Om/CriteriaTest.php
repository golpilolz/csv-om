<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om;

use Golpilolz\CsvOm\Exception\QueryException;
use Golpilolz\CsvOm\Om\ObjectManager;
use Golpilolz\CsvOm\Om\Query\Comparison;
use Golpilolz\CsvOm\Om\Query\Criteria;
use Golpilolz\CsvOm\Om\Query\Expr;
use Golpilolz\CsvOm\Om\Query\Operator;
use Golpilolz\CsvOm\Storage\CsvStorage;
use Golpilolz\CsvOm\Tests\CsvDirectoryTrait;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Book;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\BookStatus;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Priority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CriteriaTest extends TestCase
{
    use CsvDirectoryTrait;

    // Dune 9.99 published 1965 prio 2 | Hyperion no price, draft, notes | Foundation (id 007) 7.5 1951 prio 1 | Neuromancer 12 1984
    private const string BOOKS = <<<CSV
        id;book_title;isbn;price;available;published_at;status;priority;notes;legacyCode
        10;Dune;978-0441;9.99;1;1965-08-01T00:00:00+00:00;published;2;;1
        9;Hyperion;978-0553;;0;;draft;;'a; note';2
        007;Foundation;978-0553;7.5;1;1951-06-01T00:00:00+00:00;published;1;;3
        1;Neuromancer;;12;1;1984-07-01T00:00:00+00:00;published;;;4

        CSV;

    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->createCsvDirectory();
        $this->putCsv('library/books.csv', self::BOOKS);
        $this->om = new ObjectManager(new CsvStorage($this->dir));
    }

    protected function tearDown(): void
    {
        $this->removeCsvDirectory();
    }

    /** @return list<string> titles of the matching books, in result order */
    private function titles(Criteria $criteria): array
    {
        return array_map(
            static fn (Book $b): string => (new \ReflectionProperty($b, 'title'))->getValue($b),
            $this->om->getRepository(Book::class)->matching($criteria),
        );
    }

    /**
     * @return iterable<string, array{\Golpilolz\CsvOm\Om\Query\Expression, list<string>}>
     */
    public static function expressions(): iterable
    {
        yield 'gt on float skips empty cells' => [Expr::gt('price', 9), ['Dune', 'Neuromancer']];
        yield 'gte' => [Expr::gte('price', 9.99), ['Dune', 'Neuromancer']];
        yield 'lt skips empty cells' => [Expr::lt('price', 10), ['Dune', 'Foundation']];
        yield 'lte' => [Expr::lte('price', 7.5), ['Foundation']];
        yield 'operand given as string' => [Expr::gt('price', '9'), ['Dune', 'Neuromancer']];
        yield 'between is inclusive' => [Expr::between('price', 7.5, 9.99), ['Dune', 'Foundation']];
        yield 'ids compare numerically' => [Expr::gt('id', 5), ['Dune', 'Hyperion', 'Foundation']];
        yield 'dates compare chronologically' => [Expr::gte('publishedAt', new \DateTimeImmutable('1960-01-01')), ['Dune', 'Neuromancer']];
        yield 'date operand as string' => [Expr::lt('publishedAt', '1960-01-01T00:00:00+00:00'), ['Foundation']];
        yield 'enum order by backing value' => [Expr::gt('priority', Priority::Low), ['Dune']];
        yield 'neq' => [Expr::neq('status', BookStatus::Published), ['Hyperion']];
        yield 'in' => [Expr::in('id', [1, '10']), ['Dune', 'Neuromancer']];
        yield 'notIn' => [Expr::notIn('id', [1, 10]), ['Hyperion', 'Foundation']];
        yield 'isNull' => [Expr::isNull('priority'), ['Hyperion', 'Neuromancer']];
        yield 'isNotNull' => [Expr::isNotNull('priority'), ['Dune', 'Foundation']];
        yield 'neq matches empty cells' => [Expr::neq('priority', Priority::High), ['Hyperion', 'Foundation', 'Neuromancer']];
        yield 'startsWith' => [Expr::startsWith('title', 'N'), ['Neuromancer']];
        yield 'endsWith' => [Expr::endsWith('title', 'ion'), ['Hyperion', 'Foundation']];
        yield 'contains' => [Expr::contains('title', 'une'), ['Dune']];
        yield 'contains is case sensitive' => [Expr::contains('title', 'DUNE'), []];
        yield 'text ops skip empty cells' => [Expr::contains('notes', 'note'), ['Hyperion']];
        yield 'andX' => [Expr::andX(Expr::eq('status', BookStatus::Published), Expr::gt('price', 9)), ['Dune', 'Neuromancer']];
        yield 'orX' => [Expr::orX(Expr::eq('status', 'draft'), Expr::gt('price', 10)), ['Hyperion', 'Neuromancer']];
        yield 'not' => [Expr::not(Expr::eq('status', BookStatus::Published)), ['Hyperion']];
        yield 'empty andX matches all' => [Expr::andX(), ['Dune', 'Hyperion', 'Foundation', 'Neuromancer']];
        yield 'empty orX matches none' => [Expr::orX(), []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('expressions')]
    public function testExpression(\Golpilolz\CsvOm\Om\Query\Expression $expression, array $expected): void
    {
        self::assertSame($expected, $this->titles(Criteria::create()->where($expression)));
        self::assertSame(count($expected), $this->om->getRepository(Book::class)->count($expression));
    }

    public function testEmptyCriteriaMatchesEverythingInFileOrder(): void
    {
        self::assertSame(['Dune', 'Hyperion', 'Foundation', 'Neuromancer'], $this->titles(Criteria::create()));
    }

    public function testOrderAndWindow(): void
    {
        $criteria = Criteria::create()
            ->where(Expr::isNotNull('price'))
            ->orderBy(['price' => 'DESC'])
            ->setFirstResult(1)
            ->setMaxResults(1);

        self::assertSame(['Dune'], $this->titles($criteria));
    }

    public function testAndWhereAndOrWhereCombineWithTheCurrentFilter(): void
    {
        $criteria = Criteria::create()
            ->where(Expr::eq('status', BookStatus::Published))
            ->andWhere(Expr::gt('price', 9))
            ->orWhere(Expr::eq('status', BookStatus::Draft));

        self::assertSame(['Dune', 'Hyperion', 'Neuromancer'], $this->titles($criteria));
    }

    public function testNestedExpressionsAreEvaluatedOnRowsLoadedFromTheFile(): void
    {
        $expression = Expr::andX(
            Expr::orX(Expr::startsWith('title', 'D'), Expr::startsWith('title', 'F')),
            Expr::not(Expr::lt('price', 8)),
        );

        self::assertSame(['Dune'], $this->titles(Criteria::create()->where($expression)));
    }

    /**
     * @return iterable<string, array{\Golpilolz\CsvOm\Om\Query\Expression}>
     */
    public static function invalidExpressions(): iterable
    {
        yield 'unknown field' => [Expr::gt('nope', 1)];
        yield 'unknown field in a composite' => [Expr::not(Expr::eq('nope', 1))];
        yield 'null operand for ordering' => [Expr::gt('price', null)];
        yield 'operand of the wrong type' => [Expr::gt('price', 'abc')];
        yield 'non-string operand for contains' => [new Comparison('title', Operator::CONTAINS, 1)];
        yield 'invalid enum value' => [Expr::eq('status', 'nope')];
    }

    #[DataProvider('invalidExpressions')]
    public function testInvalidExpressionThrows(\Golpilolz\CsvOm\Om\Query\Expression $expression): void
    {
        $this->expectException(QueryException::class);
        $this->om->getRepository(Book::class)->matching(Criteria::create()->where($expression));
    }

    public function testInvalidWindowThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->om->getRepository(Book::class)->matching(Criteria::create()->setFirstResult(-1));
    }
}
