<?php
/**
 * Create a new application user.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/user_ops.php';
require_admin();

$activeMenu   = 'users';
$pageTitle    = 'Add User';
$pageSubtitle = 'Create a new login account.';

$pdo = db();
$form = [
    'full_name'        => '',
    'username'         => '',
    'email'            => '',
    'role'             => 'staff',
    'is_active'        => '1',
    'password'         => '',
    'password_confirm' => '',
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/users/create.php');
    }

    $form['full_name']        = post_string('full_name', 150);
    $form['username']         = post_string('username', 50);
    $form['email']            = post_string('email', 150);
    $form['role']             = post_string('role', 30);
    $form['is_active']        = post_string('is_active', 5) === '1' ? '1' : '0';
    $form['password']         = (string) ($_POST['password'] ?? '');
    $form['password_confirm'] = (string) ($_POST['password_confirm'] ?? '');

    $errors = validate_admin_user_fields($form, true);

    if ($errors === []) {
        try {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE username = ?');
            $dup->execute([$form['username']]);
            if ((int) $dup->fetchColumn() > 0) {
                $errors[] = 'That username is already taken.';
            }
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] user duplicate check failed: ' . $exception->getMessage());
            $errors[] = 'Could not validate the username. Please try again.';
        }
    }

    if ($errors === []) {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO admin_users (full_name, username, password_hash, email, role, is_active)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                trim($form['full_name']),
                trim($form['username']),
                password_hash($form['password'], PASSWORD_DEFAULT),
                normalize_user_email($form['email']),
                $form['role'],
                (int) $form['is_active'],
            ]);
            regenerate_csrf_token();
            flash('success', 'User "' . trim($form['username']) . '" created.');
            redirect('modules/users/index.php');
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] user create failed: ' . $exception->getMessage());
            if (str_contains($exception->getMessage(), 'UQ_admin_users_username') || $exception->getCode() === '23000') {
                $errors[] = 'That username is already taken.';
            } else {
                $errors[] = 'Could not create the user. Please try again.';
            }
        }
    }

    // Never redisplay passwords.
    $form['password'] = '';
    $form['password_confirm'] = '';
}

require __DIR__ . '/../../includes/header.php';
?>
<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="app-card card" autocomplete="off">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>New user</strong>
        <a href="<?= e(url('modules/users/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Back to list</a>
    </div>
    <div class="card-body form-grid-blue">
        <?= csrf_field() ?>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="full_name">Full name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="full_name" name="full_name"
                       value="<?= e($form['full_name']) ?>" maxlength="150" required>
            </div>
            <div class="form-field span-2">
                <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="username" name="username"
                       value="<?= e($form['username']) ?>" maxlength="50" required
                       pattern="[A-Za-z0-9._@\-]{3,50}" autocomplete="off">
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="email">Email</label>
                <input type="email" class="form-control" id="email" name="email"
                       value="<?= e($form['email']) ?>" maxlength="150">
            </div>
            <div class="form-field">
                <label class="form-label" for="role">Role <span class="text-danger">*</span></label>
                <select class="form-select" id="role" name="role" required>
                    <?php foreach (user_roles() as $roleOption): ?>
                        <option value="<?= e($roleOption) ?>" <?= $form['role'] === $roleOption ? 'selected' : '' ?>>
                            <?= e(status_label($roleOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="is_active">Status</label>
                <select class="form-select" id="is_active" name="is_active">
                    <option value="1" <?= $form['is_active'] === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $form['is_active'] === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="password">Password <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="password" name="password" required
                       minlength="<?= USER_MIN_PASSWORD_LENGTH ?>" maxlength="72" autocomplete="new-password">
                <div class="form-text">Min <?= USER_MIN_PASSWORD_LENGTH ?> characters, with at least one letter and one digit.</div>
            </div>
            <div class="form-field span-2">
                <label class="form-label" for="password_confirm">Confirm password <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="password_confirm" name="password_confirm" required
                       minlength="<?= USER_MIN_PASSWORD_LENGTH ?>" maxlength="72" autocomplete="new-password">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('modules/users/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Create user</button>
    </div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
