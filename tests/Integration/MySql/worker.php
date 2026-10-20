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
 * Usage: php worker.php <workerId> <courses> <capacity> <attempts> [<startAt> [<students> <skewMs>]]
 * Workers wait until the <startAt> unix timestamp (float), so they start competing at the same time.
 * With <students> > 0, students come from a pool shared across courses (a student may subscribe more than once,
 * only capacity matters), so a writer's stream also contains other courses' events via the student tag.
 * <skewMs> stamps applied_at up to that many milliseconds in the past, as happens when a writer stamps its
 * events before waiting for locks, or with clock drift between hosts.
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

$connection = MySqlTestEnvironment::createConnection($dsn);
$repository = MySqlTestEnvironment::createRepository($connection);

if ($startAt > microtime(true)) {
    time_sleep_until($startAt);
}

$summary = ['subscribed' => 0, 'full' => 0, 'conflicts' => 0, 'deadlocks' => 0, 'lockWaitTimeouts' => 0];

for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
    $course = 'course-' . random_int(1, $courses);
    $student = $students > 0 ? 'student-' . random_int(1, $students) : sprintf('student-%d-%d', $workerId, $attempt);
    $domainTags = $workerId % 2 === 0 ? [$course, $student] : [$course];

    for ($retry = 1; $retry <= 100; ++$retry) {
        $events = $repository->getEvents($domainTags, ['StudentSubscribed']);

        $subscriptions = count(array_filter(
            $events,
            fn($event) => in_array($course, $event->domainTags, true),
        ));

        if ($subscriptions >= $capacity) {
            ++$summary['full'];

            break;
        }

        try {
            $repository->saveEvents($domainTags, ['StudentSubscribed'], count($events), [
                MySqlTestEnvironment::event(
                    sprintf('%d-%d-%d', $workerId, $attempt, $retry),
                    [$course, $student],
                    'StudentSubscribed',
                    new DateTimeImmutable()->modify(sprintf('-%d microseconds', random_int(0, $skewMs * 1000))),
                ),
            ]);

            ++$summary['subscribed'];

            break;
        } catch (OptimisticLockException) {
            ++$summary['conflicts'];
        } catch (DeadlockException) {
            ++$summary['deadlocks'];
        } catch (LockWaitTimeoutException) {
            ++$summary['lockWaitTimeouts'];
        }
    }
}

echo json_encode($summary, JSON_THROW_ON_ERROR);
