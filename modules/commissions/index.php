<?php
/**
 * Commission Entries — list earned commissions.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/commission_ops.php';
require_login();

$activeMenu   = 'commission_entries';
$pageTitle    = 'Commission Entries';
$pageSubtitle = 'Commissions earned from completed service assignments.';

$search         = get_string('q', 100);
$statusFilter   = get_string('status', 20);
$professionalId = get_int('professional_id');

if ($statusFilter !== '' && !in_array($statusFilter, commission_statuses(), true)) {
    $statusFilter = '';
}

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(sr.RequestNo LIKE ? OR sp.FullName LIKE ? OR sp.ProfessionalCode LIKE ? OR c.FullName LIKE ? OR st.ServiceName LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($statusFilter !== '') {
    $where[] = 'pc.CommissionStatus = ?';
    $params[] = $statusFilter;
}
if ($professionalId > 0) {
    $where[] = 'pc.ProfessionalId = ?';
    $params[] = $professionalId;
}

$sql = 'SELECT pc.uid, pc.CommissionDate, pc.ServiceAmount, pc.CommissionType, pc.CommissionRate,
               pc.CommissionAmount, pc.PaidAmount, pc.CommissionStatus,
               sr.RequestNo, sr.uid AS RequestId,
               sp.ProfessionalCode, sp.FullName AS ProfessionalName,
               c.FullName AS CustomerName, st.ServiceName
          FROM ProfessionalCommissions pc
          INNER JOIN ServiceRequests sr ON sr.uid = pc.ServiceRequestId
          INNER JOIN ServiceProfessionals sp ON sp.uid = pc.ProfessionalId
          INNER JOIN Customers c ON c.uid = sr.CustomerId
          INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY pc.CommissionDate DESC, pc.uid DESC
         LIMIT 300';

try {
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] commission list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load commission entries right now.');
    $rows = [];
}

$professionals = [];
try {
    $professionals = active_professionals(db());
} catch (PDOException $exception) {
    error_log('[ServiceManagement] commission professionals failed: ' . $exception->getMessage());
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-3">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Request, professional, service">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (commission_statuses() as $statusOption): ?>
                    <option value="<?= e($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                        <?= e(status_label($statusOption)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label mb-1" for="professional_id">Professional</label>
            <select class="form-select" id="professional_id" name="professional_id">
                <option value="">All</option>
                <?php foreach ($professionals as $professional): ?>
                    <option value="<?= (int) $professional['uid'] ?>" <?= $professionalId === (int) $professional['uid'] ? 'selected' : '' ?>>
                        <?= e($professional['FullName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="<?= e(url('modules/commissions/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    <div class="d-flex gap-2">
        <a href="<?= e(url('modules/commissions/ledger.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-journal-text me-1"></i>Ledger
        </a>
        <a href="<?= e(url('modules/commissions/create.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>New commission
        </a>
    </div>
</div>

<div class="app-card card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Request</th>
                    <th>Professional</th>
                    <th>Service</th>
                    <th class="text-end">Service amt</th>
                    <th class="text-end">Commission</th>
                    <th class="text-end">Paid</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No commission entries found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="text-nowrap"><?= e(format_datetime((string) $row['CommissionDate'], 'd M Y')) ?></td>
                            <td>
                                <a class="theme-link" href="<?= e(url('modules/service_requests/view.php?id=' . (int) $row['RequestId'])) ?>">
                                    <?= e((string) $row['RequestNo']) ?>
                                </a>
                                <div class="small text-muted"><?= e((string) $row['CustomerName']) ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= e((string) $row['ProfessionalName']) ?></div>
                                <div class="small text-muted"><?= e((string) $row['ProfessionalCode']) ?></div>
                            </td>
                            <td><?= e((string) $row['ServiceName']) ?></td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['ServiceAmount'])) ?></td>
                            <td class="text-end text-nowrap">
                                <div class="fw-semibold"><?= e(format_money($row['CommissionAmount'])) ?></div>
                                <div class="small text-muted">
                                    <?= e((string) $row['CommissionType'] === 'Percentage'
                                        ? number_format((float) $row['CommissionRate'], 2) . '%'
                                        : 'Fixed') ?>
                                </div>
                            </td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['PaidAmount'])) ?></td>
                            <td><?= status_badge((string) $row['CommissionStatus']) ?></td>
                            <td class="text-end">
                                <a class="theme-link-btn" href="<?= e(url('modules/commissions/view.php?id=' . (int) $row['uid'])) ?>">Open</a>
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
