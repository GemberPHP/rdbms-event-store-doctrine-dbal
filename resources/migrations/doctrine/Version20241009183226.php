<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241009183226 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
            CREATE TABLE `event_store` (
              `id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
              `event_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
              `payload` json NOT NULL,
              `metadata` json NOT NULL,
              `applied_at` timestamp(6) NOT NULL,
              PRIMARY KEY (`id`),
              KEY `event_name` (`event_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL
        );

        $this->addSql(
            <<<'SQL'
            CREATE TABLE `event_store_relation` (
              `event_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
              `domain_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
              PRIMARY KEY (`event_id`,`domain_id`),
              CONSTRAINT `event_store_relation_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `event_store` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL
        );
    }
}
