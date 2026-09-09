<?php
require_once __DIR__ . '/includes/header.php';
requireLogin();

$userId = (int)($_SESSION['user_id'] ?? 0);
$user = getUserById($userId);
$errors = [];
$success = false;
$cartItems = [];
$cartTotal = 0.0;

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if ($name === '') $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if ($password !== '' && strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $password_confirm) $errors[] = 'Passwords do not match.';

    if (empty($errors)){
        $res = updateUser($userId, $name, $email, $password === '' ? null : $password);
        if ($res['success']){
            $success = true;
            $user = getUserById($userId);
        } else {
            $errors[] = $res['error'] ?? 'Failed to update profile.';
        }
    }
}

$cart = $_SESSION['cart'] ?? [];
if (!empty($cart) && is_array($cart)) {
    $cartIds = array_values(array_filter(array_map('intval', array_keys($cart)), static function (int $id): bool {
        return $id > 0;
    }));
    if ($cartIds) {
        $placeholders = implode(',', array_fill(0, count($cartIds), '?'));
        $stmt = $pdo->prepare("SELECT id, name, price FROM products WHERE id IN ($placeholders) AND status = 'available' ORDER BY name");
        $stmt->execute($cartIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $quantity = max(1, (int)($cart[$product['id']] ?? 1));
            $subtotal = (float)$product['price'] * $quantity;
            $cartItems[] = ['product' => $product, 'quantity' => $quantity, 'subtotal' => $subtotal];
            $cartTotal += $subtotal;
        }
    }
}
?>

<div class="container page-panel mt-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
        <div>
            <h1 class="mb-1">Welcome, <?php echo htmlspecialchars($user['name'] ?? ''); ?></h1>
            <p class="text-muted mb-0">Manage your account and shopping cart.</p>
        </div>
        <div class="d-flex gap-2 mt-3 mt-md-0 flex-wrap">
            <a href="products.php" class="btn btn-primary">Continue shopping</a>
            <a href="logout.php" class="btn btn-outline-light border border-light text-white">Log out</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">Profile updated.</div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><ul><?php foreach($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h4">Account overview</h2>
                    <p class="mb-2"><strong>Name:</strong> <?php echo htmlspecialchars($user['name'] ?? ''); ?></p>
                    <p class="mb-2"><strong>Email:</strong> <?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
                    <p class="mb-3"><strong>Member since:</strong> <?php echo htmlspecialchars($user['created_at'] ?? ''); ?></p>
                    <a href="cart.php" class="btn btn-primary">View full cart (<?php echo count($cartItems); ?>)</a>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h2 class="h4">Your cart</h2>
                    <?php if (!$cartItems): ?>
                        <p class="text-muted mb-3">Your cart is empty.</p>
                        <a href="products.php" class="btn btn-outline-primary">Browse products</a>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-2">
                                <thead><tr><th>Product</th><th>Qty</th><th class="text-end">Subtotal</th></tr></thead>
                                <tbody>
                                    <?php foreach ($cartItems as $item): ?>
                                        <tr>
                                            <td><a href="product.php?id=<?php echo (int)$item['product']['id']; ?>"><?php echo htmlspecialchars($item['product']['name']); ?></a></td>
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
                        <a href="cart.php" class="btn btn-primary">Review cart</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>