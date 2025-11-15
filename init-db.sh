#!/bin/sh

echo "Initializing database schema..."

mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" <<'EOSQL'
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `external_id` varchar(255) DEFAULT NULL,
  `uuid` char(36) NOT NULL,
  `username` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `name_first` varchar(255) DEFAULT NULL,
  `name_last` varchar(255) DEFAULT NULL,
  `password` text NOT NULL,
  `language` char(5) NOT NULL DEFAULT 'en',
  `root_admin` tinyint unsigned NOT NULL DEFAULT '0',
  `use_totp` tinyint unsigned NOT NULL DEFAULT '0',
  `totp_secret` text,
  `totp_authenticated_at` timestamp NULL DEFAULT NULL,
  `gravatar` tinyint unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_uuid_unique` (`uuid`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_username_unique` (`username`),
  UNIQUE KEY `users_external_id_unique` (`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `nodes`;
CREATE TABLE `nodes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `public` tinyint unsigned NOT NULL DEFAULT '1',
  `name` varchar(255) NOT NULL,
  `description` text,
  `location_id` int unsigned NOT NULL,
  `fqdn` varchar(255) NOT NULL,
  `scheme` enum('http','https') NOT NULL DEFAULT 'https',
  `behind_proxy` tinyint unsigned NOT NULL DEFAULT '0',
  `maintenance_mode` tinyint unsigned NOT NULL DEFAULT '0',
  `memory` int unsigned NOT NULL,
  `memory_overallocate` int NOT NULL DEFAULT '0',
  `disk` int unsigned NOT NULL,
  `disk_overallocate` int NOT NULL DEFAULT '0',
  `upload_size` int unsigned NOT NULL DEFAULT '100',
  `daemon_token_id` char(16) NOT NULL,
  `daemon_token` text NOT NULL,
  `daemonListen` smallint unsigned NOT NULL DEFAULT '8080',
  `daemonSFTP` smallint unsigned NOT NULL DEFAULT '2022',
  `daemonBase` varchar(255) NOT NULL DEFAULT '/var/lib/pterodactyl/volumes',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nodes_uuid_unique` (`uuid`),
  UNIQUE KEY `nodes_daemon_token_id_unique` (`daemon_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `locations`;
CREATE TABLE `locations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `short` varchar(60) NOT NULL,
  `long` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `locations_short_unique` (`short`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `servers`;
CREATE TABLE `servers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `external_id` varchar(255) DEFAULT NULL,
  `uuid` char(36) NOT NULL,
  `uuidShort` char(8) NOT NULL,
  `node_id` int unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` varchar(255) DEFAULT NULL,
  `skip_scripts` tinyint unsigned NOT NULL DEFAULT '0',
  `owner_id` int unsigned NOT NULL,
  `memory` int unsigned NOT NULL,
  `swap` int NOT NULL,
  `disk` int unsigned NOT NULL,
  `io` int unsigned NOT NULL,
  `cpu` int unsigned NOT NULL,
  `threads` varchar(255) DEFAULT NULL,
  `oom_disabled` tinyint unsigned NOT NULL DEFAULT '1',
  `allocation_id` int unsigned NOT NULL,
  `nest_id` int unsigned NOT NULL,
  `egg_id` int unsigned NOT NULL,
  `startup` text NOT NULL,
  `image` varchar(255) NOT NULL,
  `allocation_limit` int unsigned DEFAULT NULL,
  `database_limit` int unsigned DEFAULT NULL,
  `backup_limit` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `servers_uuid_unique` (`uuid`),
  UNIQUE KEY `servers_uuidshort_unique` (`uuidShort`),
  UNIQUE KEY `servers_allocation_id_unique` (`allocation_id`),
  UNIQUE KEY `servers_external_id_unique` (`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `allocations`;
CREATE TABLE `allocations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `node_id` int unsigned NOT NULL,
  `ip` varchar(255) NOT NULL,
  `ip_alias` text,
  `port` smallint unsigned NOT NULL,
  `server_id` int unsigned DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `allocations_node_id_ip_port_unique` (`node_id`,`ip`,`port`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `nests`;
CREATE TABLE `nests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `author` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nests_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `eggs`;
CREATE TABLE `eggs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `nest_id` int unsigned NOT NULL,
  `author` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `features` json DEFAULT NULL,
  `docker_images` json NOT NULL,
  `file_denylist` json DEFAULT NULL,
  `update_url` text,
  `config_files` text,
  `config_startup` text,
  `config_stop` varchar(255) DEFAULT NULL,
  `config_logs` text,
  `startup` text,
  `script_container` varchar(255) NOT NULL DEFAULT 'alpine:3.4',
  `copy_script_from` int unsigned DEFAULT NULL,
  `script_entry` varchar(255) NOT NULL DEFAULT 'ash',
  `script_is_privileged` tinyint unsigned NOT NULL DEFAULT '1',
  `script_install` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `eggs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `egg_variables`;
CREATE TABLE `egg_variables` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `egg_id` int unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `env_variable` varchar(255) NOT NULL,
  `default_value` text NOT NULL,
  `user_viewable` tinyint unsigned NOT NULL,
  `user_editable` tinyint unsigned NOT NULL,
  `rules` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `server_variables`;
CREATE TABLE `server_variables` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `server_id` int unsigned NOT NULL,
  `variable_id` int unsigned NOT NULL,
  `variable_value` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_variables_server_id_variable_id_unique` (`server_id`,`variable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `key` varchar(255) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `api_keys`;
CREATE TABLE `api_keys` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `key_type` tinyint unsigned NOT NULL DEFAULT '0',
  `identifier` char(16) NOT NULL,
  `token` text NOT NULL,
  `allowed_ips` text,
  `memo` text,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_keys_identifier_unique` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `subusers`;
CREATE TABLE `subusers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `server_id` int unsigned NOT NULL,
  `permissions` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subusers_user_id_server_id_unique` (`user_id`,`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `schedules`;
CREATE TABLE `schedules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `server_id` int unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `cron_day_of_week` varchar(255) NOT NULL,
  `cron_month` varchar(255) NOT NULL,
  `cron_day_of_month` varchar(255) NOT NULL,
  `cron_hour` varchar(255) NOT NULL,
  `cron_minute` varchar(255) NOT NULL,
  `is_active` tinyint unsigned NOT NULL,
  `is_processing` tinyint unsigned NOT NULL,
  `only_when_online` tinyint unsigned NOT NULL DEFAULT '0',
  `last_run_at` timestamp NULL DEFAULT NULL,
  `next_run_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tasks`;
CREATE TABLE `tasks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` int unsigned NOT NULL,
  `sequence_id` int unsigned NOT NULL,
  `action` varchar(255) NOT NULL,
  `payload` text NOT NULL,
  `time_offset` int unsigned NOT NULL,
  `is_queued` tinyint unsigned NOT NULL,
  `continue_on_failure` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `databases`;
CREATE TABLE `databases` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `server_id` int unsigned NOT NULL,
  `database_host_id` int unsigned NOT NULL,
  `database` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `remote` varchar(255) NOT NULL DEFAULT '%',
  `password` text NOT NULL,
  `max_connections` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `databases_database_host_id_database_unique` (`database_host_id`,`database`),
  UNIQUE KEY `databases_database_host_id_username_unique` (`database_host_id`,`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `database_hosts`;
CREATE TABLE `database_hosts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `host` varchar(255) NOT NULL,
  `port` int unsigned NOT NULL,
  `username` varchar(255) NOT NULL,
  `password` text NOT NULL,
  `max_databases` int unsigned DEFAULT NULL,
  `node_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `backups`;
CREATE TABLE `backups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `server_id` int unsigned NOT NULL,
  `uuid` char(36) NOT NULL,
  `is_successful` tinyint unsigned NOT NULL DEFAULT '1',
  `is_locked` tinyint unsigned NOT NULL DEFAULT '0',
  `name` varchar(255) NOT NULL,
  `ignored_files` text NOT NULL,
  `disk` varchar(255) NOT NULL,
  `checksum` varchar(255) DEFAULT NULL,
  `bytes` bigint unsigned NOT NULL DEFAULT '0',
  `uploaded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `backups_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `is_system` tinyint unsigned NOT NULL DEFAULT '0',
  `user_id` int unsigned DEFAULT NULL,
  `server_id` int unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `subaction` varchar(255) DEFAULT NULL,
  `device` json NOT NULL,
  `metadata` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `payload` text NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`) VALUES
('2016_01_23_195641_add_allocations_table', 1),
('2016_01_23_195851_add_api_keys', 1),
('2019_03_02_151321_fix_unique_index_to_account_for_host', 1),
('2020_04_03_230614_create_backups_table', 1);

SET FOREIGN_KEY_CHECKS=1;
EOSQL

echo "Database schema initialized successfully"
