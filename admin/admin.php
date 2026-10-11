<?php
require_once __DIR__ . '/../includes/auth.php';
// require_once __DIR__ . '/../includes/header.php';
requireLogin();
requireAdmin();
$user = getUserById((int)$_SESSION['user_id']);

$stats = [
    'products' => 0,
    'users' => 0,
    'admins' => 0,
    'orders' => 0,
    'revenue' => 0.0,
];
$categoryBreakdown = [];
$statusBreakdown = [
    'pending' => 0,
    'approved' => 0,
    'completed' => 0,
    'cancelled' => 0,
];

try {
    $stats['products'] = (int)($pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
} catch (Throwable $e) {}

try {
    $stats['users'] = (int)($pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    $stats['admins'] = (int)($pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn());
} catch (Throwable $e) {}

try {
    $stats['orders'] = (int)($pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn());
    $revenueRow = $pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM requests')->fetchColumn();
    $stats['revenue'] = (float)$revenueRow;

    $statusStmt = $pdo->query('SELECT status, COUNT(*) AS total FROM requests GROUP BY status');
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = strtolower((string)($row['status'] ?? ''));
        if (isset($statusBreakdown[$status])) {
            $statusBreakdown[$status] = (int)$row['total'];
        }
    }
} catch (Throwable $e) {}

try {
    $categoryStmt = $pdo->query('SELECT c.name, COUNT(p.id) AS product_count FROM categories c LEFT JOIN products p ON p.category_id = c.id GROUP BY c.id, c.name ORDER BY product_count DESC, c.name ASC LIMIT 5');
    $categoryBreakdown = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$maxCategoryCount = 0;
foreach ($categoryBreakdown as $entry) {
    $maxCategoryCount = max($maxCategoryCount, (int)($entry['product_count'] ?? 0));
}
$maxCategoryCount = $maxCategoryCount > 0 ? $maxCategoryCount : 1;
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

            <?php if (!empty($user)): ?>
                <div class="mt-4 small text-muted">Signed in as<br><strong><?php echo htmlspecialchars($user['name'] ?? $user['email'] ?? ''); ?></strong></div>
            <?php endif; ?>
            <br>
            <br>
            <br>
            <br>
            <br>
            <center><span>&copy; <?php echo date('Y'); ?> Ammunation</span></center>
        </div>
    </nav>

    <main id="main-content" class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1>ADMIN DASHBOARD</h1>
                <p class="mb-0 text-muted">Welcome, <?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?>.</p>
            </div>
            <div>
                <a href="../index.php" class="btn btn-outline-light">Site</a>
                <a href="../logout.php" class="btn btn-outline-danger">Log out</a>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="admin-stat-card">
                    <div class="label">Products</div>
                    <div class="value"><?php echo number_format($stats['products']); ?></div>
                    <div class="trend positive">Live catalog</div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="admin-stat-card">
                    <div class="label">Customers</div>
                    <div class="value"><?php echo number_format($stats['users']); ?></div>
                    <div class="trend">Active accounts</div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="admin-stat-card">
                    <div class="label">Admins</div>
                    <div class="value"><?php echo number_format($stats['admins']); ?></div>
                    <div class="trend">Team access</div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="admin-stat-card">
                    <div class="label">Orders</div>
                    <div class="value"><?php echo number_format($stats['orders']); ?></div>
                    <div class="trend positive">Requests tracked</div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <div class="card admin-panel-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="mb-0">Category distribution</h4>
                            <span class="badge bg-secondary">Top 5</span>
                        </div>
                        <?php if (empty($categoryBreakdown)): ?>
                            <div class="alert alert-info mb-0">No category stats available yet.</div>
                        <?php else: ?>
                            <?php foreach ($categoryBreakdown as $category): ?>
                                <?php $percent = ((int)$category['product_count'] / $maxCategoryCount) * 100; ?>
                                <div class="chart-row">
                                    <div class="chart-label"><?php echo htmlspecialchars($category['name'] ?? 'Category'); ?></div>
                                    <div class="chart-bar-track">
                                        <div class="chart-bar" style="width: <?php echo $percent; ?>%;"></div>
                                    </div>
                                    <div class="chart-value"><?php echo (int)($category['product_count'] ?? 0); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="card admin-panel-card h-100">
                    <div class="card-body">
                        <h4 class="mb-3">Order status</h4>
                        <?php foreach ($statusBreakdown as $status => $count): ?>
                            <?php $statusTotal = max(1, $stats['orders']); ?>
                            <div class="chart-row compact">
                                <div class="chart-label text-capitalize"><?php echo htmlspecialchars($status); ?></div>
                                <div class="chart-bar-track">
                                    <div class="chart-bar status-<?php echo htmlspecialchars($status); ?>" style="width: <?php echo ($count / $statusTotal) * 100; ?>%;"></div>
                                </div>
                                <div class="chart-value"><?php echo $count; ?></div>
                            </div>
                        <?php endforeach; ?>
                        <div class="mt-4 pt-3 border-top border-secondary-subtle">
                            <div class="text-muted small">Revenue</div>
                            <div class="fs-3 fw-bold">₱<?php echo number_format($stats['revenue'], 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
