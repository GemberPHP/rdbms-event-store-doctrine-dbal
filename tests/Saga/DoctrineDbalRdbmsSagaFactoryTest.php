<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Saga;

use Gember\RdbmsEventStoreDoctrineDbal\Saga\DoctrineDbalRdbmsSagaFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use DateTimeImmutable;

/**
 * @internal
 */
final class DoctrineDbalRdbmsSagaFactoryTest extends TestCase
{
    #[Test]
    public function itShouldCreateRdbmsSaga(): void
    {
        $saga = (new DoctrineDbalRdbmsSagaFactory())->createFromRow([
            'sagaName' => 'some.saga',
            'sagaId' => '01K76G1PGKPZ047KDN25PFPEEV',
            'payload' => '{"some":"data"}',
            'createdAt' => '2018-12-01 12:05:08.234543',
            'updatedAt' => null,
        ]);

        self::assertSame('some.saga', $saga->sagaName);
        self::assertSame('01K76G1PGKPZ047KDN25PFPEEV', $saga->sagaId);
        self::assertSame('{"some":"data"}', $saga->payload);
        self::assertEquals(new DateTimeImmutable('2018-12-01 12:05:08.234543'), $saga->createdAt);
        self::assertNull($saga->updatedAt);
    }
}
