<?php
require_once __DIR__ . "/includes/db.php";

$PMS_PERMISSION_PAGE = "pms.php";
$PMS_OLD_PERMISSION_PAGE = "pmc.php";
$PMS_CURRENT_PAGE = basename($_SERVER["PHP_SELF"]);

function pms_logged_employee_id($conn)
{
    if (!empty($_SESSION["employee_id"])) {
        return (int)$_SESSION["employee_id"];
    }

    if (!empty($_SESSION["user_id"])) {
        $userId = (int)$_SESSION["user_id"];
        $q = mysqli_query($conn, "SELECT employee_id FROM users WHERE id = $userId LIMIT 1");
        if ($q && ($row = mysqli_fetch_assoc($q)) && !empty($row["employee_id"])) {
            $_SESSION["employee_id"] = (int)$row["employee_id"];
            return (int)$row["employee_id"];
        }
    }

    return 0;
}

function pms_has_page_permission($conn, $permissionColumn)
{
    global $PMS_PERMISSION_PAGE, $PMS_OLD_PERMISSION_PAGE;

    $allowedColumns = ["can_view", "can_create", "can_edit", "can_delete", "can_approve"];

    if (!in_array($permissionColumn, $allowedColumns, true) || empty($_SESSION["user_id"])) {
        return false;
    }

    $userId = (int)$_SESSION["user_id"];
    $page1 = mysqli_real_escape_string($conn, $PMS_PERMISSION_PAGE);
    $page2 = mysqli_real_escape_string($conn, $PMS_OLD_PERMISSION_PAGE);

    $sql = "
        SELECT MAX(rsa.$permissionColumn) AS allowed
        FROM user_roles ur
        INNER JOIN role_sidebar_access rsa ON rsa.role_id = ur.role_id
        INNER JOIN sidebar_menus sm ON sm.id = rsa.menu_id
        WHERE ur.user_id = $userId
          AND sm.menu_url IN ('$page1', '$page2')
          AND sm.is_active = 1
    ";

    $q = mysqli_query($conn, $sql);

    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return (int)($row["allowed"] ?? 0) === 1;
    }

    return false;
}

