<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;
use Golpilolz\CsvOm\Om\Event\Events;

/**
 * Calls the method after the flush that wrote the new object.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class PostPersist extends LifecycleCallback
{
    public const string EVENT = Events::POST_PERSIST;
}
