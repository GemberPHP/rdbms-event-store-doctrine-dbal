<?php

declare(strict_types=1);

/*
 * Deadlock counterpart, spawned by EventStoreConcurrencyTest.
 *
 * Saves events for course-1 (after event-1) inside a transaction (holding its lock rows and writing enough rows to not be
 * chosen as deadlock victim), then requests the blocker row held by the test, closing the lock cycle.
 *
 * Usage: php deadlock_child.php <blockerKey>
 * Prints "committed" on success.
 */

use Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql\MySqlTestEnvironment;

require __DIR__ . '/../../../vendor/autoload.php';

$dsn = MySqlTestEnvironment::getDsn() ?? throw new RuntimeException('Missing ' . MySqlTestEnvironment::DSN_ENV);
$blockerKey = (string) ($argv[1] ?? '');

$connection = MySqlTestEnvironment::createConnection($dsn);
$repository = MySqlTestEnvironment::createRepository($connection);

$connection->beginTransaction();

$repository->saveEvents(['course-1'], ['StudentSubscribed'], 1, array_map(
    fn(int $i) => MySqlTestEnvironment::event('child-' . $i, ['course-1'], 'StudentSubscribed'),
    range(1, 5),
));

$connection->fetchOne('SELECT boundary_hash FROM event_store_lock WHERE boundary_hash = ? FOR UPDATE', [$blockerKey]);

$connection->commit();

echo 'committed';
