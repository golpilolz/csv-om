<?php

declare(strict_types=1);

namespace Golpilolz\CsvOm\Om\Event;

use Psr\Container\ContainerInterface;

/**
 * Registry of lifecycle listeners. A listener is an object and a method; it receives a
 * {@see LifecycleEventArgs} (entity events) or a {@see FlushEventArgs} (flush events). In a Symfony app,
 * tag a service with `golpilolz_csv_om.event_listener` or use {@see AsListener}.
 */
final class EventManager
{
    /** @var array<string, list<array{listener: object|string, method: string, entity: ?class-string, priority: int}>> */
    private array $listeners = [];

    /**
     * @param array<string, list<array{service: string, method: string, entity: ?class-string, priority?: int}>> $listeners
     *        event => listeners as service ids, resolved lazily through $locator
     */
    public function __construct(array $listeners = [], private readonly ?ContainerInterface $locator = null)
    {
        foreach ($listeners as $event => $entries) {
            foreach ($entries as $entry) {
                $this->register($event, $entry['service'], $entry['method'], $entry['entity'] ?? null, $entry['priority'] ?? 0);
            }
        }
    }

    /**
     * @param string|null $method defaults to the event name
     * @param class-string|null $entity only call the listener for objects of this class (entity events only)
     */
    public function addListener(string $event, object $listener, ?string $method = null, ?string $entity = null, int $priority = 0): void
    {
        $this->register($event, $listener, $method ?? $event, $entity, $priority);
    }

    public function hasListeners(string $event): bool
    {
        return ($this->listeners[$event] ?? []) !== [];
    }

    public function dispatch(string $event, LifecycleEventArgs|FlushEventArgs $args): void
    {
        foreach ($this->listeners[$event] ?? [] as $entry) {
            if ($entry['entity'] !== null && (!$args instanceof LifecycleEventArgs || !$args->getObject() instanceof $entry['entity'])) {
                continue;
            }
            $listener = $entry['listener'];
            if (is_string($listener)) {
                $listener = $this->locator?->get($listener) ?? throw new \LogicException(sprintf('Cannot resolve the listener service "%s".', $listener));
            }
            $listener->{$entry['method']}($args);
        }
    }

    private function register(string $event, object|string $listener, string $method, ?string $entity, int $priority): void
    {
        if (!in_array($event, [...Events::ENTITY_EVENTS, ...Events::FLUSH_EVENTS], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown event "%s".', $event));
        }
        if ($entity !== null && !in_array($event, Events::ENTITY_EVENTS, true)) {
            throw new \InvalidArgumentException(sprintf('Event "%s" is not about an entity: it cannot be filtered by entity class.', $event));
        }
        if (is_object($listener) && !method_exists($listener, $method) && !method_exists($listener, '__call')) {
            throw new \InvalidArgumentException(sprintf('Listener "%s" has no method "%s()".', $listener::class, $method));
        }

        $this->listeners[$event][] = ['listener' => $listener, 'method' => $method, 'entity' => $entity, 'priority' => $priority];
        // Stable: equal priorities keep their registration order.
        $position = 0;
        $indexed = array_map(static fn (array $e): array => [$position++, $e], $this->listeners[$event]);
        usort($indexed, static fn (array $a, array $b): int => $b[1]['priority'] <=> $a[1]['priority'] ?: $a[0] <=> $b[0]);
        $this->listeners[$event] = array_map(static fn (array $i): array => $i[1], $indexed);
    }
}
