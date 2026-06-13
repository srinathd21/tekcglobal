<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$userId = $_SESSION['user_id'] ?? 0;

$sidebarMenus = [];

if ($userId > 0) {
    $sidebarSql = "
        SELECT DISTINCT
            sm.id,
            sm.parent_id,
            sm.menu_title,
            sm.menu_slug,
            sm.menu_url,
            sm.icon,
            sm.menu_type,
            sm.has_submenu,
            sm.sort_order
        FROM sidebar_menus sm
        JOIN role_sidebar_access rsa ON rsa.menu_id = sm.id
        JOIN user_roles ur ON ur.role_id = rsa.role_id
        JOIN roles r ON r.id = ur.role_id
        JOIN users u ON u.id = ur.user_id
        WHERE ur.user_id = ?
          AND u.status = 'active'
          AND r.is_active = 1
          AND sm.is_active = 1
          AND rsa.can_view = 1
        ORDER BY
          CASE WHEN sm.parent_id IS NULL THEN sm.sort_order ELSE 999999 END ASC,
          CASE WHEN sm.parent_id IS NULL THEN sm.id ELSE sm.parent_id END ASC,
          sm.parent_id IS NOT NULL ASC,
          sm.sort_order ASC,
          sm.id ASC
    ";

    $stmt = mysqli_prepare($conn, $sidebarSql);
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $sidebarResult = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($sidebarResult)) {
        $sidebarMenus[] = $row;
    }

    mysqli_stmt_close($stmt);
} else {
    $sidebarResult = mysqli_query($conn, "
        SELECT
            id,
            parent_id,
            menu_title,
            menu_slug,
            menu_url,
            icon,
            menu_type,
            has_submenu,
            sort_order
        FROM sidebar_menus
        WHERE is_active = 1
        ORDER BY
            CASE WHEN parent_id IS NULL THEN sort_order ELSE 999999 END ASC,
            CASE WHEN parent_id IS NULL THEN id ELSE parent_id END ASC,
            parent_id IS NOT NULL ASC,
            sort_order ASC,
            id ASC
    ");

    while ($row = mysqli_fetch_assoc($sidebarResult)) {
        $sidebarMenus[] = $row;
    }
}

$mainMenus = [];
$subMenus = [];

foreach ($sidebarMenus as $menu) {
    if (empty($menu['parent_id'])) {
        $mainMenus[$menu['id']] = $menu;
        $mainMenus[$menu['id']]['children'] = [];
    } else {
        $subMenus[$menu['parent_id']][] = $menu;
    }
}

foreach ($subMenus as $parentId => $children) {
    if (isset($mainMenus[$parentId])) {
        $mainMenus[$parentId]['children'] = $children;
    }
}

/*
|--------------------------------------------------------------------------
| Final PHP-side sort
|--------------------------------------------------------------------------
| This guarantees display order even if MySQL returns mixed rows because of
| role access joins. Main menus are sorted by sort_order, then id.
| Submenus are sorted inside each parent by sort_order, then id.
*/

uasort($mainMenus, function ($a, $b) {
    $aSort = (int)($a['sort_order'] ?? 0);
    $bSort = (int)($b['sort_order'] ?? 0);

    if ($aSort === $bSort) {
        return (int)$a['id'] <=> (int)$b['id'];
    }

    return $aSort <=> $bSort;
});

foreach ($mainMenus as $mainId => $main) {
    if (!empty($mainMenus[$mainId]['children'])) {
        usort($mainMenus[$mainId]['children'], function ($a, $b) {
            $aSort = (int)($a['sort_order'] ?? 0);
            $bSort = (int)($b['sort_order'] ?? 0);

            if ($aSort === $bSort) {
                return (int)$a['id'] <=> (int)$b['id'];
            }

            return $aSort <=> $bSort;
        });
    }
}

function sidebar_e($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function sidebar_is_active($menuUrl, $currentPage)
{
    if (!$menuUrl || $menuUrl === '#') {
        return false;
    }

    $menuPage = basename(parse_url($menuUrl, PHP_URL_PATH));

    return $menuPage === $currentPage;
}

function sidebar_has_active_child($children, $currentPage)
{
    foreach ($children as $child) {
        if (sidebar_is_active($child['menu_url'], $currentPage)) {
            return true;
        }
    }

    return false;
}
?>

<aside id="sidebar">
    <div class="sidebar-header">
        <div class="d-flex align-items-center gap-2 w-100">
            <div id="sidebarLogo" class="brand-gradient">
                <img src="assets/img/logo.png" style="width:100%;" alt="TEK-C Logo">
            </div>

            <div class="sidebar-brand-text">
                <span>TEK</span><span class="brand-accent-text">-C</span>
            </div>

            <button id="closeMobileSidebar" class="icon-btn ms-auto d-xl-none border-0" type="button">
                <i data-lucide="x"></i>
            </button>
        </div>
    </div>

    <nav class="sidebar-nav thin-scrollbar">
        <?php foreach ($mainMenus as $main): ?>
            <?php
            $children = $main['children'] ?? [];
            $hasChildren = count($children) > 0;
            $isMainActive = sidebar_is_active($main['menu_url'], $currentPage);
            $hasActiveChild = sidebar_has_active_child($children, $currentPage);
            $collapseId = 'sidebarMenu_' . (int)$main['id'];
            $mainIcon = $main['icon'] ?: 'circle';
            ?>

            <?php if ($hasChildren): ?>
                <a href="#<?= sidebar_e($collapseId) ?>"
                   class="nav-link-custom sidebar-collapse-link <?= $hasActiveChild ? 'active' : '' ?>"
                   data-bs-toggle="collapse"
                   role="button"
                   aria-expanded="<?= $hasActiveChild ? 'true' : 'false' ?>"
                   aria-controls="<?= sidebar_e($collapseId) ?>">
                    <i data-lucide="<?= sidebar_e($mainIcon) ?>"></i>
                    <span class="sidebar-text"><?= sidebar_e($main['menu_title']) ?></span>
                    <i data-lucide="chevron-down" class="ms-auto sidebar-sub-arrow"></i>
                </a>

                <div class="collapse sidebar-submenu <?= $hasActiveChild ? 'show' : '' ?>" id="<?= sidebar_e($collapseId) ?>">
                    <?php foreach ($children as $child): ?>
                        <?php
                        $childIcon = $child['icon'] ?: 'circle';
                        $isChildActive = sidebar_is_active($child['menu_url'], $currentPage);
                        ?>
                        <a href="<?= sidebar_e($child['menu_url']) ?>"
                           class="nav-link-custom sidebar-sub-link <?= $isChildActive ? 'active' : '' ?>">
                            <i data-lucide="<?= sidebar_e($childIcon) ?>"></i>
                            <span class="sidebar-text"><?= sidebar_e($child['menu_title']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <a href="<?= sidebar_e($main['menu_url']) ?>"
                   class="nav-link-custom <?= $isMainActive ? 'active' : '' ?>">
                    <i data-lucide="<?= sidebar_e($mainIcon) ?>"></i>
                    <span class="sidebar-text"><?= sidebar_e($main['menu_title']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <div class="rounded-circle bg-body-secondary d-flex align-items-center justify-content-center flex-shrink-0"
                    style="width:36px;height:36px;">
                    <i data-lucide="headphones" style="width:16px;height:16px;"></i>
                </div>

                <div class="sidebar-help-text">
                    <p class="fw-bold small mb-0">Need Site Support?</p>
                    <p class="mb-0 text-muted-custom" style="font-size:11px;">Contact TEK-C Helpdesk</p>
                </div>
            </div>

            <i data-lucide="chevron-right" class="sidebar-arrow text-muted-custom"
                style="width:16px;height:16px;"></i>
        </div>
    </div>
</aside>