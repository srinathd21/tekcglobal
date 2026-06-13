<?php
require_once __DIR__ . "/includes/db.php";

require_permission($conn, "can_view", "projects.php");

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function project_page_permission($conn, $permissionColumn, $pageUrl = "projects.php")
{
    $allowedColumns = ["can_view", "can_create", "can_edit", "can_delete", "can_approve"];

    if (!in_array($permissionColumn, $allowedColumns, true)) {
        return false;
    }

    if (empty($_SESSION["user_id"])) {
        return false;
    }

    $userId = (int)$_SESSION["user_id"];
    $pageUrl = mysqli_real_escape_string($conn, $pageUrl);

    $sql = "
        SELECT MAX(rsa.$permissionColumn) AS allowed
        FROM user_roles ur
        INNER JOIN role_sidebar_access rsa ON rsa.role_id = ur.role_id
        INNER JOIN sidebar_menus sm ON sm.id = rsa.menu_id
        WHERE ur.user_id = $userId
          AND sm.menu_url = '$pageUrl'
          AND sm.is_active = 1
    ";

    $q = mysqli_query($conn, $sql);

    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return (int)($row["allowed"] ?? 0) === 1;
    }

    return false;
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Project saved successfully.";
} elseif (isset($_GET["deleted"])) {
    $pageMessageType = "success";
    $pageMessageText = "Project deleted successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$canProjectCreate = project_page_permission($conn, "can_create", "projects.php");
$canProjectEdit = project_page_permission($conn, "can_edit", "projects.php");
$canProjectDelete = project_page_permission($conn, "can_delete", "projects.php");

$search = trim($_GET["search"] ?? "");
$typeFilter = isset($_GET["project_type_id"]) ? (int)$_GET["project_type_id"] : 0;
$statusFilter = isset($_GET["project_status_id"]) ? (int)$_GET["project_status_id"] : 0;
$clientFilter = isset($_GET["client_id"]) ? (int)$_GET["client_id"] : 0;
$divisionFilter = isset($_GET["division_id"]) ? (int)$_GET["division_id"] : 0;

$where = ["p.deleted_at IS NULL"];
$params = [];
$types = "";

if ($search !== "") {
    $where[] = "(p.project_name LIKE ? OR p.project_code LIKE ? OR p.project_location LIKE ? OR c.client_name LIKE ? OR c.company_name LIKE ? OR cd.division_name LIKE ?)";
    $like = "%" . $search . "%";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
    }
    $types .= "ssssss";
}

if ($typeFilter > 0) {
    $where[] = "p.project_type_id = ?";
    $params[] = $typeFilter;
    $types .= "i";
}

if ($statusFilter > 0) {
    $where[] = "p.project_status_id = ?";
    $params[] = $statusFilter;
    $types .= "i";
}

if ($clientFilter > 0) {
    $where[] = "p.client_id = ?";
    $params[] = $clientFilter;
    $types .= "i";
}

if ($divisionFilter > 0) {
    $where[] = "p.division_id = ?";
    $params[] = $divisionFilter;
    $types .= "i";
}

$whereSql = implode(" AND ", $where);

$page = isset($_GET["page"]) ? (int)$_GET["page"] : 1;
$page = $page <= 0 ? 1 : $page;

$perPage = isset($_GET["per_page"]) ? (int)$_GET["per_page"] : 10;
$allowedPerPage = [10, 25, 50, 100];

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN master_project_types mpt ON mpt.id = p.project_type_id
    LEFT JOIN master_project_statuses mps ON mps.id = p.project_status_id
    LEFT JOIN company_divisions cd ON cd.id = p.division_id
    WHERE $whereSql
";

$countStmt = mysqli_prepare($conn, $countSql);
if (!empty($params)) {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
}
mysqli_stmt_execute($countStmt);
$countRow = mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt));
$totalRows = (int)($countRow["total"] ?? 0);
mysqli_stmt_close($countStmt);

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listParams = $params;
$listTypes = $types . "ii";
$listParams[] = $perPage;
$listParams[] = $offset;

