<?php
/**
 * Page header: <head>, top navbar, sidebar and the opening of the main area.
 *
 * Variables a page may set BEFORE including this file:
 *   $pageTitle    (string) title shown in the browser tab and page heading
 *   $pageSubtitle (string) optional short description under the heading
 *   $activeMenu   (string) sidebar menu key to highlight (see sidebar_menu())
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle    = isset($pageTitle) ? (string) $pageTitle : 'Dashboard';
$pageSubtitle = isset($pageSubtitle) ? (string) $pageSubtitle : '';
$activeMenu   = isset($activeMenu) ? (string) $activeMenu : '';
$authUser     = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="app-body">

<?php require __DIR__ . '/navbar.php'; ?>
<?php require __DIR__ . '/sidebar.php'; ?>

<main class="app-main" id="main-content">
    <div class="container-fluid app-content">
        <?php foreach (pull_flash_messages() as $flashMessage): ?>
            <div class="alert alert-<?= e($flashMessage['type']) ?> alert-dismissible fade show" role="alert" data-auto-dismiss>
                <?= e($flashMessage['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
