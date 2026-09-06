<?php
$pageTitle = 'Categories - Ammunation';
require_once __DIR__ . '/includes/header.php';
$category = trim($_GET['category'] ?? '');
$slug = trim($_GET['slug'] ?? '');
$categoryId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$products = [];
$categoryName = $category ?: 'Category';
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
    if ($categoryId) {
        $stmt = $pdo->prepare('SELECT p.* FROM products p WHERE p.category_id = :category_id ORDER BY p.name');
        $stmt->execute(['category_id' => $categoryId]); $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { $products = []; }
/** Resolves a category product's stored image to a public asset URL. */
function categoryImagePath(array $p): string {
    foreach (['image','image_path','image_filename','photo','thumbnail'] as $col) {
        if (!empty($p[$col])) return 'assets/images/' . $p[$col];
    }
    return 'assets/images/placeholder.svg';
}
?>
<div class="container py-4"><h1><?php echo htmlspecialchars($categoryName); ?></h1>
<div class="row">
<?php if (!$products): ?><div class="col-12"><div class="alert alert-info">No products were found in this category.</div></div><?php endif; ?>
<?php foreach ($products as $prod): ?><div class="col-sm-6 col-md-4 col-lg-3 mb-4"><div class="card h-100">
<a href="product.php?id=<?php echo (int)$prod['id']; ?>"><img src="<?php echo htmlspecialchars(categoryImagePath($prod)); ?>" class="card-img-top product-image" alt="<?php echo htmlspecialchars($prod['name'] ?? 'Product'); ?>"></a>
<div class="card-body"><h5><a class="text-decoration-none" href="product.php?id=<?php echo (int)$prod['id']; ?>"><?php echo htmlspecialchars($prod['name'] ?? 'Untitled'); ?></a></h5>
<?php if (isset($prod['price'])): ?><strong>₱<?php echo number_format((float)$prod['price'], 2); ?></strong><?php endif; ?></div></div></div><?php endforeach; ?></div></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
