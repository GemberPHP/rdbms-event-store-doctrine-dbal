<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Snapshot\TableSchema;

final readonly class SnapshotStoreTableSchema
{
    public function __construct(
        public string $tableName,
        public string $idFieldName,
        public string $boundaryHashFieldName,
        public string $lastEventIdFieldName,
        public string $eventCountFieldName,
        public string $payloadFieldName,
        public string $createdAtFieldName,
        public string $createdAtFieldFormat,
    ) {}
}
