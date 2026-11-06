<?php
/**
 * Service Categories — list with search and status filters.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$activeMenu   = 'service_categories';
$pageTitle    = 'Service Categories';
$pageSubtitle = 'Manage service categories used by service types.';

$pdo          = db();
$search       = get_string('q', 100);
$statusFilter = get_string('status', 20);

if ($statusFilter !== '' && !in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/service_categories/index.php');
    }

    $action = post_string('action', 30);
    $id     = post_int('category_id');
    $target = $id > 0 ? fetch_service_category($pdo, $id) : null;

    if ($target === null) {
        flash('danger', 'Category not found.');
        redirect('modules/service_categories/index.php');
    }

    try {
        if ($action === 'deactivate') {
            $pdo->prepare('UPDATE ServiceCategories SET IsActive = 0 WHERE uid = ?')->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Category deactivated.');
            redirect('modules/service_categories/index.php');
        }
        if ($action === 'activate') {
            $pdo->prepare('UPDATE ServiceCategories SET IsActive = 1 WHERE uid = ?')->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Category activated.');
            redirect('modules/service_categories/index.php');
        }
        if ($action === 'delete') {
            if ((int) $target['IsActive'] === 1) {
                flash('warning', 'Deactivate the category before deleting.');
                redirect('modules/service_categories/index.php');
            }
            $pdo->prepare('DELETE FROM ServiceCategories WHERE uid = ? AND IsActive = 0')->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Category deleted.');
            redirect('modules/service_categories/index.php');
        }
        flash('warning', 'Unknown action.');
        redirect('modules/service_categories/index.php');
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] category action failed: ' . $exception->getMessage());
        if ($exception->getCode() === '23000') {
            flash('danger', 'Cannot delete this category because related service types exist. Keep it inactive instead.');
        } else {
            flash('danger', 'Could not update the category.');
        }
        redirect('modules/service_categories/index.php');
    }
}

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(sc.CategoryName LIKE ? OR sc.Description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
}
if ($statusFilter === 'active') {
    $where[] = 'sc.IsActive = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'sc.IsActive = 0';
}

$sql = 'SELECT sc.uid, sc.CategoryName, sc.Description, sc.IsActive, sc.created_at,
               (SELECT COUNT(*) FROM ServiceTypes st WHERE st.CategoryId = sc.uid) AS TypeCount
          FROM ServiceCategories sc
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY sc.CategoryName ASC
         LIMIT 300';

try {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] categories list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load categories right now.');
    $rows = [];
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-4">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Category name or description">
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
            <a href="<?= e(url('modules/service_categories/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    <div>
        <a href="<?= e(url('modules/service_categories/create.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add category
        </a>
    </div>
</div>

<div class="app-card card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Category</th>
                    <th>Description</th>
                    <th class="text-end">Types</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No categories found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $isActive = (int) $row['IsActive'] === 1; ?>
                        <tr>
                            <td class="fw-semibold"><?= e((string) $row['CategoryName']) ?></td>
                            <td><?= e((string) ($row['Description'] ?: '—')) ?></td>
                            <td class="text-end">
                                <a class="theme-link" href="<?= e(url('modules/service_types/index.php?category_id=' . (int) $row['uid'])) ?>">
                                    <?= (int) $row['TypeCount'] ?>
                                </a>
                            </td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge badge-soft-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-soft-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?= e(format_datetime((string) $row['created_at'], 'd M Y')) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="theme-link-btn" href="<?= e(url('modules/service_categories/edit.php?id=' . (int) $row['uid'])) ?>">Edit</a>
                                <a class="theme-link-btn ms-2" href="<?= e(url('modules/service_types/create.php?category_id=' . (int) $row['uid'])) ?>">Add type</a>
                                <?php if ($isActive): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Deactivate this category?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="category_id" value="<?= (int) $row['uid'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-warning p-0 ms-2">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="category_id" value="<?= (int) $row['uid'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-success p-0 ms-2">Activate</button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete this inactive category?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="category_id" value="<?= (int) $row['uid'] ?>">
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
