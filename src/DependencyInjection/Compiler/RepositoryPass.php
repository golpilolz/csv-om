<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects repository services into a lazy locator keyed by repository class, used by
 * ObjectManager::getRepository(). The locator is lazy, so repositories depending on the
 * object manager do not create a circular reference.
 */
final class RepositoryPass implements CompilerPassInterface
{
    public const string TAG = 'golpilolz_csv_om.repository';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('golpilolz_csv_om.object_manager')) {
            return;
        }

        $repositories = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);
            $repositories[$class] = new Reference($id);
        }

        $container->getDefinition('golpilolz_csv_om.object_manager')
            ->setArgument('$repositoryLocator', ServiceLocatorTagPass::register($container, $repositories));
    }
}
