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
    private const int ATTEMPTS_PER_WORKER = 40;

    #[Test]
    public function itShouldNeverExceedCapacityAndNeverDeadlock(): void
    {
        $this->assertCapacityHoldsUnderStress(courses: 3, capacity: 15, students: 0, skewMs: 0);
    }

    /**
     * Writers whose streams overlap via students shared across courses, with applied_at out of commit order:
     * an event committed after a writer's load, but stamped earlier than events it loaded, must still be
     * detected by the optimistic lock.
     */
    #[Test]
    public function itShouldNeverExceedCapacityWithSharedTagsAndClockSkew(): void
    {
        $this->assertCapacityHoldsUnderStress(courses: 3, capacity: 5, students: 12, skewMs: 30);
    }

    /**
     * Each worker loads and saves inside its own transaction, like an application wrapping the command handler in a
     * transaction (e.g. a doctrine_transaction middleware). Under REPEATABLE READ that transaction reads from the
     * snapshot taken at the load, so the optimistic lock must still detect events committed after the load.
     *
     * Deadlocks are allowed here: lock rows can't be committed upfront inside another transaction, so a writer
     * rolling back a lock row it created can deadlock the writers waiting on it. They are retried by the worker.
     */
    #[Test]
    public function itShouldNeverExceedCapacityWhenLoadingInsideAnApplicationTransaction(): void
    {
        $this->assertCapacityHoldsUnderStress(courses: 3, capacity: 5, students: 12, skewMs: 0, outerTransaction: true, allowDeadlocks: true);
    }

    /**
     * Writers use the same course id in different spellings (course-1 / COURSE-1). Every stream, as the event store
     * loads and counts it, must stay within capacity: writers whose streams contain each other's events must lock each
     * other out.
     */
    #[Test]
    public function itShouldNeverExceedCapacityWhenTagsDifferOnlyInCase(): void
    {
        $this->assertCapacityHoldsUnderStress(courses: 1, capacity: 5, students: 0, skewMs: 0, caseVariants: true);
    }

    private function assertCapacityHoldsUnderStress(
        int $courses,
        int $capacity,
        int $students,
        int $skewMs,
        bool $outerTransaction = false,
        bool $allowDeadlocks = false,
        bool $caseVariants = false,
    ): void {
        $dsn = MySqlTestEnvironment::getDsn();

        if ($dsn === null) {
            self::markTestSkipped(sprintf('Set %s to run MySQL integration tests', MySqlTestEnvironment::DSN_ENV));
        }

        $connection = MySqlTestEnvironment::createConnection($dsn);
        MySqlTestEnvironment::resetSchema($connection);

        // Start all workers at the same moment, after process start-up, so they actually compete
        $startAt = microtime(true) + 1.0;
        $processes = [];

        try {
            for ($workerId = 1; $workerId <= self::WORKERS; ++$workerId) {
                $processes[] = $this->spawnWorker([$workerId, $courses, $capacity, self::ATTEMPTS_PER_WORKER, $startAt, $students, $skewMs, (int) $outerTransaction, (int) $caseVariants]);
            }
        } catch (RuntimeException $exception) {
            foreach ($processes as [$process]) {
                proc_terminate($process);
            }

            throw $exception;
        }

        $conflicts = 0;
        $deadlocks = 0;
        $lockWaitTimeouts = 0;

        foreach ($processes as [$process, $stdout, $stderrFile]) {
            $output = (string) stream_get_contents($stdout);
            fclose($stdout);
            $exitCode = proc_close($process);
            $errors = (string) file_get_contents($stderrFile);
            unlink($stderrFile);

            self::assertSame(0, $exitCode, sprintf("Worker failed:\n%s\n%s", $output, $errors));

            /** @var array{conflicts: int, deadlocks: int, lockWaitTimeouts: int} $summary */
            $summary = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $conflicts += $summary['conflicts'];
            $deadlocks += $summary['deadlocks'];
            $lockWaitTimeouts += $summary['lockWaitTimeouts'];
        }

        // Grouped as the event store compares tags (the column's collation): one row per stream
        /** @var list<array{domain_tag: string, subscriptions: int|string}> $rows */
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
                SELECT esr.domain_tag, COUNT(*) AS subscriptions
                FROM event_store es
                JOIN event_store_relation esr ON es.id = esr.event_id
                WHERE es.event_name = 'StudentSubscribed' AND LOWER(esr.domain_tag) LIKE 'course-%'
                GROUP BY esr.domain_tag
                SQL,
        );

        $connection->close();

        // With case variants, each spelling is a stream of its own once tags are compared exactly
        if (!$caseVariants) {
            self::assertCount($courses, $rows);
        }

        foreach ($rows as $row) {
            self::assertSame(
                $capacity,
                (int) $row['subscriptions'],
                sprintf('Course %s has %d subscriptions, capacity is %d', $row['domain_tag'], $row['subscriptions'], $capacity),
            );
        }

        self::assertGreaterThan(0, $conflicts, 'Workers did not compete, the test proves nothing');
        if (!$allowDeadlocks) {
            self::assertSame(0, $deadlocks, 'Deadlocks occurred between concurrent writers');
        }
        self::assertSame(0, $lockWaitTimeouts, 'Lock wait timeouts occurred between concurrent writers');
    }

    /**
     * @param list<float|int> $arguments
     *
     * @return array{resource, resource, string}
     */
    private function spawnWorker(array $arguments): array
    {
        // Stderr goes to a file: a full stderr pipe would block the worker while stdout is being read
        $stderrFile = (string) tempnam(sys_get_temp_dir(), 'gember-worker');

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/worker.php', ...array_map(strval(...), $arguments)],
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
