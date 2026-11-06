<?php
/**
 * Service assignments list.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$activeMenu   = 'service_assignments';
$pageTitle    = 'Service Assignments';
$pageSubtitle = 'Assign professionals to customer requests.';

$search       = get_string('q', 100);
$statusFilter = get_string('status', 20);
if ($statusFilter !== '' && !in_array($statusFilter, assignment_statuses(), true)) {
    $statusFilter = '';
}

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(sr.RequestNo LIKE ? OR c.FullName LIKE ? OR sp.FullName LIKE ? OR sp.ProfessionalCode LIKE ? OR c.MobileNo LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($statusFilter !== '') {
    $where[] = 'sa.AssignmentStatus = ?';
    $params[] = $statusFilter;
}

$sql = 'SELECT sa.uid, sa.AssignedDateTime, sa.AssignmentStatus, sa.AgreedCommission, sa.CommissionType,
               sr.RequestNo, sr.Priority, sr.uid AS RequestId,
               c.FullName AS CustomerName, c.MobileNo AS CustomerMobile,
               st.ServiceName,
               sp.FullName AS ProfessionalName, sp.ProfessionalCode
          FROM ServiceAssignments sa
          INNER JOIN ServiceRequests sr ON sr.uid = sa.ServiceRequestId
          INNER JOIN Customers c ON c.uid = sr.CustomerId
          INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
          INNER JOIN ServiceProfessionals sp ON sp.uid = sa.ProfessionalId
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY sa.AssignedDateTime DESC
         LIMIT 200';

try {
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $assignments = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] assignments list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load service assignments right now.');
    $assignments = [];
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-5">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Request no, customer, professional">
        </div>
        <div class="col-md-3">
            <label class="form-label mb-1" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (assignment_statuses() as $statusOption): ?>
                    <option value="<?= e($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                        <?= e(status_label($statusOption)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="<?= e(url('modules/service_assignments/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    <div>
        <a href="<?= e(url('modules/service_assignments/create.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>New assignment
        </a>
    </div>
</div>

<div class="app-card card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Request</th>
                    <th>Customer</th>
                    <th>Professional</th>
                    <th>Service</th>
                    <th>Commission</th>
                    <th>Assigned</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($assignments === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No service assignments found.</td></tr>
                <?php else: ?>
                    <?php foreach ($assignments as $row): ?>
                        <tr>
                            <td>
                                <a class="fw-semibold text-decoration-none" href="<?= e(url('modules/service_requests/view.php?id=' . (int) $row['RequestId'])) ?>">
                                    <?= e((string) $row['RequestNo']) ?>
                                </a>
                                <div class="small text-muted"><?= e((string) $row['Priority']) ?></div>
                            </td>
                            <td>
                                <div><?= e((string) $row['CustomerName']) ?></div>
                                <div class="small text-muted"><?= e((string) $row['CustomerMobile']) ?></div>
                            </td>
                            <td>
                                <div><?= e((string) $row['ProfessionalName']) ?></div>
                                <div class="small text-muted"><?= e((string) $row['ProfessionalCode']) ?></div>
                            </td>
                            <td><?= e((string) $row['ServiceName']) ?></td>
                            <td>
                                <?= e((string) $row['CommissionType'] === 'Percentage'
                                    ? number_format((float) $row['AgreedCommission'], 2) . '%'
                                    : format_money($row['AgreedCommission'])) ?>
                            </td>
                            <td><?= e(format_datetime((string) $row['AssignedDateTime'])) ?></td>
                            <td><?= status_badge((string) $row['AssignmentStatus']) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/service_assignments/view.php?id=' . (int) $row['uid'])) ?>">View</a>
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
