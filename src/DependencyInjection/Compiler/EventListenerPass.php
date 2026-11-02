<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\DependencyInjection\Compiler;

use Golpilolz\CsvOm\Om\Event\Events;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the services tagged `golpilolz_csv_om.event_listener` (attributes: `event`, optional `method`,
 * `entity` and `priority`) in the event manager. Listeners are resolved lazily, so a listener may depend on
 * the object manager without a circular reference.
 */
final class EventListenerPass implements CompilerPassInterface
{
    public const string TAG = 'golpilolz_csv_om.event_listener';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('golpilolz_csv_om.event_manager')) {
            return;
        }

        $known = [...Events::ENTITY_EVENTS, ...Events::FLUSH_EVENTS];
        $listeners = [];
        $services = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $services[$id] = new Reference($id);
            foreach ($tags as $tag) {
                $event = $tag['event'] ?? throw new InvalidArgumentException(sprintf('The "%s" tag of service "%s" needs an "event" attribute.', self::TAG, $id));
                if (!in_array($event, $known, true)) {
                    throw new InvalidArgumentException(sprintf('Service "%s" listens to the unknown event "%s", expected one of: %s.', $id, $event, implode(', ', $known)));
                }
                if (isset($tag['entity']) && !in_array($event, Events::ENTITY_EVENTS, true)) {
                    throw new InvalidArgumentException(sprintf('Service "%s": event "%s" is not about an entity, it cannot have an "entity" attribute.', $id, $event));
                }
                $listeners[$event][] = [
                    'service' => $id,
                    'method' => $tag['method'] ?? $event,
                    'entity' => $tag['entity'] ?? null,
                    'priority' => (int) ($tag['priority'] ?? 0),
                ];
            }
        }

        foreach ($listeners as &$entries) {
            // Higher priority first; equal priorities keep the order of the container.
            $position = 0;
            $entries = array_map(static fn (array $e): array => [$position++, $e], $entries);
            usort($entries, static fn (array $a, array $b): int => $b[1]['priority'] <=> $a[1]['priority'] ?: $a[0] <=> $b[0]);
            $entries = array_map(static fn (array $e): array => $e[1], $entries);
        }
        unset($entries);

        $container->getDefinition('golpilolz_csv_om.event_manager')
            ->setArgument('$listeners', $listeners)
            ->setArgument('$locator', ServiceLocatorTagPass::register($container, $services));
    }
}
