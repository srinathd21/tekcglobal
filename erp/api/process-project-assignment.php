<?php
require_once __DIR__ . "/../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../project-assignments.php");
    exit;
}

function redirect_assignment_error($message)
{
    header("Location: ../project-assignments.php?error=" . urlencode($message));
    exit;
}

$assignmentId = (int)($_POST["assignment_id"] ?? 0);

if ($assignmentId > 0) {
    require_permission($conn, "can_edit", "project-assignments.php");
} else {
    require_permission($conn, "can_create", "project-assignments.php");
}

$projectId = (int)($_POST["project_id"] ?? 0);
$employeeId = (int)($_POST["employee_id"] ?? 0);
$assignmentRoleId = (int)($_POST["assignment_role_id"] ?? 0);
$isPrimary = isset($_POST["is_primary"]) ? 1 : 0;
$assignedFrom = trim($_POST["assigned_from"] ?? "");
$assignedTo = trim($_POST["assigned_to"] ?? "");
$status = trim($_POST["status"] ?? "active");
$userId = $_SESSION["user_id"] ?? null;

if ($projectId <= 0 || $employeeId <= 0 || $assignmentRoleId <= 0) {
    redirect_assignment_error("Project, employee and role are required.");
}

if ($assignedFrom === "") {
    $assignedFrom = date("Y-m-d");
}

if ($assignedTo === "") {
    $assignedTo = null;
}

if (!in_array($status, ["active", "inactive", "completed"], true)) {
    $status = "active";
}

$projectCheck = mysqli_prepare($conn, "SELECT id FROM projects WHERE id = ? AND deleted_at IS NULL LIMIT 1");
mysqli_stmt_bind_param($projectCheck, "i", $projectId);
mysqli_stmt_execute($projectCheck);
$projectExists = mysqli_fetch_assoc(mysqli_stmt_get_result($projectCheck));
mysqli_stmt_close($projectCheck);

if (!$projectExists) {
    redirect_assignment_error("Selected project not found.");
}

$employeeCheck = mysqli_prepare($conn, "SELECT id FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($employeeCheck, "i", $employeeId);
mysqli_stmt_execute($employeeCheck);
$employeeExists = mysqli_fetch_assoc(mysqli_stmt_get_result($employeeCheck));
mysqli_stmt_close($employeeCheck);

if (!$employeeExists) {
    redirect_assignment_error("Selected employee is not active.");
}

if ($assignedTo !== null && $assignedTo < $assignedFrom) {
    redirect_assignment_error("Assigned to date cannot be earlier than assigned from date.");
}

mysqli_begin_transaction($conn);

try {
    if ($isPrimary === 1 && $status === "active") {
        $primaryUpdate = mysqli_prepare($conn, "
            UPDATE project_assignments
            SET is_primary = 0
            WHERE project_id = ? AND assignment_role_id = ? AND id <> ?
        ");
        mysqli_stmt_bind_param($primaryUpdate, "iii", $projectId, $assignmentRoleId, $assignmentId);
        mysqli_stmt_execute($primaryUpdate);
        mysqli_stmt_close($primaryUpdate);
    }

    if ($assignmentId > 0) {
        $stmt = mysqli_prepare($conn, "
            UPDATE project_assignments
            SET project_id = ?, employee_id = ?, assignment_role_id = ?, is_primary = ?,
                assigned_from = ?, assigned_to = ?, status = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($stmt, "iiiisssi", $projectId, $employeeId, $assignmentRoleId, $isPrimary, $assignedFrom, $assignedTo, $status, $assignmentId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $activityType = "UPDATE";
        $description = "Updated project assignment";
        $referenceId = $assignmentId;
    } else {
        $duplicateCheck = mysqli_prepare($conn, "
            SELECT id FROM project_assignments
            WHERE project_id = ? AND employee_id = ? AND assignment_role_id = ? AND status = 'active'
            LIMIT 1
        ");
        mysqli_stmt_bind_param($duplicateCheck, "iii", $projectId, $employeeId, $assignmentRoleId);
        mysqli_stmt_execute($duplicateCheck);
        $duplicate = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicateCheck));
        mysqli_stmt_close($duplicateCheck);

        if ($duplicate) {
            throw new Exception("This employee is already active in this project role.");
        }

        $stmt = mysqli_prepare($conn, "
            INSERT INTO project_assignments
            (project_id, employee_id, assignment_role_id, is_primary, assigned_from, assigned_to, status, assigned_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        mysqli_stmt_bind_param($stmt, "iiiisssi", $projectId, $employeeId, $assignmentRoleId, $isPrimary, $assignedFrom, $assignedTo, $status, $userId);
        mysqli_stmt_execute($stmt);
        $assignmentId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $activityType = "CREATE";
        $description = "Created project assignment";
        $referenceId = $assignmentId;
    }

    $employeeSessionId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, 'project_assignments', ?, ?, ?)
    ");
    mysqli_stmt_bind_param($log, "issssssis", $employeeSessionId, $employeeName, $username, $designation, $department, $activityType, $description, $referenceId, $ip);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    mysqli_commit($conn);

    header("Location: ../project-assignments.php?success=1");
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirect_assignment_error($e->getMessage());
}
?>
