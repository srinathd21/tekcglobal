<?php
session_start();
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/leave-module-helper.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../attendance-regularization.php");
    exit;
}

function reg_error($msg) {
    header("Location: ../attendance-regularization.php?error=" . urlencode($msg));
    exit;
}

$employeeId = lm_login_employee_id($conn);
if ($employeeId <= 0) {
    reg_error("Employee profile not linked with this user.");
}

$date = lm_clean($_POST["regularization_date"] ?? "");
$type = lm_clean($_POST["request_type"] ?? "");
$requestedIn = lm_clean($_POST["requested_punch_in_at"] ?? "");
$requestedOut = lm_clean($_POST["requested_punch_out_at"] ?? "");
$locationType = lm_clean($_POST["requested_location_type"] ?? "");
$reason = lm_clean($_POST["reason"] ?? "");

if ($date === "" || $type === "" || $reason === "") {
    reg_error("Date, request type and reason are required.");
}

if (!in_array($type, ["missing_punch_in", "missing_punch_out", "missing_both", "wrong_time", "wrong_location"], true)) {
    reg_error("Invalid request type.");
}

$requestedInValue = $requestedIn !== "" ? str_replace("T", " ", $requestedIn) . ":00" : null;
$requestedOutValue = $requestedOut !== "" ? str_replace("T", " ", $requestedOut) . ":00" : null;
$locationTypeValue = $locationType !== "" ? $locationType : null;
$userId = $_SESSION["user_id"] ?? null;
$code = "REG" . date("YmdHis") . rand(100, 999);

try {
    mysqli_begin_transaction($conn);

    $attendanceId = null;
    if (lm_table_exists($conn, "attendance_records")) {
        $q = mysqli_query($conn, "
            SELECT id FROM attendance_records
            WHERE employee_id = " . (int)$employeeId . "
              AND attendance_date = '" . mysqli_real_escape_string($conn, $date) . "'
            LIMIT 1
        ");
        if ($q && ($row = mysqli_fetch_assoc($q))) {
            $attendanceId = (int)$row["id"];
        }
    }

    $stmt = mysqli_prepare($conn, "
        INSERT INTO attendance_regularization_requests
        (regularization_code, employee_id, attendance_id, regularization_date, request_type,
         requested_punch_in_at, requested_punch_out_at, requested_location_type, reason, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");

    mysqli_stmt_bind_param(
        $stmt,
        "siissssssi",
        $code,
        $employeeId,
        $attendanceId,
        $date,
        $type,
        $requestedInValue,
        $requestedOutValue,
        $locationTypeValue,
        $reason,
        $userId
    );

    mysqli_stmt_execute($stmt);
    $regId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $q = mysqli_query($conn, "
        SELECT reporting_to FROM employees
        WHERE id = " . (int)$employeeId . "
        LIMIT 1
    ");
    if ($q && ($emp = mysqli_fetch_assoc($q)) && !empty($emp["reporting_to"])) {
        lm_create_notification(
            $conn,
            (int)$emp["reporting_to"],
            "Attendance Regularization Request",
            "An employee submitted attendance regularization request.",
            "regularization",
            $regId,
            "regularization-approval.php?status=pending",
            "warning",
            "high",
            "ATTENDANCE_REGULARIZATION"
        );
    }

    lm_log_activity($conn, "CREATE", "attendance_regularization", "Submitted attendance regularization request", $regId);

    mysqli_commit($conn);
    header("Location: ../attendance-regularization.php?success=1");
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    reg_error($e->getMessage());
}
?>