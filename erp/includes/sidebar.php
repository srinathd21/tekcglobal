<?php
$currentPage = basename(parse_url($_SERVER['PHP_SELF'] ?? '', PHP_URL_PATH));
$userId = (int)($_SESSION['user_id'] ?? 0);
$sidebarMenus = [];

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
    INNER JOIN role_sidebar_access rsa
        ON rsa.menu_id = sm.id
       AND rsa.can_view = 1
    INNER JOIN user_roles ur
        ON ur.role_id = rsa.role_id
    INNER JOIN roles r
        ON r.id = ur.role_id
       AND r.is_active = 1
    INNER JOIN users u
        ON u.id = ur.user_id
       AND u.status = 'active'
    WHERE ur.user_id = ?
      AND sm.is_active = 1
    ORDER BY
        CASE
            WHEN sm.parent_id IS NULL THEN sm.sort_order
            ELSE 999999
        END ASC,
        CASE
            WHEN sm.parent_id IS NULL THEN sm.id
            ELSE sm.parent_id
        END ASC,
        sm.parent_id IS NOT NULL ASC,
        sm.sort_order ASC,
        sm.id ASC
";

$stmt = mysqli_prepare($conn, $sidebarSql);

if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $sidebarMenus[] = $row;
    }

    mysqli_stmt_close($stmt);
}

$mainMenus = [];
$subMenus = [];

foreach ($sidebarMenus as $menu) {
    $menuId = (int)($menu['id'] ?? 0);
    $parentId = (int)($menu['parent_id'] ?? 0);

    if ($parentId <= 0) {
        $mainMenus[$menuId] = $menu;
        $mainMenus[$menuId]['children'] = [];
    } else {
        $subMenus[$parentId][] = $menu;
    }
}

foreach ($subMenus as $parentId => $children) {
    if (isset($mainMenus[$parentId])) {
        $mainMenus[$parentId]['children'] = $children;
    }
}

uasort($mainMenus, static function ($a, $b) {
    $sort = (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0);

    return $sort !== 0
        ? $sort
        : ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
});

foreach ($mainMenus as $mainId => $mainMenu) {
    if (empty($mainMenus[$mainId]['children'])) {
        continue;
    }

    usort($mainMenus[$mainId]['children'], static function ($a, $b) {
        $sort = (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0);

        return $sort !== 0
            ? $sort
            : ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
    });
}

if (!function_exists('sidebar_e')) {
    function sidebar_e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sidebar_active')) {
    function sidebar_active(string $url, string $currentPage): bool
    {
        $url = trim($url);

        if ($url === '' || $url === '#') {
            return false;
        }

        return basename((string)parse_url($url, PHP_URL_PATH)) === $currentPage;
    }
}

if (!function_exists('sidebar_child_active')) {
    function sidebar_child_active(array $children, string $currentPage): bool
    {
        foreach ($children as $child) {
            if (sidebar_active((string)($child['menu_url'] ?? ''), $currentPage)) {
                return true;
            }
        }

        return false;
    }
}
?>

<style>
:root {
    --sidebar-expanded-width: 268px;
    --sidebar-collapsed-width: 88px;
    --sidebar-flyout-width: 306px;
}

#sidebar {
    width: var(--sidebar-expanded-width);
    min-width: var(--sidebar-expanded-width);
    overflow: visible !important;
    transition: width .24s ease, min-width .24s ease;
    position: fixed !important;
    left: 0;
    top: 0;
    bottom: 0;
    z-index: 1040;
}

#sidebar .sidebar-nav {
    overflow-y: auto;
    overflow-x: visible !important;
}

#sidebar .nav-link-custom {
    position: relative;
    display: flex;
    align-items: center;
    min-height: 46px;
    gap: 12px;
    white-space: nowrap;
}

#sidebar .nav-link-custom>svg,
#sidebar .nav-link-custom>i {
    width: 20px;
    height: 20px;
    min-width: 20px;
    flex: 0 0 20px;
}

#sidebar .sidebar-flyout-title {
    display: none;
}

