<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql;

use Doctrine\DBAL\Exception\DeadlockException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Spawns concurrent worker processes (see saga_worker.php) that load, change and save the same sagas, like
 * concurrent events for one saga. Every change that was saved successfully must be in the stored saga.
 *
 * @internal
 */
#[Group('mysql-stress')]
final class SagaStoreConcurrencyStressTest extends TestCase
{
    private const int WORKERS = 8;
    private const int SAGAS = 3;
    private const int ATTEMPTS_PER_WORKER = 30;

    #[Test]
    public function itShouldNeverLoseSavedSagaChanges(): void
    {
        $this->assertNoChangesLostUnderStress(outerTransaction: false);
    }

    /**
     * Each worker loads and saves inside its own transaction, like an application wrapping the saga handler in a
     * transaction. Under REPEATABLE READ the load reads from that transaction's snapshot; the save must still detect
     * changes committed after it.
     */
    #[Test]
    public function itShouldNeverLoseSavedSagaChangesInsideApplicationTransactions(): void
    {
        $this->assertNoChangesLostUnderStress(outerTransaction: true);
    }

    private function assertNoChangesLostUnderStress(bool $outerTransaction): void
    {
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
                $processes[] = $this->spawnWorker([$workerId, self::SAGAS, self::ATTEMPTS_PER_WORKER, $startAt, (int) $outerTransaction]);
            }
        } catch (RuntimeException $exception) {
            foreach ($processes as [$process]) {
                proc_terminate($process);
            }

            throw $exception;
        }

        /** @var array<string, list<string>> $savedPerSaga */
        $savedPerSaga = [];

        /** @var array<string, int> $conflicts */
        $conflicts = [];
        $exhausted = 0;

        foreach ($processes as [$process, $stdout, $stderrFile]) {
            $output = (string) stream_get_contents($stdout);
            fclose($stdout);
            $exitCode = proc_close($process);
            $errors = (string) file_get_contents($stderrFile);
            unlink($stderrFile);

            // Anything other than a concurrency conflict fails the worker
            self::assertSame(0, $exitCode, sprintf("Worker failed:\n%s\n%s", $output, $errors));

            /** @var array{saved: array<string, list<string>>, conflicts: array<string, int>, exhausted: int} $summary */
            $summary = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            foreach ($summary['saved'] as $sagaId => $entries) {
                $savedPerSaga[$sagaId] = [...$savedPerSaga[$sagaId] ?? [], ...$entries];
            }

            foreach ($summary['conflicts'] as $cause => $count) {
                $conflicts[$cause] = ($conflicts[$cause] ?? 0) + $count;
            }

            $exhausted += $summary['exhausted'];
        }

        self::assertSame(0, $exhausted, 'Saves ran out of retries');
        self::assertSame(
            self::WORKERS * self::ATTEMPTS_PER_WORKER,
            array_sum(array_map(count(...), $savedPerSaga)),
            'Not every change was saved',
        );

        $repository = MySqlTestEnvironment::createSagaRepository($connection);

        foreach ($savedPerSaga as $sagaId => $entries) {
            $saga = $repository->get('course-waitlist', $sagaId);

            /** @var array{waitlist: list<string>} $state */
            $state = json_decode($saga->payload, true, flags: JSON_THROW_ON_ERROR);

            $lost = array_values(array_diff($entries, $state['waitlist']));

            self::assertSame(
                [],
                $lost,
                sprintf('Saga %s lost %d of %d saved changes', $sagaId, count($lost), count($entries)),
            );
            self::assertCount(count($entries), $state['waitlist'], sprintf('Saga %s has duplicate entries', $sagaId));
            self::assertSame(count($entries) - 1, $saga->revision, sprintf('Saga %s has an unexpected revision', $sagaId));
        }

        $sagaRows = $connection->fetchOne("SELECT COUNT(*) FROM saga_store WHERE saga_name = 'course-waitlist'");
        self::assertSame(self::SAGAS, is_numeric($sagaRows) ? (int) $sagaRows : 0, 'Expected exactly one saga per id');

        $connection->close();

        self::assertGreaterThan(0, $conflicts['revision'] ?? 0, 'Workers did not compete, the test proves nothing');

        if (!$outerTransaction) {
            self::assertArrayNotHasKey(DeadlockException::class, $conflicts, 'Deadlocks occurred between concurrent saves');
        }
    }

    /**
     * @param list<float|int> $arguments
     *
     * @return array{resource, resource, string}
     */
    private function spawnWorker(array $arguments): array
    {
        // Stderr goes to a file: a full stderr pipe would block the worker while stdout is being read
        $stderrFile = (string) tempnam(sys_get_temp_dir(), 'gember-saga-worker');

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/saga_worker.php', ...array_map(strval(...), $arguments)],
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
