<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om;

use Golpilolz\CsvOm\Exception\MappingException;
use Golpilolz\CsvOm\Exception\PersistenceException;
use Golpilolz\CsvOm\Om\Mapping as CsvOm;
use Golpilolz\CsvOm\Om\ObjectManager;
use Golpilolz\CsvOm\Storage\CsvStorage;
use Golpilolz\CsvOm\Tests\CsvDirectoryTrait;
use Golpilolz\CsvOm\Tests\Fixtures\Relations\Club;
use Golpilolz\CsvOm\Tests\Fixtures\Relations\Tag;
use PHPUnit\Framework\TestCase;

final class JoinDirectoryTest extends TestCase
{
    use CsvDirectoryTrait;

    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->createCsvDirectory();
        $this->putCsv('clubs.csv', "id\n1\n2\n");
        $this->putCsv('tags.csv', "code\nfun\nbig\npro\n");
        $this->putCsv('club_tags/a.csv', "club_id,tag_id,note\n1,fun,x\n2,fun,y\n");
        $this->putCsv('club_tags/sub/b.csv', "club_id,tag_id\n1,big\n");
        $this->om = new ObjectManager(new CsvStorage($this->dir));
    }

    protected function tearDown(): void
    {
        $this->removeCsvDirectory();
    }

    private function file(string $name): string
    {
        return (string) file_get_contents($this->dir . '/' . $name);
    }

    public function testPairsAreReadFromEveryFile(): void
    {
        $meta = $this->om->getClassMetadata(Club::class)->getAssociationMapping('tags');
        self::assertTrue($meta->joinDirectory);
        self::assertSame('club_tags', $meta->joinFile);

        $codes = array_map(static fn (Tag $t) => $t->code, $this->om->find(Club::class, 1)->tags->toArray());
        sort($codes);
        self::assertSame(['big', 'fun'], $codes);
    }

    public function testRemovedPairIsDroppedFromItsFileAndNewPairGoesToNewFile(): void
    {
        $club = $this->om->find(Club::class, 1);
        $club->tags->remove($this->om->find(Tag::class, 'big'));
        $club->tags->add($this->om->find(Tag::class, 'pro'));
        $this->om->flush();

        self::assertSame("club_id,tag_id,note\n1,fun,x\n2,fun,y\n", $this->file('club_tags/a.csv'));
        self::assertSame("club_id,tag_id\n", $this->file('club_tags/sub/b.csv'));
        self::assertSame("club_id,tag_id\n1,pro\n", $this->file('club_tags/new.csv'));

        // Reloads from disk.
        $om = new ObjectManager(new CsvStorage($this->dir));
        $codes = array_map(static fn (Tag $t) => $t->code, $om->find(Club::class, 1)->tags->toArray());
        sort($codes);
        self::assertSame(['fun', 'pro'], $codes);
    }

    public function testAddingWithoutNewFileThrows(): void
    {
        $club = $this->om->find(Club::class, 1);
        $club->labels->add($this->om->find(Tag::class, 'fun'));

        $this->expectException(PersistenceException::class);
        $this->om->flush();
    }

    public function testFileAndDirectoryAreExclusive(): void
    {
        $this->expectException(MappingException::class);
        $this->om->getClassMetadata(BadJoinClub::class);
    }
}

#[CsvOm\Entity(file: 'clubs.csv')]
class BadJoinClub
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\ManyToMany(Tag::class)]
    #[CsvOm\JoinTable(file: 'x.csv', directory: 'y')]
    public \Golpilolz\CsvOm\Om\Collection\Collection $tags;
}
