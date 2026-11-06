<?php
/**
 * Application configuration (non-database settings).
 *
 * Database settings live separately in config/database.php.
 * Any value below can be overridden with an environment variable
 * (useful for servers / containers) without editing this file.
 */
declare(strict_types=1);

$__deployEnv = __DIR__ . '/deploy_env.php';
if (is_file($__deployEnv)) {
    require $__deployEnv;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/** Absolute filesystem path of the project root (no trailing slash). */
define('APP_ROOT', dirname(__DIR__));

/** Application display name. */
define('APP_NAME', getenv('APP_NAME') ?: 'Services Management');

/** Short name used in compact places (mobile header, page titles). */
define('APP_SHORT_NAME', getenv('APP_SHORT_NAME') ?: 'Services');

/** Application version for cache-busting assets. */
define('APP_VERSION', '0.5.17');

/**
 * Debug mode. Keep FALSE in production: errors are logged, never displayed.
 * Enable for local development with the environment variable APP_DEBUG=1.
 */
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN));

/** Default timezone for PHP date functions and the MySQL session. */
define('APP_TIMEZONE', getenv('APP_TIMEZONE') ?: 'Asia/Karachi');

/** Currency used when formatting money. */
define('APP_CURRENCY', getenv('APP_CURRENCY') ?: 'PKR');

/**
 * Base URL path of the application, WITHOUT trailing slash.
 *
 * Examples:
 *   '/ServiceManagement'   -> http://localhost/ServiceManagement/
 *   ''                     -> application served from the web root
 *
 * Leave as NULL to auto-detect it from the document root, or set APP_BASE_URL.
 */
$configuredBaseUrl = getenv('APP_BASE_URL');
define('APP_BASE_URL_SETTING', $configuredBaseUrl === false ? null : $configuredBaseUrl);

/** Session settings. */
define('SESSION_NAME', 'SMS_SESSID');
define('SESSION_IDLE_TIMEOUT', 1800);      // seconds of inactivity before automatic logout (30 min)
define('SESSION_ABSOLUTE_TIMEOUT', 43200); // maximum session lifetime in seconds (12 hours)

/** Login brute-force throttling (per session). */
define('LOGIN_MAX_ATTEMPTS', 5);           // failed attempts before temporary lock
define('LOGIN_LOCKOUT_SECONDS', 300);      // lock duration (5 min)

/* ---------------------------------------------------------------------
 * Runtime settings derived from the configuration above.
 * ------------------------------------------------------------------- */
date_default_timezone_set(APP_TIMEZONE);
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

/**
 * Resolve the base URL: explicit setting first, otherwise derive it from
 * the web server document root (works for XAMPP htdocs/ServiceManagement).
 */
function app_detect_base_url(): string
{
    if (APP_BASE_URL_SETTING !== null) {
        return rtrim(APP_BASE_URL_SETTING, '/');
    }

    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $appRoot      = realpath(APP_ROOT);

    if ($documentRoot && $appRoot) {
        $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $appRoot      = rtrim(str_replace('\\', '/', $appRoot), '/');

        if (strncasecmp($appRoot, $documentRoot, strlen($documentRoot)) === 0) {
            $relative = substr($appRoot, strlen($documentRoot));
            return rtrim('/' . ltrim($relative, '/'), '/');
        }
    }

    // Fallback for XAMPP default install location.
    return '/ServiceManagement';
}

define('BASE_URL', app_detect_base_url());
