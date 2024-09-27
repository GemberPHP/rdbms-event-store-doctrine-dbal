<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\TableSchema;

final readonly class EventStoreTableSchema
{
    public function __construct(
        public string $tableName,
        public string $eventIdFieldName,
        public string $eventNameFieldName,
        public string $payloadFieldName,
        public string $metadataFieldName,
        public string $appliedAtFieldName,
        public string $appliedAtFieldFormat,
    ) {}
}
