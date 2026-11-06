<?php
/**
 * Assign a professional to a service request.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$activeMenu   = 'service_assignments';
$pageTitle    = 'New Service Assignment';
$pageSubtitle = 'Link a professional to a customer request.';

$pdo = db();
$requests = assignable_service_requests($pdo);
$professionals = active_professionals($pdo);

$form = [
    'ServiceRequestId' => get_int('request_id'),
    'ProfessionalId'   => 0,
    'AgreedCommission' => '0.00',
    'CommissionType'   => 'Percentage',
    'Remarks'          => '',
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/service_assignments/create.php');
    }

    $form['ServiceRequestId'] = post_int('ServiceRequestId');
    $form['ProfessionalId']   = post_int('ProfessionalId');
    $form['AgreedCommission'] = number_format(post_float('AgreedCommission'), 2, '.', '');
    $form['CommissionType']   = post_string('CommissionType', 20);
    $form['Remarks']          = post_string('Remarks', 1000);

    if ($form['ServiceRequestId'] <= 0) {
        $errors[] = 'Please select a service request.';
    }
    if ($form['ProfessionalId'] <= 0) {
        $errors[] = 'Please select a professional.';
    }
    if (!in_array($form['CommissionType'], ['Percentage', 'Fixed'], true)) {
        $errors[] = 'Invalid commission type.';
    }

    $commission = (float) $form['AgreedCommission'];
    if ($commission < 0) {
        $errors[] = 'Agreed commission cannot be negative.';
    }
    if ($form['CommissionType'] === 'Percentage' && $commission > 100) {
        $errors[] = 'Percentage commission cannot exceed 100.';
    }

    $request = $form['ServiceRequestId'] > 0 ? fetch_service_request($pdo, $form['ServiceRequestId']) : null;
    if ($form['ServiceRequestId'] > 0 && ($request === null || !in_array((string) $request['RequestStatus'], ['Pending', 'Assigned'], true))) {
        $errors[] = 'Selected request cannot be assigned in its current status.';
    }

    $professionalOk = false;
    foreach ($professionals as $professional) {
        if ((int) $professional['uid'] === $form['ProfessionalId']) {
            $professionalOk = true;
            break;
        }
    }
    if ($form['ProfessionalId'] > 0 && !$professionalOk) {
        $errors[] = 'Selected professional is not available.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            // Mark previous active assignments as reassigned when replacing.
            $reassign = $pdo->prepare(
                "UPDATE ServiceAssignments
                    SET AssignmentStatus = 'Reassigned'
                  WHERE ServiceRequestId = ?
                    AND AssignmentStatus IN ('Assigned','Accepted','InProgress')"
            );
            $reassign->execute([$form['ServiceRequestId']]);

            $insert = $pdo->prepare(
                'INSERT INTO ServiceAssignments
                    (ServiceRequestId, ProfessionalId, AgreedCommission, CommissionType, AssignmentStatus, Remarks)
                 VALUES (?, ?, ?, ?, \'Assigned\', ?)'
            );
            $insert->execute([
                $form['ServiceRequestId'],
                $form['ProfessionalId'],
                $commission,
                $form['CommissionType'],
                $form['Remarks'] !== '' ? $form['Remarks'] : null,
            ]);
            $newId = (int) $pdo->lastInsertId();

            $updateRequest = $pdo->prepare(
                "UPDATE ServiceRequests
                    SET RequestStatus = 'Assigned'
                  WHERE uid = ?
                    AND RequestStatus IN ('Pending','Assigned')"
            );
            $updateRequest->execute([$form['ServiceRequestId']]);

            $pdo->commit();
            regenerate_csrf_token();
            flash('success', 'Professional assigned successfully.');
            redirect('modules/service_assignments/view.php?id=' . $newId);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ServiceManagement] create assignment failed: ' . $exception->getMessage());
            $errors[] = 'Could not save the assignment. Please try again.';
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

<?php if ($requests === [] || $professionals === []): ?>
    <div class="alert alert-warning">
        Need at least one pending/assigned request and one active professional to create an assignment.
    </div>
<?php endif; ?>

<form method="post" class="app-card card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Assignment details</strong>
        <a href="<?= e(url('modules/service_assignments/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Back to list</a>
    </div>
    <div class="card-body form-grid-blue">
        <?= csrf_field() ?>

        <div class="form-row-blue">
            <div class="form-field">
                <label class="form-label" for="ServiceRequestId">Service request <span class="text-danger">*</span></label>
                <select class="form-select" id="ServiceRequestId" name="ServiceRequestId" required>
                    <option value="">Select request</option>
                    <?php foreach ($requests as $request): ?>
                        <option value="<?= (int) $request['uid'] ?>" <?= $form['ServiceRequestId'] === (int) $request['uid'] ? 'selected' : '' ?>>
                            <?= e($request['RequestNo'] . ' — ' . $request['CustomerName'] . ' / ' . $request['ServiceName'] . ' [' . status_label((string) $request['RequestStatus']) . ']') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="ProfessionalId">Professional <span class="text-danger">*</span></label>
                <select class="form-select" id="ProfessionalId" name="ProfessionalId" required>
                    <option value="">Select professional</option>
                    <?php foreach ($professionals as $professional): ?>
                        <option value="<?= (int) $professional['uid'] ?>" <?= $form['ProfessionalId'] === (int) $professional['uid'] ? 'selected' : '' ?>>
                            <?= e($professional['FullName'] . ' (' . $professional['ProfessionalCode'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="CommissionType">Commission type</label>
                <select class="form-select" id="CommissionType" name="CommissionType">
                    <option value="Percentage" <?= $form['CommissionType'] === 'Percentage' ? 'selected' : '' ?>>Percentage</option>
                    <option value="Fixed" <?= $form['CommissionType'] === 'Fixed' ? 'selected' : '' ?>>Fixed</option>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="AgreedCommission">Agreed commission</label>
                <input type="number" step="0.01" min="0" class="form-control" id="AgreedCommission" name="AgreedCommission"
                       value="<?= e($form['AgreedCommission']) ?>">
                <div class="form-text">For percentage, enter 0–100.</div>
            </div>
        </div>

        <div class="form-row-blue">
            <div class="form-field span-4">
                <label class="form-label" for="Remarks">Remarks</label>
                <input type="text" class="form-control" id="Remarks" name="Remarks" value="<?= e($form['Remarks']) ?>" maxlength="1000">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('modules/service_assignments/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary" <?= ($requests === [] || $professionals === []) ? 'disabled' : '' ?>>
            <i class="bi bi-check2-circle me-1"></i>Save assignment
        </button>
    </div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
