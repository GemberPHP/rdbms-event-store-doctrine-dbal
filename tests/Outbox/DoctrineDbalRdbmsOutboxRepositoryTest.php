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

        // A batch of one: a processed message must not take the place of an unprocessed one
        self::assertSame(['msg-2'], $this->ids($this->repository->getUnprocessedMessages(1)));
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

        $this->failDispatch($this->repository, 5);
        $this->failDispatch($this->repository, 5);

        $messages = $this->repository->getUnprocessedMessages(10);

        self::assertCount(1, $messages);
        self::assertSame(2, $messages[0]->retryCount);
    }

    #[Test]
    public function itShouldDeadLetterWhenRetryCountExceedsMaxRetries(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));

        // With maxRetries=3: increments 1→2→3 don't dead-letter (CASE retry_count+1 > 3 is false)
        $this->failDispatch($this->repository, 3);
        $this->failDispatch($this->repository, 3);
        $this->failDispatch($this->repository, 3);

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
        // Claims expire at once, so every call sees all claimable messages
        $repository = $this->createRepository('processor-1', leaseSeconds: 0);

        $repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));
        $repository->save(new RdbmsOutboxMessage('msg-2', 'event', 'Event2', '{}', new DateTimeImmutable('2024-10-14 12:00:01')));

        // 1st increment: retry_count 0→1, not dead-lettered yet (1>1=false)
        $this->failDispatch($repository, 1);

        $messages = $repository->getUnprocessedMessages(10);
        self::assertCount(2, $messages);

        // 2nd increment: retry_count 1→2, dead-lettered (2>1=true)
        $repository->incrementRetryCount(1, 'msg-1');

        // A batch of one: a dead-lettered message must not take the place of an unprocessed one
        self::assertSame(['msg-2'], $this->ids($repository->getUnprocessedMessages(1)));
    }

    #[Test]
    public function itShouldNotReturnMessagesClaimedByAnotherProcessor(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));
        $this->repository->save(new RdbmsOutboxMessage('msg-2', 'event', 'Event2', '{}', new DateTimeImmutable('2024-10-14 12:00:01')));

        $first = $this->createRepository('processor-1');
        $second = $this->createRepository('processor-2');

        self::assertSame(['msg-1'], $this->ids($first->getUnprocessedMessages(1)));
        // A batch of one: a message claimed by another processor must not take the place of an unclaimed one
        self::assertSame(['msg-2'], $this->ids($second->getUnprocessedMessages(1)));
        self::assertSame([], $first->getUnprocessedMessages(10));
    }

    #[Test]
    public function itShouldReturnOnlyMessagesClaimedInThatCall(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));
        $this->repository->save(new RdbmsOutboxMessage('msg-2', 'event', 'Event2', '{}', new DateTimeImmutable('2024-10-14 12:00:01')));

        self::assertSame(['msg-1'], $this->ids($this->repository->getUnprocessedMessages(1)));
        self::assertSame(['msg-2'], $this->ids($this->repository->getUnprocessedMessages(10)));
    }

    #[Test]
    public function itShouldLetAnotherProcessorTakeOverMessagesWhenTheLeaseExpired(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));

        $crashed = $this->createRepository('processor-1', leaseSeconds: 0);
        $other = $this->createRepository('processor-2');

        self::assertSame(['msg-1'], $this->ids($crashed->getUnprocessedMessages(10)));
        self::assertSame(['msg-1'], $this->ids($other->getUnprocessedMessages(10)));
    }

    #[Test]
    public function itShouldIgnoreFailureOfMessageAnotherProcessorTookOver(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));

        $slow = $this->createRepository('processor-1', leaseSeconds: 0);
        $other = $this->createRepository('processor-2');

        $slow->getUnprocessedMessages(10);
        $other->getUnprocessedMessages(10);

        $slow->incrementRetryCount(1, 'msg-1');

        self::assertSame(
            ['retry_count' => 0, 'claimed_by' => 'processor-2'],
            $this->row('msg-1', 'retry_count', 'claimed_by'),
        );
    }

    #[Test]
    public function itShouldMarkMessageProcessedAlsoAfterAnotherProcessorTookItOver(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));

        $slow = $this->createRepository('processor-1', leaseSeconds: 0);
        $other = $this->createRepository('processor-2');

        $slow->getUnprocessedMessages(10);
        $other->getUnprocessedMessages(10);

        // Delivered is delivered: no other processor has to dispatch it again
        $slow->markAsProcessed('msg-1');

        self::assertNotNull($this->row('msg-1', 'processed_at')['processed_at']);
        self::assertSame([], $this->createRepository('processor-3', leaseSeconds: 0)->getUnprocessedMessages(10));
    }

    #[Test]
    public function itShouldNotCountFailureOfMessageDeliveredMeanwhile(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));

        $slow = $this->createRepository('processor-1', leaseSeconds: 0);
        $other = $this->createRepository('processor-2');

        $slow->getUnprocessedMessages(10);
        $other->getUnprocessedMessages(10);

        // The slow processor delivered it after all; the other processor's dispatch failed (e.g. not idempotent)
        $slow->markAsProcessed('msg-1');
        $other->incrementRetryCount(0, 'msg-1');

        self::assertSame(['retry_count' => 0, 'dead_lettered_at' => null], $this->row('msg-1', 'retry_count', 'dead_lettered_at'));
    }

    #[Test]
    public function itShouldMeasureTheLeaseWithTheDatabaseClock(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));

        $first = $this->createRepository('processor-1');
        $second = $this->createRepository('processor-2');

        self::assertSame(['msg-1'], $this->ids($first->getUnprocessedMessages(10)));

        // A processor whose PHP time zone is hours ahead doesn't see the claim as expired
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');

        try {
            self::assertSame([], $second->getUnprocessedMessages(10));
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    #[Test]
    public function itShouldReleaseClaimWhenDispatchFailed(): void
    {
        $this->repository->save(new RdbmsOutboxMessage('msg-1', 'event', 'Event1', '{}', new DateTimeImmutable('2024-10-14 12:00:00')));

        $this->failDispatch($this->repository, 5);

        self::assertSame(['claimed_by' => null], $this->row('msg-1', 'claimed_by'));
        self::assertSame(['msg-1'], $this->ids($this->createRepository('processor-2')->getUnprocessedMessages(10)));
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

    private function createRepository(string $claimant, int $leaseSeconds = 300): DoctrineDbalRdbmsOutboxRepository
    {
        return new DoctrineDbalRdbmsOutboxRepository(
            $this->connection,
            OutboxTableSchemaFactory::createDefault(),
            new DoctrineDbalRdbmsOutboxFactory(),
            $leaseSeconds,
            $claimant,
        );
    }

    /**
     * Claims the messages and reports their dispatch as failed, like the outbox processor.
     */
    private function failDispatch(DoctrineDbalRdbmsOutboxRepository $repository, int $maxRetries): void
    {
        $repository->incrementRetryCount($maxRetries, ...$this->ids($repository->getUnprocessedMessages(10)));
    }

    /**
     * @param list<RdbmsOutboxMessage> $messages
     *
     * @return list<string>
     */
    private function ids(array $messages): array
    {
        return array_map(fn(RdbmsOutboxMessage $message) => $message->id, $messages);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id, string ...$columns): array
    {
        $row = $this->connection->fetchAssociative(sprintf('SELECT %s FROM outbox WHERE id = ?', implode(', ', $columns)), [$id]);
        self::assertIsArray($row);

        return $row;
    }
}
