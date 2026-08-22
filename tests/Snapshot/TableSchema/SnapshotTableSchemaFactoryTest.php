<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Snapshot\TableSchema;

use Gember\RdbmsEventStoreDoctrineDbal\Snapshot\TableSchema\SnapshotTableSchemaFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SnapshotTableSchemaFactoryTest extends TestCase
{
    #[Test]
    public function itShouldCreateDefaultSnapshotStoreTableSchema(): void
    {
        $schema = SnapshotTableSchemaFactory::createDefault();

        self::assertSame('snapshot_store', $schema->tableName);
        self::assertSame('boundary_hash', $schema->boundaryHashFieldName);
        self::assertSame('last_event_id', $schema->lastEventIdFieldName);
        self::assertSame('event_count', $schema->eventCountFieldName);
        self::assertSame('payload', $schema->payloadFieldName);
        self::assertSame('created_at', $schema->createdAtFieldName);
        self::assertSame('updated_at', $schema->updatedAtFieldName);
    }
}
