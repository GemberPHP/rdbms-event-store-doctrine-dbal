<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Outbox;

use DateTimeImmutable;
use DateMalformedStringException;
use Gember\DependencyContracts\Outbox\Rdbms\RdbmsOutboxMessage;

/**
 * @phpstan-import-type OutboxRow from DoctrineDbalRdbmsOutboxRepository
 */
final readonly class DoctrineDbalRdbmsOutboxFactory
{
    /**
     * @param OutboxRow $row
     *
     * @throws DateMalformedStringException
     */
    public function createFromRow(array $row): RdbmsOutboxMessage
    {
        return new RdbmsOutboxMessage(
            $row['id'],
            $row['messageType'],
            $row['messageName'],
            $row['payload'],
            new DateTimeImmutable($row['createdAt']),
            (int) $row['retryCount'],
        );
    }
}
