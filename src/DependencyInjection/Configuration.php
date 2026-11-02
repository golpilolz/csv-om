<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('golpilolz_csv_om');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('directory')
                    ->info('Directory holding the CSV files; entity "file" options are relative to it.')
                    ->defaultValue('%kernel.project_dir%/csv')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('delimiter')
                    ->info('Default field delimiter, overridable per entity.')
                    ->defaultValue(',')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || strlen($v) !== 1)
                        ->thenInvalid('The delimiter must be a single character, %s given.')
                    ->end()
                ->end()
                ->scalarNode('enclosure')
                    ->info('Default field enclosure, overridable per entity.')
                    ->defaultValue('"')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || strlen($v) !== 1)
                        ->thenInvalid('The enclosure must be a single character, %s given.')
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
