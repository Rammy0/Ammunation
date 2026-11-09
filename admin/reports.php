<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
requireAdmin();

$reportErrors = [];
$stats = [
    'orders' => 0,
    'revenue' => 0.0,
    'customers' => 0,
    'products' => 0,
    'stock_units' => 0,
];
$months = [];
$statuses = [];
$inventoryByCategory = [];
$bestSellers = [];
$reportStart = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$startDate = $reportStart->format('Y-m-d');
$chartColors = ['#d9b96a', '#80d4b0', '#7a9ed6', '#d98b6f', '#ad80c2', '#69737f'];
$statusColors = [
    'pending' => '#f0b24d',
    'approved' => '#7fbf6a',
    'processing' => '#7a9ed6',
    'shipped' => '#ad80c2',
    'completed' => '#2f8d6b',
    'cancelled' => '#b45a5f',
];

try {
    $stats['orders'] = (int)$pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn();
    $stats['revenue'] = (float)$pdo->query(
        "SELECT COALESCE(SUM(total_amount), 0) FROM requests WHERE status = 'completed'"
    )->fetchColumn();
} catch (Throwable $e) {
    error_log('Unable to load admin order report: ' . $e->getMessage());
    $reportErrors[] = 'Order and revenue data are currently unavailable.';
}

try {
    $stats['customers'] = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 0')->fetchColumn();
    $stats['products'] = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $stats['stock_units'] = (int)$pdo->query('SELECT COALESCE(SUM(stock), 0) FROM products')->fetchColumn();

    $inventoryStmt = $pdo->query(
        "SELECT COALESCE(c.name, 'Uncategorized') AS name, COALESCE(SUM(p.stock), 0) AS units
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         GROUP BY c.id, c.name
         ORDER BY units DESC, name ASC
         LIMIT 8"
    );
    $inventoryByCategory = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Unable to load admin inventory report: ' . $e->getMessage());
    $reportErrors[] = 'Inventory data are currently unavailable.';
}

for ($offset = 0; $offset < 6; $offset++) {
    $month = $reportStart->modify('+' . $offset . ' months');
    $key = $month->format('Y-m');
    $months[$key] = [
        'label' => $month->format('M Y'),
        'revenue' => 0.0,
        'orders' => 0,
        'units' => 0,
    ];
}

try {
    $monthlyStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS period,
                COUNT(*) AS order_count,
                COALESCE(SUM(total_amount), 0) AS revenue
         FROM requests
         WHERE status = 'completed' AND created_at >= :start_date
         GROUP BY period"
    );
    $monthlyStmt->execute(['start_date' => $startDate]);
    foreach ($monthlyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $period = (string)$row['period'];
        if (isset($months[$period])) {
            $months[$period]['revenue'] = (float)$row['revenue'];
            $months[$period]['orders'] = (int)$row['order_count'];
        }
    }

    $monthlyUnitsStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(r.created_at, '%Y-%m') AS period,
                COALESCE(SUM(ri.quantity), 0) AS units
         FROM requests r
         INNER JOIN request_items ri ON ri.request_id = r.id
         WHERE r.status = 'completed' AND r.created_at >= :start_date
         GROUP BY period"
    );
    $monthlyUnitsStmt->execute(['start_date' => $startDate]);
    foreach ($monthlyUnitsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $period = (string)$row['period'];
        if (isset($months[$period])) {
            $months[$period]['units'] = (int)$row['units'];
        }
    }
} catch (Throwable $e) {
    error_log('Unable to load admin monthly sales report: ' . $e->getMessage());
    $reportErrors[] = 'Monthly sales data are currently unavailable.';
}

