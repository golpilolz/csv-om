<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\Maker;

use Golpilolz\CsvOm\DependencyInjection\GolpilolzCsvOmExtension;
use Golpilolz\CsvOm\Maker\MakeCsvEntity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MakeCsvEntityTest extends TestCase
{
    public function testCommandMetadata(): void
    {
        self::assertSame('make:csv-entity', MakeCsvEntity::getCommandName());
    }

    public function testExtensionRegistersMakerWithConfiguration(): void
    {
        $container = new ContainerBuilder();
        (new GolpilolzCsvOmExtension())->load([['directory' => '/data', 'delimiter' => ';']], $container);

        $definition = $container->getDefinition('golpilolz_csv_om.maker.make_csv_entity');
        self::assertTrue($definition->hasTag('maker.command'));
        self::assertSame('/data', $definition->getArgument('$directory'));
        self::assertSame(';', $definition->getArgument('$delimiter'));
    }
}
