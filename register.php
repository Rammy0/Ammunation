<?php
require_once __DIR__ . '/includes/auth.php';
$errors = [];
$name = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection for registration
    if (empty($_POST['csrf_token']) || !validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'register')) {
        $errors[] = 'Invalid or expired request (CSRF token). Please reload the page and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        if ($name === '') $errors[] = 'Name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $password_confirm) $errors[] = 'Passwords do not match.';
        if (!$errors) {
            if (getUserByEmail($email)) $errors[] = 'An account with that email already exists.';
            elseif (createUser($name, $email, $password)) { header('Location: ' . withAuthContext('login.php?registered=1')); exit; }
            else $errors[] = 'Failed to create account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Register - Ammunation</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/bootstrap-5.3.8-dist/css/bootstrap.min.css"><link rel="stylesheet" href="assets/css/site.css">
<script src="assets/js/auth-context.js" defer></script>
</head><body class="auth-page" data-auth-context="<?php echo htmlspecialchars(getAuthContextToken(), ENT_QUOTES, 'UTF-8'); ?>"><div class="auth-card">
<div class="text-center mb-4"><img src="assets/images/Logo/AmmunationLogo.png" alt="Ammunation Logo" style="max-height:110px"><h1 class="mt-2">CREATE ACCOUNT</h1></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post">
<?php echo csrfInputField('register'); ?>
<?php echo authContextInputField(); ?>
<div class="mb-3"><label class="form-label">Name</label><input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($name); ?>" required></div>
<div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" required></div>
<div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" minlength="8" required></div>
<div class="mb-3"><label class="form-label">Confirm Password</label><input type="password" name="password_confirm" class="form-control" minlength="8" required></div>
<button class="btn btn-dark w-100" type="submit">CREATE ACCOUNT</button>
</form><div class="text-center mt-3"><a href="login.php">Already have an account?</a> &middot; <a href="index.php">Back to site</a></div>
</div></body></html>
