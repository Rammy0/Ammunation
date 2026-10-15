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

$authContextToken = (string)($_GET['_ctx'] ?? $_POST['_ctx'] ?? $_SERVER['HTTP_X_AUTH_CONTEXT'] ?? '');
if (!is_array($_SESSION['auth_contexts'] ?? null)) {
    $_SESSION['auth_contexts'] = [];
}
$hasValidContext = preg_match('/^[a-f0-9]{64}$/', $authContextToken) === 1;
if (!$hasValidContext) {
    $authContextToken = bin2hex(random_bytes(32));
    $legacySessionExists = empty($_SESSION['auth_contexts']) && !empty($_SESSION['user_id']);
    $_SESSION['auth_contexts'][$authContextToken] = [
        'user_id' => $legacySessionExists ? (int)$_SESSION['user_id'] : null,
        'is_admin' => $legacySessionExists && !empty($_SESSION['is_admin']),
        'cart' => $legacySessionExists && is_array($_SESSION['cart'] ?? null) ? $_SESSION['cart'] : [],
    ];
} elseif (!isset($_SESSION['auth_contexts'][$authContextToken])) {
    $_SESSION['auth_contexts'][$authContextToken] = ['user_id' => null, 'is_admin' => false, 'cart' => []];
}

$_SESSION['user_id'] = $_SESSION['auth_contexts'][$authContextToken]['user_id'] ?? null;
$_SESSION['is_admin'] = !empty($_SESSION['auth_contexts'][$authContextToken]['is_admin']);
$_SESSION['cart'] = is_array($_SESSION['auth_contexts'][$authContextToken]['cart'] ?? null)
    ? $_SESSION['auth_contexts'][$authContextToken]['cart']
    : [];
$GLOBALS['auth_context_token'] = $authContextToken;

register_shutdown_function(static function () use ($authContextToken): void {
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['auth_contexts'][$authContextToken])) {
        return;
    }

    $_SESSION['auth_contexts'][$authContextToken] = [
        'user_id' => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        'is_admin' => !empty($_SESSION['is_admin']),
        'cart' => is_array($_SESSION['cart'] ?? null) ? $_SESSION['cart'] : [],
        'cart_database_user_id' => isset($_SESSION['auth_contexts'][$authContextToken]['cart_database_user_id'])
            ? (int)$_SESSION['auth_contexts'][$authContextToken]['cart_database_user_id']
            : null,
    ];
});

/** Returns the random authentication context assigned to this browser tab. */
function getAuthContextToken(): string
{
    return (string)($GLOBALS['auth_context_token'] ?? '');
}

/** Returns a URL with the current tab's authentication context attached. */
function withAuthContext(string $url): string
{
    $token = getAuthContextToken();
    if ($token === '' || preg_match('/(?:^|[?&])_ctx=/', $url) === 1) {
        return $url;
    }

    $fragment = '';
    $fragmentPosition = strpos($url, '#');
    if ($fragmentPosition !== false) {
        $fragment = substr($url, $fragmentPosition);
        $url = substr($url, 0, $fragmentPosition);
    }

    return $url . (strpos($url, '?') === false ? '?' : '&') . '_ctx=' . rawurlencode($token) . $fragment;
}

