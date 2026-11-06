<?php
/**
 * Edit a service type.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$pdo = db();
$id  = get_int('id');
if ($id <= 0) {
    flash('danger', 'Invalid service type.');
    redirect('modules/service_types/index.php');
}

$type = fetch_service_type($pdo, $id);
if ($type === null) {
    flash('danger', 'Service type not found.');
    redirect('modules/service_types/index.php');
}

$activeMenu   = 'service_types';
$pageTitle    = 'Edit Service Type';
$pageSubtitle = 'Update ' . (string) $type['ServiceName'] . '.';

$categories = active_service_categories($pdo);
// Keep current category selectable even if it was deactivated.
$currentCategoryListed = false;
foreach ($categories as $category) {
    if ((int) $category['uid'] === (int) $type['CategoryId']) {
        $currentCategoryListed = true;
        break;
    }
}
if (!$currentCategoryListed) {
    array_unshift($categories, [
        'uid'          => (int) $type['CategoryId'],
        'CategoryName' => (string) $type['CategoryName'] . ' (inactive)',
    ]);
}

$form = [
    'CategoryId'               => (int) $type['CategoryId'],
    'ServiceName'              => (string) $type['ServiceName'],
    'Description'              => (string) ($type['Description'] ?? ''),
    'BasePrice'                => number_format((float) $type['BasePrice'], 2, '.', ''),
    'EstimatedDurationMinutes' => $type['EstimatedDurationMinutes'] !== null ? (string) (int) $type['EstimatedDurationMinutes'] : '',
    'IsActive'                 => (int) $type['IsActive'] === 1 ? '1' : '0',
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/service_types/edit.php?id=' . $id);
    }

    $form['CategoryId']               = post_int('CategoryId');
    $form['ServiceName']              = post_string('ServiceName', 150);
    $form['Description']              = post_string('Description', 500);
    $form['BasePrice']                = number_format(post_float('BasePrice'), 2, '.', '');
    $form['EstimatedDurationMinutes'] = post_string('EstimatedDurationMinutes', 10);
    $form['IsActive']                 = post_string('IsActive', 5) === '1' ? '1' : '0';

    if ($form['CategoryId'] <= 0) {
        $errors[] = 'Please select a category.';
    } else {
        $catOk = false;
        foreach ($categories as $category) {
            if ((int) $category['uid'] === $form['CategoryId']) {
                $catOk = true;
                break;
            }
        }
        if (!$catOk) {
            $errors[] = 'Selected category is not available.';
        }
    }
    if (trim($form['ServiceName']) === '') {
        $errors[] = 'Service name is required.';
    }
    if ((float) $form['BasePrice'] < 0) {
        $errors[] = 'Base price cannot be negative.';
    }

    $duration = null;
    if ($form['EstimatedDurationMinutes'] !== '') {
        if (!ctype_digit($form['EstimatedDurationMinutes']) || (int) $form['EstimatedDurationMinutes'] <= 0) {
            $errors[] = 'Estimated duration must be a positive number of minutes.';
        } else {
            $duration = (int) $form['EstimatedDurationMinutes'];
        }
    }

    if ($errors === []) {
        try {
            $update = $pdo->prepare(
                'UPDATE ServiceTypes
                    SET CategoryId = ?, ServiceName = ?, Description = ?, BasePrice = ?,
                        EstimatedDurationMinutes = ?, IsActive = ?
                  WHERE uid = ?'
            );
            $update->execute([
                $form['CategoryId'],
                trim($form['ServiceName']),
                $form['Description'] !== '' ? $form['Description'] : null,
                (float) $form['BasePrice'],
                $duration,
                (int) $form['IsActive'],
                $id,
            ]);
            regenerate_csrf_token();
            flash('success', 'Service type updated.');
            redirect('modules/service_types/index.php?category_id=' . $form['CategoryId']);
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] service type edit failed: ' . $exception->getMessage());
            if (str_contains($exception->getMessage(), 'UQ_ServiceTypes_Category_Name') || $exception->getCode() === '23000') {
                $errors[] = 'A service type with this name already exists in the selected category.';
            } else {
                $errors[] = 'Could not update the service type. Please try again.';
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
        <strong>Edit service type</strong>
        <a href="<?= e(url('modules/service_types/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Back to list</a>
    </div>
    <div class="card-body form-grid-blue">
        <?= csrf_field() ?>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="CategoryId">Category <span class="text-danger">*</span></label>
                <select class="form-select" id="CategoryId" name="CategoryId" required>
                    <option value="">Select category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['uid'] ?>" <?= $form['CategoryId'] === (int) $category['uid'] ? 'selected' : '' ?>>
                            <?= e((string) $category['CategoryName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field span-2">
                <label class="form-label" for="ServiceName">Service name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="ServiceName" name="ServiceName"
                       value="<?= e($form['ServiceName']) ?>" maxlength="150" required>
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-4">
                <label class="form-label" for="Description">Description</label>
                <input type="text" class="form-control" id="Description" name="Description"
                       value="<?= e($form['Description']) ?>" maxlength="500">
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field">
                <label class="form-label" for="BasePrice">Base price <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0" class="form-control" id="BasePrice" name="BasePrice"
                       value="<?= e($form['BasePrice']) ?>" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="EstimatedDurationMinutes">Duration (minutes)</label>
                <input type="number" min="1" step="1" class="form-control" id="EstimatedDurationMinutes"
                       name="EstimatedDurationMinutes" value="<?= e($form['EstimatedDurationMinutes']) ?>">
            </div>
            <div class="form-field span-2">
                <label class="form-label" for="IsActive">Status</label>
                <select class="form-select" id="IsActive" name="IsActive">
                    <option value="1" <?= $form['IsActive'] === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $form['IsActive'] === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('modules/service_types/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save changes</button>
    </div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
