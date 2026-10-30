<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Gember\DependencyContracts\Outbox\Rdbms\RdbmsOutboxMessage;
use Gember\RdbmsEventStoreDoctrineDbal\Outbox\DoctrineDbalRdbmsOutboxFactory;
use Gember\RdbmsEventStoreDoctrineDbal\Outbox\DoctrineDbalRdbmsOutboxRepository;
use Gember\RdbmsEventStoreDoctrineDbal\Outbox\TableSchema\OutboxTableSchemaFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Concurrent outbox processors on one SQLite file (no SKIP LOCKED): every message is dispatched exactly once, and no
 * processor fails (SQLite rejects concurrent read-then-write transactions as "database is locked").
 *
 * @internal
 */
final class OutboxSqliteConcurrencyTest extends TestCase
{
    private const int WORKERS = 3;
    private const int MESSAGES = 300;

    #[Test]
    public function itShouldDispatchEveryMessageExactlyOnceWithConcurrentProcessors(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'gember-outbox-sqlite');
        $dsn = sprintf('pdo-sqlite:///%s', $file);

        try {
            $connection = DriverManager::getConnection((new DsnParser())->parse($dsn));
            $connection->executeStatement((string) file_get_contents(__DIR__ . '/../schema.sql'));
            $connection->executeStatement('CREATE TABLE outbox_dispatch_log (message_id varchar(50) NOT NULL, worker int NOT NULL)');

            $repository = new DoctrineDbalRdbmsOutboxRepository($connection, OutboxTableSchemaFactory::createDefault(), new DoctrineDbalRdbmsOutboxFactory());

            for ($i = 1; $i <= self::MESSAGES; ++$i) {
                $repository->save(new RdbmsOutboxMessage(sprintf('message-%05d', $i), 'event', 'SomeEvent', '{}', new DateTimeImmutable()));
            }

            $startAt = microtime(true) + 1.0;
            $processes = [];

            for ($workerId = 1; $workerId <= self::WORKERS; ++$workerId) {
                $processes[$workerId] = $this->spawnWorker([$workerId, 10, $startAt, 0, $dsn]);
            }

            foreach ($processes as [$process, $stdout, $stderrFile]) {
                $output = (string) stream_get_contents($stdout);
                fclose($stdout);
                $exitCode = proc_close($process);
                $errors = (string) file_get_contents($stderrFile);
                unlink($stderrFile);

                self::assertSame(0, $exitCode, sprintf("Worker failed:\n%s\n%s", $output, $errors));
            }

            self::assertEquals(self::MESSAGES, $connection->fetchOne('SELECT COUNT(*) FROM outbox_dispatch_log'));
            self::assertEquals(self::MESSAGES, $connection->fetchOne('SELECT COUNT(DISTINCT message_id) FROM outbox_dispatch_log'));
            self::assertEquals(0, $connection->fetchOne('SELECT COUNT(*) FROM outbox WHERE processed_at IS NULL'));

            $connection->close();
        } finally {
            @unlink($file);
        }
    }

    /**
     * @param list<float|int|string> $arguments
     *
     * @return array{resource, resource, string}
     */
    private function spawnWorker(array $arguments): array
    {
        $stderrFile = (string) tempnam(sys_get_temp_dir(), 'gember-outbox-worker');

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../Integration/MySql/outbox_worker.php', ...array_map(strval(...), $arguments)],
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
