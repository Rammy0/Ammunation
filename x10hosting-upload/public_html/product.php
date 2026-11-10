<?php
require_once __DIR__ . '/includes/auth.php';
header('Location: ' . withAuthContext('products.php'), true, 302);
exit;
