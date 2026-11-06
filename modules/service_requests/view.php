<?php
/**
 * View / update a service request.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$pdo = db();
$id  = get_int('id');
if ($id <= 0) {
    flash('danger', 'Invalid service request.');
    redirect('modules/service_requests/index.php');
}

$request = fetch_service_request($pdo, $id);
if ($request === null) {
    flash('danger', 'Service request not found.');
    redirect('modules/service_requests/index.php');
}

$activeMenu   = 'service_requests';
$pageTitle    = 'Request ' . (string) $request['RequestNo'];
$pageSubtitle = 'Request details and status actions.';

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/service_requests/view.php?id=' . $id);
    }

    $action = post_string('action', 30);

    try {
        if ($action === 'cancel' && !in_array((string) $request['RequestStatus'], ['Completed', 'Cancelled'], true)) {
            $reason = post_string('CancellationReason', 500);
            $update = $pdo->prepare(
                'UPDATE ServiceRequests
                    SET RequestStatus = \'Cancelled\',
                        CancelledDateTime = NOW(),
                        CancellationReason = ?
                  WHERE uid = ?'
            );
            $update->execute([$reason !== '' ? $reason : null, $id]);
            flash('success', 'Service request cancelled.');
        } elseif ($action === 'mark_in_progress' && in_array((string) $request['RequestStatus'], ['Assigned', 'Pending'], true)) {
            $update = $pdo->prepare(
                'UPDATE ServiceRequests SET RequestStatus = \'InProgress\' WHERE uid = ?'
            );
            $update->execute([$id]);
            flash('success', 'Service request marked as in progress.');
        } elseif ($action === 'mark_completed' && in_array((string) $request['RequestStatus'], ['Assigned', 'InProgress'], true)) {
            $pdo->beginTransaction();
            $update = $pdo->prepare(
                'UPDATE ServiceRequests
                    SET RequestStatus = \'Completed\', CompletedDateTime = NOW()
                  WHERE uid = ?'
            );
            $update->execute([$id]);
            $assignUpdate = $pdo->prepare(
                "UPDATE ServiceAssignments
                    SET AssignmentStatus = 'Completed',
                        CompletionDateTime = COALESCE(CompletionDateTime, NOW())
                  WHERE ServiceRequestId = ?
                    AND AssignmentStatus IN ('Assigned','Accepted','InProgress')"
            );
            $assignUpdate->execute([$id]);
            $pdo->commit();
            flash('success', 'Service request marked as completed.');
        } else {
            flash('warning', 'That status change is not allowed for the current request.');
        }
        regenerate_csrf_token();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ServiceManagement] update request failed: ' . $exception->getMessage());
        flash('danger', 'Could not update the service request.');
    }

    redirect('modules/service_requests/view.php?id=' . $id);
}

$assignments = [];
try {
    $statement = $pdo->prepare(
        'SELECT sa.uid, sa.AssignedDateTime, sa.AssignmentStatus, sa.AgreedCommission, sa.CommissionType,
                sp.FullName AS ProfessionalName, sp.ProfessionalCode, sp.MobileNo AS ProfessionalMobile
           FROM ServiceAssignments sa
           INNER JOIN ServiceProfessionals sp ON sp.uid = sa.ProfessionalId
          WHERE sa.ServiceRequestId = ?
          ORDER BY sa.AssignedDateTime DESC'
    );
    $statement->execute([$id]);
    $assignments = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] request assignments failed: ' . $exception->getMessage());
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= e(url('modules/service_requests/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <?php if (in_array((string) $request['RequestStatus'], ['Pending', 'Assigned'], true)): ?>
            <a href="<?= e(url('modules/service_assignments/create.php?request_id=' . $id)) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-person-check me-1"></i>Assign professional
            </a>
        <?php endif; ?>
    </div>
    <div><?= status_badge((string) $request['RequestStatus']) ?></div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="app-card card mb-3">
            <div class="card-header"><strong>Request information</strong></div>
            <div class="card-body detail-rows">
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-4"><div class="text-muted small">Request no</div><div class="fw-semibold"><?= e((string) $request['RequestNo']) ?></div></div>
                        <div class="col-md-4"><div class="text-muted small">Created</div><div><?= e(format_datetime((string) $request['RequestDateTime'])) ?></div></div>
                        <div class="col-md-4"><div class="text-muted small">Priority</div><div><?= e((string) $request['Priority']) ?></div></div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small">Customer</div>
                            <div class="fw-semibold">
                                <a class="theme-link" href="<?= e(url('modules/customers/index.php')) ?>"><?= e((string) $request['CustomerName']) ?></a>
                            </div>
                            <div class="small text-muted"><?= e((string) $request['CustomerCode'] . ' · ' . $request['CustomerMobile']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Service</div>
                            <div class="fw-semibold">
                                <a class="theme-link" href="<?= e(url('modules/service_types/index.php')) ?>"><?= e((string) $request['ServiceName']) ?></a>
                            </div>
                            <div class="small text-muted"><?= e((string) $request['CategoryName']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="text-muted small">Service address</div>
                    <div><?= e((string) $request['ServiceAddress']) ?></div>
                </div>
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small">Preferred date / slot</div>
                            <div>
                                <?= e(!empty($request['PreferredDate']) ? format_datetime((string) $request['PreferredDate'], 'd M Y') : '-') ?>
                                <?= e(!empty($request['PreferredTimeSlot']) ? ' · ' . $request['PreferredTimeSlot'] : '') ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Amounts</div>
                            <div>
                                Est. <?= e(format_money($request['EstimatedAmount'])) ?>
                                · Disc. <?= e(format_money($request['DiscountAmount'])) ?>
                                · <strong>Final <?= e(format_money($request['FinalAmount'])) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="text-muted small">Problem description</div>
                    <div><?= e((string) ($request['ProblemDescription'] ?: '-')) ?></div>
                </div>
                <?php if (!empty($request['Remarks'])): ?>
                    <div class="detail-row">
                        <div class="text-muted small">Remarks</div>
                        <div><?= e((string) $request['Remarks']) ?></div>
                    </div>
                <?php endif; ?>
                <?php if ((string) $request['RequestStatus'] === 'Cancelled'): ?>
                    <div class="detail-row">
                        <div class="text-muted small">Cancellation</div>
                        <div><?= e(format_datetime((string) ($request['CancelledDateTime'] ?? ''))) ?> — <?= e((string) ($request['CancellationReason'] ?: 'No reason provided')) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="app-card card">
            <div class="card-header"><strong>Assignments</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Professional</th>
                            <th>Assigned</th>
                            <th>Commission</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($assignments === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No assignments yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= e((string) $assignment['ProfessionalName']) ?></div>
                                        <div class="small text-muted"><?= e((string) $assignment['ProfessionalCode'] . ' · ' . $assignment['ProfessionalMobile']) ?></div>
                                    </td>
                                    <td><?= e(format_datetime((string) $assignment['AssignedDateTime'])) ?></td>
                                    <td>
                                        <?= e((string) $assignment['CommissionType'] === 'Percentage'
                                            ? number_format((float) $assignment['AgreedCommission'], 2) . '%'
                                            : format_money($assignment['AgreedCommission'])) ?>
                                    </td>
                                    <td><?= status_badge((string) $assignment['AssignmentStatus']) ?></td>
                                    <td class="text-end">
                                        <a class="theme-link theme-link-btn" href="<?= e(url('modules/service_assignments/view.php?id=' . (int) $assignment['uid'])) ?>">
                                            <i class="bi bi-box-arrow-up-right me-1"></i>Open
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
    </div>

    <div class="col-lg-4">
        <div class="app-card card">
            <div class="card-header"><strong>Status actions</strong></div>
            <div class="card-body d-grid gap-2">
                <?php if (in_array((string) $request['RequestStatus'], ['Pending', 'Assigned'], true)): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_in_progress">
                        <button type="submit" class="btn btn-outline-primary w-100">Mark in progress</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array((string) $request['RequestStatus'], ['Assigned', 'InProgress'], true)): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_completed">
                        <button type="submit" class="btn btn-outline-success w-100">Mark completed</button>
                    </form>
                <?php endif; ?>

                <?php if (!in_array((string) $request['RequestStatus'], ['Completed', 'Cancelled'], true)): ?>
                    <hr>
                    <form method="post" class="d-grid gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel">
                        <label class="form-label mb-0" for="CancellationReason">Cancellation reason</label>
                        <textarea class="form-control" id="CancellationReason" name="CancellationReason" rows="2" maxlength="500"></textarea>
                        <button type="submit" class="btn btn-outline-danger"
                                onclick="return confirm('Cancel this service request?');">Cancel request</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array((string) $request['RequestStatus'], ['Completed', 'Cancelled'], true)): ?>
                    <p class="text-muted mb-0 small">No further status actions are available.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
