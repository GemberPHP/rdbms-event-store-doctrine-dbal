<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Saga;

use Gember\DependencyContracts\EventStore\Saga\RdbmsSaga;
use DateTimeImmutable;
use DateMalformedStringException;

/**
 * @phpstan-import-type SagaRow from DoctrineRdbmsSagaStoreRepository
 */
final readonly class DoctrineDbalRdbmsSagaFactory
{
    /**
     * @param SagaRow $row
     *
     * @throws DateMalformedStringException
     */
    public function createFromRow(array $row): RdbmsSaga
    {
        return new RdbmsSaga(
            $row['id'],
            $row['sagaName'],
            $row['sagaIds'],
            $row['payload'],
            new DateTimeImmutable($row['createdAt']),
            $row['updatedAt'] !== null ? new DateTimeImmutable($row['updatedAt']) : null,
        );
    }
}
