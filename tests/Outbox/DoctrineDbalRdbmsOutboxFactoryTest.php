<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Outbox;

use Gember\RdbmsEventStoreDoctrineDbal\Outbox\DoctrineDbalRdbmsOutboxFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DoctrineDbalRdbmsOutboxFactoryTest extends TestCase
{
    #[Test]
    public function itShouldCreateFromRow(): void
    {
        $factory = new DoctrineDbalRdbmsOutboxFactory();

        $message = $factory->createFromRow([
            'id' => 'msg-1',
            'messageType' => 'event',
            'messageName' => 'App\Domain\SomeEvent',
            'payload' => '{"key":"value"}',
            'createdAt' => '2024-10-14 12:00:00.000000',
            'retryCount' => 3,
        ]);

        self::assertSame('msg-1', $message->id);
        self::assertSame('event', $message->messageType);
        self::assertSame('App\Domain\SomeEvent', $message->messageName);
        self::assertSame('{"key":"value"}', $message->payload);
        self::assertSame('2024-10-14 12:00:00.000000', $message->createdAt->format('Y-m-d H:i:s.u'));
        self::assertSame(3, $message->retryCount);
    }
}
