<?php
session_start();
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/leave-module-helper.php";

require_permission($conn, "can_create", "leave-requests.php");

function e($v) { return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8"); }
function dmy($date) { return ($date && $date !== "0000-00-00") ? date("d M Y", strtotime($date)) : "-"; }

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Leave request submitted successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) ?: "Something went wrong.";
}

$employeeId = lm_login_employee_id($conn);

$leaveTypes = [];
$q = mysqli_query($conn, "SELECT * FROM master_leave_types WHERE is_active = 1 ORDER BY sort_order ASC, leave_type_name ASC");
while ($q && ($row = mysqli_fetch_assoc($q))) {
    $leaveTypes[] = $row;
}

$projects = [];
if ($employeeId > 0) {
    $q = mysqli_query($conn, "
        SELECT p.id, p.project_name, p.project_code
        FROM project_assignments pa
        INNER JOIN projects p ON p.id = pa.project_id
        WHERE pa.employee_id = " . (int)$employeeId . "
          AND pa.status = 'active'
          AND p.deleted_at IS NULL
        ORDER BY p.project_name ASC
    ");
    while ($q && ($row = mysqli_fetch_assoc($q))) {
        $projects[] = $row;
    }
}

$holidays = [];
$q = mysqli_query($conn, "SELECT holiday_date, holiday_name FROM master_holidays WHERE is_active = 1");
while ($q && ($row = mysqli_fetch_assoc($q))) {
    $holidays[$row["holiday_date"]] = $row["holiday_name"];
}

$myLeaves = [];
if ($employeeId > 0) {
    $q = mysqli_query($conn, "
        SELECT lr.*, mlt.leave_type_name, p.project_name
        FROM leave_requests lr
        LEFT JOIN master_leave_types mlt ON mlt.id = lr.leave_type_id
        LEFT JOIN projects p ON p.id = lr.project_id
        WHERE lr.employee_id = " . (int)$employeeId . "
        ORDER BY lr.id DESC
        LIMIT 20
    ");
    while ($q && ($row = mysqli_fetch_assoc($q))) {
        $myLeaves[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Leave Requests - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>
    <style>
        .page-head-card,.leave-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
        .calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}
        .calendar-head{font-size:12px;font-weight:900;color:var(--text-muted);text-align:center}
        .calendar-day{border:1px solid var(--border-soft);border-radius:14px;min-height:58px;padding:8px;background:rgba(148,163,184,.05);cursor:pointer;font-weight:800;color:var(--text-main)}
        .calendar-day.selected{background:rgba(37,99,235,.14);border-color:#2563eb;color:#2563eb}
        .calendar-day.holiday{background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.45)}
        .calendar-day small{font-size:9px;font-weight:800}
        .status-pill{border-radius:999px;padding:5px 10px;font-size:11px;font-weight:900;text-transform:capitalize}
        .status-pill.green{background:rgba(16,185,129,.15);color:#059669}
        .status-pill.amber{background:rgba(245,158,11,.15);color:#d97706}
        .status-pill.red{background:rgba(239,68,68,.15);color:#dc2626}
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
                <h1 class="h4 fw-bold mb-1">Leave Requests</h1>
                <p class="text-muted-custom mb-0 small">Apply leave using master leave types and calendar selection.</p>
            </div>
            <?php if (lm_user_has_approve_access($conn, "leave-approval.php")): ?>
                <a href="leave-approval.php" class="btn btn-outline-primary rounded-4 fw-bold btn-sm px-3">Leave Approval</a>
            <?php endif; ?>
        </div>
    </div>

    <form action="api/process-leave-request.php" method="POST" enctype="multipart/form-data" class="leave-card mb-3">
        <input type="hidden" name="selected_dates" id="selected_dates">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold small">Leave Type</label>
                <select name="leave_type_id" class="form-select rounded-4" required>
                    <option value="">Select Leave Type</option>
                    <?php foreach ($leaveTypes as $lt): ?>
                        <option value="<?= (int)$lt["id"] ?>"><?= e($lt["leave_type_name"]) ?> - <?= e($lt["allowed_days"]) ?> days</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-bold small">Project</label>
                <select name="project_id" class="form-select rounded-4">
                    <option value="">General Leave</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p["id"] ?>"><?= e($p["project_name"]) ?><?= $p["project_code"] ? " (" . e($p["project_code"]) . ")" : "" ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted-custom fw-semibold">If project selected, project manager/TL receive notification.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-bold small">Attachment</label>
                <input type="file" name="attachment" class="form-control rounded-4" accept=".pdf,.jpg,.jpeg,.png,.webp">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-bold small">Calendar Month</label>
                <input type="month" id="calendar_month" class="form-control rounded-4" value="<?= date("Y-m") ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-bold small">Selected Days</label>
                <input type="text" id="selected_count" class="form-control rounded-4" readonly value="0">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-bold small">Contact During Leave</label>
                <input type="text" name="contact_during_leave" class="form-control rounded-4">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-bold small">Handover To</label>
                <input type="text" name="handover_to" class="form-control rounded-4">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-bold small">Reason</label>
                <textarea name="reason" rows="2" class="form-control rounded-4" required></textarea>
            </div>

            <div class="col-12">
                <div class="calendar-grid mb-2" id="calendar_headers"></div>
                <div class="calendar-grid" id="leave_calendar"></div>
            </div>
        </div>

        <div class="mt-4 d-flex justify-content-end">
            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Submit Leave</button>
        </div>
    </form>

    <section class="card-ui overflow-hidden">
        <div class="p-3 p-lg-4">
            <h2 class="fw-bold fs-6 mb-1">My Leave Requests</h2>
            <p class="text-muted-custom small mb-0">Recently applied leave requests.</p>
        </div>
        <div class="overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
            <table class="project-table w-100">
                <thead><tr><th>Leave Type</th><th>Project</th><th>Dates</th><th>Days</th><th>Status</th><th>Reason</th></tr></thead>
                <tbody>
                    <?php foreach ($myLeaves as $leave): ?>
                        <tr>
                            <td class="fw-bold"><?= e($leave["leave_type_name"] ?: $leave["leave_type"]) ?></td>
                            <td><?= e($leave["project_name"] ?: "-") ?></td>
                            <td><?= e(dmy($leave["from_date"])) ?> to <?= e(dmy($leave["to_date"])) ?></td>
                            <td><?= e($leave["total_days"]) ?></td>
                            <td><span class="status-pill <?= $leave["status"] === "Approved" ? "green" : ($leave["status"] === "Rejected" ? "red" : "amber") ?>"><?= e($leave["status"]) ?></span></td>
                            <td><?= e($leave["reason"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($myLeaves)): ?>
                        <tr><td colspan="6" class="text-center text-muted-custom py-4">No leave requests found.</td></tr>
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
<?php include("includes/script.php") ?>
<script src="assets/js/script.js?v=120"></script>
<script>
const holidayMap = <?= json_encode($holidays) ?>;
let selectedDates = new Set();

function renderCalendar(){
    const monthVal = document.getElementById("calendar_month").value;
    const cal = document.getElementById("leave_calendar");
    const headers = document.getElementById("calendar_headers");
    headers.innerHTML = ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"].map(d => `<div class="calendar-head">${d}</div>`).join("");
    cal.innerHTML = "";
    if(!monthVal) return;

    const [y,m] = monthVal.split("-").map(Number);
    const first = new Date(y, m - 1, 1);
    const last = new Date(y, m, 0);

    for(let i = 0; i < first.getDay(); i++) cal.innerHTML += `<div></div>`;

    for(let d = 1; d <= last.getDate(); d++){
        const dt = `${y}-${String(m).padStart(2,"0")}-${String(d).padStart(2,"0")}`;
        const cls = [selectedDates.has(dt) ? "selected" : "", holidayMap[dt] ? "holiday" : ""].join(" ");
        cal.innerHTML += `<button type="button" class="calendar-day ${cls}" onclick="toggleDate('${dt}')">${d}${holidayMap[dt] ? `<br><small>${holidayMap[dt]}</small>` : ""}</button>`;
    }
}

function toggleDate(dt){
    if(selectedDates.has(dt)) selectedDates.delete(dt); else selectedDates.add(dt);
    const arr = Array.from(selectedDates).sort();
    document.getElementById("selected_dates").value = arr.join(",");
    document.getElementById("selected_count").value = arr.length;
    renderCalendar();
}

document.getElementById("calendar_month").addEventListener("change", renderCalendar);
renderCalendar();
</script>
</body>
</html>