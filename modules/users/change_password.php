<?php
/**
 * Change Password — for the currently signed-in user.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/user_ops.php';
require_login();

$activeMenu   = 'change_password';
$pageTitle    = 'Change Password';
$pageSubtitle = 'Update the password for your own account.';

$pdo  = db();
$user = current_user();
if ($user === null) {
    redirect('auth/login.php');
}

$form = [
    'current_password' => '',
    'password'         => '',
    'password_confirm' => '',
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/users/change_password.php');
    }

    $form['current_password'] = (string) ($_POST['current_password'] ?? '');
    $form['password']         = (string) ($_POST['password'] ?? '');
    $form['password_confirm'] = (string) ($_POST['password_confirm'] ?? '');

    try {
        $statement = $pdo->prepare(
            'SELECT uid, password_hash, is_active FROM admin_users WHERE uid = ? LIMIT 1'
        );
        $statement->execute([(int) $user['uid']]);
        $row = $statement->fetch();

        if ($row === false || (int) $row['is_active'] !== 1) {
            flash('danger', 'Your account is no longer active.');
            redirect('auth/login.php');
        }

        if ($form['current_password'] === '' || !password_verify($form['current_password'], (string) $row['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
            usleep(400000);
        }

        $errors = array_merge($errors, validate_user_password($form['password'], $form['password_confirm']));

        if ($errors === [] && hash_equals($form['current_password'], $form['password'])) {
            $errors[] = 'New password must be different from the current password.';
        }

        if ($errors === []) {
            $update = $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE uid = ?');
            $update->execute([
                password_hash($form['password'], PASSWORD_DEFAULT),
                (int) $user['uid'],
            ]);
            regenerate_csrf_token();
            flash('success', 'Password changed successfully.');
            redirect('modules/users/change_password.php');
        }
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] change password failed: ' . $exception->getMessage());
        flash('danger', 'Could not change the password. Please try again.');
        redirect('modules/users/change_password.php');
    }

    $form['current_password'] = '';
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

<form method="post" class="app-card card" style="max-width: 560px;" autocomplete="off">
    <div class="card-header"><strong>Change your password</strong></div>
    <div class="card-body form-grid-blue">
        <?= csrf_field() ?>
        <div class="form-row-blue">
            <div class="form-field span-4">
                <label class="form-label" for="current_password">Current password <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="current_password" name="current_password"
                       required maxlength="72" autocomplete="current-password">
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-4">
                <label class="form-label" for="password">New password <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="password" name="password" required
                       minlength="<?= USER_MIN_PASSWORD_LENGTH ?>" maxlength="72" autocomplete="new-password">
                <div class="form-text">Min <?= USER_MIN_PASSWORD_LENGTH ?> characters, with at least one letter and one digit.</div>
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-4">
                <label class="form-label" for="password_confirm">Confirm new password <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="password_confirm" name="password_confirm" required
                       minlength="<?= USER_MIN_PASSWORD_LENGTH ?>" maxlength="72" autocomplete="new-password">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('dashboard/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i>Update password</button>
    </div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
