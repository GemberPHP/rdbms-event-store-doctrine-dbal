<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Query\ForUpdate\ConflictResolutionMode;
use Doctrine\DBAL\Query\QueryBuilder;
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
    private string $host;
    private string $instance;

    /**
     * @param int $leaseSeconds How long claimed messages are reserved for this processor. Must exceed the time to
     *                          dispatch a batch: after it, other processors take the messages over. Measured with the
     *                          database clock, so all processors share one clock.
     * @param string|null $claimant Identifies this processor in its claims; defaults to host, process and a random part
     */
    public function __construct(
        private Connection $connection,
        private OutboxTableSchema $tableSchema,
        private DoctrineDbalRdbmsOutboxFactory $outboxFactory,
        private int $leaseSeconds = 300,
        private ?string $claimant = null,
    ) {
        $this->host = gethostname() ?: 'unknown';
        $this->instance = bin2hex(random_bytes(4));
    }

    /**
     * Claims up to $limit unprocessed messages for this processor until the lease expires, so concurrent processors
     * don't get them, and returns them. Run it outside any transaction: the claim is then committed before returning,
     * and the messages are dispatched outside a transaction.
     */
    #[Override]
    public function getUnprocessedMessages(int $limit): array
    {
        $messageIds = $this->claim($limit);

        if ($messageIds === []) {
            return [];
        }

        $schema = $this->tableSchema;

        // By id (primary key): the messages of this claim that this processor still holds
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
            ->where(sprintf('%s IN (:ids)', $schema->idFieldName))
            ->andWhere(sprintf('%s = :claimant', $schema->claimedByFieldName))
            ->andWhere(sprintf('%s IS NULL', $schema->processedAtFieldName))
            ->andWhere(sprintf('%s IS NULL', $schema->deadLetteredAtFieldName))
            ->orderBy($schema->createdAtFieldName, 'asc')
            ->addOrderBy($schema->idFieldName, 'asc')
            ->setParameter('ids', $messageIds, ArrayParameterType::STRING)
            ->setParameter('claimant', $this->claimant())
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
     * of a message another processor took over (after the lease expired), or that was delivered meanwhile, is ignored.
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
            ->andWhere(sprintf('%s IS NULL', $schema->processedAtFieldName))
            ->setParameter('maxRetries', $maxRetries, ParameterType::INTEGER)
            ->setParameter('now', (new DateTimeImmutable())->format($schema->deadLetteredAtFieldFormat))
            ->setParameter('ids', array_values($messageIds), ArrayParameterType::STRING)
            ->setParameter('claimant', $this->claimant())
            ->executeStatement();
    }

    /**
     * Claims up to $limit messages and returns the ids of the candidates.
     *
     * Correctness comes from the guarded claim update alone: of two processors claiming the same message, the update of
     * the second one no longer matches it. Where SKIP LOCKED is supported, the candidates are selected and claimed in a
     * short transaction that skips rows other processors are claiming, so concurrent processors take different
     * messages. Without it (SQLite, MySQL 5.7, MariaDB < 10.6), both statements run on their own: a transaction would
     * add nothing there, and SQLite would reject concurrent claim transactions as "database is locked".
     *
     * @return list<string>
     */
    private function claim(int $limit): array
    {
        $candidates = $this->createCandidateQuery($limit);

        try {
            $candidates->forUpdate(ConflictResolutionMode::SKIP_LOCKED)->getSQL();
        } catch (NotSupported) {
            $messageIds = $this->fetchIds($this->createCandidateQuery($limit));

            if ($messageIds !== []) {
                $this->claimCandidates($messageIds);
            }

            return $messageIds;
        }

        $ownsTransaction = !$this->connection->isTransactionActive();

        if ($ownsTransaction) {
            // Lock only the selected rows: under REPEATABLE READ the scan would also gap-lock the end of the
            // unprocessed range, blocking new outbox rows (any command) for as long as the claim takes
            if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }

            $this->connection->beginTransaction();
        }

        try {
            $messageIds = $this->fetchIds($candidates);

            if ($messageIds !== []) {
                $this->claimCandidates($messageIds);
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

        return $messageIds;
    }

    /**
     * Unprocessed messages that aren't claimed (or whose claim expired), oldest first.
     */
    private function createCandidateQuery(int $limit): QueryBuilder
    {
        $schema = $this->tableSchema;

        return $this->connection->createQueryBuilder()
            ->select(sprintf('%s as id', $schema->idFieldName))
            ->from($schema->tableName)
            ->where(sprintf('%s IS NULL', $schema->processedAtFieldName))
            ->andWhere(sprintf('%s IS NULL', $schema->deadLetteredAtFieldName))
            ->andWhere($this->claimExpired())
            ->orderBy($schema->createdAtFieldName, 'asc')
            ->addOrderBy($schema->idFieldName, 'asc')
            ->setMaxResults($limit);
    }

    /**
     * @param list<string> $messageIds
     */
    private function claimCandidates(array $messageIds): void
    {
        $schema = $this->tableSchema;
        $platform = $this->connection->getDatabasePlatform();

        // The conditions are evaluated against the latest committed row: a message claimed, processed or
        // dead-lettered since it was selected isn't claimed again
        $this->connection->createQueryBuilder()
            ->update($schema->tableName)
            ->set($schema->claimedByFieldName, ':claimant')
            ->set(
                $schema->claimedUntilFieldName,
                $platform->getDateAddSecondsExpression($platform->getCurrentTimestampSQL(), (string) $this->leaseSeconds),
            )
            ->where(sprintf('%s IN (:ids)', $schema->idFieldName))
            ->andWhere(sprintf('%s IS NULL', $schema->processedAtFieldName))
            ->andWhere(sprintf('%s IS NULL', $schema->deadLetteredAtFieldName))
            ->andWhere($this->claimExpired())
            ->setParameter('claimant', $this->claimant())
            ->setParameter('ids', $messageIds, ArrayParameterType::STRING)
            ->executeStatement();
    }

    /**
     * Not claimed, or the lease expired (by the database clock).
     */
    private function claimExpired(): string
    {
        return sprintf(
            '(%1$s IS NULL OR %1$s <= %2$s)',
            $this->tableSchema->claimedUntilFieldName,
            $this->connection->getDatabasePlatform()->getCurrentTimestampSQL(),
        );
    }

    /**
     * @return list<string>
     */
    private function fetchIds(QueryBuilder $queryBuilder): array
    {
        /** @var list<string> */
        return $queryBuilder->executeQuery()->fetchFirstColumn();
    }

    /**
     * The process id is read when claiming, so processes forked after construction claim as different processors.
     */
    private function claimant(): string
    {
        return $this->claimant ?? substr(
            sprintf('%s:%d:%s', $this->host, getmypid() ?: 0, $this->instance),
            -100,
        );
    }
}
