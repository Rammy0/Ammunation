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
$name = '';
$description = '';
$price = '';
$stock = '';
$categoryId = '';
$editId = (int)($_GET['edit'] ?? 0);
$editProduct = null;

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

/** Converts a product name into a URL-safe slug. */
function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');

    return $value !== '' ? $value : 'product';
}

/** Adds the product slug column when an older schema does not have it. */
function ensureProductSlugColumn(): void
{
    global $pdo;

    try {
        $columns = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('slug', $columns, true)) {
            return;
        }

        $pdo->exec('ALTER TABLE products ADD COLUMN slug VARCHAR(180) NULL AFTER name');
    } catch (Throwable $e) {
        // If the table is unavailable, the rest of the upload flow will surface a clearer message.
    }
}

/** Adds the stock column when it is missing from an older product schema. */
function ensureProductStockColumn(): void
{
    global $pdo;

    try {
        $columns = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('stock', $columns, true)) {
            return;
        }

        $pdo->exec('ALTER TABLE products ADD COLUMN stock INT NOT NULL DEFAULT 0 AFTER price');
    } catch (Throwable $e) {
        // The product flow will continue and surface a clearer error if the table is unavailable.
    }
}

ensureProductSlugColumn();
ensureProductStockColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Distinguish management actions from the upload form
    if (!empty($_POST['action'])) {
        $action = $_POST['action'];
        $actionPurpose = $action === 'delete' ? 'product-action' : 'product-edit';
        if (empty($_POST['csrf_token']) || !validateCsrfToken((string)($_POST['csrf_token'] ?? ''), $actionPurpose)) {
            $errors[] = 'Invalid or expired request (CSRF token).';
        } elseif ($action === 'edit') {
            $editId = (int)($_POST['product_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $editId]);
            $editProduct = $stmt->fetch(PDO::FETCH_ASSOC);

            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $price = trim((string)($_POST['price'] ?? ''));
            $stock = trim((string)($_POST['stock'] ?? ''));
            $categoryId = (int)($_POST['category_id'] ?? 0);

            if (!$editProduct) {
                $errors[] = 'Product not found.';
            } elseif ($name === '') {
                $errors[] = 'Product name is required.';
            } elseif ($categoryId <= 0) {
                $errors[] = 'Please select a category.';
            } elseif ($price === '' || !is_numeric($price) || (float)$price < 0) {
                $errors[] = 'Please enter a valid price.';
            } elseif ($stock === '' || !ctype_digit($stock) || (int)$stock < 0) {
                $errors[] = 'Please enter a valid stock amount.';
            }

            if (!$errors) {
                $slug = $editProduct['slug'] ?? slugify($name);
                if ($name !== ($editProduct['name'] ?? '')) {
                    $slugBase = slugify($name);
                    $slug = $slugBase;
                    $counter = 1;
                    do {
                        $stmt = $pdo->prepare('SELECT id FROM products WHERE slug = :slug AND id != :id LIMIT 1');
                        $stmt->execute(['slug' => $slug, 'id' => $editId]);
                        if (!$stmt->fetch()) {
                            break;
                        }
                        $slug = $slugBase . '-' . $counter++;
                    } while (true);
                }

                $imagePath = $editProduct['image'] ?? null;
                $newImagePath = null;
                if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $file = $_FILES['image'];
                    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
                    $fileMime = mime_content_type($file['tmp_name']);

                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        $errors[] = 'There was a problem uploading the product image.';
                    } elseif (($file['size'] ?? 0) > 2 * 1024 * 1024 || (!in_array($fileMime, $allowedMimeTypes, true) && !in_array($extension, $allowedExtensions, true))) {
                        $errors[] = 'Only JPG, PNG, WEBP, GIF, and SVG images up to 2MB are allowed.';
                    } else {
                        $uploadDir = __DIR__ . '/../assets/images/products';
                        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                            $errors[] = 'Unable to create the product image folder.';
                        } else {
                            $safeName = $slug . '-' . time() . '-' . bin2hex(random_bytes(4));
                            $targetFile = $uploadDir . '/' . $safeName . '.' . $extension;
                            if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
                                $errors[] = 'The image could not be saved.';
                            } else {
                                $newImagePath = 'products/' . basename($targetFile);
                                $imagePath = $newImagePath;
                            }
                        }
                    }
                }

                if (!$errors) {
                    try {
                        ensureProductPriceHistoryTable($pdo);
                        removeLegacyStoreTriggers($pdo);
                        $pdo->beginTransaction();
                        $priceStmt = $pdo->prepare('SELECT price FROM products WHERE id = :id FOR UPDATE');
                        $priceStmt->execute(['id' => $editId]);
                        $oldPrice = $priceStmt->fetchColumn();
                        if ($oldPrice === false) {
                            throw new RuntimeException('Product not found.');
                        }

                        $stmt = $pdo->prepare('UPDATE products SET category_id = :category_id, name = :name, slug = :slug, description = :description, price = :price, stock = :stock, image = :image WHERE id = :id');
                        $stmt->execute([
                            'category_id' => $categoryId,
                            'name' => $name,
                            'slug' => $slug,
                            'description' => $description,
                            'price' => (float)$price,
                            'stock' => (int)$stock,
                            'image' => $imagePath,
                            'id' => $editId,
                        ]);
                        recordProductPriceChange($pdo, $editId, $oldPrice, (float)$price);
                        $pdo->commit();
                        if ($newImagePath !== null && !empty($editProduct['image'])) {
                            $oldImage = __DIR__ . '/../assets/images/' . ltrim($editProduct['image'], '/');
                            if (is_file($oldImage)) {
                                unlink($oldImage);
                            }
                        }
                        $success = 'Product updated successfully.';
                        $editId = 0;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Unable to update product: ' . $e->getMessage());
                        if ($newImagePath !== null) {
                            $savedImage = __DIR__ . '/../assets/images/' . ltrim($newImagePath, '/');
                            if (is_file($savedImage)) {
                                unlink($savedImage);
                            }
                        }
                        $errors[] = 'Unable to update product.';
                    }
                }
            }
        } else {
            if ($action === 'delete') {
                $productId = (int)($_POST['product_id'] ?? 0);
                if ($productId <= 0) {
                    $errors[] = 'Invalid product id.';
                } else {
                    try {
                        $stmt = $pdo->prepare('SELECT image FROM products WHERE id = :id LIMIT 1');
                        $stmt->execute(['id' => $productId]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$row) {
                            $errors[] = 'Product not found.';
                        } else {
                            $referenceStmt = $pdo->prepare('SELECT COUNT(*) FROM request_items WHERE product_id = :id');
                            $referenceStmt->execute(['id' => $productId]);
                            $isReferenced = (int)$referenceStmt->fetchColumn() > 0;

                            if ($isReferenced) {
                                // Keep request history intact while removing the product from the catalog.
                                $stmt = $pdo->prepare("UPDATE products SET status = 'inactive' WHERE id = :id");
                                $stmt->execute(['id' => $productId]);
                                $success = 'Product archived because it is referenced by an existing request.';
                            } else {
                                $stmt = $pdo->prepare('DELETE FROM products WHERE id = :id');
                                $stmt->execute(['id' => $productId]);
                                $success = 'Product deleted.';
                            }

                            // remove image file only when the database row is deleted
                            if (!$isReferenced && !empty($row['image'])) {
                                $imgPath = __DIR__ . '/../assets/images/' . ltrim($row['image'], '/');
                                if (is_file($imgPath)) {
                                    @unlink($imgPath);
                                }
                            }

                            if (isJsonRequest()) {
                                sendJsonRedirect('products.php');
                            } else {
                                // redirect to avoid resubmission
                                header('Location: ' . withAuthContext('products.php'));
                                exit;
                            }
                        }
                    } catch (Throwable $e) {
                        $errors[] = 'Unable to delete product.';
                    }
                }
            }
        }
    } else {
        // regular upload flow (existing upload code)
        if (empty($_POST['csrf_token']) || !validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'product-upload')) {
            $errors[] = 'Invalid or expired request (CSRF token). Please reload the page and try again.';
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $price = trim((string)($_POST['price'] ?? ''));
        $stock = trim((string)($_POST['stock'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);

        if ($name === '') {
            $errors[] = 'Product name is required.';
        }
        if ($categoryId <= 0) {
            $errors[] = 'Please select a category.';
        }
        if ($price === '' || !is_numeric($price) || (float)$price < 0) {
            $errors[] = 'Please enter a valid price.';
        }
        if ($stock === '' || !ctype_digit($stock) || (int)$stock < 0) {
            $errors[] = 'Please enter a valid stock amount.';
        }

        if (!$errors) {
            $slugBase = slugify($name);
            $slug = $slugBase;
            $counter = 1;

            do {
                $stmt = $pdo->prepare('SELECT id FROM products WHERE slug = :slug LIMIT 1');
                $stmt->execute(['slug' => $slug]);
                if (!$stmt->fetch()) {
                    break;
                }
                $slug = $slugBase . '-' . $counter;
                $counter++;
            } while (true);

            $imagePath = null;
            $uploadDir = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['image'];

                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'There was a problem uploading the product image.';
                } else {
                    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
                    $fileMime = mime_content_type($file['tmp_name']);
                    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

                    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
                        $errors[] = 'Image must be 2MB or smaller.';
                    } elseif (!in_array($fileMime, $allowedMimeTypes, true) && !in_array($extension, $allowedExtensions, true)) {
                        $errors[] = 'Only JPG, PNG, WEBP, GIF, and SVG images are allowed.';
                    }

                    if (!$errors) {
                        $uploadDir = __DIR__ . '/../assets/images/products';
                        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                            $errors[] = 'Unable to create the product image folder.';
                        }
                    }

                    if (!$errors) {
                        $safeName = $slug . '-' . time() . '-' . bin2hex(random_bytes(4));
                        $targetFile = $uploadDir . '/' . $safeName . '.' . $extension;

                        if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
                            $errors[] = 'The image could not be saved.';
                        } else {
                            $imagePath = 'products/' . basename($targetFile);
                        }
                    }
                }
            }

            if (!$errors) {
                try {
                    $stmt = $pdo->prepare('INSERT INTO products (category_id, name, slug, description, price, stock, image) VALUES (:category_id, :name, :slug, :description, :price, :stock, :image)');
                    $ok = $stmt->execute([
                        'category_id' => $categoryId,
                        'name' => $name,
                        'slug' => $slug,
                        'description' => $description,
                        'price' => (float)$price,
                        'stock' => (int)$stock,
                        'image' => $imagePath,
                    ]);
                } catch (Throwable $e) {
                    $ok = false;
                }

                if ($ok) {
                    $success = 'Product uploaded successfully.';
                    $name = '';
                    $description = '';
                    $price = '';
                    $stock = '';
                    $categoryId = '';
                } else {
                    if ($imagePath !== null) {
                        $savedImage = __DIR__ . '/../assets/images/' . ltrim($imagePath, '/');
                        if (is_file($savedImage)) {
                            unlink($savedImage);
                        }
                    }
                    $errors[] = 'The product could not be saved.';
                }
            }
        }
    }
}

