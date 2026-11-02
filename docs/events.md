# Lifecycle events

Run code when objects are loaded, persisted, updated or removed: set a creation date, keep a slug in sync,
write an audit trail. There are two ways to hook in, and both receive the same events.

| Event | Fires | Argument |
|---|---|---|
| `prePersist` | in `persist()`, for a new object, **before** its id is read (so it can set the id, a date, ...) | `LifecycleEventArgs` |
| `postPersist` | after `flush()` has written the new object | `LifecycleEventArgs` |
| `preUpdate` | in `flush()`, for a managed object that differs from its row, before the row is computed | `PreUpdateEventArgs` |
| `postUpdate` | after `flush()` has written the changed object | `LifecycleEventArgs` |
| `preRemove` | in `remove()` (or when a `CASCADE` removal picks the object), for a managed object | `LifecycleEventArgs` |
| `postRemove` | after `flush()` has removed the object | `LifecycleEventArgs` |
| `postLoad` | once, when an object is built from its row (not for an object already in the identity map) | `LifecycleEventArgs` |
| `preFlush` | start of `flush()`, before anything is computed: objects persisted here are written by this flush | `FlushEventArgs` |
| `postFlush` | end of `flush()`, after the `post*` events | `FlushEventArgs` |

The constants are in `Golpilolz\CsvOm\Om\Event\Events`.

## Entity callbacks

Mark a method of the entity. It may be private, takes no argument or one (the event arguments), and the same
method can carry several attributes. Inherited methods count.

```php
use Golpilolz\CsvOm\Om\Mapping as CsvOm;

#[CsvOm\Entity]
class Article
{
    #[CsvOm\Id] #[CsvOm\GeneratedValue] public int $id;
    #[CsvOm\Column] public string $title = '';
    #[CsvOm\Column] public ?string $slug = null;
    #[CsvOm\Column] public ?\DateTimeImmutable $createdAt = null;

    #[CsvOm\PrePersist]
    public function stamp(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    #[CsvOm\PreUpdate]
    public function slugify(PreUpdateEventArgs $args): void
    {
        if ($args->hasChangedField('title')) {
            $this->slug = strtolower(str_replace(' ', '-', $this->title));
        }
    }
}
```

Attributes: `#[PrePersist]`, `#[PostPersist]`, `#[PreUpdate]`, `#[PostUpdate]`, `#[PreRemove]`, `#[PostRemove]`,
`#[PostLoad]`. A static or abstract method, or one with more than one parameter, is a `MappingException`.

## Listeners

A listener is any object with a method per event. In a Symfony app, mark a service class or method with
`#[AsListener]` (autoconfigured), or tag it by hand:

```php
use Golpilolz\CsvOm\Om\Event\AsListener;
use Golpilolz\CsvOm\Om\Event\Events;
use Golpilolz\CsvOm\Om\Event\LifecycleEventArgs;

final class AuditListener
{
    public function __construct(private readonly AuditLog $log)
    {
    }

    #[AsListener(event: Events::POST_PERSIST, entity: Article::class)]
    #[AsListener(event: Events::POST_REMOVE, entity: Article::class, priority: 10)]
    public function audit(LifecycleEventArgs $args): void
    {
        $this->log->record($args->getObject());
    }
}
```

```yaml
services:
    App\Audit\AuditListener:
        tags:
            - { name: golpilolz_csv_om.event_listener, event: postPersist, method: audit, entity: App\Entity\Article, priority: 10 }
```

| Option | Default | |
|---|---|---|
| `event` | required | one of the events above |
| `method` | the event name (`postLoad()` for `postLoad`) | set automatically on a method-level `#[AsListener]` |
| `entity` | all entities | only call it for objects of this class (or subclass); entity events only |
| `priority` | `0` | higher runs first; equal priorities keep registration order |

Listeners are resolved lazily, so a listener may depend on the object manager. Without Symfony, register them
yourself: `$om->getEventManager()->addListener(Events::POST_PERSIST, $listener, 'audit', Article::class)`.
For one event, entity callbacks run first, then listeners.

## `preUpdate` and the change set

`PreUpdateEventArgs` carries the scalar fields that differ from the file, as `field => [old, new]` with typed
values (`getChangeSet()`, `hasChangedField()`, `getOldValue()`, `getNewValue()`). Changes to associations are
not in the change set. The callback **may modify the object**: the row is computed after the event, so the new
values are written (if they end up equal to the file again, nothing is written). Don't `persist()` or
`remove()` from `preUpdate`.

## What to expect

- **`post*` means "written", not "committed".** Files are written one by one (see
  [Limitations](limitations.md)). The `post*` events and `postFlush` fire after every file has been written; if
  a write fails with an exception, none of them fires, and the files already written stay written.
- **`pre*` events can fire for a flush that is then refused.** `preUpdate` runs while the flush is being
  computed, and validation errors are raised afterwards (a nothing-is-written guarantee still holds).
- **Only the owning object is reported.** A change to a many-to-many collection alone does not trigger
  `preUpdate`/`postUpdate` (join rows are not entities). Removing a row through an `onDelete: 'SET NULL'` rule
  changes the referencing object, which is then updated (and reported) like any other change.
- **`postLoad` can see half-resolved relations** when the object is loaded while resolving another object's
  associations (cycles end on the object already in the identity map). To-many collections are lazy anyway.
- **`clear()`** fires nothing.
