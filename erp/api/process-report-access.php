<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

require_permission($conn, "can_edit", "report-master.php");

function redirect_access($reportId, $params = [])
{
    $params["report_type_id"] = (int)$reportId;
    header("Location: ../report-master.php?" . http_build_query($params));
    exit;
}

function log_report_access_activity($conn, $description, $referenceId = null)
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
        VALUES ($employeeId, '$employeeName', '$username', '$designation', '$department', 'update', 'Report Master', '$desc', $ref, '$ip')
    ");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../report-master.php?error=Invalid request");
    exit;
}

$reportId = (int)($_POST["report_type_id"] ?? 0);
$access = $_POST["access"] ?? [];
$userId = (int)($_SESSION["user_id"] ?? 0);

if ($reportId <= 0) {
    header("Location: ../report-master.php?error=Invalid report");
    exit;
}

try {
    mysqli_begin_transaction($conn);

    foreach ($access as $roleId => $row) {
        $roleId = (int)$roleId;
        $canSubmit = isset($row["can_submit"]) ? 1 : 0;
        $canView = isset($row["can_view"]) ? 1 : 0;
        $canRemarkTl = isset($row["can_remark_tl"]) ? 1 : 0;
        $canRemarkManager = isset($row["can_remark_manager"]) ? 1 : 0;

        $stmt = mysqli_prepare($conn, "
            INSERT INTO report_type_role_access
            (report_type_id, role_id, can_submit, can_view, can_remark_tl, can_remark_manager, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                can_submit = VALUES(can_submit),
                can_view = VALUES(can_view),
                can_remark_tl = VALUES(can_remark_tl),
                can_remark_manager = VALUES(can_remark_manager),
                updated_by = VALUES(updated_by)
        ");

        mysqli_stmt_bind_param($stmt, "iiiiiiii", $reportId, $roleId, $canSubmit, $canView, $canRemarkTl, $canRemarkManager, $userId, $userId);

        if (!$stmt || !mysqli_stmt_execute($stmt)) {
            throw new Exception("Unable to save access: " . mysqli_error($conn));
        }

        mysqli_stmt_close($stmt);
    }

    mysqli_commit($conn);
    log_report_access_activity($conn, "Updated report role access", $reportId);
    redirect_access($reportId, ["access" => 1]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirect_access($reportId, ["error" => $e->getMessage()]);
}
?>