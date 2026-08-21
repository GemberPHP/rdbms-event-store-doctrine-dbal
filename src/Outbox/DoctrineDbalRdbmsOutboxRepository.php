<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Query\ForUpdate\ConflictResolutionMode;
use Gember\DependencyContracts\Outbox\Rdbms\RdbmsOutboxMessage;
use Gember\DependencyContracts\Outbox\Rdbms\RdbmsOutboxRepository;
use Gember\RdbmsEventStoreDoctrineDbal\Outbox\TableSchema\OutboxTableSchema;
use Override;

/**
 * @phpstan-type OutboxRow array{
 *     id: string,
 *     messageType: string,
 *     messageName: string,
 *     payload: string,
 *     createdAt: string,
 *     retryCount: int,
 * }
 */
final readonly class DoctrineDbalRdbmsOutboxRepository implements RdbmsOutboxRepository
{
    public function __construct(
        private Connection $connection,
        private OutboxTableSchema $tableSchema,
        private DoctrineDbalRdbmsOutboxFactory $outboxFactory,
    ) {}

    #[Override]
    public function getUnprocessedMessages(int $limit): array
    {
        $schema = $this->tableSchema;

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select(
                <<<DQL
                {$schema->idFieldName} as id,
                {$schema->messageTypeFieldName} as messageType,
                {$schema->messageNameFieldName} as messageName,
                {$schema->payloadFieldName} as payload,
                {$schema->createdAtFieldName} as createdAt,
                {$schema->retryCountFieldName} as retryCount
                DQL
            )
            ->from($schema->tableName)
            ->where(sprintf('%s IS NULL', $schema->processedAtFieldName))
            ->andWhere(sprintf('%s IS NULL', $schema->deadLetteredAtFieldName))
            ->orderBy($schema->createdAtFieldName, 'asc')
            ->addOrderBy($schema->idFieldName, 'asc')
            ->setMaxResults($limit);

        try {
            $rows = $queryBuilder
                ->forUpdate(ConflictResolutionMode::SKIP_LOCKED)
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (NotSupported) {
            // Platform does not support FOR UPDATE SKIP LOCKED (e.g. SQLite).
            // SQLite serializes writes implicitly, so re-execute without locking.
            $rows = $this->getUnprocessedMessagesWithoutLocking($limit);
        }

        /** @var list<OutboxRow> $rows */
        return array_map(
            fn(array $row) => $this->outboxFactory->createFromRow($row),
            $rows,
        );
    }

    #[Override]
    public function save(RdbmsOutboxMessage $message): void
    {
        $schema = $this->tableSchema;

        $this->connection->createQueryBuilder()
            ->insert($schema->tableName)
            ->setValue($schema->idFieldName, ':id')
            ->setValue($schema->messageTypeFieldName, ':messageType')
            ->setValue($schema->messageNameFieldName, ':messageName')
            ->setValue($schema->payloadFieldName, ':payload')
            ->setValue($schema->createdAtFieldName, ':createdAt')
            ->setValue($schema->retryCountFieldName, ':retryCount')
            ->setParameters([
                'id' => $message->id,
                'messageType' => $message->messageType,
                'messageName' => $message->messageName,
                'payload' => $message->payload,
                'createdAt' => $message->createdAt->format($schema->createdAtFieldFormat),
                'retryCount' => 0,
            ])
            ->executeStatement();
    }

    #[Override]
    public function markAsProcessed(string ...$messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $schema = $this->tableSchema;

        $this->connection->createQueryBuilder()
            ->update($schema->tableName)
            ->set($schema->processedAtFieldName, ':processedAt')
            ->where(sprintf('%s IN (:ids)', $schema->idFieldName))
            ->setParameter('processedAt', (new DateTimeImmutable())->format($schema->processedAtFieldFormat))
            ->setParameter('ids', array_values($messageIds), ArrayParameterType::STRING)
            ->executeStatement();
    }

    #[Override]
    public function incrementRetryCount(int $maxRetries, string ...$messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $schema = $this->tableSchema;

        $this->connection->createQueryBuilder()
            ->update($schema->tableName)
            ->set($schema->retryCountFieldName, sprintf('%s + 1', $schema->retryCountFieldName))
            ->set(
                $schema->deadLetteredAtFieldName,
                sprintf(
                    'CASE WHEN %s + 1 > :maxRetries THEN :now ELSE %s END',
                    $schema->retryCountFieldName,
                    $schema->deadLetteredAtFieldName,
                ),
            )
            ->where(sprintf('%s IN (:ids)', $schema->idFieldName))
            ->setParameter('maxRetries', $maxRetries, ParameterType::INTEGER)
            ->setParameter('now', (new DateTimeImmutable())->format($schema->deadLetteredAtFieldFormat))
            ->setParameter('ids', array_values($messageIds), ArrayParameterType::STRING)
            ->executeStatement();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getUnprocessedMessagesWithoutLocking(int $limit): array
    {
        $schema = $this->tableSchema;

        return $this->connection->createQueryBuilder()
            ->select(
                <<<DQL
                {$schema->idFieldName} as id,
                {$schema->messageTypeFieldName} as messageType,
                {$schema->messageNameFieldName} as messageName,
                {$schema->payloadFieldName} as payload,
                {$schema->createdAtFieldName} as createdAt,
                {$schema->retryCountFieldName} as retryCount
                DQL
            )
            ->from($schema->tableName)
            ->where(sprintf('%s IS NULL', $schema->processedAtFieldName))
            ->andWhere(sprintf('%s IS NULL', $schema->deadLetteredAtFieldName))
            ->orderBy($schema->createdAtFieldName, 'asc')
            ->addOrderBy($schema->idFieldName, 'asc')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
