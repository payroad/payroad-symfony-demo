<?php

declare(strict_types=1);

namespace App\Infrastructure\Event;

use Payroad\Domain\DomainEvent;
use Payroad\Port\Event\DomainEventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerDomainEventDispatcher implements DomainEventDispatcherInterface
{
    public function __construct(private readonly MessageBusInterface $eventBus) {}

    public function dispatch(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->eventBus->dispatch($event);
        }
    }
}
