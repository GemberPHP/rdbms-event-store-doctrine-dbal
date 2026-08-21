<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Outbox\TableSchema;

final readonly class OutboxTableSchemaFactory
{
    public static function createDefault(
        string $tableName = 'outbox',
        string $idFieldName = 'id',
        string $messageTypeFieldName = 'message_type',
        string $messageNameFieldName = 'message_name',
        string $payloadFieldName = 'payload',
        string $createdAtFieldName = 'created_at',
        string $createdAtFieldFormat = 'Y-m-d H:i:s.u',
        string $processedAtFieldName = 'processed_at',
        string $processedAtFieldFormat = 'Y-m-d H:i:s.u',
        string $retryCountFieldName = 'retry_count',
        string $deadLetteredAtFieldName = 'dead_lettered_at',
        string $deadLetteredAtFieldFormat = 'Y-m-d H:i:s.u',
    ): OutboxTableSchema {
        return new OutboxTableSchema(
            $tableName,
            $idFieldName,
            $messageTypeFieldName,
            $messageNameFieldName,
            $payloadFieldName,
            $createdAtFieldName,
            $createdAtFieldFormat,
            $processedAtFieldName,
            $processedAtFieldFormat,
            $retryCountFieldName,
            $deadLetteredAtFieldName,
            $deadLetteredAtFieldFormat,
        );
    }
}
