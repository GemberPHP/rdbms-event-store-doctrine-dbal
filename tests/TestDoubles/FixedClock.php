<?php

declare(strict_types=1);

namespace Gember\EventStoreDoctrineDbal\Test\TestDoubles;

use Gember\EventSourcing\Util\Time\Clock\Clock;
use DateTimeImmutable;

final readonly class FixedClock implements Clock
{
    public DateTimeImmutable $time;

    public function __construct()
    {
        $this->time = new DateTimeImmutable();
    }

    public function now(string $time = 'now'): DateTimeImmutable
    {
        return $this->time->modify($time);
    }
}
