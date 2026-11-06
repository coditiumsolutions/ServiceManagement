<?php
/**
 * Professional Ledger — combined salary & commission activity with running balance.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/commission_ops.php';
require_login();

$activeMenu   = 'professional_ledger';
$pageTitle    = 'Professional Ledger';
$pageSubtitle = 'Running balance of commissions, payroll and payments.';

$pdo = db();
$professionals = [];
try {
    $professionals = active_professionals($pdo);
} catch (PDOException $exception) {
    error_log('[ServiceManagement] ledger professionals failed: ' . $exception->getMessage());
}

$professionalId = get_int('professional_id');
if ($professionalId <= 0 && $professionals !== []) {
    $professionalId = (int) $professionals[0]['uid'];
}

$fromDate = get_string('from', 20);
$toDate   = get_string('to', 20);
if ($fromDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = '';
}
if ($toDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = '';
}

$selectedProfessional = null;
foreach ($professionals as $professional) {
    if ((int) $professional['uid'] === $professionalId) {
        $selectedProfessional = $professional;
        break;
    }
}

$ledger = ['entries' => [], 'summary' => ['credit' => 0.0, 'debit' => 0.0, 'balance' => 0.0]];
if ($selectedProfessional !== null) {
    try {
        $ledger = professional_ledger(
            $pdo,
            $professionalId,
            $fromDate !== '' ? $fromDate : null,
            $toDate !== '' ? $toDate : null
        );
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] ledger failed: ' . $exception->getMessage());
        flash('danger', 'Unable to load the professional ledger right now.');
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-4">
        <label class="form-label mb-1" for="professional_id">Professional</label>
        <select class="form-select" id="professional_id" name="professional_id" required>
            <?php if ($professionals === []): ?>
                <option value="">No professionals found</option>
            <?php else: ?>
                <?php foreach ($professionals as $professional): ?>
                    <option value="<?= (int) $professional['uid'] ?>" <?= $professionalId === (int) $professional['uid'] ? 'selected' : '' ?>>
                        <?= e($professional['FullName'] . ' (' . $professional['ProfessionalCode'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label mb-1" for="from">From</label>
        <input type="date" class="form-control" id="from" name="from" value="<?= e($fromDate) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label mb-1" for="to">To</label>
        <input type="date" class="form-control" id="to" name="to" value="<?= e($toDate) ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Show ledger</button>
        <a href="<?= e(url('modules/commissions/ledger.php')) ?>" class="btn btn-outline-secondary">Reset</a>
    </div>
</form>

<?php if ($selectedProfessional === null): ?>
    <div class="alert alert-warning">Select a professional to view the ledger.</div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="app-card card h-100">
                <div class="card-body">
                    <div class="text-muted small">Total credits</div>
                    <div class="fs-5 fw-semibold text-success"><?= e(format_money($ledger['summary']['credit'])) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="app-card card h-100">
                <div class="card-body">
                    <div class="text-muted small">Total debits</div>
                    <div class="fs-5 fw-semibold text-danger"><?= e(format_money($ledger['summary']['debit'])) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="app-card card h-100">
                <div class="card-body">
                    <div class="text-muted small">Running balance</div>
                    <div class="fs-5 fw-semibold"><?= e(format_money($ledger['summary']['balance'])) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-card card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><?= e((string) $selectedProfessional['FullName']) ?> — ledger</strong>
            <span class="small text-muted"><?= e((string) $selectedProfessional['ProfessionalCode'] . ' · ' . $selectedProfessional['EmploymentType']) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>Description</th>
                        <th class="text-end">Credit</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Balance</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($ledger['entries'] === []): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No ledger activity for this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($ledger['entries'] as $entry): ?>
                            <tr>
                                <td class="text-nowrap"><?= e(format_datetime((string) $entry['EntryDate'], 'd M Y')) ?></td>
                                <td><span class="badge badge-soft-secondary"><?= e((string) $entry['Type']) ?></span></td>
                                <td>
                                    <?php if (!empty($entry['Link'])): ?>
                                        <a class="theme-link" href="<?= e(url((string) $entry['Link'])) ?>"><?= e((string) $entry['Reference']) ?></a>
                                    <?php else: ?>
                                        <?= e((string) $entry['Reference']) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) $entry['Description']) ?></td>
                                <td class="text-end text-nowrap <?= $entry['Credit'] > 0 ? 'text-success' : '' ?>">
                                    <?= $entry['Credit'] > 0 ? e(format_money($entry['Credit'])) : '—' ?>
                                </td>
                                <td class="text-end text-nowrap <?= $entry['Debit'] > 0 ? 'text-danger' : '' ?>">
                                    <?= $entry['Debit'] > 0 ? e(format_money($entry['Debit'])) : '—' ?>
                                </td>
                                <td class="text-end text-nowrap fw-semibold"><?= e(format_money($entry['Balance'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
