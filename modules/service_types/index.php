<?php
/**
 * Service Types — list with search and filters.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$activeMenu   = 'service_types';
$pageTitle    = 'Service Types';
$pageSubtitle = 'Manage service types, base rates and durations.';

$pdo          = db();
$search       = get_string('q', 100);
$statusFilter = get_string('status', 20);
$categoryId   = get_int('category_id');

if ($statusFilter !== '' && !in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/service_types/index.php');
    }

    $action = post_string('action', 30);
    $id     = post_int('type_id');
    $target = $id > 0 ? fetch_service_type($pdo, $id) : null;

    if ($target === null) {
        flash('danger', 'Service type not found.');
        redirect('modules/service_types/index.php');
    }

    try {
        if ($action === 'deactivate') {
            $pdo->prepare('UPDATE ServiceTypes SET IsActive = 0 WHERE uid = ?')->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Service type deactivated.');
            redirect('modules/service_types/index.php');
        }
        if ($action === 'activate') {
            $pdo->prepare('UPDATE ServiceTypes SET IsActive = 1 WHERE uid = ?')->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Service type activated.');
            redirect('modules/service_types/index.php');
        }
        if ($action === 'delete') {
            if ((int) $target['IsActive'] === 1) {
                flash('warning', 'Deactivate the service type before deleting.');
                redirect('modules/service_types/index.php');
            }
            $pdo->prepare('DELETE FROM ServiceTypes WHERE uid = ? AND IsActive = 0')->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Service type deleted.');
            redirect('modules/service_types/index.php');
        }
        flash('warning', 'Unknown action.');
        redirect('modules/service_types/index.php');
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] service type action failed: ' . $exception->getMessage());
        if ($exception->getCode() === '23000') {
            flash('danger', 'Cannot delete this service type because related records exist. Keep it inactive instead.');
        } else {
            flash('danger', 'Could not update the service type.');
        }
        redirect('modules/service_types/index.php');
    }
}

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(st.ServiceName LIKE ? OR st.Description LIKE ? OR sc.CategoryName LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($categoryId > 0) {
    $where[] = 'st.CategoryId = ?';
    $params[] = $categoryId;
}
if ($statusFilter === 'active') {
    $where[] = 'st.IsActive = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'st.IsActive = 0';
}

$sql = 'SELECT st.uid, st.ServiceName, st.Description, st.BasePrice, st.EstimatedDurationMinutes,
               st.IsActive, st.created_at, sc.CategoryName, sc.uid AS CategoryId
          FROM ServiceTypes st
          INNER JOIN ServiceCategories sc ON sc.uid = st.CategoryId
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY sc.CategoryName ASC, st.ServiceName ASC
         LIMIT 300';

try {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] service types list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load service types right now.');
    $rows = [];
}

$categories = [];
try {
    $categories = active_service_categories($pdo);
} catch (PDOException $exception) {
    error_log('[ServiceManagement] service type categories failed: ' . $exception->getMessage());
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-3">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Service name, category, description">
        </div>
        <div class="col-md-3">
            <label class="form-label mb-1" for="category_id">Category</label>
            <select class="form-select" id="category_id" name="category_id">
                <option value="">All</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['uid'] ?>" <?= $categoryId === (int) $category['uid'] ? 'selected' : '' ?>>
                        <?= e((string) $category['CategoryName']) ?>
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
            <a href="<?= e(url('modules/service_types/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    <div>
        <a href="<?= e(url('modules/service_types/create.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add service type
        </a>
    </div>
</div>

<div class="app-card card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Service</th>
                    <th>Category</th>
                    <th class="text-end">Base price</th>
                    <th class="text-end">Duration</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No service types found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $isActive = (int) $row['IsActive'] === 1; ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e((string) $row['ServiceName']) ?></div>
                                <?php if (!empty($row['Description'])): ?>
                                    <div class="small text-muted"><?= e((string) $row['Description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $row['CategoryName']) ?></td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['BasePrice'])) ?></td>
                            <td class="text-end text-nowrap">
                                <?= $row['EstimatedDurationMinutes'] !== null
                                    ? e((string) (int) $row['EstimatedDurationMinutes'] . ' min')
                                    : '—' ?>
                            </td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge badge-soft-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-soft-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="theme-link-btn" href="<?= e(url('modules/service_types/edit.php?id=' . (int) $row['uid'])) ?>">Edit</a>
                                <?php if ($isActive): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Deactivate this service type?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="type_id" value="<?= (int) $row['uid'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-warning p-0 ms-2">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="type_id" value="<?= (int) $row['uid'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-success p-0 ms-2">Activate</button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete this inactive service type?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="type_id" value="<?= (int) $row['uid'] ?>">
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
