<?php
/**
 * Shared service-request list UI.
 *
 * Expected variables before include:
 *   $pageTitle, $pageSubtitle, $activeMenu
 *   $forcedStatus (string|null) — lock filter to one status when set
 *   $requests (array), $search, $statusFilter, $priorityFilter
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form method="get" class="row g-2 align-items-end flex-grow-1">
        <div class="col-md-4">
            <label class="form-label mb-1" for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Request no, customer, mobile">
        </div>
        <?php if ($forcedStatus === null): ?>
            <div class="col-md-2">
                <label class="form-label mb-1" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <?php foreach (request_statuses() as $statusOption): ?>
                        <option value="<?= e($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                            <?= e(status_label($statusOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="col-md-2">
            <label class="form-label mb-1" for="priority">Priority</label>
            <select class="form-select" id="priority" name="priority">
                <option value="">All</option>
                <?php foreach (request_priorities() as $priorityOption): ?>
                    <option value="<?= e($priorityOption) ?>" <?= $priorityFilter === $priorityOption ? 'selected' : '' ?>>
                        <?= e($priorityOption) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="<?= e(url('modules/service_requests/' . basename($_SERVER['SCRIPT_NAME']))) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
    <div class="ms-md-2">
        <a href="<?= e(url('modules/service_requests/create.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>New Request
        </a>
    </div>
</div>

<div class="app-card card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Request No</th>
                    <th>Customer</th>
                    <th>Service</th>
                    <th>Preferred</th>
                    <th>Priority</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($requests === []): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No service requests found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $row): ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('modules/service_requests/view.php?id=' . (int) $row['uid'])) ?>" class="fw-semibold text-decoration-none">
                                    <?= e((string) $row['RequestNo']) ?>
                                </a>
                                <div class="small text-muted"><?= e(format_datetime((string) $row['RequestDateTime'])) ?></div>
                            </td>
                            <td>
                                <div><?= e((string) $row['CustomerName']) ?></div>
                                <div class="small text-muted"><?= e((string) $row['CustomerMobile']) ?></div>
                            </td>
                            <td><?= e((string) $row['ServiceName']) ?></td>
                            <td>
                                <?php if (!empty($row['PreferredDate'])): ?>
                                    <?= e(format_datetime((string) $row['PreferredDate'], 'd M Y')) ?>
                                    <?php if (!empty($row['PreferredTimeSlot'])): ?>
                                        <div class="small text-muted"><?= e((string) $row['PreferredTimeSlot']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $row['Priority']) ?></td>
                            <td><?= e(format_money($row['FinalAmount'])) ?></td>
                            <td><?= status_badge((string) $row['RequestStatus']) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/service_requests/view.php?id=' . (int) $row['uid'])) ?>">View</a>
                                <?php if (in_array((string) $row['RequestStatus'], ['Pending', 'Assigned'], true)): ?>
                                    <a class="btn btn-sm btn-outline-success"
                                       href="<?= e(url('modules/service_assignments/create.php?request_id=' . (int) $row['uid'])) ?>">Assign</a>
                                <?php endif; ?>
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
