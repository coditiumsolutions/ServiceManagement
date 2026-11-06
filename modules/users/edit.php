<?php
/**
 * Edit an application user.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/user_ops.php';
require_admin();

$pdo = db();
$id  = get_int('id');
if ($id <= 0) {
    flash('danger', 'Invalid user.');
    redirect('modules/users/index.php');
}

$user = fetch_admin_user($pdo, $id);
if ($user === null) {
    flash('danger', 'User not found.');
    redirect('modules/users/index.php');
}

$currentUserId = (int) (current_user()['uid'] ?? 0);
$isSelf        = $id === $currentUserId;

$activeMenu   = 'users';
$pageTitle    = 'Edit User';
$pageSubtitle = 'Update account details for ' . (string) $user['username'] . '.';

$form = [
    'full_name'        => (string) $user['full_name'],
    'username'         => (string) $user['username'],
    'email'            => (string) ($user['email'] ?? ''),
    'role'             => (string) $user['role'],
    'is_active'        => (int) $user['is_active'] === 1 ? '1' : '0',
    'password'         => '',
    'password_confirm' => '',
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/users/edit.php?id=' . $id);
    }

    $form['full_name']        = post_string('full_name', 150);
    $form['username']         = post_string('username', 50);
    $form['email']            = post_string('email', 150);
    $form['role']             = post_string('role', 30);
    $form['is_active']        = post_string('is_active', 5) === '1' ? '1' : '0';
    $form['password']         = (string) ($_POST['password'] ?? '');
    $form['password_confirm'] = (string) ($_POST['password_confirm'] ?? '');

    if ($isSelf) {
        // Keep own account active and admin-capable if currently admin.
        $form['is_active'] = '1';
        if ((string) $user['role'] === 'admin') {
            $form['role'] = 'admin';
        }
    }

    $errors = validate_admin_user_fields($form, false);

    if ($errors === []) {
        try {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE username = ? AND uid <> ?');
            $dup->execute([trim($form['username']), $id]);
            if ((int) $dup->fetchColumn() > 0) {
                $errors[] = 'That username is already taken.';
            }
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] user edit duplicate check failed: ' . $exception->getMessage());
            $errors[] = 'Could not validate the username. Please try again.';
        }
    }

    if ($errors === []) {
        try {
            if ($form['password'] !== '') {
                $update = $pdo->prepare(
                    'UPDATE admin_users
                        SET full_name = ?, username = ?, email = ?, role = ?, is_active = ?, password_hash = ?
                      WHERE uid = ?'
                );
                $update->execute([
                    trim($form['full_name']),
                    trim($form['username']),
                    normalize_user_email($form['email']),
                    $form['role'],
                    (int) $form['is_active'],
                    password_hash($form['password'], PASSWORD_DEFAULT),
                    $id,
                ]);
            } else {
                $update = $pdo->prepare(
                    'UPDATE admin_users
                        SET full_name = ?, username = ?, email = ?, role = ?, is_active = ?
                      WHERE uid = ?'
                );
                $update->execute([
                    trim($form['full_name']),
                    trim($form['username']),
                    normalize_user_email($form['email']),
                    $form['role'],
                    (int) $form['is_active'],
                    $id,
                ]);
            }

            // Keep session profile in sync when editing yourself.
            if ($isSelf && isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user'])) {
                $_SESSION['auth_user']['full_name'] = trim($form['full_name']);
                $_SESSION['auth_user']['username']  = trim($form['username']);
                $_SESSION['auth_user']['email']     = normalize_user_email($form['email']);
                $_SESSION['auth_user']['role']      = $form['role'];
            }

            regenerate_csrf_token();
            flash('success', 'User updated.');
            redirect('modules/users/index.php');
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] user edit failed: ' . $exception->getMessage());
            if (str_contains($exception->getMessage(), 'UQ_admin_users_username') || $exception->getCode() === '23000') {
                $errors[] = 'That username is already taken.';
            } else {
                $errors[] = 'Could not update the user. Please try again.';
            }
        }
    }

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
        <strong>Edit user</strong>
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
                <select class="form-select" id="role" name="role" required <?= $isSelf && (string) $user['role'] === 'admin' ? 'disabled' : '' ?>>
                    <?php foreach (user_roles() as $roleOption): ?>
                        <option value="<?= e($roleOption) ?>" <?= $form['role'] === $roleOption ? 'selected' : '' ?>>
                            <?= e(status_label($roleOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($isSelf && (string) $user['role'] === 'admin'): ?>
                    <input type="hidden" name="role" value="admin">
                    <div class="form-text">You cannot change your own admin role.</div>
                <?php endif; ?>
            </div>
            <div class="form-field">
                <label class="form-label" for="is_active">Status</label>
                <?php if ($isSelf): ?>
                    <input type="text" class="form-control" value="Active" disabled>
                    <input type="hidden" name="is_active" value="1">
                    <div class="form-text">You cannot deactivate your own account.</div>
                <?php else: ?>
                    <select class="form-select" id="is_active" name="is_active">
                        <option value="1" <?= $form['is_active'] === '1' ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= $form['is_active'] === '0' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                <?php endif; ?>
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="password">New password</label>
                <input type="password" class="form-control" id="password" name="password"
                       minlength="<?= USER_MIN_PASSWORD_LENGTH ?>" maxlength="72" autocomplete="new-password">
                <div class="form-text">Leave blank to keep the current password.</div>
            </div>
            <div class="form-field span-2">
                <label class="form-label" for="password_confirm">Confirm new password</label>
                <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                       minlength="<?= USER_MIN_PASSWORD_LENGTH ?>" maxlength="72" autocomplete="new-password">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('modules/users/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save changes</button>
    </div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
