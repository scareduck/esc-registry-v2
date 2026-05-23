<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$dsn  = getenv('DB_DSN')  ?: 'mysql:host=127.0.0.1;dbname=RegistryDB;charset=utf8mb4';
$user = getenv('DB_USER') ?: 'registry_ro';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$type = $_GET['type'] ?? '';

// TODO: add query handlers here
http_response_code(400);
echo json_encode(['error' => 'unknown type']);
