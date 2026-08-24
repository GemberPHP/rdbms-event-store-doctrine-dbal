<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Snapshot;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Gember\DependencyContracts\EventStore\Snapshot\RdbmsSnapshot;
use Gember\DependencyContracts\EventStore\Snapshot\RdbmsSnapshotStoreRepository;
use Gember\RdbmsEventStoreDoctrineDbal\Snapshot\TableSchema\SnapshotStoreTableSchema;
use Override;
use Stringable;

final readonly class DoctrineDbalRdbmsSnapshotStoreRepository implements RdbmsSnapshotStoreRepository
{
    public function __construct(
        private Connection $connection,
        private SnapshotStoreTableSchema $snapshotStoreTableSchema,
    ) {}

    #[Override]
    public function get(array $domainTags, array $eventNames): ?RdbmsSnapshot
    {
        $schema = $this->snapshotStoreTableSchema;
        $hash = $this->buildBoundaryHash($domainTags, $eventNames);

        /** @var array{
         *     id: string,
         *     lastEventId: string,
         *     eventCount: string,
         *     payload: string,
         *     createdAt: string,
         * }|false $row */
        $row = $this->connection->createQueryBuilder()
            ->select(
                sprintf('%s as id', $schema->idFieldName),
                sprintf('%s as lastEventId', $schema->lastEventIdFieldName),
                sprintf('%s as eventCount', $schema->eventCountFieldName),
                sprintf('%s as payload', $schema->payloadFieldName),
                sprintf('%s as createdAt', $schema->createdAtFieldName),
            )
            ->from($schema->tableName)
            ->where(sprintf('%s = :hash', $schema->boundaryHashFieldName))
            ->setParameter('hash', $hash)
            ->orderBy($schema->eventCountFieldName, 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return new RdbmsSnapshot(
            $row['id'],
            array_map(strval(...), $domainTags),
            $eventNames,
            $row['lastEventId'],
            (int) $row['eventCount'],
            $row['payload'],
            new DateTimeImmutable($row['createdAt']),
        );
    }

    #[Override]
    public function save(RdbmsSnapshot $snapshot): void
    {
        $schema = $this->snapshotStoreTableSchema;
        $hash = $this->buildBoundaryHash($snapshot->domainTags, $snapshot->eventNames);

        $this->connection->createQueryBuilder()
            ->insert($schema->tableName)
            ->setValue($schema->idFieldName, ':id')
            ->setValue($schema->boundaryHashFieldName, ':hash')
            ->setValue($schema->lastEventIdFieldName, ':lastEventId')
            ->setValue($schema->eventCountFieldName, ':eventCount')
            ->setValue($schema->payloadFieldName, ':payload')
            ->setValue($schema->createdAtFieldName, ':createdAt')
            ->setParameters([
                'id' => $snapshot->id,
                'hash' => $hash,
                'lastEventId' => $snapshot->lastEventId,
                'eventCount' => $snapshot->eventCount,
                'payload' => $snapshot->payload,
                'createdAt' => $snapshot->createdAt->format($schema->createdAtFieldFormat),
            ])
            ->executeStatement();
    }

    /**
     * @param list<string|Stringable> $domainTags
     * @param list<string> $eventNames
     */
    private function buildBoundaryHash(array $domainTags, array $eventNames): string
    {
        $tags = array_map(strval(...), $domainTags);
        sort($tags);
        sort($eventNames);

        return hash('sha256', implode("\0", [...$tags, "\1", ...$eventNames]));
    }
}
