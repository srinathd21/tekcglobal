<?php
session_start();
require_once __DIR__ . "/includes/db.php";

require_permission($conn, "can_view", "activity-logs.php");

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function table_exists_activity($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $q && mysqli_num_rows($q) > 0;
}

function column_exists_activity($conn, $table, $column)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && mysqli_num_rows($q) > 0;
}

function show_datetime($value)
{
    return ($value && $value !== "0000-00-00 00:00:00") ? date("d M Y h:i A", strtotime($value)) : "-";
}

function show_date_only($value)
{
    return ($value && $value !== "0000-00-00") ? date("d M Y", strtotime($value)) : "-";
}

function activity_badge_class($type)
{
    $type = strtoupper(trim($type ?? ""));

    if (in_array($type, ["CREATE", "ADD", "INSERT", "LOGIN", "APPROVE", "APPROVED"], true)) {
        return "green";
    }

    if (in_array($type, ["UPDATE", "EDIT", "MODIFY", "PASSWORD", "PUNCH_OUT"], true)) {
        return "blue";
    }

    if (in_array($type, ["DELETE", "REMOVE", "REJECT", "REJECTED", "LOGOUT"], true)) {
        return "red";
    }

    return "amber";
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

if (!table_exists_activity($conn, "activity_logs")) {
    $pageMessageType = "error";
    $pageMessageText = "activity_logs table not found.";
}

$search = trim($_GET["search"] ?? "");
$moduleFilter = trim($_GET["module"] ?? "");
$typeFilter = trim($_GET["activity_type"] ?? "");
$dateFrom = trim($_GET["date_from"] ?? "");
$dateTo = trim($_GET["date_to"] ?? "");
$employeeFilter = (int)($_GET["employee_id"] ?? 0);
$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = (int)($_GET["per_page"] ?? 25);
$allowedPerPage = [10, 25, 50, 100];

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
}

$where = ["1=1"];

if ($search !== "") {
    $like = mysqli_real_escape_string($conn, "%" . $search . "%");
    $where[] = "(
        al.employee_name LIKE '$like'
        OR al.username LIKE '$like'
        OR al.designation LIKE '$like'
        OR al.department LIKE '$like'
        OR al.module LIKE '$like'
        OR al.description LIKE '$like'
        OR al.ip_address LIKE '$like'
    )";
}

if ($moduleFilter !== "") {
    $moduleEsc = mysqli_real_escape_string($conn, $moduleFilter);
    $where[] = "al.module = '$moduleEsc'";
}

if ($typeFilter !== "") {
    $typeEsc = mysqli_real_escape_string($conn, $typeFilter);
    $where[] = "al.activity_type = '$typeEsc'";
}

if ($employeeFilter > 0) {
    $where[] = "al.employee_id = " . (int)$employeeFilter;
}

if ($dateFrom !== "") {
    $fromEsc = mysqli_real_escape_string($conn, $dateFrom);
    $where[] = "DATE(al.created_at) >= '$fromEsc'";
}

if ($dateTo !== "") {
    $toEsc = mysqli_real_escape_string($conn, $dateTo);
    $where[] = "DATE(al.created_at) <= '$toEsc'";
}

$whereSql = implode(" AND ", $where);

$totalRows = 0;
$logs = [];
$stats = [
    "total" => 0,
    "today" => 0,
    "creates" => 0,
    "updates" => 0,
    "deletes" => 0
];

