<?php
/**
 * Expense detail.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/expense_ops.php';
require_login();

$pdo = db();
$id  = get_int('id');
if ($id <= 0) {
    flash('danger', 'Invalid expense.');
    redirect('modules/expenses/index.php');
}

$expense = fetch_expense($pdo, $id);
if ($expense === null) {
    flash('danger', 'Expense not found.');
    redirect('modules/expenses/index.php');
}

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/expenses/view.php?id=' . $id);
    }
    $action = post_string('action', 30);
    if ($action === 'delete') {
        try {
            $pdo->prepare('DELETE FROM Expenses WHERE uid = ?')->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Expense deleted.');
            redirect('modules/expenses/index.php');
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] expense delete failed: ' . $exception->getMessage());
            flash('danger', 'Could not delete the expense.');
            redirect('modules/expenses/view.php?id=' . $id);
        }
    }
}

$activeMenu   = 'expenses';
$pageTitle    = (string) $expense['ExpenseNo'];
$pageSubtitle = 'Expense detail';

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <div>
        <a href="<?= e(url('modules/expenses/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to list
        </a>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= e(url('modules/expenses/edit.php?id=' . $id)) ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        <form method="post" onsubmit="return confirm('Permanently delete this expense?');">
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
                <strong><?= e((string) $expense['ExpenseNo']) ?></strong>
                <span class="fw-semibold"><?= e(format_money($expense['Amount'])) ?></span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="text-muted small">Date</div>
                        <div><?= e(format_datetime((string) $expense['ExpenseDate'], 'd M Y')) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Category</div>
                        <div><?= e((string) $expense['ExpenseCategory']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Payment method</div>
                        <div><?= e(status_label((string) $expense['PaymentMethod'])) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Paid to</div>
                        <div><?= e((string) ($expense['PaidTo'] ?: '—')) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Reference no</div>
                        <div><?= e((string) ($expense['ReferenceNo'] ?: '—')) ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Description</div>
                        <div><?= e((string) ($expense['Description'] ?: '—')) ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Remarks</div>
                        <div><?= e((string) ($expense['Remarks'] ?: '—')) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="app-card card mb-3">
            <div class="card-header"><strong>Links</strong></div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="text-muted small">Service request</div>
                    <?php if (!empty($expense['ServiceRequestId'])): ?>
                        <a href="<?= e(url('modules/service_requests/view.php?id=' . (int) $expense['ServiceRequestId'])) ?>">
                            <?= e((string) $expense['RequestNo']) ?>
                        </a>
                        <?php if (!empty($expense['CustomerName'])): ?>
                            <div class="small text-muted"><?= e((string) $expense['CustomerName']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div>—</div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="text-muted small">Professional</div>
                    <?php if (!empty($expense['ProfessionalId'])): ?>
                        <a href="<?= e(url('modules/professionals/view.php?id=' . (int) $expense['ProfessionalId'])) ?>">
                            <?= e((string) $expense['ProfessionalName']) ?>
                        </a>
                        <?php if (!empty($expense['ProfessionalCode'])): ?>
                            <div class="small text-muted"><?= e((string) $expense['ProfessionalCode']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div>—</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="app-card card">
            <div class="card-header"><strong>Audit</strong></div>
            <div class="card-body small">
                <div class="mb-2">
                    <div class="text-muted">Created</div>
                    <div><?= e(format_datetime((string) $expense['created_at'])) ?></div>
                </div>
                <div>
                    <div class="text-muted">Updated</div>
                    <div><?= e(format_datetime((string) $expense['updated_at'])) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
