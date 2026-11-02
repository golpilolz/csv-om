<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om;

use Golpilolz\CsvOm\Exception\ConcurrentModificationException;
use Golpilolz\CsvOm\Om\ObjectManager;
use Golpilolz\CsvOm\Storage\CsvStorage;
use Golpilolz\CsvOm\Tests\CsvDirectoryTrait;
use Golpilolz\CsvOm\Tests\Fixtures\Relations\Player;
use Golpilolz\CsvOm\Tests\Fixtures\Relations\Tag;
use Golpilolz\CsvOm\Tests\Fixtures\Relations\Team;
use PHPUnit\Framework\TestCase;

final class ConcurrencyTest extends TestCase
{
    use CsvDirectoryTrait;

    protected function setUp(): void
    {
        $this->createCsvDirectory();
        $this->putCsv('teams.csv', "id,name\n1,Reds\n2,Blues\n");
        $this->putCsv('players.csv', "id,name,team_id\n1,Ann,1\n2,Bob,1\n");
        $this->putCsv('tags.csv', "code\nfun\nbig\n");
        $this->putCsv('teams_tags.csv', "team_id,tag_id\n1,fun\n");
    }

    protected function tearDown(): void
    {
        $this->removeCsvDirectory();
    }

    private function om(): ObjectManager
    {
        return new ObjectManager(new CsvStorage($this->dir));
    }

    private function file(string $name): string
    {
        return (string) file_get_contents($this->dir . '/' . $name);
    }

    public function testSecondWriterFailsInsteadOfOverwritingTheFirst(): void
    {
        $a = $this->om();
        $b = $this->om();
        $teamA = $a->find(Team::class, 1);
        $teamB = $b->find(Team::class, 2);

        $teamA->name = 'Reds!';
        $a->flush();
        $teamB->name = 'Blues!';

        try {
            $b->flush();
            self::fail('Expected a ConcurrentModificationException.');
        } catch (ConcurrentModificationException $e) {
            self::assertStringContainsString('teams.csv', $e->getMessage());
        }
        self::assertSame("id,name\n1,Reds!\n2,Blues\n", $this->file('teams.csv'));
    }

    public function testClearAndRedoSucceeds(): void
    {
        $a = $this->om();
        $b = $this->om();
        $a->find(Team::class, 1);
        $b->find(Team::class, 1)->name = 'B';
        $a->find(Team::class, 1)->name = 'A';
        $a->flush();

        try {
            $b->flush();
            self::fail('Expected a ConcurrentModificationException.');
        } catch (ConcurrentModificationException) {
        }

        $b->clear();
        $b->find(Team::class, 2)->name = 'B2';
        $b->flush();

        self::assertSame("id,name\n1,A\n2,B2\n", $this->file('teams.csv'));
    }

    public function testOwnSuccessiveFlushesDoNotConflict(): void
    {
        $om = $this->om();
        $team = $om->find(Team::class, 1);
        $team->name = 'one';
        $om->flush();
        $team->name = 'two';
        $om->flush();
        $om->persist(new Player(9, 'New', $team));
        $om->flush();
        $om->flush();

        self::assertSame("id,name\n1,two\n2,Blues\n", $this->file('teams.csv'));
        self::assertSame("id,name,team_id\n1,Ann,1\n2,Bob,1\n9,New,1\n", $this->file('players.csv'));
    }

    public function testFileCreatedByAnotherProcessAfterAMissingLoadIsNotOverwritten(): void
    {
        $a = $this->om();
        $b = $this->om();
        $a->getRepository(Team::class)->findAll();
        unlink($this->dir . '/players.csv');
        $b->getRepository(Player::class)->findAll();   // missing at load
        $a->getRepository(Player::class)->findAll();
        $a->persist(new Player(1, 'Zed'));
        $a->flush();                                    // creates players.csv

        $b->persist(new Player(2, 'Yan'));

        $this->expectException(ConcurrentModificationException::class);
        $b->flush();
    }

    public function testChangeToAnUnrelatedFileIsNotAConflict(): void
    {
        $a = $this->om();
        $b = $this->om();
        $b->find(Team::class, 1)->name = 'B';
        $b->getRepository(Player::class)->findAll();
        $a->find(Player::class, 1)->name = 'Changed';
        $a->flush();

        $b->flush();

        self::assertSame("id,name\n1,B\n2,Blues\n", $this->file('teams.csv'));
    }

