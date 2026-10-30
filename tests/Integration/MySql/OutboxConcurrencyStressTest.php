<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Gember\DependencyContracts\Outbox\Rdbms\RdbmsOutboxMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Spawns concurrent outbox processors (see outbox_worker.php) that start at the same moment and process the same
 * outbox until it's empty: every message must be dispatched exactly once.
 *
 * @internal
 */
#[Group('mysql-stress')]
final class OutboxConcurrencyStressTest extends TestCase
{
    private const int WORKERS = 4;
    private const int MESSAGES = 1000;
    private const int BATCH_SIZE = 20;

    #[Test]
    public function itShouldDispatchEveryMessageExactlyOnceWithConcurrentProcessors(): void
    {
        $this->assertEveryMessageDispatchedExactlyOnce(withoutSkipLocked: false);
    }

    /**
     * Without SKIP LOCKED (SQLite, MySQL 5.7, MariaDB < 10.6), concurrent processors select the same messages: only
     * the guarded claim keeps them from dispatching the same ones.
     */
    #[Test]
    public function itShouldDispatchEveryMessageExactlyOnceWithConcurrentProcessorsWithoutSkipLocked(): void
    {
        $this->assertEveryMessageDispatchedExactlyOnce(withoutSkipLocked: true);
    }

    private function assertEveryMessageDispatchedExactlyOnce(bool $withoutSkipLocked): void
    {
        $dsn = MySqlTestEnvironment::getDsn();

        if ($dsn === null) {
            self::markTestSkipped(sprintf('Set %s to run MySQL integration tests', MySqlTestEnvironment::DSN_ENV));
        }

        $connection = MySqlTestEnvironment::createConnection($dsn);
        MySqlTestEnvironment::resetSchema($connection);
        $connection->executeStatement('CREATE TABLE outbox_dispatch_log (n int AUTO_INCREMENT PRIMARY KEY, message_id varchar(50) NOT NULL, worker int NOT NULL)');

        $repository = MySqlTestEnvironment::createOutboxRepository($connection);

        for ($i = 1; $i <= self::MESSAGES; ++$i) {
            $repository->save(new RdbmsOutboxMessage(sprintf('message-%05d', $i), 'event', 'SomeEvent', '{}', new DateTimeImmutable()));
        }

        // Start all workers at the same moment, after process start-up, so they actually compete
        $startAt = microtime(true) + 1.0;
        $processes = [];

        try {
            for ($workerId = 1; $workerId <= self::WORKERS; ++$workerId) {
                $processes[$workerId] = $this->spawnWorker([$workerId, self::BATCH_SIZE, $startAt, (int) $withoutSkipLocked]);
            }
        } catch (RuntimeException $exception) {
            foreach ($processes as [$process]) {
                proc_terminate($process);
            }

            throw $exception;
        }

        $dispatchedPerWorker = [];

        foreach ($processes as $workerId => [$process, $stdout, $stderrFile]) {
            $output = (string) stream_get_contents($stdout);
            fclose($stdout);
            $exitCode = proc_close($process);
            $errors = (string) file_get_contents($stderrFile);
            unlink($stderrFile);

            self::assertSame(0, $exitCode, sprintf("Worker failed:\n%s\n%s", $output, $errors));

            /** @var array{dispatched: int} $summary */
            $summary = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $dispatchedPerWorker[$workerId] = $summary['dispatched'];
        }

        $dispatches = $this->fetchCount($connection, 'SELECT COUNT(*) FROM outbox_dispatch_log');
        $duplicated = $this->fetchCount($connection, 'SELECT COUNT(*) FROM (SELECT message_id FROM outbox_dispatch_log GROUP BY message_id HAVING COUNT(*) > 1) d');
        $unprocessed = $this->fetchCount($connection, 'SELECT COUNT(*) FROM outbox WHERE processed_at IS NULL');

        $connection->close();

        self::assertSame(0, $unprocessed, 'Not every message was processed');
        self::assertSame(
            0,
            $duplicated,
            sprintf('%d of %d messages were dispatched more than once (%d dispatches)', $duplicated, self::MESSAGES, $dispatches),
        );
        self::assertSame(self::MESSAGES, $dispatches);

        foreach ($dispatchedPerWorker as $workerId => $dispatched) {
            self::assertGreaterThan(0, $dispatched, sprintf('Worker %d dispatched nothing: the workers did not compete', $workerId));
        }
    }

    private function fetchCount(Connection $connection, string $sql): int
    {
        $count = $connection->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @param list<float|int> $arguments
     *
     * @return array{resource, resource, string}
     */
    private function spawnWorker(array $arguments): array
    {
        // Stderr goes to a file: a full stderr pipe would block the worker while stdout is being read
        $stderrFile = (string) tempnam(sys_get_temp_dir(), 'gember-outbox-worker');

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/outbox_worker.php', ...array_map(strval(...), $arguments)],
            [1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']],
            $pipes,
        );

        if ($process === false) {
            throw new RuntimeException('Could not spawn worker process');
        }

        /** @var array{1: resource} $pipes */
        return [$process, $pipes[1], $stderrFile];
    }
}
