#!/usr/bin/env php
<?php

function loadEnv($path) {
    if (!file_exists($path)) {
        return [];
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        $value = trim($value, '"\'');
        
        $env[$name] = $value;
    }
    
    return $env;
}

$env = loadEnv(__DIR__ . '/.env.ci');

$dbHost = $env['DB_HOST'] ?? '127.0.0.1';
$dbPort = $env['DB_PORT'] ?? '3306';
$dbName = $env['DB_DATABASE'] ?? 'panel';
$dbUser = $env['DB_USERNAME'] ?? 'pterodactyl';
$dbPass = $env['DB_PASSWORD'] ?? '';

try {
    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Structure de la table 'users':\n\n";
    
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll();
    
    foreach ($columns as $col) {
        printf("%-20s %-20s %-10s %-10s %-20s %s\n", 
            $col['Field'], 
            $col['Type'], 
            $col['Null'], 
            $col['Key'], 
            $col['Default'] ?? 'NULL',
            $col['Extra']
        );
    }
    
    echo "\n\nExemple d'un utilisateur existant:\n\n";
    
    $stmt = $pdo->query("SELECT * FROM users LIMIT 1");
    $user = $stmt->fetch();
    
    if ($user) {
        foreach ($user as $key => $value) {
            printf("%-20s : %s\n", $key, $value ?? 'NULL');
        }
    } else {
        echo "Aucun utilisateur dans la base.\n";
    }

} catch (PDOException $e) {
    die("Erreur: " . $e->getMessage() . "\n");
}
