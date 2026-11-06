<?php
/**
 * Add an expense.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/expense_ops.php';
require_login();

$activeMenu   = 'expenses';
$pageTitle    = 'Add Expense';
$pageSubtitle = 'Record a business expense by category and payment method.';

$pdo          = db();
$categories   = expense_category_options($pdo);
$professionals = active_professionals($pdo);
$requests     = expense_linkable_requests($pdo);

$form = [
    'ExpenseDate'      => date('Y-m-d'),
    'ExpenseCategory'  => '',
    'Description'      => '',
    'Amount'           => '0.00',
    'PaidTo'           => '',
    'PaymentMethod'    => 'Cash',
    'ReferenceNo'      => '',
    'ServiceRequestId' => get_int('request_id'),
    'ProfessionalId'   => get_int('professional_id'),
    'Remarks'          => '',
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/expenses/create.php');
    }

    $form['ExpenseDate']      = post_string('ExpenseDate', 20);
    $form['ExpenseCategory']  = post_string('ExpenseCategory', 100);
    $form['Description']      = post_string('Description', 500);
    $form['Amount']           = number_format(post_float('Amount'), 2, '.', '');
    $form['PaidTo']           = post_string('PaidTo', 150);
    $form['PaymentMethod']    = post_string('PaymentMethod', 30);
    $form['ReferenceNo']      = post_string('ReferenceNo', 100);
    $form['ServiceRequestId'] = post_int('ServiceRequestId');
    $form['ProfessionalId']   = post_int('ProfessionalId');
    $form['Remarks']          = post_string('Remarks', 500);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['ExpenseDate'])) {
        $errors[] = 'Expense date is invalid.';
    }
    if (trim($form['ExpenseCategory']) === '') {
        $errors[] = 'Category is required.';
    }
    if ((float) $form['Amount'] <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    }
    if (!in_array($form['PaymentMethod'], expense_payment_methods(), true)) {
        $errors[] = 'Payment method is invalid.';
    }

    $requestId = $form['ServiceRequestId'] > 0 ? $form['ServiceRequestId'] : null;
    if ($requestId !== null) {
        $requestOk = false;
        foreach ($requests as $request) {
            if ((int) $request['uid'] === $requestId) {
                $requestOk = true;
                break;
            }
        }
        if (!$requestOk) {
            $errors[] = 'Selected service request is not available.';
        }
    }

    $professionalId = $form['ProfessionalId'] > 0 ? $form['ProfessionalId'] : null;
    if ($professionalId !== null) {
        $proOk = false;
        foreach ($professionals as $professional) {
            if ((int) $professional['uid'] === $professionalId) {
                $proOk = true;
                break;
            }
        }
        if (!$proOk) {
            $errors[] = 'Selected professional is not available.';
        }
    }

    if ($errors === []) {
        try {
            $expenseNo = generate_expense_no($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO Expenses
                    (ExpenseNo, ExpenseDate, ExpenseCategory, Description, Amount, PaidTo,
                     PaymentMethod, ReferenceNo, ServiceRequestId, ProfessionalId, Remarks)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $expenseNo,
                $form['ExpenseDate'],
                trim($form['ExpenseCategory']),
                $form['Description'] !== '' ? $form['Description'] : null,
                (float) $form['Amount'],
                $form['PaidTo'] !== '' ? $form['PaidTo'] : null,
                $form['PaymentMethod'],
                $form['ReferenceNo'] !== '' ? $form['ReferenceNo'] : null,
                $requestId,
                $professionalId,
                $form['Remarks'] !== '' ? $form['Remarks'] : null,
            ]);
            $newId = (int) $pdo->lastInsertId();
            regenerate_csrf_token();
            flash('success', 'Expense ' . $expenseNo . ' recorded.');
            redirect('modules/expenses/view.php?id=' . $newId);
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] expense create failed: ' . $exception->getMessage());
            if (str_contains($exception->getMessage(), 'UQ_Expenses_ExpenseNo') || $exception->getCode() === '23000') {
                $errors[] = 'Could not allocate a unique expense number. Please try again.';
            } else {
                $errors[] = 'Could not save the expense. Please try again.';
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

<form method="post" class="app-card card" autocomplete="off">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>New expense</strong>
        <a href="<?= e(url('modules/expenses/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Back to list</a>
    </div>
    <div class="card-body form-grid-blue">
        <?= csrf_field() ?>
        <div class="form-row-blue">
            <div class="form-field">
                <label class="form-label" for="ExpenseDate">Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="ExpenseDate" name="ExpenseDate"
                       value="<?= e($form['ExpenseDate']) ?>" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="ExpenseCategory">Category <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="ExpenseCategory" name="ExpenseCategory" list="category_list"
                       value="<?= e($form['ExpenseCategory']) ?>" maxlength="100" required
                       placeholder="e.g. Materials">
                <datalist id="category_list">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-field">
                <label class="form-label" for="Amount">Amount <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0.01" class="form-control" id="Amount" name="Amount"
                       value="<?= e($form['Amount']) ?>" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="PaymentMethod">Payment method <span class="text-danger">*</span></label>
                <select class="form-select" id="PaymentMethod" name="PaymentMethod" required>
                    <?php foreach (expense_payment_methods() as $method): ?>
                        <option value="<?= e($method) ?>" <?= $form['PaymentMethod'] === $method ? 'selected' : '' ?>>
                            <?= e(status_label($method)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="Description">Description</label>
                <input type="text" class="form-control" id="Description" name="Description"
                       value="<?= e($form['Description']) ?>" maxlength="500"
                       placeholder="What was purchased or paid for">
            </div>
            <div class="form-field">
                <label class="form-label" for="PaidTo">Paid to</label>
                <input type="text" class="form-control" id="PaidTo" name="PaidTo"
                       value="<?= e($form['PaidTo']) ?>" maxlength="150" placeholder="Vendor / payee">
            </div>
            <div class="form-field">
                <label class="form-label" for="ReferenceNo">Reference no</label>
                <input type="text" class="form-control" id="ReferenceNo" name="ReferenceNo"
                       value="<?= e($form['ReferenceNo']) ?>" maxlength="100" placeholder="Cheque / receipt / txn id">
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="ServiceRequestId">Linked request (optional)</label>
                <select class="form-select" id="ServiceRequestId" name="ServiceRequestId">
                    <option value="">None</option>
                    <?php foreach ($requests as $request): ?>
                        <option value="<?= (int) $request['uid'] ?>" <?= $form['ServiceRequestId'] === (int) $request['uid'] ? 'selected' : '' ?>>
                            <?= e($request['RequestNo'] . ' · ' . $request['CustomerName'] . ' · ' . $request['ServiceName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field span-2">
                <label class="form-label" for="ProfessionalId">Linked professional (optional)</label>
                <select class="form-select" id="ProfessionalId" name="ProfessionalId">
                    <option value="">None</option>
                    <?php foreach ($professionals as $professional): ?>
                        <option value="<?= (int) $professional['uid'] ?>" <?= $form['ProfessionalId'] === (int) $professional['uid'] ? 'selected' : '' ?>>
                            <?= e($professional['FullName'] . ' (' . $professional['ProfessionalCode'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-4">
                <label class="form-label" for="Remarks">Remarks</label>
                <input type="text" class="form-control" id="Remarks" name="Remarks"
                       value="<?= e($form['Remarks']) ?>" maxlength="500">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('modules/expenses/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2-circle me-1"></i>Save expense
        </button>
    </div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
