<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Gember\DependencyContracts\EventStore\Rdbms\OptimisticLockException;
use Gember\RdbmsEventStoreDoctrineDbal\DoctrineDbalRdbmsEventStoreRepository;
use Gember\RdbmsEventStoreDoctrineDbal\Transaction\DoctrineDbalTransactional;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Writer A opens a transaction, saves and keeps the transaction open (holding its locks).
 * Writer B then saves on a second connection with a short lock wait timeout:
 * a LockWaitTimeoutException proves B is serialized behind A.
 *
 * @internal
 */
#[Group('mysql')]
final class EventStoreConcurrencyTest extends TestCase
{
    private const array SUBSCRIBE_EVENT_NAMES = ['CourseCapacityChanged', 'StudentSubscribed'];

    private Connection $connectionA;
    private Connection $connectionB;
    private DoctrineDbalRdbmsEventStoreRepository $repositoryA;
    private DoctrineDbalRdbmsEventStoreRepository $repositoryB;

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

        $this->repositoryA = MySqlTestEnvironment::createRepository($this->connectionA);
        $this->repositoryB = MySqlTestEnvironment::createRepository($this->connectionB);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (isset($this->connectionA)) {
            if ($this->connectionA->isTransactionActive()) {
                $this->connectionA->rollBack();
            }

            $this->connectionA->close();
            $this->connectionB->close();
        }

