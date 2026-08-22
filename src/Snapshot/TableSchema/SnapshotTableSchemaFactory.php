<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Snapshot\TableSchema;

final readonly class SnapshotTableSchemaFactory
{
    public static function createDefault(
        string $tableName = 'snapshot_store',
        string $boundaryHashFieldName = 'boundary_hash',
        string $lastEventIdFieldName = 'last_event_id',
        string $eventCountFieldName = 'event_count',
        string $payloadFieldName = 'payload',
        string $createdAtFieldName = 'created_at',
        string $createdAtFieldFormat = 'Y-m-d H:i:s.u',
        string $updatedAtFieldName = 'updated_at',
        string $updatedAtFieldFormat = 'Y-m-d H:i:s.u',
    ): SnapshotStoreTableSchema {
        return new SnapshotStoreTableSchema(
            $tableName,
            $boundaryHashFieldName,
            $lastEventIdFieldName,
            $eventCountFieldName,
            $payloadFieldName,
            $createdAtFieldName,
            $createdAtFieldFormat,
            $updatedAtFieldName,
            $updatedAtFieldFormat,
        );
    }
}
