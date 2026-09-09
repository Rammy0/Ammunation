<?php
$pageTitle = 'Categories - Ammunation';
require_once __DIR__ . '/includes/header.php';

$category = trim($_GET['category'] ?? '');
$slug = trim($_GET['slug'] ?? '');
$categoryId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$search = trim((string)($_GET['q'] ?? ''));
$products = [];
$categoryName = $category ?: 'Category';

try {
    $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $categories = [];
}

try {
    if ($categoryId) {
        $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $categoryId]); $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cat) { $categoryName = $cat['name']; $categoryId = (int)$cat['id']; }
    } elseif ($slug) {
        $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]); $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cat) { $categoryName = $cat['name']; $categoryId = (int)$cat['id']; }
    } elseif ($category) {
        $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $category]); $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cat) { $categoryName = $cat['name']; $categoryId = (int)$cat['id']; }
    }

    $query = 'SELECT p.* FROM products p WHERE p.status = :status';
    $params = [':status' => 'available'];

    if ($categoryId > 0) {
        $query .= ' AND p.category_id = :category_id';
        $params[':category_id'] = $categoryId;
    }

    if ($search !== '') {
        $query .= ' AND (p.name LIKE :search_name OR p.description LIKE :search_description)';
        $searchTerm = '%' . $search . '%';
        $params[':search_name'] = $searchTerm;
        $params[':search_description'] = $searchTerm;
    }

    $query .= ' ORDER BY p.name';
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $products = [];
}

/** Resolves a category product's stored image to a public asset URL. */
function categoryImagePath(array $p): string {
    foreach (['image','image_path','image_filename','photo','thumbnail'] as $col) {
        if (!empty($p[$col])) {
            $storedPath = str_replace('\\', '/', (string)$p[$col]);
            if (strpos($storedPath, 'products/') === 0) {
                return 'assets/images/' . ltrim($storedPath, '/');
            }
            return 'assets/images/' . basename($storedPath);
        }
    }
    return 'assets/images/placeholder.svg';
}
?>
<div class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <h1 class="mb-0"><?php echo htmlspecialchars($categoryName); ?></h1>
        <form method="get" action="categories.php" class="product-toolbar d-flex flex-row align-items-center gap-2" role="search">
            <label for="category-search" class="visually-hidden">Search products in category</label>
            <input
                type="search"
                id="category-search"
                name="q"
                value="<?php echo htmlspecialchars($search); ?>"
                class="form-control"
                placeholder="Search products..."
                aria-label="Search products in category"
                style="max-width: 360px; min-width: 180px;"
            >
            <button type="submit" class="btn btn-primary text-nowrap">Search</button>
        </form>
    </div>

    <div class="row g-4">
        <?php if (!$products): ?><div class="col-12"><div class="alert alert-info"><?php echo ($search !== '' || $categoryId > 0) ? 'No products matched your filters.' : 'No products were found in this category.'; ?></div></div><?php endif; ?>
        <?php foreach ($products as $prod): ?><div class="col-sm-6 col-md-4 col-lg-3 mb-4"><div class="card h-100">
        <a href="product.php?id=<?php echo (int)$prod['id']; ?>"><img src="<?php echo htmlspecialchars(categoryImagePath($prod)); ?>" class="card-img-top product-image" alt="<?php echo htmlspecialchars($prod['name'] ?? 'Product'); ?>"></a>
        <div class="card-body"><h5><a class="text-decoration-none" href="product.php?id=<?php echo (int)$prod['id']; ?>"><?php echo htmlspecialchars($prod['name'] ?? 'Untitled'); ?></a></h5>
        <?php if (isset($prod['price'])): ?><strong>₱<?php echo number_format((float)$prod['price'], 2); ?></strong><?php endif; ?></div></div></div><?php endforeach; ?></div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
