<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om;

use Golpilolz\CsvOm\Exception\HydrationException;
use Golpilolz\CsvOm\Exception\MappingException;
use Golpilolz\CsvOm\Exception\PersistenceException;
use Golpilolz\CsvOm\Exception\QueryException;
use Golpilolz\CsvOm\Om\Collection\Collection;
use Golpilolz\CsvOm\Om\Mapping as CsvOm;
use Golpilolz\CsvOm\Om\Metadata\AssociationMapping;
use Golpilolz\CsvOm\Om\ObjectManager;
use Golpilolz\CsvOm\Storage\CsvStorage;
use Golpilolz\CsvOm\Tests\CsvDirectoryTrait;
use Golpilolz\CsvOm\Tests\Fixtures\Relations\Badge;
use Golpilolz\CsvOm\Tests\Fixtures\Relations\Coach;
use Golpilolz\CsvOm\Tests\Fixtures\Relations\Player;
use Golpilolz\CsvOm\Tests\Fixtures\Relations\Tag;
use Golpilolz\CsvOm\Tests\Fixtures\Relations\Team;
use PHPUnit\Framework\TestCase;

final class AssociationTest extends TestCase
{
    use CsvDirectoryTrait;

    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->createCsvDirectory();
        $this->putCsv('teams.csv', "id,name\n1,Reds\n2,Blues\n");
        $this->putCsv('players.csv', "id,name,team_id\n1,Ann,1\n2,Bob,1\n3,Cid,\n");
        $this->putCsv('badges.csv', "id,label,owner_id,extra\n1,Gold,2,keep\n");
        $this->putCsv('tags.csv', "code\nfun\nbig\npro\n");
        $this->putCsv('teams_tags.csv', "team_id,tag_id,note\n1,fun,a\n1,big,b\n2,fun,c\n");
        $this->putCsv('coaches.csv', "id,team_id\n1,2\n");
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

    public function testMetadata(): void
    {
        $team = $this->om->getClassMetadata(Team::class);
        self::assertSame(['id', 'name'], $team->getColumnNames());
        self::assertSame(AssociationMapping::ONE_TO_MANY, $team->getAssociationMapping('players')->type);
        $tags = $team->getAssociationMapping('tags');
        self::assertSame('teams_tags.csv', $tags->joinFile);
        self::assertSame(['team_id', 'tag_id'], [$tags->joinOwnerColumn, $tags->joinTargetColumn]);

        $player = $this->om->getClassMetadata(Player::class);
        self::assertSame(['id', 'name', 'team_id'], $player->getColumnNames());
        self::assertSame('SET NULL', $player->getAssociationMapping('team')->onDelete);
        self::assertSame('int', $player->getAssociationMapping('team')->joinMapping->type);
        self::assertSame(['id', 'label', 'owner_id'], $this->om->getClassMetadata(Badge::class)->getColumnNames());
    }

    public function testToOneAndInverseSidesAreResolvedThroughTheIdentityMap(): void
    {
        $ann = $this->om->find(Player::class, 1);
        $team = $this->om->find(Team::class, 1);

        self::assertSame($team, $ann->team);
        self::assertNull($this->om->find(Player::class, 3)->team);

        self::assertFalse($team->players->isInitialized());
        self::assertSame([1, 2], array_map(static fn (Player $p) => $p->id, $team->players->toArray()));
        self::assertSame($ann, $team->players[0]);

        $bob = $this->om->find(Player::class, 2);
        self::assertSame($bob, $this->om->find(Badge::class, 1)->player);
        self::assertSame($this->om->find(Badge::class, 1), $bob->badge);
        self::assertNull($ann->badge);
    }

    public function testManyToManyBothSides(): void
    {
        $team = $this->om->find(Team::class, 1);
        self::assertSame(['fun', 'big'], array_map(static fn (Tag $t) => $t->code, $team->tags->toArray()));

        $fun = $this->om->find(Tag::class, 'fun');
        self::assertEqualsCanonicalizing([1, 2], array_map(static fn (Team $t) => $t->id, $fun->teams->toArray()));
        self::assertTrue($fun->teams->contains($team));
        self::assertSame(0, $this->om->find(Tag::class, 'pro')->teams->count());
    }

