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
| `EventStoreConcurrencyTest` (group `mysql`) | Uses two connections. Writer A saves inside an open transaction, holding its locks, and writer B then saves with a 1 second lock wait timeout. Writers with overlapping boundaries, or where one appends an event type the other reads, must block. Writers with the same tags but unrelated event types must not. It also checks that lock rows survive a rollback, and that a real deadlock inside an application transaction (with a child process, `deadlock_child.php`) leaves that transaction usable. |
| `EventStoreConcurrencyStressTest` (group `mysql-stress`) | Spawns 8 worker processes (`worker.php`) that start at the same moment and concurrently subscribe students to courses with a fixed capacity, using both overlapping and identical boundaries. Every course must end exactly at capacity, without deadlocks or lock wait timeouts, and the workers must have run into optimistic lock conflicts. A second scenario shares students across courses and stamps `applied_at` up to 30 ms in the past, so events commit out of timestamp order: the optimistic lock must still detect every conflicting event (an `applied_at`-based check fails this in about 8 of 10 runs). |

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

Only the stress test:

```bash
GEMBER_MYSQL_DSN=pdo-mysql://root:root@127.0.0.1:33061/gember_test vendor/bin/phpunit --no-coverage --group mysql-stress
```

To combine groups, repeat the option (`--group mysql --group mysql-stress`); a comma-separated list runs no tests.

The stress test uses 8 workers, 3 courses with capacity 15, and 40 attempts per worker. Change the constants at
the top of `EventStoreConcurrencyStressTest` to adjust the load.

### Run the stress workers manually

For a heavier or repeated load, run the workers directly. Each worker takes
`<workerId> <courses> <capacity> <attempts> [<startAt> [<students> <skewMs>]]` and prints a JSON summary with the number of subscriptions,
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
