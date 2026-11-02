<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

/**
 * Supported column types.
 */
final class Types
{
    public const STRING = 'string';
    public const INT = 'int';
    public const FLOAT = 'float';
    public const BOOL = 'bool';
    public const DATETIME_IMMUTABLE = 'datetime_immutable';

    public const ALL = [
        self::STRING,
        self::INT,
        self::FLOAT,
        self::BOOL,
        self::DATETIME_IMMUTABLE,
    ];

    private function __construct()
    {
    }
}
