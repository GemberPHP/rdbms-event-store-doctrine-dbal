<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Transaction;

use Doctrine\DBAL\Connection;
use Gember\DependencyContracts\Util\Transaction\Transactional;
use Override;

final readonly class DoctrineDbalTransactional implements Transactional
{
    public function __construct(
        private Connection $connection,
    ) {}

    #[Override]
    public function transactional(callable $operation): mixed
    {
        /*
         * When a transaction is already active (e.g. opened by the application), run within it rather than
         * opening a savepoint: the outer transaction owns atomicity and rollback. A savepoint would break on a
         * deadlock, as the database then rolls back the whole transaction including the savepoint.
         */
        if ($this->connection->isTransactionActive()) {
            return $operation();
        }

        return $this->connection->transactional(static fn() => $operation());
    }

    #[Override]
    public function isActive(): bool
    {
        return $this->connection->isTransactionActive();
    }
}
