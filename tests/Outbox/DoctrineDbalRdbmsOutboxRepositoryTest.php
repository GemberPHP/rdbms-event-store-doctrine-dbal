<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Gember\DependencyContracts\Outbox\Rdbms\RdbmsOutboxMessage;
use Gember\RdbmsEventStoreDoctrineDbal\Outbox\DoctrineDbalRdbmsOutboxFactory;
use Gember\RdbmsEventStoreDoctrineDbal\Outbox\DoctrineDbalRdbmsOutboxRepository;
use Gember\RdbmsEventStoreDoctrineDbal\Outbox\TableSchema\OutboxTableSchemaFactory;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DoctrineDbalRdbmsOutboxRepositoryTest extends TestCase
{
    private Connection $connection;
    private DoctrineDbalRdbmsOutboxRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = DriverManager::getConnection((new DsnParser())->parse('pdo-sqlite:///:memory:'));
        $this->connection->executeStatement((string) file_get_contents(__DIR__ . '/../schema.sql'));

        $this->repository = new DoctrineDbalRdbmsOutboxRepository(
            $this->connection,
            OutboxTableSchemaFactory::createDefault(),
            new DoctrineDbalRdbmsOutboxFactory(),
        );
    }

    #[Test]
    public function itShouldSaveAndRetrieveMessages(): void
    {
        $this->repository->save(new RdbmsOutboxMessage(
            'msg-1',
            'event',
            'App\Domain\SomeEvent',
            '{"key":"value"}',
            new DateTimeImmutable('2024-10-14 12:00:00'),
        ));

        $messages = $this->repository->getUnprocessedMessages(10);

        self::assertCount(1, $messages);
        self::assertSame('msg-1', $messages[0]->id);
        self::assertSame('event', $messages[0]->messageType);
        self::assertSame('App\Domain\SomeEvent', $messages[0]->messageName);
        self::assertSame('{"key":"value"}', $messages[0]->payload);
        self::assertSame(0, $messages[0]->retryCount);
    }

    #[Test]
    public function itShouldRespectLimit(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));
        $this->repository->save(new RdbmsOutboxMessage('msg-2', 'event', 'Event2', '{}', new DateTimeImmutable('2024-10-14 12:00:01')));
        $this->repository->save(new RdbmsOutboxMessage('msg-3', 'command', 'Cmd1', '{}', new DateTimeImmutable('2024-10-14 12:00:02')));

        $messages = $this->repository->getUnprocessedMessages(2);

        self::assertCount(2, $messages);
        self::assertSame('msg-1', $messages[0]->id);
        self::assertSame('msg-2', $messages[1]->id);
    }

    #[Test]
    public function itShouldExcludeProcessedMessages(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));
        $this->repository->save(new RdbmsOutboxMessage('msg-2', 'event', 'Event2', '{}', new DateTimeImmutable('2024-10-14 12:00:01')));

        $this->repository->markAsProcessed('msg-1');

        $messages = $this->repository->getUnprocessedMessages(10);

        self::assertCount(1, $messages);
        self::assertSame('msg-2', $messages[0]->id);
    }

    #[Test]
    public function itShouldMarkMultipleAsProcessed(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));
        $this->repository->save(new RdbmsOutboxMessage('msg-2', 'event', 'Event2', '{}', new DateTimeImmutable('2024-10-14 12:00:01')));
        $this->repository->save(new RdbmsOutboxMessage('msg-3', 'event', 'Event3', '{}', new DateTimeImmutable('2024-10-14 12:00:02')));

        $this->repository->markAsProcessed('msg-1', 'msg-3');

        $messages = $this->repository->getUnprocessedMessages(10);

        self::assertCount(1, $messages);
        self::assertSame('msg-2', $messages[0]->id);
    }

    #[Test]
    public function itShouldIncrementRetryCount(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));

        $this->repository->incrementRetryCount(5, 'msg-1');
        $this->repository->incrementRetryCount(5, 'msg-1');

        $messages = $this->repository->getUnprocessedMessages(10);

        self::assertCount(1, $messages);
        self::assertSame(2, $messages[0]->retryCount);
    }

    #[Test]
    public function itShouldDeadLetterWhenRetryCountExceedsMaxRetries(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));

        // With maxRetries=3: increments 1→2→3 don't dead-letter (CASE retry_count+1 > 3 is false)
        $this->repository->incrementRetryCount(3, 'msg-1');
        $this->repository->incrementRetryCount(3, 'msg-1');
        $this->repository->incrementRetryCount(3, 'msg-1');

        // After 3 increments, retry_count=3, not yet dead-lettered
        $messages = $this->repository->getUnprocessedMessages(10);
        self::assertCount(1, $messages);
        self::assertSame(3, $messages[0]->retryCount);

        // 4th increment: retry_count 3→4, CASE 4>3=true → dead-lettered
        $this->repository->incrementRetryCount(3, 'msg-1');

        // Dead-lettered message is excluded from results
        $messages = $this->repository->getUnprocessedMessages(10);
        self::assertCount(0, $messages);
    }

    #[Test]
    public function itShouldExcludeDeadLetteredMessages(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));
        $this->repository->save(new RdbmsOutboxMessage('msg-2', 'event', 'Event2', '{}', new DateTimeImmutable('2024-10-14 12:00:01')));

        // 1st increment: retry_count 0→1, not dead-lettered yet (1>1=false)
        $this->repository->incrementRetryCount(1, 'msg-1');

        $messages = $this->repository->getUnprocessedMessages(10);
        self::assertCount(2, $messages);

        // 2nd increment: retry_count 1→2, dead-lettered (2>1=true)
        $this->repository->incrementRetryCount(1, 'msg-1');

        $messages = $this->repository->getUnprocessedMessages(10);
        self::assertCount(1, $messages);
        self::assertSame('msg-2', $messages[0]->id);
    }

    #[Test]
    public function itShouldReturnMessagesOrderedByCreatedAtAndId(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-b', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:02')));
        $this->repository->save(new RdbmsOutboxMessage('msg-a', 'event', 'Event2', '{}', new DateTimeImmutable('2024-10-14 12:00:01')));
        $this->repository->save(new RdbmsOutboxMessage('msg-c', 'command', 'Cmd1', '{}', new DateTimeImmutable('2024-10-14 12:00:01')));

        $messages = $this->repository->getUnprocessedMessages(10);

        self::assertSame('msg-a', $messages[0]->id);
        self::assertSame('msg-c', $messages[1]->id);
        self::assertSame('msg-b', $messages[2]->id);
    }

    #[Test]
    public function itShouldHandleEmptyMarkAsProcessed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->repository->markAsProcessed();
    }

    #[Test]
    public function itShouldHandleEmptyIncrementRetryCount(): void
    {
        $this->expectNotToPerformAssertions();

        $this->repository->incrementRetryCount(5);
    }
}
