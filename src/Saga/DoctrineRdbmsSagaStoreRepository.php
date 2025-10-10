<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Saga;

use Doctrine\DBAL\Connection;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSaga;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaStoreRepository;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaNotFoundException;
use Gember\RdbmsEventStoreDoctrineDbal\Saga\TableSchema\SagaStoreTableSchema;
use Override;
use Stringable;
use DateTimeImmutable;

/**
 * @phpstan-type SagaRow array{
 *     sagaId: string,
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
        private DoctrineDbalRdbmsSagaFactory $sagaFactory,
    ) {}

    #[Override]
    public function get(string $sagaName, Stringable|string $sagaId): RdbmsSaga
    {
        $sagaStoreSchema = $this->sagaStoreTableSchema;

        /** @var false|SagaRow $row */
        $row = $this->connection->createQueryBuilder()
            ->select(
                <<<DQL
                ss.{$sagaStoreSchema->sagaIdFieldName} as sagaId,
                ss.{$sagaStoreSchema->sagaNameFieldName} as sagaName,
                ss.{$sagaStoreSchema->payloadFieldName} as payload,
                ss.{$sagaStoreSchema->createdAtFieldName} as createdAt,
                ss.{$sagaStoreSchema->updatedAtFieldName} as updatedAt
                DQL
            )
            ->from($sagaStoreSchema->tableName, 'ss')
            ->where(sprintf('ss.%s = :sagaId', $sagaStoreSchema->sagaIdFieldName))
            ->andWhere(sprintf('ss.%s = :sagaName', $sagaStoreSchema->sagaNameFieldName))
            ->setParameter('sagaId', (string) $sagaId)
            ->setParameter('sagaName', $sagaName)
            ->executeQuery()
            ->fetchAssociative();

        if (!$row) {
            throw RdbmsSagaNotFoundException::withSagaId($sagaName, $sagaId);
        }

        return $this->sagaFactory->createFromRow($row);
    }

    #[Override]
    public function save(
        string $sagaName,
        Stringable|string $sagaId,
        string $payload,
        DateTimeImmutable $now,
    ): RdbmsSaga {
        $sagaStoreSchema = $this->sagaStoreTableSchema;

        try {
            $previous = $this->get($sagaName, $sagaId);
        } catch (RdbmsSagaNotFoundException) {
            $this->connection->createQueryBuilder()
                ->insert($sagaStoreSchema->tableName)
                ->setValue($sagaStoreSchema->sagaIdFieldName, ':sagaId')
                ->setValue($sagaStoreSchema->sagaNameFieldName, ':sagaName')
                ->setValue($sagaStoreSchema->payloadFieldName, ':payload')
                ->setValue($sagaStoreSchema->createdAtFieldName, ':createdAt')
                ->setParameters([
                    'sagaId' => $sagaId,
                    'sagaName' => $sagaName,
                    'payload' => $payload,
                    'createdAt' => $now->format($sagaStoreSchema->createdAtFieldFormat),
                ])
                ->executeStatement();

            return new RdbmsSaga(
                $sagaName,
                $sagaId,
                $payload,
                $now,
                null,
            );
        }

        $this->connection->createQueryBuilder()
            ->update($sagaStoreSchema->tableName)
            ->where(sprintf('%s = :sagaId', $sagaStoreSchema->sagaIdFieldName))
            ->andWhere(sprintf('%s = :sagaName', $sagaStoreSchema->sagaNameFieldName))
            ->set($sagaStoreSchema->payloadFieldName, ':payload')
            ->set($sagaStoreSchema->updatedAtFieldName, ':updatedAt')
            ->setParameters([
                'sagaId' => $sagaId,
                'sagaName' => $sagaName,
                'payload' => $payload,
                'updatedAt' => $now->format($sagaStoreSchema->updatedAtFieldFormat),
            ])
            ->executeStatement();

        return new RdbmsSaga(
            $sagaName,
            $sagaId,
            $payload,
            $previous->createdAt,
            $now,
        );
    }
}
