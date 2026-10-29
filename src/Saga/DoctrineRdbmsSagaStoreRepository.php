<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Saga;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSaga;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaConcurrencyException;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaStoreRepository;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaNotFoundException;
use Gember\DependencyContracts\Util\Generator\Identity\IdentityGenerator;
use Gember\RdbmsEventStoreDoctrineDbal\Saga\TableSchema\SagaStoreRelationTableSchema;
use Gember\RdbmsEventStoreDoctrineDbal\Saga\TableSchema\SagaStoreTableSchema;
use Override;
use Stringable;
use DateTimeImmutable;
use Throwable;

/**
 * @phpstan-type SagaRow array{
 *     id: string,
 *     sagaIds: list<string>,
 *     sagaName: string,
 *     payload: string,
 *     createdAt: string,
 *     updatedAt: null|string,
 *     revision: int|string,
 * }
 */
final readonly class DoctrineRdbmsSagaStoreRepository implements RdbmsSagaStoreRepository
{
    public function __construct(
        private Connection $connection,
        private SagaStoreTableSchema $sagaStoreTableSchema,
        private SagaStoreRelationTableSchema $sagaStoreRelationTableSchema,
        private DoctrineDbalRdbmsSagaFactory $sagaFactory,
        private IdentityGenerator $identityGenerator,
    ) {}

    #[Override]
    public function get(string $sagaName, Stringable|string ...$sagaIds): RdbmsSaga
    {
        $sagaStoreSchema = $this->sagaStoreTableSchema;
        $sagaStoreRelationSchema = $this->sagaStoreRelationTableSchema;

        /** @var array{
         *     id: string,
         *     sagaName: string,
         *     payload: string,
         *     createdAt: string,
         *     updatedAt: string|null,
         *     revision: int|string
         * }|false $row */
        $row = $this->connection->createQueryBuilder()
            ->select(
                <<<DQL
                ss.{$sagaStoreSchema->idFieldName} as id,
                ss.{$sagaStoreSchema->sagaNameFieldName} as sagaName,
                ss.{$sagaStoreSchema->payloadFieldName} as payload,
                ss.{$sagaStoreSchema->createdAtFieldName} as createdAt,
                ss.{$sagaStoreSchema->updatedAtFieldName} as updatedAt,
                ss.{$sagaStoreSchema->revisionFieldName} as revision
                DQL
            )
            ->from($sagaStoreSchema->tableName, 'ss')
            ->join('ss', $sagaStoreRelationSchema->tableName, 'ssr', sprintf(
                'ss.%s = ssr.%s',
                $sagaStoreSchema->idFieldName,
                $sagaStoreRelationSchema->idFieldName,
            ))
            ->where(sprintf('ssr.%s = :sagaName', $sagaStoreRelationSchema->sagaNameFieldName))
            ->andWhere(sprintf('ssr.%s IN (:sagaIds)', $sagaStoreRelationSchema->sagaIdFieldName))
            ->setParameter('sagaName', $sagaName)
            ->setParameter(
                'sagaIds',
                array_map(fn($sagaId) => (string) $sagaId, $sagaIds),
                ArrayParameterType::STRING,
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$row) {
            throw RdbmsSagaNotFoundException::create($sagaName, ...$sagaIds);
        }

        return $this->hydrateSaga($row);
    }

    #[Override]
    public function save(
        string $sagaName,
        string $payload,
        DateTimeImmutable $now,
        ?int $expectedRevision,
        Stringable|string ...$sagaIds,
    ): RdbmsSaga {
        /*
         * When called inside a transaction (e.g. a transactional decorator), run within it rather than
         * opening a savepoint: the caller owns atomicity and rollback. A savepoint would break on a
         * deadlock, as the database then rolls back the whole transaction including the savepoint.
         */
        $ownsTransaction = !$this->connection->isTransactionActive();

        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $result = $expectedRevision === null
                ? $this->create($sagaName, $payload, $now, ...$sagaIds)
                : $this->update($sagaName, $payload, $now, $expectedRevision, ...$sagaIds);

            if ($ownsTransaction) {
                $this->connection->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                try {
                    $this->connection->rollBack();
                } catch (Throwable) {
                    // Rethrow the original exception rather than masking it with the rollback failure
                }
            }

            /*
             * A saga id already taken (concurrent creation, or the id belongs to another saga) or a deadlock
             * between concurrent saves: the saga must be reloaded and the change retried.
             */
            if ($exception instanceof UniqueConstraintViolationException || $exception instanceof DeadlockException) {
                throw RdbmsSagaConcurrencyException::create($exception);
            }

            throw $exception;
        }
    }

    /**
     * @param array{
     *     id: string,
     *     sagaName: string,
     *     payload: string,
     *     createdAt: string,
     *     updatedAt: string|null,
     *     revision: int|string
     * } $row
     */
    private function hydrateSaga(array $row): RdbmsSaga
    {
        $sagaStoreRelationSchema = $this->sagaStoreRelationTableSchema;

        $sagaIdRows = $this->connection->createQueryBuilder()
            ->select(
                <<<DQL
                ssr.{$sagaStoreRelationSchema->sagaIdFieldName} as sagaId
                DQL
            )
            ->from($sagaStoreRelationSchema->tableName, 'ssr')
            ->where(sprintf('ssr.%s = :id', $sagaStoreRelationSchema->idFieldName))
            ->setParameter('id', $row['id'])
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var SagaRow $payload */
        $payload = $row;
        /** @var list<string> $sagaIdsFromRow */
        $sagaIdsFromRow = array_map(fn($sagaIdRow) => $sagaIdRow['sagaId'], $sagaIdRows);
        $payload['sagaIds'] = $sagaIdsFromRow;

        return $this->sagaFactory->createFromRow($payload);
    }

    private function create(
        string $sagaName,
        string $payload,
        DateTimeImmutable $now,
        Stringable|string ...$sagaIds,
    ): RdbmsSaga {
        $id = $this->identityGenerator->generate();

        $sagaStoreSchema = $this->sagaStoreTableSchema;

        $this->connection->createQueryBuilder()
            ->insert($sagaStoreSchema->tableName)
            ->setValue($sagaStoreSchema->idFieldName, ':id')
            ->setValue($sagaStoreSchema->sagaNameFieldName, ':sagaName')
            ->setValue($sagaStoreSchema->payloadFieldName, ':payload')
            ->setValue($sagaStoreSchema->createdAtFieldName, ':createdAt')
            ->setValue($sagaStoreSchema->revisionFieldName, ':revision')
            ->setParameters([
                'id' => $id,
                'sagaName' => $sagaName,
                'payload' => $payload,
                'createdAt' => $now->format($sagaStoreSchema->createdAtFieldFormat),
                'revision' => 0,
            ])
            ->executeStatement();

        // The unique (saga name, saga id) key rejects a saga created concurrently for the same saga id
        $this->insertSagaIds($id, $sagaName, ...$sagaIds);

        return new RdbmsSaga(
            $id,
            $sagaName,
            array_values($sagaIds),
            $payload,
            $now,
            null,
            0,
        );
    }

    /**
     * @throws RdbmsSagaConcurrencyException
     */
    private function update(
        string $sagaName,
        string $payload,
        DateTimeImmutable $now,
        int $expectedRevision,
        Stringable|string ...$sagaIds,
    ): RdbmsSaga {
        $sagaStoreSchema = $this->sagaStoreTableSchema;
        $sagaStoreRelationSchema = $this->sagaStoreRelationTableSchema;

        try {
            $previous = $this->get($sagaName, ...$sagaIds);
        } catch (RdbmsSagaNotFoundException $exception) {
            // The saga was loaded, but none of its saga ids find it anymore: changed concurrently
            throw RdbmsSagaConcurrencyException::create($exception);
        }

        /*
         * Only update the saga when it's still at the revision it was loaded with. The update reads the latest
         * committed row (also inside a transaction with an older snapshot), so a concurrent save is always detected.
         */
        $updated = $this->connection->createQueryBuilder()
            ->update($sagaStoreSchema->tableName)
            ->set($sagaStoreSchema->payloadFieldName, ':payload')
            ->set($sagaStoreSchema->updatedAtFieldName, ':updatedAt')
            ->set($sagaStoreSchema->revisionFieldName, sprintf('%s + 1', $sagaStoreSchema->revisionFieldName))
            ->where(sprintf('%s = :id', $sagaStoreSchema->idFieldName))
            ->andWhere(sprintf('%s = :expectedRevision', $sagaStoreSchema->revisionFieldName))
            ->setParameter('payload', $payload)
            ->setParameter('updatedAt', $now->format($sagaStoreSchema->updatedAtFieldFormat))
            ->setParameter('id', $previous->id)
            ->setParameter('expectedRevision', $expectedRevision)
            ->executeStatement();

        if ($updated === 0) {
            throw RdbmsSagaConcurrencyException::create();
        }

        $this->connection->createQueryBuilder()
            ->delete($sagaStoreRelationSchema->tableName)
            ->where(sprintf('%s = :id', $sagaStoreRelationSchema->idFieldName))
            ->setParameter('id', $previous->id)
            ->executeStatement();

        $this->insertSagaIds($previous->id, $sagaName, ...$sagaIds);

        return new RdbmsSaga(
            $previous->id,
            $sagaName,
            array_values($sagaIds),
            $payload,
            $previous->createdAt,
            $now,
            $expectedRevision + 1,
        );
    }

    private function insertSagaIds(string $id, string $sagaName, Stringable|string ...$sagaIds): void
    {
        $sagaStoreRelationSchema = $this->sagaStoreRelationTableSchema;

        foreach ($sagaIds as $sagaId) {
            $this->connection->createQueryBuilder()
                ->insert($sagaStoreRelationSchema->tableName)
                ->setValue($sagaStoreRelationSchema->idFieldName, ':id')
                ->setValue($sagaStoreRelationSchema->sagaNameFieldName, ':sagaName')
                ->setValue($sagaStoreRelationSchema->sagaIdFieldName, ':sagaId')
                ->setParameters([
                    'id' => $id,
                    'sagaName' => $sagaName,
                    'sagaId' => (string) $sagaId,
                ])
                ->executeStatement();
        }
    }
}
