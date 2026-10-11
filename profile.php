<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/store_helpers.php';
requireLogin();

$userId = (int)($_SESSION['user_id'] ?? 0);
$user = getUserById($userId);
$cart = $_SESSION['cart'] ?? [];
$cartSummary = getStoreCartSummary(is_array($cart) ? $cart : []);
$cartItems = $cartSummary['items'];
$cartTotal = $cartSummary['total'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="container page-panel mt-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
        <div>
            <!-- <h1 class="mb-1">Welcome, <?php //echo htmlspecialchars($user['uname'] ?? ''); ?></h1> -->
            <p class="text mb-0">Manage your account and shopping cart.</p>
        </div>
        <div class="d-flex gap-2 mt-3 mt-md-0 flex-wrap">
            <a href="products.php" class="btn btn-primary">Continue shopping</a>
            <a href="logout.php" class="btn btn-outline-light border border-light text-white">Log out</a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h4">Account overview</h2>
                    <p class="mb-2"><strong>Name:</strong> <?php echo htmlspecialchars($user['uname'] ?? ''); ?></p>
                    <p class="mb-2"><strong>Email:</strong> <?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
                    <p class="mb-3"><strong>Member since:</strong> <?php echo htmlspecialchars($user['created_at'] ?? ''); ?></p>
                    <a href="#cartDrawer" data-bs-toggle="offcanvas" aria-controls="cartDrawer" class="btn btn-primary">View cart (<?php echo $cartSummary['count']; ?>)</a>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h2 class="h4">Your cart</h2>
                    <?php if (!$cartItems): ?>
                        <p class="text mb-3">Your cart is empty.</p>
                        <a href="products.php" class="btn btn-outline-primary">Browse products</a>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-2 profile-cart-table">
                                <thead>
                                    <tr>
                                        <th aria-label="Image"></th>
                                        <th>Product</th>
                                        <th>Qty</th>
                                        <th class="text-end">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cartItems as $item): ?>
                                        <tr>
                                            <td>
                                                <img
                                                    class="profile-cart-image"
                                                    src="<?php echo htmlspecialchars(storeProductImageUrl($item['product']), ENT_QUOTES, 'UTF-8'); ?>"
                                                    alt="<?php echo htmlspecialchars($item['product']['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                >
                                            </td>
                                            <td><?php echo htmlspecialchars($item['product']['name']); ?></td>
                                            <td><?php echo $item['quantity']; ?></td>
                                            <td class="text-end">₱<?php echo number_format($item['subtotal'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between fw-bold mb-3">
                            <span>Total</span>
                            <span>₱<?php echo number_format($cartTotal, 2); ?></span>
                        </div>
                        <a href="#cartDrawer" data-bs-toggle="offcanvas" aria-controls="cartDrawer" class="btn btn-primary">Review cart</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>