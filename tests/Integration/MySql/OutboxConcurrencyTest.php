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
            $claimant,
        );
    }
}
