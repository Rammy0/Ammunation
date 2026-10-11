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
