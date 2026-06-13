<?php
require_once __DIR__ . "/includes/db.php";

require_permission($conn, "can_view", "temporary-replacements.php");

function tr_e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function tr_redirect($params = [])
{
    $query = http_build_query($params);
    header("Location: temporary-replacements.php" . ($query ? "?" . $query : ""));
    exit;
}

function tr_date_show($date)
{
    return ($date && $date !== "0000-00-00") ? date("d M Y", strtotime($date)) : "-";
}

function tr_status_class($status)
{
    $status = strtolower(trim($status ?? ""));

    if ($status === "active") {
        return "blue";
    }

    if ($status === "completed") {
        return "green";
    }

    if ($status === "cancelled") {
        return "red";
    }

    return "amber";
}

function tr_has_table($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $q && mysqli_num_rows($q) > 0;
}

function tr_has_column($conn, $table, $column)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && mysqli_num_rows($q) > 0;
}

function tr_employee_name_expr($conn, $alias)
{
    $parts = [];

    if (tr_has_column($conn, "employees", "employee_code")) {
        $parts[] = "NULLIF($alias.employee_code, '')";
    }

    if (tr_has_column($conn, "employees", "employee_name")) {
        $parts[] = "NULLIF($alias.employee_name, '')";
    } elseif (tr_has_column($conn, "employees", "full_name")) {
        $parts[] = "NULLIF($alias.full_name, '')";
    } elseif (tr_has_column($conn, "employees", "name")) {
        $parts[] = "NULLIF($alias.name, '')";
    } elseif (tr_has_column($conn, "employees", "first_name") && tr_has_column($conn, "employees", "last_name")) {
        $parts[] = "NULLIF(CONCAT_WS(' ', $alias.first_name, $alias.last_name), '')";
    } elseif (tr_has_column($conn, "employees", "first_name")) {
        $parts[] = "NULLIF($alias.first_name, '')";
    }

    if (empty($parts)) {
        return "CONCAT('Employee #', $alias.id)";
    }

    if (count($parts) === 1) {
        return "COALESCE({$parts[0]}, CONCAT('Employee #', $alias.id))";
    }

    return "CONCAT_WS(' - ', " . implode(", ", $parts) . ")";
}

function tr_employee_secondary_expr($conn, $alias)
{
    $parts = [];

    if (tr_has_column($conn, "employees", "designation")) {
        $parts[] = "NULLIF($alias.designation, '')";
    }

    if (tr_has_column($conn, "employees", "department")) {
        $parts[] = "NULLIF($alias.department, '')";
    }

    if (tr_has_column($conn, "employees", "mobile")) {
        $parts[] = "NULLIF($alias.mobile, '')";
    } elseif (tr_has_column($conn, "employees", "phone")) {
        $parts[] = "NULLIF($alias.phone, '')";
    }

    if (empty($parts)) {
        return "''";
    }

    return "CONCAT_WS(' · ', " . implode(", ", $parts) . ")";
}

