<?php
/**
 * Customer payment detail.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/payment_ops.php';
require_login();

$pdo = db();
$id  = get_int('id');
if ($id <= 0) {
    flash('danger', 'Invalid payment.');
    redirect('modules/payments/index.php');
}

$payment = fetch_customer_payment($pdo, $id);
if ($payment === null) {
    flash('danger', 'Payment not found.');
    redirect('modules/payments/index.php');
}

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/payments/view.php?id=' . $id);
    }
    $action = post_string('action', 30);
    if ($action === 'delete') {
        try {
            $pdo->prepare('DELETE FROM CustomerPayments WHERE uid = ?')->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Payment deleted.');
            redirect('modules/payments/index.php');
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] payment delete failed: ' . $exception->getMessage());
            flash('danger', 'Could not delete the payment.');
            redirect('modules/payments/view.php?id=' . $id);
        }
    }
}

$activeMenu   = 'customer_payments';
$pageTitle    = (string) $payment['ReceiptNo'];
$pageSubtitle = 'Customer payment receipt';

$requestSummary = null;
if (!empty($payment['ServiceRequestId'])) {
    $requestSummary = request_payment_summary(
        $pdo,
        (int) $payment['ServiceRequestId'],
        (float) ($payment['FinalAmount'] ?? 0)
    );
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <div>
        <a href="<?= e(url('modules/payments/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to list
        </a>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= e(url('modules/payments/edit.php?id=' . $id)) ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        <form method="post" onsubmit="return confirm('Permanently delete this payment?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-trash me-1"></i>Delete
            </button>
        </form>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="app-card card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><?= e((string) $payment['ReceiptNo']) ?></strong>
                <span class="fw-semibold <?= (string) $payment['PaymentType'] === 'Refund' ? 'text-danger' : '' ?>">
                    <?= (string) $payment['PaymentType'] === 'Refund' ? '-' : '' ?><?= e(format_money($payment['Amount'])) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="text-muted small">Payment date</div>
                        <div><?= e(format_datetime((string) $payment['PaymentDateTime'])) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Type</div>
                        <div><?= e((string) $payment['PaymentType']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Method</div>
                        <div><?= e(status_label((string) $payment['PaymentMethod'])) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Reference no</div>
                        <div><?= e((string) ($payment['ReferenceNo'] ?: '—')) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Created</div>
                        <div><?= e(format_datetime((string) $payment['created_at'])) ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Remarks</div>
                        <div><?= e((string) ($payment['Remarks'] ?: '—')) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="app-card card mb-3">
            <div class="card-header"><strong>Customer</strong></div>
            <div class="card-body">
                <div class="fw-semibold"><?= e((string) $payment['CustomerName']) ?></div>
                <div class="small text-muted">
                    <?= e((string) $payment['CustomerCode']) ?>
                    <?php if (!empty($payment['CustomerMobile'])): ?>
                        · <?= e((string) $payment['CustomerMobile']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="app-card card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Service request</strong>
                <?php if (!empty($payment['ServiceRequestId'])): ?>
                    <a class="theme-link-btn" href="<?= e(url('modules/service_requests/view.php?id=' . (int) $payment['ServiceRequestId'])) ?>">Open</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($payment['ServiceRequestId'])): ?>
                    <div class="text-muted">Not linked to a request.</div>
                <?php else: ?>
                    <div class="fw-semibold"><?= e((string) $payment['RequestNo']) ?></div>
                    <div class="small text-muted mb-2">
                        <?= e((string) ($payment['ServiceName'] ?: '')) ?>
                        · <?= e(status_label((string) ($payment['RequestStatus'] ?? ''))) ?>
                    </div>
                    <?php if ($requestSummary !== null): ?>
                        <div class="small">Final: <strong><?= e(format_money($requestSummary['final_amount'])) ?></strong></div>
                        <div class="small">Paid: <strong><?= e(format_money($requestSummary['paid_amount'])) ?></strong></div>
                        <div class="small">Balance:
                            <strong class="<?= $requestSummary['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= e(format_money($requestSummary['balance'])) ?>
                            </strong>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
