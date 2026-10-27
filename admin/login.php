<?php
require_once __DIR__ . '/../includes/auth.php';
if (isLoggedIn() && isAdmin()) {
    header('Location: ' . withAuthContext('admin.php'));
    exit;
}

$errors = [];
if (isset($_GET['session_expired'])) {
    $errors[] = 'Your admin session expired or was replaced. Please sign in again.';
}
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = getClientIp();
    if (!isLoginAllowed($ip)) {
        $errors[] = 'Too many login attempts. Please wait and try again later.';
    } else {
        // CSRF protection for admin login
        if (empty($_POST['csrf_token']) || !validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'admin-login')) {
            $errors[] = 'Invalid or expired request (CSRF token). Please reload the page and try again.';
        } else {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = $_POST['password'] ?? '';
            if ($username === '') $errors[] = 'Enter your username.';
            if ($password === '') $errors[] = 'Enter your password.';
            if (!$errors) {
                $user = getUserByUsername($username);
                if (!$user || !password_verify($password, $user['password'])) {
                    recordLoginAttempt($ip);
                    $errors[] = 'Invalid username or password.';
                } elseif (empty($user['is_admin'])) {
                    recordLoginAttempt($ip);
                    $errors[] = 'Admin access required.';
                } else {
                    if (loginUserById((int)$user['id'])) {
                        clearLoginAttempts($ip);
                        header('Location: ' . withAuthContext('admin.php'));
                        exit;
                    }
                    $errors[] = 'An admin session is already active, or could not be secured. Try again later.';
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
    <title>Admin Login - Ammunation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/site.css">
    <script src="../assets/js/auth-context.js" defer></script>
</head>

<body class="auth-page" data-auth-context="<?php echo htmlspecialchars(getAuthContextToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="auth-card">
        <div class="text-center mb-4"><img src="../assets/images/Logo/AmmunationLogo.png" alt="Ammunation Logo" style="max-height:110px">
            <h1 class="mt-2">ADMIN LOGIN</h1>
        </div>
        <?php if ($errors): ?><div class="alert alert-danger">
                <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
            </div><?php endif; ?>
        <form method="post"><?php echo csrfInputField('admin-login'); ?><?php echo authContextInputField(); ?><div class="mb-3"><label class="form-label">Username</label><input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($username); ?>" autocomplete="username" required></div>
            <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div><button class="btn btn-dark w-100" type="submit">LOG IN AS ADMIN</button>
        </form>
        <div class="text-center mt-3"><a href="../login.php">User login</a> &middot; <a href="../index.php">Back to site</a></div>
    </div>
</body>

</html>