    public function testChangingAReferenceUpdatesTheJoinColumn(): void
    {
        $this->om->find(Player::class, 1)->team = $this->om->find(Team::class, 2);
        $this->om->find(Player::class, 3)->team = $this->om->find(Team::class, 1);
        $this->om->flush();

        self::assertSame("id,name,team_id\n1,Ann,2\n2,Bob,1\n3,Cid,1\n", $this->file('players.csv'));
        self::assertSame("id,name\n1,Reds\n2,Blues\n", $this->file('teams.csv'));
    }

    public function testInsertWithCascadeAndUnmappedColumns(): void
    {
        $team = $this->om->find(Team::class, 2);
        $dan = new Player(4, 'Dan', $team);
        $dan->badge = new Badge(5, 'Silver', $dan);
        $this->om->persist($dan);
        $this->om->flush();

        self::assertSame("id,name,team_id\n1,Ann,1\n2,Bob,1\n3,Cid,\n4,Dan,2\n", $this->file('players.csv'));
        self::assertSame("id,label,owner_id,extra\n1,Gold,2,keep\n5,Silver,4,\n", $this->file('badges.csv'));
    }

    public function testManyToManyChangesAreWrittenAndUnmappedColumnsKept(): void
    {
        $team = $this->om->find(Team::class, 1);
        $team->tags->remove($team->tags[0]);                 // fun
        $team->tags->add($this->om->find(Tag::class, 'pro'));
        $this->om->flush();

        self::assertSame("team_id,tag_id,note\n1,big,b\n2,fun,c\n1,pro,\n", $this->file('teams_tags.csv'));

        // A second flush has nothing to do.
        $before = filemtime($this->dir . '/teams_tags.csv');
        $this->om->flush();
        self::assertSame($before, filemtime($this->dir . '/teams_tags.csv'));
    }

    public function testNewObjectWithNewJoinFile(): void
    {
        $this->om->persist($team = new Team(9, 'Greens'));
        $this->om->persist($tag = new Tag('new'));
        $team->tags->add($tag);
        $this->om->flush();

        self::assertSame("id,name\n1,Reds\n2,Blues\n9,Greens\n", $this->file('teams.csv'));
        self::assertSame("team_id,tag_id,note\n1,fun,a\n1,big,b\n2,fun,c\n9,new,\n", $this->file('teams_tags.csv'));
    }

    public function testInverseSideChangesAreIgnored(): void
    {
        $fun = $this->om->find(Tag::class, 'fun');
        $fun->teams->clear();
        $this->om->flush();

        self::assertSame("team_id,tag_id,note\n1,fun,a\n1,big,b\n2,fun,c\n", $this->file('teams_tags.csv'));
    }

    public function testOnDeleteSetNullAndManyToManyRows(): void
    {
        $this->om->remove($this->om->find(Team::class, 1));
        $this->om->flush();

        self::assertSame("id,name,team_id\n1,Ann,\n2,Bob,\n3,Cid,\n", $this->file('players.csv'));
        self::assertSame("team_id,tag_id,note\n2,fun,c\n", $this->file('teams_tags.csv'));
        self::assertNull($this->om->find(Player::class, 1)->team);
    }

    public function testOnDeleteCascade(): void
    {
        $this->om->remove($this->om->find(Player::class, 2));
        $this->om->flush();

        self::assertSame("id,name,team_id\n1,Ann,1\n3,Cid,\n", $this->file('players.csv'));
        self::assertSame("id,label,owner_id,extra\n", $this->file('badges.csv'));
    }

    public function testOnDeleteRestrictWritesNothing(): void
    {
        // Coach has no inverse side on Team: it is only known to the unit of work once it is in use.
        $this->om->find(Coach::class, 1);
        $this->om->remove($this->om->find(Team::class, 2));

        try {
            $this->om->flush();
            self::fail('Expected PersistenceException');
        } catch (PersistenceException $e) {
            self::assertStringContainsString('still referenced', $e->getMessage());
        }
        self::assertSame("id,name\n1,Reds\n2,Blues\n", $this->file('teams.csv'));
        self::assertSame("team_id,tag_id,note\n1,fun,a\n1,big,b\n2,fun,c\n", $this->file('teams_tags.csv'));
    }

    public function testRestrictIsLiftedWhenTheReferenceMoves(): void
    {
        $coach = $this->om->find(Coach::class, 1);
        $coach->team = $this->om->find(Team::class, 1);
        $this->om->remove($this->om->find(Team::class, 2));
        $this->om->flush();

        self::assertSame("id,team_id\n1,1\n", $this->file('coaches.csv'));
        self::assertSame("id,name\n1,Reds\n", $this->file('teams.csv'));
    }

