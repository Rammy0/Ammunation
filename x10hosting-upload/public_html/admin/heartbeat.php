<?php
require_once __DIR__ . '/../includes/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['ok' => false], 405);
}

if (!isLoggedIn() || !isAdmin() || !refreshAdminSession()) {
    logout();
    sendJsonResponse(['ok' => false, 'expired' => true], 401);
}

sendJsonResponse(['ok' => true]);
