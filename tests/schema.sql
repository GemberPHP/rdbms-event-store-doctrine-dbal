CREATE TABLE `event_store` (
  `id` varchar(50) NOT NULL,
  `event_name` varchar(100) NOT NULL,
  `payload` json NOT NULL,
  `metadata` json NOT NULL,
  `applied_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE `event_store_relation` (
  `event_id` varchar(50) NOT NULL,
  `domain_tag` varchar(100) NOT NULL,
  PRIMARY KEY (`event_id`, `domain_tag`)
);

CREATE TABLE `event_store_lock` (
  `boundary_hash` char(64) NOT NULL PRIMARY KEY
);

CREATE TABLE `saga_store` (
  `id` varchar(50) NOT NULL,
  `saga_name` varchar(255) NOT NULL,
  `payload` json NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE `saga_store_relation` (
  `id` varchar(50) NOT NULL,
  `saga_id` varchar(50) NOT NULL,
  PRIMARY KEY (`id`, `saga_id`)
);

CREATE TABLE `saga_store_lock` (
  `boundary_hash` char(64) NOT NULL PRIMARY KEY
);
