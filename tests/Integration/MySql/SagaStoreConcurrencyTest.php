<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaConcurrencyException;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaIdAlreadyInUseException;
use Gember\RdbmsEventStoreDoctrineDbal\Saga\DoctrineRdbmsSagaStoreRepository;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Group('mysql')]
final class SagaStoreConcurrencyTest extends TestCase
{
    private Connection $connectionA;
    private Connection $connectionB;
    private DoctrineRdbmsSagaStoreRepository $repositoryA;
    private DoctrineRdbmsSagaStoreRepository $repositoryB;

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

        $this->repositoryA = MySqlTestEnvironment::createSagaRepository($this->connectionA);
        $this->repositoryB = MySqlTestEnvironment::createSagaRepository($this->connectionB);
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
    public function itShouldRejectStaleSaveInsideApplicationTransaction(): void
    {
        $created = $this->repositoryA->save('course-waitlist', '{"waitlist":[]}', new DateTimeImmutable(), null, 'course-1');

        // B's application transaction loads revision 0 (its snapshot is taken here)
        $this->connectionB->beginTransaction();
        $loadedByB = $this->repositoryB->get('course-waitlist', 'course-1');

        // A saves in between: revision 1
        $this->repositoryA->save('course-waitlist', '{"waitlist":["S1"]}', new DateTimeImmutable(), $created, 'course-1');

        try {
            $this->repositoryB->save('course-waitlist', '{"waitlist":["S2"]}', new DateTimeImmutable(), $loadedByB, 'course-1');

            self::fail('Expected RdbmsSagaConcurrencyException: B saved from a stale state');
        } catch (RdbmsSagaConcurrencyException) {
            $this->connectionB->rollBack();
        }

        self::assertSame(['waitlist' => ['S1']], json_decode($this->repositoryA->get('course-waitlist', 'course-1')->payload, true));
    }

    #[Test]
    public function itShouldRejectConcurrentCreationOfSameSaga(): void
    {
        // A and B both didn't find the saga; B's application transaction already looked for it
        $this->connectionB->beginTransaction();
        self::assertFalse($this->connectionB->fetchOne("SELECT id FROM saga_store_relation WHERE saga_id = 'course-1'"));

        $this->repositoryA->save('course-waitlist', '{"waitlist":["S1"]}', new DateTimeImmutable(), null, 'course-1');

        try {
            $this->repositoryB->save('course-waitlist', '{"waitlist":["S2"]}', new DateTimeImmutable(), null, 'course-1');

            self::fail('Expected RdbmsSagaConcurrencyException: B created a saga that already exists');
        } catch (RdbmsSagaConcurrencyException) {
            $this->connectionB->rollBack();
        }

        self::assertSame(['waitlist' => ['S1']], json_decode($this->repositoryA->get('course-waitlist', 'course-1')->payload, true));
        self::assertEquals(1, $this->connectionA->fetchOne('SELECT COUNT(*) FROM saga_store'));
    }

    #[Test]
    public function itShouldTreatTakenSagaIdAsConcurrentWhenItsOwnerIsNotVisibleInTheTransaction(): void
    {
        $this->repositoryA->save('order', '{"saga":"s"}', new DateTimeImmutable(), null, 'own-1');

        // B's application transaction loads saga S (its snapshot is taken here)
        $this->connectionB->beginTransaction();
        $loadedByB = $this->repositoryB->get('order', 'own-1');

        // Another saga takes x-1 and commits after B's snapshot
        $this->repositoryA->save('order', '{"saga":"z"}', new DateTimeImmutable(), null, 'x-1');

        try {
            $this->repositoryB->save('order', '{"saga":"s2"}', new DateTimeImmutable(), $loadedByB, 'own-1', 'x-1');

            self::fail('Expected RdbmsSagaConcurrencyException');
        } catch (RdbmsSagaConcurrencyException) {
            // The owner of x-1 isn't visible in B's snapshot: retrying in a new transaction tells
            $this->connectionB->rollBack();
        }

        try {
            $this->repositoryB->save('order', '{"saga":"s2"}', new DateTimeImmutable(), $this->repositoryB->get('order', 'own-1'), 'own-1', 'x-1');

            self::fail('Expected RdbmsSagaIdAlreadyInUseException');
        } catch (RdbmsSagaIdAlreadyInUseException $exception) {
            self::assertStringContainsString('x-1', $exception->getMessage());
        }
    }

    #[Test]
    public function itShouldCompareSagaIdsCaseSensitively(): void
    {
        $this->repositoryA->save('order', '{"saga":"lower"}', new DateTimeImmutable(), null, 'order-abc');
        $this->repositoryA->save('order', '{"saga":"upper"}', new DateTimeImmutable(), null, 'ORDER-ABC');

        self::assertSame(['saga' => 'lower'], json_decode($this->repositoryA->get('order', 'order-abc')->payload, true));
        self::assertSame(['saga' => 'upper'], json_decode($this->repositoryA->get('order', 'ORDER-ABC')->payload, true));
    }

    #[Test]
    public function itShouldAllowSameSagaIdForDifferentSagaNames(): void
    {
        $this->repositoryA->save('order', '{"saga":"order"}', new DateTimeImmutable(), null, 'order-1');
        $this->repositoryA->save('invoice', '{"saga":"invoice"}', new DateTimeImmutable(), null, 'order-1');

        self::assertSame(['saga' => 'order'], json_decode($this->repositoryA->get('order', 'order-1')->payload, true));
        self::assertSame(['saga' => 'invoice'], json_decode($this->repositoryA->get('invoice', 'order-1')->payload, true));
    }
}
