<?php
session_start();
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/leave-module-helper.php";

require_permission($conn, "can_approve", "regularization-approval.php");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../regularization-approval.php");
    exit;
}

function reg_approval_error($msg) {
    header("Location: ../regularization-approval.php?error=" . urlencode($msg));
    exit;
}

$requestId = (int)($_POST["request_id"] ?? 0);
$action = lm_clean($_POST["action_status"] ?? "");
$remarks = lm_clean($_POST["remarks"] ?? "");
$userId = $_SESSION["user_id"] ?? null;
$now = date("Y-m-d H:i:s");

if ($requestId <= 0 || !in_array($action, ["approved", "rejected"], true)) {
    reg_approval_error("Invalid action.");
}

try {
    mysqli_begin_transaction($conn);

    $stmt = mysqli_prepare($conn, "SELECT * FROM attendance_regularization_requests WHERE id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
    mysqli_stmt_bind_param($stmt, "i", $requestId);
    mysqli_stmt_execute($stmt);
    $req = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$req) {
        throw new Exception("Request not found or already processed.");
    }

    if ($action === "approved") {
        $attendanceId = !empty($req["attendance_id"]) ? (int)$req["attendance_id"] : null;

        if (!$attendanceId) {
            $stmt = mysqli_prepare($conn, "
                INSERT INTO attendance_records
                (employee_id, attendance_date, punch_in_at, punch_out_at, location_type, approval_status, approved_by, approved_at, created_by)
                VALUES (?, ?, ?, ?, COALESCE(?, 'office'), 'approved', ?, ?, ?)
            ");
            mysqli_stmt_bind_param($stmt, "issssisi", $req["employee_id"], $req["regularization_date"], $req["requested_punch_in_at"], $req["requested_punch_out_at"], $req["requested_location_type"], $userId, $now, $userId);
            mysqli_stmt_execute($stmt);
            $attendanceId = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
        } else {
            $sets = [];
            if (!empty($req["requested_punch_in_at"])) $sets[] = "punch_in_at = '" . mysqli_real_escape_string($conn, $req["requested_punch_in_at"]) . "'";
            if (!empty($req["requested_punch_out_at"])) $sets[] = "punch_out_at = '" . mysqli_real_escape_string($conn, $req["requested_punch_out_at"]) . "'";
            if (!empty($req["requested_location_type"])) $sets[] = "location_type = '" . mysqli_real_escape_string($conn, $req["requested_location_type"]) . "'";
            $sets[] = "updated_by = " . (int)$userId;

            mysqli_query($conn, "UPDATE attendance_records SET " . implode(", ", $sets) . " WHERE id = " . (int)$attendanceId);
        }

        $stmt = mysqli_prepare($conn, "
            UPDATE attendance_regularization_requests
            SET status = 'approved', attendance_id = ?, approved_by = ?, approved_at = ?, approval_remarks = ?
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($stmt, "iissi", $attendanceId, $userId, $now, $remarks, $requestId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $stmt = mysqli_prepare($conn, "
            UPDATE attendance_regularization_requests
            SET status = 'rejected', rejected_by = ?, rejected_at = ?, approval_remarks = ?
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($stmt, "issi", $userId, $now, $remarks, $requestId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    $stmt = mysqli_prepare($conn, "
        INSERT INTO attendance_regularization_actions
        (regularization_request_id, action_by, action_status, remarks)
        VALUES (?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param($stmt, "iiss", $requestId, $userId, $action, $remarks);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    lm_create_notification(
        $conn,
        (int)$req["employee_id"],
        $action === "approved" ? "Regularization Approved" : "Regularization Rejected",
        "Your attendance regularization request was " . $action . ".",
        "regularization",
        $requestId,
        "attendance-regularization.php",
        $action === "approved" ? "success" : "danger",
        "normal",
        "ATTENDANCE_REGULARIZATION"
    );

    lm_log_activity($conn, "UPDATE", "attendance_regularization", "Regularization request " . $action, $requestId);

    mysqli_commit($conn);
    header("Location: ../regularization-approval.php?" . ($action === "approved" ? "approved=1" : "rejected=1"));
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    reg_approval_error($e->getMessage());
}
?>