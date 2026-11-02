<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\DependencyInjection;

use Golpilolz\CsvOm\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    private function process(array ...$configs): array
    {
        return (new Processor())->processConfiguration(new Configuration(), $configs);
    }

    public function testDefaults(): void
    {
        self::assertSame(
            ['directory' => '%kernel.project_dir%/csv', 'delimiter' => ',', 'enclosure' => '"'],
            $this->process([]),
        );
    }

    public function testOverridesAreMerged(): void
    {
        self::assertSame(
            ['directory' => '/data', 'delimiter' => ';', 'enclosure' => "'"],
            $this->process(['directory' => '/data'], ['delimiter' => ';', 'enclosure' => "'"]),
        );
    }

    #[DataProvider('provideInvalidConfigs')]
    public function testInvalidConfig(array $config, string $messagePart): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($messagePart);

        $this->process($config);
    }

    public static function provideInvalidConfigs(): iterable
    {
        yield 'empty directory' => [['directory' => ''], 'cannot contain an empty value'];
        yield 'long delimiter' => [['delimiter' => ';;'], 'delimiter must be a single character'];
        yield 'empty enclosure' => [['enclosure' => ''], 'enclosure must be a single character'];
        yield 'unknown option' => [['dir' => '/data'], 'Unrecognized option "dir"'];
    }
}
