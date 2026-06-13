<?php
session_start();
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/leave-module-helper.php";

require_permission($conn, "can_create", "attendance-regularization.php");

function e($v) { return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8"); }
function dmy($date) { return ($date && $date !== "0000-00-00") ? date("d M Y", strtotime($date)) : "-"; }

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Regularization request submitted successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) ?: "Something went wrong.";
}

$employeeId = lm_login_employee_id($conn);

$requests = [];
if ($employeeId > 0) {
    $q = mysqli_query($conn, "
        SELECT *
        FROM attendance_regularization_requests
        WHERE employee_id = " . (int)$employeeId . "
        ORDER BY id DESC
        LIMIT 20
    ");
    while ($q && ($row = mysqli_fetch_assoc($q))) $requests[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Attendance Regularization - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>
    <style>
        .page-head-card,.regularization-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
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
                <h1 class="h4 fw-bold mb-1">Attendance Regularization</h1>
                <p class="text-muted-custom mb-0 small">Apply correction for missing punch-in or punch-out.</p>
            </div>
            <?php if (lm_user_has_approve_access($conn, "regularization-approval.php")): ?>
                <a href="regularization-approval.php" class="btn btn-outline-primary rounded-4 fw-bold btn-sm px-3">Regularization Approval</a>
            <?php endif; ?>
        </div>
    </div>

    <form action="api/process-regularization.php" method="POST" class="regularization-card mb-3">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold small">Date</label>
                <input type="date" name="regularization_date" class="form-control rounded-4" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Request Type</label>
                <select name="request_type" class="form-select rounded-4" required>
                    <option value="">Select</option>
                    <option value="missing_punch_in">Missing Punch In</option>
                    <option value="missing_punch_out">Missing Punch Out</option>
                    <option value="missing_both">Missing Both</option>
                    <option value="wrong_time">Wrong Time</option>
                    <option value="wrong_location">Wrong Location</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Location Type</label>
                <select name="requested_location_type" class="form-select rounded-4">
                    <option value="">No Change</option>
                    <option value="office">Office</option>
                    <option value="site">Site</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold small">Requested Punch In</label>
                <input type="datetime-local" name="requested_punch_in_at" class="form-control rounded-4">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold small">Requested Punch Out</label>
                <input type="datetime-local" name="requested_punch_out_at" class="form-control rounded-4">
            </div>
            <div class="col-12">
                <label class="form-label fw-bold small">Reason</label>
                <textarea name="reason" class="form-control rounded-4" rows="4" required></textarea>
            </div>
        </div>
        <div class="mt-4 text-end">
            <button class="btn brand-gradient text-white rounded-4 fw-bold px-4">Submit Request</button>
        </div>
    </form>

    <section class="card-ui overflow-hidden">
        <div class="p-3 p-lg-4"><h2 class="fw-bold fs-6 mb-1">My Regularization Requests</h2></div>
        <div class="overflow-auto thin-scrollbar p-3">
            <table class="project-table w-100">
                <thead><tr><th>Code</th><th>Date</th><th>Type</th><th>Requested Time</th><th>Status</th><th>Reason</th></tr></thead>
                <tbody>
                    <?php foreach ($requests as $row): ?>
                        <tr>
                            <td class="fw-bold"><?= e($row["regularization_code"]) ?></td>
                            <td><?= e(dmy($row["regularization_date"])) ?></td>
                            <td><?= e(str_replace("_", " ", $row["request_type"])) ?></td>
                            <td><?= e($row["requested_punch_in_at"] ?: "-") ?> / <?= e($row["requested_punch_out_at"] ?: "-") ?></td>
                            <td><span class="status-pill <?= $row["status"] === "approved" ? "green" : ($row["status"] === "rejected" ? "red" : "amber") ?>"><?= e($row["status"]) ?></span></td>
                            <td><?= e($row["reason"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="6" class="text-center text-muted-custom py-4">No regularization requests found.</td></tr>
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
</body>
</html>