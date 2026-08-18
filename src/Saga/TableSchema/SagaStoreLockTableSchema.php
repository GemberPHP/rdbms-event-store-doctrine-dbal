<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Saga\TableSchema;

final readonly class SagaStoreLockTableSchema
{
    public function __construct(
        public string $tableName,
        public string $boundaryHashFieldName,
    ) {}
}