try {
    $statuses = $pdo->query(
        'SELECT status, COUNT(*) AS total FROM requests GROUP BY status ORDER BY total DESC, status ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $bestSellerStmt = $pdo->prepare(
        "SELECT COALESCE(MAX(p.name), MAX(ri.product_name), 'Product') AS name,
                COALESCE(SUM(ri.quantity), 0) AS units
         FROM request_items ri
         INNER JOIN requests r ON r.id = ri.request_id
         LEFT JOIN products p ON p.id = ri.product_id
         WHERE r.status = 'completed' AND r.created_at >= :start_date
         GROUP BY ri.product_id
         ORDER BY units DESC, name ASC
         LIMIT 6"
    );
    $bestSellerStmt->execute(['start_date' => $startDate]);
    $bestSellers = $bestSellerStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Unable to load admin order breakdown report: ' . $e->getMessage());
    $reportErrors[] = 'Order breakdown data are currently unavailable.';
}

$maxRevenue = max(1, ...array_column($months, 'revenue'));
$maxInventory = max(1, ...array_map(static function (array $row): int {
    return (int)$row['units'];
}, $inventoryByCategory));
$maxBestSellerUnits = max(1, ...array_map(static function (array $row): int {
    return (int)$row['units'];
}, $bestSellers));
$statusTotal = array_sum(array_map(static function (array $row): int {
    return (int)$row['total'];
}, $statuses));
$statusStops = [];
$statusAngle = 0.0;
foreach ($statuses as $index => $row) {
    $status = strtolower((string)$row['status']);
    $color = $statusColors[$status] ?? $chartColors[$index % count($chartColors)];
    $nextAngle = $statusTotal > 0
        ? $statusAngle + ((int)$row['total'] / $statusTotal) * 360
        : 0;
    $statusStops[] = $color . ' ' . number_format($statusAngle, 2, '.', '') . 'deg ' .
        number_format($nextAngle, 2, '.', '') . 'deg';
    $statusAngle = $nextAngle;
}
$statusDonut = $statusStops
    ? 'background: conic-gradient(from -90deg, ' . implode(', ', $statusStops) . ');'
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports - Ammunation Admin</title>
    <link rel="stylesheet" href="../assets/css/bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/site.css">
    <script src="../assets/js/auth-context.js" defer></script>
    <script src="../assets/js/admin-idle-timeout.js" defer></script>
