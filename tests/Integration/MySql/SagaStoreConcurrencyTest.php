<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Gember\DependencyContracts\EventStore\Saga\RdbmsSagaConcurrencyException;
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
}
