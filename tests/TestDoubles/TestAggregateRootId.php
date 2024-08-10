<?php

declare(strict_types=1);

namespace Gember\EventStoreDoctrineDbal\Test\TestDoubles;

use Gember\EventSourcing\AggregateRoot\AggregateRootId;

final readonly class TestAggregateRootId implements AggregateRootId
{
    public function __toString(): string
    {
        return '';
    }
}
