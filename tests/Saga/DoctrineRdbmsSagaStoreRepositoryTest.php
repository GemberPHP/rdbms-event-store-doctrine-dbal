<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Saga;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaConcurrencyException;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaNotFoundException;
use Gember\RdbmsEventStoreDoctrineDbal\Saga\DoctrineDbalRdbmsSagaFactory;
use Gember\RdbmsEventStoreDoctrineDbal\Saga\DoctrineRdbmsSagaStoreRepository;
use Gember\RdbmsEventStoreDoctrineDbal\Saga\TableSchema\SagaTableSchemaFactory;
use Gember\RdbmsEventStoreDoctrineDbal\Test\TestDoubles\TestIdentityGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Override;
use DateTimeImmutable;

/**
 * @internal
 */
final class DoctrineRdbmsSagaStoreRepositoryTest extends TestCase
{
    private Connection $connection;
    private DoctrineRdbmsSagaStoreRepository $repository;
    private TestIdentityGenerator $identityGenerator;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = DriverManager::getConnection((new DsnParser())->parse('pdo-sqlite:///:memory:'));
        $this->connection->executeStatement((string) file_get_contents(__DIR__ . '/../schema.sql'));

        $connection = $this->connection;

        $this->repository = new DoctrineRdbmsSagaStoreRepository(
            $connection,
            SagaTableSchemaFactory::createDefaultSagaStore(),
            SagaTableSchemaFactory::createDefaultSagaStoreRelation(),
            new DoctrineDbalRdbmsSagaFactory(),
            $this->identityGenerator = new TestIdentityGenerator(),
        );
    }

    #[Test]
    public function itShouldThrowExceptionWhenSagaNotFound(): void
    {
        self::expectException(RdbmsSagaNotFoundException::class);

        $this->repository->get('some.saga', '01K76GDQ5RT71G7HQVNR264KD4');
    }

    #[Test]
    public function itShouldSaveAndGetSaga(): void
    {
        $this->identityGenerator->ids[] = '01K7Q083CX4T7Z0NT5CKEX8NEJ';

        $this->repository->save(
            'some.saga',
            '{"some":"data"}',
            new DateTimeImmutable('2025-10-10 12:00:34'),
            null,
            '01K76GDQ5RT71G7HQVNR264KD4',
            '01K7Q033P5174AXA054FFAHW2F',
        );

        $saga = $this->repository->get('some.saga', '01K76GDQ5RT71G7HQVNR264KD4');

        self::assertSame('01K7Q083CX4T7Z0NT5CKEX8NEJ', $saga->id);
        self::assertSame('some.saga', $saga->sagaName);
        self::assertSame(['01K76GDQ5RT71G7HQVNR264KD4', '01K7Q033P5174AXA054FFAHW2F'], $saga->sagaIds);
        self::assertSame('{"some":"data"}', $saga->payload);
        self::assertEquals(new DateTimeImmutable('2025-10-10 12:00:34'), $saga->createdAt);
        self::assertNull($saga->updatedAt);
        self::assertSame(0, $saga->revision);
    }

    #[Test]
    public function itShouldGetSagaWithCorrectSagaIdsWhenMultipleSagasExist(): void
    {
        $this->identityGenerator->ids[] = '01K7Q083CX4T7Z0NT5CKEX8NEJ';
        $this->identityGenerator->ids[] = '01K7Q083CX4T7Z0NT5CKEX8NEK';

        $this->repository->save(
            'some.saga',
            '{"some":"data"}',
            new DateTimeImmutable('2025-10-10 12:00:34'),
            null,
            '01K76GDQ5RT71G7HQVNR264KD4',
            '01K7Q033P5174AXA054FFAHW2F',
        );

        $this->repository->save(
            'other.saga',
            '{"other":"data"}',
            new DateTimeImmutable('2025-10-10 13:00:00'),
            null,
            '01K76GDQ5RT71G7HQVNR264KD5',
            '01K7Q033P5174AXA054FFAHW2G',
        );

        $saga = $this->repository->get('some.saga', '01K76GDQ5RT71G7HQVNR264KD4');

        self::assertSame('01K7Q083CX4T7Z0NT5CKEX8NEJ', $saga->id);
        self::assertSame('some.saga', $saga->sagaName);
        self::assertSame(['01K76GDQ5RT71G7HQVNR264KD4', '01K7Q033P5174AXA054FFAHW2F'], $saga->sagaIds);

        $otherSaga = $this->repository->get('other.saga', '01K76GDQ5RT71G7HQVNR264KD5');

        self::assertSame('01K7Q083CX4T7Z0NT5CKEX8NEK', $otherSaga->id);
        self::assertSame('other.saga', $otherSaga->sagaName);
        self::assertSame(['01K76GDQ5RT71G7HQVNR264KD5', '01K7Q033P5174AXA054FFAHW2G'], $otherSaga->sagaIds);
    }

    #[Test]
    public function itShouldSaveExistingSaga(): void
    {
        $this->identityGenerator->ids[] = '01K7Q083CX4T7Z0NT5CKEX8NEJ';

        $this->repository->save(
            'some.saga',
            '{"some":"data"}',
            new DateTimeImmutable('2025-10-10 12:00:34'),
            null,
            '01K76GDQ5RT71G7HQVNR264KD4',
            '01K7Q0GR8ABHBZG8QCGTXJXJ7T',
        );

        $this->repository->save(
            'some.saga',
            '{"some":"updated"}',
            new DateTimeImmutable('2025-10-10 13:30:12'),
            0,
            '01K76GDQ5RT71G7HQVNR264KD4',
            '01K7Q0JGY9ZMX11K75AAY5J78R',
        );

        $saga = $this->repository->get('some.saga', '01K76GDQ5RT71G7HQVNR264KD4');

        self::assertSame('01K7Q083CX4T7Z0NT5CKEX8NEJ', $saga->id);
        self::assertSame('some.saga', $saga->sagaName);
        self::assertSame(['01K76GDQ5RT71G7HQVNR264KD4', '01K7Q0JGY9ZMX11K75AAY5J78R'], $saga->sagaIds);
        self::assertSame('{"some":"updated"}', $saga->payload);
        self::assertEquals(new DateTimeImmutable('2025-10-10 12:00:34'), $saga->createdAt);
        self::assertEquals(new DateTimeImmutable('2025-10-10 13:30:12'), $saga->updatedAt);
        self::assertSame(1, $saga->revision);
    }

    #[Test]
    public function itShouldRejectSaveWithStaleRevision(): void
    {
        $this->identityGenerator->ids[] = 'saga-1';

        $this->repository->save('some.saga', '{"waitlist":[]}', new DateTimeImmutable(), null, 'course-1');

        // Two processes loaded revision 0; the first one saves
        $this->repository->save('some.saga', '{"waitlist":["S1"]}', new DateTimeImmutable(), 0, 'course-1');

        try {
            $this->repository->save('some.saga', '{"waitlist":["S2"]}', new DateTimeImmutable(), 0, 'course-1');

            self::fail('Expected RdbmsSagaConcurrencyException');
        } catch (RdbmsSagaConcurrencyException) {
        }

        $saga = $this->repository->get('some.saga', 'course-1');

        self::assertSame('{"waitlist":["S1"]}', $saga->payload);
        self::assertSame(1, $saga->revision);
    }

    #[Test]
    public function itShouldRejectCreatingSagaForSagaIdInUse(): void
    {
        $this->identityGenerator->ids = ['saga-1', 'saga-2'];

        // Two processes didn't find the saga and both create it
        $this->repository->save('some.saga', '{"waitlist":["S1"]}', new DateTimeImmutable(), null, 'course-1');

        try {
            $this->repository->save('some.saga', '{"waitlist":["S2"]}', new DateTimeImmutable(), null, 'course-1');

            self::fail('Expected RdbmsSagaConcurrencyException');
        } catch (RdbmsSagaConcurrencyException) {
        }

        self::assertSame('{"waitlist":["S1"]}', $this->repository->get('some.saga', 'course-1')->payload);
        self::assertEquals(1, $this->connection->fetchOne('SELECT COUNT(*) FROM saga_store'));
    }

    #[Test]
    public function itShouldAllowSameSagaIdForDifferentSagaNames(): void
    {
        $this->identityGenerator->ids = ['saga-1', 'saga-2'];

        $this->repository->save('some.saga', '{"saga":"some"}', new DateTimeImmutable(), null, 'course-1');
        $this->repository->save('other.saga', '{"saga":"other"}', new DateTimeImmutable(), null, 'course-1');

        self::assertSame('{"saga":"some"}', $this->repository->get('some.saga', 'course-1')->payload);
        self::assertSame('{"saga":"other"}', $this->repository->get('other.saga', 'course-1')->payload);
    }

    #[Test]
    public function itShouldRejectSagaIdThatBelongsToAnotherSaga(): void
    {
        $this->identityGenerator->ids = ['saga-1', 'saga-2'];

        $this->repository->save('some.saga', '{"saga":"a"}', new DateTimeImmutable(), null, 'order-1');
        $this->repository->save('some.saga', '{"saga":"b"}', new DateTimeImmutable(), null, 'payment-1');

        try {
            // Saga A claims payment-1, which belongs to saga B
            $this->repository->save('some.saga', '{"saga":"a2"}', new DateTimeImmutable(), 0, 'order-1', 'payment-1');

            self::fail('Expected RdbmsSagaConcurrencyException');
        } catch (RdbmsSagaConcurrencyException) {
        }

        $sagaA = $this->repository->get('some.saga', 'order-1');

        self::assertSame('{"saga":"a"}', $sagaA->payload);
        self::assertSame(['order-1'], $sagaA->sagaIds);
        self::assertSame(0, $sagaA->revision);
    }

    #[Test]
    public function itShouldRunWithinOuterTransactionWithoutCommittingIt(): void
    {
        $this->identityGenerator->ids[] = 'saga-1';

        $this->connection->beginTransaction();

        $this->repository->save('some.saga', '{"saga":"outer"}', new DateTimeImmutable(), null, 'course-1');

        self::assertSame(1, $this->connection->getTransactionNestingLevel());

        $this->connection->rollBack();

        self::expectException(RdbmsSagaNotFoundException::class);

        $this->repository->get('some.saga', 'course-1');
    }
}
