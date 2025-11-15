<?php

echo "Initializing database schema...\n";

try {
    $pdo = new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE'),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD')
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    
    $pdo->exec("DROP TABLE IF EXISTS `migrations`");
    $pdo->exec("CREATE TABLE `migrations` (
      `id` int unsigned NOT NULL AUTO_INCREMENT,
      `migration` varchar(255) NOT NULL,
      `batch` int NOT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    $pdo->exec("DROP TABLE IF EXISTS `users`");
    $pdo->exec("CREATE TABLE `users` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    $pdo->exec("DROP TABLE IF EXISTS `nodes`");
    $pdo->exec("CREATE TABLE `nodes` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    $pdo->exec("INSERT INTO `migrations` (`migration`, `batch`) VALUES
    ('2016_01_23_195641_add_allocations_table', 1),
    ('2016_01_23_195851_add_api_keys', 1),
    ('2019_03_02_151321_fix_unique_index_to_account_for_host', 1),
    ('2020_04_03_230614_create_backups_table', 1)");
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    
    echo "Database schema initialized successfully\n";
    exit(0);
    
} catch (PDOException $e) {
    echo "Database initialization failed: " . $e->getMessage() . "\n";
    exit(1);
}