#sidebar.collapsed,
body.sidebar-collapsed #sidebar,
html.sidebar-collapsed #sidebar {
    width: var(--sidebar-collapsed-width) !important;
    min-width: var(--sidebar-collapsed-width) !important;
}

#sidebar.collapsed .sidebar-brand-text,
#sidebar.collapsed .sidebar-text,
#sidebar.collapsed .sidebar-help-text,
#sidebar.collapsed .sidebar-sub-arrow,
#sidebar.collapsed .sidebar-arrow,
body.sidebar-collapsed #sidebar .sidebar-brand-text,
body.sidebar-collapsed #sidebar .sidebar-text,
body.sidebar-collapsed #sidebar .sidebar-help-text,
body.sidebar-collapsed #sidebar .sidebar-sub-arrow,
body.sidebar-collapsed #sidebar .sidebar-arrow,
html.sidebar-collapsed #sidebar .sidebar-brand-text,
html.sidebar-collapsed #sidebar .sidebar-text,
html.sidebar-collapsed #sidebar .sidebar-help-text,
html.sidebar-collapsed #sidebar .sidebar-sub-arrow,
html.sidebar-collapsed #sidebar .sidebar-arrow {
    display: none !important;
}

#sidebar.collapsed .sidebar-header,
body.sidebar-collapsed #sidebar .sidebar-header,
html.sidebar-collapsed #sidebar .sidebar-header {
    justify-content: center;
    padding-left: 0;
    padding-right: 0;
}

#sidebar.collapsed .sidebar-header>.d-flex,
body.sidebar-collapsed #sidebar .sidebar-header>.d-flex,
html.sidebar-collapsed #sidebar .sidebar-header>.d-flex {
    justify-content: center;
    gap: 0 !important;
}

#sidebar.collapsed .nav-link-custom,
body.sidebar-collapsed #sidebar .nav-link-custom,
html.sidebar-collapsed #sidebar .nav-link-custom {
    width: 56px;
    min-width: 56px;
    height: 48px;
    margin: 5px auto;
    padding: 0 !important;
    justify-content: center !important;
    gap: 0 !important;
    border-radius: 14px;
}

#sidebar.collapsed .nav-link-custom>svg,
#sidebar.collapsed .nav-link-custom>i,
body.sidebar-collapsed #sidebar .nav-link-custom>svg,
body.sidebar-collapsed #sidebar .nav-link-custom>i,
html.sidebar-collapsed #sidebar .nav-link-custom>svg,
html.sidebar-collapsed #sidebar .nav-link-custom>i {
    margin: 0 !important;
}

#sidebar.collapsed .sidebar-footer,
body.sidebar-collapsed #sidebar .sidebar-footer,
html.sidebar-collapsed #sidebar .sidebar-footer {
    padding-left: 0;
    padding-right: 0;
}

#sidebar.collapsed .sidebar-footer>.d-flex,
body.sidebar-collapsed #sidebar .sidebar-footer>.d-flex,
html.sidebar-collapsed #sidebar .sidebar-footer>.d-flex {
    justify-content: center !important;
}

/*
     * Collapsed submenu becomes a desktop flyout.
     */
#sidebar.collapsed .sidebar-submenu,
body.sidebar-collapsed #sidebar .sidebar-submenu,
html.sidebar-collapsed #sidebar .sidebar-submenu {
    display: none !important;
    position: fixed !important;
    width: var(--sidebar-flyout-width);
    min-width: var(--sidebar-flyout-width);
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    padding: 14px;
    border-radius: 18px;
    background: #2f3a45 !important;
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, .16);
    box-shadow: 0 18px 45px rgba(0, 0, 0, .30);
    z-index: 1090;
}

#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open {
    display: block !important;
}

#sidebar.collapsed .sidebar-submenu .sidebar-flyout-title,
body.sidebar-collapsed #sidebar .sidebar-submenu .sidebar-flyout-title,
html.sidebar-collapsed #sidebar .sidebar-submenu .sidebar-flyout-title {
    display: block;
    color: #ffffff !important;
    font-size: 16px;
    font-weight: 800 !important;
    opacity: 1 !important;
    padding: 4px 10px 12px;
    margin-bottom: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
}

