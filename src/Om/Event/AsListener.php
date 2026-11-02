<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Event;

use Attribute;

/**
 * Registers a service (class level) or one of its methods as a listener of a lifecycle event. Same as tagging
 * the service with `golpilolz_csv_om.event_listener`. Without a method, the method named like the event is
 * called (`postLoad()` for `postLoad`).
 *
 * @see Events
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class AsListener
{
    /**
     * @param class-string|null $entity only call the listener for objects of this class (entity events only)
     * @param int $priority higher runs first
     */
    public function __construct(
        public string $event,
        public ?string $method = null,
        public ?string $entity = null,
        public int $priority = 0,
    ) {
    }
}