/** Returns a hidden field carrying this tab's authentication context. */
function authContextInputField(): string
{
    return '<input type="hidden" name="_ctx" value="' .
        htmlspecialchars(getAuthContextToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
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
    $stmt = $pdo->prepare('SELECT id, uname, email, password, is_admin, created_at FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

/** Finds a user by username. */
function getUserByUsername(string $username): ?array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT id, uname, email, password, is_admin, created_at FROM users WHERE LOWER(uname) = LOWER(:username) LIMIT 1');
    $stmt->execute(['username' => trim($username)]);
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

    $stmt = $pdo->prepare('SELECT id, uname, email, password, is_admin, created_at FROM users WHERE email = :email_identifier OR LOWER(uname) = LOWER(:name_identifier) LIMIT 1');
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
    $stmt = $pdo->prepare('SELECT id, uname, email, is_admin, created_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

/** Creates a user account with a securely hashed password. */
function createUser(string $name, string $email, string $password): bool
{
    global $pdo;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (uname, email, password) VALUES (:name, :email, :password)');
    try {
        return (bool)$stmt->execute(['uname' => $name, 'email' => $email, 'password' => $hash]);
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

        header('Location: ' . withAuthContext('login.php'));
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

/** Creates the database lock used to enforce one active administrator session. */
function ensureAdminSessionLock(): void
{
    global $pdo;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_session_lock (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            session_id VARCHAR(128) NULL,
            user_id INT UNSIGNED NULL,
            last_seen DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'INSERT INTO admin_session_lock (id, session_id, user_id, last_seen)
         VALUES (1, NULL, NULL, NULL)
         ON DUPLICATE KEY UPDATE id = 1'
    );
}

/** Creates a lock identity unique to this tab as well as this PHP session. */
function adminSessionLockKey(?string $sessionId = null): string
{
    return hash('sha256', ($sessionId ?? session_id()) . ':' . getAuthContextToken());
}

/** Reserves the single administrator slot for the current PHP session. */
function reserveAdminSession(int $userId): bool
{
    global $pdo;

    try {
        ensureAdminSessionLock();
        $pdo->beginTransaction();
        $stmt = $pdo->query(
            'SELECT session_id, (last_seen >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE) AS active
             FROM admin_session_lock WHERE id = 1 FOR UPDATE'
        );
        $lock = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentLockKey = adminSessionLockKey();

        $legacyCurrentSession = !empty($lock['session_id']) && $lock['session_id'] === session_id();
        if (!empty($lock['active']) && !empty($lock['session_id'])
            && $lock['session_id'] !== $currentLockKey && !$legacyCurrentSession) {
            $pdo->rollBack();
            return false;
        }

        if (empty($_SESSION['auth_session_hardened'])) {
            if (!session_regenerate_id(true)) {
                $pdo->rollBack();
                error_log('Unable to regenerate the session ID while reserving the admin session.');
                return false;
            }
            $_SESSION['auth_session_hardened'] = true;
        }

        $stmt = $pdo->prepare(
            'UPDATE admin_session_lock
             SET session_id = :session_id, user_id = :user_id, last_seen = UTC_TIMESTAMP()
             WHERE id = 1'
        );
        $stmt->execute(['session_id' => adminSessionLockKey(), 'user_id' => $userId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Unable to reserve the admin session: ' . $e->getMessage());
        return false;
    }
}

/** Releases the administrator slot if it belongs to the current session. */
function releaseAdminSession(?string $sessionId = null): void
{
    global $pdo;

    $sessionId = $sessionId ?? session_id();
    if (empty($_SESSION['is_admin']) || $sessionId === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE admin_session_lock
             SET session_id = NULL, user_id = NULL, last_seen = NULL
             WHERE id = 1 AND (session_id = :session_key OR session_id = :legacy_session_id)'
        );
        $stmt->execute([
            'session_key' => adminSessionLockKey($sessionId),
            'legacy_session_id' => $sessionId,
        ]);
    } catch (Throwable $e) {
        error_log('Unable to release the admin session lock: ' . $e->getMessage());
    }
}

/** Refreshes the administrator lock while the administrator uses the site. */
function refreshAdminSession(): bool
{
    global $pdo;

    try {
        $stmt = $pdo->prepare(
            'UPDATE admin_session_lock
             SET last_seen = UTC_TIMESTAMP()
             WHERE id = 1 AND session_id = :session_id AND user_id = :user_id'
        );
        $stmt->execute([
            'session_id' => adminSessionLockKey(),
            'user_id' => (int)($_SESSION['user_id'] ?? 0),
        ]);
        if ($stmt->rowCount() === 1) {
            return true;
        }

        $stmt = $pdo->prepare(
            'UPDATE admin_session_lock
             SET session_id = :session_key, last_seen = UTC_TIMESTAMP()
             WHERE id = 1 AND session_id = :legacy_session_id AND user_id = :user_id
               AND last_seen >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE'
        );
        $stmt->execute([
            'session_key' => adminSessionLockKey(),
            'legacy_session_id' => session_id(),
            'user_id' => (int)($_SESSION['user_id'] ?? 0),
        ]);
        if ($stmt->rowCount() === 1) {
            return true;
        }

        $stmt = $pdo->prepare(
            'SELECT 1 FROM admin_session_lock
             WHERE id = 1 AND session_id = :session_id AND user_id = :user_id'
        );
        $stmt->execute([
            'session_id' => adminSessionLockKey(),
            'user_id' => (int)($_SESSION['user_id'] ?? 0),
        ]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Unable to refresh the admin session lock: ' . $e->getMessage());
        return false;
    }
}

/** Starts an authenticated session for a user ID. */
function loginUserById(int $id): bool
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    $isAdmin = !empty($row['is_admin']);
    if ($isAdmin && !reserveAdminSession($id)) {
        return false;
    }

    if (!$isAdmin) {
        releaseAdminSession();
        if (empty($_SESSION['auth_session_hardened'])) {
            if (!session_regenerate_id(true)) {
                error_log('Unable to regenerate the session ID during login.');
                return false;
            }
            $_SESSION['auth_session_hardened'] = true;
        }
    } else {
        $_SESSION['auth_session_hardened'] = true;
    }

    $_SESSION['user_id'] = $id;
    $_SESSION['is_admin'] = $isAdmin;
    $_SESSION['cart'] = [];
    return true;
}

/** Clears the authenticated session and session cookie. */
function logout(): void
{
    releaseAdminSession();
    $_SESSION['user_id'] = null;
    $_SESSION['is_admin'] = false;
    $_SESSION['cart'] = [];
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
        $stmt = $pdo->prepare('UPDATE users SET uname = :name, email = :email, password = :password WHERE id = :id');
        $ok = $stmt->execute(['uname' => $name, 'email' => $email, 'password' => $hash, 'id' => $id]);
    } else {
        $stmt = $pdo->prepare('UPDATE users SET uname = :name, email = :email WHERE id = :id');
        $ok = $stmt->execute(['uname' => $name, 'email' => $email, 'id' => $id]);
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
        header('Location: ' . withAuthContext('../login.php'));
        exit;
    }

    if (!refreshAdminSession()) {
        logout();
        header('Location: ' . withAuthContext('login.php?session_expired=1'));
        exit;
    }
}
