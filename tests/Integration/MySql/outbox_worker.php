<?php

declare(strict_types=1);

/*
 * Outbox stress test worker, spawned by OutboxConcurrencyStressTest.
 *
 * Processes the outbox like DefaultOutboxProcessor: get a batch of unprocessed messages, dispatch each one (here:
 * record it in outbox_dispatch_log, then a little handler work) and mark it as processed, until every message is
 * processed (polling again after an empty batch, as the other processors may hold the remaining messages).
 *
 * Usage: php outbox_worker.php <workerId> <batchSize> <startAt> [<withoutSkipLocked>]
 * With <withoutSkipLocked> 1, the connection declares MySQL 5.7, which has no SKIP LOCKED: processors then only rely on
 * the guarded claim (as on SQLite, MySQL 5.7 or MariaDB < 10.6).
 * Prints a JSON summary to stdout: the number of messages this worker dispatched.
 */

use Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql\MySqlTestEnvironment;

require __DIR__ . '/../../../vendor/autoload.php';

$dsn = MySqlTestEnvironment::getDsn() ?? throw new RuntimeException('Missing ' . MySqlTestEnvironment::DSN_ENV);

/** @var list<string> $argv */
[, $workerId, $batchSize] = array_map(intval(...), $argv);
$startAt = (float) ($argv[3] ?? 0);
$withoutSkipLocked = (bool) ($argv[4] ?? false);

if ($withoutSkipLocked) {
    $dsn .= (str_contains($dsn, '?') ? '&' : '?') . 'serverVersion=5.7.44';
}

$connection = MySqlTestEnvironment::createConnection($dsn);
$repository = MySqlTestEnvironment::createOutboxRepository($connection);

if ($startAt > microtime(true)) {
    time_sleep_until($startAt);
}

$dispatched = 0;

while (true) {
    $messages = $repository->getUnprocessedMessages($batchSize);

    if ($messages === []) {
        // Like a polling processor: an empty batch can mean the other processors claimed the remaining messages
        if ($connection->fetchOne('SELECT COUNT(*) FROM outbox WHERE processed_at IS NULL') == 0) {
            break;
        }

        usleep(1000);

        continue;
    }

    foreach ($messages as $message) {
        $connection->insert('outbox_dispatch_log', ['message_id' => $message->id, 'worker' => $workerId]);

        // The handler's work
        usleep(random_int(0, 2000));

        $repository->markAsProcessed($message->id);
        ++$dispatched;
    }
}

echo json_encode(['dispatched' => $dispatched], JSON_THROW_ON_ERROR);