    public function testUnmanagedTargetIsRejectedAtFlush(): void
    {
        $this->om->find(Player::class, 3)->team = new Team(7, 'Ghosts');

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('not managed');
        $this->om->flush();
    }

    public function testDanglingReferenceFails(): void
    {
        $this->putCsv('players.csv', "id,name,team_id\n1,Ann,99\n");

        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage('with id "99"');
        $this->om->find(Player::class, 1);
    }

    public function testQueryByAssociation(): void
    {
        $team = $this->om->find(Team::class, 1);
        $repository = $this->om->getRepository(Player::class);

        self::assertSame(['Ann', 'Bob'], array_map(static fn (Player $p) => $p->name, $repository->findBy(['team' => $team])));
        self::assertSame(['Ann', 'Bob'], array_map(static fn (Player $p) => $p->name, $repository->findByTeam(1)));
        self::assertSame('Cid', $repository->findOneBy(['team' => null])->name);
        self::assertSame(3, $repository->count(['team' => [$team, null]]));
        self::assertSame(['Cid', 'Ann', 'Bob'], array_map(static fn (Player $p) => $p->name, $repository->findBy([], ['team' => 'ASC', 'id' => 'ASC'])));
    }

    public function testInverseSideCannotBeQueried(): void
    {
        $this->expectException(QueryException::class);
        $this->om->getRepository(Team::class)->findBy(['players' => 1]);
    }

    public function testWrongObjectInCriteria(): void
    {
        $this->expectException(QueryException::class);
        $this->om->getRepository(Player::class)->findBy(['team' => $this->om->find(Tag::class, 'fun')]);
    }

    public function testClearForgetsJoinFiles(): void
    {
        $this->om->find(Team::class, 1)->tags->toArray();
        $this->putCsv('teams_tags.csv', "team_id,tag_id,note\n1,pro,x\n");
        $this->om->clear();

        self::assertSame(['pro'], array_map(static fn (Tag $t) => $t->code, $this->om->find(Team::class, 1)->tags->toArray()));
    }

    public function testPersistInitializesCollections(): void
    {
        $team = (new \ReflectionClass(Team::class))->newInstanceWithoutConstructor();
        $team->id = 5;
        $team->name = 'Raw';
        $this->om->persist($team);

        self::assertInstanceOf(Collection::class, $team->players);
        self::assertTrue($team->tags->isInitialized());
    }

    public function testSelfReferencingManyToMany(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('explicit joinColumn');
        $this->om->getClassMetadata(SelfRef::class);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMappings')]
    public function testInvalidMappings(string $class, string $message): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($message);
        $this->om->getClassMetadata($class);
    }

    public static function invalidMappings(): iterable
    {
        yield 'unknown mappedBy' => [BadInverse::class, 'which must be an owning #[ManyToOne]'];
        yield 'target not an entity' => [BadTarget::class, 'is not a CSV entity'];
        yield 'set null on non nullable' => [BadSetNull::class, 'SET NULL'];
        yield 'column and association' => [BadBoth::class, 'both a column'];
        yield 'collection type' => [BadType::class, 'must be typed "Golpilolz\\CsvOm\\Om\\Collection\\Collection"'];
        yield 'to-one type' => [BadToOneType::class, 'must be typed'];
    }
}

#[CsvOm\Entity(file: 'self_refs.csv')]
final class SelfRef
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\ManyToMany(self::class)]
    public Collection $friends;
}

#[CsvOm\Entity]
final class BadInverse
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\OneToMany(Player::class, mappedBy: 'nope')]
    public Collection $players;
}

#[CsvOm\Entity]
final class BadTarget
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\ManyToOne(\stdClass::class)]
    public ?\stdClass $other = null;
}

#[CsvOm\Entity]
final class BadSetNull
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\ManyToOne(Team::class)]
    #[CsvOm\JoinColumn(onDelete: 'SET NULL')]
    public Team $team;
}

#[CsvOm\Entity]
final class BadBoth
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\Column(type: 'int')]
    #[CsvOm\ManyToOne(Team::class)]
    public ?Team $team = null;
}

#[CsvOm\Entity]
final class BadType
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\ManyToMany(Tag::class)]
    public array $tags = [];
}

#[CsvOm\Entity]
final class BadToOneType
{
    #[CsvOm\Id]
    public int $id;

    #[CsvOm\ManyToOne(Team::class)]
    public ?Player $team = null;
}
