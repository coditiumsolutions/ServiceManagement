<?php
/**
 * Income & Expense report by period.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/expense_ops.php';
require_login();

$activeMenu   = 'reports';
$pageTitle    = 'Income & Expense Reports';
$pageSubtitle = 'Compare customer income, operating expenses and labor payouts.';

$pdo      = db();
$fromDate = get_string('from', 20);
$toDate   = get_string('to', 20);

if ($fromDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = date('Y-m-01');
}
if ($toDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = date('Y-m-d');
}
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$incomeTotal   = 0.0;
$expenseTotal  = 0.0;
$laborTotal    = 0.0;
$byCategory    = [];
$byMethod      = [];
$loadError     = false;

try {
    $incomeTotal  = report_income_total($pdo, $fromDate, $toDate);
    $expenseTotal = report_expense_total($pdo, $fromDate, $toDate);
    $laborTotal   = report_labor_payout_total($pdo, $fromDate, $toDate);
    $byCategory   = report_expenses_by_category($pdo, $fromDate, $toDate);
    $byMethod     = report_income_by_method($pdo, $fromDate, $toDate);
} catch (PDOException $exception) {
    error_log('[ServiceManagement] income/expense report failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load the report right now.');
    $loadError = true;
}

$operatingProfit = $incomeTotal - $expenseTotal;
$netAfterLabor   = $operatingProfit - $laborTotal;

require __DIR__ . '/../../includes/header.php';
?>
<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-3">
        <label class="form-label mb-1" for="from">From</label>
        <input type="date" class="form-control" id="from" name="from" value="<?= e($fromDate) ?>" required>
    </div>
    <div class="col-md-3">
        <label class="form-label mb-1" for="to">To</label>
        <input type="date" class="form-control" id="to" name="to" value="<?= e($toDate) ?>" required>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Apply</button>
        <a href="<?= e(url('modules/expenses/reports.php')) ?>" class="btn btn-outline-secondary">This month</a>
        <a href="<?= e(url('modules/expenses/index.php')) ?>" class="btn btn-outline-secondary">Expenses</a>
    </div>
</form>

<?php if (!$loadError): ?>
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="app-card card h-100">
            <div class="card-body">
                <div class="text-muted small">Customer income</div>
                <div class="fs-4 fw-semibold text-success"><?= e(format_money($incomeTotal)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-card card h-100">
            <div class="card-body">
                <div class="text-muted small">Operating expenses</div>
                <div class="fs-4 fw-semibold text-danger"><?= e(format_money($expenseTotal)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-card card h-100">
            <div class="card-body">
                <div class="text-muted small">Labor payouts</div>
                <div class="fs-4 fw-semibold"><?= e(format_money($laborTotal)) ?></div>
                <div class="small text-muted">Salary + commission payments</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-card card h-100">
            <div class="card-body">
                <div class="text-muted small">Net after labor</div>
                <div class="fs-4 fw-semibold <?= $netAfterLabor >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= e(format_money($netAfterLabor)) ?>
                </div>
                <div class="small text-muted">
                    Operating <?= e(format_money($operatingProfit)) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="app-card card">
            <div class="card-header"><strong>Expenses by category</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Category</th>
                            <th class="text-end">Entries</th>
                            <th class="text-end">Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($byCategory === []): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">No expenses in this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($byCategory as $row): ?>
                                <tr>
                                    <td><?= e((string) $row['ExpenseCategory']) ?></td>
                                    <td class="text-end"><?= e(format_number($row['EntryCount'])) ?></td>
                                    <td class="text-end text-nowrap"><?= e(format_money($row['TotalAmount'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="app-card card">
            <div class="card-header"><strong>Income by payment method</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Method</th>
                            <th class="text-end">Entries</th>
                            <th class="text-end">Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($byMethod === []): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">No customer payments in this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($byMethod as $row): ?>
                                <tr>
                                    <td><?= e(status_label((string) $row['PaymentMethod'])) ?></td>
                                    <td class="text-end"><?= e(format_number($row['EntryCount'])) ?></td>
                                    <td class="text-end text-nowrap"><?= e(format_money($row['TotalAmount'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
