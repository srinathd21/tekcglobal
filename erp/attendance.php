<?php
session_start();
require_once __DIR__ . "/includes/db.php";

require_permission($conn, "can_view", "attendance.php");

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function date_show($date)
{
    return ($date && $date !== "0000-00-00") ? date("d M Y", strtotime($date)) : "-";
}

function time_show($dateTime)
{
    return ($dateTime && $dateTime !== "0000-00-00 00:00:00") ? date("h:i A", strtotime($dateTime)) : "-";
}

function status_class($status)
{
    $status = strtolower(trim($status ?? ""));
    if ($status === "approved" || $status === "present") return "green";
    if ($status === "pending") return "amber";
    if ($status === "rejected" || $status === "absent") return "red";
    return "blue";
}

function table_exists($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $q && mysqli_num_rows($q) > 0;
}

function column_exists($conn, $table, $column)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && mysqli_num_rows($q) > 0;
}

function get_login_employee_id($conn)
{
    if (!empty($_SESSION["employee_id"])) {
        return (int)$_SESSION["employee_id"];
    }

    if (!empty($_SESSION["user_id"]) && table_exists($conn, "users") && column_exists($conn, "users", "employee_id")) {
        $userId = (int)$_SESSION["user_id"];
        $res = mysqli_query($conn, "SELECT employee_id FROM users WHERE id = $userId LIMIT 1");
        if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row["employee_id"])) {
            $_SESSION["employee_id"] = (int)$row["employee_id"];
            return (int)$row["employee_id"];
        }
    }

    return 0;
}

function user_has_page_permission($conn, $permissionColumn, $pageUrl)
{
    $permissionColumn = trim($permissionColumn);
    $pageUrl = trim($pageUrl);

    if ($permissionColumn === "" || $pageUrl === "") {
        return false;
    }

    $allowedColumns = ["can_view", "can_create", "can_edit", "can_delete"];
    if (!in_array($permissionColumn, $allowedColumns, true)) {
        return false;
    }

    if (empty($_SESSION["user_id"])) {
        return false;
    }

    $userId = (int)$_SESSION["user_id"];

    if (!table_exists($conn, "users")) {
        return false;
    }

    $roleId = 0;
    if (column_exists($conn, "users", "role_id")) {
        $userQ = mysqli_query($conn, "SELECT role_id FROM users WHERE id = $userId LIMIT 1");
        if ($userQ && ($user = mysqli_fetch_assoc($userQ))) {
            $roleId = (int)($user["role_id"] ?? 0);
        }
    }

    if ($roleId <= 0 && !empty($_SESSION["role_id"])) {
        $roleId = (int)$_SESSION["role_id"];
    }

    if ($roleId <= 0) {
        return false;
    }

    $escapedPage = mysqli_real_escape_string($conn, $pageUrl);

    if (table_exists($conn, "role_permissions") && table_exists($conn, "sidebar_menus")) {
        $sql = "
            SELECT rp.`$permissionColumn` AS allowed
            FROM role_permissions rp
            INNER JOIN sidebar_menus sm ON sm.id = rp.menu_id
            WHERE rp.role_id = $roleId
              AND sm.page_url = '$escapedPage'
            LIMIT 1
        ";
        $q = mysqli_query($conn, $sql);
        if ($q && ($row = mysqli_fetch_assoc($q))) {
            return (int)$row["allowed"] === 1;
        }
    }

    if (table_exists($conn, "role_permissions") && table_exists($conn, "sidebar_control")) {
        $sql = "
            SELECT rp.`$permissionColumn` AS allowed
            FROM role_permissions rp
            INNER JOIN sidebar_control sm ON sm.id = rp.menu_id
            WHERE rp.role_id = $roleId
              AND sm.page_url = '$escapedPage'
            LIMIT 1
        ";
        $q = mysqli_query($conn, $sql);
        if ($q && ($row = mysqli_fetch_assoc($q))) {
            return (int)$row["allowed"] === 1;
        }
    }

    if (function_exists("has_permission")) {
        return (bool)has_permission($conn, $permissionColumn, $pageUrl);
    }

    return false;
}


