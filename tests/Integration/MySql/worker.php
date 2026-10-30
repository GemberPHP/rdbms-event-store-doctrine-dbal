<?php

declare(strict_types=1);

/*
 * Stress test worker, spawned by EventStoreConcurrencyStressTest.
 *
 * Repeatedly subscribes new students to random courses with a fixed capacity,
 * deciding on the event stream like a DCB use case would. Even workers use the
 * boundary {course, student}, odd workers the boundary {course}, so writers both
 * overlap and share identical boundaries.
 *
 * Usage: php worker.php <workerId> <courses> <capacity> <attempts> [<startAt> [<students> <skewMs> [<outerTransaction> [<caseVariants>]]]]
 * Workers wait until the <startAt> unix timestamp (float), so they start competing at the same time.
 * With <students> > 0, students come from a pool shared across courses (a student may subscribe more than once,
 * only capacity matters), so a writer's stream also contains other courses' events via the student tag.
 * <skewMs> stamps applied_at up to that many milliseconds in the past, as happens when a writer stamps its
 * events before waiting for locks, or with clock drift between hosts.
 * With <outerTransaction> 1, each attempt loads and saves inside its own transaction, like an application
 * wrapping the command handler in a transaction (e.g. a doctrine_transaction middleware).
 * With <caseVariants> 1, odd workers write the course tag in uppercase (COURSE-1), as when the same id arrives in
 * different spellings, and every worker applies all subscriptions its stream returns for the course, whatever the
 * spelling (like a use case applying its stream).
 * Prints a JSON summary to stdout.
 */

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Gember\DependencyContracts\EventStore\Rdbms\OptimisticLockException;
use Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql\MySqlTestEnvironment;

require __DIR__ . '/../../../vendor/autoload.php';

$dsn = MySqlTestEnvironment::getDsn() ?? throw new RuntimeException('Missing ' . MySqlTestEnvironment::DSN_ENV);

/** @var list<string> $argv */
[, $workerId, $courses, $capacity, $attempts] = array_map(intval(...), $argv);
$startAt = (float) ($argv[5] ?? 0);
$students = (int) ($argv[6] ?? 0);
$skewMs = (int) ($argv[7] ?? 0);
$outerTransaction = (bool) ($argv[8] ?? false);
$caseVariants = (bool) ($argv[9] ?? false);

$connection = MySqlTestEnvironment::createConnection($dsn);
$repository = MySqlTestEnvironment::createRepository($connection);

if ($startAt > microtime(true)) {
    time_sleep_until($startAt);
}

$summary = ['subscribed' => 0, 'full' => 0, 'conflicts' => 0, 'deadlocks' => 0, 'lockWaitTimeouts' => 0];

for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
    $course = 'course-' . random_int(1, $courses);

    if ($caseVariants && $workerId % 2 === 1) {
        $course = strtoupper($course);
    }

    $student = $students > 0 ? 'student-' . random_int(1, $students) : sprintf('student-%d-%d', $workerId, $attempt);
    $domainTags = $workerId % 2 === 0 ? [$course, $student] : [$course];

    for ($retry = 1; $retry <= 100; ++$retry) {
        if ($outerTransaction) {
            $connection->beginTransaction();
        }

        try {
            $events = $repository->getEvents($domainTags, ['StudentSubscribed']);

            $subscriptions = count(array_filter(
                $events,
                fn($event) => $caseVariants
                    ? in_array(strtolower($course), array_map(strtolower(...), $event->domainTags), true)
                    : in_array($course, $event->domainTags, true),
            ));

            if ($subscriptions >= $capacity) {
                if ($outerTransaction) {
                    $connection->commit();
                }

                ++$summary['full'];

                break;
            }

            if ($outerTransaction) {
                // Handler work between loading and saving, widening the window as in a real application
                usleep(random_int(0, 3000));
            }

            $repository->saveEvents($domainTags, ['StudentSubscribed'], count($events), [
                MySqlTestEnvironment::event(
                    sprintf('%d-%d-%d', $workerId, $attempt, $retry),
                    [$course, $student],
                    'StudentSubscribed',
                    (new DateTimeImmutable())->modify(sprintf('-%d microseconds', random_int(0, $skewMs * 1000))),
                ),
            ]);

            if ($outerTransaction) {
                $connection->commit();
            }

            ++$summary['subscribed'];

            break;
        } catch (OptimisticLockException) {
            ++$summary['conflicts'];
        } catch (DeadlockException) {
            ++$summary['deadlocks'];
        } catch (LockWaitTimeoutException) {
            ++$summary['lockWaitTimeouts'];
        } finally {
            if ($connection->isTransactionActive() && $outerTransaction) {
                $connection->rollBack();
            }
        }
    }
}

echo json_encode($summary, JSON_THROW_ON_ERROR);
