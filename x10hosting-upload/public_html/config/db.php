<?php

$host = 'YOUR_X10_MYSQL_HOST';
$dbname = 'YOUR_X10_DATABASE_NAME';
$username = 'YOUR_X10_DATABASE_USER';
$password = 'YOUR_X10_DATABASE_PASSWORD';
$site_base_url = 'https://YOUR_DOMAIN';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed.');
}