function pms_has_assigned_project_view($conn)
{
    $employeeId = pms_logged_employee_id($conn);

    if ($employeeId <= 0) {
        return false;
    }

    $q = mysqli_query($conn, "
        SELECT pa.id
        FROM project_assignments pa
        INNER JOIN projects p ON p.id = pa.project_id
        WHERE pa.employee_id = $employeeId
          AND pa.status = 'active'
          AND p.deleted_at IS NULL
        LIMIT 1
    ");

    return $q && mysqli_num_rows($q) > 0;
}

if (!pms_has_page_permission($conn, "can_view") && !pms_has_assigned_project_view($conn)) {
    http_response_code(403);
    include __DIR__ . "/403.php";
    exit;
}

function pmc_e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function pmc_date_show($date)
{
    return ($date && $date !== "0000-00-00") ? $date : "-";
}

function pmc_status_class($status)
{
    $status = strtolower(trim($status ?? ""));
    if ($status === "completed") return "green";
    if ($status === "ongoing" || $status === "active") return "blue";
    if ($status === "cancelled") return "red";
    return "amber";
}

function pmc_log_activity($conn, $type, $description, $referenceId = null)
{
    $employeeId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? ($_SESSION["name"] ?? "Admin");
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $stmt = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, 'pmc', ?, ?, ?)
    ");

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "issssssis", $employeeId, $employeeName, $username, $designation, $department, $type, $description, $referenceId, $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function pmc_recalculate_schedule($conn, $scheduleId)
{
    $scheduleId = (int)$scheduleId;

    if ($scheduleId <= 0) return;

    mysqli_query($conn, "
        UPDATE project_pmc_schedules s
        SET
            s.overall_start_date = (
                SELECT MIN(i.planned_start_date)
                FROM project_pmc_schedule_items i
                WHERE i.schedule_id = s.id AND i.is_active = 1
            ),
            s.overall_end_date = (
                SELECT MAX(i.planned_end_date)
                FROM project_pmc_schedule_items i
                WHERE i.schedule_id = s.id AND i.is_active = 1
            ),
            s.overall_duration_days = (
                SELECT
                    CASE
                        WHEN MIN(i.planned_start_date) IS NOT NULL
                         AND MAX(i.planned_end_date) IS NOT NULL
                        THEN DATEDIFF(MAX(i.planned_end_date), MIN(i.planned_start_date)) + 1
                        ELSE 0
                    END
                FROM project_pmc_schedule_items i
                WHERE i.schedule_id = s.id AND i.is_active = 1
            )
        WHERE s.id = $scheduleId
    ");
}

function pmc_redirect($params = [])
{
    global $PMS_CURRENT_PAGE;

    $query = http_build_query($params);
    header("Location: " . $PMS_CURRENT_PAGE . ($query ? "?" . $query : ""));
    exit;
}

$canPmcCreate = pms_has_page_permission($conn, "can_create");
$canPmcEdit = pms_has_page_permission($conn, "can_edit");
$canPmcDelete = pms_has_page_permission($conn, "can_delete");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $currentUserId = $_SESSION["user_id"] ?? null;

    try {
        if ($action === "save_schedule") {
            $scheduleId = (int)($_POST["schedule_id"] ?? 0);

            if ($scheduleId > 0) {
                if (!pms_has_page_permission($conn, "can_edit")) { throw new Exception("You do not have permission to edit PMS."); }
            } else {
                if (!pms_has_page_permission($conn, "can_create")) { throw new Exception("You do not have permission to create PMS."); }
            }

            $projectId = (int)($_POST["project_id"] ?? 0);
            $scheduleName = trim($_POST["schedule_name"] ?? "");
            $description = trim($_POST["description"] ?? "");
            $status = trim($_POST["schedule_status"] ?? "draft");

            if ($projectId <= 0 || $scheduleName === "") {
                throw new Exception("Project and schedule name are required.");
            }

            if (!in_array($status, ["draft", "pending", "ongoing", "completed", "cancelled"], true)) {
                $status = "draft";
            }

            if ($scheduleId > 0) {
                $stmt = mysqli_prepare($conn, "
                    UPDATE project_pmc_schedules
                    SET project_id = ?, schedule_name = ?, description = ?, schedule_status = ?, updated_by = ?
                    WHERE id = ?
                ");
                mysqli_stmt_bind_param($stmt, "isssii", $projectId, $scheduleName, $description, $status, $currentUserId, $scheduleId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                pmc_log_activity($conn, "UPDATE", "Updated Project Master Scheduleschedule", $scheduleId);
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO project_pmc_schedules
                    (project_id, schedule_name, description, schedule_status, created_by)
                    VALUES (?, ?, ?, ?, ?)
                ");
                mysqli_stmt_bind_param($stmt, "isssi", $projectId, $scheduleName, $description, $status, $currentUserId);
                mysqli_stmt_execute($stmt);
                $scheduleId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                pmc_log_activity($conn, "CREATE", "Created Project Master Scheduleschedule", $scheduleId);
            }

            pmc_recalculate_schedule($conn, $scheduleId);
            pmc_redirect(["success" => 1]);
        }

        if ($action === "cancel_schedule") {
            if (!pms_has_page_permission($conn, "can_delete")) { throw new Exception("You do not have permission to cancel PMS."); }

            $scheduleId = (int)($_POST["schedule_id"] ?? 0);

            if ($scheduleId <= 0) {
                throw new Exception("Invalid schedule.");
            }

            $stmt = mysqli_prepare($conn, "
                UPDATE project_pmc_schedules
                SET schedule_status = 'cancelled', updated_by = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, "ii", $currentUserId, $scheduleId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            pmc_log_activity($conn, "DELETE", "Cancelled Project Master Scheduleschedule", $scheduleId);
            pmc_redirect(["deleted" => 1]);
        }

        throw new Exception("Invalid action.");
    } catch (Throwable $e) {
        pmc_redirect(["error" => $e->getMessage()]);
    }
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Project Master Schedule saved successfully.";
} elseif (isset($_GET["deleted"])) {
    $pageMessageType = "success";
    $pageMessageText = "PMScancelled successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$search = trim($_GET["search"] ?? "");
$projectFilter = isset($_GET["project_id"]) ? (int)$_GET["project_id"] : 0;
$statusFilter = trim($_GET["schedule_status"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = (int)($_GET["per_page"] ?? 10);
$allowedPerPage = [10, 25, 50, 100];

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}

$where = ["s.schedule_status <> 'cancelled'", "p.deleted_at IS NULL"];

if ($projectFilter > 0) {
    $where[] = "s.project_id = " . (int)$projectFilter;
}

if ($statusFilter !== "" && in_array($statusFilter, ["draft", "pending", "ongoing", "completed"], true)) {
    $where[] = "s.schedule_status = '" . mysqli_real_escape_string($conn, $statusFilter) . "'";
}

if ($search !== "") {
    $like = mysqli_real_escape_string($conn, "%" . $search . "%");
    $where[] = "(s.schedule_name LIKE '$like' OR p.project_name LIKE '$like' OR p.project_code LIKE '$like' OR p.project_location LIKE '$like')";
}

if (!pms_has_page_permission($conn, "can_view")) {
    $loggedEmployeeId = pms_logged_employee_id($conn);
    $where[] = "EXISTS (
        SELECT 1
        FROM project_assignments pa_view
        WHERE pa_view.project_id = s.project_id
          AND pa_view.employee_id = " . (int)$loggedEmployeeId . "
          AND pa_view.status = 'active'
    )";
}

$whereSql = implode(" AND ", $where);

$countQ = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM project_pmc_schedules s
    INNER JOIN projects p ON p.id = s.project_id
    WHERE $whereSql
");
$totalRows = (int)(mysqli_fetch_assoc($countQ)["total"] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$schedulesQ = mysqli_query($conn, "
    SELECT
        s.*,
        p.project_name,
        p.project_code,
        p.project_location,
        COUNT(i.id) AS total_items,
        SUM(CASE WHEN i.item_type = 'topic' THEN 1 ELSE 0 END) AS topic_count,
        SUM(CASE WHEN i.item_type = 'task' THEN 1 ELSE 0 END) AS task_count,
        SUM(CASE WHEN i.item_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN i.item_status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing_count,
        SUM(CASE WHEN i.item_status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
        ROUND(AVG(i.progress_percent), 2) AS avg_progress
    FROM project_pmc_schedules s
    INNER JOIN projects p ON p.id = s.project_id
    LEFT JOIN project_pmc_schedule_items i
        ON i.schedule_id = s.id
       AND i.is_active = 1
    WHERE $whereSql
    GROUP BY s.id
    ORDER BY s.id DESC
    LIMIT $perPage OFFSET $offset
");

$schedules = [];
if ($schedulesQ) {
    while ($row = mysqli_fetch_assoc($schedulesQ)) {
        $schedules[] = $row;
    }
}

$projectDropdownWhere = "deleted_at IS NULL";

if (!pms_has_page_permission($conn, "can_view")) {
    $loggedEmployeeId = pms_logged_employee_id($conn);
    $projectDropdownWhere .= " AND EXISTS (
        SELECT 1
        FROM project_assignments pa_view
        WHERE pa_view.project_id = projects.id
          AND pa_view.employee_id = " . (int)$loggedEmployeeId . "
          AND pa_view.status = 'active'
    )";
}

$projectsQ = mysqli_query($conn, "
    SELECT id, project_name, project_code
    FROM projects
    WHERE $projectDropdownWhere
    ORDER BY project_name ASC
");

$projectsModalQ = mysqli_query($conn, "
    SELECT id, project_name, project_code
    FROM projects
    WHERE $projectDropdownWhere
    ORDER BY project_name ASC
");

$stats = [
    "total" => (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM project_pmc_schedules WHERE schedule_status <> 'cancelled'"))["total"] ?? 0),
    "ongoing" => (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM project_pmc_schedules WHERE schedule_status = 'ongoing'"))["total"] ?? 0),
    "pending" => (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM project_pmc_schedules WHERE schedule_status IN ('draft','pending')"))["total"] ?? 0),
    "completed" => (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM project_pmc_schedules WHERE schedule_status = 'completed'"))["total"] ?? 0)
];

function pmc_page_url($pageNumber)
{
    global $PMS_CURRENT_PAGE;

    $query = $_GET;
    $query["page"] = $pageNumber;
    return $PMS_CURRENT_PAGE . "?" . http_build_query($query);
}

function pmc_per_page_url($perPage)
{
    global $PMS_CURRENT_PAGE;

    $query = $_GET;
    $query["per_page"] = $perPage;
    $query["page"] = 1;
    return $PMS_CURRENT_PAGE . "?" . http_build_query($query);
}

$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalRows);
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>PMS - TEK-C PMS Construction</title>
    <?php include("includes/links.php"); ?>

    <style>
        .page-head-card,
        .filter-card {
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

        .status-pill {
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            text-transform: capitalize;
        }

        .status-pill.green { background: rgba(16, 185, 129, .15); color: #059669; }
        .status-pill.blue { background: rgba(37, 99, 235, .15); color: #2563eb; }
        .status-pill.amber { background: rgba(245, 158, 11, .15); color: #d97706; }
        .status-pill.red { background: rgba(239, 68, 68, .15); color: #dc2626; }

        .progress-mini {
            height: 7px;
            min-width: 90px;
            background: rgba(148, 163, 184, .20);
        }

        .progress-mini .progress-bar {
            background: linear-gradient(135deg, var(--brand-1), var(--brand-2));
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
                            <h1 class="h4 fw-bold mb-1">PMS</h1>
                            <p class="text-muted-custom mb-0 small">
                                Manage project PMS. Open View to add, edit, delete and bulk-create topics/tasks.
                            </p>
                        </div>

                        <?php if ($canPmcCreate): ?>
                            <button type="button"
                                class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3"
                                data-bs-toggle="modal"
                                data-bs-target="#scheduleModal"
                                onclick="openAddScheduleModal()">
                                <i data-lucide="calendar-plus" style="width:15px;height:15px;"></i> Add PMS
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-3 mb-3 kpi-row">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                                <i data-lucide="calendar-days"></i>
                            </div>
                            <div>
                                <div class="kpi-label">PMS <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["total"] ?></p>
                                <p class="kpi-sub">Total active schedules</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-success-subtle text-success">
                                <i data-lucide="activity"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Ongoing <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["ongoing"] ?></p>
                                <p class="kpi-sub">Running schedules</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-warning-subtle text-warning">
                                <i data-lucide="clock-3"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Pending/Draft <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["pending"] ?></p>
                                <p class="kpi-sub">Planning schedules</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                                <i data-lucide="badge-check"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Completed <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["completed"] ?></p>
                                <p class="kpi-sub">Completed schedules</p>
                            </div>
                        </article>
                    </div>
                </div>

                <form method="GET" class="filter-card mb-3">
                    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-4">
                            <label class="form-label fw-bold small">Search</label>
                            <input type="text" name="search" class="form-control rounded-4" value="<?= pmc_e($search) ?>" placeholder="Schedule, project, code, location">
                        </div>

                        <div class="col-12 col-sm-6 col-lg-3">
                            <label class="form-label fw-bold small">Project</label>
                            <select name="project_id" class="form-select rounded-4">
                                <option value="0">All Projects</option>
                                <?php while ($project = mysqli_fetch_assoc($projectsQ)): ?>
                                    <option value="<?= (int)$project["id"] ?>" <?= (int)$projectFilter === (int)$project["id"] ? "selected" : "" ?>>
                                        <?= pmc_e($project["project_name"]) ?> <?= $project["project_code"] ? "(" . pmc_e($project["project_code"]) . ")" : "" ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-3">
                            <label class="form-label fw-bold small">Status</label>
                            <select name="schedule_status" class="form-select rounded-4">
                                <option value="">All Status</option>
                                <option value="draft" <?= $statusFilter === "draft" ? "selected" : "" ?>>Draft</option>
                                <option value="pending" <?= $statusFilter === "pending" ? "selected" : "" ?>>Pending</option>
                                <option value="ongoing" <?= $statusFilter === "ongoing" ? "selected" : "" ?>>Ongoing</option>
                                <option value="completed" <?= $statusFilter === "completed" ? "selected" : "" ?>>Completed</option>
                            </select>
                        </div>

                        <div class="col-12 col-lg-2 d-flex gap-2">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button>
                            <a href="<?= pmc_e($PMS_CURRENT_PAGE) ?>" class="btn btn-outline-secondary rounded-4 fw-bold">Reset</a>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4 d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">PMS List</h2>
                            <p class="text-muted-custom small mb-0">Use View to manage detailed topic hierarchy.</p>
                        </div>
                    </div>

                    <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                        <table class="project-table w-100">
                            <thead>
                                <tr>
                                    <th>Schedule</th>
                                    <th>Project</th>
                                    <th>Dates</th>
                                    <th>Days</th>
                                    <th>Items</th>
                                    <th>Progress</th>
                                    <th>Status</th>
                                    <th style="width:220px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schedules as $schedule): ?>
                                    <?php $scheduleJson = htmlspecialchars(json_encode($schedule, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= pmc_e($schedule["schedule_name"]) ?></div>
                                            <small class="text-muted-custom">#<?= (int)$schedule["id"] ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?= pmc_e($schedule["project_name"]) ?></div>
                                            <small class="text-muted-custom"><?= pmc_e($schedule["project_code"] ?: "-") ?> · <?= pmc_e($schedule["project_location"] ?: "-") ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?= pmc_e(pmc_date_show($schedule["overall_start_date"])) ?></div>
                                            <small class="text-muted-custom">to <?= pmc_e(pmc_date_show($schedule["overall_end_date"])) ?></small>
                                        </td>
                                        <td class="fw-bold"><?= (int)$schedule["overall_duration_days"] ?></td>
                                        <td>
                                            <div class="fw-bold"><?= (int)$schedule["total_items"] ?></div>
                                            <small class="text-muted-custom"><?= (int)$schedule["topic_count"] ?> topics · <?= (int)$schedule["task_count"] ?> tasks</small>
                                        </td>
                                        <td>
                                            <div class="progress progress-mini">
                                                <div class="progress-bar" style="width: <?= (float)($schedule["avg_progress"] ?? 0) ?>%;"></div>
                                            </div>
                                            <small class="text-muted-custom fw-bold"><?= (float)($schedule["avg_progress"] ?? 0) ?>%</small>
                                        </td>
                                        <td>
                                            <span class="status-pill <?= pmc_status_class($schedule["schedule_status"]) ?>">
                                                <?= pmc_e($schedule["schedule_status"]) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <a href="pms-detail.php?schedule_id=<?= (int)$schedule["id"] ?>" class="btn btn-sm btn-outline-success rounded-4 fw-bold">View</a>

                                                <?php if ($canPmcEdit): ?>
                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#scheduleModal"
                                                        onclick='openEditScheduleModal(<?= $scheduleJson ?>)'>Edit</button>
                                                <?php endif; ?>

                                                <?php if ($canPmcDelete): ?>
                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#cancelScheduleModal"
                                                        onclick='openCancelScheduleModal(<?= $scheduleJson ?>)'>Cancel</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($schedules)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted-custom py-4">No PMS found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3">
                        <?php foreach ($schedules as $schedule): ?>
                            <?php $scheduleJson = htmlspecialchars(json_encode($schedule, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>
                            <article class="mobile-project-card">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                    <div>
                                        <p class="fw-bold small mb-1"><?= pmc_e($schedule["schedule_name"]) ?></p>
                                        <small class="text-muted-custom"><?= pmc_e($schedule["project_name"]) ?></small>
                                    </div>
                                    <span class="status-pill <?= pmc_status_class($schedule["schedule_status"]) ?>">
                                        <?= pmc_e($schedule["schedule_status"]) ?>
                                    </span>
                                </div>

                                <div class="mobile-info"><span>Days</span><span><?= (int)$schedule["overall_duration_days"] ?></span></div>
                                <div class="mobile-info"><span>Start</span><span><?= pmc_e(pmc_date_show($schedule["overall_start_date"])) ?></span></div>
                                <div class="mobile-info"><span>Finish</span><span><?= pmc_e(pmc_date_show($schedule["overall_end_date"])) ?></span></div>
                                <div class="mobile-info"><span>Items</span><span><?= (int)$schedule["total_items"] ?></span></div>

                                <div class="mt-3 d-flex flex-wrap gap-2">
                                    <a href="pms-detail.php?schedule_id=<?= (int)$schedule["id"] ?>" class="btn btn-sm btn-outline-success rounded-4 fw-bold">View</a>

                                    <?php if ($canPmcEdit): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                            data-bs-toggle="modal" data-bs-target="#scheduleModal"
                                            onclick='openEditScheduleModal(<?= $scheduleJson ?>)'>Edit</button>
                                    <?php endif; ?>

                                    <?php if ($canPmcDelete): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                                            data-bs-toggle="modal" data-bs-target="#cancelScheduleModal"
                                            onclick='openCancelScheduleModal(<?= $scheduleJson ?>)'>Cancel</button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="pagination-wrap">
                        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="text-muted-custom small fw-bold">
                                    Showing <?= (int)$fromRow ?> to <?= (int)$toRow ?> of <?= (int)$totalRows ?> schedules
                                </span>

                                <select class="form-select per-page-select" onchange="window.location.href=this.value">
                                    <?php foreach ($allowedPerPage as $limit): ?>
                                        <option value="<?= pmc_e(pmc_per_page_url($limit)) ?>" <?= $perPage === $limit ? "selected" : "" ?>>
                                            <?= (int)$limit ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <a class="page-link-custom <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= pmc_e(pmc_page_url(1)) ?>">First</a>
                                <a class="page-link-custom <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= pmc_e(pmc_page_url(max(1, $page - 1))) ?>">
                                    <i data-lucide="chevron-left" style="width:15px;height:15px;"></i>
                                </a>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                for ($i = $startPage; $i <= $endPage; $i++):
                                ?>
                                    <a class="page-link-custom <?= $i === $page ? 'active' : '' ?>" href="<?= pmc_e(pmc_page_url($i)) ?>">
                                        <?= (int)$i ?>
                                    </a>
                                <?php endfor; ?>

                                <a class="page-link-custom <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= pmc_e(pmc_page_url(min($totalPages, $page + 1))) ?>">
                                    <i data-lucide="chevron-right" style="width:15px;height:15px;"></i>
                                </a>
                                <a class="page-link-custom <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= pmc_e(pmc_page_url($totalPages)) ?>">Last</a>
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

    <?php if ($canPmcCreate || $canPmcEdit): ?>
        <div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <form method="POST" class="modal-content">
                    <input type="hidden" name="action" value="save_schedule">
                    <input type="hidden" name="schedule_id" id="schedule_id">

                    <div class="modal-header px-4">
                        <div>
                            <h5 class="modal-title fw-bold" id="scheduleModalTitle">Add PMS Schedule</h5>
                            <p class="text-muted-custom small mb-0">Create or update PMSschedule for a project.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body px-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Project <span class="text-danger">*</span></label>
                                <select name="project_id" id="schedule_project_id" class="form-select rounded-4" required>
                                    <option value="">Select Project</option>
                                    <?php while ($project = mysqli_fetch_assoc($projectsModalQ)): ?>
                                        <option value="<?= (int)$project["id"] ?>">
                                            <?= pmc_e($project["project_name"]) ?> <?= $project["project_code"] ? "(" . pmc_e($project["project_code"]) . ")" : "" ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Schedule Name <span class="text-danger">*</span></label>
                                <input type="text" name="schedule_name" id="schedule_name" class="form-control rounded-4" required placeholder="PMSSchedule">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Schedule Status</label>
                                <select name="schedule_status" id="schedule_status_modal" class="form-select rounded-4">
                                    <option value="draft">Draft</option>
                                    <option value="pending">Pending</option>
                                    <option value="ongoing">Ongoing</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold small">Description</label>
                                <textarea name="description" id="schedule_description" rows="3" class="form-control rounded-4"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4" id="scheduleSubmitBtn">Save Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canPmcDelete): ?>
        <div class="modal fade" id="cancelScheduleModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" class="modal-content">
                    <input type="hidden" name="action" value="cancel_schedule">
                    <input type="hidden" name="schedule_id" id="cancel_schedule_id">

                    <div class="modal-header px-4">
                        <div>
                            <h5 class="modal-title fw-bold">Cancel PMS</h5>
                            <p class="text-muted-custom small mb-0">This marks the PMS as cancelled.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body px-4">
                        <div class="alert alert-danger rounded-4 mb-0">
                            <p class="fw-bold mb-1" id="cancel_schedule_title">Cancel this schedule?</p>
                            <p class="small mb-0">Cancelled schedules will not show in the active list.</p>
                        </div>
                    </div>

                    <div class="modal-footer px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-4 fw-bold px-4">Cancel Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=60"></script>

    <script>
        function openAddScheduleModal() {
            document.getElementById("scheduleModalTitle").textContent = "Add PMS";
            document.getElementById("scheduleSubmitBtn").textContent = "Save Schedule";
            document.getElementById("schedule_id").value = "";
            document.getElementById("schedule_project_id").value = "";
            document.getElementById("schedule_name").value = "";
            document.getElementById("schedule_status_modal").value = "draft";
            document.getElementById("schedule_description").value = "";
        }

        function openEditScheduleModal(schedule) {
            document.getElementById("scheduleModalTitle").textContent = "Edit PMS";
            document.getElementById("scheduleSubmitBtn").textContent = "Update Schedule";
            document.getElementById("schedule_id").value = schedule.id || "";
            document.getElementById("schedule_project_id").value = schedule.project_id || "";
            document.getElementById("schedule_name").value = schedule.schedule_name || "";
            document.getElementById("schedule_status_modal").value = schedule.schedule_status || "draft";
            document.getElementById("schedule_description").value = schedule.description || "";
        }

        function openCancelScheduleModal(schedule) {
            document.getElementById("cancel_schedule_id").value = schedule.id || "";
            document.getElementById("cancel_schedule_title").textContent = "Cancel " + (schedule.schedule_name || "this schedule") + "?";
        }

        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
