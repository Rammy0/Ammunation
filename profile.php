<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/store_helpers.php';
requireLogin();
if (isAdmin()) {
    header('Location: ' . withAuthContext('admin/admin.php'));
    exit;
}

loadUserStoreCart((int)$_SESSION['user_id']);
$userId = (int)($_SESSION['user_id'] ?? 0);
$user = getUserById($userId);
$address = '';
$contactNumber = '';
$addressErrors = [];
$profileSaved = false;
$addressSchemaReady = false;

try {
    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'delivery_address'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        $pdo->exec('ALTER TABLE users ADD COLUMN delivery_address TEXT NULL');
    }
    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'contact_number'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        $pdo->exec('ALTER TABLE users ADD COLUMN contact_number VARCHAR(30) NULL');
    }

    $stmt = $pdo->prepare('SELECT delivery_address, contact_number FROM users WHERE id = :id AND is_admin = 0 LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $profileDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    $address = (string)($profileDetails['delivery_address'] ?? '');
    $contactNumber = (string)($profileDetails['contact_number'] ?? '');
    $addressSchemaReady = true;
} catch (Throwable $e) {
    error_log('Unable to load customer profile details: ' . $e->getMessage());
    $addressErrors[] = 'Your saved profile details are currently unavailable. Please try again later.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $addressSchemaReady) {
    $address = trim((string)($_POST['delivery_address'] ?? ''));
    $contactNumber = trim((string)($_POST['contact_number'] ?? ''));
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'profile-address')) {
        http_response_code(400);
        $addressErrors[] = 'This form expired. Reload the page and try again.';
    } elseif (strlen($address) > 2000) {
        $addressErrors[] = 'The address must be 2,000 characters or fewer.';
    } elseif ($contactNumber !== '' && (strlen($contactNumber) > 30 || preg_match('/^[0-9+().\s-]{7,30}$/', $contactNumber) !== 1)) {
        $addressErrors[] = 'Enter a valid contact number (7 to 30 characters).';
    } else {
        try {
            $stmt = $pdo->prepare(
                'UPDATE users SET delivery_address = :address, contact_number = :contact_number
                 WHERE id = :id AND is_admin = 0'
            );
            $stmt->execute([
                'address' => $address !== '' ? $address : null,
                'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                'id' => $userId,
            ]);
            $profileSaved = true;
        } catch (Throwable $e) {
            error_log('Unable to save customer profile details: ' . $e->getMessage());
            $addressErrors[] = 'Unable to save your profile details. Please try again.';
        }
    }
}

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
    <p class="small text mb-4">
        <a href="login.php?new_context=1" target="_blank" rel="noopener">Sign in to another account in a new tab</a>
    </p>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h2 class="h4">Account overview</h2>
                    <p class="mb-2"><strong>Name:</strong> <?php echo htmlspecialchars($user['uname'] ?? ''); ?></p>
                    <p class="mb-2"><strong>Email:</strong> <?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
                    <p class="mb-2"><strong>Contact number:</strong>
                        <?php echo htmlspecialchars($contactNumber !== '' ? $contactNumber : 'No contact number saved yet.', ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <p class="mb-3"><strong>Member since:</strong> <?php echo htmlspecialchars($user['created_at'] ?? ''); ?></p>
                    <p class="mb-3"><strong>Delivery address:</strong>
                        <?php echo htmlspecialchars($address !== '' ? $address : 'No address saved yet.', ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <a href="#cartDrawer" data-bs-toggle="offcanvas" aria-controls="cartDrawer" class="btn btn-primary">View cart (<?php echo $cartSummary['count']; ?>)</a>
                    <a href="<?php echo htmlspecialchars(withAuthContext('my_orders.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-light ms-2">My orders</a>
                    <hr>
                    <h3 class="h5">Update delivery details</h3>
                    <p class="text">Save your address and contact number for checkout.</p>
                    <?php foreach ($addressErrors as $error): ?>
                        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endforeach; ?>
                    <?php if ($profileSaved): ?>
                        <div class="alert alert-success" role="status">Your delivery details have been saved.</div>
                    <?php endif; ?>
                    <?php if ($addressSchemaReady): ?>
                        <form method="post" action="<?php echo htmlspecialchars(withAuthContext('profile.php'), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo csrfInputField('profile-address'); ?>
                            <?php echo authContextInputField(); ?>
                            <div class="mb-3">
                                <label for="delivery-address" class="form-label">Address</label>
                                <textarea
                                    id="delivery-address"
                                    name="delivery_address"
                                    class="form-control"
                                    rows="3"
                                    maxlength="2000"
                                    autocomplete="street-address"
                                ><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="contact-number" class="form-label">Contact number</label>
                                <input
                                    id="contact-number"
                                    name="contact_number"
                                    type="tel"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($contactNumber, ENT_QUOTES, 'UTF-8'); ?>"
                                    maxlength="30"
                                    autocomplete="tel"
                                    pattern="[0-9+(). -]{7,30}"
                                >
                            </div>
                            <button type="submit" class="btn btn-primary">Save details</button>
                        </form>
                    <?php endif; ?>
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