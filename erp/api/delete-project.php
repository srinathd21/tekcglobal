<?php
require_once __DIR__ . "/../includes/db.php";
require_permission($conn, "can_delete", "projects.php");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../projects.php");
    exit;
}

function redirect_delete_project_error($message)
{
    header("Location: ../projects.php?error=" . urlencode($message));
    exit;
}

$projectId = (int)($_POST["project_id"] ?? 0);
$userId = $_SESSION["user_id"] ?? null;

if ($projectId <= 0) {
    redirect_delete_project_error("Invalid project.");
}

$stmt = mysqli_prepare($conn, "SELECT project_name FROM projects WHERE id = ? AND deleted_at IS NULL LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $projectId);
mysqli_stmt_execute($stmt);
$project = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$project) {
    redirect_delete_project_error("Project not found.");
}

mysqli_begin_transaction($conn);

try {
    $del = mysqli_prepare($conn, "UPDATE projects SET deleted_at = NOW(), deleted_by = ? WHERE id = ?");
    mysqli_stmt_bind_param($del, "ii", $userId, $projectId);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);

    $employeeId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, 'DELETE', 'projects', 'Deleted project', ?, ?)
    ");
    mysqli_stmt_bind_param($log, "issssis", $employeeId, $employeeName, $username, $designation, $department, $projectId, $ip);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    mysqli_commit($conn);

    header("Location: ../projects.php?deleted=1");
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirect_delete_project_error("Unable to delete project.");
}
?>