#sidebar.collapsed .sidebar-submenu .nav-link-custom,
body.sidebar-collapsed #sidebar .sidebar-submenu .nav-link-custom,
html.sidebar-collapsed #sidebar .sidebar-submenu .nav-link-custom {
    width: 100%;
    min-width: 0;
    height: auto;
    min-height: 46px;
    margin: 2px 0;
    padding: 10px 12px !important;
    justify-content: flex-start !important;
    gap: 12px !important;
    border-radius: 12px;
}

#sidebar.collapsed .sidebar-submenu .sidebar-text,
body.sidebar-collapsed #sidebar .sidebar-submenu .sidebar-text,
html.sidebar-collapsed #sidebar .sidebar-submenu .sidebar-text {
    display: inline !important;
    color: inherit;
}

#sidebar.collapsed .sidebar-submenu .nav-link-custom>svg,
body.sidebar-collapsed #sidebar .sidebar-submenu .nav-link-custom>svg,
html.sidebar-collapsed #sidebar .sidebar-submenu .nav-link-custom>svg {
    flex: 0 0 20px;
}

#sidebar.collapsed .sidebar-collapse-link.active,
body.sidebar-collapsed #sidebar .sidebar-collapse-link.active,
html.sidebar-collapsed #sidebar .sidebar-collapse-link.active {
    box-shadow: 0 8px 20px rgba(255, 196, 0, .22);
}

@media (max-width: 1199.98px) {

    #sidebar,
    #sidebar.collapsed,
    body.sidebar-collapsed #sidebar,
    html.sidebar-collapsed #sidebar {
        width: var(--sidebar-expanded-width) !important;
        min-width: var(--sidebar-expanded-width) !important;
    }

    #sidebar .sidebar-brand-text,
    #sidebar .sidebar-text,
    #sidebar .sidebar-help-text,
    #sidebar .sidebar-sub-arrow,
    #sidebar .sidebar-arrow {
        display: initial !important;
    }

    #sidebar .nav-link-custom {
        width: auto !important;
        min-width: 0 !important;
        height: auto !important;
        min-height: 46px;
        margin: 0 !important;
        padding: 10px 14px !important;
        justify-content: flex-start !important;
        gap: 12px !important;
    }

    #sidebar .sidebar-submenu {
        position: static !important;
        width: auto !important;
        min-width: 0 !important;
        max-height: none !important;
        padding: 0 !important;
        border: 0 !important;
        box-shadow: none !important;
        background: transparent !important;
    }

    #sidebar .sidebar-flyout-title {
        display: none !important;
    }
}

/* Flyout color fix - collapsed sidebar */
#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open {
    background: #2f3a45 !important;
    color: #ffffff !important;
    border-color: rgba(255, 255, 255, .16) !important;
}

#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open .sidebar-flyout-title,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .sidebar-flyout-title,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .sidebar-flyout-title {
    color: #ffffff !important;
    font-weight: 800 !important;
    opacity: 1 !important;
    border-bottom: 1px solid rgba(255, 255, 255, .18) !important;
}

#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open .nav-link-custom,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom,
#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open .sidebar-text,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .sidebar-text,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .sidebar-text {
    color: #ffffff !important;
    font-weight: 800 !important;
    opacity: 1 !important;
}

#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open svg,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open svg,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open svg,
#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open i,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open i,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open i {
    color: #ffffff !important;
    stroke: #ffffff !important;
}

#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open .nav-link-custom:hover,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom:hover,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom:hover {
    background: rgba(255, 255, 255, .10) !important;
    color: #ffffff !important;
}

#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open .nav-link-custom.active,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom.active,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom.active {
    background: rgba(255, 255, 255, .14) !important;
    color: #ffffff !important;
}

/* Final color alignment: collapsed flyout follows sidebar background */
#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open {
    background: #2f3a45 !important;
    color: #ffffff !important;
    border-color: rgba(255, 255, 255, .16) !important;
}

#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open .sidebar-flyout-title,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .sidebar-flyout-title,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .sidebar-flyout-title {
    color: #ffffff !important;
    font-weight: 800 !important;
    opacity: 1 !important;
    border-bottom-color: rgba(255, 255, 255, .18) !important;
}

