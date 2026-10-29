<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Saga;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSaga;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaConcurrencyException;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaIdAlreadyInUseException;
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
        ?RdbmsSaga $previous,
        Stringable|string ...$sagaIds,
    ): RdbmsSaga {
        $sagaIds = $this->normalizeSagaIds(...$sagaIds);

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
            $result = $previous === null
                ? $this->create($sagaName, $payload, $now, $sagaIds)
                : $this->update($previous, $payload, $now, $sagaIds);

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

            if ($exception instanceof UniqueConstraintViolationException) {
                throw $this->classifySagaIdConflict($exception, $sagaName, $previous, $sagaIds, !$ownsTransaction);
            }

            // A deadlock between concurrent saves: the saga must be reloaded and the change retried
            if ($exception instanceof DeadlockException) {
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

    /**
     * @param list<string> $sagaIds
     */
    private function create(string $sagaName, string $payload, DateTimeImmutable $now, array $sagaIds): RdbmsSaga
    {
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

        // The unique (saga name, saga id) key rejects a saga id that already belongs to a saga
        $this->insertSagaIds($id, $sagaName, $sagaIds);

        return new RdbmsSaga($id, $sagaName, $sagaIds, $payload, $now, null, 0);
    }

    /**
     * @param list<string> $sagaIds
     *
     * @throws RdbmsSagaConcurrencyException
     */
    private function update(RdbmsSaga $previous, string $payload, DateTimeImmutable $now, array $sagaIds): RdbmsSaga
    {
        $sagaStoreSchema = $this->sagaStoreTableSchema;
        $sagaStoreRelationSchema = $this->sagaStoreRelationTableSchema;

        /*
         * Only update the saga row it was loaded from, and only while it's still at the loaded revision. The update
         * reads the latest committed row (also inside a transaction with an older snapshot), so a concurrent save is
         * always detected, and it locks the row until the end of the transaction.
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
            ->setParameter('expectedRevision', $previous->revision)
            ->executeStatement();

        if ($updated === 0) {
            throw RdbmsSagaConcurrencyException::create();
        }

        /*
         * Only write the saga ids that changed. Its loaded ids are still current: changing them requires saving the
         * saga, which would have changed its revision.
         */
        $previousSagaIds = $this->normalizeSagaIds(...$previous->sagaIds);
        $removedSagaIds = array_values(array_diff($previousSagaIds, $sagaIds));
        $addedSagaIds = array_values(array_diff($sagaIds, $previousSagaIds));

        if ($removedSagaIds !== []) {
            $this->connection->createQueryBuilder()
                ->delete($sagaStoreRelationSchema->tableName)
                ->where(sprintf('%s = :id', $sagaStoreRelationSchema->idFieldName))
                ->andWhere(sprintf('%s IN (:sagaIds)', $sagaStoreRelationSchema->sagaIdFieldName))
                ->setParameter('id', $previous->id)
                ->setParameter('sagaIds', $removedSagaIds, ArrayParameterType::STRING)
                ->executeStatement();
        }

        $this->insertSagaIds($previous->id, $previous->sagaName, $addedSagaIds);

        return new RdbmsSaga(
            $previous->id,
            $previous->sagaName,
            $sagaIds,
            $payload,
            $previous->createdAt,
            $now,
            $previous->revision + 1,
        );
    }

    /**
     * @param list<string> $sagaIds
     */
    private function insertSagaIds(string $id, string $sagaName, array $sagaIds): void
    {
        if ($sagaIds === []) {
            return;
        }

        $schema = $this->sagaStoreRelationTableSchema;
        $parameters = [];

        foreach ($sagaIds as $sagaId) {
            $parameters = [...$parameters, $id, $sagaName, $sagaId];
        }

        // One statement, in sorted saga id order
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (%s, %s, %s) VALUES %s',
                $schema->tableName,
                $schema->idFieldName,
                $schema->sagaNameFieldName,
                $schema->sagaIdFieldName,
                implode(', ', array_fill(0, count($sagaIds), '(?, ?, ?)')),
            ),
            $parameters,
        );
    }

    /**
     * A saga id was already taken. When all saga ids of a new saga belong to one other saga, that saga was created
     * concurrently (e.g. two processes handling the first events of the same saga): reloading finds it, so retrying
     * is useful. Otherwise a saga id belongs to another saga, which retrying can't change.
     *
     * @param list<string> $sagaIds
     */
    private function classifySagaIdConflict(
        UniqueConstraintViolationException $exception,
        string $sagaName,
        ?RdbmsSaga $previous,
        array $sagaIds,
        bool $inOuterTransaction,
    ): RdbmsSagaConcurrencyException|RdbmsSagaIdAlreadyInUseException {
        try {
            $owners = $this->findSagaIdOwners($sagaName, $sagaIds, $inOuterTransaction);
        } catch (DeadlockException $deadlock) {
            // Looking up the owners inside the transaction deadlocked with a concurrent save: reload and retry
            return RdbmsSagaConcurrencyException::create($deadlock);
        }

        $ownId = $previous?->id;

        $conflicts = array_filter($owners, fn(string $owner) => $owner !== $ownId);

        if ($previous === null && count($owners) === count($sagaIds) && count(array_unique($owners)) === 1) {
            return RdbmsSagaConcurrencyException::create($exception);
        }

        if ($conflicts === []) {
            // The conflicting saga is gone again (rolled back): reloading and retrying may succeed
            return RdbmsSagaConcurrencyException::create($exception);
        }

        return RdbmsSagaIdAlreadyInUseException::create($sagaName, (string) array_key_first($conflicts), $exception);
    }

    /**
     * @param list<string> $sagaIds
     *
     * @return array<array-key, string> Saga row id per saga id that is in use (numeric saga ids become int keys)
     */
    private function findSagaIdOwners(string $sagaName, array $sagaIds, bool $inOuterTransaction): array
    {
        $schema = $this->sagaStoreRelationTableSchema;

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select(sprintf('%s as sagaId', $schema->sagaIdFieldName), sprintf('%s as id', $schema->idFieldName))
            ->from($schema->tableName)
            ->where(sprintf('%s = :sagaName', $schema->sagaNameFieldName))
            ->andWhere(sprintf('%s IN (:sagaIds)', $schema->sagaIdFieldName))
            ->setParameter('sagaName', $sagaName)
            ->setParameter('sagaIds', $sagaIds, ArrayParameterType::STRING);

        // Inside a transaction with an older snapshot, a locking read sees the owner committed in the meantime
        if ($inOuterTransaction && !$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $queryBuilder->forUpdate();
        }

        /** @var list<array{sagaId: string, id: string}> $rows */
        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        $owners = [];

        foreach ($rows as $row) {
            $owners[$row['sagaId']] = $row['id'];
        }

        return $owners;
    }

    /**
     * @return list<string> Unique saga ids, sorted
     */
    private function normalizeSagaIds(Stringable|string ...$sagaIds): array
    {
        $sagaIds = array_values(array_unique(array_map(fn($sagaId) => (string) $sagaId, $sagaIds)));
        sort($sagaIds, SORT_STRING);

        return $sagaIds;
    }
}
