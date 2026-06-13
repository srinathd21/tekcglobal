<?php
require_once __DIR__ . "/includes/db.php";

require_permission($conn, "can_view", "project-assignments.php");

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function money_indian_assignment($amount)
{
    return "₹" . number_format((float)$amount, 2);
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Project assignment saved successfully.";
} elseif (isset($_GET["replacement"])) {
    $pageMessageType = "success";
    $pageMessageText = "Temporary replacement scheduled successfully.";
} elseif (isset($_GET["cancelled"])) {
    $pageMessageType = "success";
    $pageMessageText = "Replacement cancelled successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$canAssignCreate = can_create($conn, "project-assignments.php");
$canAssignEdit = can_edit($conn, "project-assignments.php");
$canAssignDelete = can_delete($conn, "project-assignments.php");

$search = trim($_GET["search"] ?? "");
$projectFilter = isset($_GET["project_id"]) ? (int)$_GET["project_id"] : 0;
$roleFilter = isset($_GET["assignment_role_id"]) ? (int)$_GET["assignment_role_id"] : 0;
$statusFilter = trim($_GET["status"] ?? "active");

$where = ["p.deleted_at IS NULL"];
$params = [];
$types = "";

if ($statusFilter !== "") {
    $where[] = "pa.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($search !== "") {
    $where[] = "(p.project_name LIKE ? OR p.project_code LIKE ? OR e.full_name LIKE ? OR e.employee_code LIKE ? OR par.role_name LIKE ?)";
    $like = "%" . $search . "%";
    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
    }
    $types .= "sssss";
}

if ($projectFilter > 0) {
    $where[] = "pa.project_id = ?";
    $params[] = $projectFilter;
    $types .= "i";
}

if ($roleFilter > 0) {
    $where[] = "pa.assignment_role_id = ?";
    $params[] = $roleFilter;
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
    FROM project_assignments pa
    INNER JOIN projects p ON p.id = pa.project_id
    INNER JOIN employees e ON e.id = pa.employee_id
    INNER JOIN project_assignment_roles par ON par.id = pa.assignment_role_id
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
        pa.*,
        p.project_name,
        p.project_code,
        p.contract_value,
        p.project_location,
        c.client_name,
        e.full_name AS employee_name,
        e.employee_code,
        e.mobile_number,
        e.email,
        par.role_name AS assignment_role_name,
        par.role_key,
        creator.name AS assigned_by_name
    FROM project_assignments pa
    INNER JOIN projects p ON p.id = pa.project_id
    LEFT JOIN clients c ON c.id = p.client_id
    INNER JOIN employees e ON e.id = pa.employee_id
    INNER JOIN project_assignment_roles par ON par.id = pa.assignment_role_id
    LEFT JOIN users creator ON creator.id = pa.assigned_by
    WHERE $whereSql
    ORDER BY p.project_name ASC, par.sort_order ASC, pa.is_primary DESC, e.full_name ASC
    LIMIT ? OFFSET ?
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $listTypes, ...$listParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$assignments = [];
while ($row = mysqli_fetch_assoc($result)) {
    $assignments[] = $row;
}
mysqli_stmt_close($stmt);

function page_url($pageNumber)
{
    $query = $_GET;
    $query["page"] = $pageNumber;
    return "project-assignments.php?" . http_build_query($query);
}

function per_page_url($perPage)
{
    $query = $_GET;
    $query["per_page"] = $perPage;
    $query["page"] = 1;
    return "project-assignments.php?" . http_build_query($query);
}

$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalRows);

