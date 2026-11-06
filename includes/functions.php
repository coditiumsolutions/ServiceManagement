<?php
/**
 * Shared helper functions: output escaping, URLs, CSRF, flash messages,
 * formatting, schema checks and the sidebar menu definition.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/* ---------------------------------------------------------------------
 * Output escaping
 * ------------------------------------------------------------------- */

/**
 * Escape a value for safe HTML output (wrapper around htmlspecialchars).
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------------------------------------------------------------------
 * URLs and redirects
 * ------------------------------------------------------------------- */

/**
 * Build an application URL from a path relative to the project root.
 */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Build a URL for a static asset with a cache-busting version.
 */
function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/')) . '?v=' . rawurlencode(APP_VERSION);
}

/**
 * Redirect to an application path (or a validated local URL) and stop.
 */
function redirect(string $path): never
{
    $target = str_starts_with($path, '/') ? $path : url($path);
    header('Location: ' . $target, true, 302);
    exit;
}

/**
 * Accept only same-site relative URLs inside the application (prevents open redirects).
 */
function is_safe_local_url(string $candidate): bool
{
    if ($candidate === '' || !str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
        return false;
    }
    if (preg_match('/[\r\n\\\\]/', $candidate)) {
        return false;
    }
    $base = BASE_URL === '' ? '/' : BASE_URL . '/';
    return str_starts_with($candidate, $base);
}

/**
 * Current request path (without query string), used for the active menu.
 */
function current_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return is_string($path) ? $path : '/';
}

/* ---------------------------------------------------------------------
 * Request helpers and input sanitising
 * ------------------------------------------------------------------- */

function is_post_request(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Read a trimmed string from POST data, stripping control characters.
 */
function post_string(string $key, int $maxLength = 255): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
    return mb_substr($value, 0, $maxLength);
}

/**
 * Read a raw string (e.g. a password) from POST data without altering it.
 */
function post_raw(string $key, int $maxLength = 255): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    return mb_substr($value, 0, $maxLength);
}

/**
 * Read a non-negative integer from POST data.
 */
function post_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    if (!is_numeric($value)) {
        return $default;
    }
    return (int) $value;
}

/**
 * Read a non-negative decimal from POST data.
 */
function post_float(string $key, float $default = 0.0): float
{
    $value = $_POST[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    if (!is_numeric($value)) {
        return $default;
    }
    return round((float) $value, 2);
}

/**
 * Read a trimmed string from GET data.
 */
function get_string(string $key, int $maxLength = 255): string
{
    $value = $_GET[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
    return mb_substr($value, 0, $maxLength);
}

/**
 * Read an integer from GET data.
 */
function get_int(string $key, int $default = 0): int
{
    $value = $_GET[$key] ?? null;
    if ($value === null || $value === '' || !is_numeric($value)) {
        return $default;
    }
    return (int) $value;
}

/**
 * Username rule shared by login and admin creation.
 */
function is_valid_username(string $username): bool
{
    return (bool) preg_match('/^[A-Za-z0-9._@-]{3,50}$/', $username);
}

/**
 * Best-effort client IP address (for logging only).
 */
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/* ---------------------------------------------------------------------
 * CSRF protection
 * ------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Validate the CSRF token submitted with a POST request.
 */
function verify_csrf_token(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return is_string($submittedToken)
        && is_string($sessionToken)
        && $sessionToken !== ''
        && hash_equals($sessionToken, $submittedToken);
}

function regenerate_csrf_token(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ---------------------------------------------------------------------
 * Flash messages (shown once on the next page)
 * ------------------------------------------------------------------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash_messages'][] = ['type' => $type, 'message' => $message];
}

/**
 * @return array<int, array{type: string, message: string}>
 */
function pull_flash_messages(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return is_array($messages) ? $messages : [];
}

/* ---------------------------------------------------------------------
 * Formatting
 * ------------------------------------------------------------------- */

function format_money(float|int|string|null $amount): string
{
    return APP_CURRENCY . ' ' . number_format((float) $amount, 2);
}

function format_number(int|float|string|null $number): string
{
    return number_format((float) $number);
}

function format_datetime(?string $value, string $format = 'd M Y, h:i A'): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '-' : date($format, $timestamp);
}

/**
 * Human-friendly label for an enum status (e.g. InProgress -> In Progress).
 */
function status_label(string $status): string
{
    return trim((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $status));
}

/**
 * Bootstrap badge HTML for request / assignment / payment statuses.
 */
function status_badge(string $status): string
{
    $classes = [
        'Pending'       => 'badge-soft-warning',
        'Assigned'      => 'badge-soft-info',
        'Accepted'      => 'badge-soft-info',
        'InProgress'    => 'badge-soft-primary',
        'Completed'     => 'badge-soft-success',
        'Paid'          => 'badge-soft-success',
        'Approved'      => 'badge-soft-primary',
        'PartiallyPaid' => 'badge-soft-warning',
        'Draft'         => 'badge-soft-secondary',
        'Reassigned'    => 'badge-soft-secondary',
        'Cancelled'     => 'badge-soft-danger',
    ];
    $class = $classes[$status] ?? 'badge-soft-secondary';
    return '<span class="badge status-badge ' . $class . '">' . e(status_label($status)) . '</span>';
}

/* ---------------------------------------------------------------------
 * Schema helpers
 * ------------------------------------------------------------------- */

/**
 * Check (and cache per request) whether a table exists in the current database.
 */
function table_exists(string $tableName): bool
{
    static $cache = [];

    if (!array_key_exists($tableName, $cache)) {
        $statement = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$tableName]);
        $cache[$tableName] = ((int) $statement->fetchColumn()) > 0;
    }

    return $cache[$tableName];
}

