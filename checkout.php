<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/store_helpers.php';
require_once __DIR__ . '/includes/order_helpers.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . withAuthContext('admin/admin.php'));
    exit;
}

loadUserStoreCart((int)$_SESSION['user_id']);
$userId = (int)$_SESSION['user_id'];
$errors = [];
$summary = getStoreCartSummary(is_array($_SESSION['cart'] ?? null) ? $_SESSION['cart'] : []);
$customerName = '';
$address = '';
$contactNumber = '';
$paymentMethod = (string)($_POST['payment_method'] ?? 'cash_on_delivery');
$schemaReady = false;
$successOrder = null;
$successItems = [];

try {
    ensureOrderSchema($pdo);
    $contactColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'contact_number'")->fetch(PDO::FETCH_ASSOC);
    if (!$contactColumn) {
        $pdo->exec('ALTER TABLE users ADD COLUMN contact_number VARCHAR(30) NULL');
    }

    $stmt = $pdo->prepare('SELECT uname, delivery_address, contact_number FROM users WHERE id = :id AND is_admin = 0 LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        throw new RuntimeException('The customer account could not be found.');
    }
    $customerName = (string)$userRow['uname'];
    $address = (string)($userRow['delivery_address'] ?? '');
    $contactNumber = (string)($userRow['contact_number'] ?? '');
    $schemaReady = true;
} catch (Throwable $e) {
    error_log('Unable to prepare order checkout: ' . $e->getMessage());
    $errors[] = 'Checkout is currently unavailable. Please try again later.';
}

