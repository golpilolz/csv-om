<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Tests\DependencyInjection;

use Golpilolz\CsvOm\DependencyInjection\Compiler\RepositoryPass;
use Golpilolz\CsvOm\DependencyInjection\GolpilolzCsvOmExtension;
use Golpilolz\CsvOm\GolpilolzCsvOmBundle;
use Golpilolz\CsvOm\Om\CSVObjectManagerInterface;
use Golpilolz\CsvOm\Om\ObjectManager;
use Golpilolz\CsvOm\Om\ObjectManagerInterface;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;
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

    public function testExtensionRegistersServicesAndAliases(): void
    {
        $container = new ContainerBuilder();
        (new GolpilolzCsvOmExtension())->load([], $container);

        self::assertSame(ObjectManager::class, $container->getDefinition('golpilolz_csv_om.object_manager')->getClass());
        self::assertSame('golpilolz_csv_om.object_manager', (string) $container->getAlias(ObjectManagerInterface::class));
        self::assertFalse($container->getAlias(ObjectManagerInterface::class)->isDeprecated());

        $legacy = $container->getAlias(CSVObjectManagerInterface::class);
        self::assertSame('golpilolz_csv_om.object_manager', (string) $legacy);
        self::assertTrue($legacy->isDeprecated());
        self::assertTrue(is_subclass_of(ObjectManager::class, CSVObjectManagerInterface::class), 'legacy type-hints still receive the service');
    }

    public function testConfigurationIsPassedToStorage(): void
    {
        $container = new ContainerBuilder();
        (new GolpilolzCsvOmExtension())->load([['directory' => '/data', 'delimiter' => ';']], $container);

        self::assertSame(
            ['$directory' => '/data', '$delimiter' => ';', '$enclosure' => '"'],
            $container->getDefinition('golpilolz_csv_om.storage')->getArguments(),
        );
    }

    public function testRepositoriesAreAutoconfigured(): void
    {
        $container = new ContainerBuilder();
        (new GolpilolzCsvOmExtension())->load([], $container);

        $instanceof = $container->getAutoconfiguredInstanceof();
        self::assertArrayHasKey(RepositoryInterface::class, $instanceof);
        self::assertArrayHasKey(RepositoryPass::TAG, $instanceof[RepositoryInterface::class]->getTags());
    }
}
