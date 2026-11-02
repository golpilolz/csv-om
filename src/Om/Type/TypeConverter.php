<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Type;

use Golpilolz\CsvOm\Exception\ConversionException;
use Golpilolz\CsvOm\Om\Mapping\Types;
use Golpilolz\CsvOm\Om\Metadata\FieldMapping;

/**
 * Converts between CSV cell strings and PHP values, according to a field mapping.
 *
 * Parsing is strict: anything that is not an exact representation of the type is rejected.
 * An empty cell is null for nullable fields, '' for non-nullable strings, and an error otherwise.
 */
final class TypeConverter
{
    public function toPhp(string $value, FieldMapping $mapping): mixed
    {
        if ($value === '') {
            if ($mapping->nullable) {
                return null;
            }
            if ($mapping->type === Types::STRING && $mapping->enumType === null) {
                return '';
            }

            throw ConversionException::nullNotAllowed($mapping->fieldName);
        }

        $php = match ($mapping->type) {
            Types::STRING => $value,
            Types::INT => $this->parseInt($value, $mapping->fieldName),
            Types::FLOAT => $this->parseFloat($value, $mapping->fieldName),
            Types::BOOL => $this->parseBool($value, $mapping->fieldName),
            Types::DATETIME_IMMUTABLE => $this->parseDate($value, $mapping),
            default => throw new \LogicException(sprintf('Unsupported type "%s".', $mapping->type)),
        };

        if ($mapping->enumType !== null) {
            return ($mapping->enumType)::tryFrom($php)
                ?? throw ConversionException::fromCsv($mapping->fieldName, $value, sprintf('a case of %s', $mapping->enumType));
        }

        return $php;
    }

    public function toCsv(mixed $value, FieldMapping $mapping): ?string
    {
        if ($value === null) {
            if (!$mapping->nullable) {
                throw ConversionException::nullNotAllowed($mapping->fieldName);
            }

            return null;
        }

        if ($mapping->enumType !== null) {
            if (!$value instanceof $mapping->enumType) {
                throw ConversionException::toCsv($mapping->fieldName, $value, $mapping->enumType);
            }

            return (string) $value->value;
        }

        return match ($mapping->type) {
            Types::STRING => match (true) {
                is_string($value) => $value,
                is_int($value), is_float($value), $value instanceof \Stringable => (string) $value,
                default => throw ConversionException::toCsv($mapping->fieldName, $value, 'string'),
            },
            Types::INT => is_int($value) ? (string) $value : throw ConversionException::toCsv($mapping->fieldName, $value, 'int'),
            Types::FLOAT => $this->formatFloat($value, $mapping->fieldName),
            Types::BOOL => is_bool($value) ? ($value ? '1' : '0') : throw ConversionException::toCsv($mapping->fieldName, $value, 'bool'),
            Types::DATETIME_IMMUTABLE => $value instanceof \DateTimeInterface
                ? $value->format($mapping->format ?? \DateTimeInterface::ATOM)
                : throw ConversionException::toCsv($mapping->fieldName, $value, \DateTimeInterface::class),
            default => throw new \LogicException(sprintf('Unsupported type "%s".', $mapping->type)),
        };
    }

    private function parseInt(string $value, string $field): int
    {
        if (preg_match('/^([+-]?)0*(\d+)$/', $value, $m) !== 1) {
            throw ConversionException::fromCsv($field, $value, 'int');
        }

        // filter_var() detects overflow; leading zeros are stripped first because it rejects them.
        $int = filter_var($m[1] . $m[2], FILTER_VALIDATE_INT);
        if ($int === false) {
            throw ConversionException::fromCsv($field, $value, 'int (out of range)');
        }

        return $int;
    }

    private function parseFloat(string $value, string $field): float
    {
        // is_numeric() tolerates surrounding whitespace; a cell with spaces is not a float.
        if (!is_numeric($value) || trim($value) !== $value) {
            throw ConversionException::fromCsv($field, $value, 'float');
        }

        $float = (float) $value;
        if (!is_finite($float)) {
            throw ConversionException::fromCsv($field, $value, 'float (out of range)');
        }

        return $float;
    }

    private function parseBool(string $value, string $field): bool
    {
        return match (strtolower($value)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw ConversionException::fromCsv($field, $value, 'bool (1, 0, true or false)'),
        };
    }

    private function parseDate(string $value, FieldMapping $mapping): \DateTimeImmutable
    {
        $format = $mapping->format ?? \DateTimeInterface::ATOM;
        // "!" resets unspecified fields (e.g. the time for "Y-m-d") instead of taking them from "now".
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw ConversionException::fromCsv($mapping->fieldName, $value, sprintf('a date with format "%s"', $format));
        }

        return $date;
    }

    private function formatFloat(mixed $value, string $field): string
    {
        if (is_int($value)) {
            $value = (float) $value;
        }
        if (!is_float($value) || !is_finite($value)) {
            throw ConversionException::toCsv($field, $value, 'finite float');
        }

        // var_export() uses serialize_precision (shortest round-trip repr), unlike (string) which uses
        // the "precision" ini setting (14 digits) and would lose data. It is locale independent.
        return var_export($value, true);
    }
}
