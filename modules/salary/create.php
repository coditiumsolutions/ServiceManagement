<?php
/**
 * Add a salary revision for a professional.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/payroll_ops.php';
require_login();

$activeMenu   = 'salary_history';
$pageTitle    = 'Add Salary Revision';
$pageSubtitle = 'Set basic salary and allowances with an effective date.';

$pdo = db();
$professionals = active_professionals($pdo);

$form = [
    'ProfessionalId' => get_int('professional_id'),
    'BasicSalary'    => '0.00',
    'Allowances'     => '0.00',
    'EffectiveFrom'  => date('Y-m-d'),
    'RevisionReason' => '',
];
$errors = [];

if ($form['ProfessionalId'] > 0) {
    $current = current_salary_history($pdo, $form['ProfessionalId']);
    if ($current !== null) {
        $form['BasicSalary'] = number_format((float) $current['BasicSalary'], 2, '.', '');
        $form['Allowances']  = number_format((float) $current['Allowances'], 2, '.', '');
    }
}

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/salary/create.php');
    }

    $form['ProfessionalId'] = post_int('ProfessionalId');
    $form['BasicSalary']    = number_format(post_float('BasicSalary'), 2, '.', '');
    $form['Allowances']     = number_format(post_float('Allowances'), 2, '.', '');
    $form['EffectiveFrom']  = post_string('EffectiveFrom', 20);
    $form['RevisionReason'] = post_string('RevisionReason', 500);

    if ($form['ProfessionalId'] <= 0) {
        $errors[] = 'Please select a professional.';
    }
    if ((float) $form['BasicSalary'] < 0 || (float) $form['Allowances'] < 0) {
        $errors[] = 'Amounts cannot be negative.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['EffectiveFrom'])) {
        $errors[] = 'Effective from date is invalid.';
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
            close_previous_salary_revisions($pdo, $form['ProfessionalId'], $form['EffectiveFrom']);
            $insert = $pdo->prepare(
                'INSERT INTO ProfessionalSalaryHistory
                    (ProfessionalId, BasicSalary, Allowances, EffectiveFrom, EffectiveTo, RevisionReason)
                 VALUES (?, ?, ?, ?, NULL, ?)'
            );
            $insert->execute([
                $form['ProfessionalId'],
                (float) $form['BasicSalary'],
                (float) $form['Allowances'],
                $form['EffectiveFrom'],
                $form['RevisionReason'] !== '' ? $form['RevisionReason'] : null,
            ]);
            $pdo->commit();
            regenerate_csrf_token();
            flash('success', 'Salary revision saved.');
            redirect('modules/salary/index.php?professional_id=' . $form['ProfessionalId'] . '&current=1');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ServiceManagement] salary create failed: ' . $exception->getMessage());
            if (str_contains($exception->getMessage(), 'UQ_ProfessionalSalaryHistory')) {
                $errors[] = 'A revision already exists for this professional on that effective date.';
            } else {
                $errors[] = 'Could not save the salary revision. Please try again.';
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

<form method="post" class="app-card card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Salary revision</strong>
        <a href="<?= e(url('modules/salary/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Back to list</a>
    </div>
    <div class="card-body form-grid-blue">
        <?= csrf_field() ?>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="ProfessionalId">Professional <span class="text-danger">*</span></label>
                <select class="form-select" id="ProfessionalId" name="ProfessionalId" required>
                    <option value="">Select professional</option>
                    <?php foreach ($professionals as $professional): ?>
                        <option value="<?= (int) $professional['uid'] ?>" <?= $form['ProfessionalId'] === (int) $professional['uid'] ? 'selected' : '' ?>>
                            <?= e($professional['FullName'] . ' (' . $professional['ProfessionalCode'] . ' · ' . $professional['EmploymentType'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="EffectiveFrom">Effective from <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="EffectiveFrom" name="EffectiveFrom"
                       value="<?= e($form['EffectiveFrom']) ?>" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="RevisionReason">Revision reason</label>
                <input type="text" class="form-control" id="RevisionReason" name="RevisionReason"
                       value="<?= e($form['RevisionReason']) ?>" maxlength="500" placeholder="e.g. Annual increment">
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field">
                <label class="form-label" for="BasicSalary">Basic salary <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0" class="form-control" id="BasicSalary" name="BasicSalary"
                       value="<?= e($form['BasicSalary']) ?>" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="Allowances">Allowances</label>
                <input type="number" step="0.01" min="0" class="form-control" id="Allowances" name="Allowances"
                       value="<?= e($form['Allowances']) ?>">
            </div>
            <div class="form-field span-2">
                <label class="form-label">Note</label>
                <div class="form-text mb-0">Saving a new revision closes any previous open revision the day before the new effective date.</div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('modules/salary/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save revision</button>
    </div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
