<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

use Attribute;
use Golpilolz\CsvOm\Om\Event\Events;

/**
 * Calls the method at flush, for a managed object that differs from its row, before the row is computed.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class PreUpdate extends LifecycleCallback
{
    public const string EVENT = Events::PRE_UPDATE;
}
