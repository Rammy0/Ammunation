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
    'inventory_units' => 0,
    'low_stock_products' => 0,
    'out_of_stock_products' => 0,
];
$reportErrors = [];
$inventoryBreakdown = [];
$lowStockProducts = [];
$inventoryReportAvailable = false;
$monthlySales = [];
$monthlySalesAvailable = false;
$bestSellingProducts = [];
$bestSellingTotalUnits = 0;
$bestSellingAvailable = false;
$bestSellingColors = ['#d9b96a', '#80d4b0', '#7a9ed6', '#d98b6f', '#ad80c2', '#69737f'];
$reportStartMonth = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$reportStartDate = $reportStartMonth->format('Y-m-d');
$lowStockThreshold = 5;
$statusBreakdown = [
    'pending' => 0,
    'approved' => 0,
    'completed' => 0,
    'cancelled' => 0,
];

try {
    $stats['products'] = (int)($pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
    $inventoryRow = $pdo->query(
        'SELECT COALESCE(SUM(stock), 0) AS inventory_units,
                COALESCE(SUM(CASE WHEN stock <= ' . $lowStockThreshold . ' THEN 1 ELSE 0 END), 0) AS low_stock_products,
                COALESCE(SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_products
         FROM products'
    )->fetch(PDO::FETCH_ASSOC);
    $stats['inventory_units'] = (int)($inventoryRow['inventory_units'] ?? 0);
    $stats['low_stock_products'] = (int)($inventoryRow['low_stock_products'] ?? 0);
    $stats['out_of_stock_products'] = (int)($inventoryRow['out_of_stock_products'] ?? 0);

    $inventoryStmt = $pdo->query(
        "SELECT COALESCE(c.name, 'Uncategorized') AS name,
                COALESCE(SUM(p.stock), 0) AS stock_units
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         GROUP BY c.id, c.name
         ORDER BY stock_units DESC, name ASC
         LIMIT 5"
    );
    $inventoryBreakdown = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);

    $lowStockStmt = $pdo->prepare(
        'SELECT id, name, stock
         FROM products
         WHERE stock <= :threshold
         ORDER BY stock ASC, name ASC
         LIMIT 8'
    );
    $lowStockStmt->execute(['threshold' => $lowStockThreshold]);
    $lowStockProducts = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);
    $inventoryReportAvailable = true;
} catch (Throwable $e) {
    error_log('Unable to load admin inventory report: ' . $e->getMessage());
    $reportErrors[] = 'Inventory reports are currently unavailable.';
}

try {
    $stats['users'] = (int)($pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    $stats['admins'] = (int)($pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn());
} catch (Throwable $e) {
    error_log('Unable to load admin user summary: ' . $e->getMessage());
}

try {
    $stats['orders'] = (int)($pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn());
    $revenueRow = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM requests WHERE status = 'completed'")->fetchColumn();
    $stats['revenue'] = (float)$revenueRow;

    $statusStmt = $pdo->query('SELECT status, COUNT(*) AS total FROM requests GROUP BY status');
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = strtolower((string)($row['status'] ?? ''));
        if (isset($statusBreakdown[$status])) {
            $statusBreakdown[$status] = (int)$row['total'];
        }
    }
} catch (Throwable $e) {
    error_log('Unable to load admin order summary: ' . $e->getMessage());
    $reportErrors[] = 'Sales and revenue reports are currently unavailable.';
}

