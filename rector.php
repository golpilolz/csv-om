<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Symfony\Symfony40\Rector\MethodCall\ContainerBuilderCompileEnvArgumentRector;

/*
 * Upgrade-only configuration: replaces code deprecated by PHP, Symfony and PHPUnit.
 *
 * - PHP sets follow the "php" constraint of composer.json (minimum 8.3), so no newer syntax is introduced.
 * - Symfony and PHPUnit sets follow the versions installed in vendor/. Run it with the minimum supported
 *   Symfony installed (the platform pin in composer.json resolves 7.4) so rules don't require Symfony 8.
 *
 * composer rector       # dry run, used by CI
 * composer rector:fix   # apply
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRootFiles()
    ->withPhpSets()
    ->withComposerBased(symfony: true, phpunit: true)
    ->withAttributesSets(symfony: true, phpunit: true)
    ->withSkip([
        // Entities are mutated from outside (hydrator, setters): properties never assigned in the class are
        // not readonly candidates. Applies to fixtures and to any entity-like class.
        ReadOnlyPropertyRector::class,
        // Promotion moves properties in reflection order, which is the CSV column order of an entity.
        // The Author fixture also tests non-promoted readonly properties on purpose.
        ClassPropertyAssignToConstructorPromotionRector::class,
        // Symfony 3 -> 4 migration rule (keeps 3.x env placeholder resolution with compile(true)):
        // irrelevant for code written against 7.4, and it changes what the container tests compile.
        ContainerBuilderCompileEnvArgumentRector::class,
    ]);
