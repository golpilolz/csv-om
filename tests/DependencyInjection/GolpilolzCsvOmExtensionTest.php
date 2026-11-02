<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\DependencyInjection;

use Golpilolz\CsvOm\DependencyInjection\GolpilolzCsvOmExtension;
use Golpilolz\CsvOm\GolpilolzCsvOmBundle;
use Golpilolz\CsvOm\Om\CSVObjectManager;
use Golpilolz\CsvOm\Om\CSVObjectManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class GolpilolzCsvOmExtensionTest extends TestCase
{
    public function testBundleExposesExtension(): void
    {
        $extension = (new GolpilolzCsvOmBundle())->getContainerExtension();

        self::assertInstanceOf(GolpilolzCsvOmExtension::class, $extension);
        self::assertSame('golpilolz_csv_om', $extension->getAlias());
    }

    public function testExtensionRegistersObjectManager(): void
    {
        $container = new ContainerBuilder();
        (new GolpilolzCsvOmExtension())->load([], $container);

        self::assertTrue($container->hasDefinition('golpilolz_csv_om.object_manager'));
        self::assertSame(CSVObjectManager::class, $container->getDefinition('golpilolz_csv_om.object_manager')->getClass());
        self::assertTrue($container->hasAlias(CSVObjectManagerInterface::class));
        self::assertSame('golpilolz_csv_om.object_manager', (string) $container->getAlias(CSVObjectManagerInterface::class));
    }
}
