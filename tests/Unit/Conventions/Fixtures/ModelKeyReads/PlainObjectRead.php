<?php

namespace Tests\Unit\Conventions\Fixtures\ModelKeyReads;

use Spatie\EventSourcing\StoredEvents\StoredEvent;

final class PlainObjectRead
{
    public function idOf(StoredEvent $event): int|string|null
    {
        return $event->id;
    }
}
