<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Mapping;

/**
 * Supported column types.
 */
final class Types
{
    public const string STRING = 'string';
    public const string INT = 'int';
    public const string FLOAT = 'float';
    public const string BOOL = 'bool';
    public const string DATETIME_IMMUTABLE = 'datetime_immutable';

    public const array ALL = [
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