#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open .nav-link-custom,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom,
#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open .sidebar-text,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .sidebar-text,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .sidebar-text {
    color: #ffffff !important;
    font-weight: 800 !important;
}

#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open svg,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open svg,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open svg,
#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open i,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open i,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open i {
    color: #ffffff !important;
    stroke: #ffffff !important;
}

#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open .nav-link-custom:hover,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom:hover,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom:hover {
    background: rgba(255, 255, 255, .10) !important;
    color: #ffffff !important;
}

#sidebar.collapsed .sidebar-submenu.sidebar-flyout-open .nav-link-custom.active,
body.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom.active,
html.sidebar-collapsed #sidebar .sidebar-submenu.sidebar-flyout-open .nav-link-custom.active {
    background: rgba(255, 255, 255, .14) !important;
    color: #ffffff !important;
}
</style>

<aside id="sidebar">
    <div class="sidebar-header">
        <div class="d-flex align-items-center gap-2 w-100">
            <div id="sidebarLogo" class="brand-gradient">
                <img src="assets/img/logo.png" style="width:100%;height:100%;object-fit:contain;" alt="TEK-C Logo">
            </div>

            <div class="sidebar-brand-text">
                <span>TEK</span><span class="brand-accent-text">-C</span>
            </div>

            <button id="closeMobileSidebar" class="icon-btn ms-auto d-xl-none border-0" type="button"
                aria-label="Close sidebar">
                <i data-lucide="x"></i>
            </button>
        </div>
    </div>

    <nav class="sidebar-nav thin-scrollbar">
        <?php foreach ($mainMenus as $main): ?>
        <?php
            $children = $main['children'] ?? [];
            $hasChildren = !empty($children);
            $isMainActive = sidebar_active((string)($main['menu_url'] ?? ''), $currentPage);
            $hasActiveChild = sidebar_child_active($children, $currentPage);
            $collapseId = 'sidebarMenu_' . (int)$main['id'];
            $icon = trim((string)($main['icon'] ?? '')) ?: 'circle';
            $title = (string)($main['menu_title'] ?? '');
            ?>

        <?php if ($hasChildren): ?>
        <a href="#<?= sidebar_e($collapseId) ?>"
            class="nav-link-custom sidebar-collapse-link <?= $hasActiveChild ? 'active' : '' ?>"
            data-bs-toggle="collapse" data-sidebar-target="<?= sidebar_e($collapseId) ?>" role="button"
            aria-expanded="<?= $hasActiveChild ? 'true' : 'false' ?>" aria-controls="<?= sidebar_e($collapseId) ?>"
            title="<?= sidebar_e($title) ?>">
            <i data-lucide="<?= sidebar_e($icon) ?>"></i>
            <span class="sidebar-text"><?= sidebar_e($title) ?></span>
            <i data-lucide="chevron-down" class="ms-auto sidebar-sub-arrow"></i>
        </a>

        <div class="collapse sidebar-submenu <?= $hasActiveChild ? 'show' : '' ?>" id="<?= sidebar_e($collapseId) ?>">
            <div class="sidebar-flyout-title">
                <?= sidebar_e($title) ?>
            </div>

            <?php foreach ($children as $child): ?>
            <?php
                        $childUrl = trim((string)($child['menu_url'] ?? '#')) ?: '#';
                        $childIcon = trim((string)($child['icon'] ?? '')) ?: 'circle';
                        $childTitle = (string)($child['menu_title'] ?? '');
                        ?>

            <a href="<?= sidebar_e($childUrl) ?>"
                class="nav-link-custom sidebar-sub-link <?= sidebar_active($childUrl, $currentPage) ? 'active' : '' ?>">
                <i data-lucide="<?= sidebar_e($childIcon) ?>"></i>
                <span class="sidebar-text">
                    <?= sidebar_e($childTitle) ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <?php
                $mainUrl = trim((string)($main['menu_url'] ?? '#')) ?: '#';
                ?>

        <a href="<?= sidebar_e($mainUrl) ?>" class="nav-link-custom <?= $isMainActive ? 'active' : '' ?>"
            title="<?= sidebar_e($title) ?>">
            <i data-lucide="<?= sidebar_e($icon) ?>"></i>
            <span class="sidebar-text"><?= sidebar_e($title) ?></span>
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
                    <p class="mb-0 text-muted-custom" style="font-size:11px;">
                        Contact TEK-C Helpdesk
                    </p>
                </div>
            </div>

            <i data-lucide="chevron-right" class="sidebar-arrow text-muted-custom" style="width:16px;height:16px;"></i>
        </div>
    </div>