$sql = "
    SELECT
        p.*,
        c.client_name,
        c.company_name,
        mpt.project_type_name,
        mps.status_name,
        mps.badge_class,
        cd.division_name,
        cd.division_code,
        manager.full_name AS manager_name,
        lead.full_name AS team_lead_name,
        pmc_latest.id AS pms_schedule_id,
        pmc_latest.schedule_name AS pms_schedule_name,
        pmc_latest.overall_start_date AS pms_start_date,
        pmc_latest.overall_end_date AS pms_finish_date,
        pmc_latest.overall_duration_days AS pms_duration_days,
        pmc_latest.schedule_status AS pms_schedule_status
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN master_project_types mpt ON mpt.id = p.project_type_id
    LEFT JOIN master_project_statuses mps ON mps.id = p.project_status_id
    LEFT JOIN company_divisions cd ON cd.id = p.division_id
    LEFT JOIN employees manager ON manager.id = p.manager_employee_id
    LEFT JOIN employees lead ON lead.id = p.team_lead_employee_id
    LEFT JOIN (
        SELECT s1.*
        FROM project_pmc_schedules s1
        INNER JOIN (
            SELECT project_id, MAX(id) AS latest_schedule_id
            FROM project_pmc_schedules
            WHERE schedule_status <> 'cancelled'
            GROUP BY project_id
        ) s2 ON s2.latest_schedule_id = s1.id
    ) pmc_latest ON pmc_latest.project_id = p.id
    WHERE $whereSql
    ORDER BY p.id DESC
    LIMIT ? OFFSET ?
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $listTypes, ...$listParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$projects = [];
while ($row = mysqli_fetch_assoc($result)) {
    $projects[] = $row;
}
mysqli_stmt_close($stmt);

function page_url($pageNumber)
{
    $query = $_GET;
    $query["page"] = $pageNumber;
    return "projects.php?" . http_build_query($query);
}

function per_page_url($perPage)
{
    $query = $_GET;
    $query["per_page"] = $perPage;
    $query["page"] = 1;
    return "projects.php?" . http_build_query($query);
}

$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalRows);

$typesQ = mysqli_query($conn, "SELECT id, project_type_name FROM master_project_types WHERE is_active = 1 ORDER BY project_type_name ASC");
$statusesQ = mysqli_query($conn, "SELECT id, status_name FROM master_project_statuses WHERE is_active = 1 ORDER BY sort_order ASC");
$clientsQ = mysqli_query($conn, "SELECT id, client_name, company_name FROM clients WHERE is_active = 1 ORDER BY client_name ASC");
$divisionsQ = mysqli_query($conn, "SELECT id, division_name, division_code FROM company_divisions WHERE is_active = 1 ORDER BY sort_order ASC, division_name ASC");

