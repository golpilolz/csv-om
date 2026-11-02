<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om;

use Golpilolz\CsvOm\Exception\MappingException;
use Golpilolz\CsvOm\Exception\PersistenceException;
use Golpilolz\CsvOm\Om\Mapping as CsvOm;
use Golpilolz\CsvOm\Om\Metadata\MetadataFactory;
use Golpilolz\CsvOm\Om\ObjectManager;
use Golpilolz\CsvOm\Storage\CsvStorage;
use Golpilolz\CsvOm\Tests\CsvDirectoryTrait;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Ticket;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Token;
use PHPUnit\Framework\TestCase;

final class GeneratedIdTest extends TestCase
{
    use CsvDirectoryTrait;

    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->createCsvDirectory();
        $this->om = new ObjectManager(new CsvStorage($this->dir));
    }

    protected function tearDown(): void
    {
        $this->removeCsvDirectory();
    }

    private function ticket(string $title): Ticket
    {
        $ticket = new Ticket();
        $ticket->title = $title;

        return $ticket;
    }

    public function testIncrementStartsAtOneOnMissingFile(): void
    {
        $ticket = $this->ticket('first');
        $this->om->persist($ticket);

        self::assertSame(1, $ticket->id);
        $this->om->flush();
        self::assertSame("id,title\n1,first\n", (string) file_get_contents($this->dir . '/tickets.csv'));
    }

    public function testIncrementContinuesAfterHighestExistingId(): void
    {
        $this->putCsv('tickets.csv', "id,title\n3,a\n10,b\n4,c\n");

        $ticket = $this->ticket('d');
        $this->om->persist($ticket);

        self::assertSame(11, $ticket->id);
    }

    public function testSeveralPersistsBeforeFlushGetDistinctIds(): void
    {
        $this->putCsv('tickets.csv', "id,title\n5,a\n");
        $a = $this->ticket('a');
        $b = $this->ticket('b');
        $this->om->persist($a);
        $this->om->persist($b);
        $this->om->flush();

        self::assertSame([6, 7], [$a->id, $b->id]);
        self::assertSame('b', (new ObjectManager(new CsvStorage($this->dir)))->find(Ticket::class, 7)?->title);
        self::assertCount(3, $this->om->getRepository(Ticket::class)->findAll());
    }

    public function testRemovedButUnflushedIdIsNotReused(): void
    {
        $this->putCsv('tickets.csv', "id,title\n1,a\n2,b\n");
        $last = $this->om->find(Ticket::class, 2);
        $this->om->remove($last);

        $new = $this->ticket('c');
        $this->om->persist($new);

        self::assertSame(3, $new->id);
    }

    public function testExplicitIdIsKept(): void
    {
        $ticket = $this->ticket('x');
        $ticket->id = 42;
        $this->om->persist($ticket);

        self::assertSame(42, $ticket->id);
    }

    public function testExplicitDuplicateIdIsStillRejected(): void
    {
        $this->putCsv('tickets.csv', "id,title\n1,a\n");
        $ticket = $this->ticket('x');
        $ticket->id = 1;

        $this->expectException(PersistenceException::class);
        $this->om->persist($ticket);
    }

    public function testUuid(): void
    {
        $a = new Token();
        $b = new Token();
        $this->om->persist($a);
        $this->om->persist($b);
        $this->om->flush();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a->id);
        self::assertNotSame($a->id, $b->id);
        self::assertSame($a->id, (new ObjectManager(new CsvStorage($this->dir)))->find(Token::class, $a->id)?->id);
    }

    public function testMappingErrors(): void
    {
        $factory = new MetadataFactory();
        foreach ([
            new #[CsvOm\Entity(file: 'x.csv')] class() { #[CsvOm\Id] #[CsvOm\GeneratedValue(strategy: 'UUID')] public int $id; },
            new #[CsvOm\Entity(file: 'x.csv')] class() { #[CsvOm\Id] #[CsvOm\GeneratedValue] public string $id; },
            new #[CsvOm\Entity(file: 'x.csv')] class() { #[CsvOm\Id] #[CsvOm\GeneratedValue(strategy: 'NOPE')] public int $id; },
            new #[CsvOm\Entity(file: 'x.csv')] class() { #[CsvOm\Id] public int $id; #[CsvOm\Column] #[CsvOm\GeneratedValue] public int $n; },
        ] as $entity) {
            try {
                $factory->getMetadataFor($entity::class);
                self::fail('Expected a MappingException.');
            } catch (MappingException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
