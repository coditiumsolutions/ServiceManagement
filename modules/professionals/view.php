<?php
/**
 * Professional Detail — profile and related activity.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/payroll_ops.php';
require_login();

$pdo = db();
$id  = get_int('id');
if ($id <= 0) {
    flash('danger', 'Invalid professional.');
    redirect('modules/professionals/index.php');
}

$professional = fetch_professional($pdo, $id);
if ($professional === null) {
    flash('danger', 'Professional not found.');
    redirect('modules/professionals/index.php');
}

$activeMenu   = 'professionals';
$pageTitle    = (string) $professional['FullName'];
$pageSubtitle = 'Professional detail — ' . (string) $professional['ProfessionalCode'];

$salary = null;
try {
    $salary = current_salary_history($pdo, $id);
} catch (PDOException $exception) {
    error_log('[ServiceManagement] professional salary failed: ' . $exception->getMessage());
}

$assignments = [];
try {
    $statement = $pdo->prepare(
        "SELECT sa.uid, sa.AssignedDateTime, sa.AssignmentStatus, sa.AgreedCommission, sa.CommissionType,
                sr.RequestNo, st.ServiceName, c.FullName AS CustomerName
           FROM ServiceAssignments sa
           INNER JOIN ServiceRequests sr ON sr.uid = sa.ServiceRequestId
           INNER JOIN ServiceTypes st ON st.uid = sr.ServiceTypeId
           INNER JOIN Customers c ON c.uid = sr.CustomerId
          WHERE sa.ProfessionalId = ?
          ORDER BY sa.AssignedDateTime DESC
          LIMIT 10"
    );
    $statement->execute([$id]);
    $assignments = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] professional assignments failed: ' . $exception->getMessage());
}

$commissionSummary = [
    'earned' => 0.0,
    'paid'   => 0.0,
    'open'   => 0.0,
];
try {
    $statement = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CommissionAmount), 0) AS earned,
            COALESCE(SUM(PaidAmount), 0) AS paid
           FROM ProfessionalCommissions
          WHERE ProfessionalId = ?
            AND CommissionStatus <> 'Cancelled'"
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    if ($row !== false) {
        $commissionSummary['earned'] = (float) $row['earned'];
        $commissionSummary['paid'] = (float) $row['paid'];
        $commissionSummary['open'] = max(0, $commissionSummary['earned'] - $commissionSummary['paid']);
    }
} catch (PDOException $exception) {
    error_log('[ServiceManagement] professional commission summary failed: ' . $exception->getMessage());
}

$payroll = null;
try {
    $statement = $pdo->prepare(
        "SELECT uid, PayrollNo, PayrollMonth, NetPayable, PaidAmount, BalanceAmount, PayrollStatus
           FROM ProfessionalPayroll
          WHERE ProfessionalId = ?
            AND PayrollStatus <> 'Cancelled'
          ORDER BY PayrollMonth DESC
          LIMIT 1"
    );
    $statement->execute([$id]);
    $payroll = $statement->fetch() ?: null;
} catch (PDOException $exception) {
    error_log('[ServiceManagement] professional payroll summary failed: ' . $exception->getMessage());
}

$isActive = (int) $professional['IsActive'] === 1;

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= e(url('modules/professionals/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <a href="<?= e(url('modules/professionals/edit.php?id=' . $id)) ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        <a href="<?= e(url('modules/salary/create.php?professional_id=' . $id)) ?>" class="theme-link-btn btn-sm">
            Salary package
        </a>
        <a href="<?= e(url('modules/payroll/index.php?professional_id=' . $id)) ?>" class="theme-link-btn btn-sm">
            Salary payment
        </a>
    </div>
    <div>
        <?php if ($isActive): ?>
            <span class="badge badge-soft-success">Active</span>
        <?php else: ?>
            <span class="badge badge-soft-secondary">Inactive</span>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Current basic</div>
            <div class="fw-semibold"><?= e(format_money($salary['BasicSalary'] ?? 0)) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Allowances</div>
            <div class="fw-semibold"><?= e(format_money($salary['Allowances'] ?? 0)) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Commission earned</div>
            <div class="fw-semibold"><?= e(format_money($commissionSummary['earned'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-card card h-100"><div class="card-body py-3">
            <div class="text-muted small">Commission open</div>
            <div class="fw-semibold"><?= e(format_money($commissionSummary['open'])) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="app-card card mb-3">
            <div class="card-header"><strong>Profile</strong></div>
            <div class="card-body detail-rows">
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Code</div>
                            <div class="fw-semibold"><?= e((string) $professional['ProfessionalCode']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Full name</div>
                            <div class="fw-semibold"><?= e((string) $professional['FullName']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Employment</div>
                            <div><?= e(status_label((string) $professional['EmploymentType'])) ?></div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Mobile</div>
                            <div><?= e((string) $professional['MobileNo']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Alternate mobile</div>
                            <div><?= e((string) ($professional['AlternateMobileNo'] ?: '—')) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Email</div>
                            <div><?= e((string) ($professional['Email'] ?: '—')) ?></div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">CNIC</div>
                            <div><?= e((string) ($professional['CNIC'] ?: '—')) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">City</div>
                            <div><?= e((string) ($professional['City'] ?: '—')) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Primary category</div>
                            <div><?= e((string) ($professional['CategoryName'] ?: '—')) ?></div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <div class="text-muted small">Address</div>
                            <div><?= e((string) ($professional['Address'] ?: '—')) ?></div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Joining date</div>
                            <div><?= e(format_datetime((string) $professional['JoiningDate'], 'd M Y')) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Leaving date</div>
                            <div><?= e($professional['LeavingDate'] ? format_datetime((string) $professional['LeavingDate'], 'd M Y') : '—') ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Bank</div>
                            <div>
                                <?= e((string) ($professional['BankName'] ?: '—')) ?>
                                <?php if (!empty($professional['BankAccountNo'])): ?>
                                    <div class="small text-muted"><?= e((string) $professional['BankAccountNo']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-card card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Recent assignments</strong>
                <a class="theme-link-btn btn-sm" href="<?= e(url('modules/service_assignments/index.php?q=' . urlencode((string) $professional['ProfessionalCode']))) ?>">View all</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Request</th>
                            <th>Service</th>
                            <th>Status</th>
                            <th>Assigned</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($assignments === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No assignments yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e((string) $assignment['RequestNo']) ?></td>
                                    <td>
                                        <div><?= e((string) $assignment['ServiceName']) ?></div>
                                        <div class="small text-muted"><?= e((string) $assignment['CustomerName']) ?></div>
                                    </td>
                                    <td><?= status_badge((string) $assignment['AssignmentStatus']) ?></td>
                                    <td class="text-nowrap"><?= e(format_datetime((string) $assignment['AssignedDateTime'], 'd M Y')) ?></td>
                                    <td class="text-end">
                                        <a class="theme-link-btn" href="<?= e(url('modules/service_assignments/view.php?id=' . (int) $assignment['uid'])) ?>">Open</a>
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

    <div class="col-lg-5">
        <div class="app-card card mb-3">
            <div class="card-header"><strong>Current salary package</strong></div>
            <div class="card-body">
                <?php if ($salary === null): ?>
                    <p class="text-muted mb-2">No salary package on record.</p>
                    <a href="<?= e(url('modules/salary/create.php?professional_id=' . $id)) ?>" class="btn btn-sm btn-primary">Add salary package</a>
                <?php else: ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Basic</span>
                        <span class="fw-semibold"><?= e(format_money($salary['BasicSalary'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Allowances</span>
                        <span class="fw-semibold"><?= e(format_money($salary['Allowances'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Total package</span>
                        <span class="fw-semibold"><?= e(format_money((float) $salary['BasicSalary'] + (float) $salary['Allowances'])) ?></span>
                    </div>
                    <div class="small text-muted mb-3">
                        Effective from <?= e(format_datetime((string) $salary['EffectiveFrom'], 'd M Y')) ?>
                    </div>
                    <a href="<?= e(url('modules/salary/index.php?professional_id=' . $id . '&current=1')) ?>" class="theme-link-btn btn-sm">Manage packages</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="app-card card mb-3">
            <div class="card-header"><strong>Latest salary payment</strong></div>
            <div class="card-body">
                <?php if ($payroll === null): ?>
                    <p class="text-muted mb-0">No salary payment month generated yet.</p>
                <?php else: ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Month</span>
                        <span><?= e(format_datetime((string) $payroll['PayrollMonth'], 'M Y')) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Total</span>
                        <span class="fw-semibold"><?= e(format_money($payroll['NetPayable'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Paid</span>
                        <span><?= e(format_money($payroll['PaidAmount'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Remaining</span>
                        <span class="fw-semibold"><?= e(format_money($payroll['BalanceAmount'])) ?></span>
                    </div>
                    <div class="mb-3"><?= status_badge((string) $payroll['PayrollStatus']) ?></div>
                    <a href="<?= e(url('modules/payroll/view.php?id=' . (int) $payroll['uid'])) ?>" class="theme-link-btn btn-sm">Open salary payment</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="app-card card">
            <div class="card-header"><strong>Commission summary</strong></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Earned</span>
                    <span><?= e(format_money($commissionSummary['earned'])) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Paid</span>
                    <span><?= e(format_money($commissionSummary['paid'])) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-3">
                    <span class="text-muted">Open</span>
                    <span class="fw-semibold"><?= e(format_money($commissionSummary['open'])) ?></span>
                </div>
                <a href="<?= e(url('modules/commissions/index.php?professional_id=' . $id)) ?>" class="theme-link-btn btn-sm">Commission entries</a>
                <a href="<?= e(url('modules/commissions/ledger.php?professional_id=' . $id)) ?>" class="theme-link-btn btn-sm ms-2">Ledger</a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
