<?php

declare(strict_types=1);

/*
 * Saga stress test worker, spawned by SagaStoreConcurrencyStressTest.
 *
 * Handles "student waitlisted" events for random courses like a saga would (see DefaultSagaEventExecutor):
 * load the course's waitlist saga (or start a new one), add an entry in memory, save the whole state.
 * Only concurrency conflicts are retried (by reloading); any other failure fails the worker.
 *
 * Usage: php saga_worker.php <workerId> <sagas> <attempts> <startAt> [<outerTransaction>]
 * With <outerTransaction> 1, each attempt loads and saves inside its own transaction, like an application
 * wrapping the handler in a transaction.
 * Prints a JSON summary to stdout: the entries saved per saga id, retried conflicts per underlying cause, and the
 * number of attempts that ran out of retries.
 */

use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaConcurrencyException;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaNotFoundException;
use Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql\MySqlTestEnvironment;

require __DIR__ . '/../../../vendor/autoload.php';

$dsn = MySqlTestEnvironment::getDsn() ?? throw new RuntimeException('Missing ' . MySqlTestEnvironment::DSN_ENV);

/** @var list<string> $argv */
[, $workerId, $sagas, $attempts] = array_map(intval(...), $argv);
$startAt = (float) ($argv[4] ?? 0);
$outerTransaction = (bool) ($argv[5] ?? false);

$connection = MySqlTestEnvironment::createConnection($dsn);
$repository = MySqlTestEnvironment::createSagaRepository($connection);

if ($startAt > microtime(true)) {
    time_sleep_until($startAt);
}

$saved = [];
$conflicts = [];
$exhausted = 0;

for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
    $sagaId = 'course-' . random_int(1, $sagas);
    $entry = sprintf('w%d-a%d', $workerId, $attempt);

    for ($retry = 1;; ++$retry) {
        if ($outerTransaction) {
            $connection->beginTransaction();
        }

        try {
            try {
                $previous = $repository->get('course-waitlist', $sagaId);

                /** @var array{waitlist: list<string>} $state */
                $state = json_decode($previous->payload, true, flags: JSON_THROW_ON_ERROR);
            } catch (RdbmsSagaNotFoundException) {
                $previous = null;
                $state = ['waitlist' => []];
            }

            // Saga handler work between loading and saving
            usleep(random_int(0, 2000));

            $state['waitlist'][] = $entry;

            $repository->save('course-waitlist', json_encode($state, JSON_THROW_ON_ERROR), new DateTimeImmutable(), $previous, $sagaId);

            if ($outerTransaction) {
                $connection->commit();
            }

            $saved[$sagaId][] = $entry;

            break;
        } catch (RdbmsSagaConcurrencyException $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $cause = $exception->getPrevious() === null ? 'revision' : $exception->getPrevious()::class;
            $conflicts[$cause] = ($conflicts[$cause] ?? 0) + 1;

            if ($retry >= 100) {
                ++$exhausted;

                break;
            }
        }
    }
}

echo json_encode(['saved' => $saved, 'conflicts' => $conflicts, 'exhausted' => $exhausted], JSON_THROW_ON_ERROR);
