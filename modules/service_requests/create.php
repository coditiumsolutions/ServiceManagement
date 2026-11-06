<?php
/**
 * Create a new customer service request.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$activeMenu   = 'service_requests';
$pageTitle    = 'New Service Request';
$pageSubtitle = 'Capture a customer service request.';

$pdo = db();
$customers = active_customers($pdo);
$serviceTypes = active_service_types($pdo);

$form = [
    'CustomerId'         => 0,
    'ServiceTypeId'      => 0,
    'PreferredDate'      => '',
    'PreferredTimeSlot'  => '',
    'ServiceAddress'     => '',
    'ProblemDescription' => '',
    'Priority'           => 'Normal',
    'EstimatedAmount'    => '0.00',
    'DiscountAmount'     => '0.00',
    'Remarks'            => '',
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/service_requests/create.php');
    }

    $form['CustomerId']         = post_int('CustomerId');
    $form['ServiceTypeId']      = post_int('ServiceTypeId');
    $form['PreferredDate']      = post_string('PreferredDate', 20);
    $form['PreferredTimeSlot']  = post_string('PreferredTimeSlot', 50);
    $form['ServiceAddress']     = post_string('ServiceAddress', 500);
    $form['ProblemDescription'] = post_string('ProblemDescription', 2000);
    $form['Priority']           = post_string('Priority', 20);
    $form['EstimatedAmount']    = number_format(post_float('EstimatedAmount'), 2, '.', '');
    $form['DiscountAmount']     = number_format(post_float('DiscountAmount'), 2, '.', '');
    $form['Remarks']            = post_string('Remarks', 1000);

    // Refresh customers in case one was just added via modal.
    $customers = active_customers($pdo);

    if ($form['CustomerId'] <= 0) {
        $errors[] = 'Please select a customer.';
    }
    if ($form['ServiceTypeId'] <= 0) {
        $errors[] = 'Please select a service type.';
    }
    if ($form['ServiceAddress'] === '') {
        $errors[] = 'Service address is required.';
    }
    if (!in_array($form['Priority'], request_priorities(), true)) {
        $errors[] = 'Invalid priority selected.';
    }

    $estimated = (float) $form['EstimatedAmount'];
    $discount  = (float) $form['DiscountAmount'];
    if ($estimated < 0 || $discount < 0) {
        $errors[] = 'Amounts cannot be negative.';
    }
    if ($discount > $estimated) {
        $errors[] = 'Discount cannot be greater than estimated amount.';
    }

    $preferredDate = $form['PreferredDate'] !== '' ? $form['PreferredDate'] : null;
    if ($preferredDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $preferredDate)) {
        $errors[] = 'Preferred date format is invalid.';
        $preferredDate = null;
    }

    $selectedType = null;
    foreach ($serviceTypes as $type) {
        if ((int) $type['uid'] === $form['ServiceTypeId']) {
            $selectedType = $type;
            break;
        }
    }
    if ($form['ServiceTypeId'] > 0 && $selectedType === null) {
        $errors[] = 'Selected service type is not available.';
    }

    $customerOk = false;
    foreach ($customers as $customer) {
        if ((int) $customer['uid'] === $form['CustomerId']) {
            $customerOk = true;
            if ($form['ServiceAddress'] === '' && !empty($customer['Address'])) {
                $form['ServiceAddress'] = (string) $customer['Address'];
            }
            break;
        }
    }
    if ($form['CustomerId'] > 0 && !$customerOk) {
        $errors[] = 'Selected customer is not available.';
    }

    if ($errors === []) {
        $finalAmount = max(0, $estimated - $discount);
        try {
            $pdo->beginTransaction();
            $requestNo = generate_request_no($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO ServiceRequests
                    (RequestNo, CustomerId, ServiceTypeId, PreferredDate, PreferredTimeSlot,
                     ServiceAddress, ProblemDescription, Priority, EstimatedAmount, DiscountAmount,
                     FinalAmount, RequestStatus, Remarks)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Pending\', ?)'
            );
            $insert->execute([
                $requestNo,
                $form['CustomerId'],
                $form['ServiceTypeId'],
                $preferredDate,
                $form['PreferredTimeSlot'] !== '' ? $form['PreferredTimeSlot'] : null,
                $form['ServiceAddress'],
                $form['ProblemDescription'] !== '' ? $form['ProblemDescription'] : null,
                $form['Priority'],
                $estimated,
                $discount,
                $finalAmount,
                $form['Remarks'] !== '' ? $form['Remarks'] : null,
            ]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->commit();
            regenerate_csrf_token();
            flash('success', 'Service request ' . $requestNo . ' created successfully.');
            redirect('modules/service_requests/view.php?id=' . $newId);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ServiceManagement] create request failed: ' . $exception->getMessage());
            $errors[] = 'Could not save the service request. Please try again.';
        }
    }
} elseif (get_int('customer_id') > 0) {
    $form['CustomerId'] = get_int('customer_id');
    foreach ($customers as $customer) {
        if ((int) $customer['uid'] === $form['CustomerId'] && !empty($customer['Address'])) {
            $form['ServiceAddress'] = (string) $customer['Address'];
            break;
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

<?php if ($serviceTypes === []): ?>
    <div class="alert alert-warning">
        Add at least one active service type before creating requests.
    </div>
<?php endif; ?>

<form method="post" class="app-card card" id="serviceRequestForm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Request details</strong>
        <a href="<?= e(url('modules/service_requests/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Back to list</a>
    </div>
    <div class="card-body form-grid-blue">
        <?= csrf_field() ?>

        <div class="form-row-blue">
            <div class="form-field">
                <label class="form-label" for="CustomerId">Customer <span class="text-danger">*</span></label>
                <div class="customer-add-wrap">
                    <select class="form-select" id="CustomerId" name="CustomerId" required>
                        <option value="">Select customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['uid'] ?>"
                                data-address="<?= e((string) ($customer['Address'] ?? '')) ?>"
                                <?= $form['CustomerId'] === (int) $customer['uid'] ? 'selected' : '' ?>>
                                <?= e($customer['FullName'] . ' (' . $customer['CustomerCode'] . ' · ' . $customer['MobileNo'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#addCustomerModal" title="Add customer">
                        <i class="bi bi-person-plus"></i>
                    </button>
                </div>
            </div>
            <div class="form-field">
                <label class="form-label" for="ServiceTypeId">Service type <span class="text-danger">*</span></label>
                <select class="form-select" id="ServiceTypeId" name="ServiceTypeId" required>
                    <option value="">Select service</option>
                    <?php foreach ($serviceTypes as $type): ?>
                        <option value="<?= (int) $type['uid'] ?>"
                            data-price="<?= e(number_format((float) $type['BasePrice'], 2, '.', '')) ?>"
                            <?= $form['ServiceTypeId'] === (int) $type['uid'] ? 'selected' : '' ?>>
                            <?= e($type['CategoryName'] . ' — ' . $type['ServiceName'] . ' (' . format_money($type['BasePrice']) . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="PreferredDate">Preferred date</label>
                <input type="date" class="form-control" id="PreferredDate" name="PreferredDate" value="<?= e($form['PreferredDate']) ?>">
            </div>
            <div class="form-field">
                <label class="form-label" for="PreferredTimeSlot">Preferred time slot</label>
                <input type="text" class="form-control" id="PreferredTimeSlot" name="PreferredTimeSlot"
                       value="<?= e($form['PreferredTimeSlot']) ?>" placeholder="e.g. 10:00 AM - 12:00 PM" maxlength="50">
            </div>
        </div>

        <div class="form-row-blue">
            <div class="form-field">
                <label class="form-label" for="Priority">Priority</label>
                <select class="form-select" id="Priority" name="Priority">
                    <?php foreach (request_priorities() as $priority): ?>
                        <option value="<?= e($priority) ?>" <?= $form['Priority'] === $priority ? 'selected' : '' ?>><?= e($priority) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="EstimatedAmount">Estimated amount</label>
                <input type="number" step="0.01" min="0" class="form-control" id="EstimatedAmount" name="EstimatedAmount"
                       value="<?= e($form['EstimatedAmount']) ?>">
            </div>
            <div class="form-field">
                <label class="form-label" for="DiscountAmount">Discount amount</label>
                <input type="number" step="0.01" min="0" class="form-control" id="DiscountAmount" name="DiscountAmount"
                       value="<?= e($form['DiscountAmount']) ?>">
            </div>
            <div class="form-field">
                <label class="form-label" for="Remarks">Remarks</label>
                <input type="text" class="form-control" id="Remarks" name="Remarks" value="<?= e($form['Remarks']) ?>" maxlength="1000">
            </div>
        </div>

        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="ServiceAddress">Service address <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="ServiceAddress" name="ServiceAddress"
                       value="<?= e($form['ServiceAddress']) ?>" required maxlength="500">
            </div>
            <div class="form-field span-2">
                <label class="form-label" for="ProblemDescription">Problem description</label>
                <textarea class="form-control" id="ProblemDescription" name="ProblemDescription" rows="1" maxlength="2000"><?= e($form['ProblemDescription']) ?></textarea>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('modules/service_requests/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary" <?= $serviceTypes === [] ? 'disabled' : '' ?>>
            <i class="bi bi-check2-circle me-1"></i>Save request
        </button>
    </div>
</form>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addCustomerModalLabel">Add new customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="quickCustomerForm" class="form-grid-blue">
                <div class="modal-body">
                    <div id="quickCustomerAlert" class="alert d-none" role="alert"></div>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="form-row-blue">
                        <div class="form-field">
                            <label class="form-label" for="qcFullName">Full name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="qcFullName" name="FullName" required maxlength="150">
                        </div>
                        <div class="form-field">
                            <label class="form-label" for="qcMobileNo">Mobile no <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="qcMobileNo" name="MobileNo" required maxlength="20">
                        </div>
                        <div class="form-field">
                            <label class="form-label" for="qcEmail">Email</label>
                            <input type="email" class="form-control" id="qcEmail" name="Email" maxlength="150">
                        </div>
                        <div class="form-field">
                            <label class="form-label" for="qcArea">Area</label>
                            <input type="text" class="form-control" id="qcArea" name="Area" maxlength="100">
                        </div>
                    </div>
                    <div class="form-row-blue">
                        <div class="form-field span-2">
                            <label class="form-label" for="qcAddress">Address <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="qcAddress" name="Address" required maxlength="500">
                        </div>
                        <div class="form-field span-2">
                            <label class="form-label" for="qcCity">City</label>
                            <input type="text" class="form-control" id="qcCity" name="City" maxlength="100">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="quickCustomerSaveBtn">
                        <i class="bi bi-check2-circle me-1"></i>Save customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var customerSelect = document.getElementById('CustomerId');
    var addressInput = document.getElementById('ServiceAddress');
    var typeSelect = document.getElementById('ServiceTypeId');
    var estimatedInput = document.getElementById('EstimatedAmount');
    var quickForm = document.getElementById('quickCustomerForm');
    var quickAlert = document.getElementById('quickCustomerAlert');
    var quickSaveBtn = document.getElementById('quickCustomerSaveBtn');
    var quickCreateUrl = <?= json_encode(url('modules/customers/quick_create.php'), JSON_UNESCAPED_SLASHES) ?>;

    function fillAddressFromCustomer() {
        if (!customerSelect || !addressInput) { return; }
        var option = customerSelect.options[customerSelect.selectedIndex];
        var address = option ? option.getAttribute('data-address') : '';
        if (address) {
            addressInput.value = address;
        }
    }

    if (customerSelect) {
        customerSelect.addEventListener('change', fillAddressFromCustomer);
    }

    if (typeSelect && estimatedInput) {
        typeSelect.addEventListener('change', function () {
            var option = typeSelect.options[typeSelect.selectedIndex];
            var price = option ? option.getAttribute('data-price') : '';
            if (price) {
                estimatedInput.value = price;
            }
        });
    }

    function showQuickAlert(type, message) {
        if (!quickAlert) { return; }
        quickAlert.className = 'alert alert-' + type;
        quickAlert.textContent = message;
        quickAlert.classList.remove('d-none');
    }

    function selectOrAddCustomer(customer) {
        if (!customerSelect) { return; }
        var existing = customerSelect.querySelector('option[value="' + customer.uid + '"]');
        if (!existing) {
            existing = document.createElement('option');
            existing.value = String(customer.uid);
            customerSelect.appendChild(existing);
        }
        existing.textContent = customer.FullName + ' (' + customer.CustomerCode + ' · ' + customer.MobileNo + ')';
        existing.setAttribute('data-address', customer.Address || '');
        existing.selected = true;
        fillAddressFromCustomer();
    }

    if (quickForm) {
        quickForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!quickSaveBtn) { return; }

            quickSaveBtn.disabled = true;
            quickSaveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

            fetch(quickCreateUrl, {
                method: 'POST',
                body: new FormData(quickForm),
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { status: response.status, data: data };
                });
            })
            .then(function (result) {
                if (!result.data || !result.data.ok) {
                    showQuickAlert('danger', (result.data && result.data.error) ? result.data.error : 'Could not create customer.');
                    return;
                }

                selectOrAddCustomer(result.data.customer);
                showQuickAlert('success', result.data.message || 'Customer saved.');
                quickForm.reset();

                window.setTimeout(function () {
                    var modalEl = document.getElementById('addCustomerModal');
                    if (modalEl && window.bootstrap) {
                        window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    }
                    if (quickAlert) {
                        quickAlert.classList.add('d-none');
                    }
                }, 700);
            })
            .catch(function () {
                showQuickAlert('danger', 'Network error while creating customer.');
            })
            .finally(function () {
                quickSaveBtn.disabled = false;
                quickSaveBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Save customer';
            });
        });
    }
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
