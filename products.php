<?php
require_once __DIR__ . '/includes/auth.php';

$search = trim((string)($_GET['q'] ?? ''));
$categoryId = (int)($_GET['category'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cart_action'])) {
    if (!isLoggedIn()) {
        $returnUrl = 'products.php?' . http_build_query([
            'q' => $search,
            'category' => $categoryId,
            'open_cart' => 1,
        ]);
        header('Location: login.php?redirect=' . rawurlencode($returnUrl));
        exit;
    }

    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'cart')) {
        http_response_code(400);
        exit('Invalid or expired request. Please try again.');
    }

    $productId = (int)($_POST['product_id'] ?? 0);
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
        header('Location: products.php?' . http_build_query([
            'q' => $search,
            'category' => $categoryId,
            'open_cart' => 1,
            'buy_now' => 1,
        ]));
        exit;
    }

    header('Location: products.php?' . http_build_query([
        'q' => $search,
        'category' => $categoryId,
        'open_cart' => 1,
    ]));
    exit;
}

// Fetch products from DB (assumes products and categories tables exist)
try{
    $query = "SELECT p.*, c.name AS category_name
              FROM products p
              LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.status = 'available'";
    $params = [];

    if ($search !== '') {
        $query .= " AND (p.name LIKE :search_name
                    OR p.description LIKE :search_description
                    OR c.name LIKE :search_category)";
        $searchTerm = '%' . $search . '%';
        $params = [
            ':search_name' => $searchTerm,
            ':search_description' => $searchTerm,
            ':search_category' => $searchTerm,
        ];
    }

    if ($categoryId > 0) {
        $query .= " AND p.category_id = :category_id";
        $params[':category_id'] = $categoryId;
    }

    $query .= " ORDER BY p.name";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){
    $products = [];
}

$categories = [];
try {
    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
}

function productImagePath($p){
    // common column names to check
    $candidates = ['image','image_path','image_filename','photo','thumbnail'];
    $allowed_ext = ['png','jpg','jpeg','webp','gif','svg'];
    foreach($candidates as $col){
        if (!empty($p[$col])) {
            $storedPath = str_replace('\\', '/', (string)$p[$col]);
            if (strpos($storedPath, 'products/') === 0) {
                return 'assets/images/' . ltrim($storedPath, '/');
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
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="mb-1">Products</h1>
            <p class="text mb-0">Find the right gear for your next move.</p>
        </div>
        <form method="get" action="products.php" class="product-toolbar d-flex flex-row align-items-center gap-2" role="search">
            <label for="product-search" class="visually-hidden">Search products</label>
            <input
                type="search"
                id="product-search"
                name="q"
                value="<?php echo htmlspecialchars($search); ?>"
                class="form-control"
                placeholder="Search products..."
                aria-label="Search products"
                style="max-width: 360px; min-width: 180px;"
            >
            <label for="product-category" class="visually-hidden">Filter by category</label>
            <select id="product-category" name="category" class="form-select" aria-label="Filter by category" style="max-width: 220px; min-width: 180px;">
                <option value="0">All categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?php echo (int)$category['id']; ?>"<?php echo $categoryId === (int)$category['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary text-nowrap">Search</button>
        </form>
    </div>
    <div id="product-grid" class="row g-4">
        <?php if (empty($products)): ?>
            <div id="no-products-message" class="col-12"><div class="alert alert-info"><?php echo ($search !== '' || $categoryId > 0) ? 'No products matched your filters.' : 'No products found.'; ?></div></div>
        <?php endif; ?>

        <?php foreach($products as $prod): ?>
            <div
                class="product-card col-sm-6 col-md-4 col-lg-3 mb-4"
                data-name="<?php echo htmlspecialchars(strtolower((string)($prod['name'] ?? ''))); ?>"
                data-description="<?php echo htmlspecialchars(strtolower((string)($prod['description'] ?? ''))); ?>"
                data-category="<?php echo (int)($prod['category_id'] ?? 0); ?>"
                data-category-name="<?php echo htmlspecialchars(strtolower((string)($prod['category_name'] ?? ''))); ?>"
            >
                <div class="card h-100">
                    <a href="product.php?id=<?php echo urlencode($prod['id']); ?>">
                        <img src="<?php echo htmlspecialchars(productImagePath($prod) . '?v=' . (int)$prod['id']); ?>" class="card-img-top" alt="<?php echo htmlspecialchars($prod['name'] ?? 'Product'); ?>">
                    </a>
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title mb-1"><a href="product.php?id=<?php echo urlencode($prod['id']); ?>" class="text-decoration-none"><?php echo htmlspecialchars($prod['name'] ?? 'Untitled'); ?></a></h5>
                        <?php if (!empty($prod['category_name'])): ?><small class="text-muted"><?php echo htmlspecialchars($prod['category_name']); ?></small><?php endif; ?>
                        <p class="mt-2 mb-0 text-truncate"><?php echo htmlspecialchars(substr($prod['description'] ?? '',0,120)); ?></p>
                        <div class="mt-auto pt-2">
                            <?php if (isset($prod['price'])): ?><strong class="text-primary">₱<?php echo number_format((float)$prod['price'],2); ?></strong><?php endif; ?>
                            <?php if (isLoggedIn()): ?>
                                <div class="d-flex gap-2 mt-3">
                                    <form method="post">
                                        <?php echo csrfInputField('cart'); ?>
                                        <input type="hidden" name="product_id" value="<?php echo (int)$prod['id']; ?>">
                                        <input type="hidden" name="cart_action" value="add">
                                        <button type="submit" class="btn btn-outline-primary btn-sm">Add to cart</button>
                                    </form>
                                    <form method="post">
                                        <?php echo csrfInputField('cart'); ?>
                                        <input type="hidden" name="product_id" value="<?php echo (int)$prod['id']; ?>">
                                        <input type="hidden" name="cart_action" value="buy_now">
                                        <button type="submit" class="btn btn-primary btn-sm">Buy now</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <a href="login.php?redirect=<?php echo rawurlencode('products.php'); ?>" class="btn btn-outline-secondary btn-sm mt-3">Log in to buy</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
    (function () {
        var searchInput = document.getElementById('product-search');
        var categorySelect = document.getElementById('product-category');
        var productCards = Array.prototype.slice.call(document.querySelectorAll('.product-card'));
        var noProductsMessage = document.getElementById('no-products-message');

        // Hides cards that do not match the current search text and category.
        function filterProducts() {
            var searchTerm = searchInput.value.trim().toLowerCase();
            var categoryId = categorySelect.value;
            var visibleCount = 0;

            productCards.forEach(function (card) {
                var searchableText = [
                    card.dataset.name,
                    card.dataset.description,
                    card.dataset.categoryName
                ].join(' ');
                var matchesSearch = !searchTerm || searchableText.indexOf(searchTerm) !== -1;
                var matchesCategory = categoryId === '0' || card.dataset.category === categoryId;
                var isVisible = matchesSearch && matchesCategory;

                card.classList.toggle('d-none', !isVisible);
                if (isVisible) {
                    visibleCount++;
                }
            });

            if (noProductsMessage) {
                noProductsMessage.classList.toggle('d-none', visibleCount > 0);
            }
        }

        searchInput.addEventListener('input', filterProducts);
        categorySelect.addEventListener('change', filterProducts);
        searchInput.form.addEventListener('submit', function (event) {
            event.preventDefault();
            filterProducts();
        });
        filterProducts();
    }());
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>