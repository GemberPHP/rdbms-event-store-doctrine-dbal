<?php

declare(strict_types=1);

/*
 * Saga stress test worker, spawned by SagaStoreConcurrencyStressTest.
 *
 * Handles "student waitlisted" events for random courses like a saga would (see DefaultSagaEventExecutor):
 * load the course's waitlist saga (or start a new one), add an entry in memory, save the whole state.
 * Every entry this worker saved must end up in the stored saga.
 *
 * Usage: php saga_worker.php <workerId> <sagas> <attempts> <startAt>
 * Prints a JSON summary to stdout: the entries saved per saga id, and the number of retried failures per class.
 */

use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaNotFoundException;
use Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql\MySqlTestEnvironment;

require __DIR__ . '/../../../vendor/autoload.php';

$dsn = MySqlTestEnvironment::getDsn() ?? throw new RuntimeException('Missing ' . MySqlTestEnvironment::DSN_ENV);

/** @var list<string> $argv */
[, $workerId, $sagas, $attempts] = array_map(intval(...), $argv);
$startAt = (float) ($argv[4] ?? 0);

$repository = MySqlTestEnvironment::createSagaRepository(MySqlTestEnvironment::createConnection($dsn));

if ($startAt > microtime(true)) {
    time_sleep_until($startAt);
}

$saved = [];
$failures = [];

for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
    $sagaId = 'course-' . random_int(1, $sagas);
    $entry = sprintf('w%d-a%d', $workerId, $attempt);

    for ($retry = 1; $retry <= 100; ++$retry) {
        try {
            try {
                /** @var array{waitlist: list<string>} $state */
                $state = json_decode($repository->get('course-waitlist', $sagaId)->payload, true, flags: JSON_THROW_ON_ERROR);
            } catch (RdbmsSagaNotFoundException) {
                $state = ['waitlist' => []];
            }

            // Saga handler work between loading and saving
            usleep(random_int(0, 2000));

            $state['waitlist'][] = $entry;

            $repository->save('course-waitlist', json_encode($state, JSON_THROW_ON_ERROR), new DateTimeImmutable(), $sagaId);

            $saved[$sagaId][] = $entry;

            break;
        } catch (Throwable $exception) {
            // Concurrency failures are retried by reloading, like a retrying saga executor would
            $failures[$exception::class] = ($failures[$exception::class] ?? 0) + 1;
        }
    }
}

echo json_encode(['saved' => $saved, 'failures' => $failures], JSON_THROW_ON_ERROR);
