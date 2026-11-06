<?php
/**
 * Add a service professional.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$activeMenu   = 'professionals';
$pageTitle    = 'Add Professional';
$pageSubtitle = 'Register a new labor / service professional.';

$pdo = db();
$categories = active_service_categories($pdo);

$form = [
    'FullName'          => '',
    'MobileNo'          => '',
    'AlternateMobileNo' => '',
    'Email'             => '',
    'CNIC'              => '',
    'Address'           => '',
    'City'              => '',
    'PrimaryCategoryId' => 0,
    'EmploymentType'    => 'SalaryPlusCommission',
    'JoiningDate'       => date('Y-m-d'),
    'BankName'          => '',
    'BankAccountNo'     => '',
    'IsActive'          => '1',
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/professionals/create.php');
    }

    $form['FullName']          = post_string('FullName', 150);
    $form['MobileNo']          = post_string('MobileNo', 20);
    $form['AlternateMobileNo'] = post_string('AlternateMobileNo', 20);
    $form['Email']             = post_string('Email', 150);
    $form['CNIC']              = post_string('CNIC', 20);
    $form['Address']           = post_string('Address', 500);
    $form['City']              = post_string('City', 100);
    $form['PrimaryCategoryId'] = post_int('PrimaryCategoryId');
    $form['EmploymentType']    = post_string('EmploymentType', 40);
    $form['JoiningDate']       = post_string('JoiningDate', 20);
    $form['BankName']          = post_string('BankName', 100);
    $form['BankAccountNo']     = post_string('BankAccountNo', 50);
    $form['IsActive']          = post_string('IsActive', 5) === '1' ? '1' : '0';

    if (trim($form['FullName']) === '') {
        $errors[] = 'Full name is required.';
    }
    if (trim($form['MobileNo']) === '') {
        $errors[] = 'Mobile number is required.';
    }
    if ($form['Email'] !== '' && filter_var($form['Email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Email address is not valid.';
    }
    if (!in_array($form['EmploymentType'], employment_types(), true)) {
        $errors[] = 'Please select a valid employment type.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['JoiningDate'])) {
        $errors[] = 'Joining date is invalid.';
    }
    if ($form['PrimaryCategoryId'] > 0) {
        $catOk = false;
        foreach ($categories as $category) {
            if ((int) $category['uid'] === $form['PrimaryCategoryId']) {
                $catOk = true;
                break;
            }
        }
        if (!$catOk) {
            $errors[] = 'Selected category is not available.';
        }
    }

    if ($errors === []) {
        try {
            $dup = $pdo->prepare('SELECT uid FROM ServiceProfessionals WHERE MobileNo = ? LIMIT 1');
            $dup->execute([$form['MobileNo']]);
            if ($dup->fetchColumn()) {
                $errors[] = 'A professional with this mobile number already exists.';
            }
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] professional mobile check failed: ' . $exception->getMessage());
            $errors[] = 'Could not validate mobile number.';
        }
    }

    if ($errors === []) {
        try {
            $code = generate_professional_code($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO ServiceProfessionals
                    (ProfessionalCode, FullName, MobileNo, AlternateMobileNo, Email, CNIC, Address, City,
                     PrimaryCategoryId, EmploymentType, JoiningDate, LeavingDate, BankName, BankAccountNo, IsActive)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)'
            );
            $insert->execute([
                $code,
                trim($form['FullName']),
                trim($form['MobileNo']),
                $form['AlternateMobileNo'] !== '' ? $form['AlternateMobileNo'] : null,
                $form['Email'] !== '' ? $form['Email'] : null,
                $form['CNIC'] !== '' ? $form['CNIC'] : null,
                $form['Address'] !== '' ? $form['Address'] : null,
                $form['City'] !== '' ? $form['City'] : null,
                $form['PrimaryCategoryId'] > 0 ? $form['PrimaryCategoryId'] : null,
                $form['EmploymentType'],
                $form['JoiningDate'],
                $form['BankName'] !== '' ? $form['BankName'] : null,
                $form['BankAccountNo'] !== '' ? $form['BankAccountNo'] : null,
                (int) $form['IsActive'],
            ]);
            $newId = (int) $pdo->lastInsertId();
            regenerate_csrf_token();
            flash('success', 'Professional ' . $code . ' created.');
            redirect('modules/professionals/view.php?id=' . $newId);
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] professional create failed: ' . $exception->getMessage());
            $errors[] = 'Could not create the professional. Please try again.';
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

<form method="post" class="app-card card" autocomplete="off">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>New professional</strong>
        <a href="<?= e(url('modules/professionals/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Back to list</a>
    </div>
    <div class="card-body form-grid-blue">
        <?= csrf_field() ?>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="FullName">Full name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="FullName" name="FullName"
                       value="<?= e($form['FullName']) ?>" maxlength="150" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="MobileNo">Mobile <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="MobileNo" name="MobileNo"
                       value="<?= e($form['MobileNo']) ?>" maxlength="20" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="AlternateMobileNo">Alternate mobile</label>
                <input type="text" class="form-control" id="AlternateMobileNo" name="AlternateMobileNo"
                       value="<?= e($form['AlternateMobileNo']) ?>" maxlength="20">
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="Email">Email</label>
                <input type="email" class="form-control" id="Email" name="Email"
                       value="<?= e($form['Email']) ?>" maxlength="150">
            </div>
            <div class="form-field">
                <label class="form-label" for="CNIC">CNIC</label>
                <input type="text" class="form-control" id="CNIC" name="CNIC"
                       value="<?= e($form['CNIC']) ?>" maxlength="20" placeholder="xxxxx-xxxxxxx-x">
            </div>
            <div class="form-field">
                <label class="form-label" for="City">City</label>
                <input type="text" class="form-control" id="City" name="City"
                       value="<?= e($form['City']) ?>" maxlength="100">
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-4">
                <label class="form-label" for="Address">Address</label>
                <input type="text" class="form-control" id="Address" name="Address"
                       value="<?= e($form['Address']) ?>" maxlength="500">
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field">
                <label class="form-label" for="PrimaryCategoryId">Primary category</label>
                <select class="form-select" id="PrimaryCategoryId" name="PrimaryCategoryId">
                    <option value="">Select category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['uid'] ?>" <?= $form['PrimaryCategoryId'] === (int) $category['uid'] ? 'selected' : '' ?>>
                            <?= e((string) $category['CategoryName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="EmploymentType">Employment type <span class="text-danger">*</span></label>
                <select class="form-select" id="EmploymentType" name="EmploymentType" required>
                    <?php foreach (employment_types() as $typeOption): ?>
                        <option value="<?= e($typeOption) ?>" <?= $form['EmploymentType'] === $typeOption ? 'selected' : '' ?>>
                            <?= e(status_label($typeOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="JoiningDate">Joining date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="JoiningDate" name="JoiningDate"
                       value="<?= e($form['JoiningDate']) ?>" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="IsActive">Status</label>
                <select class="form-select" id="IsActive" name="IsActive">
                    <option value="1" <?= $form['IsActive'] === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $form['IsActive'] === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="BankName">Bank name</label>
                <input type="text" class="form-control" id="BankName" name="BankName"
                       value="<?= e($form['BankName']) ?>" maxlength="100">
            </div>
            <div class="form-field span-2">
                <label class="form-label" for="BankAccountNo">Bank account no</label>
                <input type="text" class="form-control" id="BankAccountNo" name="BankAccountNo"
                       value="<?= e($form['BankAccountNo']) ?>" maxlength="50">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('modules/professionals/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Create professional</button>
    </div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
