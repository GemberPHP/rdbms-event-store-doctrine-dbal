<?php

declare(strict_types=1);

namespace Gember\EventStoreDoctrineDbal\Test\TableSchema;

use Gember\EventStoreDoctrineDbal\TableSchema\TableSchemaFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TableSchemaFactoryTest extends TestCase
{
    #[Test]
    public function itShouldCreateDefaultTableSchema(): void
    {
        $schema = TableSchemaFactory::createDefault();

        self::assertSame('event_store', $schema->tableName);
        self::assertSame('id', $schema->eventIdFieldName);
        self::assertSame('aggregate_root_id', $schema->aggregateRootIdFieldName);
        self::assertSame('event_name', $schema->eventNameFieldName);
        self::assertSame('payload', $schema->payloadFieldName);
        self::assertSame('playhead', $schema->playheadFieldName);
        self::assertSame('metadata', $schema->metadataFieldName);
        self::assertSame('applied_at', $schema->appliedAtFieldName);
        self::assertSame('Y-m-d H:i:s.u', $schema->appliedAtFieldFormat);
    }

    #[Test]
    public function itShouldCreateTableSchema(): void
    {
        $schema = TableSchemaFactory::createDefault(
            'custom_event_store',
            'custom_event_id',
            'custom_aggregate_root_id',
            'custom_event_name',
            'custom_payload',
            'custom_playhead',
            'custom_metadata',
            'custom_applied_at',
            'custom_applied_at_format',
        );

        self::assertSame('custom_event_store', $schema->tableName);
        self::assertSame('custom_event_id', $schema->eventIdFieldName);
        self::assertSame('custom_aggregate_root_id', $schema->aggregateRootIdFieldName);
        self::assertSame('custom_event_name', $schema->eventNameFieldName);
        self::assertSame('custom_payload', $schema->payloadFieldName);
        self::assertSame('custom_playhead', $schema->playheadFieldName);
        self::assertSame('custom_metadata', $schema->metadataFieldName);
        self::assertSame('custom_applied_at', $schema->appliedAtFieldName);
        self::assertSame('custom_applied_at_format', $schema->appliedAtFieldFormat);
    }
}
