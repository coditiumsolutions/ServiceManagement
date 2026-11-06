<?php
/**
 * Database configuration and the single shared PDO connection helper.
 *
 * Defaults match a standard XAMPP installation (root user, empty password).
 * Edit the defaults below, or override them with environment variables:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Read a database setting from the environment, falling back to a default.
 */
function db_setting(string $name, string $default): string
{
    $value = getenv($name);
    return ($value === false) ? $default : $value;
}

define('DB_HOST', db_setting('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int) db_setting('DB_PORT', '3306'));
define('DB_NAME', db_setting('DB_NAME', 'ServiceManagementDB'));
define('DB_USER', db_setting('DB_USER', 'root'));
define('DB_PASS', db_setting('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

/**
 * Return the shared PDO connection (created once per request).
 *
 * On failure the real error is written to the PHP error log and a generic
 * message is shown; host, username and password are never displayed.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Keep MySQL NOW()/CURRENT_TIMESTAMP aligned with the PHP timezone.
        $timezoneStatement = $pdo->prepare('SET time_zone = ?');
        $timezoneStatement->execute([date('P')]);
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] Database connection failed: ' . $exception->getMessage());
        db_connection_failed();
    }

    return $pdo;
}

/**
 * Stop the request with a generic, non-sensitive error message.
 */
function db_connection_failed(): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error: unable to connect to the database. Check config/database.php and the PHP error log.\n");
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Service unavailable</title></head>'
        . '<body style="font-family:system-ui,sans-serif;background:#f4f6fb;color:#1f2937;padding:48px;">'
        . '<div style="max-width:520px;margin:auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 4px 20px rgba(0,0,0,.06);">'
        . '<h1 style="font-size:20px;margin-top:0;">Database connection error</h1>'
        . '<p>The application could not connect to the database. Please try again later or contact the administrator.</p>'
        . '</div></body></html>';
    exit;
}
