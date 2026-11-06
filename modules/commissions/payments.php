<?php
/**
 * Commission Payments — list payouts against commission entries.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/commission_ops.php';
require_login();

$activeMenu   = 'commission_payments';
$pageTitle    = 'Commission Payments';
$pageSubtitle = 'Payouts recorded against professional commissions.';

$search         = get_string('q', 100);
$professionalId = get_int('professional_id');
$methodFilter   = get_string('method', 30);

if ($methodFilter !== '' && !in_array($methodFilter, commission_payment_methods(), true)) {
    $methodFilter = '';
}

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(cp.PaymentNo LIKE ? OR sr.RequestNo LIKE ? OR sp.FullName LIKE ? OR cp.ReferenceNo LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($professionalId > 0) {
    $where[] = 'cp.ProfessionalId = ?';
    $params[] = $professionalId;
}
if ($methodFilter !== '') {
    $where[] = 'cp.PaymentMethod = ?';
    $params[] = $methodFilter;
}

$sql = 'SELECT cp.uid, cp.PaymentNo, cp.PaymentDateTime, cp.Amount, cp.PaymentMethod, cp.ReferenceNo,
               pc.uid AS CommissionId, pc.CommissionAmount,
               sr.RequestNo, sp.ProfessionalCode, sp.FullName AS ProfessionalName
          FROM ProfessionalCommissionPayments cp
          INNER JOIN ProfessionalCommissions pc ON pc.uid = cp.CommissionId
          INNER JOIN ServiceRequests sr ON sr.uid = pc.ServiceRequestId
          INNER JOIN ServiceProfessionals sp ON sp.uid = cp.ProfessionalId
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY cp.PaymentDateTime DESC
         LIMIT 300';

try {
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] commission payments list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load commission payments right now.');
    $rows = [];
}

$professionals = [];
try {
    $professionals = active_professionals(db());
} catch (PDOException $exception) {
    error_log('[ServiceManagement] commission payment professionals failed: ' . $exception->getMessage());
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-3">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Payment no, request, professional">
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
        <div class="col-md-2">
            <label class="form-label mb-1" for="method">Method</label>
            <select class="form-select" id="method" name="method">
                <option value="">All</option>
                <?php foreach (commission_payment_methods() as $method): ?>
                    <option value="<?= e($method) ?>" <?= $methodFilter === $method ? 'selected' : '' ?>>
                        <?= e(status_label($method)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="<?= e(url('modules/commissions/payments.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    <div>
        <a href="<?= e(url('modules/commissions/index.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-percent me-1"></i>Commission entries
        </a>
    </div>
</div>

<div class="app-card card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Payment no</th>
                    <th>Date</th>
                    <th>Professional</th>
                    <th>Request</th>
                    <th>Method</th>
                    <th class="text-end">Amount</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No commission payments found.
                            <?php if ($professionalId > 0 || $methodFilter !== '' || $search !== ''): ?>
                                Try clearing filters, or open a commission entry and use <strong>Record payment</strong>.
                            <?php else: ?>
                                Payments appear here after you record them on a commission entry.
                            <?php endif; ?>
                            <div class="mt-2">
                                <a class="theme-link-btn" href="<?= e(url('modules/commissions/index.php')) ?>">Go to commission entries</a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="fw-semibold"><?= e((string) $row['PaymentNo']) ?></td>
                            <td class="text-nowrap"><?= e(format_datetime((string) $row['PaymentDateTime'])) ?></td>
                            <td>
                                <div class="fw-semibold"><?= e((string) $row['ProfessionalName']) ?></div>
                                <div class="small text-muted"><?= e((string) $row['ProfessionalCode']) ?></div>
                            </td>
                            <td><?= e((string) $row['RequestNo']) ?></td>
                            <td><?= e(status_label((string) $row['PaymentMethod'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['Amount'])) ?></td>
                            <td class="text-end">
                                <a class="theme-link-btn" href="<?= e(url('modules/commissions/view.php?id=' . (int) $row['CommissionId'])) ?>">Open</a>
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
