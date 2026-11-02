<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om;

use Golpilolz\CsvOm\Exception\ConcurrentModificationException;
use Golpilolz\CsvOm\Exception\MappingException;
use Golpilolz\CsvOm\Exception\PersistenceException;
use Golpilolz\CsvOm\Exception\StorageException;
use Golpilolz\CsvOm\Om\Mapping as CsvOm;
use Golpilolz\CsvOm\Om\Metadata\MetadataFactory;
use Golpilolz\CsvOm\Om\ObjectManager;
use Golpilolz\CsvOm\Storage\CsvStorage;
use Golpilolz\CsvOm\Tests\CsvDirectoryTrait;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\FlatMember;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Member;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DirectoryEntityTest extends TestCase
{
    use CsvDirectoryTrait;

    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->createCsvDirectory();
        $this->putCsv('members/a.csv', "id,name\n1,Ann\n2,Bob\n");
        $this->putCsv('members/eu/b.csv', "name,id,note\nCid,3,x\n");       // other column order, unmapped column
        $this->putCsv('members/eu/fr/c.csv', "id,name\n4,Dan\n");
        $this->putCsv('members/z.CSV', "id,name\n5,Eve\n");                  // extension is case-insensitive
        $this->putCsv('members/readme.txt', "not a csv\n");
        $this->putCsv('members/.hidden.csv', "id,name\n99,Hidden\n");
        $this->putCsv('members/.git/x.csv', "id,name\n98,Hidden\n");
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

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!str_ends_with($f->getFilename(), '.lock')) {
                $files[substr($f->getPathname(), strlen($this->dir) + 1)] = md5((string) file_get_contents($f->getPathname()));
            }
        }
        ksort($files);

        return $files;
    }

    /** @param list<Member> $members */
    private static function names(array $members): array
    {
        return array_map(static fn (Member $m): string => $m->name, $members);
    }

    private function member(int $id, string $name, ?string $source = null): Member
    {
        $member = new Member();
        $member->id = $id;
        $member->name = $name;
        $member->source = $source;

        return $member;
    }

    public function testEveryCsvFileOfTheDirectoryAndItsSubdirectoriesIsRead(): void
    {
        $members = $this->om->getRepository(Member::class)->findAll();

        self::assertSame(['Ann', 'Bob', 'Cid', 'Dan', 'Eve'], self::names($members));
        self::assertSame(['a.csv', 'a.csv', 'eu/b.csv', 'eu/fr/c.csv', 'z.CSV'], array_map(static fn (Member $m) => $m->source, $members));
        self::assertSame(5, $this->om->getRepository(Member::class)->count());
    }

    public function testFindAndCriteriaWorkAcrossFiles(): void
    {
        $repository = $this->om->getRepository(Member::class);

        self::assertSame('Cid', $this->om->find(Member::class, 3)?->name);
        self::assertNull($this->om->find(Member::class, 99));
        self::assertSame(['Dan', 'Eve'], self::names($repository->findBy(['id' => [4, 5]])));
        self::assertSame(['Eve', 'Dan', 'Cid'], self::names($repository->findBy([], ['name' => 'DESC'], 3)));
    }

    public function testRowsWithADifferentHeaderOrderAreReadByColumnName(): void
    {
        self::assertSame('Cid', $this->om->find(Member::class, 3)?->name);
        self::assertSame(3, $this->om->find(Member::class, 3)?->id);
    }

    public function testNonRecursiveDirectoryIgnoresSubdirectories(): void
    {
        $this->putCsv('flat/x.csv', "id,name\n1,X\n");
        $this->putCsv('flat/sub/y.csv', "id,name\n2,Y\n");

        $members = $this->om->getRepository(FlatMember::class)->findAll();

        self::assertSame(['X'], array_map(static fn (FlatMember $m) => $m->name, $members));
    }

    public function testMissingDirectoryIsAnEmptyTableAndIsCreatedOnWrite(): void
    {
        self::assertSame([], $this->om->getRepository(FlatMember::class)->findAll());

        $member = new FlatMember();
        $member->id = 1;
        $member->name = 'First';
        $member->source = 'one.csv';
        $this->om->persist($member);
        $this->om->flush();

        self::assertSame("id,name\n1,First\n", $this->file('flat/one.csv'));
    }

    public function testUpdateRewritesOnlyTheFileOfTheRow(): void
    {
        $before = $this->snapshot();
        $this->om->find(Member::class, 3)->name = 'Cédric';
        $this->om->flush();

        $after = $this->snapshot();
        self::assertSame("name,id,note\nCédric,3,x\n", $this->file('members/eu/b.csv'));
        self::assertSame(['members/eu/b.csv'], array_keys(array_diff_assoc($after, $before)));
    }

    public function testRemoveRewritesOnlyTheFileOfTheRow(): void
    {
        $before = $this->snapshot();
        $this->om->remove($this->om->find(Member::class, 2));
        $this->om->flush();

        self::assertSame("id,name\n1,Ann\n", $this->file('members/a.csv'));
        self::assertSame(['members/a.csv'], array_keys(array_diff_assoc($this->snapshot(), $before)));
        self::assertNull((new ObjectManager(new CsvStorage($this->dir)))->find(Member::class, 2));
    }

    public function testNewObjectGoesToTheNewFileOfTheEntity(): void
    {
        $member = $this->member(6, 'Fay');
        $this->om->persist($member);
        $this->om->flush();

        self::assertSame('inbox.csv', $member->source);
        self::assertSame("id,name\n6,Fay\n", $this->file('members/inbox.csv'));
        self::assertSame('inbox.csv', (new ObjectManager(new CsvStorage($this->dir)))->find(Member::class, 6)?->source);
    }

    public function testNewObjectGoesToItsSourceFile(): void
    {
        $this->om->persist($this->member(6, 'Fay', 'a.csv'));                // existing file: appended
        $this->om->persist($this->member(7, 'Gus', 'eu/b.csv'));             // existing file with an unmapped column
        $this->om->persist($this->member(8, 'Hal', 'new/deep/d.csv'));       // directories are created
        $this->om->flush();

        self::assertSame("id,name\n1,Ann\n2,Bob\n6,Fay\n", $this->file('members/a.csv'));
        self::assertSame("name,id,note\nCid,3,x\nGus,7,\n", $this->file('members/eu/b.csv'));
        self::assertSame("id,name\n8,Hal\n", $this->file('members/new/deep/d.csv'));
    }

    public function testSeveralFilesAreWrittenByOneFlushAndReadBack(): void
    {
        $this->om->find(Member::class, 1)->name = 'Ann2';
        $this->om->find(Member::class, 4)->name = 'Dan2';
        $this->om->remove($this->om->find(Member::class, 5));
        $this->om->persist($this->member(6, 'Fay', 'eu/fr/c.csv'));
        $this->om->flush();
        $this->om->flush();

        $other = new ObjectManager(new CsvStorage($this->dir));
        self::assertSame(['Ann2', 'Bob', 'Cid', 'Dan2', 'Fay'], self::names($other->getRepository(Member::class)->findAll()));
        // The same object manager keeps working after its files were rewritten.
        self::assertSame('Fay', $this->om->find(Member::class, 6)?->name);
    }

    public function testIdsAreUniqueAcrossFiles(): void
    {
        $this->expectException(PersistenceException::class);
        $this->om->persist($this->member(3, 'Dup', 'a.csv'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSources(): iterable
    {
        yield 'parent directory' => ['../x.csv'];
        yield 'absolute' => ['/x.csv'];
        yield 'not a csv' => ['x.txt'];
        yield 'hidden file' => ['.x.csv'];
        yield 'hidden directory' => ['.d/x.csv'];
        yield 'backslash' => ['a\\x.csv'];
        yield 'empty segment' => ['a//x.csv'];
    }

    #[DataProvider('invalidSources')]
    public function testInvalidSourceFileIsRejected(string $source): void
    {
        $this->expectException(PersistenceException::class);
        $this->om->persist($this->member(6, 'Bad', $source));
    }

    public function testObjectWithoutAnyFileCannotBePersisted(): void
    {
        $member = new FlatMember();
        $member->id = 1;

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('no file');
        $this->om->persist($member);
    }

    public function testNonRecursiveEntityRejectsSubdirectoryTargets(): void
    {
        $member = new FlatMember();
        $member->id = 1;
        $member->source = 'sub/x.csv';

        $this->expectException(PersistenceException::class);
        $this->om->persist($member);
    }

    public function testSourceFileOfAManagedObjectCannotBeChanged(): void
    {
        $member = $this->om->find(Member::class, 1);
        $member->name = 'Moved';
        $member->source = 'eu/b.csv';

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('cannot be moved');
        $this->om->flush();
    }

    public function testDuplicateIdAcrossFilesIsReportedWithBothFiles(): void
    {
        $this->putCsv('members/eu/dup.csv', "id,name\n2,Again\n");

        try {
            $this->om->getRepository(Member::class)->findAll();
            self::fail('Expected a StorageException.');
        } catch (StorageException $e) {
            self::assertStringContainsString('members/eu/dup.csv', $e->getMessage());
            self::assertStringContainsString('members/a.csv', $e->getMessage());
        }
    }

    public function testFileMissingAMappedColumnIsReportedByName(): void
    {
        $this->putCsv('members/bad.csv', "id\n9\n");

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('members/bad.csv');
        $this->om->getRepository(Member::class)->findAll();
    }

    public function testFileCreatedAfterTheLoadIsAConflict(): void
    {
        $this->om->find(Member::class, 1)->name = 'Changed';
        $this->putCsv('members/late.csv', "id,name\n7,Late\n");

        $this->expectException(ConcurrentModificationException::class);
        $this->om->flush();
    }

    public function testFileChangedAfterTheLoadIsAConflictAndNothingIsWritten(): void
    {
        $other = new ObjectManager(new CsvStorage($this->dir));
        $other->find(Member::class, 4)->name = 'Other';
        $this->om->find(Member::class, 4);
        $this->om->find(Member::class, 1)->name = 'Mine';
        $this->om->find(Member::class, 4)->name = 'Mine too';
        $other->flush();
        $before = $this->snapshot();

        try {
            $this->om->flush();
            self::fail('Expected a ConcurrentModificationException.');
        } catch (ConcurrentModificationException) {
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testNewFileOfThePlanAppearingMeanwhileIsAConflict(): void
    {
        $this->om->persist($this->member(6, 'Fay'));                       // goes to inbox.csv, created at flush
        $this->om->getRepository(Member::class)->findAll();
        $this->putCsv('members/inbox.csv', "id,name\n50,Other\n");           // someone else creates it first

        $this->expectException(ConcurrentModificationException::class);
        $this->om->flush();
    }

    public function testMetadata(): void
    {
        $metadata = (new MetadataFactory())->getMetadataFor(FlatMember::class);

        self::assertTrue($metadata->isDirectory());
        self::assertSame('flat', $metadata->directory);
        self::assertFalse($metadata->recursive);
        self::assertNull($metadata->newFile);
        self::assertSame('source', $metadata->sourceFileField);
        self::assertSame('inbox.csv', (new MetadataFactory())->getMetadataFor(Member::class)->newFile);
        self::assertSame(['id', 'name'], $metadata->getColumnNames());
    }

    public function testInvalidDirectoryMappingsAreRejected(): void
    {
        $factory = new MetadataFactory();
        foreach ([
            new #[CsvOm\Entity(file: 'a.csv', directory: 'd')] class() { #[CsvOm\Id] public int $id; },
            new #[CsvOm\Entity(file: 'a.csv', recursive: false)] class() { #[CsvOm\Id] public int $id; },
            new #[CsvOm\Entity(file: 'a.csv', newFile: 'n.csv')] class() { #[CsvOm\Id] public int $id; },
            new #[CsvOm\Entity(directory: 'd', newFile: '../n.csv')] class() { #[CsvOm\Id] public int $id; },
            new #[CsvOm\Entity(directory: 'd', newFile: 'n.txt')] class() { #[CsvOm\Id] public int $id; },
            new #[CsvOm\Entity(directory: '../d')] class() { #[CsvOm\Id] public int $id; },
            new #[CsvOm\Entity(directory: 'd', recursive: false, newFile: 'sub/n.csv')] class() { #[CsvOm\Id] public int $id; },
            new #[CsvOm\Entity(file: 'a.csv')] class() { #[CsvOm\Id] public int $id; #[CsvOm\SourceFile] public ?string $s = null; },
            new #[CsvOm\Entity(directory: 'd')] class() { #[CsvOm\Id] public int $id; #[CsvOm\SourceFile] public ?int $s = null; },
            new #[CsvOm\Entity(directory: 'd')] class() { #[CsvOm\Id] public int $id; #[CsvOm\SourceFile] public ?string $a = null; #[CsvOm\SourceFile] public ?string $b = null; },
            new #[CsvOm\Entity(directory: 'd')] class() { #[CsvOm\Id] public int $id; #[CsvOm\SourceFile] #[CsvOm\Column] public ?string $s = null; },
        ] as $index => $entity) {
            try {
                $factory->getMetadataFor($entity::class);
                self::fail(sprintf('Expected a MappingException for case #%d.', $index));
            } catch (MappingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStorageListsCsvFilesSorted(): void
    {
        $storage = new CsvStorage($this->dir);

        self::assertSame(
            ['members/a.csv', 'members/eu/b.csv', 'members/eu/fr/c.csv', 'members/z.CSV'],
            $storage->listFiles('members'),
        );
        self::assertSame(['members/a.csv', 'members/z.CSV'], $storage->listFiles('members/', false));
        self::assertSame([], $storage->listFiles('nope'));
    }

    public function testSymbolicLinksAreNotFollowed(): void
    {
        if (!@symlink($this->dir . '/members/eu', $this->dir . '/members/link')) {
            self::markTestSkipped('Symbolic links are not available.');
        }

        try {
            self::assertNotContains('members/link/b.csv', (new CsvStorage($this->dir))->listFiles('members'));
        } finally {
            unlink($this->dir . '/members/link');
        }
    }
}
