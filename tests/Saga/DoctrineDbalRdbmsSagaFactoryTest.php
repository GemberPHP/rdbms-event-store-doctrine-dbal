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
            'id' => '01K7Q14MMW2FQP2JS1RHQS7QXP',
            'sagaName' => 'some.saga',
            'sagaIds' => ['01K76G1PGKPZ047KDN25PFPEEV', '01K7Q13CG3A3PQCC98XYSE67K1'],
            'payload' => '{"some":"data"}',
            'createdAt' => '2018-12-01 12:05:08.234543',
            'updatedAt' => null,
        ]);

        self::assertSame('01K7Q14MMW2FQP2JS1RHQS7QXP', $saga->id);
        self::assertSame('some.saga', $saga->sagaName);
        self::assertSame(['01K76G1PGKPZ047KDN25PFPEEV', '01K7Q13CG3A3PQCC98XYSE67K1'], $saga->sagaIds);
        self::assertSame('{"some":"data"}', $saga->payload);
        self::assertEquals(new DateTimeImmutable('2018-12-01 12:05:08.234543'), $saga->createdAt);
        self::assertNull($saga->updatedAt);
    }
}
