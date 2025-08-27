<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\TableSchema;

final readonly class TableSchemaFactory
{
    public static function createDefaultEventStore(
        string $tableName = 'event_store',
        string $eventIdFieldName = 'id',
        string $eventNameFieldName = 'event_name',
        string $payloadFieldName = 'payload',
        string $metadataFieldName = 'metadata',
        string $appliedAtFieldName = 'applied_at',
        string $appliedAtFieldFormat = 'Y-m-d H:i:s.u',
    ): EventStoreTableSchema {
        return new EventStoreTableSchema(
            $tableName,
            $eventIdFieldName,
            $eventNameFieldName,
            $payloadFieldName,
            $metadataFieldName,
            $appliedAtFieldName,
            $appliedAtFieldFormat,
        );
    }

    public static function createDefaultEventStoreRelation(
        string $tableName = 'event_store_relation',
        string $eventIdFieldName = 'event_id',
        string $domainTagFieldName = 'domain_tag',
    ): EventStoreRelationTableSchema {
        return new EventStoreRelationTableSchema(
            $tableName,
            $eventIdFieldName,
            $domainTagFieldName,
        );
    }
}
