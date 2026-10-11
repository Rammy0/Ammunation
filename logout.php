<?php
require_once __DIR__ . '/includes/auth.php';
$homeUrl = withAuthContext('index.php');
logout();
header('Location: ' . $homeUrl);
exit;
?>