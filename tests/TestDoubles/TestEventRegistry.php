<?php

declare(strict_types=1);

namespace Gember\EventStoreDoctrineDbal\Test\TestDoubles;

use Gember\EventSourcing\Registry\Event\EventNotRegisteredException;
use Gember\EventSourcing\Registry\Event\EventRegistry;

final class TestEventRegistry implements EventRegistry
{
    /**
     * @param array<string, class-string> $map
     */
    public function __construct(
        private array $map = [],
    ) {}

    /**
     * @param array<string, class-string> $map
     */
    public function setMap(array $map): void
    {
        $this->map = $map;
    }

    public function retrieve(string $eventName): string
    {
        return $this->map[$eventName] ?? throw EventNotRegisteredException::withEventName($eventName);
    }
}
