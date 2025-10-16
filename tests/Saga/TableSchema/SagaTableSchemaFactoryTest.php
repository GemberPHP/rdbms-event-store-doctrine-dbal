<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Saga\TableSchema;

use Gember\RdbmsEventStoreDoctrineDbal\Saga\TableSchema\SagaTableSchemaFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
final class SagaTableSchemaFactoryTest extends TestCase
{
    #[Test]
    public function itShouldCreateDefaultSagaStoreTableSchema(): void
    {
        $schema = SagaTableSchemaFactory::createDefaultSagaStore();

        self::assertSame('saga_store', $schema->tableName);
        self::assertSame('id', $schema->idFieldName);
        self::assertSame('saga_name', $schema->sagaNameFieldName);
        self::assertSame('payload', $schema->payloadFieldName);
        self::assertSame('created_at', $schema->createdAtFieldName);
        self::assertSame('Y-m-d H:i:s.u', $schema->createdAtFieldFormat);
        self::assertSame('updated_at', $schema->updatedAtFieldName);
        self::assertSame('Y-m-d H:i:s.u', $schema->updatedAtFieldFormat);
    }

    #[Test]
    public function itShouldCreateCustomSagaStoreTableSchema(): void
    {
        $schema = SagaTableSchemaFactory::createDefaultSagaStore(
            'custom_saga_store',
            'custom_id',
            'custom_saga_name',
            'custom_payload',
            'custom_created_at',
            'custom_created_at_format',
            'custom_updated_at',
            'custom_updated_at_format',
        );

        self::assertSame('custom_saga_store', $schema->tableName);
        self::assertSame('custom_id', $schema->idFieldName);
        self::assertSame('custom_saga_name', $schema->sagaNameFieldName);
        self::assertSame('custom_payload', $schema->payloadFieldName);
        self::assertSame('custom_created_at', $schema->createdAtFieldName);
        self::assertSame('custom_created_at_format', $schema->createdAtFieldFormat);
        self::assertSame('custom_updated_at', $schema->updatedAtFieldName);
        self::assertSame('custom_updated_at_format', $schema->updatedAtFieldFormat);
    }

    #[Test]
    public function itShouldCreateDefaultSagaStoreRelationTableSchema(): void
    {
        $schema = SagaTableSchemaFactory::createDefaultSagaStoreRelation();

        self::assertSame('saga_store_relation', $schema->tableName);
        self::assertSame('id', $schema->idFieldName);
        self::assertSame('saga_id', $schema->sagaIdFieldName);
    }

    #[Test]
    public function itShouldCreateCustomSagaStoreRelationTableSchema(): void
    {
        $schema = SagaTableSchemaFactory::createDefaultSagaStoreRelation(
            'custom_saga_store',
            'custom_id',
            'custom_saga_id',
        );

        self::assertSame('custom_saga_store', $schema->tableName);
        self::assertSame('custom_id', $schema->idFieldName);
        self::assertSame('custom_saga_id', $schema->sagaIdFieldName);
    }
}
