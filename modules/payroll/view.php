<?php
/**
 * Salary Payment detail — totals breakdown and multiple installment payments.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/payroll_ops.php';
require_login();

$pdo = db();
$id  = get_int('id');
if ($id <= 0) {
    flash('danger', 'Invalid salary payment record.');
    redirect('modules/payroll/index.php');
}

try {
    sync_payroll_totals($pdo, $id);
} catch (PDOException $exception) {
    error_log('[ServiceManagement] payroll sync failed: ' . $exception->getMessage());
}

$payroll = fetch_payroll($pdo, $id);
if ($payroll === null) {
    flash('danger', 'Salary payment record not found.');
    redirect('modules/payroll/index.php');
}

$activeMenu   = 'payroll';
$pageTitle    = 'Salary Payment ' . (string) $payroll['PayrollNo'];
$pageSubtitle = 'Monthly salary total and installment payments.';

$payForm = [
    'Amount'        => '',
    'PaymentMethod' => 'Cash',
    'ReferenceNo'   => '',
    'Remarks'       => '',
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/payroll/view.php?id=' . $id);
    }

    $action = post_string('action', 30);
    $status = (string) $payroll['PayrollStatus'];

    try {
        if ($action === 'pay' && $status !== 'Cancelled') {
            $payForm['Amount'] = number_format(post_float('Amount'), 2, '.', '');
            $payForm['PaymentMethod'] = post_string('PaymentMethod', 30);
            $payForm['ReferenceNo'] = post_string('ReferenceNo', 100);
            $payForm['Remarks'] = post_string('Remarks', 500);

            $amount = (float) $payForm['Amount'];
            $balance = (float) $payroll['BalanceAmount'];

            if ($amount <= 0) {
                $errors[] = 'Payment amount must be greater than zero.';
            }
            if ($amount - $balance > 0.009) {
                $errors[] = 'Payment cannot exceed remaining balance (' . format_money($balance) . ').';
            }
            if (!in_array($payForm['PaymentMethod'], salary_payment_methods(), true)) {
                $errors[] = 'Invalid payment method.';
            }
            if ($balance <= 0) {
                $errors[] = 'Nothing remaining to pay for this month.';
            }

            if ($errors === []) {
                $pdo->beginTransaction();
                $paymentNo = generate_salary_payment_no($pdo);
                $insert = $pdo->prepare(
                    'INSERT INTO ProfessionalSalaryPayments
                        (PaymentNo, PayrollId, ProfessionalId, Amount, PaymentMethod, ReferenceNo, Remarks)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $paymentNo,
                    $id,
                    (int) $payroll['ProfessionalId'],
                    $amount,
                    $payForm['PaymentMethod'],
                    $payForm['ReferenceNo'] !== '' ? $payForm['ReferenceNo'] : null,
                    $payForm['Remarks'] !== '' ? $payForm['Remarks'] : null,
                ]);
                $pdo->prepare(
                    'UPDATE ProfessionalPayroll SET PaidAmount = PaidAmount + ? WHERE uid = ?'
                )->execute([$amount, $id]);
                refresh_payroll_status($pdo, $id);
                $pdo->commit();
                regenerate_csrf_token();
                flash('success', 'Salary payment ' . $paymentNo . ' recorded.');
                redirect('modules/payroll/view.php?id=' . $id);
            }
        } elseif ($action === 'cancel' && in_array($status, ['Draft', 'Approved'], true) && (float) $payroll['PaidAmount'] <= 0) {
            $pdo->prepare("UPDATE ProfessionalPayroll SET PayrollStatus = 'Cancelled' WHERE uid = ?")->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Salary payment record cancelled.');
            redirect('modules/payroll/index.php?month=' . urlencode(substr((string) $payroll['PayrollMonth'], 0, 7)));
        } elseif ($errors === []) {
            flash('warning', 'That action is not allowed for the current record.');
            redirect('modules/payroll/view.php?id=' . $id);
        }
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ServiceManagement] payroll payment failed: ' . $exception->getMessage());
        flash('danger', 'Could not record the salary payment.');
        redirect('modules/payroll/view.php?id=' . $id);
    }
}

$payments = [];
try {
    $payments = payroll_payments($pdo, $id);
} catch (PDOException $exception) {
    error_log('[ServiceManagement] payroll payments list failed: ' . $exception->getMessage());
}

$status  = (string) $payroll['PayrollStatus'];
$balance = max(0, (float) $payroll['BalanceAmount']);
if ($payForm['Amount'] === '' && $balance > 0) {
    $payForm['Amount'] = number_format($balance, 2, '.', '');
}
$monthKey = substr((string) $payroll['PayrollMonth'], 0, 7);

require __DIR__ . '/../../includes/header.php';
?>
<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= e(url('modules/payroll/index.php?month=' . urlencode($monthKey))) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <a href="<?= e(url('modules/salary/index.php?professional_id=' . (int) $payroll['ProfessionalId'] . '&current=1')) ?>" class="theme-link-btn btn-sm">
            Salary package
        </a>
    </div>
    <div><?= status_badge((string) $payroll['PayrollStatus']) ?></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4 col-lg-2">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Basic salary</div>
            <div class="fw-semibold"><?= e(format_money($payroll['BasicSalary'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Allowances</div>
            <div class="fw-semibold"><?= e(format_money($payroll['Allowances'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Commission</div>
            <div class="fw-semibold"><?= e(format_money($payroll['CommissionAmount'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Total salary</div>
            <div class="fw-semibold"><?= e(format_money($payroll['NetPayable'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Paid</div>
            <div class="fw-semibold text-success"><?= e(format_money($payroll['PaidAmount'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Remaining</div>
            <div class="fw-semibold text-danger"><?= e(format_money($balance)) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="app-card card mb-3">
            <div class="card-header"><strong>Payment information</strong></div>
            <div class="card-body detail-rows">
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Payroll no</div>
                            <div class="fw-semibold"><?= e((string) $payroll['PayrollNo']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Month</div>
                            <div class="fw-semibold"><?= e(format_datetime((string) $payroll['PayrollMonth'], 'F Y')) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Period</div>
                            <div>
                                <?= e(format_datetime((string) $payroll['PeriodStart'], 'd M Y')) ?>
                                –
                                <?= e(format_datetime((string) $payroll['PeriodEnd'], 'd M Y')) ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small">Professional</div>
                            <div class="fw-semibold"><?= e((string) $payroll['ProfessionalName']) ?></div>
                            <div class="small text-muted"><?= e((string) $payroll['ProfessionalCode'] . ' · ' . $payroll['ProfessionalMobile']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Employment type</div>
                            <div><?= e((string) $payroll['EmploymentType']) ?></div>
                        </div>
                    </div>
                </div>
                <?php if ((float) $payroll['Bonus'] > 0 || (float) $payroll['Deductions'] > 0): ?>
                    <div class="detail-row">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="text-muted small">Bonus</div>
                                <div><?= e(format_money($payroll['Bonus'])) ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">Deductions</div>
                                <div><?= e(format_money($payroll['Deductions'])) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="app-card card">
            <div class="card-header"><strong>Payment history</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Payment no</th>
                            <th>Date</th>
                            <th>Method</th>
                            <th class="text-end">Amount</th>
                            <th>Reference</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($payments === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No payments recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e((string) $payment['PaymentNo']) ?></td>
                                    <td><?= e(format_datetime((string) $payment['PaymentDateTime'])) ?></td>
                                    <td><?= e(status_label((string) $payment['PaymentMethod'])) ?></td>
                                    <td class="text-end"><?= e(format_money($payment['Amount'])) ?></td>
                                    <td><?= e((string) ($payment['ReferenceNo'] ?: '—')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if ($balance > 0 && $status !== 'Cancelled'): ?>
            <form method="post" class="app-card card mb-3">
                <div class="card-header"><strong>Record payment</strong></div>
                <div class="card-body form-grid-blue">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pay">
                    <div class="form-row-blue">
                        <div class="form-field span-4">
                            <label class="form-label" for="Amount">Amount</label>
                            <input type="number" step="0.01" min="0.01" max="<?= e((string) $balance) ?>"
                                   class="form-control" id="Amount" name="Amount"
                                   value="<?= e($payForm['Amount']) ?>" required>
                            <div class="form-text">Remaining: <?= e(format_money($balance)) ?></div>
                        </div>
                    </div>
                    <div class="form-row-blue">
                        <div class="form-field span-4">
                            <label class="form-label" for="PaymentMethod">Method</label>
                            <select class="form-select" id="PaymentMethod" name="PaymentMethod">
                                <?php foreach (salary_payment_methods() as $method): ?>
                                    <option value="<?= e($method) ?>" <?= $payForm['PaymentMethod'] === $method ? 'selected' : '' ?>>
                                        <?= e(status_label($method)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row-blue">
                        <div class="form-field span-4">
                            <label class="form-label" for="ReferenceNo">Reference</label>
                            <input type="text" class="form-control" id="ReferenceNo" name="ReferenceNo"
                                   value="<?= e($payForm['ReferenceNo']) ?>" maxlength="100">
                        </div>
                    </div>
                    <div class="form-row-blue">
                        <div class="form-field span-4">
                            <label class="form-label" for="Remarks">Remarks</label>
                            <input type="text" class="form-control" id="Remarks" name="Remarks"
                                   value="<?= e($payForm['Remarks']) ?>" maxlength="500">
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary w-100">Save payment</button>
                </div>
            </form>
        <?php else: ?>
            <div class="app-card card mb-3">
                <div class="card-body">
                    <p class="text-muted mb-0 small">
                        <?= $status === 'Cancelled' ? 'This record is cancelled.' : 'Fully paid for this month.' ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (in_array($status, ['Draft', 'Approved'], true) && (float) $payroll['PaidAmount'] <= 0): ?>
            <form method="post" onsubmit="return confirm('Cancel this salary payment record?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <button class="btn btn-outline-danger w-100" type="submit">Cancel record</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
