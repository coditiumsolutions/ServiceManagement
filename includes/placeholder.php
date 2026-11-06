<?php
/**
 * Shared "coming in a later step" page for modules not built yet.
 *
 * A module page sets these variables and then includes this file:
 *   $activeMenu      (string)   sidebar key, e.g. 'customers'
 *   $plannedFeatures (string[]) optional bullet list of upcoming features
 *   $pageTitle       (string)   optional; defaults to the menu label
 *
 * The page must already have called require_login().
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

$placeholderItem = find_menu_item((string) ($activeMenu ?? '')) ?? ['label' => 'Module', 'icon' => 'bi-grid'];
$pageTitle       = $pageTitle ?? $placeholderItem['label'];
$pageSubtitle    = $pageSubtitle ?? 'This module will be built in a later step.';
$plannedFeatures = $plannedFeatures ?? [];

require __DIR__ . '/header.php';
?>
<div class="card app-card placeholder-card">
    <div class="card-body text-center py-5 px-4">
        <div class="placeholder-icon mx-auto mb-3">
            <i class="bi <?= e($placeholderItem['icon']) ?>"></i>
        </div>
        <span class="badge badge-soft-info mb-3"><i class="bi bi-hourglass me-1"></i>Coming in a later step</span>
        <h2 class="h4 fw-semibold mb-2"><?= e($pageTitle) ?></h2>
        <p class="text-muted mb-4 mx-auto placeholder-text">
            The <?= e($pageTitle) ?> module is part of the application roadmap. The layout, navigation and
            security are in place; its screens and business logic will be added in an upcoming development step.
        </p>

        <?php if ($plannedFeatures !== []): ?>
            <div class="planned-features mx-auto text-start mb-4">
                <div class="small text-uppercase fw-semibold text-muted mb-2">Planned features</div>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($plannedFeatures as $feature): ?>
                        <li><i class="bi bi-check2 text-primary me-2"></i><?= e($feature) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <a href="<?= e(url('dashboard/index.php')) ?>" class="btn btn-primary">
            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>
</div>
<?php
require __DIR__ . '/footer.php';
