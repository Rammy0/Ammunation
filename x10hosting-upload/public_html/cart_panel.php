<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/store_helpers.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Please log in to view your cart.');
}
if (isAdmin()) {
    http_response_code(403);
    exit('Admin accounts do not have a shopping cart.');
}

loadUserStoreCart((int)$_SESSION['user_id']);
$cart = $_SESSION['cart'] ?? [];
$summary = getStoreCartSummary(is_array($cart) ? $cart : []);
$items = $summary['items'];
$total = $summary['total'];
$cartCount = $summary['count'];
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
                        src="<?php echo htmlspecialchars(storeProductImageUrl($item['product']), ENT_QUOTES, 'UTF-8'); ?>"
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
        <a href="<?php echo htmlspecialchars(withAuthContext('checkout.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success w-100 mt-2">Proceed to checkout</a>
    <?php endif; ?>
</div>
