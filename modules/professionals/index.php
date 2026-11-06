<?php
/**
 * Service Professionals — list with search and status filters.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$activeMenu   = 'professionals';
$pageTitle    = 'Service Professionals';
$pageSubtitle = 'Manage labor / service professionals.';

$pdo          = db();
$search       = get_string('q', 100);
$typeFilter   = get_string('employment_type', 40);
$statusFilter = get_string('status', 20);
$categoryId   = get_int('category_id');

if ($typeFilter !== '' && !in_array($typeFilter, employment_types(), true)) {
    $typeFilter = '';
}
if ($statusFilter !== '' && !in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/professionals/index.php');
    }

    $action = post_string('action', 30);
    $id     = post_int('professional_id');
    $target = $id > 0 ? fetch_professional($pdo, $id) : null;

    if ($target === null) {
        flash('danger', 'Professional not found.');
        redirect('modules/professionals/index.php');
    }

    try {
        if ($action === 'deactivate') {
            $pdo->prepare(
                'UPDATE ServiceProfessionals
                    SET IsActive = 0, LeavingDate = COALESCE(LeavingDate, CURDATE())
                  WHERE uid = ?'
            )->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Professional deactivated.');
            redirect('modules/professionals/index.php');
        }
        if ($action === 'activate') {
            $pdo->prepare(
                'UPDATE ServiceProfessionals
                    SET IsActive = 1, LeavingDate = NULL
                  WHERE uid = ?'
            )->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Professional activated.');
            redirect('modules/professionals/index.php');
        }
        if ($action === 'delete') {
            if ((int) $target['IsActive'] === 1) {
                flash('warning', 'Deactivate the professional before deleting.');
                redirect('modules/professionals/index.php');
            }
            $pdo->prepare('DELETE FROM ServiceProfessionals WHERE uid = ? AND IsActive = 0')->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Professional deleted.');
            redirect('modules/professionals/index.php');
        }
        flash('warning', 'Unknown action.');
        redirect('modules/professionals/index.php');
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] professional action failed: ' . $exception->getMessage());
        if ($exception->getCode() === '23000') {
            flash('danger', 'Cannot delete this professional because related records exist. Keep them inactive instead.');
        } else {
            flash('danger', 'Could not update the professional.');
        }
        redirect('modules/professionals/index.php');
    }
}

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(sp.FullName LIKE ? OR sp.ProfessionalCode LIKE ? OR sp.MobileNo LIKE ? OR sp.CNIC LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($typeFilter !== '') {
    $where[] = 'sp.EmploymentType = ?';
    $params[] = $typeFilter;
}
if ($categoryId > 0) {
    $where[] = 'sp.PrimaryCategoryId = ?';
    $params[] = $categoryId;
}
if ($statusFilter === 'active') {
    $where[] = 'sp.IsActive = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'sp.IsActive = 0';
}

$sql = 'SELECT sp.uid, sp.ProfessionalCode, sp.FullName, sp.MobileNo, sp.Email, sp.City,
               sp.EmploymentType, sp.JoiningDate, sp.LeavingDate, sp.IsActive,
               sc.CategoryName
          FROM ServiceProfessionals sp
          LEFT JOIN ServiceCategories sc ON sc.uid = sp.PrimaryCategoryId
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY sp.FullName ASC
         LIMIT 300';

try {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] professionals list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load professionals right now.');
    $rows = [];
}

$categories = [];
try {
    $categories = active_service_categories($pdo);
} catch (PDOException $exception) {
    error_log('[ServiceManagement] professional categories failed: ' . $exception->getMessage());
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-3">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Name, code, mobile, CNIC">
        </div>
        <div class="col-md-2">
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
            <label class="form-label mb-1" for="employment_type">Employment</label>
            <select class="form-select" id="employment_type" name="employment_type">
                <option value="">All</option>
                <?php foreach (employment_types() as $typeOption): ?>
                    <option value="<?= e($typeOption) ?>" <?= $typeFilter === $typeOption ? 'selected' : '' ?>>
                        <?= e(status_label($typeOption)) ?>
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
            <a href="<?= e(url('modules/professionals/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    <div>
        <a href="<?= e(url('modules/professionals/create.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add professional
        </a>
    </div>
</div>

<div class="app-card card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Mobile</th>
                    <th>Category</th>
                    <th>Employment</th>
                    <th>Joined</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No professionals found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $isActive = (int) $row['IsActive'] === 1; ?>
                        <tr>
                            <td class="fw-semibold"><?= e((string) $row['ProfessionalCode']) ?></td>
                            <td>
                                <div class="fw-semibold"><?= e((string) $row['FullName']) ?></div>
                                <?php if (!empty($row['City'])): ?>
                                    <div class="small text-muted"><?= e((string) $row['City']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?= e((string) $row['MobileNo']) ?></td>
                            <td><?= e((string) ($row['CategoryName'] ?: '—')) ?></td>
                            <td><?= e(status_label((string) $row['EmploymentType'])) ?></td>
                            <td class="text-nowrap"><?= e(format_datetime((string) $row['JoiningDate'], 'd M Y')) ?></td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge badge-soft-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-soft-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="theme-link-btn" href="<?= e(url('modules/professionals/view.php?id=' . (int) $row['uid'])) ?>">Open</a>
                                <a class="theme-link-btn ms-2" href="<?= e(url('modules/professionals/edit.php?id=' . (int) $row['uid'])) ?>">Edit</a>
                                <?php if ($isActive): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Deactivate this professional?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="professional_id" value="<?= (int) $row['uid'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-warning p-0 ms-2">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="professional_id" value="<?= (int) $row['uid'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-success p-0 ms-2">Activate</button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete this inactive professional?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="professional_id" value="<?= (int) $row['uid'] ?>">
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
