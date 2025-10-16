<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Saga\TableSchema;

final readonly class SagaStoreTableSchema
{
    public function __construct(
        public string $tableName,
        public string $idFieldName,
        public string $sagaNameFieldName,
        public string $payloadFieldName,
        public string $createdAtFieldName,
        public string $createdAtFieldFormat,
        public string $updatedAtFieldName,
        public string $updatedAtFieldFormat,
    ) {}
}
