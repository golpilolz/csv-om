<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;
use Golpilolz\CsvOm\Om\Event\Events;

/**
 * Calls the method after an object has been built from its row.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class PostLoad extends LifecycleCallback
{
    public const string EVENT = Events::POST_LOAD;
}