$successOrderId = (int)($_GET['success'] ?? 0);
if ($successOrderId > 0 && $schemaReady) {
    $stmt = $pdo->prepare(
        'SELECT id, customer_name, total_amount, delivery_address, status, payment_method, created_at
         FROM requests WHERE id = :id AND user_id = :user_id LIMIT 1'
    );
    $stmt->execute(['id' => $successOrderId, 'user_id' => $userId]);
    $successOrder = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($successOrder) {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(ri.product_name, p.name) AS product_name, ri.quantity, ri.price
             FROM request_items ri
             LEFT JOIN products p ON p.id = ri.product_id
             WHERE ri.request_id = :request_id
             ORDER BY ri.id'
        );
        $stmt->execute(['request_id' => $successOrderId]);
        $successItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaReady) {
    $customerName = trim((string)($_POST['customer_name'] ?? ''));
    $address = trim((string)($_POST['delivery_address'] ?? ''));
    $contactNumber = trim((string)($_POST['contact_number'] ?? ''));
    $paymentMethod = (string)($_POST['payment_method'] ?? '');

    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'checkout')) {
        http_response_code(400);
        $errors[] = 'This checkout request expired. Reload the page and try again.';
    } elseif (!$summary['items']) {
        $errors[] = 'Your cart is empty.';
    } elseif ($customerName === '' || strlen($customerName) > 100) {
        $errors[] = 'Enter your full name (up to 100 characters).';
    } elseif ($address === '' || strlen($address) > 2000) {
        $errors[] = 'Enter a delivery address of 2,000 characters or fewer.';
    } elseif (strlen($contactNumber) > 30 || preg_match('/^[0-9+().\\s-]{7,30}$/', $contactNumber) !== 1) {
        $errors[] = 'Enter a valid contact number.';
    } elseif (!in_array($paymentMethod, ['cash_on_delivery', 'credit_card'], true)) {
        $errors[] = 'Choose a payment method.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE users SET delivery_address = :address, contact_number = :contact_number
                 WHERE id = :id AND is_admin = 0'
            );
            $stmt->execute([
                'address' => $address,
                'contact_number' => $contactNumber,
                'id' => $userId,
            ]);

            $stmt = $pdo->prepare(
                'INSERT INTO requests
                    (user_id, customer_name, contact_number, payment_method, total_amount, delivery_address, status)
                 VALUES (:user_id, :customer_name, :contact_number, :payment_method, :total_amount, :delivery_address, :status)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'customer_name' => $customerName,
                'contact_number' => $contactNumber,
                'payment_method' => $paymentMethod,
                'total_amount' => $summary['total'],
                'delivery_address' => $address,
                'status' => 'pending',
            ]);
            $orderId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO request_items (request_id, product_id, product_name, quantity, price)
                 VALUES (:request_id, :product_id, :product_name, :quantity, :price)'
            );
            foreach ($summary['items'] as $item) {
                $itemStmt->execute([
                    'request_id' => $orderId,
                    'product_id' => (int)$item['product']['id'],
                    'product_name' => (string)$item['product']['name'],
                    'quantity' => (int)$item['quantity'],
                    'price' => (float)$item['product']['price'],
                ]);
            }

            $stmt = $pdo->prepare('DELETE FROM cart WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $userId]);
            $pdo->commit();

            $_SESSION['cart'] = [];
            foreach ($_SESSION['auth_contexts'] as &$context) {
                if ((int)($context['user_id'] ?? 0) === $userId) {
                    $context['cart'] = [];
                }
            }
            unset($context);

            header('Location: ' . withAuthContext('checkout.php?success=' . $orderId));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Unable to place customer order: ' . $e->getMessage());
            $errors[] = 'Unable to place your order. No order was created; please try again.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container page-panel checkout-page mt-4 mb-4">
    <?php if ($successOrder): ?>
        <section class="checkout-success text-center py-4">
            <div class="checkout-success-icon" aria-hidden="true">&#10003;</div>
            <h1 class="mb-2">Order placed!</h1>
            <p class="mb-1">Thank you, <?php echo htmlspecialchars($successOrder['customer_name'] ?? $customerName, ENT_QUOTES, 'UTF-8'); ?>.</p>
            <p class="mb-4">Order #<?php echo (int)$successOrder['id']; ?> has been received and is now <strong><?php echo htmlspecialchars(ucfirst($successOrder['status']), ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
            <div class="checkout-success-summary text-start mx-auto mb-4">
                <?php foreach ($successItems as $item): ?>
                    <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                        <span><?php echo htmlspecialchars($item['product_name'] ?? 'Product', ENT_QUOTES, 'UTF-8'); ?> × <?php echo (int)$item['quantity']; ?></span>
                        <strong>₱<?php echo number_format((float)$item['price'] * (int)$item['quantity'], 2); ?></strong>
                    </div>
                <?php endforeach; ?>
                <div class="d-flex justify-content-between gap-3 pt-3">
                    <strong>Total</strong><strong>₱<?php echo number_format((float)$successOrder['total_amount'], 2); ?></strong>
                </div>
            </div>
            <div class="d-flex justify-content-center flex-wrap gap-2">
                <a href="<?php echo htmlspecialchars(withAuthContext('my_orders.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">View my orders</a>
                <a href="<?php echo htmlspecialchars(withAuthContext('products.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-light">Continue shopping</a>
            </div>
        </section>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h1 class="mb-1">Checkout</h1>
                <p class="mb-0">Enter your delivery details and review your order.</p>
            </div>
            <a href="<?php echo htmlspecialchars(withAuthContext('products.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-light">Back to products</a>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endforeach; ?>

        <?php if (!$summary['items']): ?>
            <div class="alert alert-info">Your cart is empty. <a href="<?php echo htmlspecialchars(withAuthContext('products.php'), ENT_QUOTES, 'UTF-8'); ?>">Browse products</a></div>
        <?php elseif ($schemaReady): ?>
            <form method="post" action="<?php echo htmlspecialchars(withAuthContext('checkout.php'), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo csrfInputField('checkout'); ?>
                <?php echo authContextInputField(); ?>
                <div class="row g-4 align-items-start">
                    <div class="col-lg-7">
                        <section class="card checkout-card h-100">
                            <div class="card-body p-4">
                                <h2 class="h4 mb-4">Customer details</h2>
                                <div class="mb-3">
                                    <label for="customer-name" class="form-label">Full name</label>
                                    <input id="customer-name" name="customer_name" class="form-control" value="<?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>" maxlength="100" autocomplete="name" required>
                                </div>
                                <div class="mb-3">
                                    <label for="delivery-address" class="form-label">Delivery address</label>
                                    <textarea id="delivery-address" name="delivery_address" class="form-control" rows="3" maxlength="2000" autocomplete="street-address" required><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    <div class="form-text">This address will be saved to your account for next time.</div>
                                </div>
                                <div class="mb-4">
                                    <label for="contact-number" class="form-label">Contact number</label>
                                    <input id="contact-number" name="contact_number" type="tel" class="form-control" value="<?php echo htmlspecialchars($contactNumber, ENT_QUOTES, 'UTF-8'); ?>" maxlength="30" autocomplete="tel" pattern="[0-9+(). -]{7,30}" required>
                                </div>
                                <fieldset>
                                    <legend class="form-label">Payment method</legend>
                                    <div class="row g-2">
                                        <div class="col-sm-6">
                                            <label class="checkout-payment-option">
                                                <input type="radio" name="payment_method" value="cash_on_delivery" <?php echo $paymentMethod === 'cash_on_delivery' ? 'checked' : ''; ?> required>
                                                <span>Cash on delivery</span>
                                            </label>
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="checkout-payment-option">
                                                <input type="radio" name="payment_method" value="credit_card" <?php echo $paymentMethod === 'credit_card' ? 'checked' : ''; ?> required>
                                                <span>Credit card</span>
                                            </label>
                                        </div>
                                    </div>
                                </fieldset>
                            </div>
                        </section>
                    </div>
                    <div class="col-lg-5">
                        <section class="card checkout-card">
                            <div class="card-body p-4">
                                <h2 class="h4 mb-4">Order summary</h2>
                                <?php foreach ($summary['items'] as $item): ?>
                                    <div class="checkout-summary-item">
                                        <div>
                                            <strong><?php echo htmlspecialchars($item['product']['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <div class="small text-light"><?php echo (int)$item['quantity']; ?> × ₱<?php echo number_format((float)$item['product']['price'], 2); ?></div>
                                        </div>
                                        <strong>₱<?php echo number_format((float)$item['subtotal'], 2); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                                <div class="checkout-total">
                                    <strong>Total</strong>
                                    <strong>₱<?php echo number_format((float)$summary['total'], 2); ?></strong>
                                </div>
                                <button type="submit" class="btn btn-success w-100 mt-3">Place order</button>
                            </div>
                        </section>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
