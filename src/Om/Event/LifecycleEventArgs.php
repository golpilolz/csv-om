<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Event;

use Golpilolz\CsvOm\Om\ObjectManagerInterface;

/**
 * Argument of the events about one entity.
 */
class LifecycleEventArgs
{
    public function __construct(
        private readonly object $object,
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function getObject(): object
    {
        return $this->object;
    }

    public function getObjectManager(): ObjectManagerInterface
    {
        return $this->objectManager;
    }
}
