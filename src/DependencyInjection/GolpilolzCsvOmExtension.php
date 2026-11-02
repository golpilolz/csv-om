<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\DependencyInjection;

use Golpilolz\CsvOm\DependencyInjection\Compiler\RepositoryPass;
use Golpilolz\CsvOm\Om\Repository\RepositoryInterface;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class GolpilolzCsvOmExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->getDefinition('golpilolz_csv_om.storage')
            ->setArgument('$directory', $config['directory'])
            ->setArgument('$delimiter', $config['delimiter'])
            ->setArgument('$enclosure', $config['enclosure']);

        // make:csv-entity, only when symfony/maker-bundle is installed
        if (class_exists(AbstractMaker::class)) {
            $loader->load('maker.yaml');
            $container->getDefinition('golpilolz_csv_om.maker.make_csv_entity')
                ->setArgument('$directory', $config['directory'])
                ->setArgument('$delimiter', $config['delimiter'])
                ->setArgument('$enclosure', $config['enclosure']);
        }

        $container->registerForAutoconfiguration(RepositoryInterface::class)
            ->addTag(RepositoryPass::TAG);
    }

    public function getAlias(): string
    {
        return 'golpilolz_csv_om';
    }
}
