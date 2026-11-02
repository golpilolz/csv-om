<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;
use Golpilolz\CsvOm\Om\Event\Events;

/**
 * Calls the method before a new object is scheduled: set defaults (dates, id) here.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class PrePersist extends LifecycleCallback
{
    public const string EVENT = Events::PRE_PERSIST;
}
