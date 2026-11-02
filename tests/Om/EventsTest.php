<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om;

use Golpilolz\CsvOm\Exception\MappingException;
use Golpilolz\CsvOm\Om\Event\EventManager;
use Golpilolz\CsvOm\Om\Event\Events;
use Golpilolz\CsvOm\Om\Event\FlushEventArgs;
use Golpilolz\CsvOm\Om\Event\LifecycleEventArgs;
use Golpilolz\CsvOm\Om\Event\PreUpdateEventArgs;
use Golpilolz\CsvOm\Om\Mapping as CsvOm;
use Golpilolz\CsvOm\Om\Metadata\MetadataFactory;
use Golpilolz\CsvOm\Om\ObjectManager;
use Golpilolz\CsvOm\Storage\CsvStorage;
use Golpilolz\CsvOm\Tests\CsvDirectoryTrait;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Note;
use Golpilolz\CsvOm\Tests\Fixtures\Relations\Badge;
use Golpilolz\CsvOm\Tests\Fixtures\Relations\Player;
use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    use CsvDirectoryTrait;

    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->createCsvDirectory();
        $this->putCsv('notes.csv', "id,title,slug,created_at\n1,Hello,hello,2019-05-05T00:00:00+00:00\n2,World,world,2019-05-05T00:00:00+00:00\n");
        $this->om = new ObjectManager(new CsvStorage($this->dir));
        Note::$log = [];
    }

    protected function tearDown(): void
    {
        $this->removeCsvDirectory();
    }

    private function notes(): string
    {
        return (string) file_get_contents($this->dir . '/notes.csv');
    }

    private function note(int $id, string $title): Note
    {
        $note = new Note();
        $note->id = $id;
        $note->title = $title;

        return $note;
    }

    /**
     * Listener that records every event it receives, with the content of the file at that moment.
     */
    private function recorder(): object
    {
        $recorder = new class($this->dir) {
            /** @var list<string> */
            public array $log = [];

            public function __construct(private readonly string $dir)
            {
            }

            public function __call(string $event, array $args): void
            {
                $file = (string) file_get_contents($this->dir . '/notes.csv');
                $this->log[] = $event . ':' . substr_count($file, "\n");
            }
        };
        foreach ([...Events::ENTITY_EVENTS, ...Events::FLUSH_EVENTS] as $event) {
            $this->om->getEventManager()->addListener($event, $recorder);
        }

        return $recorder;
    }

    public function testPersistFiresPrePersistBeforeTheIdIsReadAndPostPersistAfterTheWrite(): void
    {
        $recorder = $this->recorder();
        $note = $this->note(3, 'Third');

        $this->om->persist($note);
        self::assertSame(['callback:prePersist'], Note::$log);
        self::assertNotNull($note->createdAt);
        self::assertSame(['prePersist:3'], $recorder->log);

        $this->om->flush();

        self::assertSame(['callback:prePersist', 'callback:postPersist:self'], Note::$log);
        // 3 lines (header + 2 rows) before the write, 4 after it.
        self::assertSame(['prePersist:3', 'preFlush:3', 'postPersist:4', 'postFlush:4'], $recorder->log);
        self::assertStringContainsString('3,Third,,2020-01-01T00:00:00+00:00', $this->notes());
    }

    public function testPrePersistCanSetAGeneratedLookingId(): void
    {
        $this->om->getEventManager()->addListener(Events::PRE_PERSIST, new class() {
            public function prePersist(LifecycleEventArgs $args): void
            {
                $args->getObject()->id = 99;
            }
        });
        $note = new Note();
        $note->title = 'No id';
        $this->om->persist($note);
        $this->om->flush();

        self::assertSame('No id', (new ObjectManager(new CsvStorage($this->dir)))->find(Note::class, 99)?->title);
    }

    public function testPreUpdateGetsTheChangeSetAndItsChangesAreWritten(): void
    {
        $note = $this->om->find(Note::class, 1);
        Note::$log = [];
        $note->title = 'Hello again';
        $this->om->flush();

        self::assertSame(['callback:preUpdate:title', 'callback:postUpdate'], Note::$log);
        // The slug set by the callback is part of the row.
        $reloaded = (new ObjectManager(new CsvStorage($this->dir)))->find(Note::class, 1);
        self::assertSame(['Hello again', 'hello again'], [$reloaded->title, $reloaded->slug]);
    }

    public function testPreUpdateArguments(): void
    {
        $seen = null;
        $this->om->getEventManager()->addListener(Events::PRE_UPDATE, new class($seen) {
            public function __construct(private mixed &$seen)
            {
            }

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                $this->seen = [$args->getChangeSet(), $args->hasChangedField('title'), $args->getOldValue('title'), $args->getNewValue('title'), $args->hasChangedField('slug')];
            }
        });
        $note = $this->om->find(Note::class, 2);
        $note->title = 'Earth';
        $this->om->flush();

        self::assertSame([['title' => ['World', 'Earth']], true, 'World', 'Earth', false], $seen);
    }

    public function testNoUpdateEventForAnUnchangedObject(): void
    {
        $this->om->find(Note::class, 1);
        Note::$log = [];
        $this->om->flush();

        self::assertSame([], Note::$log);
    }

    public function testRemoveFiresPreRemoveNowAndPostRemoveAfterTheWrite(): void
    {
        $note = $this->om->find(Note::class, 2);
        Note::$log = [];

        $this->om->remove($note);
        self::assertSame(['callback:preRemove'], Note::$log);

        $this->om->flush();
        self::assertSame(['callback:preRemove', 'callback:postRemove'], Note::$log);
        self::assertStringNotContainsString('World', $this->notes());
    }

    public function testRemovingANeverFlushedObjectFiresNoRemoveEvent(): void
    {
        $note = $this->note(3, 'Third');
        $this->om->persist($note);
        Note::$log = [];
        $this->om->remove($note);
        $this->om->flush();

        self::assertSame([], Note::$log);
    }

    public function testCascadedRemovalFiresPreAndPostRemoveForTheReferencingObject(): void
    {
        $this->putCsv('players.csv', "id,name,team_id\n2,Bob,\n");
        $this->putCsv('badges.csv', "id,label,owner_id\n1,Gold,2\n");
        $log = [];
        $listener = new class($log) {
            public function __construct(private array &$log)
            {
            }

            public function __call(string $event, array $args): void
            {
                $this->log[] = $event . ':' . $args[0]->getObject()::class;
            }
        };
        $this->om->getEventManager()->addListener(Events::PRE_REMOVE, $listener);
        $this->om->getEventManager()->addListener(Events::POST_REMOVE, $listener);

        $this->om->remove($this->om->find(Player::class, 2));
        $this->om->flush();

        self::assertSame([
            'preRemove:' . Player::class,
            'preRemove:' . Badge::class,
            'postRemove:' . Player::class,
            'postRemove:' . Badge::class,
        ], $log);
    }

    public function testPostLoadFiresOncePerBuiltObject(): void
    {
        $this->om->find(Note::class, 1);
        $this->om->find(Note::class, 1);
        self::assertSame(['callback:postLoad:1'], Note::$log);

        $this->om->getRepository(Note::class)->findAll();
        self::assertSame(['callback:postLoad:1', 'callback:postLoad:2'], Note::$log);
    }

    public function testPreFlushListenerCanPersistObjectsWrittenByTheSameFlush(): void
    {
        $this->om->getEventManager()->addListener(Events::PRE_FLUSH, new class() {
            public function preFlush(FlushEventArgs $args): void
            {
                $note = new Note();
                $note->id = 7;
                $note->title = 'Added by listener';
                $args->getObjectManager()->persist($note);
            }
        });
        $this->om->flush();

        self::assertNotNull((new ObjectManager(new CsvStorage($this->dir)))->find(Note::class, 7));
    }

    public function testListenersRunByPriorityThenRegistrationOrderAndCanFilterByEntity(): void
    {
        $listener = new class() {
            public array $order = [];

            public function __call(string $method, array $args): void
            {
                $this->order[] = $method;
            }
        };
        $events = new EventManager();
        $events->addListener(Events::POST_LOAD, $listener, 'low', priority: -5);
        $events->addListener(Events::POST_LOAD, $listener, 'first');
        $events->addListener(Events::POST_LOAD, $listener, 'high', priority: 10);
        $events->addListener(Events::POST_LOAD, $listener, 'second');
        $events->addListener(Events::POST_LOAD, $listener, 'other', entity: \stdClass::class);
        $events->addListener(Events::POST_LOAD, $listener, 'note', entity: Note::class);

        $events->dispatch(Events::POST_LOAD, new LifecycleEventArgs(new Note(), $this->om));

        self::assertSame(['high', 'first', 'second', 'note', 'low'], $listener->order);
    }

    public function testEventsAreRunAfterTheirEntityCallbacks(): void
    {
        $this->om->getEventManager()->addListener(Events::PRE_PERSIST, new class() {
            public function prePersist(): void
            {
                Note::$log[] = 'listener:prePersist';
            }
        });
        $this->om->persist($this->note(3, 'x'));

        self::assertSame(['callback:prePersist', 'listener:prePersist'], Note::$log);
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testInvalidListenerRegistrationIsRejected(): void
    {
        $events = new EventManager();
        $listener = new class() {
            public function ok(): void
            {
            }
        };

        foreach ([
            fn () => $events->addListener('nope', $listener, 'ok'),
            fn () => $events->addListener(Events::PRE_FLUSH, $listener, 'ok', Note::class),
            fn () => $events->addListener(Events::POST_LOAD, $listener, 'missing'),
        ] as $register) {
            try {
                $register();
                self::fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testInvalidCallbackMappingsAreRejected(): void
    {
        $factory = new MetadataFactory();
        foreach ([
            new #[CsvOm\Entity(file: 'x.csv')] class() {
                #[CsvOm\Id] public int $id;

                #[CsvOm\PrePersist] public static function nope(): void
                {
                }
            },
            new #[CsvOm\Entity(file: 'x.csv')] class() {
                #[CsvOm\Id] public int $id;

                #[CsvOm\PostLoad] public function nope(int $a, int $b): void
                {
                }
            },
        ] as $entity) {
            try {
                $factory->getMetadataFor($entity::class);
                self::fail('Expected a MappingException.');
            } catch (MappingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCallbackMetadata(): void
    {
        $metadata = (new MetadataFactory())->getMetadataFor(Note::class);

        self::assertSame(['stamp'], $metadata->lifecycleCallbacks[Events::PRE_PERSIST]);
        self::assertSame(['loaded'], $metadata->lifecycleCallbacks[Events::POST_LOAD]);
    }
}
