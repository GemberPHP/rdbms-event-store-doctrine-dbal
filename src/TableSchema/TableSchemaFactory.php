<?php

declare(strict_types=1);

namespace Gember\EventStoreDoctrineDbal\TableSchema;

final readonly class TableSchemaFactory
{
    public static function createDefault(
        string $tableName = 'event_store',
        string $eventIdFieldName = 'id',
        string $aggregateRootIdFieldName = 'aggregate_root_id',
        string $eventNameFieldName = 'event_name',
        string $payloadFieldName = 'payload',
        string $playheadFieldName = 'playhead',
        string $metadataFieldName = 'metadata',
        string $appliedAtFieldName = 'applied_at',
        string $appliedAtFieldFormat = 'Y-m-d H:i:s.u',
    ): TableSchema {
        return new TableSchema(
            $tableName,
            $eventIdFieldName,
            $aggregateRootIdFieldName,
            $eventNameFieldName,
            $payloadFieldName,
            $playheadFieldName,
            $metadataFieldName,
            $appliedAtFieldName,
            $appliedAtFieldFormat,
        );
    }
}
