<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\DependencyInjection;

use Golpilolz\CsvOm\GolpilolzCsvOmBundle;
use Golpilolz\CsvOm\Om\Event\AsListener;
use Golpilolz\CsvOm\Om\Event\Events;
use Golpilolz\CsvOm\Om\Event\FlushEventArgs;
use Golpilolz\CsvOm\Om\Event\LifecycleEventArgs;
use Golpilolz\CsvOm\Om\ObjectManagerInterface;
use Golpilolz\CsvOm\Tests\CsvDirectoryTrait;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Note;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

#[AsListener(event: Events::POST_FLUSH, priority: -10)]
final class ClassLevelListener
{
    /** @var list<string> */
    public static array $log = [];

    public function postFlush(FlushEventArgs $args): void
    {
        self::$log[] = 'class:postFlush';
    }

    #[AsListener(event: Events::POST_PERSIST, entity: Note::class)]
    public function onNote(LifecycleEventArgs $args): void
    {
        self::$log[] = 'method:' . $args->getObject()::class;
    }
}

final readonly class TaggedListener
{
    public function __construct(private ObjectManagerInterface $om)
    {
    }

    public function om(): ObjectManagerInterface
    {
        return $this->om;
    }

    public function preFlush(): void
    {
        ClassLevelListener::$log[] = 'tagged:preFlush';
    }
}

final class EventListenerTest extends TestCase
{
    use CsvDirectoryTrait;

    protected function setUp(): void
    {
        $this->createCsvDirectory();
        mkdir($this->dir . '/csv');
        ClassLevelListener::$log = [];
    }

    protected function tearDown(): void
    {
        $this->removeCsvDirectory();
    }

    private function container(callable $register): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->dir);
        $bundle = new GolpilolzCsvOmBundle();
        $container->registerExtension($bundle->getContainerExtension());
        $container->loadFromExtension('golpilolz_csv_om', []);
        $bundle->build($container);
        $register($container);
        $container->setAlias('test.object_manager', ObjectManagerInterface::class)->setPublic(true);
        $container->compile();

        return $container;
    }

    public function testAttributeAndTaggedListenersAreRegistered(): void
    {
        $container = $this->container(static function (ContainerBuilder $container): void {
            $container->register(ClassLevelListener::class)->setAutoconfigured(true);
            $container->register(TaggedListener::class)
                ->setAutowired(true)
                ->addTag('golpilolz_csv_om.event_listener', ['event' => Events::PRE_FLUSH]);
        });

        $om = $container->get('test.object_manager');
        $note = new Note();
        $note->id = 1;
        $om->persist($note);
        $om->flush();

        self::assertSame(['tagged:preFlush', 'method:' . Note::class, 'class:postFlush'], ClassLevelListener::$log);
    }

    public function testUnknownEventFailsAtCompileTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->container(static function (ContainerBuilder $container): void {
            $container->register(TaggedListener::class)
                ->setAutowired(true)
                ->addTag('golpilolz_csv_om.event_listener', ['event' => 'nope']);
        });
    }

    public function testTagWithoutEventFailsAtCompileTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->container(static function (ContainerBuilder $container): void {
            $container->register(TaggedListener::class)->setAutowired(true)->addTag('golpilolz_csv_om.event_listener');
        });
    }
}
