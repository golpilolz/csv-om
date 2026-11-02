<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Exception;

final class PersistenceException extends \RuntimeException
{
    public static function missingId(string $className): self
    {
        return new self(sprintf('Cannot persist an entity "%s" without identifier: set it before persist(), or mark the id with #[CsvOm\\GeneratedValue].', $className));
    }

    public static function noTargetFile(string $className): self
    {
        return new self(sprintf('Cannot persist an entity "%s": it has no file to be added to. Set its #[CsvOm\\SourceFile] property, or give #[CsvOm\\Entity] a "newFile".', $className));
    }

    public static function invalidTargetFile(string $className, string $file, string $reason): self
    {
        return new self(sprintf('Invalid file "%s" for an entity "%s": %s.', $file, $className, $reason));
    }

    public static function sourceFileChanged(string $className, string $id, string $from, string $to): self
    {
        return new self(sprintf('The source file of "%s" #%s changed from "%s" to "%s": rows cannot be moved between files.', $className, $id, $from, $to));
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

    public static function unmanagedTarget(string $className, ?string $id, string $field, string $target): self
    {
        return new self(sprintf('Association "%s::$%s" (entity id "%s") points to a "%s" that is not managed: persist() it first, or set cascade: [\'persist\'] on the association.', $className, $field, $id ?? '?', $target));
    }

    public static function wrongTargetClass(string $className, string $field, string $expected, object $actual): self
    {
        return new self(sprintf('Association "%s::$%s" expects "%s", "%s" given.', $className, $field, $expected, $actual::class));
    }

    public static function nullAssociation(string $className, ?string $id, string $field): self
    {
        return new self(sprintf('Association "%s::$%s" (entity id "%s") is not nullable but has no target.', $className, $field, $id ?? '?'));
    }

    public static function stillReferenced(string $className, string $id, string $referrer, string $referrerId, string $field): self
    {
        return new self(sprintf('Cannot remove "%s" with id "%s": it is still referenced by "%s" with id "%s" through "%s". Change the reference first, or use onDelete SET NULL / CASCADE.', $className, $id, $referrer, $referrerId, $field));
    }
}