    public function testConflictOnAnyFileWritesNothing(): void
    {
        $a = $this->om();
        $b = $this->om();
        $team = $b->find(Team::class, 1);
        $team->name = 'B';
        $b->find(Player::class, 1)->name = 'B-player';
        $a->find(Player::class, 2)->name = 'A-player';
        $a->flush();

        try {
            $b->flush();
            self::fail('Expected a ConcurrentModificationException.');
        } catch (ConcurrentModificationException) {
        }

        // teams.csv is written before players.csv in B's flush order or not, neither must have changed.
        self::assertSame("id,name\n1,Reds\n2,Blues\n", $this->file('teams.csv'));
        self::assertSame("id,name,team_id\n1,Ann,1\n2,A-player,1\n", $this->file('players.csv'));
    }

    public function testJoinFileConflictAndOwnSuccessiveFlushes(): void
    {
        $a = $this->om();
        $b = $this->om();
        $teamA = $a->find(Team::class, 1);
        $teamB = $b->find(Team::class, 1);
        $teamA->tags->toArray();
        $teamB->tags->toArray();

        $teamA->tags->add($a->find(Tag::class, 'big'));
        $a->flush();
        // A's own second write is not a conflict.
        $teamA->tags->remove($teamA->tags[0]);
        $a->flush();
        self::assertSame("team_id,tag_id\n1,big\n", $this->file('teams_tags.csv'));

        $teamB->tags->add($b->find(Tag::class, 'big'));
        $this->expectException(ConcurrentModificationException::class);
        $b->flush();
    }

    public function testStorageChecksTheFingerprintUnderTheLock(): void
    {
        $storage = new CsvStorage($this->dir);
        $seen = $storage->fingerprint('teams.csv');
        self::assertNotSame('', $seen);
        self::assertSame('', $storage->fingerprint('nope.csv'));

        $new = $storage->write('teams.csv', ['id', 'name'], [['id' => '1', 'name' => 'x']], null, null, $seen);
        self::assertSame($storage->fingerprint('teams.csv'), $new);
        self::assertNotSame($seen, $new);

        // The version read first is stale now: nothing is written.
        try {
            $storage->write('teams.csv', ['id', 'name'], [['id' => '1', 'name' => 'y']], null, null, $seen);
            self::fail('Expected a ConcurrentModificationException.');
        } catch (ConcurrentModificationException) {
        }
        self::assertSame("id,name\n1,x\n", $this->file('teams.csv'));

        // Two writes with the same size, in the same second, are told apart.
        $one = $storage->write('teams.csv', ['id', 'name'], [['id' => '1', 'name' => 'a']]);
        $two = $storage->write('teams.csv', ['id', 'name'], [['id' => '1', 'name' => 'b']]);
        self::assertNotSame($one, $two);
        $storage->write('teams.csv', ['id', 'name'], [['id' => '1', 'name' => 'x']]);

        // '' means "must not exist"; null skips the check.
        try {
            $storage->write('teams.csv', ['id', 'name'], [], null, null, '');
            self::fail('Expected a ConcurrentModificationException.');
        } catch (ConcurrentModificationException) {
        }
        $storage->write('teams.csv', ['id', 'name'], [['id' => '2', 'name' => 'z']]);
        self::assertSame("id,name\n2,z\n", $this->file('teams.csv'));
        self::assertSame('', $storage->fingerprint('fresh.csv'));
        $storage->write('fresh.csv', ['id'], [], null, null, '');
        self::assertFileExists($this->dir . '/fresh.csv');
    }

    public function testFileReplacedWhileItsIndexIsInUseIsDetected(): void
    {
        $storage = new CsvStorage($this->dir);
        $stream = $storage->open('teams.csv');
        self::assertCount(2, iterator_to_array($stream->records()));

        $storage->write('teams.csv', ['id', 'name'], [['id' => '9', 'name' => 'x']]);

        $this->expectException(ConcurrentModificationException::class);
        iterator_to_array($stream->records());
    }
}