try {
    $categories = $pdo->query('SELECT id, name, slug FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    $search = trim((string)($_GET['q'] ?? ''));
    $filterCategory = (int)($_GET['category'] ?? 0);
    $sql = 'SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id';
    $conditions = [];
    $params = [];
    if ($search !== '') {
        $conditions[] = '(p.name LIKE :search OR p.description LIKE :search OR p.slug LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }
    if ($filterCategory > 0) {
        $conditions[] = 'p.category_id = :category';
        $params['category'] = $filterCategory;
    }
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY p.id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $categories = [];
    $products = [];
    $search = '';
    $filterCategory = 0;
    if (empty($errors)) {
        $errors[] = 'Unable to load the categories or product list.';
    }
}

if ($editId > 0 && $editProduct === null) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $editId]);
        $editProduct = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$editProduct) {
            $errors[] = 'Product not found.';
            $editId = 0;
        }
    } catch (Throwable $e) {
        $errors[] = 'Unable to load the product for editing.';
        $editId = 0;
    }
}
?>
<!DOCTYPE html><html lang="en"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Product Management - Ammunation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/site.css">
    <link rel="stylesheet" href="../assets/css/logout-confirmation.css">
    <script src="../assets/js/ajax-nav.js?v=20261011-tabs" defer></script>
    <script src="../assets/js/auth-context.js" defer></script>
    <script src="../assets/js/admin-idle-timeout.js" defer></script>
    <script src="../assets/js/logout-confirmation.js" defer></script>