        parent::tearDown();
    }

    #[Test]
    public function itShouldSerializeWritersWithOverlappingButDifferentBoundaries(): void
    {
        $this->connectionA->beginTransaction();
        $this->repositoryA->saveEvents(
            ['course-1', 'student-1'],
            self::SUBSCRIBE_EVENT_NAMES,
            0,
            [MySqlTestEnvironment::event('event-a', ['course-1', 'student-1'], 'StudentSubscribed')],
        );

        $this->assertBlocked(fn() => $this->repositoryB->saveEvents(
            ['course-1', 'student-2'],
            self::SUBSCRIBE_EVENT_NAMES,
            0,
            [MySqlTestEnvironment::event('event-b', ['course-1', 'student-2'], 'StudentSubscribed')],
        ));

        $this->connectionA->commit();

        // B decided without seeing A's subscription, so it must be rejected now
        $this->expectException(OptimisticLockException::class);

        $this->repositoryB->saveEvents(
            ['course-1', 'student-2'],
            self::SUBSCRIBE_EVENT_NAMES,
            0,
            [MySqlTestEnvironment::event('event-b', ['course-1', 'student-2'], 'StudentSubscribed')],
        );
    }

    #[Test]
    public function itShouldSerializeWriterAppendingAnEventTypeOtherWriterReads(): void
    {
        // A reads StudentSubscribed for course-1 (e.g. to count subscriptions)
        $this->connectionA->beginTransaction();
        $this->repositoryA->saveEvents(
            ['course-1'],
            self::SUBSCRIBE_EVENT_NAMES,
            0,
            [MySqlTestEnvironment::event('event-a', ['course-1'], 'CourseCapacityChanged')],
        );

        // B does not read StudentSubscribed, but appends one tagged with course-1
        $this->assertBlocked(fn() => $this->repositoryB->saveEvents(
            ['course-1', 'student-2'],
            ['StudentCreated'],
            0,
            [MySqlTestEnvironment::event('event-b', ['course-1', 'student-2'], 'StudentSubscribed')],
        ));

        $this->connectionA->commit();

        // Serialized after A, B's own query is unaffected, so it succeeds
        $this->repositoryB->saveEvents(
            ['course-1', 'student-2'],
            ['StudentCreated'],
            0,
            [MySqlTestEnvironment::event('event-b', ['course-1', 'student-2'], 'StudentSubscribed')],
        );

        self::assertSame(2, $this->countEvents());
    }

    #[Test]
    public function itShouldNotSerializeWritersWithSameTagsButUnrelatedEventTypes(): void
    {
        // Make sure lock rows already exist, so both the insert and the existing-row path are exercised
        $this->repositoryA->saveEvents(['course-1'], ['CourseRenamed'], 0, [
            MySqlTestEnvironment::event('event-1', ['course-1'], 'CourseRenamed'),
        ]);
        $this->repositoryA->saveEvents(['course-1'], ['StudentSubscribed'], 0, [
            MySqlTestEnvironment::event('event-2', ['course-1'], 'StudentSubscribed'),
        ]);

        $this->connectionA->beginTransaction();
        $this->repositoryA->saveEvents(['course-1'], ['CourseRenamed'], 1, [
            MySqlTestEnvironment::event('event-3', ['course-1'], 'CourseRenamed'),
        ]);

        $this->repositoryB->saveEvents(['course-1'], ['StudentSubscribed'], 1, [
            MySqlTestEnvironment::event('event-4', ['course-1'], 'StudentSubscribed'),
        ]);

        $this->connectionA->commit();

        self::assertSame(4, $this->countEvents());
    }

    #[Test]
    public function itShouldSerializeWritersWithIdenticalBoundary(): void
    {
        $this->repositoryA->saveEvents(['course-1'], self::SUBSCRIBE_EVENT_NAMES, 0, [
            MySqlTestEnvironment::event('event-1', ['course-1'], 'StudentSubscribed'),
        ]);

        $this->connectionA->beginTransaction();
        $this->repositoryA->saveEvents(['course-1'], self::SUBSCRIBE_EVENT_NAMES, 1, [
            MySqlTestEnvironment::event('event-a', ['course-1'], 'StudentSubscribed'),
        ]);

        $this->assertBlocked(fn() => $this->repositoryB->saveEvents(['course-1'], self::SUBSCRIBE_EVENT_NAMES, 1, [
            MySqlTestEnvironment::event('event-b', ['course-1'], 'StudentSubscribed'),
        ]));

        $this->connectionA->commit();

        $this->expectException(OptimisticLockException::class);

        $this->repositoryB->saveEvents(['course-1'], self::SUBSCRIBE_EVENT_NAMES, 1, [
            MySqlTestEnvironment::event('event-b', ['course-1'], 'StudentSubscribed'),
        ]);
    }

    #[Test]
    public function itShouldKeepLockRowsWhenOwnedTransactionRollsBack(): void
    {
        $this->repositoryA->saveEvents(['course-1'], self::SUBSCRIBE_EVENT_NAMES, 0, [
            MySqlTestEnvironment::event('event-1', ['course-1'], 'StudentSubscribed'),
        ]);

        try {
            // Stale event count, with lock rows for course-2 that don't exist yet
            $this->repositoryA->saveEvents(['course-1', 'course-2'], self::SUBSCRIBE_EVENT_NAMES, 0, [
                MySqlTestEnvironment::event('event-2', ['course-2'], 'StudentSubscribed'),
            ]);

            self::fail('Expected OptimisticLockException');
        } catch (OptimisticLockException) {
        }

        // Lock rows are committed upfront, so a rollback never removes rows other writers may be waiting on
        self::assertContains(
            hash('sha256', "course-2\0StudentSubscribed"),
            $this->connectionA->fetchFirstColumn('SELECT boundary_hash FROM event_store_lock'),
        );
        self::assertSame(1, $this->countEvents());
    }

    #[Test]
    public function itShouldLeaveOuterTransactionUsableAfterDeadlock(): void
    {
        $blockerKey = str_repeat('f', 64);
        $this->connectionA->executeStatement('INSERT INTO event_store_lock (boundary_hash) VALUES (?)', [$blockerKey]);
        $this->repositoryA->saveEvents(['course-1'], ['StudentSubscribed'], 0, [
            MySqlTestEnvironment::event('event-1', ['course-1'], 'StudentSubscribed'),
        ]);

        // Application transaction holding the blocker row, with the Transactional decorator inside it
        $this->connectionA->beginTransaction();
        $this->connectionA->fetchOne('SELECT boundary_hash FROM event_store_lock WHERE boundary_hash = ? FOR UPDATE', [$blockerKey]);

        // Child locks course-1 and then waits for the blocker row
        $child = proc_open(
            [PHP_BINARY, __DIR__ . '/deadlock_child.php', $blockerKey],
            [1 => ['pipe', 'w'], 2 => ['file', (string) tempnam(sys_get_temp_dir(), 'gember'), 'w']],
            $pipes,
        );
        self::assertIsResource($child);
        $this->waitForLockWait();

        try {
            // Closes the cycle: waits for course-1, held by the child
            (new DoctrineDbalTransactional($this->connectionA))->transactional(
                fn() => $this->repositoryA->saveEvents(['course-1'], ['StudentSubscribed'], 1, [
                    MySqlTestEnvironment::event('event-a', ['course-1'], 'StudentSubscribed'),
                ]),
            );

            self::fail('Expected DeadlockException');
        } catch (DeadlockException) {
            self::assertSame(1, $this->connectionA->getTransactionNestingLevel());
        }

        $this->connectionA->rollBack();

        self::assertSame(0, $this->connectionA->getTransactionNestingLevel());

        /** @var array{1: resource} $pipes */
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($child);

        self::assertSame('committed', $output);
        self::assertSame(6, $this->countEvents());
    }

    #[Test]
    public function itShouldDetectEventCommittedAfterLoadWithEarlierAppliedAt(): void
    {
        $this->repositoryA->saveEvents(['course-1', 'student-0'], ['StudentSubscribed'], 0, [
            MySqlTestEnvironment::event('s0', ['course-1', 'student-0'], 'StudentSubscribed', new DateTimeImmutable('2025-01-01 10:00:00')),
        ]);

        // Writer C stamped its event at 10:00:02 and is still in its transaction
        $this->connectionA->beginTransaction();
        $this->repositoryA->saveEvents(['course-1', 'student-1'], ['StudentSubscribed'], 1, [
            MySqlTestEnvironment::event('y-c', ['course-1', 'student-1'], 'StudentSubscribed', new DateTimeImmutable('2025-01-01 10:00:02')),
        ]);

        // Writer D shares no lock with C, stamps 10:00:03 and commits
        $this->repositoryB->saveEvents(['course-2', 'student-3'], ['StudentSubscribed'], 0, [
            MySqlTestEnvironment::event('y-d', ['course-2', 'student-3'], 'StudentSubscribed', new DateTimeImmutable('2025-01-01 10:00:03')),
        ]);

        // Writer E loads s0 and y-d, not C's uncommitted event
        $expectedByE = count($this->repositoryB->getEvents(['course-1', 'student-3'], ['StudentSubscribed']));
        self::assertSame(2, $expectedByE);

        $this->connectionA->commit();

        // y-c sorts before y-d, but changed the count: E must be rejected
        $this->expectException(OptimisticLockException::class);

        $this->repositoryB->saveEvents(['course-1', 'student-3'], ['StudentSubscribed'], $expectedByE, [
            MySqlTestEnvironment::event('y-e', ['course-1', 'student-3'], 'StudentSubscribed'),
        ]);
    }

    private function waitForLockWait(): void
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $lockWaits = $this->connectionB->fetchOne('SELECT COUNT(*) FROM performance_schema.data_lock_waits');

            if (is_numeric($lockWaits) && $lockWaits > 0) {
                return;
            }

            usleep(50_000);
        }

        self::fail('Child process did not start waiting for the blocker row');
    }

    private function assertBlocked(callable $save): void
    {
        try {
            $save();
        } catch (LockWaitTimeoutException) {
            self::assertFalse($this->connectionB->isTransactionActive());

            return;
        }

        self::fail('Expected writer B to be blocked by writer A, but it saved without waiting');
    }

    private function countEvents(): int
    {
        $count = $this->connectionA->fetchOne('SELECT COUNT(*) FROM event_store');

        return is_numeric($count) ? (int) $count : 0;
    }
}
