<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test;

use Gember\RdbmsEventStoreDoctrineDbal\DoctrineDbalRdbmsEventFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

/**
 * @internal
 */
final class DoctrineDbalRdbmsEventFactoryTest extends TestCase
{
    #[Test]
    public function itShouldCreateRdbmsEvent(): void
    {
        $event = (new DoctrineDbalRdbmsEventFactory())->createFromRow([
            'eventId' => '5e3ce06e-8f08-440e-b7ea-412ac6c3e892',
            'eventName' => 'event_name',
            'payload' => '{"some":"data"}',
            'metadata' => '{"some":"metadata"}',
            'appliedAt' => '2018-12-01 12:05:08.234543',
            'domainId' => '5f183c87-20c5-412e-8b0f-e8d86c7b7a47',
        ]);

        self::assertSame('5e3ce06e-8f08-440e-b7ea-412ac6c3e892', $event->eventId);
        self::assertSame('event_name', $event->eventName);
        self::assertSame('{"some":"data"}', $event->payload);
        self::assertSame(['some' => 'metadata'], $event->metadata);
        self::assertEquals(new DateTimeImmutable('2018-12-01 12:05:08.234543'), $event->appliedAt);
    }
}
