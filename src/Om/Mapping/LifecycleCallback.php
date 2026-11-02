<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

/**
 * Base of the lifecycle callback attributes (#[PrePersist], #[PostLoad], ...), which mark a method of an
 * entity to call on an event. The method is non-static and takes no argument or one: the event arguments
 * ({@see \Golpilolz\CsvOm\Om\Event\LifecycleEventArgs}, a `PreUpdateEventArgs` for #[PreUpdate]).
 */
abstract class LifecycleCallback
{
    public const string EVENT = '';
}
