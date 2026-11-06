<?php
/**
 * Expenses — list with search, date and category filters.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_once __DIR__ . '/../../includes/expense_ops.php';
require_login();

$activeMenu   = 'expenses';
$pageTitle    = 'Expenses';
$pageSubtitle = 'Record and manage business expenses.';

$pdo          = db();
$search       = get_string('q', 100);
$category     = get_string('category', 100);
$methodFilter = get_string('method', 30);
$fromDate     = get_string('from', 20);
$toDate       = get_string('to', 20);

if ($methodFilter !== '' && !in_array($methodFilter, expense_payment_methods(), true)) {
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
        redirect('modules/expenses/index.php');
    }

    $action = post_string('action', 30);
    $id     = post_int('expense_id');
    $target = $id > 0 ? fetch_expense($pdo, $id) : null;

    if ($target === null) {
        flash('danger', 'Expense not found.');
        redirect('modules/expenses/index.php');
    }

    try {
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM Expenses WHERE uid = ?')->execute([$id]);
            regenerate_csrf_token();
            flash('success', 'Expense deleted.');
            redirect('modules/expenses/index.php');
        }
        flash('warning', 'Unknown action.');
        redirect('modules/expenses/index.php');
    } catch (PDOException $exception) {
        error_log('[ServiceManagement] expense action failed: ' . $exception->getMessage());
        flash('danger', 'Could not delete the expense.');
        redirect('modules/expenses/index.php');
    }
}

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(e.ExpenseNo LIKE ? OR e.ExpenseCategory LIKE ? OR e.Description LIKE ?
                 OR e.PaidTo LIKE ? OR e.ReferenceNo LIKE ? OR sr.RequestNo LIKE ?
                 OR sp.FullName LIKE ? OR sp.ProfessionalCode LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
}
if ($category !== '') {
    $where[] = 'e.ExpenseCategory = ?';
    $params[] = $category;
}
if ($methodFilter !== '') {
    $where[] = 'e.PaymentMethod = ?';
    $params[] = $methodFilter;
}
if ($fromDate !== '') {
    $where[] = 'e.ExpenseDate >= ?';
    $params[] = $fromDate;
}
if ($toDate !== '') {
    $where[] = 'e.ExpenseDate <= ?';
    $params[] = $toDate;
}

$sql = 'SELECT e.uid, e.ExpenseNo, e.ExpenseDate, e.ExpenseCategory, e.Description,
               e.Amount, e.PaidTo, e.PaymentMethod, e.ReferenceNo,
               sr.RequestNo, sp.FullName AS ProfessionalName
          FROM Expenses e
          LEFT JOIN ServiceRequests sr ON sr.uid = e.ServiceRequestId
          LEFT JOIN ServiceProfessionals sp ON sp.uid = e.ProfessionalId
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY e.ExpenseDate DESC, e.uid DESC
         LIMIT 300';

$rows = [];
$totalAmount = 0.0;
try {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    foreach ($rows as $row) {
        $totalAmount += (float) $row['Amount'];
    }
} catch (PDOException $exception) {
    error_log('[ServiceManagement] expenses list failed: ' . $exception->getMessage());
    flash('danger', 'Unable to load expenses right now.');
}

$categories = expense_category_options($pdo);

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-2">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="No, category, paid to">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="category">Category</label>
            <select class="form-select" id="category" name="category">
                <option value="">All</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="method">Method</label>
            <select class="form-select" id="method" name="method">
                <option value="">All</option>
                <?php foreach (expense_payment_methods() as $method): ?>
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
            <a href="<?= e(url('modules/expenses/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    <div>
        <a href="<?= e(url('modules/expenses/create.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add expense
        </a>
    </div>
</div>

<?php if ($rows !== []): ?>
    <div class="mb-2 text-muted small">
        Showing <?= count($rows) ?> expense<?= count($rows) === 1 ? '' : 's' ?>
        · Total <?= e(format_money($totalAmount)) ?>
    </div>
<?php endif; ?>

<div class="app-card card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Expense no</th>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Paid to</th>
                    <th>Method</th>
                    <th class="text-end">Amount</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No expenses found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e((string) $row['ExpenseNo']) ?></div>
                                <?php if (!empty($row['RequestNo'])): ?>
                                    <div class="small text-muted"><?= e((string) $row['RequestNo']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?= e(format_datetime((string) $row['ExpenseDate'], 'd M Y')) ?></td>
                            <td>
                                <div><?= e((string) $row['ExpenseCategory']) ?></div>
                                <?php if (!empty($row['Description'])): ?>
                                    <div class="small text-muted"><?= e((string) $row['Description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e((string) ($row['PaidTo'] ?: '—')) ?>
                                <?php if (!empty($row['ProfessionalName'])): ?>
                                    <div class="small text-muted"><?= e((string) $row['ProfessionalName']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e(status_label((string) $row['PaymentMethod'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(format_money($row['Amount'])) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="theme-link-btn" href="<?= e(url('modules/expenses/view.php?id=' . (int) $row['uid'])) ?>">View</a>
                                <a class="theme-link-btn ms-2" href="<?= e(url('modules/expenses/edit.php?id=' . (int) $row['uid'])) ?>">Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete this expense?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="expense_id" value="<?= (int) $row['uid'] ?>">
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
