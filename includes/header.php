<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/store_helpers.php';

$user = null;
if (isLoggedIn()) {
    $user = getUserById((int)($_SESSION['user_id'] ?? 0));
}
$cart = $_SESSION['cart'] ?? [];
$cartCount = getStoreCartCount(is_array($cart) ? $cart : []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Ammunation'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/site.css">
    <script src="assets/js/ajax-nav.js" defer></script>
</head>
<body>

<nav class="navbar navbar-expand-lg site-navbar">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="index.php" aria-label="Ammunation home">
            <img src="assets/images/Logo/AmmunationLogo.png" alt="Ammunation Logo" class="brand-logo">
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <?php if (isLoggedIn()): ?>
                    <li class="nav-item ms-lg-2">
                        <a class="btn btn-outline-light btn-sm" href="#cartDrawer" data-bs-toggle="offcanvas" aria-controls="cartDrawer">
                            &#x1F6D2 Cart <span id="cartCountBadge" class="badge bg-danger"<?php echo $cartCount > 0 ? '' : ' hidden'; ?>><?php echo $cartCount; ?></span>
                        </a>
                    </li>
                    <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                        <a class="btn btn-light btn-sm" href="profile.php">Profile</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item ms-lg-2">
                        <a class="btn btn-light btn-sm" href="login.php">Login</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>  

<?php if (isLoggedIn()): ?>
<div class="offcanvas offcanvas-end cart-drawer" tabindex="-1" id="cartDrawer" aria-labelledby="cartDrawerLabel">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title" id="cartDrawerLabel">Your cart</h2>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close cart"></button>
    </div>
    <div class="offcanvas-body" id="cartDrawerContent" aria-live="polite">
        <p class="text-muted">Loading cart…</p>
    </div>
</div>
<?php endif; ?>

<div id="menuHotspot" class="menu-hotspot" aria-label="Open navigation menu" title="Open menu"></div>

<aside id="sideMenu" class="side-menu collapsed" aria-label="Site navigation">
    <div class="side-inner">
        <div class="sidebar-brand">
            <img src="assets/images/Logo/AmmunationLogo.png" alt="Ammunation Logo">
            <div class="sidebar-brand-name">AMMUNATION</div>
            <div class="sidebar-brand-tagline">THE ARSENAL YOU CAN TRUST</div>
        </div>

        <nav class="nav flex-column">
            <a class="nav-link" href="index.php">Home</a>
            <a class="nav-link" href="products.php">Products</a>
            <a class="nav-link" href="#">Orders</a>
            <?php if (isLoggedIn()): ?><a class="nav-link" href="#cartDrawer" data-bs-toggle="offcanvas" aria-controls="cartDrawer">Cart</a><?php endif; ?>

            <?php
            $menuCategories = [
                'Pistol' => 'Pistols',
                'Rifle' => 'Rifles',
                'Shotgun' => 'Shotguns',
                'SMG' => 'SMG',
                'Sniper Rifle' => 'Sniper Rifles'
            ];
            $foundCats = [];
            try {
                $dbCategories = array_values($menuCategories);
                $placeholders = implode(',', array_fill(0, count($dbCategories), '?'));
                $stmt = $pdo->prepare("SELECT id, name, slug FROM categories WHERE name IN ($placeholders)");
                $stmt->execute($dbCategories);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $foundCats[$row['name']] = $row;
                }
            } catch (Throwable $e) {
                // The sidebar remains usable even if the category table is unavailable.
            }
            ?>

            <div class="nav-item has-sub">
                <a href="#" class="nav-link category-toggle d-flex align-items-center">
                    <span>Categories</span><span class="ms-auto caret">&#9656;</span>
                </a>
                <nav class="submenu nav flex-column">
                    <?php foreach ($menuCategories as $label => $dbName):
                        $link = 'categories.php?category=' . urlencode($dbName);
                        if (isset($foundCats[$dbName])) {
                            $row = $foundCats[$dbName];
                            $link = !empty($row['slug'])
                                ? 'categories.php?slug=' . urlencode($row['slug'])
                                : 'categories.php?id=' . urlencode($row['id']);
                        }
                    ?>
                        <a class="nav-link sub-item" href="<?php echo htmlspecialchars($link); ?>"><?php echo htmlspecialchars($label); ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <a class="nav-link" href="profile.php">My Account</a>
            <a class="nav-link" href="register.php">Login/Register</a>
            <a class="nav-link" href="#">Contact Us</a>
        </nav>
    </div>
</aside>

<main id="pageContent">
