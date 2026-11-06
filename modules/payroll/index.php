<?php
/**
 * Salary Payment — salary + allowances + commission, paid and remaining.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/payroll_ops.php';
require_login();

$activeMenu   = 'payroll';
$pageTitle    = 'Salary Payment';
$pageSubtitle = 'Total = basic salary + allowances + commission. Pay in multiple installments.';

$search         = get_string('q', 100);
$statusFilter   = get_string('status', 20);
$monthFilter    = get_string('month', 10);
$professionalId = get_int('professional_id');

if ($monthFilter === '' || !preg_match('/^\d{4}-\d{2}$/', $monthFilter)) {
    $monthFilter = date('Y-m');
}
if ($statusFilter !== '' && !in_array($statusFilter, payroll_statuses(), true)) {
    $statusFilter = '';
}

$pdo = db();
try {
    ensure_month_payrolls($pdo, $monthFilter);
} catch (PDOException $exception) {
    error_log('[ServiceManagement] ensure month payrolls failed: ' . $exception->getMessage());
    flash('danger', 'Unable to sync salary totals for the selected month.');
}

$where  = ['pp.PayrollMonth = ?', "pp.PayrollStatus <> 'Cancelled'"];
$params = [$monthFilter . '-01'];

if ($search !== '') {
    $where[] = '(pp.PayrollNo LIKE ? OR sp.FullName LIKE ? OR sp.ProfessionalCode LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($statusFilter !== '') {
    $where[] = 'pp.PayrollStatus = ?';
    $params[] = $statusFilter;
}
if ($professionalId > 0) {
    $where[] = 'pp.ProfessionalId = ?';
    $params[] = $professionalId;
}

$sql = 'SELECT pp.uid, pp.PayrollNo, pp.PayrollMonth, pp.PeriodStart, pp.PeriodEnd,
               pp.BasicSalary, pp.Allowances, pp.CommissionAmount, pp.Bonus, pp.Deductions,
               pp.NetPayable, pp.PaidAmount, pp.BalanceAmount, pp.PayrollStatus,
               sp.ProfessionalCode, sp.FullName AS ProfessionalName
          FROM ProfessionalPayroll pp
          INNER JOIN ServiceProfessionals sp ON sp.uid = pp.ProfessionalId
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY sp.FullName ASC
         LIMIT 300';

try {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] payroll list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load salary payment records right now.');
    $rows = [];
}

$professionals = [];
try {
    $professionals = salaried_professionals($pdo, $monthFilter . '-01');
} catch (PDOException $exception) {
    error_log('[ServiceManagement] payroll professionals failed: ' . $exception->getMessage());
}

$totals = [
    'basic'      => 0.0,
    'allowances' => 0.0,
    'commission' => 0.0,
    'total'      => 0.0,
    'paid'       => 0.0,
    'remaining'  => 0.0,
];
foreach ($rows as $row) {
    $totals['basic'] += (float) $row['BasicSalary'];
    $totals['allowances'] += (float) $row['Allowances'];
    $totals['commission'] += (float) $row['CommissionAmount'];
    $totals['total'] += (float) $row['NetPayable'];
    $totals['paid'] += (float) $row['PaidAmount'];
    $totals['remaining'] += (float) $row['BalanceAmount'];
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-2">
            <label class="form-label mb-1" for="month">Month</label>
            <input type="month" class="form-control" id="month" name="month" value="<?= e($monthFilter) ?>" required>
        </div>
        <div class="col-md-3">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Payroll no, professional">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (payroll_statuses() as $statusOption): ?>
                    <?php if ($statusOption === 'Draft' || $statusOption === 'Cancelled') {
                        continue;
                    } ?>
                    <option value="<?= e($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                        <?= e(status_label($statusOption)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
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
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="<?= e(url('modules/payroll/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-2">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Basic salary</div>
            <div class="fw-semibold"><?= e(format_money($totals['basic'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Allowances</div>
            <div class="fw-semibold"><?= e(format_money($totals['allowances'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Commission</div>
            <div class="fw-semibold"><?= e(format_money($totals['commission'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Total salary</div>
            <div class="fw-semibold"><?= e(format_money($totals['total'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Paid</div>
            <div class="fw-semibold text-success"><?= e(format_money($totals['paid'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Remaining</div>
            <div class="fw-semibold text-danger"><?= e(format_money($totals['remaining'])) ?></div>
        </div></div>
    </div>
</div>

<div class="app-card card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Professional</th>
                    <th class="text-end">Basic</th>
                    <th class="text-end">Allowances</th>
                    <th class="text-end">Commission</th>
                    <th class="text-end">Total salary</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Remaining</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            No salary payment records for this month. Add a salary package for each professional first.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e((string) $row['ProfessionalName']) ?></div>
                                <div class="small text-muted"><?= e((string) $row['ProfessionalCode']) ?></div>
                            </td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['BasicSalary'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['Allowances'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['CommissionAmount'])) ?></td>
                            <td class="text-end text-nowrap fw-semibold"><?= e(format_money($row['NetPayable'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['PaidAmount'])) ?></td>
                            <td class="text-end text-nowrap fw-semibold"><?= e(format_money($row['BalanceAmount'])) ?></td>
                            <td><?= status_badge((string) $row['PayrollStatus']) ?></td>
                            <td class="text-end">
                                <a class="theme-link-btn" href="<?= e(url('modules/payroll/view.php?id=' . (int) $row['uid'])) ?>">
                                    <?= (float) $row['BalanceAmount'] > 0 ? 'Pay' : 'Open' ?>
                                </a>
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
