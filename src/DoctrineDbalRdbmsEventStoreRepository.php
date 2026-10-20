<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Gember\DependencyContracts\EventStore\Rdbms\OptimisticLockException;
use Gember\DependencyContracts\EventStore\Rdbms\RdbmsEvent;
use Gember\DependencyContracts\EventStore\Rdbms\RdbmsEventStoreRepository;
use Gember\RdbmsEventStoreDoctrineDbal\TableSchema\EventStoreLockTableSchema;
use Gember\RdbmsEventStoreDoctrineDbal\TableSchema\EventStoreRelationTableSchema;
use Gember\RdbmsEventStoreDoctrineDbal\TableSchema\EventStoreTableSchema;
use Override;
use Stringable;
use Throwable;

/**
 * @phpstan-type Row array{
 *     eventId: string,
 *     eventName: string,
 *     payload: string,
 *     metadata: string,
 *     appliedAt: string,
 *     domainTag: string
 * }
 */
final readonly class DoctrineDbalRdbmsEventStoreRepository implements RdbmsEventStoreRepository
{
    public function __construct(
        private Connection $connection,
        private EventStoreTableSchema $eventStoreTableSchema,
        private EventStoreRelationTableSchema $eventStoreRelationTableSchema,
        private EventStoreLockTableSchema $eventStoreLockTableSchema,
        private DoctrineDbalRdbmsEventFactory $rdbmsEventFactory,
    ) {}

    #[Override]
    public function getEvents(array $domainTags, array $eventNames, ?string $afterEventId = null): array
    {
        $eventStoreSchema = $this->eventStoreTableSchema;
        $eventStoreRelationSchema = $this->eventStoreRelationTableSchema;

        $qb = $this->connection->createQueryBuilder()
            ->select(
                <<<DQL
                es.{$eventStoreSchema->eventIdFieldName} as eventId,
                es.{$eventStoreSchema->payloadFieldName} as payload,
                es.{$eventStoreSchema->eventNameFieldName} as eventName,
                es.{$eventStoreSchema->appliedAtFieldName} as appliedAt,
                es.{$eventStoreSchema->metadataFieldName} as metadata,
                esr.{$eventStoreRelationSchema->domainTagFieldName} as domainTag
                DQL
            )
            ->from($eventStoreSchema->tableName, 'es')
            ->join('es', $eventStoreRelationSchema->tableName, 'esr', sprintf(
                'es.%s = esr.%s',
                $eventStoreSchema->eventIdFieldName,
                $eventStoreRelationSchema->eventIdFieldName,
            ))
            ->where(sprintf('es.%s IN(:eventNames)', $eventStoreSchema->eventNameFieldName))
            ->andWhere(sprintf('esr.%s IN(:domainTags)', $eventStoreRelationSchema->domainTagFieldName))
            ->setParameter('eventNames', $eventNames, ArrayParameterType::STRING)
            ->setParameter('domainTags', $domainTags, ArrayParameterType::STRING)
            ->orderBy(sprintf('es.%s', $eventStoreSchema->appliedAtFieldName), 'asc')
            ->addOrderBy(sprintf('es.%s', $eventStoreSchema->eventIdFieldName), 'asc');

        if ($afterEventId !== null) {
            $qb->join('es', $eventStoreSchema->tableName, 'anchor', sprintf(
                'anchor.%s = :afterEventId',
                $eventStoreSchema->eventIdFieldName,
            ))
                ->andWhere(sprintf(
                    '(es.%s > anchor.%s OR (es.%s = anchor.%s AND es.%s > :afterEventId))',
                    $eventStoreSchema->appliedAtFieldName,
                    $eventStoreSchema->appliedAtFieldName,
                    $eventStoreSchema->appliedAtFieldName,
                    $eventStoreSchema->appliedAtFieldName,
                    $eventStoreSchema->eventIdFieldName,
                ))
                ->setParameter('afterEventId', $afterEventId);
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        /** @var array<string, RdbmsEvent> $events */
        $events = [];

        /** @var Row $row */
        foreach ($rows as $row) {
            $eventId = $row['eventId'];

            $event = $events[$eventId] ?? $this->rdbmsEventFactory->createFromRow($row);

            $events[$eventId] = $event->withDomainTag($row['domainTag']);
        }

        return array_values($events);
    }

    #[Override]
    public function countEvents(array $domainTags, array $eventNames): int
    {
        $eventStoreSchema = $this->eventStoreTableSchema;
        $eventStoreRelationSchema = $this->eventStoreRelationTableSchema;

        $count = $this->connection->createQueryBuilder()
            ->select(sprintf('COUNT(DISTINCT es.%s)', $eventStoreSchema->eventIdFieldName))
            ->from($eventStoreSchema->tableName, 'es')
            ->join('es', $eventStoreRelationSchema->tableName, 'esr', sprintf(
                'es.%s = esr.%s',
                $eventStoreSchema->eventIdFieldName,
                $eventStoreRelationSchema->eventIdFieldName,
            ))
            ->where(sprintf('es.%s IN(:eventNames)', $eventStoreSchema->eventNameFieldName))
            ->andWhere(sprintf('esr.%s IN(:domainTags)', $eventStoreRelationSchema->domainTagFieldName))
            ->setParameter('eventNames', $eventNames, ArrayParameterType::STRING)
            ->setParameter('domainTags', $domainTags, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    #[Override]
    public function saveEvents(array $domainTags, array $eventNames, int $expectedEventCount, array $events): void
    {
        $eventStoreSchema = $this->eventStoreTableSchema;
        $eventStoreRelationSchema = $this->eventStoreRelationTableSchema;

        /*
         * When called inside a transaction (e.g. a transactional decorator), run within it rather than
         * opening a savepoint: the caller owns atomicity and rollback. A savepoint would break on a
         * deadlock, as the database then rolls back the whole transaction including the savepoint.
         */
        $ownsTransaction = !$this->connection->isTransactionActive();
        $lockKeys = $this->buildLockKeys($domainTags, $eventNames, $events);

        if ($ownsTransaction) {
            $this->createMissingLockRows($lockKeys);

            $this->connection->beginTransaction();
        }

        try {
            /*
             * Acquire exclusive row locks on every (domain tag, event name) pair this writer reads or writes.
             * Any concurrent writer appending an event that matches this writer's query (or vice versa)
             * shares at least one pair, so they are serialized — one blocks until the other commits.
             */
            $this->acquireLocks($lockKeys);

            /*
             * With the locks held, safely check whether the number of matching events still equals the number
             * the caller based its decision on. Events are append-only, so any matching event committed in between
             * changes the count (whatever its timestamp), and an OptimisticLockException is thrown.
             */
            $this->guardOptimisticLock($domainTags, $eventNames, $expectedEventCount);

            foreach ($events as $event) {
                $this->connection->createQueryBuilder()
                    ->insert($eventStoreSchema->tableName)
                    ->setValue($eventStoreSchema->eventIdFieldName, ':id')
                    ->setValue($eventStoreSchema->eventNameFieldName, ':eventName')
                    ->setValue($eventStoreSchema->payloadFieldName, ':payload')
                    ->setValue($eventStoreSchema->metadataFieldName, ':metadata')
                    ->setValue($eventStoreSchema->appliedAtFieldName, ':appliedAt')
                    ->setParameters([
                        'id' => $event->eventId,
                        'eventName' => $event->eventName,
                        'payload' => $event->payload,
                        'metadata' => json_encode($event->metadata, JSON_THROW_ON_ERROR),
                        'appliedAt' => $event->appliedAt->format($eventStoreSchema->appliedAtFieldFormat),
                    ])
                    ->executeStatement();

                foreach ($event->domainTags as $domainTag) {
                    $this->connection->createQueryBuilder()
                        ->insert($eventStoreRelationSchema->tableName)
                        ->setValue($eventStoreRelationSchema->eventIdFieldName, ':eventId')
                        ->setValue($eventStoreRelationSchema->domainTagFieldName, ':domainTag')
                        ->setParameters([
                            'eventId' => $event->eventId,
                            'domainTag' => $domainTag,
                        ])
                        ->executeStatement();
                }
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
    }

    /**
     * Commits missing lock rows upfront (MySQL/MariaDB only, outside a transaction).
     *
     * A lock row created inside the writer's transaction disappears again when that transaction rolls back
     * (e.g. on an OptimisticLockException). InnoDB then turns the locks of all writers waiting on that row into
     * gap locks, and the waiters deadlock each other when inserting it. Lock rows that already exist are only
     * locked, never removed, so this cannot happen.
     *
     * This is an optimization only: acquireLocks() still creates any row missing at that point.
     *
     * @param list<string> $lockKeys
     */
    private function createMissingLockRows(array $lockKeys): void
    {
        if ($lockKeys === [] || !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $lockSchema = $this->eventStoreLockTableSchema;

        $existingLockKeys = $this->connection->createQueryBuilder()
            ->select($lockSchema->boundaryHashFieldName)
            ->from($lockSchema->tableName)
            ->where(sprintf('%s IN (:lockKeys)', $lockSchema->boundaryHashFieldName))
            ->setParameter('lockKeys', $lockKeys, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchFirstColumn();

        $missingLockKeys = array_values(array_diff($lockKeys, array_filter($existingLockKeys, is_string(...))));

        if ($missingLockKeys === []) {
            return;
        }

        try {
            $this->connection->executeStatement(
                sprintf(
                    'INSERT IGNORE INTO %s (%s) VALUES %s',
                    $lockSchema->tableName,
                    $lockSchema->boundaryHashFieldName,
                    implode(', ', array_fill(0, count($missingLockKeys), '(?)')),
                ),
                $missingLockKeys,
            );
        } catch (DeadlockException|LockWaitTimeoutException) {
            // Concurrent writers creating the same rows; acquireLocks() creates whatever is still missing
        }
    }

    /**
     * @param list<string> $lockKeys
     */
    private function acquireLocks(array $lockKeys): void
    {
        if ($lockKeys === []) {
            return;
        }

        $lockSchema = $this->eventStoreLockTableSchema;
        $platform = $this->connection->getDatabasePlatform();
        $values = implode(', ', array_fill(0, count($lockKeys), '(?)'));

        $sql = match (true) {
            /*
             * The upsert creates missing lock rows and takes an exclusive lock on new and existing rows in a
             * single statement, in sorted key order. A plain INSERT hitting a duplicate key would take a shared
             * lock first, and upgrading it to an exclusive lock deadlocks between concurrent writers.
             */
            $platform instanceof AbstractMySQLPlatform => sprintf(
                'INSERT INTO %1$s (%2$s) VALUES %3$s ON DUPLICATE KEY UPDATE %2$s = %2$s',
                $lockSchema->tableName,
                $lockSchema->boundaryHashFieldName,
                $values,
            ),
            /*
             * SQLite serializes writers on database level, the lock rows only need to exist.
             */
            $platform instanceof SQLitePlatform => sprintf(
                'INSERT OR IGNORE INTO %s (%s) VALUES %s',
                $lockSchema->tableName,
                $lockSchema->boundaryHashFieldName,
                $values,
            ),
            default => throw UnsupportedPlatformException::create($platform),
        };

        $this->connection->executeStatement($sql, $lockKeys);
    }

    /**
     * @param list<string|Stringable> $domainTags
     * @param list<string> $eventNames
     *
     * @throws OptimisticLockException
     */
    private function guardOptimisticLock(array $domainTags, array $eventNames, int $expectedEventCount): void
    {
        if ($this->countEvents($domainTags, $eventNames) !== $expectedEventCount) {
            throw OptimisticLockException::create();
        }
    }

    /**
     * Builds a lock key per (domain tag, event name) pair of the read set (the writer's query: domain tags
     * x event names) and of the write set (each appended event's own domain tags x its event name).
     *
     * @param list<string|Stringable> $domainTags
     * @param list<string> $eventNames
     * @param list<RdbmsEvent> $events
     *
     * @return list<string>
     */
    private function buildLockKeys(array $domainTags, array $eventNames, array $events): array
    {
        $lockKeys = [];

        foreach ($domainTags as $domainTag) {
            foreach ($eventNames as $eventName) {
                $lockKeys[] = $this->buildLockKey((string) $domainTag, $eventName);
            }
        }

        foreach ($events as $event) {
            foreach ($event->domainTags as $domainTag) {
                $lockKeys[] = $this->buildLockKey($domainTag, $event->eventName);
            }
        }

        $lockKeys = array_values(array_unique($lockKeys));
        sort($lockKeys, SORT_STRING);

        return $lockKeys;
    }

    private function buildLockKey(string $domainTag, string $eventName): string
    {
        return hash('sha256', $domainTag . "\0" . $eventName);
    }
}
