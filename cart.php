<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/store_helpers.php';
requireLogin();

$cart = $_SESSION['cart'] ?? [];
$cartAction = (string)($_POST['cart_action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cartAction !== '') {
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'cart')) {
        http_response_code(400);
        exit('Invalid or expired request. Please try again.');
    }

    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    if ($cartAction === 'clear') {
        $_SESSION['cart'] = [];
    } else {
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId <= 0 || !in_array($cartAction, ['increase', 'decrease', 'remove'], true)) {
            http_response_code(400);
            exit('Invalid cart request.');
        }

        if ($cartAction === 'increase') {
            $_SESSION['cart'][$productId] = ((int)($_SESSION['cart'][$productId] ?? 0)) + 1;
        } elseif ($cartAction === 'decrease') {
            $quantity = ((int)($_SESSION['cart'][$productId] ?? 0)) - 1;
            if ($quantity > 0) {
                $_SESSION['cart'][$productId] = $quantity;
            } else {
                unset($_SESSION['cart'][$productId]);
            }
        } else {
            unset($_SESSION['cart'][$productId]);
        }
    }

    header('Location: cart.php');
    exit;
}

$summary = getStoreCartSummary(is_array($cart) ? $cart : []);
$items = $summary['items'];
$total = $summary['total'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="container page-panel mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <h1 class="mb-0">Cart</h1>
        <a href="products.php" class="btn btn-primary px-4 py-2 shadow-sm">Back to products</a>
    </div>
    <?php if (isset($_GET['buy_now'])): ?>
        <div class="alert alert-success">Item added. Review your order below before checkout.</div>
    <?php endif; ?>
    <?php if (!$items): ?>
        <div class="alert alert-info">Your cart is empty.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle cart-table">
                <thead><tr><th>Product</th><th>Quantity</th><th>Price</th><th>Subtotal</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['product']['name']); ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <form method="post" class="m-0">
                                        <?php echo csrfInputField('cart'); ?>
                                        <input type="hidden" name="cart_action" value="decrease">
                                        <input type="hidden" name="product_id" value="<?php echo (int)$item['product']['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="Decrease quantity">-</button>
                                    </form>
                                    <span><?php echo $item['quantity']; ?></span>
                                    <form method="post" class="m-0">
                                        <?php echo csrfInputField('cart'); ?>
                                        <input type="hidden" name="cart_action" value="increase">
                                        <input type="hidden" name="product_id" value="<?php echo (int)$item['product']['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="Increase quantity">+</button>
                                    </form>
                                </div>
                            </td>
                            <td>₱<?php echo number_format((float)$item['product']['price'], 2); ?></td>
                            <td>₱<?php echo number_format($item['subtotal'], 2); ?></td>
                            <td>
                                <form method="post" class="m-0">
                                    <?php echo csrfInputField('cart'); ?>
                                    <input type="hidden" name="cart_action" value="remove">
                                    <input type="hidden" name="product_id" value="<?php echo (int)$item['product']['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <h3 class="text-end">Total: ₱<?php echo number_format($total, 2); ?></h3>
        <div class="d-flex justify-content-end gap-2">
            <form method="post">
                <?php echo csrfInputField('cart'); ?>
                <input type="hidden" name="cart_action" value="clear">
                <button type="submit" class="btn btn-outline-danger">Clear cart</button>
            </form>
            <p class="text-muted mb-0 align-self-center">Checkout is not yet available.</p>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
