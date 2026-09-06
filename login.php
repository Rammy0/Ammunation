<?php
require_once __DIR__ . '/includes/auth.php';

$errors = [];
$email = '';
$redirect = trim((string)($_GET['redirect'] ?? $_POST['redirect'] ?? ''));
if ($redirect === '' || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*\.php(?:\?[^\/]*)?$/', $redirect) !== 1) {
    $redirect = 'index.php';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = getClientIp();
    if (!isLoginAllowed($ip)) {
        $errors[] = 'Too many login attempts. Please wait and try again later.';
    } else {
        // CSRF protection for login
        if (empty($_POST['csrf_token']) || !validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'login')) {
            $errors[] = 'Invalid or expired request (CSRF token). Please reload the page and try again.';
        } else {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
            if ($password === '') $errors[] = 'Enter your password.';

            if (!$errors) {
                $user = getUserByEmail($email);
                if ($user && password_verify($password, $user['password'])) {
                    // Successful login: clear attempts
                    clearLoginAttempts($ip);
                    loginUserById((int)$user['id']);
                    header('Location: ' . $redirect);
                    exit;
                }
                // record failed attempt
                recordLoginAttempt($ip);
                $errors[] = 'Invalid email or password.';
            }
        }
    }
}
$just_registered = isset($_GET['registered']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - Ammunation</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/bootstrap-5.3.8-dist/css/bootstrap.min.css"><link rel="stylesheet" href="assets/css/site.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="text-center mb-4"><img src="assets/images/Logo/AmmunationLogo.png" alt="Ammunation Logo" style="max-height:110px"><h1 class="mt-2">LOGIN</h1></div>
    <?php if ($just_registered): ?><div class="alert alert-success">Account created. Please log in.</div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post">
        <?php echo csrfInputField('login'); ?>
        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
        <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" required></div>
        <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
        <button class="btn btn-dark w-100" type="submit">LOG IN</button>
    </form>
    <div class="text-center mt-3"><a href="register.php">Create an account</a> &middot; <a href="index.php">Back to site</a></div>
</div>
</body></html>
