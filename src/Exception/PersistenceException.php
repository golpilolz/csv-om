<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Exception;

final class PersistenceException extends \RuntimeException
{
    public static function missingId(string $className): self
    {
        return new self(sprintf('Cannot persist an entity "%s" without identifier: CSV ids are assigned by the application, set it before persist().', $className));
    }

    public static function duplicateId(string $className, string $id): self
    {
        return new self(sprintf('An entity "%s" with id "%s" already exists.', $className, $id));
    }

    public static function notManaged(string $className, string $action): self
    {
        return new self(sprintf('Cannot %s an entity "%s" that is not managed by the object manager (never persisted, or detached by clear()).', $action, $className));
    }

    public static function identifierChanged(string $className, string $from, ?string $to): self
    {
        return new self(sprintf('The identifier of a managed entity "%s" cannot change (from "%s" to "%s"). Remove it and persist a new one instead.', $className, $from, $to ?? 'null'));
    }
}
