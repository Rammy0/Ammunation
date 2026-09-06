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

/** Converts a product name into a URL-safe slug. */
function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');

    return $value !== '' ? $value : 'product';
}

/** Adds the product quantity column when an older schema does not have it. */
function ensureProductQuantityColumn(): void
{
    global $pdo;

    try {
        $columns = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('quantity', $columns, true)) {
            return;
        }

        $pdo->exec('ALTER TABLE products ADD COLUMN quantity INT NOT NULL DEFAULT 0 AFTER price');
    } catch (Throwable $e) {
        // Ignore if the table is unavailable; the page will surface the underlying database error.
    }
}

ensureProductQuantityColumn();

$id = (int)($_GET['id'] ?? 0);
$errors = [];
$success = '';
$product = null;

try {
    $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $categories = [];
}

if ($id > 0) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $product = null;
    }
}

if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'product-edit')) {
        $errors[] = 'Invalid or expired request (CSRF token).';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $price = trim((string)($_POST['price'] ?? ''));
        $quantity = trim((string)($_POST['quantity'] ?? ''));
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
        if ($quantity === '' || !ctype_digit((string)$quantity) || (int)$quantity < 0) {
            $errors[] = 'Please enter a valid quantity.';
        }

        if (empty($errors)) {
            $slug = $product['slug'] ?? slugify($name);
            if ($name !== ($product['name'] ?? '')) {
                $slugBase = slugify($name);
                $slug = $slugBase;
                $counter = 1;
                do {
                    $stmt = $pdo->prepare('SELECT id FROM products WHERE slug = :slug AND id != :id LIMIT 1');
                    $stmt->execute(['slug' => $slug, 'id' => $id]);
                    if (!$stmt->fetch()) break;
                    $slug = $slugBase . '-' . $counter;
                    $counter++;
                } while (true);
            }

            $imagePath = $product['image'] ?? null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['image'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Problem uploading image.';
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

                    if (empty($errors)) {
                        $uploadDir = __DIR__ . '/../assets/images/products';
                        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                            $errors[] = 'Unable to create upload folder.';
                        } else {
                            $safeName = ($slug ?? slugify($name)) . '-' . time() . '-' . bin2hex(random_bytes(4));
                            $targetFile = $uploadDir . '/' . $safeName . '.' . $extension;
                            if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
                                $errors[] = 'Image could not be saved.';
                            } else {
                                if (!empty($product['image'])) {
                                    $old = __DIR__ . '/../assets/images/' . ltrim($product['image'], '/');
                                    if (is_file($old)) @unlink($old);
                                }
                                $imagePath = 'products/' . basename($targetFile);
                            }
                        }
                    }
                }
            }

            if (empty($errors)) {
                try {
                    $stmt = $pdo->prepare('UPDATE products SET category_id = :category_id, name = :name, slug = :slug, description = :description, price = :price, quantity = :quantity, image = :image WHERE id = :id');
                    $stmt->execute([
                        'category_id' => $categoryId,
                        'name' => $name,
                        'slug' => $slug,
                        'description' => $description,
                        'price' => (float)$price,
                        'quantity' => (int)$quantity,
                        'image' => $imagePath,
                        'id' => $id,
                    ]);

                    $success = 'Product updated.';
                    $product['name'] = $name;
                    $product['description'] = $description;
                    $product['price'] = $price;
                    $product['quantity'] = (int)$quantity;
                    $product['category_id'] = $categoryId;
                    $product['image'] = $imagePath;
                } catch (Throwable $e) {
                    if ($imagePath !== ($product['image'] ?? null)) {
                        $savedImage = __DIR__ . '/../assets/images/' . ltrim((string)$imagePath, '/');
                        if (is_file($savedImage)) {
                            unlink($savedImage);
                        }
                    }
                    $errors[] = 'Unable to update product.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Product - Ammunation</title>
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
                <li class="nav-item mb-2">
                    <span class="nav-link">Products</span>
                    <div id="productsMenu">
                        <ul class="nav flex-column ms-3 mt-2">
                            <li class="nav-item mb-1"><a class="nav-link nav-sublink" href="products.php">View Products</a></li>
                            <li class="nav-item mb-1"><a class="nav-link nav-sublink" href="products.php?action=add">Add Product</a></li>
                            <li class="nav-item mb-1"><a class="nav-link nav-sublink active" href="products.php">Manage Products</a></li>
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
            <br><br><br><br><br><br><br><br>
            <center><span>&copy; <?php echo date('Y'); ?> Ammunation</span></center>
        </div>
    </nav>

    <main id="main-content" class="container py-5">
        <?php if ($id > 0 && $product): ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">Edit Product</h1>
                    <p class="mb-0 text-muted">Modify product details.</p>
                </div>
                <a href="product_edit.php" class="btn btn-outline-secondary">Back to list</a>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" data-ajax="true">
                        <?php echo csrfInputField('product-edit'); ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Product name</label>
                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($product['name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Category</label>
                                <select name="category_id" class="form-select" required>
                                    <option value="">Select category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo (int)$category['id']; ?>" <?php echo ((int)$product['category_id'] === (int)$category['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Price (PHP)</label>
                                <input type="number" step="0.01" min="0" name="price" class="form-control" value="<?php echo htmlspecialchars($product['price'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Quantity</label>
                                <input type="number" min="0" step="1" name="quantity" class="form-control" value="<?php echo htmlspecialchars((string)($product['quantity'] ?? 0)); ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="5"><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Replace product image</label>
                                <input type="file" name="image" class="form-control" accept="image/*">
                                <div class="form-text">Optional. Upload to replace existing image.</div>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-dark">Save changes</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="mb-4">
                <h1 class="h3 mb-1">Edit Product</h1>
                <p class="text-muted mb-0">Select a product to edit.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errors[0]); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <?php
                        try {
                            $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.created_at DESC');
                            $stmt->execute();
                            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Throwable $e) {
                            $products = [];
                        }
                        ?>
                        <?php if (empty($products)): ?>
                            <div class="alert alert-info mb-0"><strong>No products found.</strong></div>
                        <?php else: ?>
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:60px;">Image</th>
                                        <th>Product Name</th>
                                        <th>Category</th>
                                        <th style="width:120px;">Price</th>
                                        <th style="width:100px;">Quantity</th>
                                        <th style="width:100px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $item): ?>
                                        <tr>
                                            <td>
                                                <img src="<?php echo htmlspecialchars(productImagePath($item) . '?v=' . (int)$item['id']); ?>" alt="<?php echo htmlspecialchars($item['name'] ?? 'Product'); ?>" style="width:50px;height:50px;object-fit:cover;border-radius:4px;background:#f0f0f0;">
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($item['name'] ?? 'Untitled'); ?></strong>
                                                    <?php if (!empty($item['description'])): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars(substr($item['description'], 0, 60)); ?>...</small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?></span></td>
                                            <td><strong class="text-primary">₱<?php echo number_format((float)($item['price'] ?? 0), 2); ?></strong></td>
                                            <td><span class="badge bg-secondary-subtle text-dark"><?php echo (int)($item['quantity'] ?? 0); ?></span></td>
                                            <td><a href="product_edit.php?id=<?php echo (int)$item['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
