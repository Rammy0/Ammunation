<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    // Harden session cookie parameters before starting the session
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $cookieParams = [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', // leave empty to use host of the request
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($cookieParams);
    } else {
        session_set_cookie_params($cookieParams['lifetime'], $cookieParams['path'], $cookieParams['domain'], $cookieParams['secure'], $cookieParams['httponly']);
    }
    session_start();
}

/**
 * CSRF token helpers
 */
/** Creates a short-lived, single-use CSRF token for a form purpose. */
function generateCsrfToken(string $purpose = 'default'): string
{
    if (empty($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }
    $token = bin2hex(random_bytes(16));
    // store with purpose and timestamp
    $_SESSION['csrf_tokens'][$token] = [
        'purpose' => $purpose,
        'created' => time()
    ];
    
    // Remove expired tokens before applying a generous safety cap. Product
    // listings can contain many forms, each with its own single-use token.
    $now = time();
    foreach ($_SESSION['csrf_tokens'] as $storedToken => $meta) {
        if (!empty($meta['created']) && ($now - (int)$meta['created']) > 3600) {
            unset($_SESSION['csrf_tokens'][$storedToken]);
        }
    }
    if (count($_SESSION['csrf_tokens']) > 500) {
        $_SESSION['csrf_tokens'] = array_slice($_SESSION['csrf_tokens'], -500, 500, true);
    }
    return $token;
}

/** Validates and consumes a CSRF token. */
function validateCsrfToken(string $token, string $purpose = 'default'): bool
{
    if (empty($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
        return false;
    }
    if (empty($token) || !isset($_SESSION['csrf_tokens'][$token])) {
        return false;
    }
    $meta = $_SESSION['csrf_tokens'][$token];
    // optional: check purpose matches
    if (!empty($meta['purpose']) && $meta['purpose'] !== $purpose) {
        return false;
    }
    // token lifetime: 1 hour
    if (!empty($meta['created']) && (time() - (int)$meta['created']) > 3600) {
        unset($_SESSION['csrf_tokens'][$token]);
        return false;
    }
    // single use
    unset($_SESSION['csrf_tokens'][$token]);
    return true;
}

/** Returns a hidden HTML input containing a CSRF token. */
function csrfInputField(string $purpose = 'default'): string
{
    $t = htmlspecialchars(generateCsrfToken($purpose), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $t . '">';
}

// Detect JSON/AJAX requests (XMLHttpRequest or explicit Accept header)
/** Determines whether the request expects an AJAX/JSON response. */
function isJsonRequest(): bool
{
    $xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $acceptsJson = strpos(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    return $xhr || $acceptsJson;
}

/** Sends a JSON response and terminates the current request. */
function sendJsonResponse(array $data, int $status = 200): void
{
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($data);
    exit;
}

/** Sends a redirect instruction in JSON format. */
function sendJsonRedirect(string $url, int $status = 200): void
{
    sendJsonResponse(['redirect' => $url], $status);
}

/** Finds a user by email address. */
function getUserByEmail(string $email): ?array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT id, name, email, password, is_admin, created_at FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

/** Finds a user by either email or their display-name username. */
function getUserByLoginIdentifier(string $identifier): ?array
{
    global $pdo;
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, name, email, password, is_admin, created_at FROM users WHERE email = :email_identifier OR LOWER(name) = LOWER(:name_identifier) LIMIT 1');
    $stmt->execute([
        ':email_identifier' => $identifier,
        ':name_identifier' => $identifier,
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

/** Finds a user by database ID. */
function getUserById(int $id): ?array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT id, name, email, is_admin, created_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

/** Creates a user account with a securely hashed password. */
function createUser(string $name, string $email, string $password): bool
{
    global $pdo;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (name, email, password) VALUES (:name, :email, :password)');
    try {
        return (bool)$stmt->execute(['name' => $name, 'email' => $email, 'password' => $hash]);
    } catch (PDOException $e) {
        // Handle duplicate email (unique constraint)
        if ($e->getCode() === '23000') {
            return false;
        }
        // For other DB errors, log and return false
        error_log('createUser DB error: ' . $e->getMessage());
        return false;
    }
}

/** Reports whether the session contains an authenticated user. */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

/** Redirects unauthenticated users to the login page. */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        if (isJsonRequest()) {
            sendJsonRedirect('/login.php', 401);
        }

        header('Location: login.php');
        exit;
    }
}

/**
 * Simple login rate limiting helpers stored in session.
 * Tracks attempts per IP address within a time window to mitigate brute-force.
 */
/** Returns the client IP used by the login rate limiter. */
function getClientIp(): string
{
    // Prefer REMOTE_ADDR; do not trust X-Forwarded-For without proxy validation
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/** Builds the session key for an IP's login attempts. */
function loginAttemptsKey(string $ip): string
{
    return 'login_attempts_' . $ip;
}

/** Checks whether an IP may make another login attempt. */
function isLoginAllowed(string $ip, int $maxAttempts = 5, int $windowSeconds = 900): bool
{
    if (empty($_SESSION)) return true; // session not started? allow by default
    $key = loginAttemptsKey($ip);
    $data = $_SESSION[$key] ?? null;
    if (!$data) return true;
    if (time() - $data['first'] > $windowSeconds) {
        // window expired
        unset($_SESSION[$key]);
        return true;
    }
    return $data['count'] < $maxAttempts;
}

/** Records a failed login attempt for an IP address. */
function recordLoginAttempt(string $ip): void
{
    if (empty($_SESSION)) return;
    $key = loginAttemptsKey($ip);
    $data = $_SESSION[$key] ?? null;
    if (!$data) {
        $_SESSION[$key] = ['count' => 1, 'first' => time()];
    } else {
        $data['count'] = ($data['count'] ?? 0) + 1;
        // keep original first timestamp
        $_SESSION[$key] = $data;
    }
}

/** Clears recorded login attempts after a successful login. */
function clearLoginAttempts(string $ip): void
{
    if (empty($_SESSION)) return;
    $key = loginAttemptsKey($ip);
    unset($_SESSION[$key]);
}

/** Starts an authenticated session for a user ID. */
function loginUserById(int $id): void
{
    global $pdo;

    // A fresh login should replace any existing session state so a different
    // account can sign in even when an admin session is currently active.
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    $_SESSION['cart'] = [];

    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]); 
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $_SESSION['is_admin'] = !empty($row['is_admin']);
}

/** Clears the authenticated session and session cookie. */
function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/** Updates account details and optionally replaces the password. */
function updateUser(int $id, string $name, string $email, ?string $password = null): array
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
    $stmt->execute(['email' => $email, 'id' => $id]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'Email already in use by another account.'];
    }

    if ($password !== null && $password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET name = :name, email = :email, password = :password WHERE id = :id');
        $ok = $stmt->execute(['name' => $name, 'email' => $email, 'password' => $hash, 'id' => $id]);
    } else {
        $stmt = $pdo->prepare('UPDATE users SET name = :name, email = :email WHERE id = :id');
        $ok = $stmt->execute(['name' => $name, 'email' => $email, 'id' => $id]);
    }

    return ['success' => (bool)$ok];
}

/** Reports whether the current user has administrator privileges. */
function isAdmin(): bool
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    if (!empty($_SESSION['user_id'])) {
        $user = getUserById((int)$_SESSION['user_id']);
        return !empty($user['is_admin']);
    }

    return false;
}

/** Redirects users who are not authenticated administrators. */
function requireAdmin(): void
{
    if (!isLoggedIn() || !isAdmin()) {
        if (isJsonRequest()) {
            sendJsonRedirect('/login.php', 403);
        }
        header('Location: ../login.php');
        exit;
    }
}