function user_has_attendance_approve_access($conn)
{
    if (empty($_SESSION["user_id"])) {
        return false;
    }

    $userId = (int)$_SESSION["user_id"];
    $pageUrl = "attendance-approvals.php";

    if (!table_exists($conn, "user_roles") || !table_exists($conn, "role_sidebar_access") || !table_exists($conn, "sidebar_menus")) {
        return false;
    }

    $escapedPageUrl = mysqli_real_escape_string($conn, $pageUrl);

    $sql = "
        SELECT rsa.can_approve
        FROM user_roles ur
        INNER JOIN role_sidebar_access rsa ON rsa.role_id = ur.role_id
        INNER JOIN sidebar_menus sm ON sm.id = rsa.menu_id
        WHERE ur.user_id = $userId
          AND sm.menu_url = '$escapedPageUrl'
          AND sm.is_active = 1
        LIMIT 1
    ";

    $q = mysqli_query($conn, $sql);

    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return (int)$row["can_approve"] === 1;
    }

    return false;
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Attendance updated successfully.";
} elseif (isset($_GET["pending"])) {
    $pageMessageType = "success";
    $pageMessageText = "Other location punch-in request submitted for approval.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$employeeId = get_login_employee_id($conn);
$today = date("Y-m-d");
$canApproveAttendance = user_has_attendance_approve_access($conn);

$employee = null;
if ($employeeId > 0) {
    $stmt = mysqli_prepare($conn, "
        SELECT e.*, ol.location_name AS office_location_name, ol.location_code AS office_location_code,
               ol.address AS office_address, ol.city AS office_city, ol.state AS office_state,
               ol.latitude AS office_latitude, ol.longitude AS office_longitude, ol.location_radius AS office_location_radius
        FROM employees e
        LEFT JOIN master_office_locations ol ON ol.id = e.office_location_id
        WHERE e.id = ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "i", $employeeId);
    mysqli_stmt_execute($stmt);
    $employee = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

if (!$employee) {
    $pageMessageType = "error";
    $pageMessageText = "Employee profile not linked with this user account.";
}

$todayAttendance = null;
if ($employeeId > 0 && table_exists($conn, "attendance_records")) {
    $stmt = mysqli_prepare($conn, "
        SELECT ar.*, ol.location_name AS office_location_name, p.project_name, p.project_code
        FROM attendance_records ar
        LEFT JOIN master_office_locations ol ON ol.id = ar.office_location_id
        LEFT JOIN projects p ON p.id = ar.project_id
        WHERE ar.employee_id = ? AND ar.attendance_date = ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "is", $employeeId, $today);
    mysqli_stmt_execute($stmt);
    $todayAttendance = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

$pendingOtherRequest = null;
if ($employeeId > 0 && table_exists($conn, "attendance_other_location_requests")) {
    $stmt = mysqli_prepare($conn, "
        SELECT *
        FROM attendance_other_location_requests
        WHERE employee_id = ? AND attendance_date = ? AND status = 'pending'
        ORDER BY id DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "is", $employeeId, $today);
    mysqli_stmt_execute($stmt);
    $pendingOtherRequest = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

$officeLocations = [];
if (table_exists($conn, "master_office_locations")) {
    $q = mysqli_query($conn, "
        SELECT id, location_name, location_code, address, city, state, latitude, longitude, location_radius, is_head_office
        FROM master_office_locations
        WHERE is_active = 1
        ORDER BY is_head_office DESC, sort_order ASC, location_name ASC
    ");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $officeLocations[] = $row;
        }
    }
}

$siteProjects = [];
if ($employeeId > 0 && table_exists($conn, "project_assignments")) {
    $q = mysqli_query($conn, "
        SELECT p.id, p.project_name, p.project_code, p.project_location, p.latitude, p.longitude, p.location_radius
        FROM project_assignments pa
        INNER JOIN projects p ON p.id = pa.project_id
        WHERE pa.employee_id = " . (int)$employeeId . "
          AND pa.status = 'active'
          AND p.deleted_at IS NULL
        ORDER BY p.project_name ASC
    ");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $siteProjects[] = $row;
        }
    }
}

$recentAttendances = [];
if ($employeeId > 0 && table_exists($conn, "attendance_records")) {
    $stmt = mysqli_prepare($conn, "
        SELECT ar.*, ol.location_name AS office_location_name, p.project_name, p.project_code
        FROM attendance_records ar
        LEFT JOIN master_office_locations ol ON ol.id = ar.office_location_id
        LEFT JOIN projects p ON p.id = ar.project_id
        WHERE ar.employee_id = ?
        ORDER BY ar.attendance_date DESC, ar.id DESC
        LIMIT 10
    ");
    mysqli_stmt_bind_param($stmt, "i", $employeeId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $recentAttendances[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$officeJson = json_encode($officeLocations, JSON_HEX_APOS | JSON_HEX_QUOT);
$siteJson = json_encode($siteProjects, JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Attendance - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>

    <style>
        .page-head-card,
        .attendance-card,
        .map-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px;
        }

        .attendance-action-card {
            height: 100%;
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            padding: 18px;
            background: rgba(148, 163, 184, .06);
            transition: .2s;
        }

        .attendance-action-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-card);
        }

        .action-icon {
            width: 52px;
            height: 52px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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

        .status-pill.green { background: rgba(16,185,129,.15); color:#059669; }
        .status-pill.blue { background: rgba(37,99,235,.15); color:#2563eb; }
        .status-pill.amber { background: rgba(245,158,11,.15); color:#d97706; }
        .status-pill.red { background: rgba(239,68,68,.15); color:#dc2626; }

        .location-preview {
            border: 1px solid var(--border-soft);
            background: rgba(37, 99, 235, .06);
            border-radius: 18px;
            padding: 12px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
        }

        .attendance-map {
            height: 330px;
            border-radius: 18px;
            border: 1px solid var(--border-soft);
            background: rgba(148, 163, 184, .10);
            overflow: hidden;
        }

        .radius-info-box {
            border: 1px solid var(--border-soft);
            background: rgba(245, 158, 11, .08);
            border-radius: 18px;
            padding: 12px;
            font-size: 12px;
            font-weight: 800;
            color: var(--text-muted);
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
                            <h1 class="h4 fw-bold mb-1">Attendance</h1>
                            <p class="text-muted-custom mb-0 small">
                                Office and site punch-ins show map, radius meter, your GPS distance, and other location approval flow.
                            </p>
                        </div>

                        <?php if ($canApproveAttendance): ?>
                            <a href="attendance-approvals.php" class="btn btn-outline-primary rounded-4 fw-bold btn-sm px-3">
                                <i data-lucide="clipboard-check" style="width:15px;height:15px;"></i> Other Location Approvals
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <section class="attendance-card mb-3">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                        <div>
                            <p class="text-muted-custom small fw-bold mb-1">Today</p>
                            <h2 class="fw-bold h5 mb-2"><?= date("d M Y") ?></h2>

                            <?php if ($todayAttendance): ?>
                                <span class="status-pill <?= status_class($todayAttendance["approval_status"]) ?>">
                                    <?= e($todayAttendance["approval_status"]) ?>
                                </span>
                                <span class="status-pill blue ms-1"><?= e($todayAttendance["location_type"]) ?></span>
                                <?php if (!empty($todayAttendance["allowed_radius_meters"])): ?>
                                    <span class="status-pill amber ms-1">Radius <?= (int)$todayAttendance["allowed_radius_meters"] ?>m</span>
                                <?php endif; ?>
                            <?php elseif ($pendingOtherRequest): ?>
                                <span class="status-pill amber">Other Location Pending</span>
                            <?php else: ?>
                                <span class="status-pill red">Not Punched In</span>
                            <?php endif; ?>
                        </div>

                        <div class="text-lg-end">
                            <p class="text-muted-custom small fw-bold mb-1">Punch In</p>
                            <h3 class="fw-bold h5 mb-1"><?= $todayAttendance ? e(time_show($todayAttendance["punch_in_at"])) : "-" ?></h3>
                            <p class="text-muted-custom small mb-0">Punch Out: <?= $todayAttendance ? e(time_show($todayAttendance["punch_out_at"])) : "-" ?></p>
                        </div>
                    </div>

                    <?php if ($todayAttendance): ?>
                        <hr>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <p class="text-muted-custom small fw-bold mb-1">Location</p>
                                <p class="fw-bold mb-0">
                                    <?php if ($todayAttendance["location_type"] === "office"): ?>
                                        <?= e($todayAttendance["office_location_name"] ?: "Office") ?>
                                    <?php elseif ($todayAttendance["location_type"] === "site"): ?>
                                        <?= e($todayAttendance["project_name"] ?: "Site") ?>
                                    <?php else: ?>
                                        Other Location
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="col-md-3">
                                <p class="text-muted-custom small fw-bold mb-1">Radius / Distance</p>
                                <p class="fw-bold mb-0">
                                    Radius: <?= !empty($todayAttendance["allowed_radius_meters"]) ? (int)$todayAttendance["allowed_radius_meters"] . "m" : "-" ?><br>
                                    In Distance: <?= $todayAttendance["distance_meters"] !== null ? round((float)$todayAttendance["distance_meters"]) . "m" : "-" ?><br>
                                    Out Distance: <?= isset($todayAttendance["punch_out_distance_meters"]) && $todayAttendance["punch_out_distance_meters"] !== null ? round((float)$todayAttendance["punch_out_distance_meters"]) . "m" : "-" ?>
                                </p>
                            </div>

                            <div class="col-md-6">
                                <p class="text-muted-custom small fw-bold mb-1">Address</p>
                                <p class="fw-semibold small mb-0"><?= e($todayAttendance["address"] ?: "-") ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="attendance-action-card">
                            <div class="action-icon bg-success-subtle text-success mb-3">
                                <i data-lucide="building-2"></i>
                            </div>
                            <h3 class="fw-bold fs-6 mb-1">Office Punch In</h3>
                            <p class="text-muted-custom small fw-semibold">Select office, show map circle, and punch in only inside radius.</p>
                            <button type="button"
                                class="btn btn-outline-success rounded-4 fw-bold w-100"
                                data-bs-toggle="modal"
                                data-bs-target="#officePunchModal"
                                onclick="startPunchModal('office')"
                                <?= ($todayAttendance || $pendingOtherRequest || !$employee) ? "disabled" : "" ?>>
                                Office Punch In
                            </button>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="attendance-action-card">
                            <div class="action-icon text-white mb-3" style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                                <i data-lucide="map-pin"></i>
                            </div>
                            <h3 class="fw-bold fs-6 mb-1">Site Punch In</h3>
                            <p class="text-muted-custom small fw-semibold">Select assigned project, view site radius, and punch in inside radius.</p>
                            <button type="button"
                                class="btn btn-outline-primary rounded-4 fw-bold w-100"
                                data-bs-toggle="modal"
                                data-bs-target="#sitePunchModal"
                                onclick="startPunchModal('site')"
                                <?= ($todayAttendance || $pendingOtherRequest || !$employee) ? "disabled" : "" ?>>
                                Site Punch In
                            </button>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="attendance-action-card">
                            <div class="action-icon bg-warning-subtle text-warning mb-3">
                                <i data-lucide="navigation"></i>
                            </div>
                            <h3 class="fw-bold fs-6 mb-1">Other Location</h3>
                            <p class="text-muted-custom small fw-semibold">Shows your location on map and sends reason for approval.</p>
                            <button type="button"
                                class="btn btn-outline-warning rounded-4 fw-bold w-100"
                                data-bs-toggle="modal"
                                data-bs-target="#otherPunchModal"
                                onclick="startPunchModal('other')"
                                <?= ($todayAttendance || $pendingOtherRequest || !$employee) ? "disabled" : "" ?>>
                                Request Other Punch In
                            </button>
                        </div>
                    </div>
                </div>

                <?php if ($todayAttendance && empty($todayAttendance["punch_out_at"])): ?>
                    <section class="attendance-card mb-3">
                        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-2">
                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Punch Out</h2>
                                <p class="text-muted-custom small mb-0">Get current location and close today's attendance.</p>
                            </div>

                            <button type="button"
                                class="btn btn-danger rounded-4 fw-bold px-4"
                                data-bs-toggle="modal"
                                data-bs-target="#punchOutModal"
                                onclick="startPunchOutModal()">
                                Punch Out
                            </button>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4">
                        <h2 class="fw-bold fs-6 mb-1">Recent Attendance</h2>
                        <p class="text-muted-custom small mb-0">Last 10 attendance entries with radius and distance.</p>
                    </div>

                    <div class="overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                        <table class="project-table w-100">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Punch In</th>
                                    <th>Punch Out</th>
                                    <th>Radius / Distance</th>
                                    <th>Status</th>
                                    <th>Location</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentAttendances as $row): ?>
                                    <tr>
                                        <td class="fw-bold"><?= e(date_show($row["attendance_date"])) ?></td>
                                        <td><span class="status-pill blue"><?= e($row["location_type"]) ?></span></td>
                                        <td><?= e(time_show($row["punch_in_at"])) ?></td>
                                        <td><?= e(time_show($row["punch_out_at"])) ?></td>
                                        <td>
                                            <span class="status-pill amber">R: <?= !empty($row["allowed_radius_meters"]) ? (int)$row["allowed_radius_meters"] . "m" : "-" ?></span><br>
                                            <small class="text-muted-custom">In: <?= $row["distance_meters"] !== null ? round((float)$row["distance_meters"]) . "m" : "-" ?></small><br>
                                            <small class="text-muted-custom">Out: <?= isset($row["punch_out_distance_meters"]) && $row["punch_out_distance_meters"] !== null ? round((float)$row["punch_out_distance_meters"]) . "m" : "-" ?></small>
                                        </td>
                                        <td><span class="status-pill <?= status_class($row["approval_status"]) ?>"><?= e($row["approval_status"]) ?></span></td>
                                        <td>
                                            <?php if ($row["location_type"] === "office"): ?>
                                                <?= e($row["office_location_name"] ?: "Office") ?>
                                            <?php elseif ($row["location_type"] === "site"): ?>
                                                <?= e($row["project_name"] ?: "Site") ?>
                                            <?php else: ?>
                                                <?= e($row["address"] ?: "Other Location") ?>
                                            <?php endif; ?>
                                            <?php if (!empty($row["latitude"]) && !empty($row["longitude"])): ?>
                                                <br><a target="_blank" class="small fw-bold" href="https://www.google.com/maps?q=<?= e($row["latitude"]) ?>,<?= e($row["longitude"]) ?>">Open Map</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($recentAttendances)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted-custom py-4">No attendance found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include("includes/rightsidbar.php"); ?>
    </div>

    <div class="modal fade" id="punchOutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="POST" action="api/process-attendance.php" class="modal-content" id="punchOutForm">
                <input type="hidden" name="action" value="punch_out">
                <input type="hidden" name="latitude" id="punchout_latitude">
                <input type="hidden" name="longitude" id="punchout_longitude">
                <input type="hidden" name="address" id="punchout_address">
                <input type="hidden" name="target_latitude" id="punchout_target_latitude">
                <input type="hidden" name="target_longitude" id="punchout_target_longitude">
                <input type="hidden" name="allowed_radius_meters" id="punchout_allowed_radius_meters">
                <input type="hidden" name="distance_meters" id="punchout_distance_meters">

                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Punch Out</h5>
                        <p class="text-muted-custom small mb-0">Your current marker must be inside the same attendance radius.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="attendance-map mb-3" id="punchout_map"></div>
                    <div class="radius-info-box" id="punchout_radius_info">Getting current location...</div>
                </div>

                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-4 fw-bold px-4">Confirm Punch Out</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="officePunchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="POST" action="api/process-attendance.php" class="modal-content">
                <input type="hidden" name="action" value="office_punch_in">
                <input type="hidden" name="latitude" id="office_latitude">
                <input type="hidden" name="longitude" id="office_longitude">
                <input type="hidden" name="address" id="office_address">
                <input type="hidden" name="target_latitude" id="office_target_latitude">
                <input type="hidden" name="target_longitude" id="office_target_longitude">
                <input type="hidden" name="allowed_radius_meters" id="office_allowed_radius_meters">
                <input type="hidden" name="distance_meters" id="office_distance_meters">

                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Office Punch In</h5>
                        <p class="text-muted-custom small mb-0">Your marker must be inside office radius circle.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <label class="form-label fw-bold small">Office Location <span class="text-danger">*</span></label>
                    <select name="office_location_id" id="office_location_id" class="form-select rounded-4 mb-3" required onchange="refreshSelectedTarget('office')">
                        <option value="">Select Office</option>
                        <?php foreach ($officeLocations as $office): ?>
                            <option value="<?= (int)$office["id"] ?>" <?= ((int)($employee["office_location_id"] ?? 0) === (int)$office["id"]) ? "selected" : "" ?>>
                                <?= e($office["location_name"]) ?> <?= $office["location_code"] ? "(" . e($office["location_code"]) . ")" : "" ?>
                                <?= $office["city"] ? "- " . e($office["city"]) : "" ?>
                                <?= !empty($office["location_radius"]) ? "- Radius " . (int)$office["location_radius"] . "m" : "" ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="attendance-map mb-3" id="office_map"></div>
                    <div class="radius-info-box" id="office_radius_info">Getting current location...</div>
                </div>

                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Punch In</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="sitePunchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="POST" action="api/process-attendance.php" class="modal-content">
                <input type="hidden" name="action" value="site_punch_in">
                <input type="hidden" name="latitude" id="site_latitude">
                <input type="hidden" name="longitude" id="site_longitude">
                <input type="hidden" name="address" id="site_address">
                <input type="hidden" name="target_latitude" id="site_target_latitude">
                <input type="hidden" name="target_longitude" id="site_target_longitude">
                <input type="hidden" name="allowed_radius_meters" id="site_allowed_radius_meters">
                <input type="hidden" name="distance_meters" id="site_distance_meters">

                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Site Punch In</h5>
                        <p class="text-muted-custom small mb-0">Your marker must be inside project radius circle.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <label class="form-label fw-bold small">Project Site <span class="text-danger">*</span></label>
                    <select name="project_id" id="site_project_id" class="form-select rounded-4 mb-3" required onchange="refreshSelectedTarget('site')">
                        <option value="">Select Site</option>
                        <?php foreach ($siteProjects as $project): ?>
                            <option value="<?= (int)$project["id"] ?>">
                                <?= e($project["project_name"]) ?> <?= $project["project_code"] ? "(" . e($project["project_code"]) . ")" : "" ?>
                                <?= $project["project_location"] ? "- " . e($project["project_location"]) : "" ?>
                                <?= !empty($project["location_radius"]) ? "- Radius " . (int)$project["location_radius"] . "m" : "" ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="attendance-map mb-3" id="site_map"></div>
                    <div class="radius-info-box" id="site_radius_info">Getting current location...</div>
                </div>

                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Punch In</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="otherPunchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="POST" action="api/process-attendance.php" class="modal-content">
                <input type="hidden" name="action" value="other_punch_request">
                <input type="hidden" name="latitude" id="other_latitude">
                <input type="hidden" name="longitude" id="other_longitude">
                <input type="hidden" name="address" id="other_address">
                <input type="hidden" name="target_latitude" id="other_target_latitude">
                <input type="hidden" name="target_longitude" id="other_target_longitude">
                <input type="hidden" name="allowed_radius_meters" id="other_allowed_radius_meters">
                <input type="hidden" name="distance_meters" id="other_distance_meters">

                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Other Location Punch In Request</h5>
                        <p class="text-muted-custom small mb-0">Your current location map is attached for approval.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="attendance-map mb-3" id="other_map"></div>
                    <div class="radius-info-box mb-3" id="other_radius_info">Getting current location...</div>

                    <label class="form-label fw-bold small">Reason <span class="text-danger">*</span></label>
                    <textarea name="reason" rows="4" class="form-control rounded-4" required
                        placeholder="Explain why you are punching in from other location"></textarea>
                </div>

                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=90"></script>

    <script>
        const officeLocations = <?= $officeJson ?: "[]" ?>;
        const siteProjects = <?= $siteJson ?: "[]" ?>;
        const todayAttendanceData = <?= json_encode($todayAttendance ?: [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        const mapState = {
            office: { map: null, targetMarker: null, userMarker: null, circle: null, current: null },
            site: { map: null, targetMarker: null, userMarker: null, circle: null, current: null },
            other: { map: null, targetMarker: null, userMarker: null, circle: null, current: null },
            punchout: { map: null, targetMarker: null, userMarker: null, circle: null, current: null }
        };

        function byId(id) {
            return document.getElementById(id);
        }

        function getDistanceMeters(lat1, lng1, lat2, lng2) {
            const R = 6371000;
            const toRad = d => d * Math.PI / 180;
            const dLat = toRad(lat2 - lat1);
            const dLng = toRad(lng2 - lng1);
            const a =
                Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);

            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        }

        function setHiddenFields(type, currentLat, currentLng, address, targetLat, targetLng, radius, distance) {
            if (byId(type + "_latitude")) byId(type + "_latitude").value = currentLat || "";
            if (byId(type + "_longitude")) byId(type + "_longitude").value = currentLng || "";
            if (byId(type + "_address")) byId(type + "_address").value = address || "";
            if (byId(type + "_target_latitude")) byId(type + "_target_latitude").value = targetLat || "";
            if (byId(type + "_target_longitude")) byId(type + "_target_longitude").value = targetLng || "";
            if (byId(type + "_allowed_radius_meters")) byId(type + "_allowed_radius_meters").value = radius || "";
            if (byId(type + "_distance_meters")) byId(type + "_distance_meters").value = distance ? Math.round(distance) : "";
        }

        function selectedOffice() {
            const id = parseInt(byId("office_location_id")?.value || "0", 10);
            return officeLocations.find(x => parseInt(x.id, 10) === id) || null;
        }

        function selectedSite() {
            const id = parseInt(byId("site_project_id")?.value || "0", 10);
            return siteProjects.find(x => parseInt(x.id, 10) === id) || null;
        }

        function getTarget(type) {
            if (type === "office") {
                const row = selectedOffice();
                if (!row) return null;

                return {
                    name: row.location_name || "Office",
                    lat: parseFloat(row.latitude),
                    lng: parseFloat(row.longitude),
                    radius: parseInt(row.location_radius || "100", 10) || 100,
                    address: row.address || ""
                };
            }

            if (type === "site") {
                const row = selectedSite();
                if (!row) return null;

                return {
                    name: row.project_name || "Site",
                    lat: parseFloat(row.latitude),
                    lng: parseFloat(row.longitude),
                    radius: parseInt(row.location_radius || "100", 10) || 100,
                    address: row.project_location || ""
                };
            }

            if (type === "punchout") {
                if (!todayAttendanceData || !todayAttendanceData.id) return null;

                const lat = parseFloat(todayAttendanceData.target_latitude || "");
                const lng = parseFloat(todayAttendanceData.target_longitude || "");
                const radius = parseInt(todayAttendanceData.allowed_radius_meters || "0", 10);

                if (!Number.isFinite(lat) || !Number.isFinite(lng) || !radius) {
                    return null;
                }

                let name = "Punch Out Location";
                if (todayAttendanceData.location_type === "office") {
                    name = todayAttendanceData.office_location_name || "Office";
                } else if (todayAttendanceData.location_type === "site") {
                    name = todayAttendanceData.project_name || "Site";
                } else {
                    name = "Other Location";
                }

                return {
                    name: name,
                    lat: lat,
                    lng: lng,
                    radius: radius,
                    address: todayAttendanceData.address || ""
                };
            }

            return null;
        }

        function initPunchMap(type) {
            const mapEl = byId(type + "_map");
            if (!mapEl || typeof google === "undefined" || !google.maps) return;

            if (!mapState[type].map) {
                mapState[type].map = new google.maps.Map(mapEl, {
                    center: { lat: 20.5937, lng: 78.9629 },
                    zoom: 5,
                    mapTypeControl: true,
                    streetViewControl: false,
                    fullscreenControl: true,
                    mapTypeId: google.maps.MapTypeId.ROADMAP
                });
            }

            setTimeout(() => {
                google.maps.event.trigger(mapState[type].map, "resize");
                renderPunchMap(type);
            }, 200);
        }

        function renderPunchMap(type) {
            const state = mapState[type];
            if (!state.map || typeof google === "undefined") return;

            if (state.targetMarker) state.targetMarker.setMap(null);
            if (state.userMarker) state.userMarker.setMap(null);
            if (state.circle) state.circle.setMap(null);

            const target = getTarget(type);
            const current = state.current;

            const bounds = new google.maps.LatLngBounds();
            let hasBounds = false;

            if (target && Number.isFinite(target.lat) && Number.isFinite(target.lng)) {
                const targetPos = { lat: target.lat, lng: target.lng };

                state.targetMarker = new google.maps.Marker({
                    position: targetPos,
                    map: state.map,
                    title: target.name,
                    label: "T"
                });

                state.circle = new google.maps.Circle({
                    map: state.map,
                    center: targetPos,
                    radius: target.radius,
                    fillColor: "#10b981",
                    fillOpacity: 0.12,
                    strokeColor: "#10b981",
                    strokeOpacity: 0.75,
                    strokeWeight: 2
                });

                bounds.extend(targetPos);
                hasBounds = true;
            }

            if (current && Number.isFinite(current.lat) && Number.isFinite(current.lng)) {
                const userPos = { lat: current.lat, lng: current.lng };

                state.userMarker = new google.maps.Marker({
                    position: userPos,
                    map: state.map,
                    title: "Your Current Location",
                    label: "Y",
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 8,
                        fillColor: "#2563eb",
                        fillOpacity: 1,
                        strokeColor: "#ffffff",
                        strokeWeight: 2
                    }
                });

                bounds.extend(userPos);
                hasBounds = true;
            }

            if (hasBounds) {
                state.map.fitBounds(bounds);
                if (target && current) {
                    setTimeout(() => {
                        if (state.map.getZoom() > 18) state.map.setZoom(18);
                    }, 300);
                } else {
                    setTimeout(() => {
                        if (state.map.getZoom() > 16) state.map.setZoom(16);
                    }, 300);
                }
            }

            updateRadiusInfo(type);
        }

        function updateRadiusInfo(type) {
            const info = byId(type + "_radius_info");
            if (!info) return;

            const current = mapState[type].current;
            const target = getTarget(type);

            if (type === "punchout") {
                if (!target) {
                    if (!current) {
                        info.textContent = "Getting current location...";
                        return;
                    }

                    info.innerHTML = `
                        <div><b>Your Punch Out Location:</b> ${current.lat.toFixed(8)}, ${current.lng.toFixed(8)}</div>
                        <div><b>Address:</b> ${current.address || "Address not available"}</div>
                        <div><b>Status:</b> No radius target found for this attendance. Punch out will save current location.</div>
                    `;
                    setHiddenFields(type, current.lat.toFixed(8), current.lng.toFixed(8), current.address || "", "", "", "", "");
                    return;
                }

                if (!current) {
                    info.innerHTML = `<b>${target.name}</b><br>Allowed Radius: ${target.radius}m<br>Getting your current GPS location...`;
                    return;
                }

                const distance = getDistanceMeters(target.lat, target.lng, current.lat, current.lng);
                const inside = distance <= target.radius;

                info.innerHTML = `
                    <div><b>${target.name}</b></div>
                    <div><b>Allowed Radius:</b> ${target.radius}m</div>
                    <div><b>Your Punch Out Distance:</b> ${Math.round(distance)}m</div>
                    <div><b>Status:</b> <span class="${inside ? "text-success" : "text-danger"}">${inside ? "Inside radius - punch-out allowed" : "Outside radius - punch-out will be blocked"}</span></div>
                    <div><b>Your Address:</b> ${current.address || "Address not available"}</div>
                `;

                setHiddenFields(
                    type,
                    current.lat.toFixed(8),
                    current.lng.toFixed(8),
                    current.address || "",
                    target.lat.toFixed(8),
                    target.lng.toFixed(8),
                    target.radius,
                    distance
                );
                return;
            }

            if (type === "other") {
                if (!current) {
                    info.textContent = "Getting current location...";
                    return;
                }

                info.innerHTML = `
                    <div><b>Your Location:</b> ${current.lat.toFixed(8)}, ${current.lng.toFixed(8)}</div>
                    <div><b>Address:</b> ${current.address || "Address not available"}</div>
                    <div><b>Status:</b> Other location requires higher-person approval.</div>
                `;
                setHiddenFields(type, current.lat.toFixed(8), current.lng.toFixed(8), current.address || "", "", "", "", "");
                return;
            }

            if (!target) {
                info.textContent = type === "office" ? "Select office location." : "Select project site.";
                return;
            }

            if (!Number.isFinite(target.lat) || !Number.isFinite(target.lng)) {
                info.innerHTML = `<b>${target.name}</b><br>Latitude/longitude not set. Please update location master/project location.`;
                return;
            }

            if (!current) {
                info.innerHTML = `<b>${target.name}</b><br>Allowed Radius: ${target.radius}m<br>Getting your current GPS location...`;
                return;
            }

            const distance = getDistanceMeters(target.lat, target.lng, current.lat, current.lng);
            const inside = distance <= target.radius;

            info.innerHTML = `
                <div><b>${target.name}</b></div>
                <div><b>Allowed Radius:</b> ${target.radius}m</div>
                <div><b>Your Distance:</b> ${Math.round(distance)}m</div>
                <div><b>Status:</b> <span class="${inside ? "text-success" : "text-danger"}">${inside ? "Inside radius - punch-in allowed" : "Outside radius - punch-in will be blocked"}</span></div>
                <div><b>Your Address:</b> ${current.address || "Address not available"}</div>
            `;

            setHiddenFields(
                type,
                current.lat.toFixed(8),
                current.lng.toFixed(8),
                current.address || "",
                target.lat.toFixed(8),
                target.lng.toFixed(8),
                target.radius,
                distance
            );
        }

        function reverseGeocodeCurrent(type, lat, lng) {
            if (typeof google === "undefined" || !google.maps) {
                mapState[type].current = { lat, lng, address: "" };
                renderPunchMap(type);
                return;
            }

            const geocoder = new google.maps.Geocoder();
            geocoder.geocode({
                location: { lat: parseFloat(lat), lng: parseFloat(lng) }
            }, function (results, status) {
                const address = (status === "OK" && results[0]) ? results[0].formatted_address : "";
                mapState[type].current = { lat: parseFloat(lat), lng: parseFloat(lng), address };
                renderPunchMap(type);
            });
        }

        function startPunchModal(type) {
            initPunchMap(type);
            refreshSelectedTarget(type);

            const info = byId(type + "_radius_info");
            if (info) info.textContent = "Getting current location...";

            if (!navigator.geolocation) {
                if (info) info.textContent = "Geolocation is not supported by this browser.";
                return;
            }

            navigator.geolocation.getCurrentPosition(
                function (position) {
                    reverseGeocodeCurrent(type, position.coords.latitude, position.coords.longitude);
                },
                function (error) {
                    let msg = "Unable to get current location. ";
                    if (error.code === error.PERMISSION_DENIED) msg += "Please allow location permission.";
                    else if (error.code === error.POSITION_UNAVAILABLE) msg += "Location unavailable.";
                    else if (error.code === error.TIMEOUT) msg += "Location request timed out.";

                    if (info) info.textContent = msg;
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        }

        function refreshSelectedTarget(type) {
            initPunchMap(type);
            renderPunchMap(type);
        }

        function startPunchOutModal() {
            initPunchMap("punchout");
            refreshSelectedTarget("punchout");

            const info = byId("punchout_radius_info");
            if (info) info.textContent = "Getting current location...";

            if (!navigator.geolocation) {
                if (info) info.textContent = "Geolocation is not supported by this browser.";
                return;
            }

            navigator.geolocation.getCurrentPosition(
                function (position) {
                    reverseGeocodeCurrent("punchout", position.coords.latitude, position.coords.longitude);
                },
                function (error) {
                    let msg = "Unable to get current location. ";
                    if (error.code === error.PERMISSION_DENIED) msg += "Please allow location permission.";
                    else if (error.code === error.POSITION_UNAVAILABLE) msg += "Location unavailable.";
                    else if (error.code === error.TIMEOUT) msg += "Location request timed out.";

                    if (info) info.textContent = msg;
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        }

        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>

    <script
        src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCyBiTiehtlXq0UxU-CTy_odcLF33eekBE&libraries=places,geometry"
        async
        defer>
    </script>
</body>

</html>
