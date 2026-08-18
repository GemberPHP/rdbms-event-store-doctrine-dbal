<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Saga;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSaga;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaStoreRepository;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaNotFoundException;
use Gember\DependencyContracts\Util\Generator\Identity\IdentityGenerator;
use Gember\RdbmsEventStoreDoctrineDbal\Saga\TableSchema\SagaStoreLockTableSchema;
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
 *     updatedAt: null|string
 * }
 */
final readonly class DoctrineRdbmsSagaStoreRepository implements RdbmsSagaStoreRepository
{
    public function __construct(
        private Connection $connection,
        private SagaStoreTableSchema $sagaStoreTableSchema,
        private SagaStoreRelationTableSchema $sagaStoreRelationTableSchema,
        private SagaStoreLockTableSchema $sagaStoreLockTableSchema,
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
         *     updatedAt: string|null
         * }|false $row */
        $row = $this->connection->createQueryBuilder()
            ->select(
                <<<DQL
                ss.{$sagaStoreSchema->idFieldName} as id,
                ss.{$sagaStoreSchema->sagaNameFieldName} as sagaName,
                ss.{$sagaStoreSchema->payloadFieldName} as payload,
                ss.{$sagaStoreSchema->createdAtFieldName} as createdAt,
                ss.{$sagaStoreSchema->updatedAtFieldName} as updatedAt
                DQL
            )
            ->from($sagaStoreSchema->tableName, 'ss')
            ->join('ss', $sagaStoreRelationSchema->tableName, 'ssr', sprintf(
                'ss.%s = ssr.%s',
                $sagaStoreSchema->idFieldName,
                $sagaStoreRelationSchema->idFieldName,
            ))
            ->where(sprintf('ssr.%s IN (:sagaIds)', $sagaStoreRelationSchema->sagaIdFieldName))
            ->andWhere(sprintf('ss.%s = :sagaName', $sagaStoreSchema->sagaNameFieldName))
            ->setParameter(
                'sagaIds',
                array_map(fn($sagaId) => (string) $sagaId, $sagaIds),
                ArrayParameterType::STRING,
            )
            ->setParameter('sagaName', $sagaName)
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
        Stringable|string ...$sagaIds,
    ): RdbmsSaga {
        $this->connection->beginTransaction();

        try {
            /*
             * Serialize concurrent writers for the same saga boundary,
             * ensuring a lockable row exists even when the saga doesn't yet.
             */
            $this->acquireBoundaryLock($sagaName, ...$sagaIds);

            /*
             * Find existing saga with FOR UPDATE to lock the row, so
             * concurrent updaters block until this transaction commits.
             */
            $previous = $this->findForUpdate($sagaName, ...$sagaIds);

            if ($previous === null) {
                $result = $this->create($sagaName, $payload, $now, ...$sagaIds);
            } else {
                $result = $this->update($previous, $sagaName, $payload, $now, ...$sagaIds);
            }

            $this->connection->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }

    private function findForUpdate(string $sagaName, Stringable|string ...$sagaIds): ?RdbmsSaga
    {
        $sagaStoreSchema = $this->sagaStoreTableSchema;
        $sagaStoreRelationSchema = $this->sagaStoreRelationTableSchema;

        $sagaIdStrings = array_map(fn($sagaId) => (string) $sagaId, $sagaIds);

        try {
            /** @var array{
             *     id: string,
             *     sagaName: string,
             *     payload: string,
             *     createdAt: string,
             *     updatedAt: string|null
             * }|false $row */
            $row = $this->connection->createQueryBuilder()
                ->select(
                    <<<DQL
                    ss.{$sagaStoreSchema->idFieldName} as id,
                    ss.{$sagaStoreSchema->sagaNameFieldName} as sagaName,
                    ss.{$sagaStoreSchema->payloadFieldName} as payload,
                    ss.{$sagaStoreSchema->createdAtFieldName} as createdAt,
                    ss.{$sagaStoreSchema->updatedAtFieldName} as updatedAt
                    DQL
                )
                ->from($sagaStoreSchema->tableName, 'ss')
                ->join('ss', $sagaStoreRelationSchema->tableName, 'ssr', sprintf(
                    'ss.%s = ssr.%s',
                    $sagaStoreSchema->idFieldName,
                    $sagaStoreRelationSchema->idFieldName,
                ))
                ->where(sprintf('ssr.%s IN (:sagaIds)', $sagaStoreRelationSchema->sagaIdFieldName))
                ->andWhere(sprintf('ss.%s = :sagaName', $sagaStoreSchema->sagaNameFieldName))
                ->setParameter('sagaIds', $sagaIdStrings, ArrayParameterType::STRING)
                ->setParameter('sagaName', $sagaName)
                ->setMaxResults(1)
                ->forUpdate()
                ->executeQuery()
                ->fetchAssociative();
        } catch (NotSupported) {
            // Platform does not support FOR UPDATE (e.g. SQLite).
            // SQLite serializes writes implicitly, so this is safe.
            /** @var array{
             *     id: string,
             *     sagaName: string,
             *     payload: string,
             *     createdAt: string,
             *     updatedAt: string|null
             * }|false $row */
            $row = $this->connection->createQueryBuilder()
                ->select(
                    <<<DQL
                    ss.{$sagaStoreSchema->idFieldName} as id,
                    ss.{$sagaStoreSchema->sagaNameFieldName} as sagaName,
                    ss.{$sagaStoreSchema->payloadFieldName} as payload,
                    ss.{$sagaStoreSchema->createdAtFieldName} as createdAt,
                    ss.{$sagaStoreSchema->updatedAtFieldName} as updatedAt
                    DQL
                )
                ->from($sagaStoreSchema->tableName, 'ss')
                ->join('ss', $sagaStoreRelationSchema->tableName, 'ssr', sprintf(
                    'ss.%s = ssr.%s',
                    $sagaStoreSchema->idFieldName,
                    $sagaStoreRelationSchema->idFieldName,
                ))
                ->where(sprintf('ssr.%s IN (:sagaIds)', $sagaStoreRelationSchema->sagaIdFieldName))
                ->andWhere(sprintf('ss.%s = :sagaName', $sagaStoreSchema->sagaNameFieldName))
                ->setParameter('sagaIds', $sagaIdStrings, ArrayParameterType::STRING)
                ->setParameter('sagaName', $sagaName)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        }

        if (!$row) {
            return null;
        }

        return $this->hydrateSaga($row);
    }

    /**
     * @param array{
     *     id: string,
     *     sagaName: string,
     *     payload: string,
     *     createdAt: string,
     *     updatedAt: string|null
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
        $sagaStoreRelationSchema = $this->sagaStoreRelationTableSchema;

        $this->connection->createQueryBuilder()
            ->insert($sagaStoreSchema->tableName)
            ->setValue($sagaStoreSchema->idFieldName, ':id')
            ->setValue($sagaStoreSchema->sagaNameFieldName, ':sagaName')
            ->setValue($sagaStoreSchema->payloadFieldName, ':payload')
            ->setValue($sagaStoreSchema->createdAtFieldName, ':createdAt')
            ->setParameters([
                'id' => $id,
                'sagaName' => $sagaName,
                'payload' => $payload,
                'createdAt' => $now->format($sagaStoreSchema->createdAtFieldFormat),
            ])
            ->executeStatement();

        foreach ($sagaIds as $sagaId) {
            $this->connection->createQueryBuilder()
                ->insert($sagaStoreRelationSchema->tableName)
                ->setValue($sagaStoreRelationSchema->idFieldName, ':id')
                ->setValue($sagaStoreRelationSchema->sagaIdFieldName, ':sagaId')
                ->setParameters([
                    'id' => $id,
                    'sagaId' => $sagaId,
                ])
                ->executeStatement();
        }

        return new RdbmsSaga(
            $id,
            $sagaName,
            array_values($sagaIds),
            $payload,
            $now,
            null,
        );
    }

    private function update(
        RdbmsSaga $previous,
        string $sagaName,
        string $payload,
        DateTimeImmutable $now,
        Stringable|string ...$sagaIds,
    ): RdbmsSaga {
        $sagaStoreSchema = $this->sagaStoreTableSchema;
        $sagaStoreRelationSchema = $this->sagaStoreRelationTableSchema;

        $this->connection->createQueryBuilder()
            ->update($sagaStoreSchema->tableName)
            ->where(sprintf('%s = :id', $sagaStoreSchema->idFieldName))
            ->set($sagaStoreSchema->payloadFieldName, ':payload')
            ->set($sagaStoreSchema->updatedAtFieldName, ':updatedAt')
            ->setParameter('id', $previous->id)
            ->setParameter('payload', $payload)
            ->setParameter('updatedAt', $now->format($sagaStoreSchema->updatedAtFieldFormat))
            ->executeStatement();

        $this->connection->createQueryBuilder()
            ->delete($sagaStoreRelationSchema->tableName)
            ->where(sprintf('%s = :id', $sagaStoreRelationSchema->idFieldName))
            ->setParameter('id', $previous->id)
            ->executeStatement();

        foreach ($sagaIds as $sagaId) {
            $this->connection->createQueryBuilder()
                ->insert($sagaStoreRelationSchema->tableName)
                ->setValue($sagaStoreRelationSchema->idFieldName, ':id')
                ->setValue($sagaStoreRelationSchema->sagaIdFieldName, ':sagaId')
                ->setParameters([
                    'id' => $previous->id,
                    'sagaId' => $sagaId,
                ])
                ->executeStatement();
        }

        return new RdbmsSaga(
            $previous->id,
            $sagaName,
            array_values($sagaIds),
            $payload,
            $previous->createdAt,
            $now,
        );
    }

    private function acquireBoundaryLock(string $sagaName, Stringable|string ...$sagaIds): void
    {
        $lockSchema = $this->sagaStoreLockTableSchema;
        $hash = $this->buildBoundaryHash($sagaName, ...$sagaIds);

        try {
            $this->connection->createQueryBuilder()
                ->insert($lockSchema->tableName)
                ->setValue($lockSchema->boundaryHashFieldName, ':hash')
                ->setParameter('hash', $hash)
                ->executeStatement();
        } catch (UniqueConstraintViolationException) {
            // Row already exists, proceed to lock it
        }

        try {
            $this->connection->createQueryBuilder()
                ->select($lockSchema->boundaryHashFieldName)
                ->from($lockSchema->tableName)
                ->where(sprintf('%s = :hash', $lockSchema->boundaryHashFieldName))
                ->setParameter('hash', $hash)
                ->forUpdate()
                ->executeQuery();
        } catch (NotSupported) {
            // Platform does not support FOR UPDATE (e.g. SQLite).
            // SQLite serializes writes implicitly, so this is safe.
        }
    }

    private function buildBoundaryHash(string $sagaName, Stringable|string ...$sagaIds): string
    {
        $ids = array_map(strval(...), $sagaIds);
        sort($ids);

        return hash('sha256', implode("\0", [$sagaName, "\1", ...$ids]));
    }
}
