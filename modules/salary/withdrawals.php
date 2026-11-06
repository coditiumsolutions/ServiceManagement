<?php
/**
 * Salary Payments — all installment payments against monthly salary totals.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/payroll_ops.php';
require_login();

$activeMenu   = 'salary_withdrawals';
$pageTitle    = 'Salary Payments';
$pageSubtitle = 'Installment payments against monthly salary totals.';

$search         = get_string('q', 100);
$professionalId = get_int('professional_id');
$methodFilter   = get_string('method', 30);
$monthFilter    = get_string('month', 10);

if ($methodFilter !== '' && !in_array($methodFilter, salary_payment_methods(), true)) {
    $methodFilter = '';
}
if ($monthFilter !== '' && !preg_match('/^\d{4}-\d{2}$/', $monthFilter)) {
    $monthFilter = '';
}

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(spay.PaymentNo LIKE ? OR pp.PayrollNo LIKE ? OR sp.FullName LIKE ? OR spay.ReferenceNo LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($professionalId > 0) {
    $where[] = 'spay.ProfessionalId = ?';
    $params[] = $professionalId;
}
if ($methodFilter !== '') {
    $where[] = 'spay.PaymentMethod = ?';
    $params[] = $methodFilter;
}
if ($monthFilter !== '') {
    $where[] = 'pp.PayrollMonth = ?';
    $params[] = $monthFilter . '-01';
}

$sql = 'SELECT spay.uid, spay.PaymentNo, spay.PaymentDateTime, spay.Amount, spay.PaymentMethod, spay.ReferenceNo,
               pp.uid AS PayrollId, pp.PayrollNo, pp.PayrollMonth, pp.NetPayable, pp.PaidAmount, pp.BalanceAmount,
               sp.ProfessionalCode, sp.FullName AS ProfessionalName
          FROM ProfessionalSalaryPayments spay
          INNER JOIN ProfessionalPayroll pp ON pp.uid = spay.PayrollId
          INNER JOIN ServiceProfessionals sp ON sp.uid = spay.ProfessionalId
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY spay.PaymentDateTime DESC
         LIMIT 300';

try {
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] labor payments list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load salary payments right now.');
    $rows = [];
}

$professionals = [];
try {
    $professionals = salaried_professionals(db());
} catch (PDOException $exception) {
    error_log('[ServiceManagement] salary payment professionals failed: ' . $exception->getMessage());
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-3">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Payment no, payroll, professional">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="month">Month</label>
            <input type="month" class="form-control" id="month" name="month" value="<?= e($monthFilter) ?>">
        </div>
        <div class="col-md-2">
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
                <?php foreach (salary_payment_methods() as $method): ?>
                    <option value="<?= e($method) ?>" <?= $methodFilter === $method ? 'selected' : '' ?>>
                        <?= e(status_label($method)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="<?= e(url('modules/salary/withdrawals.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    <div>
        <a href="<?= e(url('modules/payroll/index.php')) ?>" class="btn btn-primary">
            <i class="bi bi-calendar-check me-1"></i>Salary Payment
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
                    <th>Month</th>
                    <th>Method</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Remaining</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No salary payments found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="fw-semibold"><?= e((string) $row['PaymentNo']) ?></td>
                            <td class="text-nowrap"><?= e(format_datetime((string) $row['PaymentDateTime'])) ?></td>
                            <td>
                                <div class="fw-semibold"><?= e((string) $row['ProfessionalName']) ?></div>
                                <div class="small text-muted"><?= e((string) $row['ProfessionalCode']) ?></div>
                            </td>
                            <td class="text-nowrap"><?= e(format_datetime((string) $row['PayrollMonth'], 'M Y')) ?></td>
                            <td><?= e(status_label((string) $row['PaymentMethod'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['Amount'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['BalanceAmount'])) ?></td>
                            <td class="text-end">
                                <a class="theme-link-btn" href="<?= e(url('modules/payroll/view.php?id=' . (int) $row['PayrollId'])) ?>">Open</a>
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
