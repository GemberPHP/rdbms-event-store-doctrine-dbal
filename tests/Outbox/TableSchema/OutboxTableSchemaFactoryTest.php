<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Outbox\TableSchema;

use Gember\RdbmsEventStoreDoctrineDbal\Outbox\TableSchema\OutboxTableSchemaFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class OutboxTableSchemaFactoryTest extends TestCase
{
    #[Test]
    public function itShouldCreateDefaultOutboxTableSchema(): void
    {
        $schema = OutboxTableSchemaFactory::createDefault();

        self::assertSame('outbox', $schema->tableName);
        self::assertSame('id', $schema->idFieldName);
        self::assertSame('message_type', $schema->messageTypeFieldName);
        self::assertSame('message_name', $schema->messageNameFieldName);
        self::assertSame('payload', $schema->payloadFieldName);
        self::assertSame('created_at', $schema->createdAtFieldName);
        self::assertSame('Y-m-d H:i:s.u', $schema->createdAtFieldFormat);
        self::assertSame('processed_at', $schema->processedAtFieldName);
        self::assertSame('Y-m-d H:i:s.u', $schema->processedAtFieldFormat);
        self::assertSame('retry_count', $schema->retryCountFieldName);
        self::assertSame('dead_lettered_at', $schema->deadLetteredAtFieldName);
        self::assertSame('Y-m-d H:i:s.u', $schema->deadLetteredAtFieldFormat);
    }

    #[Test]
    public function itShouldCreateCustomOutboxTableSchema(): void
    {
        $schema = OutboxTableSchemaFactory::createDefault(
            'custom_outbox',
            'custom_id',
            'custom_message_type',
            'custom_message_name',
            'custom_payload',
            'custom_created_at',
            'custom_created_at_format',
            'custom_processed_at',
            'custom_processed_at_format',
            'custom_retry_count',
            'custom_dead_lettered_at',
            'custom_dead_lettered_at_format',
        );

        self::assertSame('custom_outbox', $schema->tableName);
        self::assertSame('custom_id', $schema->idFieldName);
        self::assertSame('custom_message_type', $schema->messageTypeFieldName);
        self::assertSame('custom_message_name', $schema->messageNameFieldName);
        self::assertSame('custom_payload', $schema->payloadFieldName);
        self::assertSame('custom_created_at', $schema->createdAtFieldName);
        self::assertSame('custom_created_at_format', $schema->createdAtFieldFormat);
        self::assertSame('custom_processed_at', $schema->processedAtFieldName);
        self::assertSame('custom_processed_at_format', $schema->processedAtFieldFormat);
        self::assertSame('custom_retry_count', $schema->retryCountFieldName);
        self::assertSame('custom_dead_lettered_at', $schema->deadLetteredAtFieldName);
        self::assertSame('custom_dead_lettered_at_format', $schema->deadLetteredAtFieldFormat);
    }
}
