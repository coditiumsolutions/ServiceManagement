<?php
/**
 * ONE-TIME administrator creation script (command line only).
 *
 * Run from the project folder:
 *     php auth/create_admin.php
 *
 * Optional arguments (any missing value is prompted for):
 *     --full-name="System Administrator" --username=admin --email=admin@example.com
 * The password is ALWAYS prompted for (hidden where the terminal allows),
 * or read from standard input when input is piped, so it never appears in
 * shell history or the process list.
 *
 * Safety rules:
 *   - Refuses to run over HTTP (returns 403). It is not a registration page.
 *   - Creates the admin_users table if it does not exist (sql/admin_users.sql).
 *   - Refuses if ANY administrator account already exists.
 *   - Refuses a duplicate username.
 *
 * After creating the first administrator, DELETE this file (recommended)
 * or move it outside the web root. See README.md.
 */
declare(strict_types=1);

// --- Block every web request --------------------------------------------
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("403 Forbidden\n");
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

const MIN_PASSWORD_LENGTH = 10;

/** Print a message and stop with an exit code. */
function cli_fail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, "\n[ERROR] " . $message . "\n");
    exit($exitCode);
}

function cli_line(string $message = ''): void
{
    fwrite(STDOUT, $message . "\n");
}

/** Read one line from STDIN (prompt shown only when interactive). */
function cli_prompt(string $label, bool $hidden = false): string
{
    $interactive = stream_isatty(STDIN);

    if ($interactive) {
        fwrite(STDOUT, $label . ': ');
    }

    $canHide = $hidden && $interactive && DIRECTORY_SEPARATOR === '/' && trim((string) shell_exec('command -v stty')) !== '';
    if ($canHide) {
        shell_exec('stty -echo');
    }

    $line = fgets(STDIN);

    if ($canHide) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    if ($line === false) {
        cli_fail('No input received for "' . $label . '".');
    }

    return rtrim($line, "\r\n");
}

/** Ensure admin_users exists by running sql/admin_users.sql statement by statement. */
function ensure_admin_users_table(PDO $pdo): void
{
    $sqlFile = APP_ROOT . '/sql/admin_users.sql';
    $sql     = @file_get_contents($sqlFile);

    if ($sql === false) {
        cli_fail('Cannot read ' . $sqlFile);
    }

    // Drop comment lines and the USE statement (the PDO connection already selects the database).
    $lines = array_filter(
        preg_split('/\R/', $sql) ?: [],
        static fn (string $line): bool => !preg_match('/^\s*(--|USE\s)/i', $line)
    );

    foreach (explode(';', implode("\n", $lines)) as $statementSql) {
        if (trim($statementSql) !== '') {
            $pdo->prepare($statementSql)->execute();
        }
    }
}

/** @return array<string, string> */
function parse_cli_options(): array
{
    $options = getopt('', ['full-name:', 'username:', 'email:', 'help']);
    if (isset($options['help'])) {
        cli_line('Usage: php auth/create_admin.php [--full-name="Name"] [--username=admin] [--email=you@example.com]');
        cli_line('The password is always prompted for (or read from piped standard input).');
        exit(0);
    }
    return array_map(static fn ($value): string => is_array($value) ? (string) end($value) : (string) $value, $options);
}

// --- Main -----------------------------------------------------------------
cli_line('==============================================');
cli_line(' ' . APP_NAME . ' - Create first administrator');
cli_line('==============================================');

$options = parse_cli_options();
$pdo     = db();

ensure_admin_users_table($pdo);

// Early, friendly check (repeated inside the transaction below).
$existingStatement = $pdo->prepare('SELECT COUNT(*) FROM admin_users');
$existingStatement->execute();
$existingCount = (int) $existingStatement->fetchColumn();
if ($existingCount > 0) {
    cli_fail('An administrator account already exists. This one-time script will not create another. '
        . 'Delete auth/create_admin.php now.', 2);
}

$fullName = trim($options['full-name'] ?? cli_prompt('Full name'));
$username = trim($options['username'] ?? cli_prompt('Username (3-50 chars: letters, digits, . _ @ -)'));
$email    = trim($options['email'] ?? cli_prompt('Email (optional, press Enter to skip)'));
$password = cli_prompt('Password (min ' . MIN_PASSWORD_LENGTH . ' chars, letters and digits)', true);
$confirm  = cli_prompt('Confirm password', true);

// --- Validation ------------------------------------------------------------
$errors = [];

if ($fullName === '' || mb_strlen($fullName) > 150) {
    $errors[] = 'Full name is required (max 150 characters).';
}
if (!is_valid_username($username)) {
    $errors[] = 'Username must be 3-50 characters: letters, digits, dot, underscore, @ or hyphen.';
}
if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 150)) {
    $errors[] = 'Email address is not valid.';
}
if (strlen($password) < MIN_PASSWORD_LENGTH || strlen($password) > 72) {
    $errors[] = 'Password must be between ' . MIN_PASSWORD_LENGTH . ' and 72 characters.';
}
if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
    $errors[] = 'Password must contain at least one letter and one digit.';
}
if (!hash_equals($password, $confirm)) {
    $errors[] = 'Passwords do not match.';
}

if ($errors !== []) {
    cli_fail("Administrator not created:\n  - " . implode("\n  - ", $errors));
}

// --- Create the account inside a transaction ----------------------------------
try {
    $pdo->beginTransaction();

    // Lock the table rows so two simultaneous runs cannot both succeed.
    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM admin_users FOR UPDATE');
    $countStatement->execute();
    if ((int) $countStatement->fetchColumn() > 0) {
        $pdo->rollBack();
        cli_fail('An administrator account already exists. Nothing was created.', 2);
    }

    $duplicateStatement = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE username = ?');
    $duplicateStatement->execute([$username]);
    if ((int) $duplicateStatement->fetchColumn() > 0) {
        $pdo->rollBack();
        cli_fail('The username "' . $username . '" is already taken.', 2);
    }

    $insertStatement = $pdo->prepare(
        'INSERT INTO admin_users (full_name, username, password_hash, email, role, is_active)
         VALUES (?, ?, ?, ?, ?, 1)'
    );
    $insertStatement->execute([
        $fullName,
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        $email !== '' ? $email : null,
        'admin',
    ]);

    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[ServiceManagement] create_admin failed: ' . $exception->getMessage());
    if ($exception->getCode() === '23000') {
        cli_fail('The username "' . $username . '" is already taken.', 2);
    }
    cli_fail('Could not create the administrator because of a database error. See the PHP error log.');
}

cli_line();
cli_line('[OK] Administrator "' . $username . '" created successfully.');
cli_line('     Sign in at: http://localhost' . (APP_BASE_URL_SETTING ?? '/ServiceManagement') . '/auth/login.php');
cli_line();
cli_line('IMPORTANT: delete auth/create_admin.php now (or move it outside the web root).');
exit(0);