$projectsQ = mysqli_query($conn, "
    SELECT id, project_name, project_code
    FROM projects
    WHERE deleted_at IS NULL
    ORDER BY project_name ASC
");

$rolesQ = mysqli_query($conn, "
    SELECT id, role_name, role_key
    FROM project_assignment_roles
    WHERE is_active = 1
    ORDER BY sort_order ASC, role_name ASC
");

$employeesQ = mysqli_query($conn, "
    SELECT e.id, e.full_name, e.employee_code, r.role_name
    FROM employees e
    LEFT JOIN roles r ON r.id = e.role_id
    WHERE e.employee_status = 'active'
    ORDER BY e.full_name ASC
");

$replacementEmployeesQ = mysqli_query($conn, "
    SELECT e.id, e.full_name, e.employee_code, r.role_name
    FROM employees e
    LEFT JOIN roles r ON r.id = e.role_id
    WHERE e.employee_status = 'active'
    ORDER BY e.full_name ASC
");

$stats = [
    "active" => 0,
    "projects" => 0,
    "employees" => 0,
    "replacements" => 0
];

$stats["active"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM project_assignments WHERE status = 'active'"))["total"] ?? 0);
$stats["projects"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT project_id) AS total FROM project_assignments WHERE status = 'active'"))["total"] ?? 0);
$stats["employees"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT employee_id) AS total FROM project_assignments WHERE status = 'active'"))["total"] ?? 0);
$stats["replacements"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM project_assignment_replacements WHERE status IN ('scheduled','active')"))["total"] ?? 0);

$replacementsQ = mysqli_query($conn, "
    SELECT
        pr.*,
        p.project_name,
        p.project_code,
        original.full_name AS original_employee_name,
        replacement.full_name AS replacement_employee_name,
        par.role_name AS assignment_role_name
    FROM project_assignment_replacements pr
    INNER JOIN projects p ON p.id = pr.project_id
    INNER JOIN employees original ON original.id = pr.original_employee_id
    INNER JOIN employees replacement ON replacement.id = pr.replacement_employee_id
    INNER JOIN project_assignment_roles par ON par.id = pr.assignment_role_id
    WHERE p.deleted_at IS NULL
    ORDER BY pr.id DESC
    LIMIT 8
");
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Project Assignments - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>

    <style>
        .page-head-card {
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

        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 14px;
        }

        .employee-avatar {
            width: 42px;
            height: 42px;
            min-width: 42px;
            border-radius: 16px;
            background: rgba(148, 163, 184, .14);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            font-weight: 900;
        }

        .project-code-pill {
            border: 1px solid var(--border-soft);
            background: rgba(148, 163, 184, .10);
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
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

        .pagination-wrap {
            border-top: 1px solid var(--border-soft);
            padding: 14px 16px;
            background: rgba(148, 163, 184, .04);
        }

        .page-link-custom {
            min-width: 36px;
            height: 36px;
            border-radius: 13px;
            border: 1px solid var(--border-soft);
            background: var(--card-bg);
            color: var(--text-main);
            font-size: 12px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            padding: 0 10px;
        }

        .page-link-custom:hover {
            color: var(--text-main);
            background: rgba(148, 163, 184, .10);
        }

        .page-link-custom.active {
            color: #fff;
            background-image: linear-gradient(135deg, var(--brand-1), var(--brand-2));
            border-color: transparent;
        }

        .page-link-custom.disabled {
            opacity: .45;
            pointer-events: none;
        }

        .per-page-select {
            width: 88px;
            min-height: 36px;
            border-radius: 13px;
            font-size: 12px;
            font-weight: 800;
        }

        .replacement-card {
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 13px;
            background: rgba(148, 163, 184, .05);
        }

        .replacement-arrow {
            width: 30px;
            height: 30px;
            border-radius: 12px;
            background: rgba(37, 99, 235, .12);
            color: #2563eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
                            <h1 class="h4 fw-bold mb-1">Project Assignments</h1>
                            <p class="text-muted-custom mb-0 small">Manage project managers, team leads, engineers and temporary leave replacements.</p>
                        </div>

                        <?php if ($canAssignCreate): ?>
                            <button type="button" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3"
                                data-bs-toggle="modal" data-bs-target="#assignmentModal" onclick="openAddAssignmentModal()">
                                <i data-lucide="user-plus" style="width:15px;height:15px;"></i> Add Assignment
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-3 mb-3 kpi-row">
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                                <i data-lucide="user-check" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Active Assignments <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["active"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["active"] ?></span> active roles</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-success-subtle text-success">
                                <i data-lucide="folder-kanban" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Assigned Projects <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["projects"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["projects"] ?></span> projects covered</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                                <i data-lucide="users" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Assigned Employees <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["employees"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["employees"] ?></span> unique employees</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-warning-subtle text-warning">
                                <i data-lucide="repeat-2" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Replacements <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["replacements"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["replacements"] ?></span> scheduled/active</p>
                            </div>
                        </article>
                    </div>
                </div>

                <form method="GET" class="filter-card mb-3">
                    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-3">
                            <label class="form-label fw-bold small">Search</label>
                            <input type="text" name="search" class="form-control rounded-4" value="<?= e($search) ?>" placeholder="Project, employee, role">
                        </div>

                        <div class="col-12 col-sm-6 col-lg-3">
                            <label class="form-label fw-bold small">Project</label>
                            <select name="project_id" class="form-select rounded-4">
                                <option value="0">All Projects</option>
                                <?php while ($project = mysqli_fetch_assoc($projectsQ)): ?>
                                    <option value="<?= (int)$project["id"] ?>" <?= $projectFilter === (int)$project["id"] ? "selected" : "" ?>>
                                        <?= e($project["project_name"]) ?> <?= $project["project_code"] ? "(" . e($project["project_code"]) . ")" : "" ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-2">
                            <label class="form-label fw-bold small">Role</label>
                            <select name="assignment_role_id" class="form-select rounded-4">
                                <option value="0">All Roles</option>
                                <?php
                                mysqli_data_seek($rolesQ, 0);
                                while ($role = mysqli_fetch_assoc($rolesQ)):
                                ?>
                                    <option value="<?= (int)$role["id"] ?>" <?= $roleFilter === (int)$role["id"] ? "selected" : "" ?>>
                                        <?= e($role["role_name"]) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-2">
                            <label class="form-label fw-bold small">Status</label>
                            <select name="status" class="form-select rounded-4">
                                <option value="">All</option>
                                <option value="active" <?= $statusFilter === "active" ? "selected" : "" ?>>Active</option>
                                <option value="inactive" <?= $statusFilter === "inactive" ? "selected" : "" ?>>Inactive</option>
                                <option value="completed" <?= $statusFilter === "completed" ? "selected" : "" ?>>Completed</option>
                            </select>
                        </div>

                        <div class="col-12 col-lg-2 d-flex gap-2">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button>
                            <a href="project-assignments.php" class="btn btn-outline-secondary rounded-4 fw-bold">Reset</a>
                        </div>
                    </div>
                </form>

                <div class="row g-3">
                    <div class="col-12 col-xl-8">
                        <section class="card-ui overflow-hidden h-100">
                            <div class="p-3 p-lg-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-3">
                                <div>
                                    <h2 class="fw-bold fs-6 mb-1">Current Assignments</h2>
                                    <p class="text-muted-custom small mb-0">Control active project staff and role ownership.</p>
                                </div>
                            </div>

                            <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                                <table class="project-table w-100">
                                    <thead>
                                        <tr>
                                            <th>Project</th>
                                            <th>Employee</th>
                                            <th>Role</th>
                                            <th>Primary</th>
                                            <th>Assigned From</th>
                                            <th>Status</th>
                                            <th style="width:230px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assignments as $assignment): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold"><?= e($assignment["project_name"]) ?></div>
                                                    <small class="project-code-pill"><?= e($assignment["project_code"] ?: "PRJ-" . $assignment["project_id"]) ?></small>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="employee-avatar"><?= e(strtoupper(substr($assignment["employee_name"], 0, 1))) ?></div>
                                                        <div>
                                                            <div class="fw-bold"><?= e($assignment["employee_name"]) ?></div>
                                                            <small class="text-muted-custom"><?= e($assignment["employee_code"]) ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?= e($assignment["assignment_role_name"]) ?></td>
                                                <td><?= (int)$assignment["is_primary"] === 1 ? '<span class="pill green">Primary</span>' : '<span class="pill amber">Support</span>' ?></td>
                                                <td><?= e($assignment["assigned_from"] ?: "-") ?></td>
                                                <td><span class="pill <?= $assignment["status"] === "active" ? "green" : "amber" ?>"><?= e(ucfirst($assignment["status"])) ?></span></td>
                                                <td>
                                                    <?php if ($canAssignEdit): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                                            data-bs-toggle="modal" data-bs-target="#assignmentModal"
                                                            onclick='openEditAssignmentModal(<?= json_encode($assignment, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>

                                                        <button type="button" class="btn btn-sm btn-outline-warning rounded-4 fw-bold"
                                                            data-bs-toggle="modal" data-bs-target="#replacementModal"
                                                            onclick='openReplacementModal(<?= json_encode($assignment, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Replace</button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <?php if (empty($assignments)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted-custom py-4">No assignments found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3">
                                <?php foreach ($assignments as $assignment): ?>
                                    <article class="mobile-project-card">
                                        <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                            <div>
                                                <p class="fw-bold small mb-1"><?= e($assignment["project_name"]) ?></p>
                                                <small class="text-muted-custom"><?= e($assignment["assignment_role_name"]) ?></small>
                                            </div>
                                            <span class="pill <?= $assignment["status"] === "active" ? "green" : "amber" ?>"><?= e(ucfirst($assignment["status"])) ?></span>
                                        </div>

                                        <div class="mobile-info"><span>Employee</span><span><?= e($assignment["employee_name"]) ?></span></div>
                                        <div class="mobile-info"><span>Code</span><span><?= e($assignment["employee_code"]) ?></span></div>
                                        <div class="mobile-info"><span>From</span><span><?= e($assignment["assigned_from"] ?: "-") ?></span></div>

                                        <?php if ($canAssignEdit): ?>
                                            <div class="mt-3 d-flex flex-wrap gap-2">
                                                <button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                                    data-bs-toggle="modal" data-bs-target="#assignmentModal"
                                                    onclick='openEditAssignmentModal(<?= json_encode($assignment, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>

                                                <button type="button" class="btn btn-sm btn-outline-warning rounded-4 fw-bold"
                                                    data-bs-toggle="modal" data-bs-target="#replacementModal"
                                                    onclick='openReplacementModal(<?= json_encode($assignment, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Replace</button>
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <div class="pagination-wrap">
                                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="text-muted-custom small fw-bold">
                                            Showing <?= (int)$fromRow ?> to <?= (int)$toRow ?> of <?= (int)$totalRows ?> assignments
                                        </span>

                                        <select class="form-select per-page-select" onchange="window.location.href=this.value">
                                            <?php foreach ([10, 25, 50, 100] as $limit): ?>
                                                <option value="<?= e(per_page_url($limit)) ?>" <?= $perPage === $limit ? "selected" : "" ?>>
                                                    <?= (int)$limit ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <a class="page-link-custom <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(page_url(1)) ?>">First</a>
                                        <a class="page-link-custom <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(page_url(max(1, $page - 1))) ?>">
                                            <i data-lucide="chevron-left" style="width:15px;height:15px;"></i>
                                        </a>

                                        <?php
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $page + 2);
                                        for ($i = $startPage; $i <= $endPage; $i++):
                                        ?>
                                            <a class="page-link-custom <?= $i === $page ? 'active' : '' ?>" href="<?= e(page_url($i)) ?>">
                                                <?= (int)$i ?>
                                            </a>
                                        <?php endfor; ?>

                                        <a class="page-link-custom <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(page_url(min($totalPages, $page + 1))) ?>">
                                            <i data-lucide="chevron-right" style="width:15px;height:15px;"></i>
                                        </a>
                                        <a class="page-link-custom <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(page_url($totalPages)) ?>">Last</a>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="col-12 col-xl-4">
                        <section class="card-ui p-3 p-lg-4 h-100">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                                <div>
                                    <h2 class="fw-bold fs-6 mb-1">Recent Replacements</h2>
                                    <p class="text-muted-custom small mb-0">Temporary project staff changes.</p>
                                </div>
                            </div>

                            <div class="d-grid gap-3">
                                <?php if ($replacementsQ && mysqli_num_rows($replacementsQ) > 0): ?>
                                    <?php while ($replacement = mysqli_fetch_assoc($replacementsQ)): ?>
                                        <article class="replacement-card">
                                            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                                                <div>
                                                    <p class="fw-bold small mb-1"><?= e($replacement["project_name"]) ?></p>
                                                    <small class="text-muted-custom"><?= e($replacement["assignment_role_name"]) ?></small>
                                                </div>
                                                <span class="pill <?= in_array($replacement["status"], ["active", "scheduled"], true) ? "green" : "amber" ?>">
                                                    <?= e(ucfirst($replacement["status"])) ?>
                                                </span>
                                            </div>

                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <span class="fw-bold small"><?= e($replacement["original_employee_name"]) ?></span>
                                                <span class="replacement-arrow"><i data-lucide="arrow-right" style="width:14px;height:14px;"></i></span>
                                                <span class="fw-bold small"><?= e($replacement["replacement_employee_name"]) ?></span>
                                            </div>

                                            <p class="text-muted-custom small mb-2">
                                                <?= e($replacement["replacement_from"]) ?> to <?= e($replacement["replacement_to"]) ?>
                                            </p>

                                            <?php if ($canAssignEdit && in_array($replacement["status"], ["scheduled", "active"], true)): ?>
                                                <form action="api/cancel-project-replacement.php" method="POST" class="m-0">
                                                    <input type="hidden" name="replacement_id" value="<?= (int)$replacement["id"] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-4 fw-bold w-100"
                                                        onclick="return confirm('Cancel this replacement?')">Cancel</button>
                                                </form>
                                            <?php endif; ?>
                                        </article>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="text-center text-muted-custom py-4">
                                        <i data-lucide="repeat-2" style="width:32px;height:32px;"></i>
                                        <p class="fw-bold small mb-0 mt-2">No replacements found.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>
                </div>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include("includes/rightsidbar.php"); ?>
    </div>

    <?php if ($canAssignCreate || $canAssignEdit): ?>
        <div class="modal fade" id="assignmentModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <form action="api/process-project-assignment.php" method="POST" class="modal-content">
                    <input type="hidden" name="assignment_id" id="assignment_id">

                    <div class="modal-header px-4">
                        <div>
                            <h5 class="modal-title fw-bold" id="assignmentModalTitle">Add Assignment</h5>
                            <p class="text-muted-custom small mb-0">Assign or update employee role on a project.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body px-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Project <span class="text-danger">*</span></label>
                                <select name="project_id" id="modal_project_id" class="form-select rounded-4" required>
                                    <option value="">Select Project</option>
                                    <?php
                                    $modalProjectsQ = mysqli_query($conn, "SELECT id, project_name, project_code FROM projects WHERE deleted_at IS NULL ORDER BY project_name ASC");
                                    while ($project = mysqli_fetch_assoc($modalProjectsQ)):
                                    ?>
                                        <option value="<?= (int)$project["id"] ?>">
                                            <?= e($project["project_name"]) ?> <?= $project["project_code"] ? "(" . e($project["project_code"]) . ")" : "" ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Employee <span class="text-danger">*</span></label>
                                <select name="employee_id" id="modal_employee_id" class="form-select rounded-4" required>
                                    <option value="">Select Employee</option>
                                    <?php
                                    mysqli_data_seek($employeesQ, 0);
                                    while ($employee = mysqli_fetch_assoc($employeesQ)):
                                    ?>
                                        <option value="<?= (int)$employee["id"] ?>">
                                            <?= e($employee["full_name"] . " (" . $employee["employee_code"] . ")") ?><?= $employee["role_name"] ? " - " . e($employee["role_name"]) : "" ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Assignment Role <span class="text-danger">*</span></label>
                                <select name="assignment_role_id" id="modal_assignment_role_id" class="form-select rounded-4" required>
                                    <option value="">Select Role</option>
                                    <?php
                                    mysqli_data_seek($rolesQ, 0);
                                    while ($role = mysqli_fetch_assoc($rolesQ)):
                                    ?>
                                        <option value="<?= (int)$role["id"] ?>"><?= e($role["role_name"]) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Assigned From</label>
                                <input type="date" name="assigned_from" id="modal_assigned_from" class="form-control rounded-4">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Assigned To</label>
                                <input type="date" name="assigned_to" id="modal_assigned_to" class="form-control rounded-4">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Status</label>
                                <select name="status" id="modal_status" class="form-select rounded-4">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>

                            <div class="col-md-6 d-flex align-items-end">
                                <label class="scope-pill">
                                    <input type="checkbox" name="is_primary" id="modal_is_primary" value="1" class="form-check-input">
                                    <span>Mark as primary for this role</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4" id="assignmentSubmitBtn">Save Assignment</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="replacementModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <form action="api/process-project-replacement.php" method="POST" class="modal-content">
                    <input type="hidden" name="original_assignment_id" id="replacement_original_assignment_id">
                    <input type="hidden" name="project_id" id="replacement_project_id">
                    <input type="hidden" name="original_employee_id" id="replacement_original_employee_id">
                    <input type="hidden" name="assignment_role_id" id="replacement_assignment_role_id">

                    <div class="modal-header px-4">
                        <div>
                            <h5 class="modal-title fw-bold">Temporary Replacement</h5>
                            <p class="text-muted-custom small mb-0">Replace an assigned employee temporarily during leave or absence.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body px-4">
                        <div class="replacement-card mb-3">
                            <p class="fw-bold small mb-1" id="replacement_project_title">Project</p>
                            <p class="text-muted-custom small mb-0" id="replacement_employee_text">Original employee</p>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Replacement Employee <span class="text-danger">*</span></label>
                                <select name="replacement_employee_id" id="replacement_employee_id" class="form-select rounded-4" required>
                                    <option value="">Select Replacement</option>
                                    <?php while ($employee = mysqli_fetch_assoc($replacementEmployeesQ)): ?>
                                        <option value="<?= (int)$employee["id"] ?>">
                                            <?= e($employee["full_name"] . " (" . $employee["employee_code"] . ")") ?><?= $employee["role_name"] ? " - " . e($employee["role_name"]) : "" ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">From <span class="text-danger">*</span></label>
                                <input type="date" name="replacement_from" class="form-control rounded-4" required>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">To <span class="text-danger">*</span></label>
                                <input type="date" name="replacement_to" class="form-control rounded-4" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold small">Reason</label>
                                <textarea name="reason" rows="3" class="form-control rounded-4" placeholder="Leave / emergency / temporary work allocation"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Schedule Replacement</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=31"></script>
    <script>
        function openAddAssignmentModal() {
            document.getElementById("assignmentModalTitle").textContent = "Add Assignment";
            document.getElementById("assignmentSubmitBtn").textContent = "Save Assignment";
            document.getElementById("assignment_id").value = "";
            document.getElementById("modal_project_id").value = "";
            document.getElementById("modal_employee_id").value = "";
            document.getElementById("modal_assignment_role_id").value = "";
            document.getElementById("modal_assigned_from").value = new Date().toISOString().slice(0, 10);
            document.getElementById("modal_assigned_to").value = "";
            document.getElementById("modal_status").value = "active";
            document.getElementById("modal_is_primary").checked = false;
        }

        function openEditAssignmentModal(row) {
            document.getElementById("assignmentModalTitle").textContent = "Edit Assignment";
            document.getElementById("assignmentSubmitBtn").textContent = "Update Assignment";
            document.getElementById("assignment_id").value = row.id || "";
            document.getElementById("modal_project_id").value = row.project_id || "";
            document.getElementById("modal_employee_id").value = row.employee_id || "";
            document.getElementById("modal_assignment_role_id").value = row.assignment_role_id || "";
            document.getElementById("modal_assigned_from").value = row.assigned_from || "";
            document.getElementById("modal_assigned_to").value = row.assigned_to || "";
            document.getElementById("modal_status").value = row.status || "active";
            document.getElementById("modal_is_primary").checked = parseInt(row.is_primary || 0, 10) === 1;
        }

        function openReplacementModal(row) {
            document.getElementById("replacement_original_assignment_id").value = row.id || "";
            document.getElementById("replacement_project_id").value = row.project_id || "";
            document.getElementById("replacement_original_employee_id").value = row.employee_id || "";
            document.getElementById("replacement_assignment_role_id").value = row.assignment_role_id || "";
            document.getElementById("replacement_project_title").textContent = row.project_name || "Project";
            document.getElementById("replacement_employee_text").textContent =
                "Replace " + (row.employee_name || "employee") + " for role: " + (row.assignment_role_name || "-");

            const replacementSelect = document.getElementById("replacement_employee_id");
            if (replacementSelect) {
                Array.from(replacementSelect.options).forEach(function(option) {
                    option.disabled = option.value && parseInt(option.value, 10) === parseInt(row.employee_id || 0, 10);
                });
                replacementSelect.value = "";
            }
        }

        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
