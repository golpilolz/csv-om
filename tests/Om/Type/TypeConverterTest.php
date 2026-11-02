<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Om\Type;

use Golpilolz\CsvOm\Exception\ConversionException;
use Golpilolz\CsvOm\Om\Mapping\Types;
use Golpilolz\CsvOm\Om\Metadata\FieldMapping;
use Golpilolz\CsvOm\Om\Type\TypeConverter;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\BookStatus;
use Golpilolz\CsvOm\Tests\Fixtures\Entity\Priority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypeConverterTest extends TestCase
{
    private TypeConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new TypeConverter();
    }

    private static function field(string $type, bool $nullable = false, ?string $enumType = null, ?string $format = null): FieldMapping
    {
        if ($type === Types::DATETIME_IMMUTABLE) {
            $format ??= \DateTimeInterface::ATOM;
        }

        return new FieldMapping('f', 'f', $type, $nullable, $enumType, $format);
    }

    #[DataProvider('provideValidCsvValues')]
    public function testToPhp(FieldMapping $mapping, string $csv, mixed $expected): void
    {
        $actual = $this->converter->toPhp($csv, $mapping);

        if ($expected instanceof \DateTimeImmutable) {
            self::assertInstanceOf(\DateTimeImmutable::class, $actual);
            self::assertSame($expected->format('Y-m-d H:i:s.u P'), $actual->format('Y-m-d H:i:s.u P'));

            return;
        }
        self::assertSame($expected, $actual);
    }

    public static function provideValidCsvValues(): iterable
    {
        yield 'string' => [self::field(Types::STRING), ' keep spaces ', ' keep spaces '];
        yield 'empty non-nullable string' => [self::field(Types::STRING), '', ''];
        yield 'empty nullable string' => [self::field(Types::STRING, true), '', null];
        yield 'int' => [self::field(Types::INT), '42', 42];
        yield 'negative int' => [self::field(Types::INT), '-7', -7];
        yield 'plus sign int' => [self::field(Types::INT), '+7', 7];
        yield 'leading zeros int' => [self::field(Types::INT), '007', 7];
        yield 'zero int' => [self::field(Types::INT), '000', 0];
        yield 'max int' => [self::field(Types::INT), (string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'min int' => [self::field(Types::INT), (string) PHP_INT_MIN, PHP_INT_MIN];
        yield 'empty nullable int' => [self::field(Types::INT, true), '', null];
        yield 'float' => [self::field(Types::FLOAT), '1.5', 1.5];
        yield 'integer float' => [self::field(Types::FLOAT), '3', 3.0];
        yield 'exponent float' => [self::field(Types::FLOAT), '1.0E+25', 1.0E+25];
        yield 'bool 1' => [self::field(Types::BOOL), '1', true];
        yield 'bool 0' => [self::field(Types::BOOL), '0', false];
        yield 'bool TRUE' => [self::field(Types::BOOL), 'TRUE', true];
        yield 'bool false' => [self::field(Types::BOOL), 'false', false];
        yield 'datetime atom' => [self::field(Types::DATETIME_IMMUTABLE), '2024-05-01T10:30:00+02:00', new \DateTimeImmutable('2024-05-01 10:30:00+02:00')];
        yield 'date only resets time' => [self::field(Types::DATETIME_IMMUTABLE, format: 'Y-m-d'), '2024-05-01', new \DateTimeImmutable('2024-05-01 00:00:00')];
        yield 'custom format' => [self::field(Types::DATETIME_IMMUTABLE, format: 'd/m/Y H:i'), '01/05/2024 10:30', new \DateTimeImmutable('2024-05-01 10:30:00')];
        yield 'string enum' => [self::field(Types::STRING, enumType: BookStatus::class), 'published', BookStatus::Published];
        yield 'int enum' => [self::field(Types::INT, enumType: Priority::class), '2', Priority::High];
        yield 'int enum leading zero' => [self::field(Types::INT, enumType: Priority::class), '02', Priority::High];
        yield 'empty nullable enum' => [self::field(Types::STRING, true, BookStatus::class), '', null];
    }

    #[DataProvider('provideInvalidCsvValues')]
    public function testToPhpRejectsInvalidValues(FieldMapping $mapping, string $csv, string $messagePart): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage($messagePart);

        $this->converter->toPhp($csv, $mapping);
    }

    public static function provideInvalidCsvValues(): iterable
    {
        yield 'decimal int' => [self::field(Types::INT), '1.5', 'to int'];
        yield 'int with suffix' => [self::field(Types::INT), '12abc', 'to int'];
        yield 'int with spaces' => [self::field(Types::INT), ' 12', 'to int'];
        yield 'int exponent' => [self::field(Types::INT), '1e3', 'to int'];
        yield 'int overflow' => [self::field(Types::INT), '9223372036854775808', 'out of range'];
        yield 'empty int' => [self::field(Types::INT), '', 'not nullable'];
        yield 'float with comma' => [self::field(Types::FLOAT), '1,5', 'to float'];
        yield 'float with spaces' => [self::field(Types::FLOAT), '1.5 ', 'to float'];
        yield 'float INF text' => [self::field(Types::FLOAT), 'INF', 'to float'];
        yield 'float overflow' => [self::field(Types::FLOAT), '1e999', 'out of range'];
        yield 'bool yes' => [self::field(Types::BOOL), 'yes', 'bool (1, 0, true or false)'];
        yield 'empty bool' => [self::field(Types::BOOL), '', 'not nullable'];
        yield 'relative date' => [self::field(Types::DATETIME_IMMUTABLE), 'tomorrow', 'format "Y-m-d\TH:i:sP"'];
        yield 'date wrong format' => [self::field(Types::DATETIME_IMMUTABLE, format: 'Y-m-d'), '01/05/2024', 'format "Y-m-d"'];
        yield 'date overflow' => [self::field(Types::DATETIME_IMMUTABLE, format: 'Y-m-d'), '2024-02-30', 'format "Y-m-d"'];
        yield 'date trailing data' => [self::field(Types::DATETIME_IMMUTABLE, format: 'Y-m-d'), '2024-02-01 10:00', 'format "Y-m-d"'];
        yield 'unknown enum case' => [self::field(Types::STRING, enumType: BookStatus::class), 'archived', 'a case of'];
        yield 'empty non-nullable enum' => [self::field(Types::STRING, enumType: BookStatus::class), '', 'not nullable'];
        yield 'int enum not int' => [self::field(Types::INT, enumType: Priority::class), 'high', 'to int'];
    }

    #[DataProvider('provideValidPhpValues')]
    public function testToCsv(FieldMapping $mapping, mixed $php, ?string $expected): void
    {
        self::assertSame($expected, $this->converter->toCsv($php, $mapping));
    }

    public static function provideValidPhpValues(): iterable
    {
        yield 'string' => [self::field(Types::STRING), 'a', 'a'];
        yield 'int to string column' => [self::field(Types::STRING), 5, '5'];
        yield 'stringable' => [self::field(Types::STRING), new class () implements \Stringable {
            public function __toString(): string
            {
                return 'str';
            }
        }, 'str'];
        yield 'null nullable' => [self::field(Types::STRING, true), null, null];
        yield 'int' => [self::field(Types::INT), -12, '-12'];
        yield 'float' => [self::field(Types::FLOAT), 1.5, '1.5'];
        yield 'float keeps precision' => [self::field(Types::FLOAT), 0.1 + 0.2, '0.30000000000000004'];
        yield 'whole float' => [self::field(Types::FLOAT), 3.0, '3.0'];
        yield 'int into float' => [self::field(Types::FLOAT), 3, '3.0'];
        yield 'bool true' => [self::field(Types::BOOL), true, '1'];
        yield 'bool false' => [self::field(Types::BOOL), false, '0'];
        yield 'datetime atom' => [self::field(Types::DATETIME_IMMUTABLE), new \DateTimeImmutable('2024-05-01 10:30:00+02:00'), '2024-05-01T10:30:00+02:00'];
        yield 'mutable datetime' => [self::field(Types::DATETIME_IMMUTABLE, format: 'Y-m-d'), new \DateTime('2024-05-01 10:30'), '2024-05-01'];
        yield 'string enum' => [self::field(Types::STRING, enumType: BookStatus::class), BookStatus::Draft, 'draft'];
        yield 'int enum' => [self::field(Types::INT, enumType: Priority::class), Priority::Low, '1'];
    }

    #[DataProvider('provideInvalidPhpValues')]
    public function testToCsvRejectsInvalidValues(FieldMapping $mapping, mixed $php, string $messagePart): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage($messagePart);

        $this->converter->toCsv($php, $mapping);
    }

    public static function provideInvalidPhpValues(): iterable
    {
        yield 'null non-nullable' => [self::field(Types::STRING), null, 'not nullable'];
        yield 'array to string' => [self::field(Types::STRING), ['a'], 'expected string, array given'];
        yield 'bool to string' => [self::field(Types::STRING), true, 'expected string, bool given'];
        yield 'numeric string to int' => [self::field(Types::INT), '5', 'expected int, string given'];
        yield 'float to int' => [self::field(Types::INT), 5.0, 'expected int, float given'];
        yield 'INF' => [self::field(Types::FLOAT), INF, 'finite float'];
        yield 'NAN' => [self::field(Types::FLOAT), NAN, 'finite float'];
        yield 'int to bool' => [self::field(Types::BOOL), 1, 'expected bool, int given'];
        yield 'string to datetime' => [self::field(Types::DATETIME_IMMUTABLE), '2024-05-01', 'expected DateTimeInterface'];
        yield 'wrong enum' => [self::field(Types::INT, enumType: Priority::class), BookStatus::Draft, 'expected ' . Priority::class];
        yield 'raw value for enum' => [self::field(Types::STRING, enumType: BookStatus::class), 'draft', 'expected ' . BookStatus::class];
    }

    public function testFloatsRoundTripExactly(): void
    {
        $mapping = self::field(Types::FLOAT);
        foreach ([0.1, 1 / 3, -2.5e-300, 1.7976931348623157E+308, 123456789.123456789] as $float) {
            self::assertSame($float, $this->converter->toPhp($this->converter->toCsv($float, $mapping), $mapping));
        }
    }

    public function testFloatOutputDoesNotDependOnPrecisionIni(): void
    {
        // The locale is switched too when one is installed; PHP 8 float/string conversion is
        // locale-independent anyway, so the precision ini is what this test really guards.
        $precision = ini_get('precision');
        $locale = setlocale(LC_NUMERIC, '0');
        try {
            ini_set('precision', '5');
            setlocale(LC_NUMERIC, 'fr_FR.UTF-8', 'fr_FR', 'de_DE.UTF-8', 'de_DE');

            self::assertSame('1.23456789', $this->converter->toCsv(1.23456789, self::field(Types::FLOAT)));
        } finally {
            ini_set('precision', (string) $precision);
            setlocale(LC_NUMERIC, (string) $locale);
        }
    }
}
