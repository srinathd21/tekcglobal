<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

require_permission($conn, "can_delete", "report-master.php");

function log_report_delete_activity($conn, $description, $referenceId = null)
{
    $employeeId = (int)($_SESSION["employee_id"] ?? 0);
    $employeeName = mysqli_real_escape_string($conn, $_SESSION["employee_name"] ?? $_SESSION["name"] ?? "");
    $username = mysqli_real_escape_string($conn, $_SESSION["username"] ?? "");
    $designation = mysqli_real_escape_string($conn, $_SESSION["designation"] ?? "");
    $department = mysqli_real_escape_string($conn, $_SESSION["department"] ?? "");
    $desc = mysqli_real_escape_string($conn, $description);
    $ref = $referenceId !== null ? (int)$referenceId : "NULL";
    $ip = mysqli_real_escape_string($conn, $_SERVER["REMOTE_ADDR"] ?? "");

    mysqli_query($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES ($employeeId, '$employeeName', '$username', '$designation', '$department', 'delete', 'Report Master', '$desc', $ref, '$ip')
    ");
}

$reportId = (int)($_POST["report_id"] ?? 0);

if ($reportId <= 0) {
    header("Location: ../report-master.php?error=Invalid report");
    exit;
}

mysqli_query($conn, "UPDATE master_report_types SET is_active = 0, updated_by = " . (int)($_SESSION["user_id"] ?? 0) . " WHERE id = $reportId");
log_report_delete_activity($conn, "Disabled report master", $reportId);

header("Location: ../report-master.php?deleted=1");
exit;
?>