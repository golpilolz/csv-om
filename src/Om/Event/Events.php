<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Event;

/**
 * Names of the lifecycle events. See docs/events.md for when each one fires.
 */
final class Events
{
    public const string PRE_PERSIST = 'prePersist';
    public const string POST_PERSIST = 'postPersist';
    public const string PRE_UPDATE = 'preUpdate';
    public const string POST_UPDATE = 'postUpdate';
    public const string PRE_REMOVE = 'preRemove';
    public const string POST_REMOVE = 'postRemove';
    public const string POST_LOAD = 'postLoad';
    public const string PRE_FLUSH = 'preFlush';
    public const string POST_FLUSH = 'postFlush';

    /** Events that concern one entity (they carry a {@see LifecycleEventArgs}), the only ones entity callbacks can use. */
    public const array ENTITY_EVENTS = [
        self::PRE_PERSIST, self::POST_PERSIST, self::PRE_UPDATE, self::POST_UPDATE,
        self::PRE_REMOVE, self::POST_REMOVE, self::POST_LOAD,
    ];

    public const array FLUSH_EVENTS = [self::PRE_FLUSH, self::POST_FLUSH];

    private function __construct()
    {
    }
}
