#!/usr/bin/env php
<?php

function generatePassword($length = 16) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

function generateUuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

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

echo "===================================\n";
echo "Création d'un nouvel utilisateur\n";
echo "===================================\n\n";

$env = loadEnv(__DIR__ . '/.env.ci');

$dbHost = $env['DB_HOST'] ?? '127.0.0.1';
$dbPort = $env['DB_PORT'] ?? '3306';
$dbName = $env['DB_DATABASE'] ?? 'panel';
$dbUser = $env['DB_USERNAME'] ?? 'pterodactyl';
$dbPass = $env['DB_PASSWORD'] ?? '';

echo "Email: ";
$email = trim(fgets(STDIN));

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Email invalide\n");
}

echo "Username: ";
$username = trim(fgets(STDIN));

if (empty($username)) {
    die("Username invalide\n");
}

echo "Prénom: ";
$nameFirst = trim(fgets(STDIN));

echo "Nom: ";
$nameLast = trim(fgets(STDIN));

echo "Admin (y/n): ";
$adminInput = trim(fgets(STDIN));
$rootAdmin = strtolower($adminInput) === 'y' ? 1 : 0;

$password = generatePassword(16);
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);
$uuid = generateUuid();
$externalId = 'ext_' . bin2hex(random_bytes(8));
$now = date('Y-m-d H:i:s');

try {
    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO users (
            external_id, uuid, email, username, name_first, name_last, 
            password, language, root_admin, use_totp, 
            gravatar, created_at, updated_at
        ) VALUES (
            :external_id, :uuid, :email, :username, :name_first, :name_last,
            :password, 'en', :root_admin, 0,
            1, :created_at, :updated_at
        )
    ");

    $stmt->execute([
        ':external_id' => $externalId,
        ':uuid' => $uuid,
        ':email' => $email,
        ':username' => $username,
        ':name_first' => $nameFirst,
        ':name_last' => $nameLast,
        ':password' => $hashedPassword,
        ':root_admin' => $rootAdmin,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $userId = $pdo->lastInsertId();

    echo "\n✓ Utilisateur créé avec succès !\n\n";
    echo "┌─────────────────────────────────────────────────────────────┐\n";
    echo "│ ID          : " . str_pad($userId, 43) . "│\n";
    echo "│ External ID : " . str_pad($externalId, 43) . "│\n";
    echo "│ UUID        : " . str_pad($uuid, 43) . "│\n";
    echo "│ Email       : " . str_pad($email, 43) . "│\n";
    echo "│ Username    : " . str_pad($username, 43) . "│\n";
    echo "│ Nom         : " . str_pad("$nameFirst $nameLast", 43) . "│\n";
    echo "│ Admin       : " . str_pad($rootAdmin ? 'Oui' : 'Non', 43) . "│\n";
    echo "│ Mot de passe: " . str_pad($password, 43) . "│\n";
    echo "└─────────────────────────────────────────────────────────────┘\n";
    echo "\n⚠ IMPORTANT: Sauvegardez ce mot de passe : $password\n\n";

} catch (PDOException $e) {
    die("Erreur de connexion à la base de données: " . $e->getMessage() . "\n");
}
