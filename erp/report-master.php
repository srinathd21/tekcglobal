<?php
require_once __DIR__ . "/includes/db.php";

require_permission($conn, "can_view", "report-master.php");

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function report_master_permission($conn, $permissionColumn, $pageUrl = "report-master.php")
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

function frequency_show($type, $days)
{
    if ($type === "custom_days") {
        return "Every " . (int)$days . " days";
    }

    return ucwords(str_replace("_", " ", $type));
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Report saved successfully.";
} elseif (isset($_GET["access"])) {
    $pageMessageType = "success";
    $pageMessageText = "Report role access updated successfully.";
} elseif (isset($_GET["deleted"])) {
    $pageMessageType = "success";
    $pageMessageText = "Report disabled successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$canReportCreate = report_master_permission($conn, "can_create", "report-master.php");
$canReportEdit = report_master_permission($conn, "can_edit", "report-master.php");
$canReportDelete = report_master_permission($conn, "can_delete", "report-master.php");

$search = trim($_GET["search"] ?? "");
$frequencyFilter = trim($_GET["frequency"] ?? "");
$statusFilter = trim($_GET["status"] ?? "");

$where = ["1=1"];

if ($search !== "") {
    $safeSearch = mysqli_real_escape_string($conn, $search);
    $where[] = "(
        report_name LIKE '%$safeSearch%'
        OR report_code LIKE '%$safeSearch%'
        OR submit_file_name LIKE '%$safeSearch%'
        OR print_file_name LIKE '%$safeSearch%'
    )";
}

if ($frequencyFilter !== "" && in_array($frequencyFilter, ["daily", "weekly", "monthly", "custom_days", "on_demand"], true)) {
    $where[] = "frequency_type = '" . mysqli_real_escape_string($conn, $frequencyFilter) . "'";
}

if ($statusFilter !== "" && in_array($statusFilter, ["active", "inactive"], true)) {
    $where[] = "is_active = " . ($statusFilter === "active" ? "1" : "0");
}

$whereSql = implode(" AND ", $where);

