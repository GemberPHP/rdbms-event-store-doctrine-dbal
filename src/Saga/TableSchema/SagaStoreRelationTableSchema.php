<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Saga\TableSchema;

final readonly class SagaStoreRelationTableSchema
{
    public function __construct(
        public string $tableName,
        public string $idFieldName,
        public string $sagaIdFieldName,
    ) {}
}
