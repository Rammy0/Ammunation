<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/store_helpers.php';

if (!isLoggedIn()) {
    sendJsonResponse(['error' => 'Please log in to manage your cart.'], 401);
}
if (isAdmin()) {
    sendJsonResponse(['error' => 'Admin accounts do not have a shopping cart.'], 403);
}

loadUserStoreCart((int)$_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['error' => 'Invalid cart request.'], 405);
}

if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'cart')) {
    sendJsonResponse(['error' => 'Your request expired. Refresh the cart and try again.'], 400);
}

$action = (string)($_POST['cart_action'] ?? '');
$productId = (int)($_POST['product_id'] ?? 0);
if (!in_array($action, ['increase', 'decrease', 'remove', 'clear'], true)
    || ($action !== 'clear' && $productId <= 0)) {
    sendJsonResponse(['error' => 'Invalid cart request.'], 400);
}

if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if ($action === 'clear') {
    $_SESSION['cart'] = [];
} elseif ($action === 'increase' && isset($_SESSION['cart'][$productId])) {
    $_SESSION['cart'][$productId] = (int)$_SESSION['cart'][$productId] + 1;
} elseif ($action === 'decrease' && isset($_SESSION['cart'][$productId])) {
    $quantity = (int)$_SESSION['cart'][$productId] - 1;
    if ($quantity > 0) {
        $_SESSION['cart'][$productId] = $quantity;
    } else {
        unset($_SESSION['cart'][$productId]);
    }
} elseif ($action === 'remove') {
    unset($_SESSION['cart'][$productId]);
}

try {
    saveUserStoreCart((int)$_SESSION['user_id'], $_SESSION['cart']);
} catch (RuntimeException $e) {
    sendJsonResponse(['error' => $e->getMessage()], 500);
}

sendJsonResponse(['success' => true, 'count' => getStoreCartCount($_SESSION['cart'])]);