</head>
<body class="with-sidebar" data-auth-context="<?php echo htmlspecialchars(getAuthContextToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <main id="main-content" class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1>REPORTS</h1>
                <p class="mb-0 text-muted">Sales, orders, and inventory · Last six calendar months</p>
            </div>
            <a href="admin.php" class="btn btn-outline-light">Dashboard</a>
        </div>

        <?php foreach (array_unique($reportErrors) as $error): ?>
            <div class="alert alert-warning" role="status"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endforeach; ?>

        <div class="row g-4 mb-4">
            <div class="col-sm-6 col-xl-3"><div class="admin-stat-card"><div class="label">Total orders</div><div class="value"><?php echo number_format($stats['orders']); ?></div><div class="trend">All statuses</div></div></div>
            <div class="col-sm-6 col-xl-3"><div class="admin-stat-card"><div class="label">Completed revenue</div><div class="value">₱<?php echo number_format($stats['revenue'], 2); ?></div><div class="trend positive">Completed orders</div></div></div>
            <div class="col-sm-6 col-xl-3"><div class="admin-stat-card"><div class="label">Customers</div><div class="value"><?php echo number_format($stats['customers']); ?></div><div class="trend">Registered accounts</div></div></div>
            <div class="col-sm-6 col-xl-3"><div class="admin-stat-card"><div class="label">Inventory</div><div class="value"><?php echo number_format($stats['stock_units']); ?></div><div class="trend"><?php echo number_format($stats['products']); ?> products</div></div></div>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <section class="card admin-panel-card"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                        <div><h2 class="h4 mb-1">Monthly sales</h2><div class="text-muted small">Completed orders · Revenue and units sold</div></div>
                        <span class="badge bg-secondary">6 months</span>
                    </div>
                    <div class="sales-chart" role="group" aria-label="Completed monthly revenue and units sold">
                        <?php foreach ($months as $month): ?>
                            <?php $width = ((float)$month['revenue'] / $maxRevenue) * 100; ?>
                            <div class="sales-chart-row">
                                <div class="chart-label"><?php echo htmlspecialchars($month['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="sales-chart-track" aria-hidden="true"><span class="sales-chart-bar report-chart-bar" data-chart-width="<?php echo $width; ?>" style="--chart-width: <?php echo $width; ?>%;"></span></div>
                                <div class="sales-chart-values"><strong>₱<?php echo number_format((float)$month['revenue'], 2); ?></strong><span><?php echo number_format((int)$month['units']); ?> units · <?php echo number_format((int)$month['orders']); ?> orders</span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div></section>
            </div>

            <div class="col-xl-6">
                <section class="card admin-panel-card h-100"><div class="card-body">
                    <h2 class="h4 mb-4">Order status</h2>
                    <?php if ($statusTotal === 0): ?>
                        <div class="alert alert-info mb-0">No order data available yet.</div>
                    <?php else: ?>
                        <div class="report-donut-layout">
                            <div class="report-donut report-chart-donut" role="img" aria-label="Order status breakdown" style="<?php echo htmlspecialchars($statusDonut, ENT_QUOTES, 'UTF-8'); ?>"></div>
                            <div class="best-seller-legend">
                                <?php foreach ($statuses as $index => $row): ?>
                                    <?php
                                    $status = strtolower((string)$row['status']);
                                    $color = $statusColors[$status] ?? $chartColors[$index % count($chartColors)];
                                    ?>
                                    <div class="best-seller-legend-item">
                                        <span class="best-seller-swatch" style="--pie-color: <?php echo htmlspecialchars($color, ENT_QUOTES, 'UTF-8'); ?>"></span>
                                        <span class="best-seller-name text-capitalize"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <strong><?php echo number_format((int)$row['total']); ?></strong>
                                        <span class="best-seller-share"><?php echo number_format(((int)$row['total'] / $statusTotal) * 100, 1); ?>%</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div></section>
            </div>

            <div class="col-xl-6">
                <section class="card admin-panel-card h-100"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2"><h2 class="h4 mb-0">Inventory by category</h2><span class="badge bg-secondary">Units in stock</span></div>
                    <?php if (!$inventoryByCategory): ?>
                        <div class="alert alert-info mb-0">No inventory data available yet.</div>
                    <?php else: ?>
                        <?php foreach ($inventoryByCategory as $category): ?>
                            <?php $width = ((int)$category['units'] / $maxInventory) * 100; ?>
                            <div class="chart-row">
                                <div class="chart-label"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="chart-bar-track" aria-hidden="true"><div class="chart-bar report-chart-bar" data-chart-width="<?php echo $width; ?>" style="--chart-width: <?php echo $width; ?>%;"></div></div>
                                <div class="chart-value"><?php echo number_format((int)$category['units']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div></section>
            </div>

            <div class="col-12">
                <section class="card admin-panel-card"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2"><h2 class="h4 mb-0">Best-selling products</h2><span class="badge bg-secondary">Completed orders · 6 months</span></div>
                    <?php if (!$bestSellers): ?>
                        <div class="alert alert-info mb-0">No completed sales in this period yet.</div>
                    <?php else: ?>
                        <?php foreach ($bestSellers as $index => $product): ?>
                            <?php $width = ((int)$product['units'] / $maxBestSellerUnits) * 100; ?>
                            <div class="chart-row">
                                <div class="chart-label"><?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="chart-bar-track" aria-hidden="true"><div class="chart-bar report-chart-bar" data-chart-width="<?php echo $width; ?>" style="--chart-width: <?php echo $width; ?>%; background: <?php echo htmlspecialchars($chartColors[$index % count($chartColors)], ENT_QUOTES, 'UTF-8'); ?>;"></div></div>
                                <div class="chart-value"><?php echo number_format((int)$product['units']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div></section>
            </div>
        </div>
    </main>
    <script src="../assets/css/bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/report-charts.js" defer></script>
</body>
</html>
