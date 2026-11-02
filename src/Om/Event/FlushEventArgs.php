<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Event;

use Golpilolz\CsvOm\Om\ObjectManagerInterface;

/**
 * Argument of `preFlush` and `postFlush`.
 */
final readonly class FlushEventArgs
{
    public function __construct(
        private ObjectManagerInterface $objectManager,
    ) {
    }

    public function getObjectManager(): ObjectManagerInterface
    {
        return $this->objectManager;
    }
}