$reportsQ = mysqli_query($conn, "
    SELECT *
    FROM master_report_types
    WHERE $whereSql
    ORDER BY sort_order ASC, id DESC
");

$reports = [];
while ($r = mysqli_fetch_assoc($reportsQ)) {
    $reports[] = $r;
}

$rolesQ = mysqli_query($conn, "SELECT id, role_name, role_slug FROM roles WHERE is_active = 1 ORDER BY role_name ASC");

$roleRows = [];
while ($role = mysqli_fetch_assoc($rolesQ)) {
    $roleRows[] = $role;
}

$accessQ = mysqli_query($conn, "SELECT report_type_id, role_id, can_submit, can_view, can_remark_tl, can_remark_manager FROM report_type_role_access");
$reportAccess = [];

while ($a = mysqli_fetch_assoc($accessQ)) {
    $reportAccess[(int)$a["report_type_id"]][(int)$a["role_id"]] = $a;
}

$stats = [
    "total" => 0,
    "active" => 0,
    "daily" => 0,
    "weekly" => 0,
];

$statsQ = mysqli_query($conn, "SELECT frequency_type, is_active FROM master_report_types");
while ($s = mysqli_fetch_assoc($statsQ)) {
    $stats["total"]++;
    if ((int)$s["is_active"] === 1) {
        $stats["active"]++;
    }
    if ($s["frequency_type"] === "daily") {
        $stats["daily"]++;
    }
    if ($s["frequency_type"] === "weekly") {
        $stats["weekly"]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Report Master - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>
    <style>
        .page-head-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
        .kpi-row>[class*="col"]{display:flex}.kpi-card{width:100%;height:100%;min-height:118px;background:var(--card-bg);border:1px solid var(--border-soft);border-radius:24px;box-shadow:var(--shadow-card);padding:22px 24px;display:flex;align-items:center;gap:22px}.kpi-icon{width:58px;height:58px;min-width:58px;border-radius:22px;display:inline-flex;align-items:center;justify-content:center}.kpi-label{color:var(--text-muted);font-size:13px;font-weight:800;display:flex;align-items:center;gap:4px;white-space:nowrap}.kpi-value{color:var(--text-main);font-size:24px;font-weight:900;margin:4px 0 2px;line-height:1.15}.kpi-sub{color:var(--text-muted);font-size:12px;font-weight:600;margin:0}.kpi-sub span{color:#008a5b;font-weight:900}
        .filter-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:14px}
        .report-code-pill,.file-pill,.frequency-pill{display:inline-flex;align-items:center;justify-content:center;width:fit-content;max-width:100%;border-radius:999px;padding:5px 12px;font-size:12px;line-height:1;font-weight:900;white-space:nowrap;vertical-align:middle}
        .report-code-pill{color:var(--text-main);background:rgba(148,163,184,.10);border:1px solid var(--border-soft)}
        .file-pill{color:#0f172a;background:rgba(148,163,184,.12);border:1px solid var(--border-soft)}
        .frequency-pill{color:#2563eb;background:rgba(37,99,235,.10);border:1px solid rgba(37,99,235,.18)}
        .modal-content{background:var(--card-bg);color:var(--text-main);border:1px solid var(--border-soft);border-radius:24px;box-shadow:var(--shadow-card)}.modal-header,.modal-footer{border-color:var(--border-soft)}
        .form-control,.form-select{background:var(--card-bg);color:var(--text-main);border-color:var(--border-soft);min-height:42px;font-size:13px;font-weight:600}.form-control:focus,.form-select:focus{color:var(--text-main);background:var(--card-bg);border-color:var(--brand-2);box-shadow:0 0 0 4px rgba(37,99,235,.12)}
        .permission-wrap{max-height:58vh;overflow:auto;border:1px solid #111827;border-radius:18px}
        .permission-table{border-collapse:separate;border-spacing:0}
        .permission-table th,.permission-table td{padding:10px 8px;font-size:12px;vertical-align:middle;border-bottom:1px solid #111827;border-right:1px solid #111827}
        .permission-table th:first-child,.permission-table td:first-child{border-left:1px solid #111827}
        .permission-table thead th{position:sticky;top:0;z-index:2;background:var(--card-bg);color:var(--text-main);font-size:11px;font-weight:900;text-transform:uppercase;border-top:1px solid #111827}
        .permission-table tbody tr:hover td{background:rgba(148,163,184,.10)}
        .access-check,.column-select-all{width:17px;height:17px;cursor:pointer;border:2px solid #111827!important;box-shadow:none!important}
        .access-check:checked,.column-select-all:checked{background-color:#111827!important;border-color:#111827!important}
        .access-tools{border:1px solid #111827;border-radius:18px;padding:10px 12px;margin-bottom:12px;background:rgba(148,163,184,.06)}
        .select-section-box{display:inline-flex;align-items:center;gap:7px;font-size:11px;font-weight:900;color:var(--text-main);white-space:nowrap}
        .delete-warning-box{border:1px solid rgba(239,68,68,.30);background:rgba(239,68,68,.08);border-radius:18px;padding:14px}
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
                            <h1 class="h4 fw-bold mb-1">Report Master</h1>
                            <p class="text-muted-custom mb-0 small">Create report configuration with file names, frequency, days/month/week rules, and role access.</p>
                        </div>
                        <?php if ($canReportCreate): ?>
                            <button type="button" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3" data-bs-toggle="modal" data-bs-target="#reportModal" onclick="openAddReportModal()">
                                <i data-lucide="plus" style="width:15px;height:15px;"></i> Create Report
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-3 mb-3 kpi-row">
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl"><article class="kpi-card"><div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);"><i data-lucide="file-text"></i></div><div><div class="kpi-label">Total Reports <i data-lucide="info" style="width:12px;height:12px;"></i></div><p class="kpi-value"><?= (int)$stats["total"] ?></p><p class="kpi-sub"><span>↑ <?= (int)$stats["total"] ?></span> report configs</p></div></article></div>
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl"><article class="kpi-card"><div class="kpi-icon bg-success-subtle text-success"><i data-lucide="check-circle-2"></i></div><div><div class="kpi-label">Active Reports <i data-lucide="info" style="width:12px;height:12px;"></i></div><p class="kpi-value"><?= (int)$stats["active"] ?></p><p class="kpi-sub"><span><?= (int)$stats["active"] ?></span> enabled reports</p></div></article></div>
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl"><article class="kpi-card"><div class="kpi-icon bg-warning-subtle text-warning"><i data-lucide="calendar-days"></i></div><div><div class="kpi-label">Daily Reports <i data-lucide="info" style="width:12px;height:12px;"></i></div><p class="kpi-value"><?= (int)$stats["daily"] ?></p><p class="kpi-sub"><span><?= (int)$stats["daily"] ?></span> daily submit</p></div></article></div>
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl"><article class="kpi-card"><div class="kpi-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);"><i data-lucide="calendar-range"></i></div><div><div class="kpi-label">Weekly Reports <i data-lucide="info" style="width:12px;height:12px;"></i></div><p class="kpi-value"><?= (int)$stats["weekly"] ?></p><p class="kpi-sub"><span><?= (int)$stats["weekly"] ?></span> weekly submit</p></div></article></div>
                </div>

                <form method="GET" class="filter-card mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-4"><label class="form-label fw-bold small">Search</label><input type="text" name="search" class="form-control rounded-4" value="<?= e($search) ?>" placeholder="Report, code, submit file, print file"></div>
                        <div class="col-12 col-sm-6 col-lg-3"><label class="form-label fw-bold small">Frequency</label><select name="frequency" class="form-select rounded-4"><option value="">All Frequency</option><option value="daily" <?= $frequencyFilter==="daily"?"selected":"" ?>>Daily</option><option value="weekly" <?= $frequencyFilter==="weekly"?"selected":"" ?>>Weekly</option><option value="monthly" <?= $frequencyFilter==="monthly"?"selected":"" ?>>Monthly</option><option value="custom_days" <?= $frequencyFilter==="custom_days"?"selected":"" ?>>Custom Days</option><option value="on_demand" <?= $frequencyFilter==="on_demand"?"selected":"" ?>>On Demand</option></select></div>
                        <div class="col-12 col-sm-6 col-lg-3"><label class="form-label fw-bold small">Status</label><select name="status" class="form-select rounded-4"><option value="">All Status</option><option value="active" <?= $statusFilter==="active"?"selected":"" ?>>Active</option><option value="inactive" <?= $statusFilter==="inactive"?"selected":"" ?>>Inactive</option></select></div>
                        <div class="col-12 col-lg-2 d-flex gap-2"><button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button><a href="report-master.php" class="btn btn-outline-secondary rounded-4 fw-bold">Reset</a></div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-3">
                        <div><h2 class="fw-bold fs-6 mb-1">Manage Report Configuration</h2><p class="text-muted-custom small mb-0">Only file names and role/frequency settings are saved. Report pages will be added separately.</p></div>
                    </div>

                    <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                        <table class="project-table w-100">
                            <thead><tr><th>Report</th><th>Submit File</th><th>Print File</th><th>Frequency</th><th>Remark Flow</th><th>Status</th><th style="width:260px;">Action</th></tr></thead>
                            <tbody>
                                <?php foreach ($reports as $r): ?>
                                    <tr>
                                        <td><div class="fw-bold"><?= e($r["report_name"]) ?></div><small class="report-code-pill"><?= e($r["report_code"]) ?></small></td>
                                        <td><span class="file-pill"><?= e($r["submit_file_name"]) ?></span></td>
                                        <td><span class="file-pill"><?= e($r["print_file_name"]) ?></span></td>
                                        <td><span class="frequency-pill"><?= e(frequency_show($r["frequency_type"], $r["custom_days"])) ?></span></td>
                                        <td><div class="small fw-bold">TL: <?= (int)$r["requires_tl_remark"] === 1 ? "Yes" : "No" ?></div><div class="small fw-bold">Manager: <?= (int)$r["requires_manager_remark"] === 1 ? "Yes" : "No" ?></div></td>
                                        <td><span class="pill <?= (int)$r["is_active"] === 1 ? "green" : "amber" ?>"><?= (int)$r["is_active"] === 1 ? "Active" : "Inactive" ?></span></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold" data-bs-toggle="modal" data-bs-target="#accessModal" onclick='openAccessModal(<?= (int)$r["id"] ?>, <?= json_encode($reportAccess[(int)$r["id"]] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($r["report_name"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Access</button>
                                            <?php if ($canReportEdit): ?><button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold" data-bs-toggle="modal" data-bs-target="#reportModal" onclick='openEditReportModal(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button><?php endif; ?>
                                            <?php if ($canReportDelete && (int)$r["is_active"] === 1): ?><button type="button" class="btn btn-sm btn-outline-danger rounded-4 fw-bold" data-bs-toggle="modal" data-bs-target="#deleteReportModal" onclick='openDeleteReportModal(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Disable</button><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($reports)): ?><tr><td colspan="7" class="text-center text-muted-custom py-4">No reports found.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3">
                        <?php foreach ($reports as $r): ?>
                            <article class="mobile-project-card">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-2"><div><p class="fw-bold small mb-1"><?= e($r["report_name"]) ?></p><small class="text-muted-custom"><?= e($r["report_code"]) ?></small></div><span class="pill <?= (int)$r["is_active"] === 1 ? "green" : "amber" ?>"><?= (int)$r["is_active"] === 1 ? "Active" : "Inactive" ?></span></div>
                                <div class="mobile-info"><span>Submit File</span><span><?= e($r["submit_file_name"]) ?></span></div>
                                <div class="mobile-info"><span>Print File</span><span><?= e($r["print_file_name"]) ?></span></div>
                                <div class="mobile-info"><span>Frequency</span><span><?= e(frequency_show($r["frequency_type"], $r["custom_days"])) ?></span></div>
                                <div class="mobile-info"><span>TL Remark</span><span><?= (int)$r["requires_tl_remark"] === 1 ? "Yes" : "No" ?></span></div>
                                <div class="mobile-info"><span>Manager Remark</span><span><?= (int)$r["requires_manager_remark"] === 1 ? "Yes" : "No" ?></span></div>
                                <div class="mt-3 d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold" data-bs-toggle="modal" data-bs-target="#accessModal" onclick='openAccessModal(<?= (int)$r["id"] ?>, <?= json_encode($reportAccess[(int)$r["id"]] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($r["report_name"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Access</button>
                                    <?php if ($canReportEdit): ?><button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold" data-bs-toggle="modal" data-bs-target="#reportModal" onclick='openEditReportModal(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button><?php endif; ?>
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

    <div class="modal fade" id="reportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form action="api/process-report-master.php" method="POST" class="modal-content">
                <input type="hidden" name="report_id" id="report_id">
                <div class="modal-header px-4"><div><h5 class="modal-title fw-bold" id="reportModalTitle">Create Report</h5><p class="text-muted-custom small mb-0">Set file names, frequency and remark flow.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body px-4">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label fw-bold small">Report Name</label><input type="text" name="report_name" id="report_name" class="form-control rounded-4" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold small">Report Code</label><input type="text" name="report_code" id="report_code" class="form-control rounded-4" placeholder="DAR" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold small">Submit File Name</label><input type="text" name="submit_file_name" id="submit_file_name" class="form-control rounded-4" placeholder="dar.php" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold small">Print File Name</label><input type="text" name="print_file_name" id="print_file_name" class="form-control rounded-4" placeholder="report-dar-print.php" required></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">Frequency</label><select name="frequency_type" id="frequency_type" class="form-select rounded-4" onchange="toggleCustomDays()"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="custom_days">Custom Days</option><option value="on_demand">On Demand</option></select></div>
                        <div class="col-md-4" id="custom_days_wrap"><label class="form-label fw-bold small">Custom Days</label><input type="number" name="custom_days" id="custom_days" class="form-control rounded-4" min="1"></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">Sort Order</label><input type="number" name="sort_order" id="sort_order" class="form-control rounded-4" value="0"></div>
                        <div class="col-12"><label class="form-label fw-bold small">Description</label><textarea name="description" id="description" rows="3" class="form-control rounded-4"></textarea></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">TL Remark?</label><select name="requires_tl_remark" id="requires_tl_remark" class="form-select rounded-4"><option value="1">Yes</option><option value="0">No</option></select></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">Manager Remark?</label><select name="requires_manager_remark" id="requires_manager_remark" class="form-select rounded-4"><option value="1">Yes</option><option value="0">No</option></select></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">Status</label><select name="is_active" id="is_active" class="form-select rounded-4"><option value="1">Active</option><option value="0">Inactive</option></select></div>
                    </div>
                </div>
                <div class="modal-footer px-4"><button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Save Report</button></div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="accessModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <form action="api/process-report-access.php" method="POST" class="modal-content">
                <input type="hidden" name="report_type_id" id="access_report_id">
                <div class="modal-header px-4"><div><h5 class="modal-title fw-bold">Report Role Access</h5><p class="text-muted-custom small mb-0" id="accessReportName">Configure report permissions.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body px-4">
                    <div class="permission-wrap thin-scrollbar">
                        <div class="access-tools d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-2">
                            <label class="select-section-box mb-0"><input type="checkbox" class="form-check-input column-select-all" id="selectAllAccess"> Select All Permissions</label>
                            <div class="d-flex flex-wrap gap-3">
                                <?php foreach (["can_submit" => "Submit", "can_view" => "View", "can_remark_tl" => "TL Remark", "can_remark_manager" => "Manager Remark"] as $perm => $label): ?>
                                    <label class="select-section-box mb-0"><input type="checkbox" class="form-check-input column-select-all" data-column-perm="<?= e($perm) ?>"> All <?= e($label) ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <table class="w-100 permission-table">
                            <thead><tr><th>Role</th><th class="text-center">Submit</th><th class="text-center">View</th><th class="text-center">TL Remark</th><th class="text-center">Manager Remark</th></tr></thead>
                            <tbody>
                                <?php foreach ($roleRows as $role): ?>
                                    <tr>
                                        <td><div class="fw-bold"><?= e($role["role_name"]) ?></div><small class="text-muted-custom"><?= e($role["role_slug"]) ?></small></td>
                                        <?php foreach (["can_submit", "can_view", "can_remark_tl", "can_remark_manager"] as $perm): ?>
                                            <td class="text-center"><input class="form-check-input access-check" type="checkbox" data-role-id="<?= (int)$role["id"] ?>" data-perm="<?= e($perm) ?>" name="access[<?= (int)$role["id"] ?>][<?= e($perm) ?>]" value="1"></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer px-4"><button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Save Access</button></div>
            </form>
        </div>
    </div>

    <?php if ($canReportDelete): ?>
    <div class="modal fade" id="deleteReportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form action="api/delete-report-master.php" method="POST" class="modal-content">
                <input type="hidden" name="report_id" id="delete_report_id">
                <div class="modal-header px-4"><div><h5 class="modal-title fw-bold">Disable Report</h5><p class="text-muted-custom small mb-0">This will make report inactive.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body px-4"><div class="delete-warning-box"><div class="d-flex gap-2 align-items-start"><i data-lucide="triangle-alert" class="text-danger" style="width:20px;height:20px;"></i><div><p class="fw-bold mb-1" id="delete_report_title">Disable this report?</p><p class="text-muted-custom small mb-0">Existing configuration stays in database, but this report will be hidden from active use.</p></div></div></div></div>
                <div class="modal-footer px-4"><button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger rounded-4 fw-bold px-4">Disable</button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=31"></script>
    <script>
        function toggleCustomDays() {
            const type = document.getElementById("frequency_type").value;
            const wrap = document.getElementById("custom_days_wrap");
            if (wrap) wrap.style.display = type === "custom_days" ? "block" : "none";
        }

        function openAddReportModal() {
            document.getElementById("reportModalTitle").textContent = "Create Report";
            ["report_id", "report_name", "report_code", "submit_file_name", "print_file_name", "description", "custom_days"].forEach(id => document.getElementById(id).value = "");
            document.getElementById("frequency_type").value = "daily";
            document.getElementById("sort_order").value = "0";
            document.getElementById("requires_tl_remark").value = "1";
            document.getElementById("requires_manager_remark").value = "1";
            document.getElementById("is_active").value = "1";
            toggleCustomDays();
        }

        function openEditReportModal(report) {
            document.getElementById("reportModalTitle").textContent = "Edit Report";
            document.getElementById("report_id").value = report.id || "";
            document.getElementById("report_name").value = report.report_name || "";
            document.getElementById("report_code").value = report.report_code || "";
            document.getElementById("submit_file_name").value = report.submit_file_name || "";
            document.getElementById("print_file_name").value = report.print_file_name || "";
            document.getElementById("frequency_type").value = report.frequency_type || "daily";
            document.getElementById("custom_days").value = report.custom_days || "";
            document.getElementById("sort_order").value = report.sort_order || "0";
            document.getElementById("description").value = report.description || "";
            document.getElementById("requires_tl_remark").value = report.requires_tl_remark || "1";
            document.getElementById("requires_manager_remark").value = report.requires_manager_remark || "1";
            document.getElementById("is_active").value = report.is_active || "1";
            toggleCustomDays();
        }

        function openDeleteReportModal(report) {
            const idInput = document.getElementById("delete_report_id");
            const title = document.getElementById("delete_report_title");
            if (idInput) idInput.value = report.id || "";
            if (title) title.textContent = "Disable " + (report.report_name || "this report") + "?";
        }

        function refreshAccessChecks() {
            ["can_submit", "can_view", "can_remark_tl", "can_remark_manager"].forEach(function (perm) {
                const columnBox = document.querySelector('.column-select-all[data-column-perm="' + perm + '"]');
                const checks = Array.from(document.querySelectorAll('.access-check[data-perm="' + perm + '"]'));
                const checked = checks.filter(function (input) { return input.checked; }).length;

                if (columnBox) {
                    columnBox.checked = checks.length > 0 && checked === checks.length;
                    columnBox.indeterminate = checked > 0 && checked < checks.length;
                }
            });

            const allBox = document.getElementById("selectAllAccess");
            const allChecks = Array.from(document.querySelectorAll(".access-check"));
            const allChecked = allChecks.filter(function (input) { return input.checked; }).length;

            if (allBox) {
                allBox.checked = allChecks.length > 0 && allChecked === allChecks.length;
                allBox.indeterminate = allChecked > 0 && allChecked < allChecks.length;
            }
        }

        function openAccessModal(reportId, access, reportName) {
            document.getElementById("access_report_id").value = reportId;
            document.getElementById("accessReportName").textContent = "Configure access for " + reportName;

            document.querySelectorAll(".access-check").forEach(function (input) {
                input.checked = false;
            });

            Object.keys(access || {}).forEach(function (roleId) {
                const row = access[roleId];
                ["can_submit", "can_view", "can_remark_tl", "can_remark_manager"].forEach(function (perm) {
                    const input = document.querySelector('.access-check[data-role-id="' + roleId + '"][data-perm="' + perm + '"]');
                    if (input && parseInt(row[perm] || 0) === 1) {
                        input.checked = true;
                    }
                });
            });

            refreshAccessChecks();
        }

        document.addEventListener("DOMContentLoaded", function () {
            const selectAllAccess = document.getElementById("selectAllAccess");

            if (selectAllAccess) {
                selectAllAccess.addEventListener("change", function () {
                    document.querySelectorAll(".access-check").forEach(function (input) {
                        input.checked = selectAllAccess.checked;
                    });
                    refreshAccessChecks();
                });
            }

            document.querySelectorAll(".column-select-all[data-column-perm]").forEach(function (columnBox) {
                columnBox.addEventListener("change", function () {
                    const perm = columnBox.dataset.columnPerm;
                    document.querySelectorAll('.access-check[data-perm="' + perm + '"]').forEach(function (input) {
                        input.checked = columnBox.checked;
                    });
                    refreshAccessChecks();
                });
            });

            document.querySelectorAll(".access-check").forEach(function (input) {
                input.addEventListener("change", refreshAccessChecks);
            });

            toggleCustomDays();
        });

        window.addEventListener("load", function () { if (window.lucide && typeof window.lucide.createIcons === "function") { window.lucide.createIcons(); } });
    </script>
</body>
</html>
