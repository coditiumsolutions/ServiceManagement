<?php
/**
 * Customer Payments — list with search, type, method and date filters.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/payment_ops.php';
require_login();

$activeMenu   = 'customer_payments';
$pageTitle    = 'Customer Payments';
$pageSubtitle = 'Record receipts against customers and service requests.';

$pdo          = db();
$search       = get_string('q', 100);
$typeFilter   = get_string('type', 30);
$methodFilter = get_string('method', 30);
$fromDate     = get_string('from', 20);
$toDate       = get_string('to', 20);

if ($typeFilter !== '' && !in_array($typeFilter, customer_payment_types(), true)) {
    $typeFilter = '';
}
if ($methodFilter !== '' && !in_array($methodFilter, customer_payment_methods(), true)) {
    $methodFilter = '';
}
if ($fromDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = '';
}
if ($toDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = '';
}

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/payments/index.php');
    }

    $action = post_string('action', 30);
    $id     = post_int('payment_id');
    $target = $id > 0 ? fetch_customer_payment($pdo, $id) : null;

    if ($target === null) {
        flash('danger', 'Payment not found.');
        redirect('modules/payments/index.php');
    }

    try {
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM CustomerPayments WHERE uid = ?')->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Payment deleted.');
            redirect('modules/payments/index.php');
        }
        flash('warning', 'Unknown action.');
        redirect('modules/payments/index.php');
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] payment action failed: ' . $exception->getMessage());
        flash('danger', 'Could not delete the payment.');
        redirect('modules/payments/index.php');
    }
}

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(cp.ReceiptNo LIKE ? OR cp.ReferenceNo LIKE ? OR cp.Remarks LIKE ?
                 OR c.FullName LIKE ? OR c.CustomerCode LIKE ? OR c.MobileNo LIKE ?
                 OR sr.RequestNo LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}
if ($typeFilter !== '') {
    $where[]  = 'cp.PaymentType = ?';
    $params[] = $typeFilter;
}
if ($methodFilter !== '') {
    $where[]  = 'cp.PaymentMethod = ?';
    $params[] = $methodFilter;
}
if ($fromDate !== '') {
    $where[]  = 'DATE(cp.PaymentDateTime) >= ?';
    $params[] = $fromDate;
}
if ($toDate !== '') {
    $where[]  = 'DATE(cp.PaymentDateTime) <= ?';
    $params[] = $toDate;
}

$sql = 'SELECT cp.uid, cp.ReceiptNo, cp.PaymentDateTime, cp.Amount, cp.PaymentType, cp.PaymentMethod,
               cp.ReferenceNo, c.FullName AS CustomerName, c.CustomerCode, c.MobileNo,
               sr.RequestNo
          FROM CustomerPayments cp
          INNER JOIN Customers c ON c.uid = cp.CustomerId
          LEFT JOIN ServiceRequests sr ON sr.uid = cp.ServiceRequestId
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY cp.PaymentDateTime DESC, cp.uid DESC
         LIMIT 300';

$rows = [];
$totalAmount = 0.0;
try {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    foreach ($rows as $row) {
        $amount = (float) $row['Amount'];
        $totalAmount += ((string) $row['PaymentType'] === 'Refund') ? -$amount : $amount;
    }
} catch (PDOException $exception) {
    error_log('[ServiceManagement] payments list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load payments right now.');
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-2">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Receipt, customer, request">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="type">Type</label>
            <select class="form-select" id="type" name="type">
                <option value="">All</option>
                <?php foreach (customer_payment_types() as $type): ?>
                    <option value="<?= e($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>>
                        <?= e($type) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="method">Method</label>
            <select class="form-select" id="method" name="method">
                <option value="">All</option>
                <?php foreach (customer_payment_methods() as $method): ?>
                    <option value="<?= e($method) ?>" <?= $methodFilter === $method ? 'selected' : '' ?>>
                        <?= e(status_label($method)) ?>
                    </option>
                <?php endforeach; ?>
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
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="<?= e(url('modules/payments/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    <div>
        <a href="<?= e(url('modules/payments/create.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Record payment
        </a>
    </div>
</div>

<?php if ($rows !== []): ?>
    <div class="mb-2 text-muted small">
        Showing <?= count($rows) ?> payment<?= count($rows) === 1 ? '' : 's' ?>
        · Net <?= e(format_money($totalAmount)) ?>
    </div>
<?php endif; ?>

<div class="app-card card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Receipt</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Method</th>
                    <th class="text-end">Amount</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No customer payments found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e((string) $row['ReceiptNo']) ?></div>
                                <?php if (!empty($row['RequestNo'])): ?>
                                    <div class="small text-muted"><?= e((string) $row['RequestNo']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?= e(format_datetime((string) $row['PaymentDateTime'])) ?></td>
                            <td>
                                <div class="fw-semibold"><?= e((string) $row['CustomerName']) ?></div>
                                <div class="small text-muted">
                                    <?= e((string) $row['CustomerCode']) ?>
                                    <?php if (!empty($row['MobileNo'])): ?>
                                        · <?= e((string) $row['MobileNo']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= e((string) $row['PaymentType']) ?></td>
                            <td><?= e(status_label((string) $row['PaymentMethod'])) ?></td>
                            <td class="text-end text-nowrap <?= (string) $row['PaymentType'] === 'Refund' ? 'text-danger' : '' ?>">
                                <?= (string) $row['PaymentType'] === 'Refund' ? '-' : '' ?><?= e(format_money($row['Amount'])) ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="theme-link-btn" href="<?= e(url('modules/payments/view.php?id=' . (int) $row['uid'])) ?>">View</a>
                                <a class="theme-link-btn ms-2" href="<?= e(url('modules/payments/edit.php?id=' . (int) $row['uid'])) ?>">Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete this payment?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="payment_id" value="<?= (int) $row['uid'] ?>">
                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0 ms-2">Delete</button>
                                </form>
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
