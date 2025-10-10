<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Saga;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaNotFoundException;
use Gember\RdbmsEventStoreDoctrineDbal\Saga\DoctrineDbalRdbmsSagaFactory;
use Gember\RdbmsEventStoreDoctrineDbal\Saga\DoctrineRdbmsSagaStoreRepository;
use Gember\RdbmsEventStoreDoctrineDbal\Saga\TableSchema\SagaTableSchemaFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Override;
use DateTimeImmutable;

/**
 * @internal
 */
final class DoctrineRdbmsSagaStoreRepositoryTest extends TestCase
{
    private DoctrineRdbmsSagaStoreRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DriverManager::getConnection((new DsnParser())->parse('pdo-sqlite:///:memory:'));
        $connection->executeStatement((string) file_get_contents(__DIR__ . '/../schema.sql'));

        $this->repository = new DoctrineRdbmsSagaStoreRepository(
            $connection,
            SagaTableSchemaFactory::createDefaultSagaStore(),
            new DoctrineDbalRdbmsSagaFactory(),
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
        $this->repository->save(
            'some.saga',
            '01K76GDQ5RT71G7HQVNR264KD4',
            '{"some":"data"}',
            new DateTimeImmutable('2025-10-10 12:00:34'),
        );

        $saga = $this->repository->get('some.saga', '01K76GDQ5RT71G7HQVNR264KD4');

        self::assertSame('some.saga', $saga->sagaName);
        self::assertSame('01K76GDQ5RT71G7HQVNR264KD4', $saga->sagaId);
        self::assertSame('{"some":"data"}', $saga->payload);
        self::assertEquals(new DateTimeImmutable('2025-10-10 12:00:34'), $saga->createdAt);
        self::assertNull($saga->updatedAt);
    }

    #[Test]
    public function itShouldSaveExistingSaga(): void
    {
        $this->repository->save(
            'some.saga',
            '01K76GDQ5RT71G7HQVNR264KD4',
            '{"some":"data"}',
            new DateTimeImmutable('2025-10-10 12:00:34'),
        );

        $this->repository->save(
            'some.saga',
            '01K76GDQ5RT71G7HQVNR264KD4',
            '{"some":"updated"}',
            new DateTimeImmutable('2025-10-10 13:30:12'),
        );

        $saga = $this->repository->get('some.saga', '01K76GDQ5RT71G7HQVNR264KD4');

        self::assertSame('some.saga', $saga->sagaName);
        self::assertSame('01K76GDQ5RT71G7HQVNR264KD4', $saga->sagaId);
        self::assertSame('{"some":"updated"}', $saga->payload);
        self::assertEquals(new DateTimeImmutable('2025-10-10 12:00:34'), $saga->createdAt);
        self::assertEquals(new DateTimeImmutable('2025-10-10 13:30:12'), $saga->updatedAt);
    }
}
