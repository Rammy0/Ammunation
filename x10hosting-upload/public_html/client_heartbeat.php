<?php
require_once __DIR__ . '/includes/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['ok' => false], 405);
}

if (!isLoggedIn() || isAdmin()) {
    sendJsonResponse(['ok' => false], 401);
}

try {
    ensureClientPresenceTable();
    $stmt = $pdo->prepare(
        'INSERT INTO client_presence (user_id, last_seen)
         VALUES (:user_id, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE last_seen = UTC_TIMESTAMP()'
    );
    $stmt->execute(['user_id' => (int)$_SESSION['user_id']]);
    sendJsonResponse(['ok' => true]);
} catch (Throwable $e) {
    error_log('Unable to update client presence: ' . $e->getMessage());
    sendJsonResponse(['ok' => false], 500);
}
