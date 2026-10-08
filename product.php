<?php
require_once __DIR__ . '/includes/auth.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$slug = isset($_GET['slug']) ? trim($_GET['slug']) : null;
$product = null;
$site_base_url = isset($site_base_url) ? $site_base_url : 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cart_action'])) {
    $productId = (int)($_POST['product_id'] ?? 0);
    $returnUrl = $productId > 0 ? 'product.php?id=' . urlencode((string)$productId) . '&open_cart=1' : 'products.php?open_cart=1';

    if (!isLoggedIn()) {
        header('Location: login.php?redirect=' . rawurlencode($returnUrl));
        exit;
    }

    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'cart')) {
        http_response_code(400);
        exit('Invalid or expired request. Please try again.');
    }

    $stmt = $pdo->prepare("SELECT id FROM products WHERE id = :id AND status = 'available' LIMIT 1");
    $stmt->execute(['id' => $productId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        exit('Product not found.');
    }

    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    $_SESSION['cart'][$productId] = ((int)($_SESSION['cart'][$productId] ?? 0)) + 1;

    if ($_POST['cart_action'] === 'buy_now') {
        header('Location: ' . $returnUrl . '&buy_now=1');
        exit;
    }

    header('Location: ' . $returnUrl);
    exit;
}

/** Checks whether the products table supports slug-based lookups. */
function productHasSlugColumn(): bool
{
    global $pdo;

    try {
        $columns = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
        return in_array('slug', $columns, true);
    } catch (Throwable $e) {
        return false;
    }
}

try{
    if ($id) {
        $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = :id AND p.status = 'available' LIMIT 1");
        $stmt->execute(['id' => $id]);
    } elseif ($slug && productHasSlugColumn()) {
        $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.slug = :slug AND p.status = 'available' LIMIT 1");
        $stmt->execute(['slug' => $slug]);
    }
    if (isset($stmt)) $product = $stmt->fetch(PDO::FETCH_ASSOC);
}catch(Exception $e){
    $product = null;
}

/** Resolves a stored product image value to a public asset URL. */
function productImagePath(array $p): string {
    $candidates = ['image','image_path','image_filename','photo','thumbnail'];
    $allowed_ext = ['png','jpg','jpeg','webp','gif','svg'];
    foreach($candidates as $col){
        if (!empty($p[$col])) {
            $storedPath = str_replace('\\', '/', (string)$p[$col]);
            if (strpos($storedPath, 'products/') === 0) {
                $filename = basename($storedPath);
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed_ext, true)) {
                    return 'assets/images/' . $storedPath;
                }
            }
            $filename = basename($storedPath);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed_ext, true)) {
                return 'assets/images/' . $filename;
            }
        }
    }
    return 'assets/images/placeholder.svg';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container page-panel mt-4">
    <?php if (!$product): ?>
        <div class="alert alert-warning">Product not found.</div>
    <?php else: ?>
        <div class="row g-4 align-items-center">
            <div class="col-md-6">
                <div class="card p-3 border-0">
                    <img src="<?php echo htmlspecialchars(productImagePath($product) . '?v=' . (int)$product['id']); ?>" class="img-fluid rounded" alt="<?php echo htmlspecialchars($product['name']); ?>">
                </div>
            </div>
            <div class="col-md-6">
                <h1 class="mb-2"><?php echo htmlspecialchars($product['name']); ?></h1>
                <?php if (!empty($product['category_name'])): ?><p class="text-muted mb-3"><?php echo htmlspecialchars($product['category_name']); ?></p><?php endif; ?>
                <h3 class="text-primary mb-3">₱<?php echo number_format((float)($product['price'] ?? 0),2); ?></h3>
                <p class="lead"><?php echo nl2br(htmlspecialchars($product['description'] ?? '')); ?></p>
                <?php if (isLoggedIn()): ?>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <form method="post">
                            <?php echo csrfInputField('cart'); ?>
                            <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                            <input type="hidden" name="cart_action" value="add">
                            <button type="submit" class="btn btn-outline-primary">Add to cart</button>
                        </form>
                        <form method="post">
                            <?php echo csrfInputField('cart'); ?>
                            <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                            <input type="hidden" name="cart_action" value="buy_now">
                            <button type="submit" class="btn btn-primary">Buy now</button>
                        </form>
                    </div>
                <?php else: ?>
                    <a href="login.php?redirect=<?php echo rawurlencode('product.php?id=' . (int)$product['id']); ?>" class="btn btn-outline-secondary mb-3">Log in to buy</a>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                    <a href="products.php" class="btn btn-outline-secondary">Back</a>
                    <a href="mailto:?subject=Check%20this%20product&body=<?php echo rawurlencode('Take a look: ' . rtrim($site_base_url, '/') . $_SERVER['REQUEST_URI']); ?>" class="btn btn-outline-secondary">Share</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>