try {
    $monthlySales = [];
    for ($monthOffset = 0; $monthOffset < 6; $monthOffset++) {
        $month = $reportStartMonth->modify('+' . $monthOffset . ' months');
        $period = $month->format('Y-m');
        $monthlySales[$period] = [
            'label' => $month->format('M Y'),
            'orders' => 0,
            'units' => 0,
            'revenue' => 0.0,
        ];
    }

    $salesStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS period,
                COUNT(*) AS order_count,
                COALESCE(SUM(total_amount), 0) AS revenue
         FROM requests
         WHERE status = 'completed' AND created_at >= :start_date
         GROUP BY period"
    );
    $salesStmt->execute(['start_date' => $reportStartDate]);
    foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $period = (string)$row['period'];
        if (isset($monthlySales[$period])) {
            $monthlySales[$period]['orders'] = (int)$row['order_count'];
            $monthlySales[$period]['revenue'] = (float)$row['revenue'];
        }
    }

    $unitsStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(r.created_at, '%Y-%m') AS period,
                COALESCE(SUM(ri.quantity), 0) AS units
         FROM requests r
         INNER JOIN request_items ri ON ri.request_id = r.id
         WHERE r.status = 'completed' AND r.created_at >= :start_date
         GROUP BY period"
    );
    $unitsStmt->execute(['start_date' => $reportStartDate]);
    foreach ($unitsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $period = (string)$row['period'];
        if (isset($monthlySales[$period])) {
            $monthlySales[$period]['units'] = (int)$row['units'];
        }
    }
    $monthlySalesAvailable = true;
} catch (Throwable $e) {
    error_log('Unable to load admin sales report: ' . $e->getMessage());
    $reportErrors[] = 'The monthly sales chart is currently unavailable.';
}

$bestSellingSegments = [];
$pieGradientStops = [];
try {
    $totalUnitsStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(ri.quantity), 0)
         FROM request_items ri
         INNER JOIN requests r ON r.id = ri.request_id
         WHERE r.status = 'completed' AND r.created_at >= :start_date"
    );
    $totalUnitsStmt->execute(['start_date' => $reportStartDate]);
    $bestSellingTotalUnits = (int)$totalUnitsStmt->fetchColumn();

    $bestSellingStmt = $pdo->prepare(
        "SELECT COALESCE(MAX(p.name), MAX(ri.product_name), 'Product') AS name,
                COALESCE(SUM(ri.quantity), 0) AS units
         FROM request_items ri
         INNER JOIN requests r ON r.id = ri.request_id
         LEFT JOIN products p ON p.id = ri.product_id
         WHERE r.status = 'completed' AND r.created_at >= :start_date
         GROUP BY ri.product_id
         ORDER BY units DESC, name ASC
         LIMIT 5"
    );
    $bestSellingStmt->execute(['start_date' => $reportStartDate]);
    $bestSellingProducts = $bestSellingStmt->fetchAll(PDO::FETCH_ASSOC);
    $bestSellingAvailable = true;
} catch (Throwable $e) {
    error_log('Unable to load admin best-selling products report: ' . $e->getMessage());
    $reportErrors[] = 'The best-selling products report is currently unavailable.';
}

$pieAngle = 0.0;
$topProductUnits = 0;
foreach ($bestSellingProducts as $index => $product) {
    $units = (int)$product['units'];
    $topProductUnits += $units;
    $bestSellingSegments[] = ['units' => $units, 'color' => $bestSellingColors[$index]];
}
$otherProductUnits = max(0, $bestSellingTotalUnits - $topProductUnits);
if ($otherProductUnits > 0) {
    $bestSellingSegments[] = ['units' => $otherProductUnits, 'color' => $bestSellingColors[5]];
}
if ($bestSellingTotalUnits > 0) {
    foreach ($bestSellingSegments as $index => $segment) {
        $nextAngle = $index === array_key_last($bestSellingSegments)
            ? 360
            : $pieAngle + ((int)$segment['units'] / $bestSellingTotalUnits) * 360;
        $pieGradientStops[] = $segment['color'] . ' ' . number_format($pieAngle, 2, '.', '') . 'deg ' . number_format($nextAngle, 2, '.', '') . 'deg';
        $pieAngle = $nextAngle;
    }
}
$bestSellingPieStyle = $pieGradientStops
    ? 'background: conic-gradient(from -90deg, ' . implode(', ', $pieGradientStops) . ');'
    : '';

