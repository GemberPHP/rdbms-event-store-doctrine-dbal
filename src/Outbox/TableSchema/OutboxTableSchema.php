<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Outbox\TableSchema;

final readonly class OutboxTableSchema
{
    public function __construct(
        public string $tableName,
        public string $idFieldName,
        public string $messageTypeFieldName,
        public string $messageNameFieldName,
        public string $payloadFieldName,
        public string $createdAtFieldName,
        public string $createdAtFieldFormat,
        public string $processedAtFieldName,
        public string $processedAtFieldFormat,
        public string $retryCountFieldName,
        public string $deadLetteredAtFieldName,
        public string $deadLetteredAtFieldFormat,
    ) {}
}