if (table_exists_activity($conn, "activity_logs")) {
    $countQ = mysqli_query($conn, "SELECT COUNT(*) AS total FROM activity_logs al WHERE $whereSql");
    $totalRows = $countQ ? (int)(mysqli_fetch_assoc($countQ)["total"] ?? 0) : 0;

    $stats["total"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM activity_logs"))["total"] ?? 0);
    $stats["today"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM activity_logs WHERE DATE(created_at) = CURDATE()"))["total"] ?? 0);
    $stats["creates"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM activity_logs WHERE UPPER(activity_type) IN ('CREATE','ADD','INSERT')"))["total"] ?? 0);
    $stats["updates"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM activity_logs WHERE UPPER(activity_type) IN ('UPDATE','EDIT','MODIFY')"))["total"] ?? 0);
    $stats["deletes"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM activity_logs WHERE UPPER(activity_type) IN ('DELETE','REMOVE')"))["total"] ?? 0);

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $logsQ = mysqli_query($conn, "
        SELECT al.*
        FROM activity_logs al
        WHERE $whereSql
        ORDER BY al.id DESC
        LIMIT $perPage OFFSET $offset
    ");

    if ($logsQ) {
        while ($row = mysqli_fetch_assoc($logsQ)) {
            $logs[] = $row;
        }
    }
} else {
    $totalPages = 1;
    $offset = 0;
}

$modules = [];
$types = [];
$employees = [];

if (table_exists_activity($conn, "activity_logs")) {
    $moduleQ = mysqli_query($conn, "
        SELECT DISTINCT module
        FROM activity_logs
        WHERE module IS NOT NULL AND module <> ''
        ORDER BY module ASC
    ");
    if ($moduleQ) {
        while ($row = mysqli_fetch_assoc($moduleQ)) {
            $modules[] = $row["module"];
        }
    }

    $typeQ = mysqli_query($conn, "
        SELECT DISTINCT activity_type
        FROM activity_logs
        WHERE activity_type IS NOT NULL AND activity_type <> ''
        ORDER BY activity_type ASC
    ");
    if ($typeQ) {
        while ($row = mysqli_fetch_assoc($typeQ)) {
            $types[] = $row["activity_type"];
        }
    }

    $employeeQ = mysqli_query($conn, "
        SELECT employee_id, employee_name
        FROM activity_logs
        WHERE employee_id IS NOT NULL AND employee_id > 0 AND employee_name IS NOT NULL AND employee_name <> ''
        GROUP BY employee_id, employee_name
        ORDER BY employee_name ASC
    ");
    if ($employeeQ) {
        while ($row = mysqli_fetch_assoc($employeeQ)) {
            $employees[] = $row;
        }
    }
}

function page_url($pageNumber)
{
    $q = $_GET;
    $q["page"] = $pageNumber;
    return "activity-logs.php?" . http_build_query($q);
}

function per_page_url($limit)
{
    $q = $_GET;
    $q["per_page"] = $limit;
    $q["page"] = 1;
    return "activity-logs.php?" . http_build_query($q);
}

$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalRows);
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Activity Logs - TEK-C PMC Construction</title>
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

                .activity-stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 18px 20px;
            min-height: 112px;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .activity-stat-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .activity-stat-icon {
            width: 58px;
            height: 58px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }

        .activity-stat-icon svg {
            width: 28px;
            height: 28px;
        }

        .activity-stat-icon.blue {
            color: #2563eb;
            background: rgba(37, 99, 235, .14);
        }

        .activity-stat-icon.green {
            color: #059669;
            background: rgba(16, 185, 129, .16);
        }

        .activity-stat-icon.purple {
            color: #7c3aed;
            background: rgba(124, 58, 237, .15);
        }

        .activity-stat-icon.amber {
            color: #d97706;
            background: rgba(245, 158, 11, .18);
        }

        .activity-stat-icon.red {
            color: #dc2626;
            background: rgba(239, 68, 68, .14);
        }

        .activity-stat-label {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 900;
            line-height: 1.2;
            margin: 0 0 7px;
            white-space: nowrap;
        }

        .activity-stat-value {
            color: var(--text-main);
            font-size: 28px;
            line-height: 1;
            font-weight: 900;
            letter-spacing: -.03em;
            margin: 0;
        }

        .activity-stat-sub {
            color: #059669;
            font-size: 12px;
            font-weight: 900;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

.activity-filter-card {
            overflow: visible;
        }

        .activity-filter-card .form-control,
        .activity-filter-card .form-select {
            min-height: 44px;
            font-weight: 700;
        }

        .activity-filter-card .filter-actions {
            display: flex;
            gap: 8px;
            min-width: 190px;
        }

        .activity-table-wrap {
            width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            padding-bottom: 8px;
        }

        .activity-table {
            min-width: 1180px;
            table-layout: fixed;
        }

        .activity-table th,
        .activity-table td {
            vertical-align: middle;
        }

        .activity-table .col-user { width: 245px; }
        .activity-table .col-activity { width: 115px; }
        .activity-table .col-module { width: 130px; }
        .activity-table .col-desc { width: 310px; }
        .activity-table .col-ref { width: 105px; }
        .activity-table .col-ip { width: 120px; }
        .activity-table .col-date { width: 185px; }
        .activity-table .col-view { width: 90px; }

        .activity-badge {
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .activity-badge.green { background: rgba(16,185,129,.15); color:#059669; }
        .activity-badge.blue { background: rgba(37,99,235,.15); color:#2563eb; }
        .activity-badge.amber { background: rgba(245,158,11,.15); color:#d97706; }
        .activity-badge.red { background: rgba(239,68,68,.15); color:#dc2626; }

        .log-user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--brand-1), var(--brand-2));
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            flex-shrink: 0;
        }

        .log-description {
            max-width: 420px;
            white-space: normal;
            word-break: break-word;
        }

        .mobile-log-card {
            border: 1px solid var(--border-soft);
            background: var(--card-bg);
            box-shadow: var(--shadow-card);
            border-radius: 20px;
            padding: 14px;
        }

        .mobile-info {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-soft);
            font-size: 12px;
        }

        .mobile-info span:first-child {
            color: var(--text-muted);
            font-weight: 800;
        }

        .mobile-info span:last-child {
            color: var(--text-main);
            font-weight: 800;
            text-align: right;
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

        .detail-box {
            border: 1px solid var(--border-soft);
            background: rgba(148, 163, 184, .06);
            border-radius: 18px;
            padding: 13px;
            height: 100%;
        }

        .detail-label {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 5px;
        }

        .detail-value {
            color: var(--text-main);
            font-size: 13px;
            font-weight: 800;
            margin: 0;
            word-break: break-word;
        }

        @media (max-width: 1199.98px) {
            .activity-filter-card .filter-actions {
                width: 100%;
            }

            .activity-filter-card .filter-actions .btn {
                flex: 1;
            }
        }

        @media (max-width: 575.98px) {
            .activity-stat-card {
                min-height: 92px;
                padding: 15px 15px 15px 18px;
            }

            .activity-stat-value {
                font-size: 24px;
            }

            .activity-stat-icon {
                width: 42px;
                height: 42px;
                border-radius: 16px;
            }
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
                            <h1 class="h4 fw-bold mb-1">Activity Logs</h1>
                            <p class="text-muted-custom mb-0 small">Track create, update, delete, login, approval and other system activities.</p>
                        </div>

                        <a href="activity-logs.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm px-3">
                            <i data-lucide="refresh-cw" style="width:15px;height:15px;"></i> Refresh
                        </a>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6 col-xl">
                        <div class="activity-stat-card">
                            <div class="activity-stat-left">
                                <div class="activity-stat-icon blue"><i data-lucide="history"></i></div>
                                <div>
                                    <p class="activity-stat-label">Total Logs</p>
                                    <h3 class="activity-stat-value"><?= (int)$stats["total"] ?></h3>
                                    <div class="activity-stat-sub"><i data-lucide="arrow-up" style="width:13px;height:13px;"></i> All Activities</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl">
                        <div class="activity-stat-card">
                            <div class="activity-stat-left">
                                <div class="activity-stat-icon green"><i data-lucide="calendar-days"></i></div>
                                <div>
                                    <p class="activity-stat-label">Today</p>
                                    <h3 class="activity-stat-value"><?= (int)$stats["today"] ?></h3>
                                    <div class="activity-stat-sub"><i data-lucide="arrow-up" style="width:13px;height:13px;"></i> Today Logs</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl">
                        <div class="activity-stat-card">
                            <div class="activity-stat-left">
                                <div class="activity-stat-icon purple"><i data-lucide="plus-circle"></i></div>
                                <div>
                                    <p class="activity-stat-label">Creates</p>
                                    <h3 class="activity-stat-value"><?= (int)$stats["creates"] ?></h3>
                                    <div class="activity-stat-sub"><i data-lucide="arrow-up" style="width:13px;height:13px;"></i> New Records</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl">
                        <div class="activity-stat-card">
                            <div class="activity-stat-left">
                                <div class="activity-stat-icon amber"><i data-lucide="pencil"></i></div>
                                <div>
                                    <p class="activity-stat-label">Updates</p>
                                    <h3 class="activity-stat-value"><?= (int)$stats["updates"] ?></h3>
                                    <div class="activity-stat-sub"><i data-lucide="arrow-up" style="width:13px;height:13px;"></i> Modified</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl">
                        <div class="activity-stat-card">
                            <div class="activity-stat-left">
                                <div class="activity-stat-icon red"><i data-lucide="trash-2"></i></div>
                                <div>
                                    <p class="activity-stat-label">Deletes</p>
                                    <h3 class="activity-stat-value"><?= (int)$stats["deletes"] ?></h3>
                                    <div class="activity-stat-sub"><i data-lucide="arrow-up" style="width:13px;height:13px;"></i> Removed</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="GET" class="filter-card activity-filter-card mb-3">
                    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">

                    <div class="row g-3 align-items-end">
                        <div class="col-md-6 col-xl-3">
                            <label class="form-label fw-bold small">Search</label>
                            <input type="text" name="search" class="form-control rounded-4" value="<?= e($search) ?>" placeholder="Employee, module, description, IP">
                        </div>

                        <div class="col-md-6 col-xl-2">
                            <label class="form-label fw-bold small">Module</label>
                            <select name="module" class="form-select rounded-4">
                                <option value="">All Modules</option>
                                <?php foreach ($modules as $module): ?>
                                    <option value="<?= e($module) ?>" <?= $moduleFilter === $module ? "selected" : "" ?>>
                                        <?= e($module) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 col-xl-2">
                            <label class="form-label fw-bold small">Activity Type</label>
                            <select name="activity_type" class="form-select rounded-4">
                                <option value="">All Types</option>
                                <?php foreach ($types as $type): ?>
                                    <option value="<?= e($type) ?>" <?= $typeFilter === $type ? "selected" : "" ?>>
                                        <?= e($type) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 col-xl-2">
                            <label class="form-label fw-bold small">Employee</label>
                            <select name="employee_id" class="form-select rounded-4">
                                <option value="0">All Employees</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= (int)$emp["employee_id"] ?>" <?= $employeeFilter === (int)$emp["employee_id"] ? "selected" : "" ?>>
                                        <?= e($emp["employee_name"]) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 col-xl-1">
                            <label class="form-label fw-bold small">From</label>
                            <input type="date" name="date_from" class="form-control rounded-4" value="<?= e($dateFrom) ?>">
                        </div>

                        <div class="col-md-6 col-xl-1">
                            <label class="form-label fw-bold small">To</label>
                            <input type="date" name="date_to" class="form-control rounded-4" value="<?= e($dateTo) ?>">
                        </div>

                        <div class="col-md-12 col-xl-auto">
                            <div class="filter-actions">
                                <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Filter</button>
                                <a href="activity-logs.php" class="btn btn-outline-secondary rounded-4 fw-bold px-4">Reset</a>
                            </div>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4 d-flex flex-column flex-lg-row justify-content-lg-between gap-2">
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">System Activity</h2>
                            <p class="text-muted-custom small mb-0">Showing latest activity records based on selected filters.</p>
                        </div>
                        <span class="activity-badge blue"><?= (int)$totalRows ?> Result<?= $totalRows === 1 ? "" : "s" ?></span>
                    </div>

                    <div class="d-none d-lg-block px-3 px-lg-4 pb-3">
                        <div class="activity-table-wrap thin-scrollbar">
                            <table class="project-table activity-table w-100">
                                <thead>
                                    <tr>
                                        <th class="col-user">User</th>
                                        <th class="col-activity">Activity</th>
                                        <th class="col-module">Module</th>
                                        <th class="col-desc">Description</th>
                                        <th class="col-ref">Reference</th>
                                        <th class="col-ip">IP Address</th>
                                        <th class="col-date">Date & Time</th>
                                        <th class="col-view">View</th>
                                    </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <?php $json = htmlspecialchars(json_encode($log, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="log-user-avatar"><?= e(strtoupper(substr($log["employee_name"] ?: $log["username"] ?: "U", 0, 1))) ?></div>
                                                <div>
                                                    <div class="fw-bold"><?= e($log["employee_name"] ?: "-") ?></div>
                                                    <small class="text-muted-custom">
                                                        <?= e($log["username"] ?: "-") ?>
                                                        <?= !empty($log["designation"]) ? " · " . e($log["designation"]) : "" ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="activity-badge <?= e(activity_badge_class($log["activity_type"])) ?>">
                                                <?= e($log["activity_type"] ?: "-") ?>
                                            </span>
                                        </td>
                                        <td class="fw-bold"><?= e($log["module"] ?: "-") ?></td>
                                        <td>
                                            <div class="log-description fw-semibold small"><?= e($log["description"] ?: "-") ?></div>
                                        </td>
                                        <td><?= !empty($log["reference_id"]) ? "#" . e($log["reference_id"]) : "-" ?></td>
                                        <td><small class="text-muted-custom"><?= e($log["ip_address"] ?: "-") ?></small></td>
                                        <td>
                                            <div class="fw-bold"><?= e(show_datetime($log["created_at"] ?? "")) ?></div>
                                        </td>
                                        <td>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                                data-bs-toggle="modal"
                                                data-bs-target="#viewLogModal"
                                                onclick='viewLogDetails(<?= $json ?>)'>
                                                View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted-custom py-4">No activity logs found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="d-lg-none px-3 px-lg-4 pb-3 d-grid gap-3">
                        <?php foreach ($logs as $log): ?>
                            <?php $json = htmlspecialchars(json_encode($log, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>
                            <article class="mobile-log-card">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <p class="fw-bold small mb-1"><?= e($log["employee_name"] ?: "-") ?></p>
                                        <small class="text-muted-custom"><?= e($log["username"] ?: "-") ?></small>
                                    </div>
                                    <span class="activity-badge <?= e(activity_badge_class($log["activity_type"])) ?>">
                                        <?= e($log["activity_type"] ?: "-") ?>
                                    </span>
                                </div>

                                <div class="mobile-info"><span>Module</span><span><?= e($log["module"] ?: "-") ?></span></div>
                                <div class="mobile-info"><span>Description</span><span><?= e($log["description"] ?: "-") ?></span></div>
                                <div class="mobile-info"><span>Reference</span><span><?= !empty($log["reference_id"]) ? "#" . e($log["reference_id"]) : "-" ?></span></div>
                                <div class="mobile-info"><span>IP</span><span><?= e($log["ip_address"] ?: "-") ?></span></div>
                                <div class="mobile-info"><span>Date</span><span><?= e(show_datetime($log["created_at"] ?? "")) ?></span></div>

                                <button type="button"
                                    class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-3"
                                    data-bs-toggle="modal"
                                    data-bs-target="#viewLogModal"
                                    onclick='viewLogDetails(<?= $json ?>)'>
                                    View Details
                                </button>
                            </article>
                        <?php endforeach; ?>

                        <?php if (empty($logs)): ?>
                            <div class="text-center text-muted-custom py-4">No activity logs found.</div>
                        <?php endif; ?>
                    </div>

                    <div class="pagination-wrap">
                        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="text-muted-custom small fw-bold">
                                    Showing <?= (int)$fromRow ?> to <?= (int)$toRow ?> of <?= (int)$totalRows ?> logs
                                </span>
                                <select class="form-select per-page-select" onchange="window.location.href=this.value">
                                    <?php foreach ($allowedPerPage as $limit): ?>
                                        <option value="<?= e(per_page_url($limit)) ?>" <?= $perPage === $limit ? "selected" : "" ?>>
                                            <?= (int)$limit ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <a class="page-link-custom <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(page_url(1)) ?>">First</a>
                                <a class="page-link-custom <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(page_url(max(1, $page - 1))) ?>">‹</a>

                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                    <a class="page-link-custom <?= $i === $page ? 'active' : '' ?>" href="<?= e(page_url($i)) ?>"><?= (int)$i ?></a>
                                <?php endfor; ?>

                                <a class="page-link-custom <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(page_url(min($totalPages, $page + 1))) ?>">›</a>
                                <a class="page-link-custom <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(page_url($totalPages)) ?>">Last</a>
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

    <div class="modal fade" id="viewLogModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Activity Log Details</h5>
                        <p class="text-muted-custom small mb-0">Detailed view of selected activity record.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="detail-box">
                                <div class="detail-label">User</div>
                                <p class="detail-value" id="logUser">-</p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="detail-box">
                                <div class="detail-label">Username</div>
                                <p class="detail-value" id="logUsername">-</p>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="detail-box">
                                <div class="detail-label">Activity Type</div>
                                <p class="detail-value" id="logType">-</p>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="detail-box">
                                <div class="detail-label">Module</div>
                                <p class="detail-value" id="logModule">-</p>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="detail-box">
                                <div class="detail-label">Reference ID</div>
                                <p class="detail-value" id="logReference">-</p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="detail-box">
                                <div class="detail-label">Designation / Department</div>
                                <p class="detail-value" id="logWork">-</p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="detail-box">
                                <div class="detail-label">IP Address</div>
                                <p class="detail-value" id="logIp">-</p>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="detail-box">
                                <div class="detail-label">Description</div>
                                <p class="detail-value" id="logDescription">-</p>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="detail-box">
                                <div class="detail-label">Created At</div>
                                <p class="detail-value" id="logCreatedAt">-</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=100"></script>

    <script>
        function safeText(value) {
            return value && String(value).trim() !== "" ? value : "-";
        }

        function jsDateTime(value) {
            if (!value) return "-";

            const date = new Date(String(value).replace(" ", "T"));
            if (isNaN(date.getTime())) return value;

            return date.toLocaleString("en-IN", {
                day: "2-digit",
                month: "short",
                year: "numeric",
                hour: "2-digit",
                minute: "2-digit"
            });
        }

        function viewLogDetails(log) {
            document.getElementById("logUser").textContent = safeText(log.employee_name);
            document.getElementById("logUsername").textContent = safeText(log.username);
            document.getElementById("logType").textContent = safeText(log.activity_type);
            document.getElementById("logModule").textContent = safeText(log.module);
            document.getElementById("logReference").textContent = log.reference_id ? "#" + log.reference_id : "-";
            document.getElementById("logWork").textContent = safeText((log.designation || "-") + " / " + (log.department || "-"));
            document.getElementById("logIp").textContent = safeText(log.ip_address);
            document.getElementById("logDescription").textContent = safeText(log.description);
            document.getElementById("logCreatedAt").textContent = jsDateTime(log.created_at);
        }

        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
