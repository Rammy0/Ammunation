<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
requireAdmin();
 
$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $currentUser = getUserById((int)$_SESSION['user_id']);
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (empty($_POST['csrf_token']) || !validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'user-action')) {
        $errors[] = 'Invalid or expired request (CSRF token). Please reload the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $targetId = (int)($_POST['user_id'] ?? 0);

        if ($targetId <= 0) {
            $errors[] = 'Invalid user id.';
        } else {
            // Prevent actions on current user where appropriate
            $currentUserId = (int)($_SESSION['user_id'] ?? 0);

            if ($action === 'toggle_admin') {
                // Prevent removing admin role from the only admin
                try {
                    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = :id LIMIT 1');
                    $stmt->execute(['id' => $targetId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $isAdminNow = !empty($row['is_admin']);

                    if ($isAdminNow) {
                        // Count admins
                        $countStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1');
                        $adminCount = (int)$countStmt->fetchColumn();
                        if ($adminCount <= 1) {
                            $errors[] = 'Cannot remove admin role: there must be at least one admin.';
                        }
                    }

                    if (empty($errors)) {
                        $stmt = $pdo->prepare('UPDATE users SET is_admin = :is_admin WHERE id = :id');
                        $stmt->execute(['is_admin' => $isAdminNow ? 0 : 1, 'id' => $targetId]);
                        $success = 'User role updated.';

                        // If changed current user's admin flag, refresh session
                        if ($targetId === $currentUserId) {
                            $_SESSION['is_admin'] = !$isAdminNow;
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Unable to update user role.';
                }
            } elseif ($action === 'delete') {
                if ($targetId === $currentUserId) {
                    $errors[] = 'You cannot delete your own account while logged in.';
                } else {
                    try {
                        $referenceStmt = $pdo->prepare('SELECT COUNT(*) FROM requests WHERE user_id = :id');
                        $referenceStmt->execute(['id' => $targetId]);
                        if ((int)$referenceStmt->fetchColumn() > 0) {
                            $errors[] = 'This user has existing requests and cannot be deleted without removing order history.';
                        } else {
                            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                            $stmt->execute(['id' => $targetId]);
                            $success = 'User deleted.';
                        }
                    } catch (Throwable $e) {
                        $errors[] = 'Unable to delete user.';
                    }
                }
            }
        }
    }
}

try {
    $users = $pdo->query('SELECT id, name, email, is_admin, created_at FROM users ORDER BY created_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $users = [];
    $errors[] = 'Unable to load user list.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Users - Ammunation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/site.css">
    <script src="../assets/js/ajax-nav.js?v=20261011-tabs" defer></script>
    <script src="../assets/js/auth-context.js" defer></script>
    <script src="../assets/css/bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js" defer></script>
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
                
                <!-- Products menu -->
                <li class="nav-item mb-2">
                    <span class="nav-link">Products</span>
                    <div id="productsMenu">
                        <ul class="nav flex-column ms-3 mt-2">
                            <li class="nav-item mb-1"><a class="nav-link nav-sublink" href="products.php">View Products</a></li>
                            <li class="nav-item mb-1"><a class="nav-link nav-sublink" href="products.php?action=add">Add Product</a></li>
                            <li class="nav-item mb-1"><a class="nav-link nav-sublink" href="products.php">Manage Products</a></li>
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
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1>MANAGE USERS</h1>
            <p class="mb-0 text-muted">View and manage registered users.</p>
        </div>
        <div>
            <a href="admin.php" class="btn btn-outline-light me-2">Dashboard</a>
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
            <h4 class="mb-3">Users</h4>

            <?php if (empty($users)): ?>
                <div class="alert alert-info">No users found.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-sm align-middle">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Admin</th>
                            <th>Registered</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?php echo (int)$u['id']; ?></td>
                                <td><?php echo htmlspecialchars($u['name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                                <td><?php echo !empty($u['is_admin']) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                                <td><?php echo htmlspecialchars($u['created_at'] ?? ''); ?></td>
                                <td class="text-end">
                                    <div class="d-inline-block">
                                        <form method="post" data-ajax="true" style="display:inline-block;margin-right:6px;">
                                                                                    <?php echo csrfInputField('user-action'); ?>
                                                                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                                                    <input type="hidden" name="action" value="toggle_admin">
                                                                                    
                                                                                </form>
                                        <?php if ((int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                                                                                    <a href="user_edit.php?id=<?php echo (int)$u['id']; ?>" class="btn btn-sm btn-outline-primary me-1">Edit</a>
                                                                                    <form method="post" data-ajax="true" style="display:inline-block;" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                                                                                            <?php echo csrfInputField('user-action'); ?>
                                                                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                                                            <input type="hidden" name="action" value="delete">
                                                                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
