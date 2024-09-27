<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\TableSchema;

final readonly class EventStoreRelationTableSchema
{
    public function __construct(
        public string $tableName,
        public string $eventIdFieldName,
        public string $domainIdFieldName,
    ) {}
}
