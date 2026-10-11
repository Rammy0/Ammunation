<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
requireAdmin();

$errors = [];
$success = '';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . withAuthContext('users.php'));
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT id, name, email, is_admin, created_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $user = null;
}

if (!$user) {
    header('Location: ' . withAuthContext('users.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'user-edit')) {
        $errors[] = 'Invalid or expired request (CSRF token). Please reload the page and try again.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }

        if (empty($errors)) {
            $res = updateUser($id, $name, $email, $password !== '' ? $password : null);
            if (!empty($res['success'])) {
                $success = 'User updated successfully.';
                // reload fresh data
                $user['name'] = $name;
                $user['email'] = $email;
            } else {
                $errors[] = $res['error'] ?? 'Unable to update user.';
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
    <title>Edit User - Ammunation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/site.css">
    <script src="../assets/js/ajax-nav.js?v=20261011-tabs" defer></script>
    <script src="../assets/js/auth-context.js" defer></script>
</head>
<body class="with-sidebar" data-auth-context="<?php echo htmlspecialchars(getAuthContextToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <nav class="side-menu" aria-label="Admin sidebar">
        <div class="side-inner">
            <div class="sidebar-brand">
                <div class="sidebar-brand-name">Ammunation</div>
                <div class="sidebar-brand-tagline">Admin</div>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item mb-2"><a class="nav-link" href="admin.php">Dashboard</a></li>
                <li class="nav-item mb-2"><a class="nav-link" href="products.php">Upload Products</a></li>
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
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1>EDIT USER</h1>
            <p class="mb-0 text-muted">Modify user details for <?php echo htmlspecialchars($user['email'] ?? ''); ?>.</p>
        </div>
        <div>
            <a href="users.php" class="btn btn-outline-light me-2">Back</a>
            <a href="../logout.php" class="btn btn-outline-danger">Log out</a>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" data-ajax="true">
                <?php echo csrfInputField('user-edit'); ?>
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">New password (leave blank to keep current)</label>
                    <input type="password" name="password" class="form-control" value="">
                </div>
                <div class="mb-3">
                    <button type="submit" class="btn btn-dark">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
