<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\TableSchema;

use Gember\RdbmsEventStoreDoctrineDbal\TableSchema\TableSchemaFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TableSchemaFactoryTest extends TestCase
{
    #[Test]
    public function itShouldCreateDefaultEventStoreTableSchema(): void
    {
        $schema = TableSchemaFactory::createDefaultEventStore();

        self::assertSame('event_store', $schema->tableName);
        self::assertSame('id', $schema->eventIdFieldName);
        self::assertSame('event_name', $schema->eventNameFieldName);
        self::assertSame('payload', $schema->payloadFieldName);
        self::assertSame('metadata', $schema->metadataFieldName);
        self::assertSame('applied_at', $schema->appliedAtFieldName);
        self::assertSame('Y-m-d H:i:s.u', $schema->appliedAtFieldFormat);
    }

    #[Test]
    public function itShouldCreateCustomEventStoreTableSchema(): void
    {
        $schema = TableSchemaFactory::createDefaultEventStore(
            'custom_event_store',
            'custom_event_id',
            'custom_event_name',
            'custom_payload',
            'custom_metadata',
            'custom_applied_at',
            'custom_applied_at_format',
        );

        self::assertSame('custom_event_store', $schema->tableName);
        self::assertSame('custom_event_id', $schema->eventIdFieldName);
        self::assertSame('custom_event_name', $schema->eventNameFieldName);
        self::assertSame('custom_payload', $schema->payloadFieldName);
        self::assertSame('custom_metadata', $schema->metadataFieldName);
        self::assertSame('custom_applied_at', $schema->appliedAtFieldName);
        self::assertSame('custom_applied_at_format', $schema->appliedAtFieldFormat);
    }

    #[Test]
    public function itShouldCreateDefaultEventStoreRelationTableSchema(): void
    {
        $schema = TableSchemaFactory::createDefaultEventStoreRelation();

        self::assertSame('event_store_relation', $schema->tableName);
        self::assertSame('event_id', $schema->eventIdFieldName);
        self::assertSame('domain_id', $schema->domainIdFieldName);
    }

    #[Test]
    public function itShouldCreateCustomEventStoreRelationTableSchema(): void
    {
        $schema = TableSchemaFactory::createDefaultEventStoreRelation(
            'custom_event_store_relation',
            'custom_event_id',
            'custom_domain_id',
        );

        self::assertSame('custom_event_store_relation', $schema->tableName);
        self::assertSame('custom_event_id', $schema->eventIdFieldName);
        self::assertSame('custom_domain_id', $schema->domainIdFieldName);
    }
}
