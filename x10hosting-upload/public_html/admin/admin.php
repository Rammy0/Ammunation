<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
requireAdmin();

$user = getUserById((int)$_SESSION['user_id']);
$overview = [
    'products' => 0,
    'customers' => 0,
    'orders' => 0,
    'revenue' => 0.0,
    'pending_orders' => 0,
    'inventory_units' => 0,
    'low_stock_products' => 0,
    'out_of_stock_products' => 0,
];

try {
    $overview['products'] = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $overview['customers'] = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 0')->fetchColumn();
    $overview['orders'] = (int)$pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn();
    $overview['pending_orders'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM requests WHERE status = 'pending'"
    )->fetchColumn();
    $overview['revenue'] = (float)$pdo->query(
        "SELECT COALESCE(SUM(total_amount), 0) FROM requests WHERE status = 'completed'"
    )->fetchColumn();

    $inventory = $pdo->query(
        'SELECT COALESCE(SUM(stock), 0) AS units,
                COALESCE(SUM(CASE WHEN stock <= 5 THEN 1 ELSE 0 END), 0) AS low_stock,
                COALESCE(SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock
         FROM products'
    )->fetch(PDO::FETCH_ASSOC);
    $overview['inventory_units'] = (int)($inventory['units'] ?? 0);
    $overview['low_stock_products'] = (int)($inventory['low_stock'] ?? 0);
    $overview['out_of_stock_products'] = (int)($inventory['out_of_stock'] ?? 0);
} catch (Throwable $e) {
    error_log('Unable to load admin dashboard overview: ' . $e->getMessage());
    $overviewError = 'Some overview information is currently unavailable.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard - Ammunation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/site.css">
    <link rel="stylesheet" href="../assets/css/logout-confirmation.css">
    <script src="../assets/js/auth-context.js" defer></script>
    <script src="../assets/js/admin-idle-timeout.js" defer></script>
    <script src="../assets/js/logout-confirmation.js" defer></script>
</head>
<body class="with-sidebar" data-auth-context="<?php echo htmlspecialchars(getAuthContextToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <main id="main-content" class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1>ADMIN DASHBOARD</h1>
                <p class="mb-0 text-muted">Welcome, <?php echo htmlspecialchars($user['uname'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?>.</p>
            </div>
            <a href="../logout.php" class="btn btn-outline-danger">Log out</a>
        </div>

        <?php if (isset($overviewError)): ?>
            <div class="alert alert-warning" role="status"><?php echo htmlspecialchars($overviewError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-sm-6 col-xl-3">
                <div class="admin-stat-card">
                    <div class="label">Products</div>
                    <div class="value"><?php echo number_format($overview['products']); ?></div>
                    <div class="trend">Catalog total</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="admin-stat-card">
                    <div class="label">Customers</div>
                    <div class="value"><?php echo number_format($overview['customers']); ?></div>
                    <div class="trend">Registered accounts</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="admin-stat-card">
                    <div class="label">Orders</div>
                    <div class="value"><?php echo number_format($overview['orders']); ?></div>
                    <div class="trend">All order statuses</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="admin-stat-card">
                    <div class="label">Completed revenue</div>
                    <div class="value">₱<?php echo number_format($overview['revenue'], 2); ?></div>
                    <div class="trend">Completed orders</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="admin-stat-card">
                    <div class="label">Pending orders</div>
                    <div class="value"><?php echo number_format($overview['pending_orders']); ?></div>
                    <div class="trend">Awaiting action</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="admin-stat-card">
                    <div class="label">Units in stock</div>
                    <div class="value"><?php echo number_format($overview['inventory_units']); ?></div>
                    <div class="trend">Across all products</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="admin-stat-card">
                    <div class="label">Low-stock products</div>
                    <div class="value"><?php echo number_format($overview['low_stock_products']); ?></div>
                    <div class="trend">5 or fewer units</div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="admin-stat-card">
                    <div class="label">Out of stock</div>
                    <div class="value"><?php echo number_format($overview['out_of_stock_products']); ?></div>
                    <div class="trend">Products with no units</div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-2">
            <div class="col-md-4">
                <a class="btn btn-outline-light w-100 py-3" href="orders.php">Manage orders</a>
            </div>
            <div class="col-md-4">
                <a class="btn btn-outline-light w-100 py-3" href="products.php">Manage products</a>
            </div>
            <div class="col-md-4">
                <a class="btn btn-outline-light w-100 py-3" href="reports.php">View reports</a>
            </div>
        </div>
    </main>
    <script src="../assets/css/bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
