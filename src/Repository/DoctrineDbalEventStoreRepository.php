<?php

declare(strict_types=1);

namespace Gember\EventStoreDoctrineDbal\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Gember\EventSourcing\AggregateRoot\AggregateRootId;
use Gember\EventSourcing\EventNameResolver\EventNameResolver;
use Gember\EventSourcing\EventNameResolver\UnresolvableEventNameException;
use Gember\EventSourcing\EventStore\EventEnvelope;
use Gember\EventSourcing\Util\Serialization\Serializer\SerializationFailedException;
use Gember\EventSourcing\Util\Serialization\Serializer\Serializer;
use Gember\EventStoreDoctrineDbal\TableSchema\TableSchema;
use JsonException;
use Throwable;

/**
 * @phpstan-import-type RowPayload from EventStoreRepository
 */
final readonly class DoctrineDbalEventStoreRepository implements EventStoreRepository
{
    public function __construct(
        private Connection $connection,
        private TableSchema $tableSchema,
        private EventNameResolver $eventNameResolver,
        private Serializer $serializer,
    ) {}

    /**
     * @throws Exception
     */
    public function getRows(AggregateRootId $aggregateRootId, int $playhead): array
    {
        $schema = $this->tableSchema;

        /** @var list<RowPayload> */
        return $this->connection->createQueryBuilder()
            ->select(
                <<<DQL
                {$schema->eventIdFieldName} as eventId,
                {$schema->payloadFieldName} as payload,
                {$schema->eventNameFieldName} as eventName,
                {$schema->playheadFieldName} as playhead,
                {$schema->appliedAtFieldName} as appliedAt,
                {$schema->metadataFieldName} as metadata
                DQL
            )
            ->from($schema->tableName)
            ->where(sprintf('%s >= :playhead', $schema->payloadFieldName))
            ->andWhere(sprintf('%s = :aggregateRootId', $schema->aggregateRootIdFieldName))
            ->setParameters([
                'playhead' => $playhead,
                'aggregateRootId' => $aggregateRootId,
            ])
            ->orderBy($schema->appliedAtFieldName, 'desc')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @throws Exception
     * @throws Throwable
     * @throws UnresolvableEventNameException
     * @throws SerializationFailedException
     * @throws JsonException
     */
    public function saveRows(AggregateRootId $aggregateRootId, EventEnvelope ...$envelopes): void
    {
        $schema = $this->tableSchema;

        try {
            $this->connection->beginTransaction();

            foreach ($envelopes as $envelope) {
                $this->connection->createQueryBuilder()
                    ->insert($schema->tableName)
                    ->setValue($schema->eventIdFieldName, ':id')
                    ->setValue($schema->aggregateRootIdFieldName, ':aggregateRootId')
                    ->setValue($schema->eventNameFieldName, ':eventName')
                    ->setValue($schema->payloadFieldName, ':payload')
                    ->setValue($schema->playheadFieldName, ':playhead')
                    ->setValue($schema->metadataFieldName, ':metadata')
                    ->setValue($schema->appliedAtFieldName, ':appliedAt')
                    ->setParameters([
                        'id' => $envelope->eventId,
                        'aggregateRootId' => (string) $aggregateRootId,
                        'eventName' => $this->eventNameResolver->resolve($envelope->event::class),
                        'payload' => $this->serializer->serialize($envelope->event),
                        'playhead' => $envelope->playhead,
                        'metadata' => json_encode($envelope->metadata->metadata, JSON_THROW_ON_ERROR),
                        'appliedAt' => $envelope->appliedAt->format($schema->appliedAtFieldFormat),
                    ])
                    ->executeStatement();
            }

            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }

    /**
     * @throws Exception
     */
    public function getLastPlayhead(AggregateRootId $aggregateRootId): ?int
    {
        $schema = $this->tableSchema;

        $row = $this->connection->createQueryBuilder()
            ->select($schema->playheadFieldName)
            ->andWhere(sprintf('%s = :aggregateRootId', $schema->aggregateRootIdFieldName))
            ->setParameters([
                'aggregateRootId' => $aggregateRootId,
            ])
            ->orderBy($schema->appliedAtFieldName, 'desc')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return (int) $row[$schema->playheadFieldName]; // @phpstan-ignore cast.int
    }
}
