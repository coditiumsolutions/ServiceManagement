<?php
/**
 * Create a commission entry from a completed assignment.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/commission_ops.php';
require_login();

$activeMenu   = 'commission_entries';
$pageTitle    = 'New Commission Entry';
$pageSubtitle = 'Create commission from a completed assignment.';

$pdo = db();
$assignments = commissionable_assignments($pdo);

$form = [
    'AssignmentId'     => get_int('assignment_id'),
    'CommissionType'   => 'Percentage',
    'CommissionRate'   => '0.00',
    'CommissionAmount' => '0.00',
    'ServiceAmount'    => '0.00',
    'CommissionDate'   => date('Y-m-d'),
    'Remarks'          => '',
];
$errors = [];

$selected = null;
foreach ($assignments as $assignment) {
    if ((int) $assignment['uid'] === $form['AssignmentId']) {
        $selected = $assignment;
        break;
    }
}
if ($selected !== null) {
    $resolved = resolve_commission_for_assignment($pdo, $selected);
    $form['CommissionType']   = $resolved['type'];
    $form['CommissionRate']   = number_format($resolved['rate'], 2, '.', '');
    $form['CommissionAmount'] = number_format($resolved['amount'], 2, '.', '');
    $form['ServiceAmount']    = number_format($resolved['service_amount'], 2, '.', '');
    if (!empty($selected['CompletionDateTime'])) {
        $form['CommissionDate'] = date('Y-m-d', strtotime((string) $selected['CompletionDateTime']));
    }
}

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/commissions/create.php');
    }

    $form['AssignmentId']     = post_int('AssignmentId');
    $form['CommissionType']   = post_string('CommissionType', 20);
    $form['CommissionRate']   = number_format(post_float('CommissionRate'), 2, '.', '');
    $form['CommissionAmount'] = number_format(post_float('CommissionAmount'), 2, '.', '');
    $form['ServiceAmount']    = number_format(post_float('ServiceAmount'), 2, '.', '');
    $form['CommissionDate']   = post_string('CommissionDate', 20);
    $form['Remarks']          = post_string('Remarks', 500);

    $selected = null;
    foreach ($assignments as $assignment) {
        if ((int) $assignment['uid'] === $form['AssignmentId']) {
            $selected = $assignment;
            break;
        }
    }

    if ($selected === null) {
        $errors[] = 'Please select a completed assignment without an existing commission.';
    }
    if (!in_array($form['CommissionType'], ['Percentage', 'Fixed'], true)) {
        $errors[] = 'Invalid commission type.';
    }
    if ((float) $form['CommissionAmount'] < 0 || (float) $form['ServiceAmount'] < 0 || (float) $form['CommissionRate'] < 0) {
        $errors[] = 'Amounts cannot be negative.';
    }
    if ($form['CommissionType'] === 'Percentage' && (float) $form['CommissionRate'] > 100) {
        $errors[] = 'Percentage rate cannot exceed 100.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['CommissionDate'])) {
        $errors[] = 'Commission date is invalid.';
    }

    if ($errors === [] && $selected !== null) {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO ProfessionalCommissions
                    (AssignmentId, ProfessionalId, ServiceRequestId, ServiceAmount,
                     CommissionType, CommissionRate, CommissionAmount, PaidAmount,
                     CommissionDate, CommissionStatus, Remarks)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, \'Pending\', ?)'
            );
            $insert->execute([
                (int) $selected['uid'],
                (int) $selected['ProfessionalId'],
                (int) $selected['ServiceRequestId'],
                (float) $form['ServiceAmount'],
                $form['CommissionType'],
                (float) $form['CommissionRate'],
                (float) $form['CommissionAmount'],
                $form['CommissionDate'],
                $form['Remarks'] !== '' ? $form['Remarks'] : null,
            ]);
            $newId = (int) $pdo->lastInsertId();
            regenerate_csrf_token();
            flash('success', 'Commission entry created.');
            redirect('modules/commissions/view.php?id=' . $newId);
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] commission create failed: ' . $exception->getMessage());
            if (str_contains($exception->getMessage(), 'UQ_ProfessionalCommissions_AssignmentId')) {
                $errors[] = 'A commission entry already exists for this assignment.';
            } else {
                $errors[] = 'Could not save the commission entry. Please try again.';
            }
        }
    }
}

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

<?php if ($assignments === []): ?>
    <div class="alert alert-warning">
        No completed assignments are waiting for a commission entry.
        Complete an assignment first, then return here.
    </div>
<?php endif; ?>

<form method="post" class="app-card card" id="commissionCreateForm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Commission details</strong>
        <a href="<?= e(url('modules/commissions/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Back to list</a>
    </div>
    <div class="card-body form-grid-blue">
        <?= csrf_field() ?>
        <div class="form-row-blue">
            <div class="form-field span-4">
                <label class="form-label" for="AssignmentId">Completed assignment <span class="text-danger">*</span></label>
                <select class="form-select" id="AssignmentId" name="AssignmentId" required
                        onchange="window.location='<?= e(url('modules/commissions/create.php')) ?>?assignment_id='+this.value;">
                    <option value="">Select assignment</option>
                    <?php foreach ($assignments as $assignment): ?>
                        <option value="<?= (int) $assignment['uid'] ?>" <?= $form['AssignmentId'] === (int) $assignment['uid'] ? 'selected' : '' ?>>
                            <?= e($assignment['RequestNo'] . ' — ' . $assignment['ProfessionalName'] . ' / ' . $assignment['ServiceName']
                                . ' (' . format_money($assignment['FinalAmount']) . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field">
                <label class="form-label" for="CommissionDate">Commission date</label>
                <input type="date" class="form-control" id="CommissionDate" name="CommissionDate"
                       value="<?= e($form['CommissionDate']) ?>" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="ServiceAmount">Service amount</label>
                <input type="number" step="0.01" min="0" class="form-control" id="ServiceAmount" name="ServiceAmount"
                       value="<?= e($form['ServiceAmount']) ?>">
            </div>
            <div class="form-field">
                <label class="form-label" for="CommissionType">Commission type</label>
                <select class="form-select" id="CommissionType" name="CommissionType">
                    <option value="Percentage" <?= $form['CommissionType'] === 'Percentage' ? 'selected' : '' ?>>Percentage</option>
                    <option value="Fixed" <?= $form['CommissionType'] === 'Fixed' ? 'selected' : '' ?>>Fixed</option>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="CommissionRate">Rate / value</label>
                <input type="number" step="0.01" min="0" class="form-control" id="CommissionRate" name="CommissionRate"
                       value="<?= e($form['CommissionRate']) ?>">
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field">
                <label class="form-label" for="CommissionAmount">Commission amount <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0" class="form-control" id="CommissionAmount" name="CommissionAmount"
                       value="<?= e($form['CommissionAmount']) ?>" required>
            </div>
            <div class="form-field span-3">
                <label class="form-label" for="Remarks">Remarks</label>
                <input type="text" class="form-control" id="Remarks" name="Remarks"
                       value="<?= e($form['Remarks']) ?>" maxlength="500">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('modules/commissions/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary" <?= $assignments === [] ? 'disabled' : '' ?>>
            <i class="bi bi-check2-circle me-1"></i>Save commission
        </button>
    </div>
</form>
<script>
(function () {
    var typeEl = document.getElementById('CommissionType');
    var rateEl = document.getElementById('CommissionRate');
    var serviceEl = document.getElementById('ServiceAmount');
    var amountEl = document.getElementById('CommissionAmount');
    function recalc() {
        if (!typeEl || !rateEl || !serviceEl || !amountEl) { return; }
        var rate = parseFloat(rateEl.value || '0');
        var service = parseFloat(serviceEl.value || '0');
        if (typeEl.value === 'Percentage') {
            amountEl.value = (service * rate / 100).toFixed(2);
        } else {
            amountEl.value = rate.toFixed(2);
        }
    }
    if (typeEl) { typeEl.addEventListener('change', recalc); }
    if (rateEl) { rateEl.addEventListener('input', recalc); }
    if (serviceEl) { serviceEl.addEventListener('input', recalc); }
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
