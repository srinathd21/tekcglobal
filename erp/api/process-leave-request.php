<?php
session_start();
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/leave-module-helper.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../leave-requests.php");
    exit;
}

function leave_redirect_error($msg) {
    header("Location: ../leave-requests.php?error=" . urlencode($msg));
    exit;
}

function leave_upload_attachment() {
    if (empty($_FILES["attachment"]["name"])) {
        return null;
    }

    $allowed = ["pdf", "jpg", "jpeg", "png", "webp"];
    $ext = strtolower(pathinfo($_FILES["attachment"]["name"], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        throw new Exception("Only PDF, JPG, PNG and WEBP files are allowed.");
    }

    $dir = __DIR__ . "/../uploads/leaves/";
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $name = "leave_" . date("YmdHis") . "_" . rand(1000, 9999) . "." . $ext;

    if (!move_uploaded_file($_FILES["attachment"]["tmp_name"], $dir . $name)) {
        throw new Exception("Unable to upload attachment.");
    }

    return "uploads/leaves/" . $name;
}

$employeeId = lm_login_employee_id($conn);
if ($employeeId <= 0) {
    leave_redirect_error("Employee profile not linked with this user.");
}

$leaveTypeId = (int)($_POST["leave_type_id"] ?? 0);
$projectId = (int)($_POST["project_id"] ?? 0);
$selectedDatesRaw = lm_clean($_POST["selected_dates"] ?? "");
$reason = lm_clean($_POST["reason"] ?? "");
$contactDuringLeave = lm_clean($_POST["contact_during_leave"] ?? "");
$handoverTo = lm_clean($_POST["handover_to"] ?? "");

if ($leaveTypeId <= 0) {
    leave_redirect_error("Please select leave type.");
}

if ($selectedDatesRaw === "") {
    leave_redirect_error("Please select leave days.");
}

if ($reason === "") {
    leave_redirect_error("Reason is required.");
}

$dates = array_values(array_unique(array_filter(array_map("trim", explode(",", $selectedDatesRaw)))));
sort($dates);

if (empty($dates)) {
    leave_redirect_error("Please select valid leave days.");
}

try {
    $typeStmt = mysqli_prepare($conn, "SELECT * FROM master_leave_types WHERE id = ? AND is_active = 1 LIMIT 1");
    mysqli_stmt_bind_param($typeStmt, "i", $leaveTypeId);
    mysqli_stmt_execute($typeStmt);
    $leaveType = mysqli_fetch_assoc(mysqli_stmt_get_result($typeStmt));
    mysqli_stmt_close($typeStmt);

    if (!$leaveType) {
        leave_redirect_error("Invalid leave type.");
    }

    $attachment = leave_upload_attachment();

    $fromDate = $dates[0];
    $toDate = end($dates);
    $totalDays = count($dates);
    $selectedDatesJson = json_encode($dates);
    $status = "Pending";
    $appliedAt = date("Y-m-d H:i:s");
    $projectIdValue = $projectId > 0 ? $projectId : null;
    $userId = $_SESSION["user_id"] ?? null;

    mysqli_begin_transaction($conn);

    $stmt = mysqli_prepare($conn, "
        INSERT INTO leave_requests
        (employee_id, project_id, leave_type_id, leave_type, from_date, to_date, total_days, reason,
         contact_during_leave, handover_to, selected_dates_json, attachment, status, applied_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    mysqli_stmt_bind_param(
        $stmt,
        "iiisssdsssssss",
        $employeeId,
        $projectIdValue,
        $leaveTypeId,
        $leaveType["leave_type_code"],
        $fromDate,
        $toDate,
        $totalDays,
        $reason,
        $contactDuringLeave,
        $handoverTo,
        $selectedDatesJson,
        $attachment,
        $status,
        $appliedAt
    );

    mysqli_stmt_execute($stmt);
    $leaveId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    if ($projectId > 0) {
        $officials = lm_project_higher_officials($conn, $projectId, $employeeId);

        foreach ($officials as $officialEmployeeId) {
            lm_create_notification(
                $conn,
                $officialEmployeeId,
                "New Leave Request",
                "A project team member applied leave and is waiting for approval.",
                "leave",
                $leaveId,
                "leave-approval.php?status=Pending",
                "warning",
                "high",
                "LEAVE_REQUEST"
            );
        }
    }

    lm_log_activity($conn, "CREATE", "leave_requests", "Submitted leave request", $leaveId);

    mysqli_commit($conn);

    header("Location: ../leave-requests.php?success=1");
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    leave_redirect_error($e->getMessage());
}
?>