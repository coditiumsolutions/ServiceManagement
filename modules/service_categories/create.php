<?php
/**
 * Add a service category.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_ops.php';
require_login();

$activeMenu   = 'service_categories';
$pageTitle    = 'Add Category';
$pageSubtitle = 'Create a new service category.';

$pdo = db();
$form = [
    'CategoryName' => '',
    'Description'  => '',
    'IsActive'     => '1',
];
$errors = [];

if (is_post_request()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('modules/service_categories/create.php');
    }

    $form['CategoryName'] = post_string('CategoryName', 100);
    $form['Description']  = post_string('Description', 500);
    $form['IsActive']     = post_string('IsActive', 5) === '1' ? '1' : '0';

    if (trim($form['CategoryName']) === '') {
        $errors[] = 'Category name is required.';
    }

    if ($errors === []) {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO ServiceCategories (CategoryName, Description, IsActive)
                 VALUES (?, ?, ?)'
            );
            $insert->execute([
                trim($form['CategoryName']),
                $form['Description'] !== '' ? $form['Description'] : null,
                (int) $form['IsActive'],
            ]);
            $newId = (int) $pdo->lastInsertId();
            regenerate_csrf_token();
            flash('success', 'Category created.');
            redirect('modules/service_categories/index.php');
        } catch (PDOException $exception) {
            error_log('[ServiceManagement] category create failed: ' . $exception->getMessage());
            if (str_contains($exception->getMessage(), 'UQ_ServiceCategories_CategoryName') || $exception->getCode() === '23000') {
                $errors[] = 'A category with this name already exists.';
            } else {
                $errors[] = 'Could not create the category. Please try again.';
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
        <strong>New category</strong>
        <a href="<?= e(url('modules/service_categories/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Back to list</a>
    </div>
    <div class="card-body form-grid-blue">
        <?= csrf_field() ?>
        <div class="form-row-blue">
            <div class="form-field span-2">
                <label class="form-label" for="CategoryName">Category name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="CategoryName" name="CategoryName"
                       value="<?= e($form['CategoryName']) ?>" maxlength="100" required>
            </div>
            <div class="form-field span-2">
                <label class="form-label" for="IsActive">Status</label>
                <select class="form-select" id="IsActive" name="IsActive">
                    <option value="1" <?= $form['IsActive'] === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $form['IsActive'] === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>
        <div class="form-row-blue">
            <div class="form-field span-4">
                <label class="form-label" for="Description">Description</label>
                <input type="text" class="form-control" id="Description" name="Description"
                       value="<?= e($form['Description']) ?>" maxlength="500">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="<?= e(url('modules/service_categories/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Create category</button>
    </div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
