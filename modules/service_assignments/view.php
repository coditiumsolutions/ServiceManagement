<?php
/**
 * View / update a service assignment.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$pdo = db();
$id  = get_int('id');
if ($id <= 0) {
    flash('danger', 'Invalid service assignment.');
    redirect('modules/service_assignments/index.php');
}

$assignment = fetch_service_assignment($pdo, $id);
if ($assignment === null) {
    flash('danger', 'Service assignment not found.');
    redirect('modules/service_assignments/index.php');
}

$activeMenu   = 'service_assignments';
$pageTitle    = 'Assignment #' . $id;
$pageSubtitle = 'Assignment details and workflow actions.';

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/service_assignments/view.php?id=' . $id);
    }

    $action = post_string('action', 40);
    $current = (string) $assignment['AssignmentStatus'];

    try {
        if ($action === 'accept' && $current === 'Assigned') {
            $pdo->prepare("UPDATE ServiceAssignments SET AssignmentStatus = 'Accepted' WHERE uid = ?")->execute([$id]);
            flash('success', 'Assignment accepted.');
        } elseif ($action === 'start' && in_array($current, ['Assigned', 'Accepted'], true)) {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE ServiceAssignments
                    SET AssignmentStatus = 'InProgress',
                        ServiceStartDateTime = COALESCE(ServiceStartDateTime, NOW())
                  WHERE uid = ?"
            )->execute([$id]);
            $pdo->prepare(
                "UPDATE ServiceRequests
                    SET RequestStatus = 'InProgress'
                  WHERE uid = ?
                    AND RequestStatus IN ('Pending','Assigned','InProgress')"
            )->execute([(int) $assignment['ServiceRequestId']]);
            $pdo->commit();
            flash('success', 'Service started.');
        } elseif ($action === 'complete' && in_array($current, ['Assigned', 'Accepted', 'InProgress'], true)) {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE ServiceAssignments
                    SET AssignmentStatus = 'Completed',
                        ServiceStartDateTime = COALESCE(ServiceStartDateTime, NOW()),
                        CompletionDateTime = NOW()
                  WHERE uid = ?"
            )->execute([$id]);
            $pdo->prepare(
                "UPDATE ServiceRequests
                    SET RequestStatus = 'Completed',
                        CompletedDateTime = NOW()
                  WHERE uid = ?"
            )->execute([(int) $assignment['ServiceRequestId']]);
            $pdo->commit();
            flash('success', 'Assignment and request marked completed.');
        } elseif ($action === 'cancel' && !in_array($current, ['Completed', 'Cancelled', 'Reassigned'], true)) {
            $pdo->prepare("UPDATE ServiceAssignments SET AssignmentStatus = 'Cancelled' WHERE uid = ?")->execute([$id]);
            flash('success', 'Assignment cancelled.');
        } else {
            flash('warning', 'That action is not allowed for the current assignment status.');
        }
        regenerate_csrf_token();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ServiceManagement] update assignment failed: ' . $exception->getMessage());
        flash('danger', 'Could not update the assignment.');
    }

    redirect('modules/service_assignments/view.php?id=' . $id);
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= e(url('modules/service_assignments/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <a href="<?= e(url('modules/service_requests/view.php?id=' . (int) $assignment['ServiceRequestId'])) ?>" class="theme-link-btn btn-sm">
            <i class="bi bi-clipboard-data me-1"></i>Open request
        </a>
    </div>
    <div><?= status_badge((string) $assignment['AssignmentStatus']) ?></div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="app-card card">
            <div class="card-header"><strong>Assignment information</strong></div>
            <div class="card-body detail-rows">
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Request</div>
                            <div class="fw-semibold">
                                <a class="theme-link" href="<?= e(url('modules/service_requests/view.php?id=' . (int) $assignment['ServiceRequestId'])) ?>">
                                    <?= e((string) $assignment['RequestNo']) ?>
                                </a>
                            </div>
                            <div class="small text-muted">Request status: <?= e(status_label((string) $assignment['RequestStatus'])) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Customer</div>
                            <div class="fw-semibold">
                                <a class="theme-link" href="<?= e(url('modules/customers/index.php')) ?>"><?= e((string) $assignment['CustomerName']) ?></a>
                            </div>
                            <div class="small text-muted"><?= e((string) $assignment['CustomerMobile']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Service</div>
                            <div class="fw-semibold">
                                <a class="theme-link" href="<?= e(url('modules/service_types/index.php')) ?>"><?= e((string) $assignment['ServiceName']) ?></a>
                            </div>
                            <div class="small text-muted">Final <?= e(format_money($assignment['FinalAmount'])) ?></div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small">Professional</div>
                            <div class="fw-semibold">
                                <a class="theme-link" href="<?= e(url('modules/professionals/index.php')) ?>"><?= e((string) $assignment['ProfessionalName']) ?></a>
                            </div>
                            <div class="small text-muted"><?= e((string) $assignment['ProfessionalCode'] . ' · ' . $assignment['ProfessionalMobile']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Commission</div>
                            <div>
                                <?= e((string) $assignment['CommissionType'] === 'Percentage'
                                    ? number_format((float) $assignment['AgreedCommission'], 2) . '%'
                                    : format_money($assignment['AgreedCommission'])) ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Assigned at</div>
                            <div><?= e(format_datetime((string) $assignment['AssignedDateTime'])) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Started at</div>
                            <div><?= e(format_datetime((string) ($assignment['ServiceStartDateTime'] ?? ''))) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Completed at</div>
                            <div><?= e(format_datetime((string) ($assignment['CompletionDateTime'] ?? ''))) ?></div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="text-muted small">Service address</div>
                    <div><?= e((string) $assignment['ServiceAddress']) ?></div>
                </div>
                <?php if (!empty($assignment['Remarks'])): ?>
                    <div class="detail-row">
                        <div class="text-muted small">Remarks</div>
                        <div><?= e((string) $assignment['Remarks']) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="app-card card">
            <div class="card-header"><strong>Workflow actions</strong></div>
            <div class="card-body d-grid gap-2">
                <?php if ((string) $assignment['AssignmentStatus'] === 'Assigned'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="accept">
                        <button class="btn btn-outline-info w-100" type="submit">Mark accepted</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array((string) $assignment['AssignmentStatus'], ['Assigned', 'Accepted'], true)): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="start">
                        <button class="btn btn-outline-primary w-100" type="submit">Start service</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array((string) $assignment['AssignmentStatus'], ['Assigned', 'Accepted', 'InProgress'], true)): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="complete">
                        <button class="btn btn-outline-success w-100" type="submit">Complete assignment</button>
                    </form>
                <?php endif; ?>

                <?php if (!in_array((string) $assignment['AssignmentStatus'], ['Completed', 'Cancelled', 'Reassigned'], true)): ?>
                    <form method="post" onsubmit="return confirm('Cancel this assignment?');">
                        <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                        <button class="btn btn-outline-danger w-100" type="submit">Cancel assignment</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0 small">No further workflow actions are available.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
