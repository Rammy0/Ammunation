<?php

/** Returns the public URL for a stored product image, or the shared placeholder. */
function storeProductImageUrl(array $product): string
{
    foreach (['image', 'image_path', 'image_filename', 'photo', 'thumbnail'] as $column) {
        if (empty($product[$column])) {
            continue;
        }

        $storedPath = str_replace('\\', '/', (string)$product[$column]);
        $filename = basename($storedPath);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'], true)) {
            continue;
        }

        $directory = strpos($storedPath, 'products/') === 0
            || strpos($storedPath, 'assets/images/products/') === 0
            ? 'products/'
            : '';

        return 'assets/images/' . $directory . $filename;
    }

    return 'assets/images/placeholder.svg';
}

/** Loads a customer's cart from the database into the current tab's session context. */
function loadUserStoreCart(int $userId): void
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT product_id, quantity FROM cart WHERE user_id = :user_id ORDER BY id');
    $stmt->execute(['user_id' => $userId]);
    $databaseCart = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productId = (int)$row['product_id'];
        $quantity = (int)$row['quantity'];
        if ($productId > 0 && $quantity > 0) {
            $databaseCart[$productId] = ($databaseCart[$productId] ?? 0) + $quantity;
        }
    }

    $token = getAuthContextToken();
    $alreadyLoaded = isset($_SESSION['auth_contexts'][$token]['cart_database_user_id'])
        && (int)$_SESSION['auth_contexts'][$token]['cart_database_user_id'] === $userId;
    $legacyCart = $_SESSION['cart'] ?? [];

    if (!$alreadyLoaded && !$databaseCart && is_array($legacyCart) && $legacyCart) {
        saveUserStoreCart($userId, $legacyCart);
        foreach ($_SESSION['auth_contexts'] as &$context) {
            if ((int)($context['user_id'] ?? 0) === $userId) {
                $context['cart_database_user_id'] = $userId;
            }
        }
        unset($context);
        return;
    }

    $_SESSION['cart'] = $databaseCart;
    $_SESSION['auth_contexts'][$token]['cart_database_user_id'] = $userId;
}

/** Replaces a customer's persisted cart and updates the current tab's copy. */
function saveUserStoreCart(int $userId, array $cart): void
{
    global $pdo;

    $normalizedCart = [];
    foreach ($cart as $productId => $quantity) {
        $productId = (int)$productId;
        $quantity = (int)$quantity;
        if ($productId > 0 && $quantity > 0) {
            $normalizedCart[$productId] = $quantity;
        }
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('DELETE FROM cart WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $stmt = $pdo->prepare(
            'INSERT INTO cart (user_id, product_id, quantity)
             VALUES (:user_id, :product_id, :quantity)'
        );
        foreach ($normalizedCart as $productId => $quantity) {
            $stmt->execute([
                'user_id' => $userId,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Unable to save customer cart: ' . $e->getMessage());
        throw new RuntimeException('Unable to save your cart. Please try again.', 0, $e);
    }

    $_SESSION['cart'] = $normalizedCart;
    $_SESSION['auth_contexts'][getAuthContextToken()]['cart_database_user_id'] = $userId;
}

/** Builds a consistent cart summary from the current session cart. */
function getStoreCartSummary(array $cart): array
{
    global $pdo;

    $ids = array_values(array_filter(array_map('intval', array_keys($cart)), static function (int $id): bool {
        return $id > 0;
    }));
    if (!$ids) {
        return ['items' => [], 'total' => 0.0, 'count' => 0];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) AND status = 'available' ORDER BY name");
    $stmt->execute($ids);

    $items = [];
    $total = 0.0;
    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
        $productId = (int)$product['id'];
        $quantity = max(1, (int)($cart[$productId] ?? 1));
        $subtotal = (float)$product['price'] * $quantity;
        $items[] = ['product' => $product, 'quantity' => $quantity, 'subtotal' => $subtotal];
        $total += $subtotal;
        $count += $quantity;
    }

    return ['items' => $items, 'total' => $total, 'count' => $count];
}

/** Returns the total quantity in a session cart. */
function getStoreCartCount(array $cart): int
{
    return array_sum(array_map(static function ($quantity): int {
        return max(0, (int)$quantity);
    }, $cart));
}
