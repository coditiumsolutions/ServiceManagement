<?php
/**
 * Salary History — list salary revisions for professionals.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/payroll_ops.php';
require_login();

$activeMenu   = 'salary_history';
$pageTitle    = 'Salary Packages';
$pageSubtitle = 'Basic salary and allowances used in monthly labor totals.';

$search          = get_string('q', 100);
$professionalId  = get_int('professional_id');
$currentOnly     = get_string('current', 5) === '1';

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(sp.FullName LIKE ? OR sp.ProfessionalCode LIKE ? OR sp.MobileNo LIKE ? OR sh.RevisionReason LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($professionalId > 0) {
    $where[] = 'sh.ProfessionalId = ?';
    $params[] = $professionalId;
}
if ($currentOnly) {
    $where[] = 'sh.EffectiveTo IS NULL';
}

$sql = 'SELECT sh.uid, sh.BasicSalary, sh.Allowances, sh.EffectiveFrom, sh.EffectiveTo, sh.RevisionReason, sh.created_at,
               sp.uid AS ProfessionalId, sp.ProfessionalCode, sp.FullName AS ProfessionalName, sp.MobileNo, sp.EmploymentType
          FROM ProfessionalSalaryHistory sh
          INNER JOIN ServiceProfessionals sp ON sp.uid = sh.ProfessionalId
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY sh.EffectiveFrom DESC, sh.uid DESC
         LIMIT 300';

try {
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('[ServiceManagement] salary history list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load salary history right now.');
    $rows = [];
}

$professionals = [];
try {
    $professionals = active_professionals(db());
} catch (PDOException $exception) {
    error_log('[ServiceManagement] salary professionals failed: ' . $exception->getMessage());
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-3">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Name, code, reason">
        </div>
        <div class="col-md-3">
            <label class="form-label mb-1" for="professional_id">Professional</label>
            <select class="form-select" id="professional_id" name="professional_id">
                <option value="">All</option>
                <?php foreach ($professionals as $professional): ?>
                    <option value="<?= (int) $professional['uid'] ?>" <?= $professionalId === (int) $professional['uid'] ? 'selected' : '' ?>>
                        <?= e($professional['FullName'] . ' (' . $professional['ProfessionalCode'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="current">Show</label>
            <select class="form-select" id="current" name="current">
                <option value="">All revisions</option>
                <option value="1" <?= $currentOnly ? 'selected' : '' ?>>Current only</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="<?= e(url('modules/salary/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    <div>
        <a href="<?= e(url('modules/salary/create.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add salary revision
        </a>
    </div>
</div>

<div class="app-card card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Professional</th>
                    <th class="text-end">Basic</th>
                    <th class="text-end">Allowances</th>
                    <th class="text-end">Total</th>
                    <th>Effective from</th>
                    <th>Effective to</th>
                    <th>Reason</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No salary revisions found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $isCurrent = empty($row['EffectiveTo']);
                        $total = (float) $row['BasicSalary'] + (float) $row['Allowances'];
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e((string) $row['ProfessionalName']) ?></div>
                                <div class="small text-muted"><?= e((string) $row['ProfessionalCode'] . ' · ' . $row['MobileNo']) ?></div>
                            </td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['BasicSalary'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['Allowances'])) ?></td>
                            <td class="text-end text-nowrap fw-semibold"><?= e(format_money($total)) ?></td>
                            <td class="text-nowrap"><?= e(format_datetime((string) $row['EffectiveFrom'], 'd M Y')) ?></td>
                            <td class="text-nowrap">
                                <?php if ($isCurrent): ?>
                                    <span class="badge badge-soft-success">Current</span>
                                <?php else: ?>
                                    <?= e(format_datetime((string) $row['EffectiveTo'], 'd M Y')) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($row['RevisionReason'] ?: '—')) ?></td>
                            <td class="text-end">
                                <a class="theme-link-btn" href="<?= e(url('modules/salary/create.php?professional_id=' . (int) $row['ProfessionalId'])) ?>">
                                    Revise
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
<?php require __DIR__ . '/../../includes/footer.php'; ?>
