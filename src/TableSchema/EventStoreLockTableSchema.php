<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\TableSchema;

final readonly class EventStoreLockTableSchema
{
    public function __construct(
        public string $tableName,
        public string $boundaryHashFieldName,
    ) {}
}
