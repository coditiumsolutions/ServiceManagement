<?php
/**
 * Users — list application login accounts.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/user_ops.php';
require_admin();

$activeMenu   = 'users';
$pageTitle    = 'Users';
$pageSubtitle = 'Manage application login accounts, roles and access.';

$pdo            = db();
$search         = get_string('q', 100);
$roleFilter     = get_string('role', 30);
$statusFilter   = get_string('status', 20);
$currentUserId  = (int) (current_user()['uid'] ?? 0);

if ($roleFilter !== '' && !in_array($roleFilter, user_roles(), true)) {
    $roleFilter = '';
}
if ($statusFilter !== '' && !in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/users/index.php');
    }

    $action = post_string('action', 30);
    $userId = post_int('user_id');
    $target = $userId > 0 ? fetch_admin_user($pdo, $userId) : null;

    if ($target === null) {
        flash('danger', 'User not found.');
        redirect('modules/users/index.php');
    }

    try {
        if ($action === 'deactivate') {
            if ($userId === $currentUserId) {
                flash('warning', 'You cannot deactivate your own account.');
                redirect('modules/users/index.php');
            }
            $pdo->prepare('UPDATE admin_users SET is_active = 0 WHERE uid = ?')->execute([$userId]);
            regenerate_csrf_token();
            flash('success', 'User "' . $target['username'] . '" deactivated.');
            redirect('modules/users/index.php');
        }

        if ($action === 'activate') {
            $pdo->prepare('UPDATE admin_users SET is_active = 1 WHERE uid = ?')->execute([$userId]);
            regenerate_csrf_token();
            flash('success', 'User "' . $target['username'] . '" activated.');
            redirect('modules/users/index.php');
        }

        if ($action === 'delete') {
            if ($userId === $currentUserId) {
                flash('warning', 'You cannot delete your own account.');
                redirect('modules/users/index.php');
            }
            if ((int) $target['is_active'] === 1) {
                flash('warning', 'Deactivate the user before deleting.');
                redirect('modules/users/index.php');
            }
            $pdo->prepare('DELETE FROM admin_users WHERE uid = ? AND is_active = 0')->execute([$userId]);
            regenerate_csrf_token();
            flash('success', 'User "' . $target['username'] . '" deleted.');
            redirect('modules/users/index.php');
        }

        flash('warning', 'Unknown action.');
        redirect('modules/users/index.php');
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] user action failed: ' . $exception->getMessage());
        flash('danger', 'Could not update the user account.');
        redirect('modules/users/index.php');
    }
}

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(full_name LIKE ? OR username LIKE ? OR email LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($roleFilter !== '') {
    $where[] = 'role = ?';
    $params[] = $roleFilter;
}
if ($statusFilter === 'active') {
    $where[] = 'is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'is_active = 0';
}

$sql = 'SELECT uid, full_name, username, email, role, is_active, created_at, updated_at
          FROM admin_users
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY full_name ASC, username ASC
         LIMIT 300';

try {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] users list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load users right now.');
    $rows = [];
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-3">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Name, username, email">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="role">Role</label>
            <select class="form-select" id="role" name="role">
                <option value="">All</option>
                <?php foreach (user_roles() as $roleOption): ?>
                    <option value="<?= e($roleOption) ?>" <?= $roleFilter === $roleOption ? 'selected' : '' ?>>
                        <?= e(status_label($roleOption)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="<?= e(url('modules/users/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    <div>
        <a href="<?= e(url('modules/users/create.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add user
        </a>
    </div>
</div>

<div class="app-card card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $isSelf = (int) $row['uid'] === $currentUserId;
                        $isActive = (int) $row['is_active'] === 1;
                        ?>
                        <tr>
                            <td class="fw-semibold">
                                <?= e((string) $row['full_name']) ?>
                                <?php if ($isSelf): ?>
                                    <span class="badge badge-soft-info ms-1">You</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $row['username']) ?></td>
                            <td><?= e((string) ($row['email'] ?: '—')) ?></td>
                            <td><?= e(status_label((string) $row['role'])) ?></td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge badge-soft-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-soft-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?= e(format_datetime((string) $row['created_at'], 'd M Y')) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="theme-link-btn" href="<?= e(url('modules/users/edit.php?id=' . (int) $row['uid'])) ?>">Edit</a>
                                <?php if (!$isSelf && $isActive): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Deactivate this user?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="user_id" value="<?= (int) $row['uid'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-warning p-0 ms-2">Deactivate</button>
                                    </form>
                                <?php elseif (!$isSelf && !$isActive): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="user_id" value="<?= (int) $row['uid'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-success p-0 ms-2">Activate</button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete this inactive user?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?= (int) $row['uid'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-danger p-0 ms-2">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
