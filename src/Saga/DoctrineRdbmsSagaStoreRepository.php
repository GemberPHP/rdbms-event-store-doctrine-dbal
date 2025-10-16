<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Saga;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSaga;
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
 *     updatedAt: null|string
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

        /** @var list<array{
         *     id: string,
         *     sagaId: string,
         *     sagaName: string,
         *     payload: string,
         *     createdAt: string,
         *     updatedAt: string|null
         * }> $row */
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

        $sagaIdRows = $this->connection->createQueryBuilder()
            ->select(
                <<<DQL
                ssr.{$sagaStoreRelationSchema->sagaIdFieldName} as sagaId
                DQL
            )
            ->from($sagaStoreRelationSchema->tableName, 'ssr')
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var list<SagaRow> $payload */
        $payload = $row;
        $payload['sagaIds'] = array_map(fn($sagaIdRow) => $sagaIdRow['sagaId'], $sagaIdRows);

        return $this->sagaFactory->createFromRow($payload);
    }

    #[Override]
    public function save(
        string $sagaName,
        string $payload,
        DateTimeImmutable $now,
        Stringable|string ...$sagaIds,
    ): RdbmsSaga {
        try {
            $previous = $this->get($sagaName, ...$sagaIds);
        } catch (RdbmsSagaNotFoundException) {
            return $this->create($sagaName, $payload, $now, ...$sagaIds);
        }

        return $this->update($previous, $sagaName, $payload, $now, ...$sagaIds);
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

        $this->connection->beginTransaction();

        try {
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

            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
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

        $this->connection->beginTransaction();

        try {
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

            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
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
}