$stats = [
    "total" => (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM projects WHERE deleted_at IS NULL"))["total"] ?? 0),
    "active" => 0,
    "planning" => 0,
    "value" => 0,
];

$activeStatsQ = mysqli_query($conn, "
    SELECT LOWER(mps.status_code) AS code, COUNT(*) AS total
    FROM projects p
    LEFT JOIN master_project_statuses mps ON mps.id = p.project_status_id
    WHERE p.deleted_at IS NULL
    GROUP BY mps.status_code
");
while ($s = mysqli_fetch_assoc($activeStatsQ)) {
    if ($s["code"] === "active") {
        $stats["active"] = (int)$s["total"];
    }
    if ($s["code"] === "planning") {
        $stats["planning"] = (int)$s["total"];
    }
}

$stats["value"] = (float)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(contract_value),0) AS total FROM projects WHERE deleted_at IS NULL"))["total"] ?? 0);

function money_indian($amount)
{
    return "₹" . number_format((float)$amount, 2);
}

function project_date_show($date)
{
    return ($date && $date !== "0000-00-00") ? date("d M Y", strtotime($date)) : "-";
}

function pms_finish_date($project)
{
    if (!empty($project["pms_finish_date"])) {
        return $project["pms_finish_date"];
    }

    return $project["expected_completion_date"] ?? null;
}

function pms_timeline_label($project)
{
    return !empty($project["pms_finish_date"]) ? "PMS Finish" : "Target Finish";
}

function pms_timeline_start($project)
{
    if (!empty($project["pms_start_date"])) {
        return $project["pms_start_date"];
    }

    return $project["start_date"] ?? null;
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Projects - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>
    <style>
        .page-head-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
        .kpi-row>[class*="col"]{display:flex}.kpi-card{width:100%;height:100%;min-height:118px;background:var(--card-bg);border:1px solid var(--border-soft);border-radius:24px;box-shadow:var(--shadow-card);padding:22px 24px;display:flex;align-items:center;gap:22px}.kpi-icon{width:58px;height:58px;min-width:58px;border-radius:22px;display:inline-flex;align-items:center;justify-content:center}.kpi-label{color:var(--text-muted);font-size:13px;font-weight:800;display:flex;align-items:center;gap:4px;white-space:nowrap}.kpi-value{color:var(--text-main);font-size:24px;font-weight:900;margin:4px 0 2px;line-height:1.15}.kpi-sub{color:var(--text-muted);font-size:12px;font-weight:600;margin:0}.kpi-sub span{color:#008a5b;font-weight:900}
        .filter-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:14px;overflow:hidden}
        .project-code-pill{border:1px solid var(--border-soft);background:rgba(148,163,184,.10);border-radius:999px;padding:5px 10px;font-size:12px;font-weight:900}
        .pagination-wrap{border-top:1px solid var(--border-soft);padding:14px 16px;background:rgba(148,163,184,.04)}.page-link-custom{min-width:36px;height:36px;border-radius:13px;border:1px solid var(--border-soft);background:var(--card-bg);color:var(--text-main);font-size:12px;font-weight:900;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;padding:0 10px}.page-link-custom:hover{color:var(--text-main);background:rgba(148,163,184,.10)}.page-link-custom.active{color:#fff;background-image:linear-gradient(135deg,var(--brand-1),var(--brand-2));border-color:transparent}.page-link-custom.disabled{opacity:.45;pointer-events:none}.per-page-select{width:88px;min-height:36px;border-radius:13px;font-size:12px;font-weight:800}
        .delete-warning-box{border:1px solid rgba(239,68,68,.30);background:rgba(239,68,68,.08);border-radius:18px;padding:14px}.modal-content{background:var(--card-bg);color:var(--text-main);border:1px solid var(--border-soft);border-radius:24px;box-shadow:var(--shadow-card)}.modal-header,.modal-footer{border-color:var(--border-soft)}

        .project-code-pill,
        .pms-finish-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            max-width: 100%;
            border-radius: 999px;
            padding: 5px 12px;
            font-size: 12px;
            line-height: 1;
            font-weight: 900;
            white-space: nowrap;
            vertical-align: middle;
        }

        .project-code-pill {
            color: var(--text-main);
            background: rgba(148, 163, 184, .10);
            border: 1px solid var(--border-soft);
        }

        .pms-finish-pill {
            color: #0f172a;
            background: rgba(148, 163, 184, .12);
            border: 1px solid var(--border-soft);
            margin-top: 4px;
        }

        .timeline-cell {
            min-width: 180px;
        }

        .timeline-date {
            display: block;
            font-weight: 900;
            color: var(--text-main);
            line-height: 1.25;
        }

        .timeline-sub {
            display: block;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 700;
            margin-top: 3px;
            line-height: 1.25;
        }

        .timeline-pms-meta {
            display: block;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            margin-top: 5px;
            line-height: 1.25;
        }


        .filter-card {
            overflow: hidden;
        }

        .project-filter-grid {
            align-items: end;
        }

        .project-filter-grid > [class*="col"] {
            min-width: 0;
        }

        .project-filter-grid .form-label {
            margin-bottom: 6px;
        }

        .project-filter-grid .form-control,
        .project-filter-grid .form-select,
        .project-filter-grid .btn {
            min-height: 40px;
            width: 100%;
        }

        .filter-action-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            align-items: end;
            width: 100%;
        }

        .filter-action-group .btn {
            white-space: nowrap;
            padding-left: 12px;
            padding-right: 12px;
        }

        @media (max-width: 575.98px) {
            .filter-action-group {
                grid-template-columns: 1fr;
            }
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
                            <h1 class="h4 fw-bold mb-1">Projects</h1>
                            <p class="text-muted-custom mb-0 small">Manage project details, contracts, site locations, team assignments and PMS finish dates.</p>
                        </div>
                        <?php if ($canProjectCreate): ?>
                            <a href="project-add.php" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3">
                                <i data-lucide="folder-plus" style="width:15px;height:15px;"></i> Add Project
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-3 mb-3 kpi-row">
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl"><article class="kpi-card"><div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);"><i data-lucide="folder-kanban"></i></div><div><div class="kpi-label">Total Projects <i data-lucide="info" style="width:12px;height:12px;"></i></div><p class="kpi-value"><?= (int)$stats["total"] ?></p><p class="kpi-sub"><span>↑ <?= (int)$stats["total"] ?></span> project records</p></div></article></div>
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl"><article class="kpi-card"><div class="kpi-icon bg-success-subtle text-success"><i data-lucide="activity"></i></div><div><div class="kpi-label">Active Projects <i data-lucide="info" style="width:12px;height:12px;"></i></div><p class="kpi-value"><?= (int)$stats["active"] ?></p><p class="kpi-sub"><span><?= (int)$stats["active"] ?></span> running now</p></div></article></div>
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl"><article class="kpi-card"><div class="kpi-icon bg-warning-subtle text-warning"><i data-lucide="timer"></i></div><div><div class="kpi-label">Planning <i data-lucide="info" style="width:12px;height:12px;"></i></div><p class="kpi-value"><?= (int)$stats["planning"] ?></p><p class="kpi-sub"><span><?= (int)$stats["planning"] ?></span> upcoming</p></div></article></div>
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl"><article class="kpi-card"><div class="kpi-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);"><i data-lucide="indian-rupee"></i></div><div><div class="kpi-label">Contract Value <i data-lucide="info" style="width:12px;height:12px;"></i></div><p class="kpi-value"><?= money_indian($stats["value"]) ?></p><p class="kpi-sub"><span>Total</span> contract amount</p></div></article></div>
                </div>

                <form method="GET" class="filter-card mb-3">
                    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                    <div class="row g-2 project-filter-grid">
                        <div class="col-12 col-sm-6 col-lg-4 col-xl-2">
                            <label class="form-label fw-bold small">Search</label>
                            <input type="text" name="search" class="form-control rounded-4" value="<?= e($search) ?>" placeholder="Project, code, client, location">
                        </div>

                        <div class="col-12 col-sm-6 col-lg-4 col-xl-2">
                            <label class="form-label fw-bold small">Division</label>
                            <select name="division_id" class="form-select rounded-4">
                                <option value="0">All Divisions</option>
                                <?php while ($d = mysqli_fetch_assoc($divisionsQ)): ?>
                                    <option value="<?= (int)$d["id"] ?>" <?= $divisionFilter === (int)$d["id"] ? "selected" : "" ?>>
                                        <?= e($d["division_name"]) ?><?= $d["division_code"] ? " (" . e($d["division_code"]) . ")" : "" ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-4 col-xl-2">
                            <label class="form-label fw-bold small">Client</label>
                            <select name="client_id" class="form-select rounded-4">
                                <option value="0">All Clients</option>
                                <?php while ($c = mysqli_fetch_assoc($clientsQ)): ?>
                                    <option value="<?= (int)$c["id"] ?>" <?= $clientFilter === (int)$c["id"] ? "selected" : "" ?>>
                                        <?= e($c["client_name"]) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-4 col-xl-2">
                            <label class="form-label fw-bold small">Type</label>
                            <select name="project_type_id" class="form-select rounded-4">
                                <option value="0">All Types</option>
                                <?php while ($t = mysqli_fetch_assoc($typesQ)): ?>
                                    <option value="<?= (int)$t["id"] ?>" <?= $typeFilter === (int)$t["id"] ? "selected" : "" ?>>
                                        <?= e($t["project_type_name"]) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-4 col-xl-2">
                            <label class="form-label fw-bold small">Status</label>
                            <select name="project_status_id" class="form-select rounded-4">
                                <option value="0">All Status</option>
                                <?php while ($s = mysqli_fetch_assoc($statusesQ)): ?>
                                    <option value="<?= (int)$s["id"] ?>" <?= $statusFilter === (int)$s["id"] ? "selected" : "" ?>>
                                        <?= e($s["status_name"]) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-4 col-xl-2">
                            <div class="filter-action-group">
                                <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold">Filter</button>
                                <a href="projects.php" class="btn btn-outline-secondary rounded-4 fw-bold">Reset</a>
                            </div>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-3">
                        <div><h2 class="fw-bold fs-6 mb-1">Manage Projects</h2><p class="text-muted-custom small mb-0">Add, view, edit and delete project records. Finish date is decided from the latest PMS schedule when available.</p></div>
                    </div>

                    <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                        <table class="project-table w-100">
                            <thead><tr><th>Project</th><th>Client</th><th>Division</th><th>Type</th><th>Value</th><th>Manager</th><th>Status</th><th>Timeline</th><th style="width:230px; min-width:230px;">Action</th></tr></thead>
                            <tbody>
                                <?php foreach ($projects as $p): ?>
                                    <tr>
                                        <td><div class="fw-bold"><?= e($p["project_name"]) ?></div><small class="project-code-pill"><?= e($p["project_code"] ?: "PRJ-" . $p["id"]) ?></small></td>
                                        <td><div class="fw-bold small"><?= e($p["client_name"]) ?></div><small class="text-muted-custom"><?= e($p["company_name"] ?: "-") ?></small></td>
                                        <td><span class="project-code-pill"><?= e($p["division_name"] ?: "-") ?></span></td>
                                        <td><?= e($p["project_type_name"] ?: "-") ?></td>
                                        <td class="fw-bold"><?= money_indian($p["contract_value"]) ?></td>
                                        <td><?= e($p["manager_name"] ?: "-") ?></td>
                                        <td><span class="pill <?= e($p["badge_class"] ?: "green") ?>"><?= e($p["status_name"] ?: "-") ?></span></td>
                                        <td class="timeline-cell">
                                            <span class="timeline-date"><?= e(project_date_show(pms_timeline_start($p))) ?></span>
                                            <span class="timeline-sub">to <?= e(project_date_show(pms_finish_date($p))) ?></span>
                                            <span class="pms-finish-pill"><?= e(pms_timeline_label($p)) ?></span>
                                            <?php if (!empty($p["pms_schedule_id"])): ?>
                                                <span class="timeline-pms-meta">
                                                    <?= e($p["pms_schedule_name"]) ?><?= !empty($p["pms_duration_days"]) ? " · " . (int)$p["pms_duration_days"] . " days" : "" ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-2">
                                                <a href="project-view.php?id=<?= (int)$p["id"] ?>" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold">View</a>
                                                <?php if ($canProjectEdit): ?><a href="project-edit.php?id=<?= (int)$p["id"] ?>" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">Edit</a><?php endif; ?>
                                                <?php if ($canProjectDelete): ?><button type="button" class="btn btn-sm btn-outline-danger rounded-4 fw-bold" data-bs-toggle="modal" data-bs-target="#deleteProjectModal" onclick='openDeleteProjectModal(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Delete</button><?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($projects)): ?><tr><td colspan="9" class="text-center text-muted-custom py-4">No projects found.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3">
                        <?php foreach ($projects as $p): ?>
                            <article class="mobile-project-card">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-2"><div><p class="fw-bold small mb-1"><?= e($p["project_name"]) ?></p><small class="text-muted-custom"><?= e($p["client_name"]) ?></small></div><span class="pill <?= e($p["badge_class"] ?: "green") ?>"><?= e($p["status_name"] ?: "-") ?></span></div>
                                <div class="mobile-info"><span>Division</span><span><?= e($p["division_name"] ?: "-") ?></span></div>
                                <div class="mobile-info"><span>Value</span><span><?= money_indian($p["contract_value"]) ?></span></div>
                                <div class="mobile-info"><span>Manager</span><span><?= e($p["manager_name"] ?: "-") ?></span></div>
                                <div class="mobile-info"><span>Location</span><span><?= e($p["project_location"]) ?></span></div>
                                <div class="mobile-info"><span><?= e(pms_timeline_label($p)) ?></span><span><?= e(project_date_show(pms_finish_date($p))) ?></span></div>
                                <?php if (!empty($p["pms_schedule_id"])): ?>
                                    <div class="mobile-info"><span>PMS Duration</span><span><?= (int)$p["pms_duration_days"] ?> days</span></div>
                                <?php endif; ?>
                                <div class="mt-3 d-flex flex-wrap gap-2">
                                    <a href="project-view.php?id=<?= (int)$p["id"] ?>" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold">View</a>
                                    <?php if ($canProjectEdit): ?><a href="project-edit.php?id=<?= (int)$p["id"] ?>" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">Edit</a><?php endif; ?>
                                    <?php if ($canProjectDelete): ?><button type="button" class="btn btn-sm btn-outline-danger rounded-4 fw-bold" data-bs-toggle="modal" data-bs-target="#deleteProjectModal" onclick='openDeleteProjectModal(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Delete</button><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="pagination-wrap">
                        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                            <div class="d-flex align-items-center gap-2 flex-wrap"><span class="text-muted-custom small fw-bold">Showing <?= (int)$fromRow ?> to <?= (int)$toRow ?> of <?= (int)$totalRows ?> projects</span><select class="form-select per-page-select" onchange="window.location.href=this.value"><?php foreach ([10,25,50,100] as $limit): ?><option value="<?= e(per_page_url($limit)) ?>" <?= $perPage===$limit?"selected":"" ?>><?= (int)$limit ?></option><?php endforeach; ?></select></div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <a class="page-link-custom <?= $page<=1?'disabled':'' ?>" href="<?= e(page_url(1)) ?>">First</a>
                                <a class="page-link-custom <?= $page<=1?'disabled':'' ?>" href="<?= e(page_url(max(1,$page-1))) ?>"><i data-lucide="chevron-left" style="width:15px;height:15px;"></i></a>
                                <?php $startPage=max(1,$page-2); $endPage=min($totalPages,$page+2); for($i=$startPage;$i<=$endPage;$i++): ?>
                                    <a class="page-link-custom <?= $i===$page?'active':'' ?>" href="<?= e(page_url($i)) ?>"><?= (int)$i ?></a>
                                <?php endfor; ?>
                                <a class="page-link-custom <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(page_url(min($totalPages,$page+1))) ?>"><i data-lucide="chevron-right" style="width:15px;height:15px;"></i></a>
                                <a class="page-link-custom <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(page_url($totalPages)) ?>">Last</a>
                            </div>
                        </div>
                    </div>
                </section>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>
        <div id="settingsOverlay"></div>
        <?php include("includes/rightsidbar.php"); ?>
    </div>

    <?php if ($canProjectDelete): ?>
    <div class="modal fade" id="deleteProjectModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form action="api/delete-project.php" method="POST" class="modal-content">
                <input type="hidden" name="project_id" id="delete_project_id">
                <div class="modal-header px-4"><div><h5 class="modal-title fw-bold">Delete Project</h5><p class="text-muted-custom small mb-0">This will soft-delete the project.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body px-4"><div class="delete-warning-box"><div class="d-flex gap-2 align-items-start"><i data-lucide="triangle-alert" class="text-danger" style="width:20px;height:20px;"></i><div><p class="fw-bold mb-1" id="delete_project_title">Delete this project?</p><p class="text-muted-custom small mb-0">Project assignments will remain in database, but this project will be hidden from active lists.</p></div></div></div></div>
                <div class="modal-footer px-4"><button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger rounded-4 fw-bold px-4">Delete</button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=30"></script>
    <script>
        function openDeleteProjectModal(project) {
            const idInput = document.getElementById("delete_project_id");
            const title = document.getElementById("delete_project_title");
            if (idInput) idInput.value = project.id || "";
            if (title) title.textContent = "Delete " + (project.project_name || "this project") + "?";
        }
        window.addEventListener("load", function(){ if(window.lucide) window.lucide.createIcons(); });
    </script>
</body>
</html>
