<?php
/**
 * View / update a commission entry; record payouts.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/commission_ops.php';
require_login();

$pdo = db();
$id  = get_int('id');
if ($id <= 0) {
    flash('danger', 'Invalid commission entry.');
    redirect('modules/commissions/index.php');
}

$commission = fetch_commission($pdo, $id);
if ($commission === null) {
    flash('danger', 'Commission entry not found.');
    redirect('modules/commissions/index.php');
}

$activeMenu   = 'commission_entries';
$pageTitle    = 'Commission #' . $id;
$pageSubtitle = 'Commission details, approval and payments.';

$payForm = [
    'Amount'        => number_format(max(0, (float) $commission['CommissionAmount'] - (float) $commission['PaidAmount']), 2, '.', ''),
    'PaymentMethod' => 'Cash',
    'ReferenceNo'   => '',
    'Remarks'       => '',
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/commissions/view.php?id=' . $id);
    }

    $action = post_string('action', 30);
    $status = (string) $commission['CommissionStatus'];

    try {
        if ($action === 'approve' && $status === 'Pending') {
            $pdo->prepare("UPDATE ProfessionalCommissions SET CommissionStatus = 'Approved' WHERE uid = ?")->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Commission approved.');
            redirect('modules/commissions/view.php?id=' . $id);
        }

        if ($action === 'cancel' && !in_array($status, ['Paid', 'Cancelled'], true)) {
            $pdo->prepare("UPDATE ProfessionalCommissions SET CommissionStatus = 'Cancelled' WHERE uid = ?")->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Commission cancelled.');
            redirect('modules/commissions/view.php?id=' . $id);
        }

        if ($action === 'pay' && !in_array($status, ['Cancelled', 'Paid'], true)) {
            $payForm['Amount'] = number_format(post_float('Amount'), 2, '.', '');
            $payForm['PaymentMethod'] = post_string('PaymentMethod', 30);
            $payForm['ReferenceNo'] = post_string('ReferenceNo', 100);
            $payForm['Remarks'] = post_string('Remarks', 500);

            $amount = (float) $payForm['Amount'];
            $balance = (float) $commission['CommissionAmount'] - (float) $commission['PaidAmount'];

            if ($amount <= 0) {
                $errors[] = 'Payment amount must be greater than zero.';
            }
            if ($amount - $balance > 0.009) {
                $errors[] = 'Payment cannot exceed remaining balance (' . format_money($balance) . ').';
            }
            if (!in_array($payForm['PaymentMethod'], commission_payment_methods(), true)) {
                $errors[] = 'Invalid payment method.';
            }

            if ($errors === []) {
                $pdo->beginTransaction();
                $paymentNo = generate_commission_payment_no($pdo);
                $insert = $pdo->prepare(
                    'INSERT INTO ProfessionalCommissionPayments
                        (PaymentNo, CommissionId, ProfessionalId, PayrollId, Amount, PaymentMethod, ReferenceNo, Remarks)
                     VALUES (?, ?, ?, NULL, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $paymentNo,
                    $id,
                    (int) $commission['ProfessionalId'],
                    $amount,
                    $payForm['PaymentMethod'],
                    $payForm['ReferenceNo'] !== '' ? $payForm['ReferenceNo'] : null,
                    $payForm['Remarks'] !== '' ? $payForm['Remarks'] : null,
                ]);
                $pdo->prepare(
                    'UPDATE ProfessionalCommissions SET PaidAmount = PaidAmount + ? WHERE uid = ?'
                )->execute([$amount, $id]);
                refresh_commission_status($pdo, $id);
                $pdo->commit();
                regenerate_csrf_token();
                flash('success', 'Commission payment ' . $paymentNo . ' recorded.');
                redirect('modules/commissions/view.php?id=' . $id);
            }
        } elseif ($errors === []) {
            flash('warning', 'That action is not allowed for the current commission status.');
            redirect('modules/commissions/view.php?id=' . $id);
        }
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ServiceManagement] commission update failed: ' . $exception->getMessage());
        flash('danger', 'Could not update the commission entry.');
        redirect('modules/commissions/view.php?id=' . $id);
    }
}

$payments = [];
try {
    $statement = $pdo->prepare(
        'SELECT * FROM ProfessionalCommissionPayments
          WHERE CommissionId = ?
          ORDER BY PaymentDateTime DESC'
    );
    $statement->execute([$id]);
    $payments = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] commission payments failed: ' . $exception->getMessage());
}

$balance = max(0, (float) $commission['CommissionAmount'] - (float) $commission['PaidAmount']);

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
        <a href="<?= e(url('modules/commissions/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <a href="<?= e(url('modules/commissions/ledger.php?professional_id=' . (int) $commission['ProfessionalId'])) ?>" class="theme-link-btn btn-sm">
            Professional ledger
        </a>
    </div>
    <div><?= status_badge((string) $commission['CommissionStatus']) ?></div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="app-card card mb-3">
            <div class="card-header"><strong>Commission information</strong></div>
            <div class="card-body detail-rows">
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Request</div>
                            <div class="fw-semibold">
                                <a class="theme-link" href="<?= e(url('modules/service_requests/view.php?id=' . (int) $commission['RequestId'])) ?>">
                                    <?= e((string) $commission['RequestNo']) ?>
                                </a>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Customer</div>
                            <div><?= e((string) $commission['CustomerName']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Service</div>
                            <div><?= e((string) $commission['ServiceName']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small">Professional</div>
                            <div class="fw-semibold"><?= e((string) $commission['ProfessionalName']) ?></div>
                            <div class="small text-muted"><?= e((string) $commission['ProfessionalCode'] . ' · ' . $commission['ProfessionalMobile']) ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Commission date</div>
                            <div><?= e(format_datetime((string) $commission['CommissionDate'], 'd M Y')) ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Completed at</div>
                            <div><?= e(format_datetime((string) ($commission['CompletionDateTime'] ?? ''))) ?></div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="text-muted small">Service amount</div>
                            <div><?= e(format_money($commission['ServiceAmount'])) ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Rate</div>
                            <div>
                                <?= e((string) $commission['CommissionType'] === 'Percentage'
                                    ? number_format((float) $commission['CommissionRate'], 2) . '%'
                                    : format_money($commission['CommissionRate'])) ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Commission</div>
                            <div class="fw-semibold"><?= e(format_money($commission['CommissionAmount'])) ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Balance</div>
                            <div class="fw-semibold"><?= e(format_money($balance)) ?></div>
                        </div>
                    </div>
                </div>
                <?php if (!empty($commission['Remarks'])): ?>
                    <div class="detail-row">
                        <div class="text-muted small">Remarks</div>
                        <div><?= e((string) $commission['Remarks']) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="app-card card">
            <div class="card-header"><strong>Payments</strong></div>
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
                            <tr><td colspan="5" class="text-center text-muted py-3">No payments recorded.</td></tr>
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
        <div class="app-card card mb-3">
            <div class="card-header"><strong>Status actions</strong></div>
            <div class="card-body d-grid gap-2">
                <?php if ((string) $commission['CommissionStatus'] === 'Pending'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve">
                        <button class="btn btn-outline-success w-100" type="submit">Approve commission</button>
                    </form>
                <?php endif; ?>
                <?php if (!in_array((string) $commission['CommissionStatus'], ['Paid', 'Cancelled'], true)): ?>
                    <form method="post" onsubmit="return confirm('Cancel this commission entry?');">
                        <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                        <button class="btn btn-outline-danger w-100" type="submit">Cancel commission</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0 small">No further status actions are available.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($balance > 0 && !in_array((string) $commission['CommissionStatus'], ['Cancelled', 'Pending'], true)): ?>
            <form method="post" class="app-card card">
                <div class="card-header"><strong>Record payment</strong></div>
                <div class="card-body form-grid-blue">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pay">
                    <div class="form-row-blue">
                        <div class="form-field span-2">
                            <label class="form-label" for="Amount">Amount</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" id="Amount" name="Amount"
                                   value="<?= e($payForm['Amount']) ?>" required>
                        </div>
                        <div class="form-field span-2">
                            <label class="form-label" for="PaymentMethod">Method</label>
                            <select class="form-select" id="PaymentMethod" name="PaymentMethod">
                                <?php foreach (commission_payment_methods() as $method): ?>
                                    <option value="<?= e($method) ?>" <?= $payForm['PaymentMethod'] === $method ? 'selected' : '' ?>>
                                        <?= e(status_label($method)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row-blue">
                        <div class="form-field span-2">
                            <label class="form-label" for="ReferenceNo">Reference</label>
                            <input type="text" class="form-control" id="ReferenceNo" name="ReferenceNo"
                                   value="<?= e($payForm['ReferenceNo']) ?>" maxlength="100">
                        </div>
                        <div class="form-field span-2">
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
        <?php elseif ((string) $commission['CommissionStatus'] === 'Pending'): ?>
            <div class="alert alert-light border small mb-0">Approve the commission before recording a payment.</div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
