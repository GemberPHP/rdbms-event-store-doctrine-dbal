# Testing

## Unit tests

The regular test suite runs against an in-memory SQLite database and needs no setup:

```bash
composer test
```

This runs Rector, PHP CS Fixer, PHPStan, the dependency analyser, PHPUnit and the coverage check.

## MySQL concurrency tests

SQLite only allows a single writer, so it cannot show how the event store behaves under concurrent writes.
The tests in `tests/Integration/MySql` therefore run against a real MySQL server. They are skipped unless the
`GEMBER_MYSQL_DSN` environment variable is set. CI sets it and runs them against a MySQL 8 service.

| Test | What it verifies |
|------|------------------|
| `EventStoreConcurrencyTest` (group `mysql`) | With two connections: writers with overlapping boundaries block each other, unrelated ones don't; lock rows survive a rollback; a deadlock leaves an application transaction usable; a stale snapshot inside an application transaction is rejected; tags differing only in case are separate streams. |
| `EventStoreConcurrencyStressTest` (group `mysql-stress`) | 8 worker processes (`worker.php`) subscribe students to courses concurrently: no course may exceed its capacity, without deadlocks or lock wait timeouts. Scenarios: overlapping boundaries, shared tags with `applied_at` out of commit order, an application transaction around load and save, and differently cased tags. |
| `SagaStoreConcurrencyTest` (group `mysql`) | With two connections: a save of an older revision inside an application transaction is rejected, and so is a second creation of the same saga. |
| `SagaStoreConcurrencyStressTest` (group `mysql-stress`) | 8 worker processes (`saga_worker.php`) change the same 3 sagas concurrently: every change is saved exactly once, revisions match, one saga per saga id. Also inside an application transaction. |
| `OutboxConcurrencyTest` (group `mysql`) | With two connections: claimed messages are skipped without waiting (`SKIP LOCKED`), expired leases are taken over, failed claims roll back, dead-lettering and backoff behave as on SQLite, and a poll doesn't scan messages waiting for a retry. |
| `OutboxConcurrencyStressTest` (group `mysql-stress`) | 4 outbox processors (`outbox_worker.php`) process 1,000 messages: every message is dispatched exactly once. Also without `SKIP LOCKED` (as on MySQL 5.7). |
| `OutboxSqliteConcurrencyTest` (default suite) | 3 outbox processors on one SQLite file: every message is dispatched exactly once and no processor fails. |

### Start MySQL

```bash
docker run -d --name gember-mysql \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=gember_test \
  -p 33061:3306 mysql:8.0
```

MySQL needs 10-30 seconds before it accepts connections. Wait until this prints `mysqld is alive`:

```bash
docker exec gember-mysql mysqladmin -uroot -proot -h127.0.0.1 ping
```

> [!WARNING]
> The tests drop and recreate all tables in the configured database. Only point `GEMBER_MYSQL_DSN` at a
> dedicated test database.

### Run the tests

All MySQL tests:

```bash
GEMBER_MYSQL_DSN=pdo-mysql://root:root@127.0.0.1:33061/gember_test vendor/bin/phpunit --no-coverage tests/Integration
```

Only the two-connection tests:

```bash
GEMBER_MYSQL_DSN=pdo-mysql://root:root@127.0.0.1:33061/gember_test vendor/bin/phpunit --no-coverage --group mysql
```

Only the stress tests (event store, saga store and outbox):

```bash
GEMBER_MYSQL_DSN=pdo-mysql://root:root@127.0.0.1:33061/gember_test vendor/bin/phpunit --no-coverage --group mysql-stress
```

To combine groups, repeat the option (`--group mysql --group mysql-stress`); a comma-separated list runs no tests.

The event store stress test uses 8 workers and 40 attempts per worker (the constants `WORKERS` and
`ATTEMPTS_PER_WORKER` in `EventStoreConcurrencyStressTest`); the courses and capacity are set per scenario in its test methods.

### Run the stress workers manually

For a heavier or repeated load, run the workers directly. Each worker takes
`<workerId> <courses> <capacity> <attempts> [<startAt> [<students> <skewMs> [<outerTransaction> [<caseVariants>]]]]` and prints a JSON summary with the number of subscriptions,
optimistic lock conflicts, deadlocks and lock wait timeouts. Even worker ids use the boundary
`{course, student}`, odd ones `{course}`.

```bash
export GEMBER_MYSQL_DSN=pdo-mysql://root:root@127.0.0.1:33061/gember_test

# Reset the schema
php -r 'require "vendor/autoload.php"; use Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql\MySqlTestEnvironment as E; E::resetSchema(E::createConnection(getenv("GEMBER_MYSQL_DSN")));'

# 16 workers, 6 courses with capacity 40, 100 attempts each
for i in $(seq 1 16); do php tests/Integration/MySql/worker.php $i 6 40 100 & done; wait
```

Without a start time argument the workers start immediately. Afterwards, check that no course exceeds its capacity:

```bash
docker exec gember-mysql mysql -uroot -proot gember_test -e "
  SELECT esr.domain_tag, COUNT(*) AS subscriptions
  FROM event_store es JOIN event_store_relation esr ON es.id = esr.event_id
  WHERE es.event_name = 'StudentSubscribed' AND esr.domain_tag LIKE 'course-%'
  GROUP BY esr.domain_tag"
```

The examples use bash syntax. In fish, use `export VAR=value` and
`for i in (seq 1 16); php tests/Integration/MySql/worker.php $i 6 40 100 &; end; wait`.

### Clean up

```bash
docker rm -f gember-mysql
```
