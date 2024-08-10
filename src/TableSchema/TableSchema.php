<?php

declare(strict_types=1);

namespace Gember\EventStoreDoctrineDbal\TableSchema;

final readonly class TableSchema
{
    public function __construct(
        public string $tableName,
        public string $eventIdFieldName,
        public string $aggregateRootIdFieldName,
        public string $eventNameFieldName,
        public string $payloadFieldName,
        public string $playheadFieldName,
        public string $metadataFieldName,
        public string $appliedAtFieldName,
        public string $appliedAtFieldFormat,
    ) {}
}
