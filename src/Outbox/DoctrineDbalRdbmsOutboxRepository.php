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
use Throwable;

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
    /**
     * Identifies this processor in the messages it claims (host, process and a random part).
     */
    private string $claimant;

    /**
     * @param int $leaseSeconds How long claimed messages are reserved for this processor. Must exceed the time to
     *                          dispatch a batch: after it, other processors take the messages over.
     */
    public function __construct(
        private Connection $connection,
        private OutboxTableSchema $tableSchema,
        private DoctrineDbalRdbmsOutboxFactory $outboxFactory,
        private int $leaseSeconds = 300,
        ?string $claimant = null,
    ) {
        $this->claimant = $claimant ?? substr(
            sprintf('%s:%d:%s', gethostname() ?: 'unknown', getmypid() ?: 0, bin2hex(random_bytes(4))),
            -100,
        );
    }

    /**
     * Claims up to $limit unprocessed messages for this processor until the lease expires, so concurrent processors
     * don't get them, and returns them. Dispatching happens after the claim is committed, outside any transaction.
     */
    #[Override]
    public function getUnprocessedMessages(int $limit): array
    {
        $now = new DateTimeImmutable();
        $claimedUntil = $now->modify(sprintf('+%d seconds', $this->leaseSeconds));

        $ownsTransaction = !$this->connection->isTransactionActive();

        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $messageIds = $this->findClaimableMessageIds($limit, $now);

            if ($messageIds !== []) {
                $this->claim($messageIds, $now, $claimedUntil);
            }

            if ($ownsTransaction) {
                $this->connection->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                try {
                    $this->connection->rollBack();
                } catch (Throwable) {
                    // Rethrow the original exception rather than masking it with the rollback failure
                }
            }

            throw $exception;
        }

        if ($messageIds === []) {
            return [];
        }

        $schema = $this->tableSchema;

        /** @var list<OutboxRow> $rows */
        $rows = $this->connection->createQueryBuilder()
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
            ->where(sprintf('%s = :claimant', $schema->claimedByFieldName))
            ->andWhere(sprintf('%s = :claimedUntil', $schema->claimedUntilFieldName))
            ->orderBy($schema->createdAtFieldName, 'asc')
            ->addOrderBy($schema->idFieldName, 'asc')
            ->setParameter('claimant', $this->claimant)
            ->setParameter('claimedUntil', $claimedUntil->format($schema->claimedUntilFieldFormat))
            ->executeQuery()
            ->fetchAllAssociative();

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

    /**
     * Marks the messages as processed, also when their lease expired meanwhile: delivered is delivered, and it keeps
     * another processor from dispatching them again.
     */
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
            ->andWhere(sprintf('%s IS NULL', $schema->processedAtFieldName))
            ->setParameter('processedAt', (new DateTimeImmutable())->format($schema->processedAtFieldFormat))
            ->setParameter('ids', array_values($messageIds), ArrayParameterType::STRING)
            ->executeStatement();
    }

    /**
     * Counts a failed dispatch of messages claimed by this processor and releases them, so they are retried. A failure
     * of a message another processor took over (after the lease expired) is left to that processor.
     */
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
            ->set($schema->claimedByFieldName, 'NULL')
            ->set($schema->claimedUntilFieldName, 'NULL')
            ->where(sprintf('%s IN (:ids)', $schema->idFieldName))
            ->andWhere(sprintf('%s = :claimant', $schema->claimedByFieldName))
            ->setParameter('maxRetries', $maxRetries, ParameterType::INTEGER)
            ->setParameter('now', (new DateTimeImmutable())->format($schema->deadLetteredAtFieldFormat))
            ->setParameter('ids', array_values($messageIds), ArrayParameterType::STRING)
            ->setParameter('claimant', $this->claimant)
            ->executeStatement();
    }

    /**
     * Unprocessed messages that aren't claimed (or whose claim expired), oldest first. Where supported, rows another
     * processor is claiming right now are skipped (SKIP LOCKED), so concurrent processors take different messages.
     * Without it, the guarded claim alone keeps them from taking the same ones.
     *
     * @return list<string>
     */
    private function findClaimableMessageIds(int $limit, DateTimeImmutable $now): array
    {
        $schema = $this->tableSchema;

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select(sprintf('%s as id', $schema->idFieldName))
            ->from($schema->tableName)
            ->where(sprintf('%s IS NULL', $schema->processedAtFieldName))
            ->andWhere(sprintf('%s IS NULL', $schema->deadLetteredAtFieldName))
            ->andWhere(sprintf('(%1$s IS NULL OR %1$s < :now)', $schema->claimedUntilFieldName))
            ->orderBy($schema->createdAtFieldName, 'asc')
            ->addOrderBy($schema->idFieldName, 'asc')
            ->setParameter('now', $now->format($schema->claimedUntilFieldFormat))
            ->setMaxResults($limit);

        try {
            /** @var list<string> */
            return (clone $queryBuilder)
                ->forUpdate(ConflictResolutionMode::SKIP_LOCKED)
                ->executeQuery()
                ->fetchFirstColumn();
        } catch (NotSupported) {
            // E.g. SQLite, MySQL 5.7 or MariaDB < 10.6
            /** @var list<string> */
            return $queryBuilder->executeQuery()->fetchFirstColumn();
        }
    }

    /**
     * Claims the messages that are still claimable: of two processors claiming the same message, the update of the
     * second one no longer matches it (the condition is evaluated against the latest committed row).
     *
     * @param list<string> $messageIds
     */
    private function claim(array $messageIds, DateTimeImmutable $now, DateTimeImmutable $claimedUntil): void
    {
        $schema = $this->tableSchema;

        $this->connection->createQueryBuilder()
            ->update($schema->tableName)
            ->set($schema->claimedByFieldName, ':claimant')
            ->set($schema->claimedUntilFieldName, ':claimedUntil')
            ->where(sprintf('%s IN (:ids)', $schema->idFieldName))
            ->andWhere(sprintf('%s IS NULL', $schema->processedAtFieldName))
            ->andWhere(sprintf('%s IS NULL', $schema->deadLetteredAtFieldName))
            ->andWhere(sprintf('(%1$s IS NULL OR %1$s < :now)', $schema->claimedUntilFieldName))
            ->setParameter('claimant', $this->claimant)
            ->setParameter('claimedUntil', $claimedUntil->format($schema->claimedUntilFieldFormat))
            ->setParameter('now', $now->format($schema->claimedUntilFieldFormat))
            ->setParameter('ids', $messageIds, ArrayParameterType::STRING)
            ->executeStatement();
    }
}
