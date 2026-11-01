<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Gember\DependencyContracts\Outbox\Rdbms\RdbmsOutboxMessage;
use Gember\RdbmsEventStoreDoctrineDbal\Outbox\DoctrineDbalRdbmsOutboxFactory;
use Gember\RdbmsEventStoreDoctrineDbal\Outbox\DoctrineDbalRdbmsOutboxRepository;
use Gember\RdbmsEventStoreDoctrineDbal\Outbox\TableSchema\OutboxTableSchemaFactory;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Group('mysql')]
final class OutboxConcurrencyTest extends TestCase
{
    private Connection $connectionA;
    private Connection $connectionB;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $dsn = MySqlTestEnvironment::getDsn();

        if ($dsn === null) {
            self::markTestSkipped(sprintf('Set %s to run MySQL integration tests', MySqlTestEnvironment::DSN_ENV));
        }

        $this->connectionA = MySqlTestEnvironment::createConnection($dsn);
        $this->connectionB = MySqlTestEnvironment::createConnection($dsn);
        $this->connectionB->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        MySqlTestEnvironment::resetSchema($this->connectionA);

        $repository = MySqlTestEnvironment::createOutboxRepository($this->connectionA);

        foreach (['msg-1', 'msg-2'] as $i => $id) {
            $repository->save(new RdbmsOutboxMessage($id, 'event', 'SomeEvent', '{}', new DateTimeImmutable(sprintf('2024-10-14 12:00:0%d', $i))));
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        if (isset($this->connectionA)) {
            foreach ([$this->connectionA, $this->connectionB] as $connection) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                $connection->close();
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function itShouldSkipMessagesAnotherProcessorIsClaimingWithoutWaiting(): void
    {
        // Processor A is in the middle of claiming msg-1 (its claim transaction holds the row lock)
        $this->connectionA->beginTransaction();
        $this->connectionA->fetchOne("SELECT id FROM outbox WHERE id = 'msg-1' FOR UPDATE");

        $start = microtime(true);
        $messages = $this->createRepository($this->connectionB, 'processor-b')->getUnprocessedMessages(10);

        self::assertSame(['msg-2'], array_map(fn($message) => $message->id, $messages));
        self::assertLessThan(0.5, microtime(true) - $start, 'Processor B waited for the lock of processor A');
    }

    #[Test]
    public function itShouldLetAnotherProcessorTakeOverMessagesWhenTheLeaseExpired(): void
    {
        $crashed = $this->createRepository($this->connectionA, 'processor-a', leaseSeconds: 0);
        $other = $this->createRepository($this->connectionB, 'processor-b');

        self::assertCount(2, $crashed->getUnprocessedMessages(10));
        self::assertCount(2, $other->getUnprocessedMessages(10));
        self::assertSame([], $crashed->getUnprocessedMessages(10));

        self::assertSame(
            [['id' => 'msg-1', 'claimed_by' => 'processor-b'], ['id' => 'msg-2', 'claimed_by' => 'processor-b']],
            $this->connectionA->fetchAllAssociative('SELECT id, claimed_by FROM outbox ORDER BY id'),
        );
    }

    #[Test]
    public function itShouldRollBackTheClaimWhenItFails(): void
    {
        $repository = $this->createRepository($this->connectionA, 'processor-a', claimedByFieldName: 'missing_column');

        try {
            $repository->getUnprocessedMessages(10);

            self::fail('Expected the claim to fail');
        } catch (DbalException) {
        }

        self::assertFalse($this->connectionA->isTransactionActive());
        self::assertSame(0, $this->connectionA->getTransactionNestingLevel());
    }

    #[Test]
    public function itShouldClaimWithoutChangingTheIsolationLevelOfTheConnection(): void
    {
        $this->createRepository($this->connectionA, 'processor-a')->getUnprocessedMessages(10);

        $this->connectionA->beginTransaction();

        self::assertSame('REPEATABLE-READ', $this->connectionA->fetchOne('SELECT @@transaction_isolation'));
    }

    #[Test]
    public function itShouldDeadLetterAfterTheFirstAttemptAndMaxRetriesRetries(): void
    {
        $repository = $this->createRepository($this->connectionA, 'processor-a');

        // max_retries 2: the first attempt and 2 retries, as on every database
        foreach ([1, 2, 3] as $attempt) {
            $this->connectionA->executeStatement('UPDATE outbox SET claimed_until = NULL WHERE claimed_by IS NULL');
            self::assertContains('msg-1', array_map(fn($message) => $message->id, $repository->getUnprocessedMessages(10)));
            $repository->incrementRetryCount(2, 'msg-1');

            self::assertSame(
                $attempt === 3,
                $this->connectionA->fetchOne("SELECT dead_lettered_at FROM outbox WHERE id = 'msg-1'") !== null,
                sprintf('Dead-lettered after attempt %d', $attempt),
            );
        }
    }

    /**
     * The backoff is measured in UTC with the old retry count, also with a session time zone with daylight saving time.
     */
    #[Test]
    public function itShouldRetryAfterABackoffInUtcWhateverTheSessionTimeZone(): void
    {
        // 2026-03-29 01:59:59 Europe/Amsterdam (frozen clock), one second before daylight saving time starts
        $this->connectionA->executeStatement("SET time_zone = 'Europe/Amsterdam', timestamp = 1774745999");
        $this->connectionB->executeStatement("SET time_zone = '+14:00', timestamp = 1774745999");

        $repository = $this->createRepository($this->connectionA, 'processor-a');

        foreach ([5, 10] as $delay) {
            $this->connectionA->executeStatement("UPDATE outbox SET claimed_until = NULL WHERE claimed_by IS NULL AND id = 'msg-1'");
            self::assertContains('msg-1', array_map(fn($message) => $message->id, $repository->getUnprocessedMessages(1)));
            $repository->incrementRetryCount(5, 'msg-1');

            self::assertEquals($delay, $this->connectionA->fetchOne(
                "SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), claimed_until) FROM outbox WHERE id = 'msg-1' AND claimed_by IS NULL",
            ));
            self::assertNotContains('msg-1', array_map(fn($message) => $message->id, $this->createRepository($this->connectionB, 'processor-b')->getUnprocessedMessages(10)));
            $this->connectionB->executeStatement("UPDATE outbox SET claimed_by = NULL, claimed_until = NULL WHERE claimed_by = 'processor-b'");
        }
    }

    /**
     * Messages that failed are the oldest unprocessed messages: a poll must not read (or sort) all of them.
     *
     * @param list<string> $expectedFirst
     */
    #[Test]
    #[DataProvider('provideMessagesAheadOfTheQueue')]
    public function itShouldNotReadAllMessagesThatFailed(string $claimedUntil, array $expectedFirst): void
    {
        $this->connectionA->executeStatement("UPDATE outbox SET created_at = NOW(6) WHERE id IN ('msg-1', 'msg-2')");
        $this->connectionA->executeStatement('SET SESSION cte_max_recursion_depth = 10000');
        $this->connectionA->executeStatement(sprintf(<<<'SQL'
            INSERT INTO outbox (id, message_type, message_name, payload, created_at, retry_count, claimed_until)
            WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 2000)
            SELECT CONCAT('retry-', i), 'event', 'E', '{}', NOW(6) - INTERVAL 1 DAY + INTERVAL i MICROSECOND, 1, %s FROM n
            SQL, $claimedUntil));

        $this->connectionA->executeStatement('FLUSH STATUS');
        $messages = $this->createRepository($this->connectionA, 'processor-a')->getUnprocessedMessages(2);
        $reads = $this->connectionA->fetchAssociative("SHOW SESSION STATUS LIKE 'Handler_read_next'");

        self::assertSame($expectedFirst, array_map(fn($message) => $message->id, $messages));
        self::assertIsArray($reads);
        self::assertIsNumeric($reads['Value']);
        self::assertLessThan(100, (int) $reads['Value'], 'The poll read all messages that failed');
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideMessagesAheadOfTheQueue(): iterable
    {
        // Waiting for a retry (outage): skipped by the index range
        yield 'waiting for a retry' => ['UTC_TIMESTAMP() + INTERVAL 1 HOUR', ['msg-1', 'msg-2']];

        // All due at once (outage over): read in the order they're due, not sorted by age
        yield 'due for a retry' => ['UTC_TIMESTAMP() - INTERVAL 1 HOUR', ['retry-1', 'retry-2']];
    }

    /**
     * The lease is in UTC: a session time zone with daylight saving time doesn't shift or break it. A claim just before
     * the spring-forward gap (02:00–03:00 Europe/Amsterdam doesn't exist) would otherwise get an invalid local time.
     */
    #[Test]
    public function itShouldClaimWithALeaseInUtcWhateverTheSessionTimeZone(): void
    {
        // 2026-03-29 01:59:59 Europe/Amsterdam, one second before daylight saving time starts
        $this->connectionA->executeStatement("SET time_zone = 'Europe/Amsterdam', timestamp = 1774745999");
        $this->connectionB->executeStatement("SET time_zone = '+14:00', timestamp = 1774745999");

        self::assertCount(2, $this->createRepository($this->connectionA, 'processor-a')->getUnprocessedMessages(10));

        self::assertEquals(300, $this->connectionA->fetchOne(
            "SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), claimed_until) FROM outbox WHERE id = 'msg-1'",
        ));
        self::assertSame([], $this->createRepository($this->connectionB, 'processor-b')->getUnprocessedMessages(10));
    }

    private function createRepository(
        Connection $connection,
        string $claimant,
        int $leaseSeconds = 300,
        string $claimedByFieldName = 'claimed_by',
    ): DoctrineDbalRdbmsOutboxRepository {
        return new DoctrineDbalRdbmsOutboxRepository(
            $connection,
            OutboxTableSchemaFactory::createDefault(claimedByFieldName: $claimedByFieldName),
            new DoctrineDbalRdbmsOutboxFactory(),
            $leaseSeconds,
            claimant: $claimant,
        );
    }
}
