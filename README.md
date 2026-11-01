# 🫚 Gember Event store: Doctrine DBAL
[![Build Status](https://github.com/GemberPHP/rdbms-event-store-doctrine-dbal/actions/workflows/test.yaml/badge.svg?branch=main)](https://github.com/GemberPHP/rdbms-event-store-doctrine-dbal/actions/workflows/test.yaml)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-8892BF.svg?style=flat)](http://www.php.net)

Gember Event store ([gember/event-sourcing](https://github.com/GemberPHP/event-sourcing)) implementation based on [doctrine/dbal](https://github.com/doctrine/dbal).

## Installation

```bash
composer require gember/rdbms-event-store-doctrine-dbal
```

With [gember/event-sourcing-symfony-bundle](https://github.com/GemberPHP/event-sourcing-symfony-bundle) or [gember/event-sourcing-universal-service-provider](https://github.com/GemberPHP/event-sourcing-universal-service-provider), this package is installed and wired automatically.

## Database

Supported: MySQL and MariaDB (InnoDB), and SQLite. Other platforms throw an `UnsupportedPlatformException`.

The tables (event store, saga store, snapshot store and outbox) are available as:
- Raw SQL (MySQL/MariaDB): [resources/schema.sql](resources/schema.sql)
- Doctrine migrations: [resources/migrations/doctrine](resources/migrations/doctrine) (namespace `DoctrineMigrations`; copy them into your migrations directory)
- Phinx migrations: [resources/migrations/phinx](resources/migrations/phinx)

While in 0.x, released migrations are changed in place: a database that already ran them needs the manual steps in [UPGRADE.md](UPGRADE.md).

## Documentation

- [Gember Event Sourcing documentation](https://gemberphp.github.io/event-sourcing/)
- [Testing](docs/testing.md): running the unit tests and the MySQL concurrency tests
