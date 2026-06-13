<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../attendance-approvals.php");
    exit;
}

require_permission($conn, "can_edit", "attendance-approvals.php");

function clean($v)
{
    return trim($v ?? "");
}

function redirect_approval($params = [])
{
    header("Location: ../attendance-approvals.php" . ($params ? "?" . http_build_query($params) : ""));
    exit;
}

function redirect_error($message)
{
    redirect_approval(["error" => $message]);
}

function table_exists($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $q && mysqli_num_rows($q) > 0;
}

function log_activity($conn, $type, $description, $referenceId = null)
{
    if (!table_exists($conn, "activity_logs")) return;

    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $sessionEmployeeId = $_SESSION["employee_id"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, 'attendance-approvals', ?, ?, ?)
    ");
    if ($log) {
        mysqli_stmt_bind_param($log, "issssssis", $sessionEmployeeId, $employeeName, $username, $designation, $department, $type, $description, $referenceId, $ip);
        mysqli_stmt_execute($log);
        mysqli_stmt_close($log);
    }
}

$requestId = (int)($_POST["request_id"] ?? 0);
$approvalAction = clean($_POST["approval_action"] ?? "");
$remarks = clean($_POST["approval_remarks"] ?? "");
$userId = $_SESSION["user_id"] ?? null;
$now = date("Y-m-d H:i:s");

if ($requestId <= 0 || !in_array($approvalAction, ["approve", "reject"], true)) {
    redirect_error("Invalid approval request.");
}

try {
    mysqli_begin_transaction($conn);

    $stmt = mysqli_prepare($conn, "
        SELECT *
        FROM attendance_other_location_requests
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    mysqli_stmt_bind_param($stmt, "i", $requestId);
    mysqli_stmt_execute($stmt);
    $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$request) {
        throw new Exception("Request not found.");
    }

    if ($request["status"] !== "pending") {
        throw new Exception("This request is already processed.");
    }

    if ($approvalAction === "approve") {
        $check = mysqli_prepare($conn, "
            SELECT id
            FROM attendance_records
            WHERE employee_id = ? AND attendance_date = ?
            LIMIT 1
        ");
        mysqli_stmt_bind_param($check, "is", $request["employee_id"], $request["attendance_date"]);
        mysqli_stmt_execute($check);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
        mysqli_stmt_close($check);

        if ($existing) {
            throw new Exception("Attendance already exists for this employee and date.");
        }

        $stmt = mysqli_prepare($conn, "
            INSERT INTO attendance_records
            (employee_id, attendance_date, punch_in_at, location_type, other_request_id,
             target_latitude, target_longitude, allowed_radius_meters, distance_meters,
             latitude, longitude, address, approval_status, approved_by, approved_at, created_by)
            VALUES (?, ?, ?, 'other', ?, NULL, NULL, NULL, NULL, ?, ?, ?, 'approved', ?, ?, ?)
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "issiddsisi",
            $request["employee_id"],
            $request["attendance_date"],
            $request["requested_punch_in_at"],
            $requestId,
            $request["latitude"],
            $request["longitude"],
            $request["address"],
            $userId,
            $now,
            $userId
        );

        mysqli_stmt_execute($stmt);
        $attendanceId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "
            UPDATE attendance_other_location_requests
            SET status = 'approved',
                attendance_id = ?,
                approved_by = ?,
                approved_at = ?,
                approval_remarks = ?
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($stmt, "iissi", $attendanceId, $userId, $now, $remarks, $requestId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        log_activity($conn, "UPDATE", "Approved other location attendance request", $requestId);
        mysqli_commit($conn);
        redirect_approval(["approved" => 1]);
    }

    $stmt = mysqli_prepare($conn, "
        UPDATE attendance_other_location_requests
        SET status = 'rejected',
            rejected_by = ?,
            rejected_at = ?,
            approval_remarks = ?
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt, "issi", $userId, $now, $remarks, $requestId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    log_activity($conn, "UPDATE", "Rejected other location attendance request", $requestId);

    mysqli_commit($conn);
    redirect_approval(["rejected" => 1]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirect_error($e->getMessage());
}
?>
