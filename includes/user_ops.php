<?php
/**
 * Shared helpers for application user management (admin_users).
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

const USER_MIN_PASSWORD_LENGTH = 8;

/**
 * @return list<string>
 */
function user_roles(): array
{
    return ['admin', 'manager', 'staff'];
}

/**
 * Restrict sensitive user-management pages to administrators.
 */
function require_admin(): void
{
    require_login();
    $user = current_user();
    if ($user === null || (string) ($user['role'] ?? '') !== 'admin') {
        flash('danger', 'Only administrators can manage users.');
        redirect('dashboard/index.php');
    }
}

/**
 * @return array<string, mixed>|null
 */
function fetch_admin_user(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT uid, full_name, username, email, role, is_active, created_at, updated_at
           FROM admin_users
          WHERE uid = ?
          LIMIT 1'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

/**
 * Validate a new password. Returns a list of error messages (empty = ok).
 *
 * @return list<string>
 */
function validate_user_password(string $password, string $confirm = ''): array
{
    $errors = [];
    $length = strlen($password);

    if ($length < USER_MIN_PASSWORD_LENGTH || $length > 72) {
        $errors[] = 'Password must be between ' . USER_MIN_PASSWORD_LENGTH . ' and 72 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors[] = 'Password must contain at least one letter and one digit.';
    }
    if (!hash_equals($password, $confirm)) {
        $errors[] = 'Passwords do not match.';
    }

    return $errors;
}

function normalize_user_email(string $email): ?string
{
    $email = trim($email);
    return $email === '' ? null : $email;
}

/**
 * @return list<string>
 */
function validate_admin_user_fields(array $form, bool $requirePassword = false): array
{
    $errors = [];

    $fullName = trim((string) ($form['full_name'] ?? ''));
    $username = trim((string) ($form['username'] ?? ''));
    $email    = trim((string) ($form['email'] ?? ''));
    $role     = (string) ($form['role'] ?? '');
    $password = (string) ($form['password'] ?? '');
    $confirm  = (string) ($form['password_confirm'] ?? '');

    if ($fullName === '' || mb_strlen($fullName) > 150) {
        $errors[] = 'Full name is required (max 150 characters).';
    }
    if (!is_valid_username($username)) {
        $errors[] = 'Username must be 3–50 characters: letters, digits, dot, underscore, @ or hyphen.';
    }
    if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 150)) {
        $errors[] = 'Email address is not valid.';
    }
    if (!in_array($role, user_roles(), true)) {
        $errors[] = 'Please select a valid role.';
    }
    if ($requirePassword || $password !== '') {
        $errors = array_merge($errors, validate_user_password($password, $confirm));
    }

    return $errors;
}
