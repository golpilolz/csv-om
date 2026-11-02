<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Event;

use Golpilolz\CsvOm\Om\ObjectManagerInterface;

/**
 * Argument of `preUpdate`: the object with the scalar fields that differ from the loaded row. Changes to
 * associations are not part of the change set.
 */
final class PreUpdateEventArgs extends LifecycleEventArgs
{
    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet field name => [value in the file, current value]
     */
    public function __construct(
        object $object,
        ObjectManagerInterface $objectManager,
        private readonly array $changeSet,
    ) {
        parent::__construct($object, $objectManager);
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function getChangeSet(): array
    {
        return $this->changeSet;
    }

    public function hasChangedField(string $field): bool
    {
        return isset($this->changeSet[$field]);
    }

    public function getOldValue(string $field): mixed
    {
        return ($this->changeSet[$field] ?? throw new \InvalidArgumentException(sprintf('Field "%s" has not changed.', $field)))[0];
    }

    public function getNewValue(string $field): mixed
    {
        return ($this->changeSet[$field] ?? throw new \InvalidArgumentException(sprintf('Field "%s" has not changed.', $field)))[1];
    }
}
