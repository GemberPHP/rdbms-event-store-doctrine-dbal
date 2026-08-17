<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Gember\DependencyContracts\EventStore\Rdbms\OptimisticLockException;
use Gember\DependencyContracts\EventStore\Rdbms\RdbmsEvent;
use Gember\DependencyContracts\EventStore\Rdbms\RdbmsEventStoreRepository;
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
        private DoctrineDbalRdbmsEventFactory $rdbmsEventFactory,
    ) {}

    #[Override]
    public function getEvents(array $domainTags, array $eventNames): array
    {
        $eventStoreSchema = $this->eventStoreTableSchema;
        $eventStoreRelationSchema = $this->eventStoreRelationTableSchema;

        $rows = $this->connection->createQueryBuilder()
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
            ->executeQuery()
            ->fetchAllAssociative();

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
    public function saveEvents(array $domainTags, array $eventNames, ?string $lastEventId, array $events): void
    {
        $eventStoreSchema = $this->eventStoreTableSchema;
        $eventStoreRelationSchema = $this->eventStoreRelationTableSchema;

        $this->connection->beginTransaction();

        try {
            $this->guardOptimisticLock($domainTags, $eventNames, $lastEventId);

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

            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }

    /**
     * @param list<string|Stringable> $domainTags
     * @param list<string> $eventNames
     *
     * @throws OptimisticLockException
     */
    private function guardOptimisticLock(array $domainTags, array $eventNames, ?string $lastEventId): void
    {
        $eventStoreSchema = $this->eventStoreTableSchema;
        $eventStoreRelationSchema = $this->eventStoreRelationTableSchema;

        try {
            /** @var list<string> $row */
            $row = $this->connection->createQueryBuilder()
                ->select(sprintf('es.%s', $eventStoreSchema->eventIdFieldName))
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
                ->orderBy(sprintf('es.%s', $eventStoreSchema->appliedAtFieldName), 'desc')
                ->setMaxResults(1)
                ->forUpdate()
                ->executeQuery()
                ->fetchFirstColumn();
        } catch (NotSupported) {
            // Platform does not support FOR UPDATE (e.g. SQLite).
            // SQLite serializes writes implicitly, so this is safe.
            /** @var list<string> $row */
            $row = $this->connection->createQueryBuilder()
                ->select(sprintf('es.%s', $eventStoreSchema->eventIdFieldName))
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
                ->orderBy(sprintf('es.%s', $eventStoreSchema->appliedAtFieldName), 'desc')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchFirstColumn();
        }

        $lastEventIdPersisted = $row[0] ?? null;

        if ($lastEventIdPersisted === null) {
            return;
        }

        if ($lastEventIdPersisted !== $lastEventId) {
            throw OptimisticLockException::create();
        }
    }
}
