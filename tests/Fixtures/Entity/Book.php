<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity(file: 'library/books.csv', repositoryClass: BookRepository::class, delimiter: ';', enclosure: "'")]
final class Book
{
    #[CsvOm\Id]
    private int $id;

    #[CsvOm\Column(name: 'book_title')]
    private string $title;

    #[CsvOm\Column]
    private ?float $price = null;

    #[CsvOm\Column]
    private bool $available = true;

    #[CsvOm\Column(name: 'published_at')]
    private ?\DateTimeImmutable $publishedAt = null;

    #[CsvOm\Column]
    private BookStatus $status = BookStatus::Draft;

    #[CsvOm\Column]
    private ?Priority $priority = null;

    /** @var mixed Untyped: mapped as nullable string. */
    #[CsvOm\Column]
    public $notes;

    #[CsvOm\Column(type: 'int', nullable: false)]
    public int|string $legacyCode = 0;

    /** Not mapped. */
    public string $computed = '';

    private function __construct()
    {
    }
}
