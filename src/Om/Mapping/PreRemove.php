<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;
use Golpilolz\CsvOm\Om\Event\Events;

/**
 * Calls the method when a managed object is scheduled for removal.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class PreRemove extends LifecycleCallback
{
    public const string EVENT = Events::PRE_REMOVE;
}
