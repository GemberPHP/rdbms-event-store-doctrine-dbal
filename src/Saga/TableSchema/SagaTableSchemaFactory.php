<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Saga\TableSchema;

final readonly class SagaTableSchemaFactory
{
    public static function createDefaultSagaStore(
        string $tableName = 'saga_store',
        string $idFieldName = 'id',
        string $sagaNameFieldName = 'saga_name',
        string $payloadFieldName = 'payload',
        string $createdAtFieldName = 'created_at',
        string $createdAtFieldFormat = 'Y-m-d H:i:s.u',
        string $updatedAtFieldName = 'updated_at',
        string $updatedAtFieldFormat = 'Y-m-d H:i:s.u',
    ): SagaStoreTableSchema {
        return new SagaStoreTableSchema(
            $tableName,
            $idFieldName,
            $sagaNameFieldName,
            $payloadFieldName,
            $createdAtFieldName,
            $createdAtFieldFormat,
            $updatedAtFieldName,
            $updatedAtFieldFormat,
        );
    }

    public static function createDefaultSagaStoreRelation(
        string $tableName = 'saga_store_relation',
        string $idFieldName = 'id',
        string $sagaIdFieldName = 'saga_id',
    ): SagaStoreRelationTableSchema {
        return new SagaStoreRelationTableSchema(
            $tableName,
            $idFieldName,
            $sagaIdFieldName,
        );
    }
}
