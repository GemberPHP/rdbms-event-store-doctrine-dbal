<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal;

use DateTimeImmutable;
use Gember\EventSourcing\EventStore\Rdbms\RdbmsEvent;
use JsonException;
use DateMalformedStringException;

/**
 * @phpstan-import-type Row from DoctrineDbalRdbmsEventStoreRepository
 */
final readonly class DoctrineDbalRdbmsEventFactory
{
    /**
     * @param Row $row
     *
     * @throws JsonException
     * @throws DateMalformedStringException
     */
    public function createFromRow(array $row): RdbmsEvent
    {
        return new RdbmsEvent(
            $row['eventId'],
            [],
            $row['eventName'],
            $row['payload'],
            (array) json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR),
            new DateTimeImmutable($row['appliedAt']),
        );
    }
}
