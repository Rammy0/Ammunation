<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
requireAdmin();

$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $currentUser = getUserById((int)$_SESSION['user_id']);
}

/** Resolves a stored product image value to an admin-facing asset URL. */
function productImagePath($p)
{
    $candidates = ['image', 'image_path', 'image_filename', 'photo', 'thumbnail'];
    foreach ($candidates as $col) {
        if (!empty($p[$col])) {
            $value = str_replace('\\', '/', (string)$p[$col]);
            if (strpos($value, 'assets/images/') === 0) {
                return '../' . $value;
            }
            if (strpos($value, '/assets/') === 0 || strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0) {
                return $value;
            }
            return '../assets/images/' . ltrim($value, '/');
        }
    }

    return '../assets/images/placeholder.svg';
}

$id = (int)($_GET['id'] ?? 0);
$errors = [];
$success = '';
$product = null;

if ($id > 0) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $product = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'product-delete')) {
        $errors[] = 'Invalid or expired request (CSRF token).';
    } else {
        $productId = (int)($_POST['product_id'] ?? 0);
        
        if ($productId <= 0) {
            $errors[] = 'Invalid product ID.';
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM products WHERE id = :id');
                $stmt->execute(['id' => $productId]);
                $success = 'Product deleted successfully.';
                $product = null;
            } catch (Throwable $e) {
                $errors[] = 'Failed to delete product: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Product - Ammunation</title>
    <link rel="stylesheet" href="../assets/css/bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/site.css">
</head>
<body class="with-sidebar" data-auth-context="<?php echo htmlspecialchars(getAuthContextToken(), ENT_QUOTES, 'UTF-8'); ?>">
<script src="../assets/js/auth-context.js" defer></script>
    <nav class="side-menu">
        <div class="p-4">
            <h5 class="mb-4">Admin Panel</h5>
            <ul class="nav flex-column">
                <li class="nav-item mb-2"><a class="nav-link" href="admin.php">Dashboard</a></li>
                
                <!-- Products menu -->
                <li class="nav-item mb-2">
                    <span class="nav-link">Products</span>
                    <div id="productsMenu">
                        <ul class="nav flex-column ms-3 mt-2">
                            <li class="nav-item mb-1"><a class="nav-link nav-sublink" href="products.php?action=add">Add Product</a></li>
                            <li class="nav-item mb-1"><a class="nav-link nav-sublink active" href="products.php">Manage Products</a></li>
                            <li class="nav-item mb-1"><a class="nav-link nav-sublink" href="products.php">View Products</a></li>
                        </ul>
                    </div>
                </li>
                
                <li class="nav-item mb-2"><a class="nav-link" href="users.php">Manage Users</a></li>
                <li class="nav-item mb-2"><a class="nav-link" href="orders.php">Orders</a></li>
                <li class="nav-item mt-3"><a class="nav-link" href="../index.php">View site</a></li>
                <li class="nav-item"><a class="nav-link text-danger" href="../logout.php">Log out</a></li>
            </ul>

            <?php if (!empty($currentUser)): ?>
                <div class="mt-4 small text-muted">Signed in as<br><strong><?php echo htmlspecialchars($currentUser['name'] ?? $currentUser['email'] ?? ''); ?></strong></div>
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

    <!-- Delete Product Header -->
    <div class="mb-4">
        <div>
            <h1 class="h3 mb-1">Delete Product</h1>
            <p class="text-muted mb-0">Select a product to delete</p>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <div class="alert-heading">Error</div>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Products List for Deletion -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <?php
                try {
                    $stmt = $pdo->prepare('SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.created_at DESC');
                    $stmt->execute();
                    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $products = [];
                }
                ?>
                <?php if (empty($products)): ?>
                    <div class="alert alert-info mb-0">
                        <strong>No products found.</strong>
                    </div>
                <?php else: ?>
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px;">Image</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th style="width: 120px;">Price</th>
                                <th style="width: 100px;">Quantity</th>
                                <th style="width: 100px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $prod): ?>
                                <tr>
                                    <td>
                                        <img src="<?php echo htmlspecialchars(productImagePath($prod) . '?v=' . (int)$prod['id']); ?>" 
                                             alt="<?php echo htmlspecialchars($prod['name'] ?? 'Product'); ?>" 
                                             style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px; background: #f0f0f0;">
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($prod['name'] ?? 'Untitled'); ?></strong>
                                            <?php if (!empty($prod['description'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($prod['description'], 0, 60)); ?>...</small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark"><?php echo htmlspecialchars($prod['category_name'] ?? 'Uncategorized'); ?></span>
                                    </td>
                                    <td>
                                        <strong class="text-primary">₱<?php echo number_format((float)($prod['price'] ?? 0), 2); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-dark"><?php echo (int)($prod['quantity'] ?? 0); ?></span>
                                    </td>
                                    <td>
                                        <form method="post" data-ajax="true" onsubmit="return confirm('Delete this product? This action cannot be undone.');" style="display:inline;">
                                            <?php echo csrfInputField('product-delete'); ?>
                                            <input type="hidden" name="product_id" value="<?php echo (int)$prod['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

</main>
<script src="../assets/css/bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
