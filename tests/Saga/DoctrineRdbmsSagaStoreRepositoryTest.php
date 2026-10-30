<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Saga;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaConcurrencyException;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaIdAlreadyInUseException;
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

        $created = $this->repository->save(
            'some.saga',
            '{"some":"data"}',
            new DateTimeImmutable('2025-10-10 12:00:34'),
            null,
            '01K76GDQ5RT71G7HQVNR264KD4',
            '01K7Q0GR8ABHBZG8QCGTXJXJ7T',
        );

        $saved = $this->repository->save(
            'some.saga',
            '{"some":"updated"}',
            new DateTimeImmutable('2025-10-10 13:30:12'),
            $created,
            '01K76GDQ5RT71G7HQVNR264KD4',
            '01K7Q0JGY9ZMX11K75AAY5J78R',
        );

        $saga = $this->repository->get('some.saga', '01K76GDQ5RT71G7HQVNR264KD4');

        // The returned saga is the stored one: saving it again is based on it
        self::assertEquals($saga, $saved);

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
        $loadedByFirst = $this->repository->get('some.saga', 'course-1');
        $loadedBySecond = $this->repository->get('some.saga', 'course-1');

        $this->repository->save('some.saga', '{"waitlist":["S1"]}', new DateTimeImmutable(), $loadedByFirst, 'course-1');

        try {
            $this->repository->save('some.saga', '{"waitlist":["S2"]}', new DateTimeImmutable(), $loadedBySecond, 'course-1');

            self::fail('Expected RdbmsSagaConcurrencyException');
        } catch (RdbmsSagaConcurrencyException) {
        }

        $saga = $this->repository->get('some.saga', 'course-1');

        self::assertSame('{"waitlist":["S1"]}', $saga->payload);
        self::assertSame(1, $saga->revision);
    }

    #[Test]
    public function itShouldRejectConcurrentCreationOfSameSagaAsRetryable(): void
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
    public function itShouldRejectConcurrentCreationOwningOnlySomeOfTheSagaIdsAsRetryable(): void
    {
        $this->identityGenerator->ids = ['saga-1', 'saga-2'];

        // Two first events of the same saga handled concurrently: one carries only the order id, the other also the
        // payment id. Neither found the saga; the first creates it.
        $this->repository->save('order.saga', '{"order":1}', new DateTimeImmutable(), null, 'order-1');

        try {
            $this->repository->save('order.saga', '{"order":2}', new DateTimeImmutable(), null, 'order-1', 'payment-1');

            self::fail('Expected RdbmsSagaConcurrencyException');
        } catch (RdbmsSagaConcurrencyException) {
        }

        // Retrying finds the saga created concurrently
        self::assertSame('{"order":1}', $this->repository->get('order.saga', 'order-1', 'payment-1')->payload);
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
    public function itShouldRejectTakingOverSagaIdOfAnotherSagaWithoutChangingIt(): void
    {
        $this->identityGenerator->ids = ['saga-a', 'saga-b'];

        $sagaA = $this->repository->save('some.saga', '{"saga":"a"}', new DateTimeImmutable(), null, 'z-order');
        $this->repository->save('some.saga', '{"saga":"b"}', new DateTimeImmutable(), null, 'a-payment');

        // Saga A (revision 0) takes over a-payment, which belongs to saga B (also revision 0)
        try {
            $this->repository->save('some.saga', '{"saga":"a2"}', new DateTimeImmutable(), $sagaA, 'a-payment');

            self::fail('Expected RdbmsSagaIdAlreadyInUseException');
        } catch (RdbmsSagaIdAlreadyInUseException $exception) {
            self::assertStringContainsString('a-payment', $exception->getMessage());
        }

        $sagaB = $this->repository->get('some.saga', 'a-payment');

        self::assertSame('saga-b', $sagaB->id);
        self::assertSame('{"saga":"b"}', $sagaB->payload);
        self::assertSame(0, $sagaB->revision);
        self::assertSame('{"saga":"a"}', $this->repository->get('some.saga', 'z-order')->payload);
    }

    #[Test]
    public function itShouldNameTheTakenSagaIdWhenKeepingItsOwnSagaIds(): void
    {
        $this->identityGenerator->ids = ['saga-a', 'saga-b'];

        $sagaA = $this->repository->save('some.saga', '{"saga":"a"}', new DateTimeImmutable(), null, 'a-own');
        $this->repository->save('some.saga', '{"saga":"b"}', new DateTimeImmutable(), null, 'b-taken');

        try {
            $this->repository->save('some.saga', '{"saga":"a2"}', new DateTimeImmutable(), $sagaA, 'a-own', 'b-taken');

            self::fail('Expected RdbmsSagaIdAlreadyInUseException');
        } catch (RdbmsSagaIdAlreadyInUseException $exception) {
            self::assertStringContainsString('b-taken', $exception->getMessage());
            self::assertStringNotContainsString('a-own', $exception->getMessage());
        }
    }

    #[Test]
    public function itShouldOnlyUpdateTheSagaItWasLoadedFrom(): void
    {
        $this->identityGenerator->ids = ['saga-a', 'saga-b'];

        $sagaA = $this->repository->save('some.saga', '{"saga":"a"}', new DateTimeImmutable(), null, 'a-1');
        $this->repository->save('some.saga', '{"saga":"b"}', new DateTimeImmutable(), null, 'b-1');

        $this->repository->save('some.saga', '{"saga":"a2"}', new DateTimeImmutable(), $sagaA, 'a-1');

        $sagaB = $this->repository->get('some.saga', 'b-1');

        self::assertSame('{"saga":"b"}', $sagaB->payload);
        self::assertSame(0, $sagaB->revision);
    }

    #[Test]
    public function itShouldOnlyRemoveSagaIdsOfTheSagaItself(): void
    {
        $this->identityGenerator->ids = ['saga-a', 'saga-b'];

        $sagaA = $this->repository->save('some.saga', '{"saga":"a"}', new DateTimeImmutable(), null, 'order-1');
        $this->repository->save('other.saga', '{"saga":"b"}', new DateTimeImmutable(), null, 'order-1');

        $this->repository->save('some.saga', '{"saga":"a2"}', new DateTimeImmutable(), $sagaA, 'order-2');

        self::assertSame(['order-1'], $this->repository->get('other.saga', 'order-1')->sagaIds);
    }

    #[Test]
    public function itShouldReplaceAllSagaIds(): void
    {
        $this->identityGenerator->ids[] = 'saga-1';

        $created = $this->repository->save('some.saga', '{"step":1}', new DateTimeImmutable(), null, 'order-1');
        $this->repository->save('some.saga', '{"step":2}', new DateTimeImmutable(), $created, 'order-2');

        $saga = $this->repository->get('some.saga', 'order-2');

        self::assertSame('saga-1', $saga->id);
        self::assertSame(['order-2'], $saga->sagaIds);
        self::assertSame(1, $saga->revision);

        self::expectException(RdbmsSagaNotFoundException::class);

        $this->repository->get('some.saga', 'order-1');
    }

    #[Test]
    public function itShouldIgnoreDuplicateSagaIdValues(): void
    {
        $this->identityGenerator->ids[] = 'saga-1';

        $saga = $this->repository->save('some.saga', '{}', new DateTimeImmutable(), null, 'same-id', 'same-id');

        self::assertSame(['same-id'], $saga->sagaIds);
        self::assertSame(['same-id'], $this->repository->get('some.saga', 'same-id')->sagaIds);
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