</head><body class="with-sidebar" data-auth-context="<?php echo htmlspecialchars(getAuthContextToken(), ENT_QUOTES, 'UTF-8'); ?>">
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
                <li class="nav-item mb-2"><a class="nav-link" href="reports.php">Reports</a></li>
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

    <!-- Product Management Header -->
    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-1">Product Management</h1>
                <p class="text-muted mb-0">Manage your product catalog</p>
            </div>
            <a href="products.php?action=add" class="btn btn-dark">+ Add Product</a>
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

    <?php if ($editId > 0 && $editProduct): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title mb-4">Edit Product</h5>
            <form method="post" enctype="multipart/form-data">
                <?php echo csrfInputField('product-edit'); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="product_id" value="<?php echo $editId; ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Product Name *</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($editProduct['name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Category *</label>
                        <select name="category_id" class="form-select" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo (int)$category['id']; ?>" <?php echo ((int)($editProduct['category_id'] ?? 0) === (int)$category['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Price (PHP) *</label>
                        <input type="number" step="0.01" min="0" name="price" class="form-control" value="<?php echo htmlspecialchars($editProduct['price'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Stock *</label>
                        <input type="number" min="0" step="1" name="stock" class="form-control" value="<?php echo htmlspecialchars((string)($editProduct['stock'] ?? 0)); ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4"><?php echo htmlspecialchars($editProduct['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Replace product image</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <div class="form-text">Optional. Upload to replace the existing image.</div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-dark">Save changes</button>
                        <a href="products.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Add Product Form -->
    <?php if (!empty($_GET['action']) && $_GET['action'] === 'add'): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title mb-4">Add New Product</h5>
            <form method="post" enctype="multipart/form-data" data-ajax="true">
                <?php echo csrfInputField('product-upload'); ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Product Name *</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($name); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Category *</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo (int)$category['id']; ?>" <?php echo ($categoryId === (int)$category['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Price (PHP) *</label>
                        <input type="number" step="0.01" min="0" name="price" class="form-control" value="<?php echo htmlspecialchars($price); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Stock *</label>
                        <input type="number" min="0" step="1" name="stock" class="form-control" value="<?php echo htmlspecialchars($stock); ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4"><?php echo htmlspecialchars($description); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Product Image</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <small class="text-muted">JPG, PNG, WEBP, GIF, or SVG up to 2MB</small>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-dark">Add Product</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Search and Filter Bar -->
    <?php if (empty($_GET['action']) || $_GET['action'] !== 'add'): ?>
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-2" data-ajax="true">
                <div class="col-md-6">
                    <label class="form-label d-none">Search Products</label>
                    <input type="search" id="admin-product-search" name="q" class="form-control" value="<?php echo htmlspecialchars($search ?? ''); ?>" placeholder="Search by name, description..." aria-label="Search products">
                </div>
                <div class="col-md-4">
                    <label class="form-label d-none">Filter by Category</label>
                    <select name="category" class="form-select">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int)$category['id']; ?>" <?php echo ((int)($filterCategory ?? 0) === (int)$category['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-outline-dark w-100" type="submit">Search</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Products Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <?php if (empty($products)): ?>
                    <div class="alert alert-info mb-0">
                        <strong>No products found.</strong> Start by <a href="products.php?action=add" class="alert-link">adding a new product</a>.
                    </div>
                <?php else: ?>
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px;">Image</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th style="width: 120px;">Price</th>
                                <th style="width: 100px;">Stock</th>
                                <th style="width: 160px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr class="admin-product-row"
                                    data-category="<?php echo (int)($product['category_id'] ?? 0); ?>"
                                    data-search="<?php echo htmlspecialchars(strtolower(implode(' ', [
                                        (string)($product['name'] ?? ''),
                                        (string)($product['description'] ?? ''),
                                        (string)($product['category_name'] ?? ''),
                                        (string)($product['slug'] ?? '')
                                    ]))); ?>">
                                    <td>
                                        <img src="<?php echo htmlspecialchars(productImagePath($product) . '?v=' . (int)$product['id']); ?>" 
                                             alt="<?php echo htmlspecialchars($product['name'] ?? 'Product'); ?>" 
                                             style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px; background: #f0f0f0;">
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($product['name'] ?? 'Untitled'); ?></strong>
                                            <?php if (!empty($product['description'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($product['description'], 0, 60)); ?>...</small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark"><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></span>
                                    </td>
                                    <td>
                                        <strong class="text-primary">₱<?php echo number_format((float)($product['price'] ?? 0), 2); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-dark"><?php echo (int)($product['stock'] ?? 0); ?></span>
                                    </td>
                                    <td>
                                        <a href="products.php?edit=<?php echo (int)$product['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this product?');">
                                            <?php echo csrfInputField('product-action'); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="admin-no-match" class="d-none">
                                <td colspan="6" class="text-center text-muted">No products match your search.</td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
    </div>
    </div>
    <?php endif; ?>
</div>
<script>
    (function () {
        var searchInput = document.getElementById('admin-product-search');
        var categorySelect = document.querySelector('select[name="category"]');
        var rows = Array.prototype.slice.call(document.querySelectorAll('.admin-product-row'));
        var noMatch = document.getElementById('admin-no-match');

        if (!searchInput || !rows.length) {
            return;
        }

        function filterProducts() {
            var searchTerm = searchInput.value.trim().toLowerCase();
            var categoryId = categorySelect ? categorySelect.value : '0';
            var visibleCount = 0;

            rows.forEach(function (row) {
                var matchesSearch = !searchTerm || row.dataset.search.indexOf(searchTerm) !== -1;
                var matchesCategory = categoryId === '0' || row.dataset.category === categoryId;
                var visible = matchesSearch && matchesCategory;
                row.classList.toggle('d-none', !visible);
                if (visible) {
                    visibleCount++;
                }
            });

            if (noMatch) {
                noMatch.classList.toggle('d-none', visibleCount > 0);
            }
        }

        searchInput.addEventListener('input', filterProducts);
        if (categorySelect) {
            categorySelect.addEventListener('change', filterProducts);
        }
        searchInput.form.addEventListener('submit', function (event) {
            event.preventDefault();
            filterProducts();
        });
        filterProducts();
    }());
</script>
<script src="../assets/css/bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js"></script>
</body></html>
