<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Spawns concurrent worker processes (see worker.php) that compete for course capacity
 * through overlapping and identical DCB boundaries.
 *
 * @internal
 */
#[Group('mysql-stress')]
final class EventStoreConcurrencyStressTest extends TestCase
{
    private const int WORKERS = 8;
    private const int COURSES = 3;
    private const int CAPACITY = 15;
    private const int ATTEMPTS_PER_WORKER = 40;

    #[Test]
    public function itShouldNeverExceedCapacityAndNeverDeadlock(): void
    {
        $dsn = MySqlTestEnvironment::getDsn();

        if ($dsn === null) {
            self::markTestSkipped(sprintf('Set %s to run MySQL integration tests', MySqlTestEnvironment::DSN_ENV));
        }

        $connection = MySqlTestEnvironment::createConnection($dsn);
        MySqlTestEnvironment::resetSchema($connection);

        $processes = [];

        for ($workerId = 1; $workerId <= self::WORKERS; ++$workerId) {
            $processes[] = $this->spawnWorker($workerId);
        }

        $deadlocks = 0;
        $lockWaitTimeouts = 0;

        foreach ($processes as [$process, $stdout, $stderr]) {
            $output = (string) stream_get_contents($stdout);
            $errors = (string) stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);
            $exitCode = proc_close($process);

            self::assertSame(0, $exitCode, sprintf("Worker failed:\n%s\n%s", $output, $errors));

            /** @var array{deadlocks: int, lockWaitTimeouts: int} $summary */
            $summary = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $deadlocks += $summary['deadlocks'];
            $lockWaitTimeouts += $summary['lockWaitTimeouts'];
        }

        /** @var list<array{domain_tag: string, subscriptions: int|string}> $rows */
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
                SELECT esr.domain_tag, COUNT(*) AS subscriptions
                FROM event_store es
                JOIN event_store_relation esr ON es.id = esr.event_id
                WHERE es.event_name = 'StudentSubscribed' AND esr.domain_tag LIKE 'course-%'
                GROUP BY esr.domain_tag
                SQL,
        );

        $connection->close();

        self::assertCount(self::COURSES, $rows);

        foreach ($rows as $row) {
            self::assertSame(
                self::CAPACITY,
                (int) $row['subscriptions'],
                sprintf('Course %s has %d subscriptions, capacity is %d', $row['domain_tag'], $row['subscriptions'], self::CAPACITY),
            );
        }

        self::assertSame(0, $deadlocks, 'Deadlocks occurred between concurrent writers');
        self::assertSame(0, $lockWaitTimeouts, 'Lock wait timeouts occurred between concurrent writers');
    }

    /**
     * @return array{resource, resource, resource}
     */
    private function spawnWorker(int $workerId): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/worker.php',
                (string) $workerId,
                (string) self::COURSES,
                (string) self::CAPACITY,
                (string) self::ATTEMPTS_PER_WORKER,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if ($process === false) {
            throw new RuntimeException('Could not spawn worker process');
        }

        /** @var array{1: resource, 2: resource} $pipes */
        return [$process, $pipes[1], $pipes[2]];
    }
}
