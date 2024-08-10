<?php

declare(strict_types=1);

namespace Gember\EventStoreDoctrineDbal\Test\TestDoubles;

use Gember\EventSourcing\AggregateRoot\AggregateRootId;
use Gember\EventSourcing\EventStore\EventEnvelope;
use Gember\EventStoreDoctrineDbal\Repository\EventStoreRepository;
use Throwable;

/**
 * @phpstan-import-type RowPayload from EventStoreRepository
 */
final class TestEventStoreRepository implements EventStoreRepository
{
    private ?Throwable $exception = null;

    /**
     * @var list<RowPayload>
     */
    private array $rows = [];

    private ?int $playhead = null;

    public function addThrows(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    /**
     * @param RowPayload $rows
     */
    public function addRow(array ...$rows): void
    {
        $this->rows = array_values([...$this->rows, ...$rows]);
    }

    public function addLastPlayhead(int $playhead): void
    {
        $this->playhead = $playhead;
    }

    /**
     * @throws Throwable
     *
     * @return list<RowPayload>
     */
    public function getRows(AggregateRootId $aggregateRootId, int $playhead): array
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->rows;
    }

    public function saveRows(AggregateRootId $aggregateRootId, EventEnvelope ...$envelopes): void
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }
    }

    public function getLastPlayhead(AggregateRootId $aggregateRootId): ?int
    {
        return $this->playhead;
    }
}
