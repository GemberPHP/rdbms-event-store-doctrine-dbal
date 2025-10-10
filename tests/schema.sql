CREATE TABLE `event_store` (
  `id` varchar(50) NOT NULL,
  `event_name` varchar(100) NOT NULL,
  `payload` json NOT NULL,
  `metadata` json NOT NULL,
  `applied_at` timestamp(6) NOT NULL
);

CREATE TABLE `event_store_relation` (
  `event_id` varchar(50) NOT NULL,
  `domain_tag` varchar(50) NOT NULL
);

CREATE TABLE `saga_store` (
  `saga_id` varchar(50),
  `saga_name` varchar(255),
  `payload` json NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL
);