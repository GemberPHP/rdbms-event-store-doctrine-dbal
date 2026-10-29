<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql;

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
                $processes[] = $this->spawnWorker([$workerId, self::SAGAS, self::ATTEMPTS_PER_WORKER, $startAt]);
            }
        } catch (RuntimeException $exception) {
            foreach ($processes as [$process]) {
                proc_terminate($process);
            }

            throw $exception;
        }

        /** @var array<string, list<string>> $savedPerSaga */
        $savedPerSaga = [];

        /** @var array<string, int> $failures */
        $failures = [];

        foreach ($processes as [$process, $stdout, $stderrFile]) {
            $output = (string) stream_get_contents($stdout);
            fclose($stdout);
            $exitCode = proc_close($process);
            $errors = (string) file_get_contents($stderrFile);
            unlink($stderrFile);

            self::assertSame(0, $exitCode, sprintf("Worker failed:\n%s\n%s", $output, $errors));

            /** @var array{saved: array<string, list<string>>, failures: array<string, int>} $summary */
            $summary = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            foreach ($summary['saved'] as $sagaId => $entries) {
                $savedPerSaga[$sagaId] = [...$savedPerSaga[$sagaId] ?? [], ...$entries];
            }

            foreach ($summary['failures'] as $class => $count) {
                $failures[$class] = ($failures[$class] ?? 0) + $count;
            }
        }

        $repository = MySqlTestEnvironment::createSagaRepository($connection);

        foreach ($savedPerSaga as $sagaId => $entries) {
            /** @var array{waitlist: list<string>} $state */
            $state = json_decode($repository->get('course-waitlist', $sagaId)->payload, true, flags: JSON_THROW_ON_ERROR);

            $lost = array_values(array_diff($entries, $state['waitlist']));

            self::assertSame(
                [],
                $lost,
                sprintf('Saga %s lost %d of %d saved changes', $sagaId, count($lost), count($entries)),
            );
            self::assertCount(count($entries), $state['waitlist'], sprintf('Saga %s has duplicate entries', $sagaId));
        }

        $sagaRows = $connection->fetchOne("SELECT COUNT(*) FROM saga_store WHERE saga_name = 'course-waitlist'");
        self::assertSame(self::SAGAS, is_numeric($sagaRows) ? (int) $sagaRows : 0, 'Expected exactly one saga per id');

        $connection->close();

        self::assertNotSame([], $failures, 'Workers did not compete, the test proves nothing');
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