$maxInventoryUnits = 0;
$maxMonthlyRevenue = 0.0;
foreach ($inventoryBreakdown as $entry) {
    $maxInventoryUnits = max($maxInventoryUnits, (int)($entry['stock_units'] ?? 0));
}
foreach ($monthlySales as $month) {
    $maxMonthlyRevenue = max($maxMonthlyRevenue, (float)$month['revenue']);
}
$maxInventoryUnits = max(1, $maxInventoryUnits);
$maxMonthlyRevenue = max(1, $maxMonthlyRevenue);
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
    <script src="../assets/js/jquery-4.0.0.min.js" defer></script>
    <script src="../assets/js/admin-dashboard.js" defer></script>
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
        <?php foreach (array_unique($reportErrors) as $reportError): ?>
            <div class="alert alert-warning" role="status"><?php echo htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endforeach; ?>
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
            <div class="col-12">
                <div class="card admin-panel-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div>
                                <h4 class="mb-1">Sales &amp; revenue</h4>
                                <div class="text-muted small">Completed orders · last 6 calendar months</div>
                            </div>
                            <div class="text-end">
                                <div class="text-muted small">Completed revenue</div>
                                <div class="fs-4 fw-bold">₱<?php echo number_format($stats['revenue'], 2); ?></div>
                            </div>
                        </div>
                        <?php if (!$monthlySalesAvailable): ?>
                            <div class="alert alert-warning mb-0">The monthly sales chart is currently unavailable.</div>
                        <?php else: ?>
                            <div class="sales-chart" role="group" aria-label="Completed monthly revenue and units sold for the last six calendar months">
                                <?php foreach ($monthlySales as $month): ?>
                                    <?php $revenuePercent = ((float)$month['revenue'] / $maxMonthlyRevenue) * 100; ?>
                                    <div class="sales-chart-row">
                                        <div class="chart-label"><?php echo htmlspecialchars($month['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="sales-chart-track" aria-hidden="true">
                                            <span class="sales-chart-bar admin-report-bar" data-chart-width="<?php echo $revenuePercent; ?>" style="width: <?php echo $revenuePercent; ?>%;"></span>
                                        </div>
                                        <div class="sales-chart-values">
                                            <strong>₱<?php echo number_format((float)$month['revenue'], 2); ?></strong>
                                            <span><?php echo number_format((int)$month['units']); ?> units · <?php echo number_format((int)$month['orders']); ?> orders</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-xl-7">
                <div class="card admin-panel-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="mb-0">Inventory by category</h4>
                            <span class="badge bg-secondary">Stock units · Top 5</span>
                        </div>
                        <?php if (!$inventoryReportAvailable): ?>
                            <div class="alert alert-warning mb-0">Inventory reports are currently unavailable.</div>
                        <?php elseif (empty($inventoryBreakdown)): ?>
                            <div class="alert alert-info mb-0">No inventory data available yet.</div>
                        <?php else: ?>
                            <?php foreach ($inventoryBreakdown as $category): ?>
                                <?php $percent = ((int)$category['stock_units'] / $maxInventoryUnits) * 100; ?>
                                <div class="chart-row">
                                    <div class="chart-label"><?php echo htmlspecialchars($category['name'] ?? 'Category', ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="chart-bar-track">
                                        <div class="chart-bar admin-report-bar" data-chart-width="<?php echo $percent; ?>" style="width: <?php echo $percent; ?>%;"></div>
                                    </div>
                                    <div class="chart-value"><?php echo number_format((int)($category['stock_units'] ?? 0)); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if ($inventoryReportAvailable): ?>
                            <div class="inventory-summary mt-4 pt-3 border-top border-secondary-subtle">
                                <div><span>Units in stock</span><strong><?php echo number_format($stats['inventory_units']); ?></strong></div>
                                <div><span>Low stock (≤ <?php echo $lowStockThreshold; ?>)</span><strong><?php echo number_format($stats['low_stock_products']); ?></strong></div>
                                <div><span>Out of stock</span><strong><?php echo number_format($stats['out_of_stock_products']); ?></strong></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="card admin-panel-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="mb-0">Stock alerts</h4>
                            <span class="badge bg-secondary">≤ <?php echo $lowStockThreshold; ?> units</span>
                        </div>
                        <?php if (!$inventoryReportAvailable): ?>
                            <div class="alert alert-warning mb-0">Stock alerts are currently unavailable.</div>
                        <?php elseif (empty($lowStockProducts)): ?>
                            <div class="alert alert-success mb-0">No low-stock products.</div>
                        <?php else: ?>
                            <div class="low-stock-list">
                                <?php foreach ($lowStockProducts as $product): ?>
                                    <div class="low-stock-item">
                                        <a href="product_edit.php?id=<?php echo (int)$product['id']; ?>"><?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                                        <span class="badge <?php echo (int)$product['stock'] === 0 ? 'bg-danger' : 'bg-warning text-dark'; ?>">
                                            <?php echo (int)$product['stock'] === 0 ? 'Out of stock' : number_format((int)$product['stock']) . ' left'; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($stats['low_stock_products'] > count($lowStockProducts)): ?>
                                <a class="btn btn-outline-light btn-sm mt-3" href="products.php">Manage all low-stock products</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="card admin-panel-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div>
                                <h4 class="mb-1">Best-selling products</h4>
                                <div class="text-muted small">Units sold · last 6 calendar months</div>
                            </div>
                            <span class="badge bg-secondary">Completed orders</span>
                        </div>
                        <?php if (!$bestSellingAvailable): ?>
                            <div class="alert alert-warning mb-0">The best-selling products report is currently unavailable.</div>
                        <?php elseif ($bestSellingTotalUnits === 0): ?>
                            <div class="alert alert-info mb-0">No completed product sales in this period yet.</div>
                        <?php else: ?>
                            <div class="best-seller-chart">
                                <div
                                    class="best-seller-pie"
                                    role="img"
                                    aria-label="Pie chart showing the share of units sold by the top five products and other products"
                                    data-pie-segments="<?php echo htmlspecialchars(json_encode($bestSellingSegments, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8'); ?>"
                                    style="<?php echo htmlspecialchars($bestSellingPieStyle, ENT_QUOTES, 'UTF-8'); ?>"
                                ></div>
                                <ul class="best-seller-legend list-unstyled mb-0">
                                    <?php foreach ($bestSellingProducts as $index => $product): ?>
                                        <?php $units = (int)$product['units']; ?>
                                        <li class="best-seller-legend-item">
                                            <span class="best-seller-swatch" style="--pie-color: <?php echo htmlspecialchars($bestSellingColors[$index], ENT_QUOTES, 'UTF-8'); ?>;"></span>
                                            <span class="best-seller-name" title="<?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <strong><?php echo number_format($units); ?></strong>
                                            <span class="best-seller-share"><?php echo number_format(($units / $bestSellingTotalUnits) * 100, 1); ?>%</span>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if ($otherProductUnits > 0): ?>
                                        <li class="best-seller-legend-item">
                                            <span class="best-seller-swatch" style="--pie-color: <?php echo htmlspecialchars($bestSellingColors[5], ENT_QUOTES, 'UTF-8'); ?>;"></span>
                                            <span class="best-seller-name">Other products</span>
                                            <strong><?php echo number_format($otherProductUnits); ?></strong>
                                            <span class="best-seller-share"><?php echo number_format(($otherProductUnits / $bestSellingTotalUnits) * 100, 1); ?>%</span>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
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
                            <div class="text-muted small">Orders tracked</div>
                            <div class="fs-3 fw-bold"><?php echo number_format($stats['orders']); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
