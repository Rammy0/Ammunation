<?php
require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Please log in to view your cart.');
}

$cart = $_SESSION['cart'] ?? [];
$items = [];
$total = 0.0;
$cartCount = 0;

function cartPanelImagePath(array $product): string
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

        if (strpos($storedPath, 'assets/images/') === 0) {
            return $storedPath;
        }

        if (strpos($storedPath, 'products/') === 0) {
            return 'assets/images/' . $storedPath;
        }

        return 'assets/images/' . $filename;
    }

    return 'assets/images/placeholder.svg';
}

if (is_array($cart) && $cart) {
    $ids = array_values(array_filter(array_map('intval', array_keys($cart)), static function (int $id): bool {
        return $id > 0;
    }));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) AND status = 'available'");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $productId = (int)$product['id'];
            $quantity = max(1, (int)($cart[$productId] ?? 1));
            $subtotal = (float)$product['price'] * $quantity;
            $items[] = ['product' => $product, 'quantity' => $quantity, 'subtotal' => $subtotal];
            $total += $subtotal;
            $cartCount += $quantity;
        }
    }
}
?>
<div class="cart-drawer-content" data-cart-count="<?php echo $cartCount; ?>">
    <?php if (!$items): ?>
        <p>Your cart is empty.</p>
        <a href="products.php" class="btn btn-primary w-100">Browse products</a>
    <?php else: ?>
        <div class="cart-drawer-items">
            <?php foreach ($items as $item): ?>
                <article class="cart-drawer-item">
                    <img
                        class="cart-drawer-item-image"
                        src="<?php echo htmlspecialchars(cartPanelImagePath($item['product']), ENT_QUOTES, 'UTF-8'); ?>"
                        alt="<?php echo htmlspecialchars($item['product']['name'], ENT_QUOTES, 'UTF-8'); ?>"
                    >
                    <div class="cart-drawer-item-info">
                        <h3><?php echo htmlspecialchars($item['product']['name']); ?></h3>
                        <p>₱<?php echo number_format((float)$item['product']['price'], 2); ?> each</p>
                    </div>
                    <div class="cart-drawer-item-subtotal">₱<?php echo number_format($item['subtotal'], 2); ?></div>
                    <div class="cart-drawer-controls">
                        <form method="post" action="cart_action.php" class="cart-drawer-action">
                            <?php echo csrfInputField('cart'); ?>
                            <input type="hidden" name="cart_action" value="decrease">
                            <input type="hidden" name="product_id" value="<?php echo (int)$item['product']['id']; ?>">
                            <button type="submit" aria-label="Decrease quantity">−</button>
                        </form>
                        <span><?php echo $item['quantity']; ?></span>
                        <form method="post" action="cart_action.php" class="cart-drawer-action">
                            <?php echo csrfInputField('cart'); ?>
                            <input type="hidden" name="cart_action" value="increase">
                            <input type="hidden" name="product_id" value="<?php echo (int)$item['product']['id']; ?>">
                            <button type="submit" aria-label="Increase quantity">+</button>
                        </form>
                        <form method="post" action="cart_action.php" class="cart-drawer-action ms-auto">
                            <?php echo csrfInputField('cart'); ?>
                            <input type="hidden" name="cart_action" value="remove">
                            <input type="hidden" name="product_id" value="<?php echo (int)$item['product']['id']; ?>">
                            <button type="submit" class="remove-item">Remove</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="cart-drawer-total">
            <span>Total</span>
            <strong>₱<?php echo number_format($total, 2); ?></strong>
        </div>
        <form method="post" action="cart_action.php" class="cart-drawer-action">
            <?php echo csrfInputField('cart'); ?>
            <input type="hidden" name="cart_action" value="clear">
            <button type="submit" class="btn btn-outline-danger w-100">Clear cart</button>
        </form>
        <button type="button" class="btn btn-success w-100 mt-2" disabled>Proceed to checkout</button>
        <p class="cart-drawer-note">Checkout is not yet available.</p>
    <?php endif; ?>
</div>
