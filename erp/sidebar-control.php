<?php
session_start();
require_once __DIR__ . "/includes/db.php";

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Sidebar menu saved successfully.";
} elseif (isset($_GET["status"])) {
    $pageMessageType = "success";
    $pageMessageText = "Sidebar menu status changed successfully.";
} elseif (isset($_GET["deleted"])) {
    $pageMessageType = "success";
    $pageMessageText = "Sidebar menu deleted successfully.";
} elseif (isset($_GET["page_created"])) {
    $pageMessageType = "success";
    $pageMessageText = "Sidebar menu and page file created successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$selectedParentId = isset($_GET["parent_id"]) ? (int)$_GET["parent_id"] : 0;

$mainMenusQ = mysqli_query($conn, "
    SELECT id, menu_title, menu_slug, menu_url, icon, sort_order, is_active
    FROM sidebar_menus
    WHERE parent_id IS NULL
    ORDER BY sort_order ASC, id ASC
");

$mainMenus = [];
while ($m = mysqli_fetch_assoc($mainMenusQ)) {
    $mainMenus[] = $m;
}

/*
|--------------------------------------------------------------------------
| Grouped sidebar menu listing
|--------------------------------------------------------------------------
| Display rule:
| - Main menus are sorted by sort_order ASC.
| - Each main menu shows only its own submenus below it.
| - Submenus are sorted by sort_order ASC inside their parent.
| - If parent_id filter is selected, only that main menu and its children show.
*/

$menusQ = mysqli_query($conn, "
    SELECT 
        sm.*,
        parent.menu_title AS parent_title,
        COALESCE(parent.sort_order, sm.sort_order) AS parent_sort_order,
        COALESCE(parent.id, sm.id) AS parent_group_id
    FROM sidebar_menus sm
    LEFT JOIN sidebar_menus parent ON parent.id = sm.parent_id
    ORDER BY
        parent_sort_order ASC,
        parent_group_id ASC,
        CASE WHEN sm.parent_id IS NULL THEN 0 ELSE 1 END ASC,
        sm.sort_order ASC,
        sm.id ASC
");

$allRows = [];
$stats = [
    "total" => 0,
    "main" => 0,
    "sub" => 0,
    "active" => 0
];

while ($row = mysqli_fetch_assoc($menusQ)) {
    $allRows[] = $row;
    $stats["total"]++;

    if ($row["parent_id"] === null) {
        $stats["main"]++;
    } else {
        $stats["sub"]++;
    }

    if ((int)$row["is_active"] === 1) {
        $stats["active"]++;
    }
}

$menuRows = [];

foreach ($mainMenus as $main) {
    $mainId = (int)$main["id"];

    if ($selectedParentId > 0 && $selectedParentId !== $mainId) {
        continue;
    }

    foreach ($allRows as $row) {
        if ((int)$row["id"] === $mainId && $row["parent_id"] === null) {
            $menuRows[] = $row;
            break;
        }
    }

    $children = [];
    foreach ($allRows as $row) {
        if ((int)($row["parent_id"] ?? 0) === $mainId) {
            $children[] = $row;
        }
    }

    usort($children, function ($a, $b) {
        $aSort = (int)($a["sort_order"] ?? 0);
        $bSort = (int)($b["sort_order"] ?? 0);

        if ($aSort === $bSort) {
            return (int)$a["id"] <=> (int)$b["id"];
        }

        return $aSort <=> $bSort;
    });

    foreach ($children as $child) {
        $menuRows[] = $child;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Sidebar Control - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>

    <style>
        .page-head-card,
        .sidebar-tab-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px;
        }

        .kpi-row>[class*="col"] {
            display: flex;
        }

        .kpi-card {
            width: 100%;
            height: 100%;
            min-height: 118px;
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 24px;
            box-shadow: var(--shadow-card);
            padding: 22px 24px;
            display: flex;
            align-items: center;
            gap: 22px;
        }

        .kpi-icon {
            width: 58px;
            height: 58px;
            min-width: 58px;
            border-radius: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .kpi-label {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .kpi-value {
            color: var(--text-main);
            font-size: 24px;
            font-weight: 900;
            margin: 4px 0 2px;
            line-height: 1.15;
        }

        .kpi-sub {
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
            margin: 0;
        }

        .kpi-sub span {
            color: #008a5b;
            font-weight: 900;
        }

        .modal-content {
            background: var(--card-bg);
            color: var(--text-main);
            border: 1px solid var(--border-soft);
            border-radius: 24px;
            box-shadow: var(--shadow-card);
        }

        .modal-header,
        .modal-footer {
            border-color: var(--border-soft);
        }

        .form-control,
        .form-select {
            background: var(--card-bg);
            color: var(--text-main);
            border-color: var(--border-soft);
            min-height: 42px;
            font-size: 13px;
            font-weight: 600;
        }

        .form-control:focus,
        .form-select:focus {
            color: var(--text-main);
            background: var(--card-bg);
            border-color: var(--brand-2);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, .12);
        }

        .page-path-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--border-soft);
            background: rgba(148, 163, 184, .08);
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 800;
            color: var(--text-muted);
        }

        .menu-indent {
            padding-left: 22px;
        }

        .menu-icon-preview {
            width: 34px;
            height: 34px;
            min-width: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: rgba(148, 163, 184, .12);
            color: var(--text-main);
        }


        .filter-chip {
            border: 1px solid var(--border-soft);
            background: var(--card-bg);
            color: var(--text-main);
            border-radius: 999px;
            padding: 8px 13px;
            font-size: 12px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            transition: .2s ease;
        }

        .filter-chip:hover {
            background: rgba(148, 163, 184, .10);
            color: var(--text-main);
        }

        .filter-chip.active {
            color: #fff;
            background-image: linear-gradient(135deg, var(--brand-1), var(--brand-2));
            border-color: transparent;
        }

        .parent-row-highlight td {
            background: rgba(148, 163, 184, .06);
        }

        .delete-warning-box {
            border: 1px solid rgba(239, 68, 68, .30);
            background: rgba(239, 68, 68, .08);
            border-radius: 18px;
            padding: 14px;
        }
    </style>
</head>

<body>
    <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>

    <?php include("includes/page-message.php"); ?>

    <div class="min-vh-100 d-flex">
        <?php include("includes/sidebar.php"); ?>

        <main id="main">
            <?php include("includes/nav.php"); ?>

            <section class="page-section p-3 p-lg-3">
                <div class="page-head-card mb-3">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                        <div>
                            <h1 class="h4 fw-bold mb-1">Sidebar Control</h1>
                            <p class="text-muted-custom mb-0 small">Create main menus and assign submenus under the correct parent. Page files are created automatically from the given URL.</p>
                        </div>
                        <button type="button" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3"
                            data-bs-toggle="modal" data-bs-target="#sidebarMenuModal" onclick="openAddSidebarMenuModal()">
                            <i data-lucide="plus" style="width:15px;height:15px;"></i> Add Menu
                        </button>
                    </div>
                </div>

                <div class="row g-3 mb-3 kpi-row">
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                                <i data-lucide="panel-left" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Total Menus <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["total"] ?></p>
                                <p class="kpi-sub"><span>↑ <?= (int)$stats["total"] ?></span> sidebar items</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-success-subtle text-success">
                                <i data-lucide="layout-list" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Main Menus <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["main"] ?></p>
                                <p class="kpi-sub"><span>↑ <?= (int)$stats["main"] ?></span> parent menus</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                                <i data-lucide="list-tree" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Sub Menus <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["sub"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["sub"] ?></span> child menus</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-warning-subtle text-warning">
                                <i data-lucide="check-circle-2" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Active <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["active"] ?></p>
                                <p class="kpi-sub"><span>↑ <?= (int)$stats["active"] ?></span> visible items</p>
                            </div>
                        </article>
                    </div>
                </div>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4 border-bottom" style="border-color:var(--border-soft)!important;">
                        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3 mb-3">
                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Manage Sidebar Menus</h2>
                                <p class="text-muted-custom small mb-0">Click a main menu below to show only that menu and its assigned submenus.</p>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <a href="sidebar-control.php"
                               class="filter-chip <?= $selectedParentId === 0 ? 'active' : '' ?>">
                                <i data-lucide="list" style="width:14px;height:14px;"></i>
                                All Menus
                            </a>

                            <?php foreach ($mainMenus as $mainFilter): ?>
                                <a href="sidebar-control.php?parent_id=<?= (int)$mainFilter["id"] ?>"
                                   class="filter-chip <?= $selectedParentId === (int)$mainFilter["id"] ? 'active' : '' ?>">
                                    <i data-lucide="<?= e($mainFilter["icon"] ?: "circle") ?>" style="width:14px;height:14px;"></i>
                                    <?= e($mainFilter["menu_title"]) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                        <table class="project-table w-100">
                            <thead>
                                <tr>
                                    <th>Menu</th>
                                    <th>Type</th>
                                    <th>URL / Page</th>
                                    <th>Icon</th>
                                    <th>Sort</th>
                                    <th>Status</th>
                                    <th style="width:285px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($menuRows as $row): ?>
                                    <tr class="<?= $row["parent_id"] ? "" : "parent-row-highlight" ?>">
                                        <td>
                                            <div class="d-flex align-items-center gap-2 <?= $row["parent_id"] ? "menu-indent" : "" ?>">
                                                <span class="menu-icon-preview">
                                                    <i data-lucide="<?= e($row["icon"] ?: "circle") ?>" style="width:16px;height:16px;"></i>
                                                </span>
                                                <div>
                                                    <div class="fw-bold"><?= $row["parent_id"] ? "— " : "" ?><?= e($row["menu_title"]) ?></div>
                                                    <small class="text-muted-custom"><?= e($row["menu_slug"]) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="pill <?= $row["parent_id"] ? "amber" : "green" ?>">
                                                <?= $row["parent_id"] ? "Submenu" : "Main" ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="page-path-pill">
                                                <i data-lucide="file-code-2" style="width:13px;height:13px;"></i>
                                                <?= e($row["menu_url"]) ?>
                                            </span>
                                        </td>
                                        <td><?= e($row["icon"]) ?></td>
                                        <td><?= (int)$row["sort_order"] ?></td>
                                        <td>
                                            <span class="pill <?= (int)$row["is_active"] === 1 ? "green" : "amber" ?>">
                                                <?= (int)$row["is_active"] === 1 ? "Active" : "Inactive" ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                                data-bs-toggle="modal" data-bs-target="#sidebarMenuModal"
                                                onclick='openEditSidebarMenuModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>

                                            <a href="api/toggle-sidebar-menu.php?id=<?= (int)$row["id"] ?>"
                                                onclick="return confirm('Change sidebar menu status?')"
                                                class="btn btn-sm btn-outline-warning rounded-4 fw-bold">Toggle</a>

                                            <button type="button"
                                                class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteMenuModal"
                                                onclick='openDeleteSidebarMenuModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3">
                        <?php foreach ($menuRows as $row): ?>
                            <article class="mobile-project-card">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                    <div>
                                        <p class="fw-bold small mb-1"><?= $row["parent_id"] ? "— " : "" ?><?= e($row["menu_title"]) ?></p>
                                        <small class="text-muted-custom"><?= e($row["menu_url"]) ?></small>
                                    </div>
                                    <span class="pill <?= (int)$row["is_active"] === 1 ? "green" : "amber" ?>">
                                        <?= (int)$row["is_active"] === 1 ? "Active" : "Inactive" ?>
                                    </span>
                                </div>

                                <div class="mobile-info"><span>Type</span><span><?= $row["parent_id"] ? "Submenu" : "Main" ?></span></div>
                                <div class="mobile-info"><span>Icon</span><span><?= e($row["icon"]) ?></span></div>
                                <div class="mobile-info"><span>Sort</span><span><?= (int)$row["sort_order"] ?></span></div>

                                <div class="mt-3 d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                        data-bs-toggle="modal" data-bs-target="#sidebarMenuModal"
                                        onclick='openEditSidebarMenuModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>

                                    <a href="api/toggle-sidebar-menu.php?id=<?= (int)$row["id"] ?>"
                                        onclick="return confirm('Change sidebar menu status?')"
                                        class="btn btn-sm btn-outline-warning rounded-4 fw-bold">Toggle</a>

                                    <button type="button"
                                        class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                                        data-bs-toggle="modal"
                                        data-bs-target="#deleteMenuModal"
                                        onclick='openDeleteSidebarMenuModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Delete</button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include("includes/rightsidbar.php"); ?>
    </div>

    <div class="modal fade" id="sidebarMenuModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form action="api/process-sidebar-menu.php" method="POST" class="modal-content">
                <input type="hidden" name="menu_id" id="menu_id">

                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold" id="sidebarMenuModalTitle">Add Sidebar Menu</h5>
                        <p class="text-muted-custom small mb-0">Creates menu record and page file, for example projects.php or sites.php.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Menu Type</label>
                        <select name="menu_kind" id="menu_kind" class="form-select rounded-4" onchange="toggleParentMenu()">
                            <option value="main">Main Menu</option>
                            <option value="sub">Submenu</option>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="parentMenuWrap">
                        <label class="form-label fw-bold small">Parent Main Menu</label>
                        <select name="parent_id" id="parent_id" class="form-select rounded-4">
                            <option value="">Select Parent</option>
                            <?php foreach ($mainMenus as $main): ?>
                                <option value="<?= (int)$main["id"] ?>"><?= e($main["menu_title"]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Menu Title</label>
                        <input type="text" name="menu_title" id="menu_title" class="form-control rounded-4" required placeholder="Projects">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Menu Slug</label>
                        <input type="text" name="menu_slug" id="menu_slug" class="form-control rounded-4" placeholder="auto generated if empty">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Page URL / File Name</label>
                        <input type="text" name="menu_url" id="menu_url" class="form-control rounded-4" required placeholder="projects or projects.php">
                        <small class="text-muted-custom fw-semibold">
                            You can enter projects, projects.php, sites, sites.php, or reports/projects. .php will be added automatically.
                        </small>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-bold small">Lucide Icon</label>
                            <input type="text" name="icon" id="icon" class="form-control rounded-4" value="circle">
                        </div>

                        <div class="col-6">
                            <label class="form-label fw-bold small">Sort Order</label>
                            <input type="number" name="sort_order" id="sort_order" class="form-control rounded-4" value="0">
                        </div>

                        <div class="col-6">
                            <label class="form-label fw-bold small">Has Submenu?</label>
                            <select name="has_submenu" id="has_submenu" class="form-select rounded-4">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>

                        <div class="col-6">
                            <label class="form-label fw-bold small">Status</label>
                            <select name="is_active" id="is_active" class="form-select rounded-4">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="create_page" id="create_page" value="1" checked>
                        <label class="form-check-label fw-bold small" for="create_page">
                            Create page file automatically if not exists
                        </label>
                    </div>
                </div>

                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Save Menu</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="deleteMenuModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form action="api/delete-sidebar-menu.php" method="POST" class="modal-content">
                <input type="hidden" name="menu_id" id="delete_menu_id">

                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Delete Sidebar Menu</h5>
                        <p class="text-muted-custom small mb-0">Choose whether only menu data should be deleted or page file also.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="delete-warning-box mb-3">
                        <div class="d-flex gap-2 align-items-start">
                            <i data-lucide="triangle-alert" class="text-danger" style="width:20px;height:20px;"></i>
                            <div>
                                <p class="fw-bold mb-1" id="delete_menu_title">Delete this menu?</p>
                                <p class="text-muted-custom small mb-0">This will remove the menu from sidebar access also. If this is a main menu, its submenus may also be removed by database cascade.</p>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Page File</label>
                        <input type="text" id="delete_menu_url" class="form-control rounded-4" readonly>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="delete_page_file" id="delete_page_file" value="1">
                        <label class="form-check-label fw-bold small" for="delete_page_file">
                            Delete page file also from project folder
                        </label>
                    </div>
                </div>

                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-4 fw-bold px-4">Delete Menu</button>
                </div>
            </form>
        </div>
    </div>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=11"></script>
    <script>
        function toggleParentMenu() {
            const menuKind = document.getElementById("menu_kind").value;
            const parentWrap = document.getElementById("parentMenuWrap");
            const hasSubmenu = document.getElementById("has_submenu");

            if (menuKind === "sub") {
                parentWrap.classList.remove("d-none");
                hasSubmenu.value = "0";
                hasSubmenu.setAttribute("disabled", "disabled");
            } else {
                parentWrap.classList.add("d-none");
                hasSubmenu.removeAttribute("disabled");
            }
        }

        function openAddSidebarMenuModal() {
            document.getElementById("sidebarMenuModalTitle").textContent = "Add Sidebar Menu";
            document.getElementById("menu_id").value = "";
            document.getElementById("menu_kind").value = "main";
            document.getElementById("parent_id").value = "";
            document.getElementById("menu_title").value = "";
            document.getElementById("menu_slug").value = "";
            document.getElementById("menu_url").value = "";
            document.getElementById("icon").value = "circle";
            document.getElementById("sort_order").value = "0";
            document.getElementById("has_submenu").value = "0";
            document.getElementById("is_active").value = "1";
            document.getElementById("create_page").checked = true;
            toggleParentMenu();
        }

        function openEditSidebarMenuModal(menu) {
            document.getElementById("sidebarMenuModalTitle").textContent = "Edit Sidebar Menu";
            document.getElementById("menu_id").value = menu.id || "";
            document.getElementById("menu_kind").value = menu.parent_id ? "sub" : "main";
            document.getElementById("parent_id").value = menu.parent_id || "";
            document.getElementById("menu_title").value = menu.menu_title || "";
            document.getElementById("menu_slug").value = menu.menu_slug || "";
            document.getElementById("menu_url").value = menu.menu_url || "";
            document.getElementById("icon").value = menu.icon || "circle";
            document.getElementById("sort_order").value = menu.sort_order || "0";
            document.getElementById("has_submenu").value = menu.has_submenu || "0";
            document.getElementById("is_active").value = menu.is_active || "1";
            document.getElementById("create_page").checked = false;
            toggleParentMenu();
        }

        function openDeleteSidebarMenuModal(menu) {
            document.getElementById("delete_menu_id").value = menu.id || "";
            document.getElementById("delete_menu_title").textContent = "Delete " + (menu.menu_title || "this menu") + "?";
            document.getElementById("delete_menu_url").value = menu.menu_url || "";
            document.getElementById("delete_page_file").checked = false;
        }

        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
