<?php
/**
 * Top header bar: application name, sidebar toggle and user menu.
 * Included by includes/header.php.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

$navUser     = current_user();
$navFullName = $navUser['full_name'] ?? 'User';
$navInitials = strtoupper(mb_substr($navFullName, 0, 1) . (mb_substr((string) strrchr($navFullName, ' '), 1, 1) ?: ''));
?>
<header class="app-header">
    <div class="d-flex align-items-center gap-2">
        <a href="<?= e(url('dashboard/index.php')) ?>" class="app-brand text-decoration-none">
            <span class="app-brand-icon"><i class="bi bi-gear-wide-connected"></i></span>
            <span class="app-brand-text d-none d-sm-inline"><?= e(APP_NAME) ?></span>
            <span class="app-brand-text d-inline d-sm-none"><?= e(APP_SHORT_NAME) ?></span>
        </a>
    </div>

    <div class="d-flex align-items-center gap-2">
        <span class="header-date d-none d-md-inline"><i class="bi bi-calendar3 me-1"></i><?= e(date('l, d M Y')) ?></span>

        <div class="dropdown">
            <button class="btn user-menu-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="user-avatar"><?= e($navInitials) ?></span>
                <span class="d-none d-md-inline user-name"><?= e($navFullName) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><h6 class="dropdown-header">Signed in as <?= e($navUser['username'] ?? '') ?></h6></li>
                <li><a class="dropdown-item" href="<?= e(url('modules/users/change_password.php')) ?>"><i class="bi bi-key me-2"></i>Change Password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="post" action="<?= e(url('auth/logout.php')) ?>" class="m-0">
                        <?= csrf_field() ?>
                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>

<div class="app-subheader" id="appSubheader" aria-label="Page toolbar">
    <div class="app-subheader-inner">
        <div class="d-flex align-items-center gap-2 min-w-0">
            <button type="button" class="btn btn-icon btn-icon-dark sidebar-toggle" id="sidebarToggle"
                    aria-controls="appSidebar" aria-expanded="false" aria-label="Toggle navigation">
                <i class="bi bi-list"></i>
            </button>
            <div class="app-subheader-title min-w-0">
                <h1 class="subheader-page-title"><?= e($pageTitle ?? 'Dashboard') ?></h1>
                <?php if (!empty($pageSubtitle)): ?>
                    <p class="subheader-page-subtitle mb-0"><?= e((string) $pageSubtitle) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= e(url('dashboard/index.php')) ?>"><i class="bi bi-house-door"></i> Home</a></li>
                <?php if (($activeMenu ?? '') !== 'dashboard'): ?>
                    <li class="breadcrumb-item active" aria-current="page"><?= e($pageTitle ?? '') ?></li>
                <?php endif; ?>
            </ol>
        </nav>
    </div>
</div>
