<?php

declare(strict_types=1);

/*
 * Outbox stress test worker, spawned by OutboxConcurrencyStressTest.
 *
 * Processes the outbox like DefaultOutboxProcessor: get a batch of unprocessed messages, dispatch each one (here:
 * record it in outbox_dispatch_log, then a little handler work) and mark it as processed, until no messages are left.
 *
 * Usage: php outbox_worker.php <workerId> <batchSize> <startAt>
 * Prints a JSON summary to stdout: the number of messages this worker dispatched.
 */

use Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql\MySqlTestEnvironment;

require __DIR__ . '/../../../vendor/autoload.php';

$dsn = MySqlTestEnvironment::getDsn() ?? throw new RuntimeException('Missing ' . MySqlTestEnvironment::DSN_ENV);

/** @var list<string> $argv */
[, $workerId, $batchSize] = array_map(intval(...), $argv);
$startAt = (float) ($argv[3] ?? 0);

$connection = MySqlTestEnvironment::createConnection($dsn);
$repository = MySqlTestEnvironment::createOutboxRepository($connection);

if ($startAt > microtime(true)) {
    time_sleep_until($startAt);
}

$dispatched = 0;

while (($messages = $repository->getUnprocessedMessages($batchSize)) !== []) {
    foreach ($messages as $message) {
        $connection->insert('outbox_dispatch_log', ['message_id' => $message->id, 'worker' => $workerId]);

        // The handler's work
        usleep(random_int(0, 2000));

        $repository->markAsProcessed($message->id);
        ++$dispatched;
    }
}

echo json_encode(['dispatched' => $dispatched], JSON_THROW_ON_ERROR);
