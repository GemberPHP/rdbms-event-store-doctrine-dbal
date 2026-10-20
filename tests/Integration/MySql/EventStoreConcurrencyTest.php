<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Gember\DependencyContracts\EventStore\Rdbms\OptimisticLockException;
use Gember\RdbmsEventStoreDoctrineDbal\DoctrineDbalRdbmsEventStoreRepository;
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
            null,
            [MySqlTestEnvironment::event('event-a', ['course-1', 'student-1'], 'StudentSubscribed')],
        );

        $this->assertBlocked(fn() => $this->repositoryB->saveEvents(
            ['course-1', 'student-2'],
            self::SUBSCRIBE_EVENT_NAMES,
            null,
            [MySqlTestEnvironment::event('event-b', ['course-1', 'student-2'], 'StudentSubscribed')],
        ));

        $this->connectionA->commit();

        // B decided without seeing A's subscription, so it must be rejected now
        $this->expectException(OptimisticLockException::class);

        $this->repositoryB->saveEvents(
            ['course-1', 'student-2'],
            self::SUBSCRIBE_EVENT_NAMES,
            null,
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
            null,
            [MySqlTestEnvironment::event('event-a', ['course-1'], 'CourseCapacityChanged')],
        );

        // B does not read StudentSubscribed, but appends one tagged with course-1
        $this->assertBlocked(fn() => $this->repositoryB->saveEvents(
            ['course-1', 'student-2'],
            ['StudentCreated'],
            null,
            [MySqlTestEnvironment::event('event-b', ['course-1', 'student-2'], 'StudentSubscribed')],
        ));

        $this->connectionA->commit();

        // Serialized after A, B's own query is unaffected, so it succeeds
        $this->repositoryB->saveEvents(
            ['course-1', 'student-2'],
            ['StudentCreated'],
            null,
            [MySqlTestEnvironment::event('event-b', ['course-1', 'student-2'], 'StudentSubscribed')],
        );

        self::assertSame(2, $this->countEvents());
    }

    #[Test]
    public function itShouldNotSerializeWritersWithSameTagsButUnrelatedEventTypes(): void
    {
        // Make sure lock rows already exist, so both the insert and the existing-row path are exercised
        $this->repositoryA->saveEvents(['course-1'], ['CourseRenamed'], null, [
            MySqlTestEnvironment::event('event-1', ['course-1'], 'CourseRenamed'),
        ]);
        $this->repositoryA->saveEvents(['course-1'], ['StudentSubscribed'], null, [
            MySqlTestEnvironment::event('event-2', ['course-1'], 'StudentSubscribed'),
        ]);

        $this->connectionA->beginTransaction();
        $this->repositoryA->saveEvents(['course-1'], ['CourseRenamed'], 'event-1', [
            MySqlTestEnvironment::event('event-3', ['course-1'], 'CourseRenamed'),
        ]);

        $this->repositoryB->saveEvents(['course-1'], ['StudentSubscribed'], 'event-2', [
            MySqlTestEnvironment::event('event-4', ['course-1'], 'StudentSubscribed'),
        ]);

        $this->connectionA->commit();

        self::assertSame(4, $this->countEvents());
    }

    #[Test]
    public function itShouldSerializeWritersWithIdenticalBoundary(): void
    {
        $this->repositoryA->saveEvents(['course-1'], self::SUBSCRIBE_EVENT_NAMES, null, [
            MySqlTestEnvironment::event('event-1', ['course-1'], 'StudentSubscribed'),
        ]);

        $this->connectionA->beginTransaction();
        $this->repositoryA->saveEvents(['course-1'], self::SUBSCRIBE_EVENT_NAMES, 'event-1', [
            MySqlTestEnvironment::event('event-a', ['course-1'], 'StudentSubscribed'),
        ]);

        $this->assertBlocked(fn() => $this->repositoryB->saveEvents(['course-1'], self::SUBSCRIBE_EVENT_NAMES, 'event-1', [
            MySqlTestEnvironment::event('event-b', ['course-1'], 'StudentSubscribed'),
        ]));

        $this->connectionA->commit();

        $this->expectException(OptimisticLockException::class);

        $this->repositoryB->saveEvents(['course-1'], self::SUBSCRIBE_EVENT_NAMES, 'event-1', [
            MySqlTestEnvironment::event('event-b', ['course-1'], 'StudentSubscribed'),
        ]);
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
