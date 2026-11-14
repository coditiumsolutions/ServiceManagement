<?php
/**
 * Edit a customer payment.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/payment_ops.php';
require_login();

$pdo = db();
$id  = get_int('id');
if ($id <= 0) {
    flash('danger', 'Invalid payment.');
    redirect('modules/payments/index.php');
}

$payment = fetch_customer_payment($pdo, $id);
if ($payment === null) {
    flash('danger', 'Payment not found.');
    redirect('modules/payments/index.php');
}

$activeMenu   = 'customer_payments';
$pageTitle    = 'Edit Payment';
$pageSubtitle = 'Update ' . (string) $payment['ReceiptNo'] . '.';

$customers = active_customers($pdo);
$requests  = payment_linkable_requests($pdo);

// Keep currently selected inactive/missing customer selectable.
if (!empty($payment['CustomerId'])) {
    $listed = false;
    foreach ($customers as $customer) {
        if ((int) $customer['uid'] === (int) $payment['CustomerId']) {
            $listed = true;
            break;
        }
    }
    if (!$listed) {
        array_unshift($customers, [
            'uid'          => (int) $payment['CustomerId'],
            'CustomerCode' => (string) ($payment['CustomerCode'] ?? ''),
            'FullName'     => (string) ($payment['CustomerName'] ?? 'Customer') . ' (inactive)',
            'MobileNo'     => (string) ($payment['CustomerMobile'] ?? ''),
        ]);
    }
}

// Keep currently linked request selectable.
if (!empty($payment['ServiceRequestId'])) {
    $listed = false;
    foreach ($requests as $request) {
        if ((int) $request['uid'] === (int) $payment['ServiceRequestId']) {
            $listed = true;
            break;
        }
    }
    if (!$listed && !empty($payment['RequestNo'])) {
        array_unshift($requests, [
            'uid'          => (int) $payment['ServiceRequestId'],
            'RequestNo'    => (string) $payment['RequestNo'],
            'CustomerId'   => (int) $payment['CustomerId'],
            'CustomerName' => (string) ($payment['CustomerName'] ?? ''),
            'ServiceName'  => (string) ($payment['ServiceName'] ?? ''),
            'FinalAmount'  => (float) ($payment['FinalAmount'] ?? 0),
        ]);
    }
}

$paymentDt = strtotime((string) $payment['PaymentDateTime']);
$form = [
    'CustomerId'       => (int) $payment['CustomerId'],
    'ServiceRequestId' => (int) ($payment['ServiceRequestId'] ?? 0),
    'PaymentDate'      => $paymentDt ? date('Y-m-d', $paymentDt) : date('Y-m-d'),
    'PaymentTime'      => $paymentDt ? date('H:i', $paymentDt) : date('H:i'),
    'Amount'           => number_format((float) $payment['Amount'], 2, '.', ''),
    'PaymentType'      => (string) $payment['PaymentType'],
    'PaymentMethod'    => (string) $payment['PaymentMethod'],
    'ReferenceNo'      => (string) ($payment['ReferenceNo'] ?? ''),
    'Remarks'          => (string) ($payment['Remarks'] ?? ''),
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/payments/edit.php?id=' . $id);
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
        if ($customer === null) {
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
            $paymentDateTime = $form['PaymentDate'] . ' ' . $form['PaymentTime'] . ':00';
            $update = $pdo->prepare(
                'UPDATE CustomerPayments
                    SET CustomerId = ?, ServiceRequestId = ?, PaymentDateTime = ?, Amount = ?,
                        PaymentType = ?, PaymentMethod = ?, ReferenceNo = ?, Remarks = ?
                  WHERE uid = ?'
            );
            $update->execute([
                $form['CustomerId'],
                $requestId,
                $paymentDateTime,
                (float) $form['Amount'],
                $form['PaymentType'],
                $form['PaymentMethod'],
                $form['ReferenceNo'] !== '' ? $form['ReferenceNo'] : null,
                $form['Remarks'] !== '' ? $form['Remarks'] : null,
                $id,
            ]);
            regenerate_csrf_token();
            flash('success', 'Payment updated.');
            redirect('modules/payments/view.php?id=' . $id);
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] payment update failed: ' . $exception->getMessage());
            $errors[] = 'Could not update the payment. Please try again.';
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
        <strong>Edit <?= e((string) $payment['ReceiptNo']) ?></strong>
        <a href="<?= e(url('modules/payments/view.php?id=' . $id)) ?>" class="btn btn-sm btn-outline-secondary">Cancel</a>
    </div>
    <div class="card-body form-grid-blue">
        <?= csrf_field() ?>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="CustomerId">Customer <span class="text-danger">*</span></label>
                <select class="form-select" id="CustomerId" name="CustomerId" required>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int) $customer['uid'] ?>" <?= $form['CustomerId'] === (int) $customer['uid'] ? 'selected' : '' ?>>
                            <?= e($customer['FullName'] . ' (' . $customer['CustomerCode'] . ' · ' . ($customer['MobileNo'] ?? '') . ')') ?>
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
                            <?= e($request['RequestNo'] . ' · ' . $request['CustomerName'] . ' · ' . ($request['ServiceName'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
                       value="<?= e($form['ReferenceNo']) ?>" maxlength="100">
            </div>
            <div class="form-field span-2">
                <label class="form-label" for="Remarks">Remarks</label>
                <input type="text" class="form-control" id="Remarks" name="Remarks"
                       value="<?= e($form['Remarks']) ?>" maxlength="500">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('modules/payments/view.php?id=' . $id)) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2-circle me-1"></i>Save changes
        </button>
    </div>
</form>

<script>
(function () {
    var customerSelect = document.getElementById('CustomerId');
    var requestSelect = document.getElementById('ServiceRequestId');
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

    customerSelect.addEventListener('change', filterRequests);
    filterRequests();
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
