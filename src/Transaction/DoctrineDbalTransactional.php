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
        return $this->connection->transactional(static fn() => $operation());
    }
}
