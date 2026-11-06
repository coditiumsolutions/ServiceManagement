<?php
/**
 * Dark navy sidebar navigation built from sidebar_menu().
 * Multi-item sections render as expand/collapse groups.
 * The item whose key equals $activeMenu is highlighted.
 * Included by includes/header.php.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

$sidebarActiveKey = isset($activeMenu) ? (string) $activeMenu : '';
$sidebarGroupIcons = sidebar_group_icons();
?>
<aside class="app-sidebar" id="appSidebar" aria-label="Main navigation">
    <div class="sidebar-inner">
        <ul class="sidebar-nav list-unstyled mb-0">
            <?php foreach (sidebar_menu() as $sectionTitle => $sectionItems): ?>
                <?php
                $isCollapsible = count($sectionItems) > 1;
                $sectionHasActive = false;
                foreach ($sectionItems as $menuItem) {
                    if ($menuItem['key'] === $sidebarActiveKey) {
                        $sectionHasActive = true;
                        break;
                    }
                }
                $groupId = 'sidebar-group-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($sectionTitle));
                $groupIcon = $sidebarGroupIcons[$sectionTitle] ?? 'bi-folder';
                ?>

                <?php if (!$isCollapsible): ?>
                    <?php foreach ($sectionItems as $menuItem): ?>
                        <?php $isActive = $menuItem['key'] === $sidebarActiveKey; ?>
                        <li>
                            <a href="<?= e(url($menuItem['path'])) ?>"
                               class="sidebar-link<?= $isActive ? ' active' : '' ?>"
                               <?= $isActive ? 'aria-current="page"' : '' ?>>
                                <i class="bi <?= e($menuItem['icon']) ?>"></i>
                                <span><?= e($menuItem['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="sidebar-group<?= $sectionHasActive ? ' is-open' : '' ?>">
                        <button type="button"
                                class="sidebar-link sidebar-group-toggle<?= $sectionHasActive ? ' has-active' : '' ?>"
                                data-sidebar-toggle="<?= e($groupId) ?>"
                                aria-expanded="<?= $sectionHasActive ? 'true' : 'false' ?>"
                                aria-controls="<?= e($groupId) ?>">
                            <i class="bi <?= e($groupIcon) ?>"></i>
                            <span><?= e($sectionTitle) ?></span>
                            <i class="bi bi-chevron-down sidebar-group-caret" aria-hidden="true"></i>
                        </button>
                        <ul class="sidebar-submenu list-unstyled mb-0"
                            id="<?= e($groupId) ?>"
                            <?= $sectionHasActive ? '' : 'hidden' ?>>
                            <?php foreach ($sectionItems as $menuItem): ?>
                                <?php $isActive = $menuItem['key'] === $sidebarActiveKey; ?>
                                <li>
                                    <?php if ($menuItem['key'] === 'logout'): ?>
                                        <form method="post" action="<?= e(url($menuItem['path'])) ?>" class="m-0">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="sidebar-link sidebar-sublink sidebar-link-button">
                                                <i class="bi <?= e($menuItem['icon']) ?>"></i>
                                                <span><?= e($menuItem['label']) ?></span>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <a href="<?= e(url($menuItem['path'])) ?>"
                                           class="sidebar-link sidebar-sublink<?= $isActive ? ' active' : '' ?>"
                                           <?= $isActive ? 'aria-current="page"' : '' ?>>
                                            <i class="bi <?= e($menuItem['icon']) ?>"></i>
                                            <span><?= e($menuItem['label']) ?></span>
                                        </a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="sidebar-footer">
        <i class="bi bi-shield-lock me-1"></i> v<?= e(APP_VERSION) ?>
    </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>
