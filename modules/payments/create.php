<?php
/**
 * Record a customer payment.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/payment_ops.php';
require_login();

$activeMenu   = 'customer_payments';
$pageTitle    = 'Record Payment';
$pageSubtitle = 'Create a customer receipt against a request or account.';

$pdo       = db();
$customers = active_customers($pdo);
$requests  = payment_linkable_requests($pdo);

$prefillCustomerId = get_int('customer_id');
$prefillRequestId  = get_int('request_id');
$prefillAmount     = '';

if ($prefillRequestId > 0) {
    $linkedRequest = fetch_request_for_payment($pdo, $prefillRequestId);
    if ($linkedRequest !== null) {
        $prefillCustomerId = (int) $linkedRequest['CustomerId'];
        $summary = request_payment_summary($pdo, $prefillRequestId, (float) $linkedRequest['FinalAmount']);
        if ($summary['balance'] > 0) {
            $prefillAmount = number_format($summary['balance'], 2, '.', '');
        }
    }
}

$form = [
    'CustomerId'       => $prefillCustomerId,
    'ServiceRequestId' => $prefillRequestId,
    'PaymentDate'      => date('Y-m-d'),
    'PaymentTime'      => date('H:i'),
    'Amount'           => $prefillAmount !== '' ? $prefillAmount : '0.00',
    'PaymentType'      => 'Full',
    'PaymentMethod'    => 'Cash',
    'ReferenceNo'      => '',
    'Remarks'          => '',
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/payments/create.php');
    }

    $form['CustomerId']       = post_int('CustomerId');
    $form['ServiceRequestId'] = post_int('ServiceRequestId');
    $form['PaymentDate']      = post_string('PaymentDate', 20);
    $form['PaymentTime']      = post_string('PaymentTime', 10);
    $form['Amount']           = number_format(post_float('Amount'), 2, '.', '');
    $form['PaymentType']      = post_string('PaymentType', 30);
    $form['PaymentMethod']    = post_string('PaymentMethod', 30);
    $form['ReferenceNo']      = post_string('ReferenceNo', 100);
    $form['Remarks']          = post_string('Remarks', 500);

    if ($form['CustomerId'] <= 0) {
        $errors[] = 'Customer is required.';
    } else {
        $customer = fetch_customer_basic($pdo, $form['CustomerId']);
        if ($customer === null || (int) $customer['IsActive'] !== 1) {
            $errors[] = 'Selected customer is not available.';
        }
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['PaymentDate'])) {
        $errors[] = 'Payment date is invalid.';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $form['PaymentTime'])) {
        $errors[] = 'Payment time is invalid.';
    }
    if ((float) $form['Amount'] <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    }
    if (!in_array($form['PaymentType'], customer_payment_types(), true)) {
        $errors[] = 'Payment type is invalid.';
    }
    if (!in_array($form['PaymentMethod'], customer_payment_methods(), true)) {
        $errors[] = 'Payment method is invalid.';
    }

    $requestId = $form['ServiceRequestId'] > 0 ? $form['ServiceRequestId'] : null;
    if ($requestId !== null) {
        $request = fetch_request_for_payment($pdo, $requestId);
        if ($request === null) {
            $errors[] = 'Selected service request is not available.';
        } elseif ((int) $request['CustomerId'] !== $form['CustomerId']) {
            $errors[] = 'The selected request does not belong to this customer.';
        }
    }

    if ($errors === []) {
        try {
            $receiptNo = generate_receipt_no($pdo);
            $paymentDateTime = $form['PaymentDate'] . ' ' . $form['PaymentTime'] . ':00';
            $insert = $pdo->prepare(
                'INSERT INTO CustomerPayments
                    (ReceiptNo, CustomerId, ServiceRequestId, PaymentDateTime, Amount,
                     PaymentType, PaymentMethod, ReferenceNo, Remarks)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $receiptNo,
                $form['CustomerId'],
                $requestId,
                $paymentDateTime,
                (float) $form['Amount'],
                $form['PaymentType'],
                $form['PaymentMethod'],
                $form['ReferenceNo'] !== '' ? $form['ReferenceNo'] : null,
                $form['Remarks'] !== '' ? $form['Remarks'] : null,
            ]);
            $newId = (int) $pdo->lastInsertId();
            regenerate_csrf_token();
            flash('success', 'Payment ' . $receiptNo . ' recorded.');
            redirect('modules/payments/view.php?id=' . $newId);
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] payment create failed: ' . $exception->getMessage());
            if (str_contains($exception->getMessage(), 'UQ_CustomerPayments_ReceiptNo') || $exception->getCode() === '23000') {
                $errors[] = 'Could not allocate a unique receipt number. Please try again.';
            } else {
                $errors[] = 'Could not save the payment. Please try again.';
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

<form method="post" class="app-card card" autocomplete="off" id="paymentForm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>New customer payment</strong>
        <a href="<?= e(url('modules/payments/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Back to list</a>
    </div>
    <div class="card-body form-grid-blue">
        <?= csrf_field() ?>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="CustomerId">Customer <span class="text-danger">*</span></label>
                <select class="form-select" id="CustomerId" name="CustomerId" required>
                    <option value="">Select customer</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int) $customer['uid'] ?>" <?= $form['CustomerId'] === (int) $customer['uid'] ? 'selected' : '' ?>>
                            <?= e($customer['FullName'] . ' (' . $customer['CustomerCode'] . ' · ' . $customer['MobileNo'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field span-2">
                <label class="form-label" for="ServiceRequestId">Service request (optional)</label>
                <select class="form-select" id="ServiceRequestId" name="ServiceRequestId">
                    <option value="">None</option>
                    <?php foreach ($requests as $request): ?>
                        <option value="<?= (int) $request['uid'] ?>"
                                data-customer="<?= (int) $request['CustomerId'] ?>"
                                <?= $form['ServiceRequestId'] === (int) $request['uid'] ? 'selected' : '' ?>>
                            <?= e($request['RequestNo'] . ' · ' . $request['CustomerName'] . ' · ' . $request['ServiceName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text" id="requestBalanceHint"></div>
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field">
                <label class="form-label" for="PaymentDate">Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="PaymentDate" name="PaymentDate"
                       value="<?= e($form['PaymentDate']) ?>" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="PaymentTime">Time <span class="text-danger">*</span></label>
                <input type="time" class="form-control" id="PaymentTime" name="PaymentTime"
                       value="<?= e($form['PaymentTime']) ?>" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="Amount">Amount <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0.01" class="form-control" id="Amount" name="Amount"
                       value="<?= e($form['Amount']) ?>" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="PaymentType">Payment type <span class="text-danger">*</span></label>
                <select class="form-select" id="PaymentType" name="PaymentType" required>
                    <?php foreach (customer_payment_types() as $type): ?>
                        <option value="<?= e($type) ?>" <?= $form['PaymentType'] === $type ? 'selected' : '' ?>>
                            <?= e($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field">
                <label class="form-label" for="PaymentMethod">Payment method <span class="text-danger">*</span></label>
                <select class="form-select" id="PaymentMethod" name="PaymentMethod" required>
                    <?php foreach (customer_payment_methods() as $method): ?>
                        <option value="<?= e($method) ?>" <?= $form['PaymentMethod'] === $method ? 'selected' : '' ?>>
                            <?= e(status_label($method)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="ReferenceNo">Reference no</label>
                <input type="text" class="form-control" id="ReferenceNo" name="ReferenceNo"
                       value="<?= e($form['ReferenceNo']) ?>" maxlength="100"
                       placeholder="Cheque / txn / bank ref">
            </div>
            <div class="form-field span-2">
                <label class="form-label" for="Remarks">Remarks</label>
                <input type="text" class="form-control" id="Remarks" name="Remarks"
                       value="<?= e($form['Remarks']) ?>" maxlength="500">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('modules/payments/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2-circle me-1"></i>Save payment
        </button>
    </div>
</form>

<script>
(function () {
    var customerSelect = document.getElementById('CustomerId');
    var requestSelect = document.getElementById('ServiceRequestId');
    var hint = document.getElementById('requestBalanceHint');
    var allOptions = Array.prototype.slice.call(requestSelect.querySelectorAll('option[data-customer]'));

    function filterRequests() {
        var customerId = customerSelect.value;
        allOptions.forEach(function (option) {
            var match = !customerId || option.getAttribute('data-customer') === customerId;
            option.hidden = !match;
            if (!match && option.selected) {
                requestSelect.value = '';
            }
        });
    }

    function syncCustomerFromRequest() {
        var selected = requestSelect.options[requestSelect.selectedIndex];
        if (!selected || !selected.value) {
            hint.textContent = '';
            return;
        }
        var customerId = selected.getAttribute('data-customer');
        if (customerId && customerSelect.value !== customerId) {
            customerSelect.value = customerId;
            filterRequests();
            requestSelect.value = selected.value;
        }
        hint.textContent = 'Linked request will validate against the selected customer.';
    }

    customerSelect.addEventListener('change', filterRequests);
    requestSelect.addEventListener('change', syncCustomerFromRequest);
    filterRequests();
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
