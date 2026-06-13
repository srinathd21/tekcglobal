<?php
session_start();
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/leave-module-helper.php";

require_permission($conn, "can_approve", "regularization-approval.php");

function e($v) { return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8"); }
function dmy($date) { return ($date && $date !== "0000-00-00") ? date("d M Y", strtotime($date)) : "-"; }

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["approved"])) {
    $pageMessageType = "success";
    $pageMessageText = "Regularization request approved successfully.";
} elseif (isset($_GET["rejected"])) {
    $pageMessageType = "success";
    $pageMessageText = "Regularization request rejected successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) ?: "Something went wrong.";
}

$status = $_GET["status"] ?? "pending";
if (!in_array($status, ["pending", "approved", "rejected", "cancelled", "all"], true)) $status = "pending";

$where = $status === "all" ? "1=1" : "arr.status = '" . mysqli_real_escape_string($conn, $status) . "'";

$rows = [];
$q = mysqli_query($conn, "
    SELECT arr.*, e.full_name, e.employee_code
    FROM attendance_regularization_requests arr
    INNER JOIN employees e ON e.id = arr.employee_id
    WHERE $where
    ORDER BY arr.id DESC
");
while ($q && ($row = mysqli_fetch_assoc($q))) $rows[] = $row;
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Regularization Approval - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>
    <style>
        .page-head-card,.filter-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
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
            <div><h1 class="h4 fw-bold mb-1">Regularization Approval</h1><p class="text-muted-custom mb-0 small">Approve missing punch-in / punch-out requests.</p></div>
            <a href="attendance-regularization.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm px-3">Back</a>
        </div>
    </div>

    <form method="GET" class="filter-card mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-bold small">Status</label>
                <select name="status" class="form-select rounded-4">
                    <?php foreach (["pending","approved","rejected","cancelled","all"] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? "selected" : "" ?>><?= e(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button></div>
        </div>
    </form>

    <section class="card-ui overflow-hidden">
        <div class="overflow-auto thin-scrollbar p-3">
            <table class="project-table w-100">
                <thead><tr><th>Employee</th><th>Date</th><th>Type</th><th>Requested</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $json = htmlspecialchars(json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>
                        <tr>
                            <td><b><?= e($row["full_name"]) ?></b><br><small class="text-muted-custom"><?= e($row["employee_code"]) ?></small></td>
                            <td><?= e(dmy($row["regularization_date"])) ?></td>
                            <td><?= e(str_replace("_", " ", $row["request_type"])) ?></td>
                            <td><?= e($row["requested_punch_in_at"] ?: "-") ?> / <?= e($row["requested_punch_out_at"] ?: "-") ?></td>
                            <td><?= e($row["reason"]) ?></td>
                            <td><span class="status-pill <?= $row["status"] === "approved" ? "green" : ($row["status"] === "rejected" ? "red" : "amber") ?>"><?= e($row["status"]) ?></span></td>
                            <td>
                                <?php if ($row["status"] === "pending"): ?>
                                    <button class="btn btn-sm btn-outline-success rounded-4 fw-bold" data-bs-toggle="modal" data-bs-target="#approvalModal" onclick='openRegApproval(<?= $json ?>,"approved")'>Approve</button>
                                    <button class="btn btn-sm btn-outline-danger rounded-4 fw-bold" data-bs-toggle="modal" data-bs-target="#approvalModal" onclick='openRegApproval(<?= $json ?>,"rejected")'>Reject</button>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="text-center text-muted-custom py-4">No requests found.</td></tr>
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

<div class="modal fade" id="approvalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="api/process-regularization-approval.php" method="POST" class="modal-content">
            <input type="hidden" name="request_id" id="request_id">
            <input type="hidden" name="action_status" id="action_status">
            <div class="modal-header px-4">
                <h5 class="modal-title fw-bold" id="approvalTitle">Regularization Approval</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <label class="form-label fw-bold small">Remarks</label>
                <textarea name="remarks" class="form-control rounded-4" rows="3"></textarea>
            </div>
            <div class="modal-footer px-4">
                <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                <button class="btn brand-gradient text-white rounded-4 fw-bold px-4">Submit</button>
            </div>
        </form>
    </div>
</div>

<?php include("includes/script.php") ?>
<script>
function openRegApproval(row, status) {
    document.getElementById("request_id").value = row.id;
    document.getElementById("action_status").value = status;
    document.getElementById("approvalTitle").textContent = status === "approved" ? "Approve Regularization" : "Reject Regularization";
}
</script>
</body>
</html>