function tr_log($conn, $type, $description, $referenceId = null)
{
    if (!tr_has_table($conn, "activity_logs")) {
        return;
    }

    $employeeId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? ($_SESSION["name"] ?? "Admin");
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $stmt = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, 'temporary-replacements', ?, ?, ?)
    ");

    if ($stmt) {
        mysqli_stmt_bind_param(
            $stmt,
            "issssssis",
            $employeeId,
            $employeeName,
            $username,
            $designation,
            $department,
            $type,
            $description,
            $referenceId,
            $ip
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

$missingTables = [];
foreach (["project_assignment_replacements", "project_assignments", "project_assignment_roles", "projects", "employees"] as $requiredTable) {
    if (!tr_has_table($conn, $requiredTable)) {
        $missingTables[] = $requiredTable;
    }
}

$canCreate = can_create($conn, "temporary-replacements.php");
$canEdit = can_edit($conn, "temporary-replacements.php");
$canDelete = can_delete($conn, "temporary-replacements.php");

if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($missingTables)) {
    $action = $_POST["action"] ?? "";
    $currentUserId = $_SESSION["user_id"] ?? null;

    try {
        if ($action === "save_replacement") {
            $replacementId = (int)($_POST["replacement_id"] ?? 0);

            if ($replacementId > 0) {
                require_permission($conn, "can_edit", "temporary-replacements.php");
            } else {
                require_permission($conn, "can_create", "temporary-replacements.php");
            }

            $assignmentId = (int)($_POST["original_assignment_id"] ?? 0);
            $replacementEmployeeId = (int)($_POST["replacement_employee_id"] ?? 0);
            $leaveRequestIdRaw = trim($_POST["leave_request_id"] ?? "");
            $leaveRequestId = $leaveRequestIdRaw === "" ? null : (int)$leaveRequestIdRaw;
            $replacementFrom = trim($_POST["replacement_from"] ?? "");
            $replacementTo = trim($_POST["replacement_to"] ?? "");
            $reason = trim($_POST["reason"] ?? "");
            $status = trim($_POST["status"] ?? "scheduled");

            if ($assignmentId <= 0 || $replacementEmployeeId <= 0 || $replacementFrom === "" || $replacementTo === "") {
                throw new Exception("Assignment, replacement employee, from date and to date are required.");
            }

            if ($replacementTo < $replacementFrom) {
                throw new Exception("Replacement to date cannot be earlier than from date.");
            }

            if (!in_array($status, ["scheduled", "active", "completed", "cancelled"], true)) {
                $status = "scheduled";
            }

            $assignmentQ = mysqli_prepare($conn, "
                SELECT project_id, employee_id, assignment_role_id
                FROM project_assignments
                WHERE id = ?
                LIMIT 1
            ");
            mysqli_stmt_bind_param($assignmentQ, "i", $assignmentId);
            mysqli_stmt_execute($assignmentQ);
            $assignment = mysqli_fetch_assoc(mysqli_stmt_get_result($assignmentQ));
            mysqli_stmt_close($assignmentQ);

            if (!$assignment) {
                throw new Exception("Original project assignment not found.");
            }

            $projectId = (int)$assignment["project_id"];
            $originalEmployeeId = (int)$assignment["employee_id"];
            $assignmentRoleId = (int)$assignment["assignment_role_id"];

            if ($originalEmployeeId === $replacementEmployeeId) {
                throw new Exception("Original employee and replacement employee cannot be same.");
            }

            $employeeCheck = mysqli_prepare($conn, "SELECT id FROM employees WHERE id = ? LIMIT 1");
            mysqli_stmt_bind_param($employeeCheck, "i", $replacementEmployeeId);
            mysqli_stmt_execute($employeeCheck);
            $employeeExists = mysqli_num_rows(mysqli_stmt_get_result($employeeCheck)) > 0;
            mysqli_stmt_close($employeeCheck);

            if (!$employeeExists) {
                throw new Exception("Replacement employee not found.");
            }

            if ($replacementId > 0) {
                $stmt = mysqli_prepare($conn, "
                    UPDATE project_assignment_replacements
                    SET project_id = ?,
                        leave_request_id = ?,
                        original_assignment_id = ?,
                        original_employee_id = ?,
                        replacement_employee_id = ?,
                        assignment_role_id = ?,
                        replacement_from = ?,
                        replacement_to = ?,
                        reason = ?,
                        status = ?
                    WHERE id = ?
                ");
                mysqli_stmt_bind_param(
                    $stmt,
                    "iiiiiissssi",
                    $projectId,
                    $leaveRequestId,
                    $assignmentId,
                    $originalEmployeeId,
                    $replacementEmployeeId,
                    $assignmentRoleId,
                    $replacementFrom,
                    $replacementTo,
                    $reason,
                    $status,
                    $replacementId
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                tr_log($conn, "UPDATE", "Updated temporary project replacement", $replacementId);
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO project_assignment_replacements
                    (project_id, leave_request_id, original_assignment_id, original_employee_id, replacement_employee_id,
                     assignment_role_id, replacement_from, replacement_to, reason, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                mysqli_stmt_bind_param(
                    $stmt,
                    "iiiiiissssi",
                    $projectId,
                    $leaveRequestId,
                    $assignmentId,
                    $originalEmployeeId,
                    $replacementEmployeeId,
                    $assignmentRoleId,
                    $replacementFrom,
                    $replacementTo,
                    $reason,
                    $status,
                    $currentUserId
                );
                mysqli_stmt_execute($stmt);
                $replacementId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                tr_log($conn, "CREATE", "Created temporary project replacement", $replacementId);
            }

            tr_redirect(["success" => 1]);
        }

        if ($action === "cancel_replacement") {
            require_permission($conn, "can_delete", "temporary-replacements.php");

            $replacementId = (int)($_POST["replacement_id"] ?? 0);

            if ($replacementId <= 0) {
                throw new Exception("Invalid replacement.");
            }

            $stmt = mysqli_prepare($conn, "
                UPDATE project_assignment_replacements
                SET status = 'cancelled',
                    cancelled_by = ?,
                    cancelled_at = NOW()
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, "ii", $currentUserId, $replacementId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            tr_log($conn, "DELETE", "Cancelled temporary project replacement", $replacementId);
            tr_redirect(["deleted" => 1]);
        }

        throw new Exception("Invalid action.");
    } catch (Throwable $e) {
        tr_redirect(["error" => $e->getMessage()]);
    }
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Temporary replacement saved successfully.";
} elseif (isset($_GET["deleted"])) {
    $pageMessageType = "success";
    $pageMessageText = "Temporary replacement cancelled successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
} elseif (!empty($missingTables)) {
    $pageMessageType = "error";
    $pageMessageText = "Missing required table(s): " . implode(", ", $missingTables);
}

$search = trim($_GET["search"] ?? "");
$projectFilter = (int)($_GET["project_id"] ?? 0);
$statusFilter = trim($_GET["status"] ?? "");
$dateFrom = trim($_GET["date_from"] ?? "");
$dateTo = trim($_GET["date_to"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = (int)($_GET["per_page"] ?? 10);
$allowedPerPage = [10, 25, 50, 100];

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}

$replacements = [];
$totalRows = 0;
$totalPages = 1;
$offset = 0;
$stats = ["scheduled" => 0, "active" => 0, "completed" => 0, "cancelled" => 0];
$projects = [];
$employees = [];
$assignments = [];
$leaveRequests = [];

$originalEmployeeExpr = tr_employee_name_expr($conn, "oe");
$replacementEmployeeExpr = tr_employee_name_expr($conn, "re");
$assignmentEmployeeExpr = tr_employee_name_expr($conn, "ae");
$employeeExpr = tr_employee_name_expr($conn, "e");
$employeeSecondaryExpr = tr_employee_secondary_expr($conn, "e");
$assignmentEmployeeSecondaryExpr = tr_employee_secondary_expr($conn, "ae");

$roleNameCol = tr_has_column($conn, "project_assignment_roles", "role_name") ? "role_name" : (tr_has_column($conn, "project_assignment_roles", "role_key") ? "role_key" : "id");
$projectNameCol = tr_has_column($conn, "projects", "project_name") ? "project_name" : (tr_has_column($conn, "projects", "name") ? "name" : "id");
$projectCodeCol = tr_has_column($conn, "projects", "project_code") ? "project_code" : "id";

if (empty($missingTables)) {
    $projectsQ = mysqli_query($conn, "
        SELECT id, `$projectNameCol` AS project_name, `$projectCodeCol` AS project_code
        FROM projects
        WHERE " . (tr_has_column($conn, "projects", "deleted_at") ? "deleted_at IS NULL" : "1=1") . "
        ORDER BY `$projectNameCol` ASC
    ");

    if ($projectsQ) {
        while ($row = mysqli_fetch_assoc($projectsQ)) {
            $projects[] = $row;
        }
    }

    $employeeActiveWhere = tr_has_column($conn, "employees", "is_active") ? "WHERE e.is_active = 1" : "";
    $employeesQ = mysqli_query($conn, "
        SELECT e.id, $employeeExpr AS employee_name, $employeeSecondaryExpr AS employee_meta
        FROM employees e
        $employeeActiveWhere
        ORDER BY employee_name ASC
    ");

    if ($employeesQ) {
        while ($row = mysqli_fetch_assoc($employeesQ)) {
            $employees[] = $row;
        }
    }

    $assignmentStatusWhere = tr_has_column($conn, "project_assignments", "status") ? "pa.status = 'active'" : "1=1";
    $projectsDeletedWhere = tr_has_column($conn, "projects", "deleted_at") ? "p.deleted_at IS NULL" : "1=1";

    $assignmentsQ = mysqli_query($conn, "
        SELECT
            pa.id,
            pa.project_id,
            pa.employee_id,
            pa.assignment_role_id,
            p.`$projectNameCol` AS project_name,
            p.`$projectCodeCol` AS project_code,
            ar.`$roleNameCol` AS role_name,
            $assignmentEmployeeExpr AS employee_name,
            $assignmentEmployeeSecondaryExpr AS employee_meta
        FROM project_assignments pa
        INNER JOIN projects p ON p.id = pa.project_id
        INNER JOIN employees ae ON ae.id = pa.employee_id
        INNER JOIN project_assignment_roles ar ON ar.id = pa.assignment_role_id
        WHERE $assignmentStatusWhere
          AND $projectsDeletedWhere
        ORDER BY p.`$projectNameCol` ASC, ar.`$roleNameCol` ASC, employee_name ASC
    ");

    if ($assignmentsQ) {
        while ($row = mysqli_fetch_assoc($assignmentsQ)) {
            $assignments[] = $row;
        }
    }

    if (tr_has_table($conn, "leave_requests")) {
        $leaveTextCol = tr_has_column($conn, "leave_requests", "reason") ? "reason" : "id";
        $leaveRequestsQ = mysqli_query($conn, "
            SELECT id, `$leaveTextCol` AS leave_text
            FROM leave_requests
            ORDER BY id DESC
            LIMIT 100
        ");

        if ($leaveRequestsQ) {
            while ($row = mysqli_fetch_assoc($leaveRequestsQ)) {
                $leaveRequests[] = $row;
            }
        }
    }

    $where = ["1=1"];

    if ($projectFilter > 0) {
        $where[] = "r.project_id = " . (int)$projectFilter;
    }

    if ($statusFilter !== "" && in_array($statusFilter, ["scheduled", "active", "completed", "cancelled"], true)) {
        $where[] = "r.status = '" . mysqli_real_escape_string($conn, $statusFilter) . "'";
    }

    if ($dateFrom !== "") {
        $where[] = "r.replacement_to >= '" . mysqli_real_escape_string($conn, $dateFrom) . "'";
    }

    if ($dateTo !== "") {
        $where[] = "r.replacement_from <= '" . mysqli_real_escape_string($conn, $dateTo) . "'";
    }

    if ($search !== "") {
        $like = mysqli_real_escape_string($conn, "%" . $search . "%");
        $where[] = "(p.`$projectNameCol` LIKE '$like'
                    OR p.`$projectCodeCol` LIKE '$like'
                    OR $originalEmployeeExpr LIKE '$like'
                    OR $replacementEmployeeExpr LIKE '$like'
                    OR ar.`$roleNameCol` LIKE '$like'
                    OR r.reason LIKE '$like')";
    }

    $whereSql = implode(" AND ", $where);

    $countQ = mysqli_query($conn, "
        SELECT COUNT(*) AS total
        FROM project_assignment_replacements r
        INNER JOIN projects p ON p.id = r.project_id
        INNER JOIN employees oe ON oe.id = r.original_employee_id
        INNER JOIN employees re ON re.id = r.replacement_employee_id
        INNER JOIN project_assignment_roles ar ON ar.id = r.assignment_role_id
        WHERE $whereSql
    ");

    if ($countQ) {
        $totalRows = (int)(mysqli_fetch_assoc($countQ)["total"] ?? 0);
    }

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $replacementsQ = mysqli_query($conn, "
        SELECT
            r.*,
            p.`$projectNameCol` AS project_name,
            p.`$projectCodeCol` AS project_code,
            ar.`$roleNameCol` AS assignment_role,
            $originalEmployeeExpr AS original_employee_name,
            $replacementEmployeeExpr AS replacement_employee_name
        FROM project_assignment_replacements r
        INNER JOIN projects p ON p.id = r.project_id
        INNER JOIN employees oe ON oe.id = r.original_employee_id
        INNER JOIN employees re ON re.id = r.replacement_employee_id
        INNER JOIN project_assignment_roles ar ON ar.id = r.assignment_role_id
        WHERE $whereSql
        ORDER BY
            CASE r.status
                WHEN 'active' THEN 1
                WHEN 'scheduled' THEN 2
                WHEN 'completed' THEN 3
                ELSE 4
            END,
            r.replacement_from ASC,
            r.id DESC
        LIMIT $perPage OFFSET $offset
    ");

    if ($replacementsQ) {
        while ($row = mysqli_fetch_assoc($replacementsQ)) {
            $replacements[] = $row;
        }
    }

    foreach (array_keys($stats) as $statusKey) {
        $q = mysqli_query($conn, "SELECT COUNT(*) AS total FROM project_assignment_replacements WHERE status = '$statusKey'");
        $stats[$statusKey] = $q ? (int)(mysqli_fetch_assoc($q)["total"] ?? 0) : 0;
    }
}

function tr_page_url($pageNumber)
{
    $query = $_GET;
    $query["page"] = $pageNumber;
    return "temporary-replacements.php?" . http_build_query($query);
}

function tr_per_page_url($limit)
{
    $query = $_GET;
    $query["per_page"] = $limit;
    $query["page"] = 1;
    return "temporary-replacements.php?" . http_build_query($query);
}

$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalRows);
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Temporary Replacements - TEK-C PMC Construction</title>
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

        .replacement-chain {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 220px;
        }

        .employee-chip {
            border: 1px solid var(--border-soft);
            border-radius: 14px;
            padding: 8px 10px;
            background: rgba(148, 163, 184, .06);
            font-size: 12px;
            font-weight: 800;
        }

        .arrow-chip {
            width: 30px;
            height: 30px;
            min-width: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: rgba(37, 99, 235, .10);
            color: #2563eb;
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

        .delete-warning-box {
            border: 1px solid rgba(239, 68, 68, .30);
            background: rgba(239, 68, 68, .08);
            border-radius: 18px;
            padding: 14px;
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

        .mobile-replacement-card {
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 13px;
            background: var(--card-bg);
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
                            <h1 class="h4 fw-bold mb-1">Temporary Replacements</h1>
                            <p class="text-muted-custom mb-0 small">
                                Manage temporary employee replacements for project assignments during leave or absence.
                            </p>
                        </div>

                        <?php if ($canCreate && empty($missingTables)): ?>
                            <button type="button"
                                class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3"
                                data-bs-toggle="modal"
                                data-bs-target="#replacementModal"
                                onclick="openAddReplacementModal()">
                                <i data-lucide="user-round-plus" style="width:15px;height:15px;"></i> Add Replacement
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-3 mb-3 kpi-row">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-warning-subtle text-warning">
                                <i data-lucide="calendar-clock"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Scheduled <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["scheduled"] ?></p>
                                <p class="kpi-sub">Upcoming replacements</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                                <i data-lucide="users-round"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Active <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["active"] ?></p>
                                <p class="kpi-sub">Currently replacing</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-success-subtle text-success">
                                <i data-lucide="badge-check"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Completed <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["completed"] ?></p>
                                <p class="kpi-sub">Finished replacements</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-danger-subtle text-danger">
                                <i data-lucide="x-circle"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Cancelled <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["cancelled"] ?></p>
                                <p class="kpi-sub">Cancelled records</p>
                            </div>
                        </article>
                    </div>
                </div>

                <form method="GET" class="filter-card mb-3">
                    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-3">
                            <label class="form-label fw-bold small">Search</label>
                            <input type="text" name="search" class="form-control rounded-4" value="<?= tr_e($search) ?>" placeholder="Project, employee, role, reason">
                        </div>

                        <div class="col-12 col-sm-6 col-lg-2">
                            <label class="form-label fw-bold small">Project</label>
                            <select name="project_id" class="form-select rounded-4">
                                <option value="0">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= (int)$project["id"] ?>" <?= $projectFilter === (int)$project["id"] ? "selected" : "" ?>>
                                        <?= tr_e($project["project_name"]) ?> <?= $project["project_code"] ? "(" . tr_e($project["project_code"]) . ")" : "" ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-2">
                            <label class="form-label fw-bold small">Status</label>
                            <select name="status" class="form-select rounded-4">
                                <option value="">All Status</option>
                                <option value="scheduled" <?= $statusFilter === "scheduled" ? "selected" : "" ?>>Scheduled</option>
                                <option value="active" <?= $statusFilter === "active" ? "selected" : "" ?>>Active</option>
                                <option value="completed" <?= $statusFilter === "completed" ? "selected" : "" ?>>Completed</option>
                                <option value="cancelled" <?= $statusFilter === "cancelled" ? "selected" : "" ?>>Cancelled</option>
                            </select>
                        </div>

                        <div class="col-6 col-lg-2">
                            <label class="form-label fw-bold small">From</label>
                            <input type="date" name="date_from" class="form-control rounded-4" value="<?= tr_e($dateFrom) ?>">
                        </div>

                        <div class="col-6 col-lg-2">
                            <label class="form-label fw-bold small">To</label>
                            <input type="date" name="date_to" class="form-control rounded-4" value="<?= tr_e($dateTo) ?>">
                        </div>

                        <div class="col-12 col-lg-1 d-flex gap-2">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button>
                        </div>

                        <div class="col-12 d-flex justify-content-end">
                            <a href="temporary-replacements.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm px-3">Reset Filters</a>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4 d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">Replacement Records</h2>
                            <p class="text-muted-custom small mb-0">View, edit and cancel temporary replacements.</p>
                        </div>
                    </div>

                    <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                        <table class="project-table w-100">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Replacement</th>
                                    <th>Role</th>
                                    <th>Period</th>
                                    <th>Status</th>
                                    <th>Reason</th>
                                    <th style="width:190px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($replacements as $replacement): ?>
                                    <?php $replacementJson = htmlspecialchars(json_encode($replacement, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= tr_e($replacement["project_name"]) ?></div>
                                            <small class="text-muted-custom"><?= tr_e($replacement["project_code"] ?: "-") ?></small>
                                        </td>
                                        <td>
                                            <div class="replacement-chain">
                                                <span class="employee-chip"><?= tr_e($replacement["original_employee_name"]) ?></span>
                                                <span class="arrow-chip"><i data-lucide="arrow-right" style="width:14px;height:14px;"></i></span>
                                                <span class="employee-chip"><?= tr_e($replacement["replacement_employee_name"]) ?></span>
                                            </div>
                                        </td>
                                        <td class="fw-bold"><?= tr_e($replacement["assignment_role"]) ?></td>
                                        <td>
                                            <div class="fw-bold"><?= tr_e(tr_date_show($replacement["replacement_from"])) ?></div>
                                            <small class="text-muted-custom">to <?= tr_e(tr_date_show($replacement["replacement_to"])) ?></small>
                                        </td>
                                        <td>
                                            <span class="status-pill <?= tr_status_class($replacement["status"]) ?>">
                                                <?= tr_e($replacement["status"]) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-muted-custom"><?= tr_e($replacement["reason"] ?: "-") ?></span>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php if ($canEdit): ?>
                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#replacementModal"
                                                        onclick='openEditReplacementModal(<?= $replacementJson ?>)'>Edit</button>
                                                <?php endif; ?>

                                                <?php if ($canDelete && $replacement["status"] !== "cancelled"): ?>
                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#cancelReplacementModal"
                                                        onclick='openCancelReplacementModal(<?= $replacementJson ?>)'>Cancel</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($replacements)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted-custom py-4">
                                            <?= empty($missingTables) ? "No temporary replacements found." : "Required tables missing. Please check database." ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3">
                        <?php foreach ($replacements as $replacement): ?>
                            <?php $replacementJson = htmlspecialchars(json_encode($replacement, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>
                            <article class="mobile-replacement-card">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <p class="fw-bold small mb-1"><?= tr_e($replacement["project_name"]) ?></p>
                                        <small class="text-muted-custom"><?= tr_e($replacement["assignment_role"]) ?></small>
                                    </div>
                                    <span class="status-pill <?= tr_status_class($replacement["status"]) ?>">
                                        <?= tr_e($replacement["status"]) ?>
                                    </span>
                                </div>

                                <div class="mobile-info"><span>Original</span><span><?= tr_e($replacement["original_employee_name"]) ?></span></div>
                                <div class="mobile-info"><span>Replacement</span><span><?= tr_e($replacement["replacement_employee_name"]) ?></span></div>
                                <div class="mobile-info"><span>From</span><span><?= tr_e(tr_date_show($replacement["replacement_from"])) ?></span></div>
                                <div class="mobile-info"><span>To</span><span><?= tr_e(tr_date_show($replacement["replacement_to"])) ?></span></div>

                                <div class="mt-3 d-flex flex-wrap gap-2">
                                    <?php if ($canEdit): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                            data-bs-toggle="modal" data-bs-target="#replacementModal"
                                            onclick='openEditReplacementModal(<?= $replacementJson ?>)'>Edit</button>
                                    <?php endif; ?>

                                    <?php if ($canDelete && $replacement["status"] !== "cancelled"): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                                            data-bs-toggle="modal" data-bs-target="#cancelReplacementModal"
                                            onclick='openCancelReplacementModal(<?= $replacementJson ?>)'>Cancel</button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="pagination-wrap">
                        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="text-muted-custom small fw-bold">
                                    Showing <?= (int)$fromRow ?> to <?= (int)$toRow ?> of <?= (int)$totalRows ?> records
                                </span>

                                <select class="form-select per-page-select" onchange="window.location.href=this.value">
                                    <?php foreach ($allowedPerPage as $limit): ?>
                                        <option value="<?= tr_e(tr_per_page_url($limit)) ?>" <?= $perPage === $limit ? "selected" : "" ?>>
                                            <?= (int)$limit ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <a class="page-link-custom <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= tr_e(tr_page_url(1)) ?>">First</a>
                                <a class="page-link-custom <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= tr_e(tr_page_url(max(1, $page - 1))) ?>">
                                    <i data-lucide="chevron-left" style="width:15px;height:15px;"></i>
                                </a>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                for ($i = $startPage; $i <= $endPage; $i++):
                                ?>
                                    <a class="page-link-custom <?= $i === $page ? 'active' : '' ?>" href="<?= tr_e(tr_page_url($i)) ?>">
                                        <?= (int)$i ?>
                                    </a>
                                <?php endfor; ?>

                                <a class="page-link-custom <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= tr_e(tr_page_url(min($totalPages, $page + 1))) ?>">
                                    <i data-lucide="chevron-right" style="width:15px;height:15px;"></i>
                                </a>
                                <a class="page-link-custom <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= tr_e(tr_page_url($totalPages)) ?>">Last</a>
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

    <?php if (($canCreate || $canEdit) && empty($missingTables)): ?>
        <div class="modal fade" id="replacementModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl">
                <form method="POST" class="modal-content">
                    <input type="hidden" name="action" value="save_replacement">
                    <input type="hidden" name="replacement_id" id="replacement_id">

                    <div class="modal-header px-4">
                        <div>
                            <h5 class="modal-title fw-bold" id="replacementModalTitle">Add Temporary Replacement</h5>
                            <p class="text-muted-custom small mb-0">Select original assignment and temporary replacement employee.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body px-4">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-bold small">Original Project Assignment <span class="text-danger">*</span></label>
                                <select name="original_assignment_id" id="original_assignment_id" class="form-select rounded-4" required>
                                    <option value="">Select Assignment</option>
                                    <?php foreach ($assignments as $assignment): ?>
                                        <option value="<?= (int)$assignment["id"] ?>"
                                            data-project-id="<?= (int)$assignment["project_id"] ?>"
                                            data-employee-id="<?= (int)$assignment["employee_id"] ?>"
                                            data-role-id="<?= (int)$assignment["assignment_role_id"] ?>">
                                            <?= tr_e($assignment["project_name"]) ?>
                                            <?= $assignment["project_code"] ? "(" . tr_e($assignment["project_code"]) . ")" : "" ?>
                                            - <?= tr_e($assignment["role_name"]) ?>
                                            - <?= tr_e($assignment["employee_name"]) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted-custom fw-semibold">This decides original employee, project and assignment role automatically.</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Replacement Employee <span class="text-danger">*</span></label>
                                <select name="replacement_employee_id" id="replacement_employee_id" class="form-select rounded-4" required>
                                    <option value="">Select Replacement Employee</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?= (int)$employee["id"] ?>">
                                            <?= tr_e($employee["employee_name"]) ?> <?= $employee["employee_meta"] ? "- " . tr_e($employee["employee_meta"]) : "" ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Leave Request</label>
                                <select name="leave_request_id" id="leave_request_id" class="form-select rounded-4">
                                    <option value="">No Leave Request</option>
                                    <?php foreach ($leaveRequests as $leave): ?>
                                        <option value="<?= (int)$leave["id"] ?>">
                                            #<?= (int)$leave["id"] ?> - <?= tr_e($leave["leave_text"]) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Replacement From <span class="text-danger">*</span></label>
                                <input type="date" name="replacement_from" id="replacement_from" class="form-control rounded-4" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Replacement To <span class="text-danger">*</span></label>
                                <input type="date" name="replacement_to" id="replacement_to" class="form-control rounded-4" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Status</label>
                                <select name="status" id="status" class="form-select rounded-4">
                                    <option value="scheduled">Scheduled</option>
                                    <option value="active">Active</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold small">Reason / Notes</label>
                                <textarea name="reason" id="reason" rows="3" class="form-control rounded-4" placeholder="Reason for temporary replacement"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4" id="replacementSubmitBtn">Save Replacement</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canDelete && empty($missingTables)): ?>
        <div class="modal fade" id="cancelReplacementModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" class="modal-content">
                    <input type="hidden" name="action" value="cancel_replacement">
                    <input type="hidden" name="replacement_id" id="cancel_replacement_id">

                    <div class="modal-header px-4">
                        <div>
                            <h5 class="modal-title fw-bold">Cancel Replacement</h5>
                            <p class="text-muted-custom small mb-0">This will mark the replacement as cancelled.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body px-4">
                        <div class="delete-warning-box">
                            <p class="fw-bold mb-1" id="cancel_replacement_title">Cancel this replacement?</p>
                            <p class="text-muted-custom small mb-0">The replacement record will be kept for history.</p>
                        </div>
                    </div>

                    <div class="modal-footer px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">No</button>
                        <button type="submit" class="btn btn-danger rounded-4 fw-bold px-4">Yes, Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=70"></script>

    <script>
        function openAddReplacementModal() {
            document.getElementById("replacementModalTitle").textContent = "Add Temporary Replacement";
            document.getElementById("replacementSubmitBtn").textContent = "Save Replacement";
            document.getElementById("replacement_id").value = "";
            document.getElementById("original_assignment_id").value = "";
            document.getElementById("replacement_employee_id").value = "";
            document.getElementById("leave_request_id").value = "";
            document.getElementById("replacement_from").value = "";
            document.getElementById("replacement_to").value = "";
            document.getElementById("status").value = "scheduled";
            document.getElementById("reason").value = "";
        }

        function openEditReplacementModal(replacement) {
            document.getElementById("replacementModalTitle").textContent = "Edit Temporary Replacement";
            document.getElementById("replacementSubmitBtn").textContent = "Update Replacement";
            document.getElementById("replacement_id").value = replacement.id || "";
            document.getElementById("original_assignment_id").value = replacement.original_assignment_id || "";
            document.getElementById("replacement_employee_id").value = replacement.replacement_employee_id || "";
            document.getElementById("leave_request_id").value = replacement.leave_request_id || "";
            document.getElementById("replacement_from").value = replacement.replacement_from || "";
            document.getElementById("replacement_to").value = replacement.replacement_to || "";
            document.getElementById("status").value = replacement.status || "scheduled";
            document.getElementById("reason").value = replacement.reason || "";
        }

        function openCancelReplacementModal(replacement) {
            document.getElementById("cancel_replacement_id").value = replacement.id || "";
            document.getElementById("cancel_replacement_title").textContent =
                "Cancel replacement for " + (replacement.project_name || "this project") + "?";
        }

        document.addEventListener("DOMContentLoaded", function () {
            const form = document.querySelector("#replacementModal form");

            if (form) {
                form.addEventListener("submit", function (e) {
                    const assignment = document.getElementById("original_assignment_id");
                    const replacement = document.getElementById("replacement_employee_id");
                    const from = document.getElementById("replacement_from");
                    const to = document.getElementById("replacement_to");

                    const selected = assignment.options[assignment.selectedIndex];
                    const originalEmployeeId = selected ? selected.getAttribute("data-employee-id") : "";

                    if (originalEmployeeId && replacement.value && String(originalEmployeeId) === String(replacement.value)) {
                        e.preventDefault();
                        alert("Original employee and replacement employee cannot be same.");
                        return;
                    }

                    if (from.value && to.value && to.value < from.value) {
                        e.preventDefault();
                        alert("Replacement to date cannot be earlier than from date.");
                    }
                });
            }

            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
