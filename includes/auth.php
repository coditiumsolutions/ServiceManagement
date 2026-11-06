<?php
/**
 * Application bootstrap + authentication.
 *
 * Every web page includes this file first. It loads configuration,
 * helpers and the database helper, starts a hardened session, sends
 * security headers and provides the login / logout / guard functions.
 *
 * Usage in a protected page:
 *     require_once __DIR__ . '/../includes/auth.php';
 *     require_login();
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/* ---------------------------------------------------------------------
 * Security headers and session start
 * ------------------------------------------------------------------- */

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    // Authenticated pages must not be cached by the browser or proxies.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function is_https_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_ABSOLUTE_TIMEOUT);

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,                       // session cookie (until browser closes)
        'path'     => BASE_URL === '' ? '/' : BASE_URL . '/',
        'domain'   => '',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

if (PHP_SAPI !== 'cli') {
    send_security_headers();
    start_secure_session();
}

/* ---------------------------------------------------------------------
 * Authentication state
 * ------------------------------------------------------------------- */

function is_logged_in(): bool
{
    return isset($_SESSION['auth_user_id']) && is_int($_SESSION['auth_user_id']);
}

/**
 * Currently logged-in user (from the session).
 *
 * @return array{uid: int, username: string, full_name: string, email: ?string, role: string}|null
 */
function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    return $_SESSION['auth_user'] ?? null;
}

/**
 * Guard for every protected page: redirects to the login page when the
 * visitor is not authenticated, the session expired, or the account was
 * deactivated. Also refreshes the "last activity" timestamp.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        remember_intended_url();
        redirect('auth/login.php');
    }

    $now = time();
    $lastActivity = (int) ($_SESSION['auth_last_activity'] ?? 0);
    $loginTime    = (int) ($_SESSION['auth_login_time'] ?? 0);

    if ($now - $lastActivity > SESSION_IDLE_TIMEOUT || $now - $loginTime > SESSION_ABSOLUTE_TIMEOUT) {
        end_user_session();
        start_secure_session();
        remember_intended_url();
        flash('warning', 'Your session expired due to inactivity. Please sign in again.');
        redirect('auth/login.php');
    }

    // Re-check the account so deactivated / deleted users lose access immediately.
    $statement = db()->prepare('SELECT is_active FROM admin_users WHERE uid = ? LIMIT 1');
    $statement->execute([$_SESSION['auth_user_id']]);
    $isActive = $statement->fetchColumn();

    if ($isActive === false || (int) $isActive !== 1) {
        end_user_session();
        start_secure_session();
        flash('danger', 'Your account is no longer active. Please contact the administrator.');
        redirect('auth/login.php');
    }

    $_SESSION['auth_last_activity'] = $now;
}

/**
 * Store the page the visitor wanted so we can return there after login.
 */
function remember_intended_url(): void
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && is_safe_local_url($requestUri)) {
        $_SESSION['intended_url'] = $requestUri;
    }
}

/* ---------------------------------------------------------------------
 * Brute-force throttling (per session)
 * ------------------------------------------------------------------- */

function login_lockout_remaining(): int
{
    $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
    return max(0, $lockedUntil - time());
}

function register_failed_login(): void
{
    $_SESSION['login_failed_attempts'] = (int) ($_SESSION['login_failed_attempts'] ?? 0) + 1;

    if ($_SESSION['login_failed_attempts'] >= LOGIN_MAX_ATTEMPTS) {
        $_SESSION['login_locked_until']    = time() + LOGIN_LOCKOUT_SECONDS;
        $_SESSION['login_failed_attempts'] = 0;
    }

    // Small fixed delay slows down automated guessing.
    usleep(500000);
}

function reset_login_throttle(): void
{
    unset($_SESSION['login_failed_attempts'], $_SESSION['login_locked_until']);
}

/* ---------------------------------------------------------------------
 * Login / logout
 * ------------------------------------------------------------------- */

/**
 * Verify credentials. Returns the user row on success, NULL on failure.
 * Uses a constant-work path so unknown usernames take as long as wrong passwords.
 *
 * @return array<string, mixed>|null
 */
function attempt_login(string $username, string $password): ?array
{
    $statement = db()->prepare(
        'SELECT uid, full_name, username, password_hash, email, role, is_active
           FROM admin_users
          WHERE username = ?
          LIMIT 1'
    );
    $statement->execute([$username]);
    $user = $statement->fetch();

    if ($user === false) {
        // Dummy verification to equalise response time.
        password_verify($password, '$2y$12$ljM6HYHor8ufCZN6MISrJeHJ4mL3j9D3nuZHceDco3Q8RsSRoSoIu');
        return null;
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        return null;
    }

    if ((int) $user['is_active'] !== 1) {
        return null;
    }

    // Transparently upgrade the hash when PHP's default algorithm changes.
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $rehash = db()->prepare('UPDATE admin_users SET password_hash = ? WHERE uid = ?');
        $rehash->execute([password_hash($password, PASSWORD_DEFAULT), $user['uid']]);
    }

    return $user;
}

/**
 * Establish an authenticated session for the given user.
 *
 * @param array<string, mixed> $user
 */
function login_user(array $user): void
{
    // Prevent session fixation: new ID, old session data file removed.
    session_regenerate_id(true);

    $now = time();
    $_SESSION['auth_user_id']       = (int) $user['uid'];
    $_SESSION['auth_user']          = [
        'uid'       => (int) $user['uid'],
        'username'  => (string) $user['username'],
        'full_name' => (string) $user['full_name'],
        'email'     => $user['email'] !== null ? (string) $user['email'] : null,
        'role'      => (string) $user['role'],
    ];
    $_SESSION['auth_login_time']    = $now;
    $_SESSION['auth_last_activity'] = $now;

    reset_login_throttle();
    regenerate_csrf_token();
}

/**
 * Completely destroy the session: data, server-side file and cookie.
 */
function end_user_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?: 'Lax',
        ]);
    }

    session_destroy();
}
