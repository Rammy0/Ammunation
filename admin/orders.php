<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_helpers.php';
requireLogin();
requireAdmin();

$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $currentUser = getUserById((int)$_SESSION['user_id']);
}

$errors = [];
$success = '';
$orderSchemaReady = false;
try {
    ensureOrderSchema($pdo);
    $orderSchemaReady = true;
} catch (Throwable $e) {
    error_log('Unable to prepare admin order management: ' . $e->getMessage());
    $errors[] = 'Order management is currently unavailable.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $orderSchemaReady) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $allowedStatuses = ['pending', 'approved', 'processing', 'shipped', 'completed', 'cancelled'];

    if (empty($_POST['csrf_token']) || !validateCsrfToken((string)$_POST['csrf_token'], 'request-status')) {
        $errors[] = 'Invalid or expired request. Please reload the page and try again.';
    } elseif ($requestId <= 0 || !in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Invalid request status.';
    } else {
        try {
            $stmt = $pdo->prepare('UPDATE requests SET status = :status WHERE id = :id');
            $stmt->execute(['status' => $status, 'id' => $requestId]);
            $success = 'Order status updated.';
        } catch (Throwable $e) {
            error_log('Unable to update order status: ' . $e->getMessage());
            $errors[] = 'Unable to update order status.';
        }
    }
}

try {
    if (!$orderSchemaReady) {
        throw new RuntimeException('Order management schema is unavailable.');
    }
    $stmt = $pdo->query(
        "SELECT r.id, r.user_id, r.customer_name, r.contact_number, r.payment_method,
                r.total_amount, r.delivery_address, r.status, r.created_at,
                u.uname, u.email AS customer_email,
                GROUP_CONCAT(CONCAT(COALESCE(ri.product_name, p.name, 'Product'), ' x', ri.quantity)
                             ORDER BY COALESCE(ri.product_name, p.name, 'Product') SEPARATOR ', ') AS items_summary
         FROM requests r
         LEFT JOIN users u ON u.id = r.user_id
         LEFT JOIN request_items ri ON ri.request_id = r.id
         LEFT JOIN products p ON p.id = ri.product_id
         GROUP BY r.id, r.user_id, r.customer_name, r.contact_number, r.payment_method,
                  r.total_amount, r.delivery_address, r.status, r.created_at, u.uname, u.email
         ORDER BY r.created_at DESC
         LIMIT 200"
    );
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $orders = [];
    error_log('Unable to load admin orders: ' . $e->getMessage());
    $errors[] = 'Unable to load orders. Confirm the order tables are available.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Orders - Ammunation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/site.css">
    <script src="../assets/js/ajax-nav.js?v=20261011-tabs" defer></script>
    <script src="../assets/js/auth-context.js" defer></script>
    <script src="../assets/css/bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js" defer></script>
</head>
<body class="with-sidebar" data-auth-context="<?php echo htmlspecialchars(getAuthContextToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <nav class="side-menu" aria-label="Admin sidebar">
        <div class="side-inner">
            <div class="sidebar-brand">
                <div class="sidebar-brand-name">Ammunation</div>
                <div class="sidebar-brand-tagline">Admin</div>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item mb-2"><a class="nav-link" href="admin.php">Dashboard</a></li>
                
                <!-- Products menu -->
                <li class="nav-item mb-2">
                    <span class="nav-link">Products</span>
                    <div id="productsMenu">
                        <ul class="nav flex-column ms-3 mt-2">
                            <li class="nav-item mb-1"><a class="nav-link nav-sublink" href="products.php?action=add">Add Product</a></li>
                            <li class="nav-item mb-1"><a class="nav-link nav-sublink" href="products.php">Manage Products</a></li>
                        </ul>
                    </div>
                </li>
                
                <li class="nav-item mb-2"><a class="nav-link" href="users.php">Manage Users</a></li>
                <li class="nav-item mb-2"><a class="nav-link" href="orders.php">Orders</a></li>
                <li class="nav-item mt-3"><a class="nav-link" href="../index.php">View site</a></li>
                <li class="nav-item"><a class="nav-link text-danger" href="../logout.php">Log out</a></li>
            </ul>

            <?php if (!empty($currentUser)): ?>
                <div class="mt-4 small text-muted">Signed in as<br><strong><?php echo htmlspecialchars($currentUser['uname'] ?? $currentUser['email'] ?? ''); ?></strong></div>
            <?php endif; ?>
            <br>
            <br>
            <br>
            <br>
            <br>
            <br>
            <br>
            <center><span>&copy; <?php echo date('Y'); ?> Ammunation</span></center>
        </div>
    </nav>
<main id="main-content" class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1>ORDERS</h1>
            <p class="mb-0 text-muted">View and manage orders.</p>
        </div>
        <div>
            <a href="admin.php" class="btn btn-outline-light me-2">Dashboard</a>
            <a href="../logout.php" class="btn btn-outline-danger">Log out</a>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="card">
        <?php if (empty($orders)): ?>
            <div class="card-body">
                <div class="alert alert-info mb-0">No orders found.</div>
            </div>
        <?php else: ?>
            <div class="card-body">
                <h4 class="mb-3">Recent orders</h4>
                <div class="table-responsive">
                    <table class="table table-striped table-sm align-middle">
                        <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Contact / payment</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Delivery address</th>
                            <th>Status</th>
                            <th>Placed</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($o['id'] ?? ''); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($o['customer_name'] ?: ($o['uname'] ?? $o['customer_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <div class="small"><?php echo htmlspecialchars($o['customer_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($o['contact_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                    <div class="small"><?php echo ($o['payment_method'] ?? '') === 'credit_card' ? 'Credit card' : 'Cash on delivery'; ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($o['items_summary'] ?? ''); ?></td>
                                <td>₱<?php echo number_format((float)($o['total_amount'] ?? 0), 2); ?></td>
                                <td><?php echo htmlspecialchars($o['delivery_address'] ?? ''); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($o['status'] ?? ''); ?></span></td>
                                <td><?php echo htmlspecialchars($o['created_at'] ?? ''); ?></td>
                                <td>
                                    <form method="post" data-ajax="true" class="d-flex gap-1">
                                        <?php echo csrfInputField('request-status'); ?>
                                        <input type="hidden" name="request_id" value="<?php echo (int)$o['id']; ?>">
                                        <select name="status" class="form-select form-select-sm">
                                            <?php foreach (['pending', 'approved', 'processing', 'shipped', 'completed', 'cancelled'] as $status): ?>
                                                <option value="<?php echo $status; ?>" <?php echo ($status === ($o['status'] ?? '')) ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