/**
 * Return the subset of the given tables that do not exist.
 *
 * @param string[] $tableNames
 * @return string[]
 */
function missing_tables(array $tableNames): array
{
    return array_values(array_filter($tableNames, static fn (string $name): bool => !table_exists($name)));
}

/* ---------------------------------------------------------------------
 * Navigation
 * ------------------------------------------------------------------- */

/**
 * Sidebar menu definition: section => list of items.
 * Each item: key, label, icon (Bootstrap Icons), path (relative to project root).
 * Sections with more than one item (or marked collapsible) render as expand/collapse groups.
 *
 * @return array<string, array<int, array{key: string, label: string, icon: string, path: string}>>
 */
function sidebar_menu(): array
{
    return [
        'Dashboard' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'path' => 'dashboard/index.php'],
        ],
        'Services' => [
            ['key' => 'service_requests',    'label' => 'Customer Requests',    'icon' => 'bi-clipboard-plus',  'path' => 'modules/service_requests/index.php'],
            ['key' => 'service_assignments', 'label' => 'Service Assignments',  'icon' => 'bi-person-check',    'path' => 'modules/service_assignments/index.php'],
            ['key' => 'in_progress',         'label' => 'In Progress Services', 'icon' => 'bi-hourglass-split', 'path' => 'modules/service_requests/in_progress.php'],
            ['key' => 'completed',           'label' => 'Completed Services',   'icon' => 'bi-check2-circle',   'path' => 'modules/service_requests/completed.php'],
        ],
        'Labor & Salary' => [
            ['key' => 'salary_history',      'label' => 'Salary Packages',      'icon' => 'bi-clock-history',   'path' => 'modules/salary/index.php'],
            ['key' => 'payroll',             'label' => 'Salary Payment',       'icon' => 'bi-calendar-check',  'path' => 'modules/payroll/index.php'],
            ['key' => 'commission_entries',  'label' => 'Commission Entries',   'icon' => 'bi-percent',         'path' => 'modules/commissions/index.php'],
            ['key' => 'professional_ledger', 'label' => 'Professional Ledger',  'icon' => 'bi-journal-text',    'path' => 'modules/commissions/ledger.php'],
        ],
        'Accounts' => [
            ['key' => 'customer_payments', 'label' => 'Customer Payments',        'icon' => 'bi-credit-card',     'path' => 'modules/payments/index.php'],
            ['key' => 'expenses',          'label' => 'Expenses',                 'icon' => 'bi-receipt',         'path' => 'modules/expenses/index.php'],
            ['key' => 'reports',           'label' => 'Income & Expense Reports', 'icon' => 'bi-bar-chart-line',  'path' => 'modules/expenses/reports.php'],
        ],
        'Administrations' => [
            ['key' => 'users',           'label' => 'Users',           'icon' => 'bi-person-gear', 'path' => 'modules/users/index.php'],
            ['key' => 'change_password', 'label' => 'Change Password', 'icon' => 'bi-key',         'path' => 'modules/users/change_password.php'],
            ['key' => 'logout',          'label' => 'Logout',          'icon' => 'bi-box-arrow-right', 'path' => 'auth/logout.php'],
        ],
        'Modules' => [
            ['key' => 'customers',          'label' => 'Customers',             'icon' => 'bi-people',          'path' => 'modules/customers/index.php'],
            ['key' => 'service_categories', 'label' => 'Service Categories',    'icon' => 'bi-tags',            'path' => 'modules/service_categories/index.php'],
            ['key' => 'service_types',      'label' => 'Service Types',         'icon' => 'bi-tools',           'path' => 'modules/service_types/index.php'],
            ['key' => 'professionals',      'label' => 'Service Professionals', 'icon' => 'bi-person-badge',    'path' => 'modules/professionals/index.php'],
        ],
    ];
}

/**
 * Icons used for collapsible sidebar group toggles.
 *
 * @return array<string, string>
 */
function sidebar_group_icons(): array
{
    return [
        'Modules'            => 'bi-grid-1x2',
        'Services'            => 'bi-briefcase',
        'Labor & Salary'     => 'bi-cash-coin',
        'Accounts'           => 'bi-bank',
        'Administrations'    => 'bi-gear',
    ];
}

/**
 * Find a menu item by its key.
 *
 * @return array{key: string, label: string, icon: string, path: string}|null
 */
function find_menu_item(string $key): ?array
{
    foreach (sidebar_menu() as $items) {
        foreach ($items as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }
    }
    return null;
}
