<?php
$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $currentUser = getUserById((int)$_SESSION['user_id']);
}
?>
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
                        <li class="nav-item mb-1"><a class="nav-link nav-sublink" href="products.php?action=add"> U+279C Add Product</a></li>
                        <li class="nav-item mb-1"><a class="nav-link nav-sublink" href="products.php"> U+279C Manage Products</a></li>
                    </ul>
                </div>
            </li>
            
            <li class="nav-item mb-2"><a class="nav-link" href="users.php">Manage Users</a></li>
            <li class="nav-item mb-2"><a class="nav-link" href="orders.php">Orders</a></li>
            <li class="nav-item mt-3"><a class="nav-link" href="../index.php">View site</a></li>
            <li class="nav-item"><a class="nav-link text-danger" href="../logout.php">Log out</a></li>
        </ul>

        <?php if ($currentUser): ?>
            <div class="mt-4 small text-muted">Signed in as<br><strong><?php echo htmlspecialchars($currentUser['name'] ?? $currentUser['email'] ?? ''); ?></strong></div>
        <?php endif; ?>
    </div>
</nav>