</aside>

<script>
(function() {
    const sidebar = document.getElementById('sidebar');

    if (!sidebar) {
        return;
    }

    let openFlyout = null;
    let openTrigger = null;

    function isDesktop() {
        return window.matchMedia('(min-width: 1200px)').matches;
    }

    function isCollapsed() {
        return sidebar.classList.contains('collapsed') ||
            document.body.classList.contains('sidebar-collapsed') ||
            document.documentElement.classList.contains('sidebar-collapsed');
    }

    function closeFlyout() {
        if (openFlyout) {
            openFlyout.classList.remove('sidebar-flyout-open');
            openFlyout.style.top = '';
            openFlyout.style.left = '';
        }

        if (openTrigger) {
            openTrigger.setAttribute('aria-expanded', 'false');
        }

        openFlyout = null;
        openTrigger = null;
    }

    function positionFlyout(trigger, flyout) {
        const triggerRect = trigger.getBoundingClientRect();
        const gap = 10;
        const flyoutWidth = flyout.offsetWidth || 306;
        const flyoutHeight = flyout.offsetHeight || 200;
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;

        let left = triggerRect.right + gap;
        let top = triggerRect.top;

        if (left + flyoutWidth > viewportWidth - 12) {
            left = triggerRect.left - flyoutWidth - gap;
        }

        if (top + flyoutHeight > viewportHeight - 12) {
            top = Math.max(12, viewportHeight - flyoutHeight - 12);
        }

        flyout.style.left = `${Math.max(12, left)}px`;
        flyout.style.top = `${Math.max(12, top)}px`;
    }

    function openCollapsedFlyout(trigger) {
        const targetId = trigger.dataset.sidebarTarget;
        const flyout = document.getElementById(targetId);

        if (!flyout) {
            return;
        }

        if (openFlyout === flyout) {
            closeFlyout();
            return;
        }

        closeFlyout();

        flyout.classList.remove('show');
        flyout.classList.add('sidebar-flyout-open');
        trigger.setAttribute('aria-expanded', 'true');

        openFlyout = flyout;
        openTrigger = trigger;

        requestAnimationFrame(function() {
            positionFlyout(trigger, flyout);
        });
    }

    sidebar.addEventListener('click', function(event) {
        const trigger = event.target.closest('.sidebar-collapse-link');

        if (!trigger || !isDesktop() || !isCollapsed()) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        openCollapsedFlyout(trigger);
    }, true);

    document.addEventListener('click', function(event) {
        if (!openFlyout) {
            return;
        }

        if (
            openFlyout.contains(event.target) ||
            (openTrigger && openTrigger.contains(event.target))
        ) {
            return;
        }

        closeFlyout();
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeFlyout();
        }
    });

    window.addEventListener('resize', function() {
        if (!openFlyout) {
            return;
        }

        if (!isDesktop() || !isCollapsed()) {
            closeFlyout();
            return;
        }

        positionFlyout(openTrigger, openFlyout);
    });

    window.addEventListener('scroll', function() {
        if (openFlyout && openTrigger) {
            positionFlyout(openTrigger, openFlyout);
        }
    }, true);

    const observer = new MutationObserver(function() {
        if (!isCollapsed()) {
            closeFlyout();
        }
    });

    observer.observe(sidebar, {
        attributes: true,
        attributeFilter: ['class']
    });

    observer.observe(document.body, {
        attributes: true,
        attributeFilter: ['class']
    });

    if (window.lucide) {
        window.lucide.createIcons();
    }
})();
</script>