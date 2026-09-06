<?php
require_once __DIR__ . '/../includes/auth.php';
// require_once __DIR__ . '/../includes/header.php';
requireLogin();
requireAdmin();
$user = getUserById((int)$_SESSION['user_id']);
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
    <script src="../assets/js/ajax-nav.js" defer></script>
    <script src="../assets/css/bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js" defer></script>
</head>

<body class="with-sidebar">
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
            <br>
            <br>
            <center><span>&copy; <?php echo date('Y'); ?> Ammunation</span></center>
        </div>
    </nav>

    <main id="main-content" class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1>ADMIN DASHBOARD</h1>
                <p class="mb-0">Welcome, <?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?>.</p>
            </div>
            <div>
                <a href="../index.php" class="btn btn-outline-light">Site</a>
                <a href="../logout.php" class="btn btn-outline-danger">Log out</a>
            </div>
        </div>
    <div class="card">
        <div class="card-body">
            <h4>Management</h4>
            <div class="list-group">
                <a href="../products.php" class="list-group-item list-group-item-action">View Products</a>
                <a href="products.php" class="list-group-item list-group-item-action">Upload Products</a>
                <a href="users.php" class="list-group-item list-group-item-action">Manage Users</a>
                <a href="orders.php" class="list-group-item list-group-item-action">Orders</a>
            </div>
        </div>
    </div>
    </main>
</body>
</html>
