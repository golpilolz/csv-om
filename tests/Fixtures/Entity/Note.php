<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Fixtures\Entity;

use Golpilolz\CsvOm\Om\Event\LifecycleEventArgs;
use Golpilolz\CsvOm\Om\Event\PreUpdateEventArgs;
use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity(file: 'notes.csv')]
class Note
{
    /** @var list<string> */
    public static array $log = [];

    #[CsvOm\Id]
    public int $id;

    #[CsvOm\Column]
    public string $title = '';

    #[CsvOm\Column]
    public ?string $slug = null;

    #[CsvOm\Column(name: 'created_at')]
    public ?\DateTimeImmutable $createdAt = null;

    #[CsvOm\PrePersist]
    public function stamp(): void
    {
        $this->createdAt ??= new \DateTimeImmutable('2020-01-01T00:00:00+00:00');
        self::$log[] = 'callback:prePersist';
    }

    #[CsvOm\PostPersist]
    private function persisted(LifecycleEventArgs $args): void
    {
        self::$log[] = 'callback:postPersist:' . ($args->getObject() === $this ? 'self' : 'other');
    }

    #[CsvOm\PreUpdate]
    public function slugify(PreUpdateEventArgs $args): void
    {
        $this->slug = strtolower($this->title);
        self::$log[] = 'callback:preUpdate:' . implode(',', array_keys($args->getChangeSet()));
    }

    #[CsvOm\PostUpdate]
    public function updated(): void
    {
        self::$log[] = 'callback:postUpdate';
    }

    #[CsvOm\PreRemove]
    public function removing(): void
    {
        self::$log[] = 'callback:preRemove';
    }

    #[CsvOm\PostRemove]
    public function removed(): void
    {
        self::$log[] = 'callback:postRemove';
    }

    #[CsvOm\PostLoad]
    public function loaded(): void
    {
        self::$log[] = 'callback:postLoad:' . $this->id;
    }
}
