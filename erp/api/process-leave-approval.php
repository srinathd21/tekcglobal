<?php
session_start();
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/leave-module-helper.php";

require_permission($conn, "can_approve", "leave-approval.php");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../leave-approval.php");
    exit;
}

function approval_error($msg) {
    header("Location: ../leave-approval.php?error=" . urlencode($msg));
    exit;
}

$leaveId = (int)($_POST["leave_id"] ?? 0);
$action = lm_clean($_POST["action_status"] ?? "");
$remarks = lm_clean($_POST["remarks"] ?? "");
$userId = $_SESSION["user_id"] ?? null;
$now = date("Y-m-d H:i:s");

if ($leaveId <= 0 || !in_array($action, ["Approved", "Rejected"], true)) {
    approval_error("Invalid leave action.");
}

try {
    mysqli_begin_transaction($conn);

    $stmt = mysqli_prepare($conn, "SELECT * FROM leave_requests WHERE id = ? AND status = 'Pending' LIMIT 1 FOR UPDATE");
    mysqli_stmt_bind_param($stmt, "i", $leaveId);
    mysqli_stmt_execute($stmt);
    $leave = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$leave) {
        throw new Exception("Leave request not found or already processed.");
    }

    if ($action === "Approved") {
        $stmt = mysqli_prepare($conn, "
            UPDATE leave_requests
            SET status = 'Approved', approved_by = ?, approved_at = ?, approver_remarks = ?
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($stmt, "issi", $userId, $now, $remarks, $leaveId);
    } else {
        $stmt = mysqli_prepare($conn, "
            UPDATE leave_requests
            SET status = 'Rejected', rejected_by = ?, rejected_at = ?, rejection_reason = ?
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($stmt, "issi", $userId, $now, $remarks, $leaveId);
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    lm_create_notification(
        $conn,
        (int)$leave["employee_id"],
        $action === "Approved" ? "Leave Approved" : "Leave Rejected",
        "Your leave request from " . $leave["from_date"] . " to " . $leave["to_date"] . " was " . strtolower($action) . ".",
        "leave",
        $leaveId,
        "leave-requests.php",
        $action === "Approved" ? "success" : "danger",
        "normal",
        "LEAVE_APPROVAL"
    );

    lm_log_activity($conn, "UPDATE", "leave_requests", "Leave request " . strtolower($action), $leaveId);

    mysqli_commit($conn);

    header("Location: ../leave-approval.php?" . ($action === "Approved" ? "approved=1" : "rejected=1"));
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    approval_error($e->getMessage());
}
?>