<?php
require_once __DIR__ . "/../includes/db.php";
require_permission($conn, "can_edit", "project-assignments.php");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../project-assignments.php");
    exit;
}

function redirect_replacement_error($message)
{
    header("Location: ../project-assignments.php?error=" . urlencode($message));
    exit;
}

$originalAssignmentId = (int)($_POST["original_assignment_id"] ?? 0);
$projectId = (int)($_POST["project_id"] ?? 0);
$originalEmployeeId = (int)($_POST["original_employee_id"] ?? 0);
$replacementEmployeeId = (int)($_POST["replacement_employee_id"] ?? 0);
$assignmentRoleId = (int)($_POST["assignment_role_id"] ?? 0);
$replacementFrom = trim($_POST["replacement_from"] ?? "");
$replacementTo = trim($_POST["replacement_to"] ?? "");
$reason = trim($_POST["reason"] ?? "");
$userId = $_SESSION["user_id"] ?? null;

if ($originalAssignmentId <= 0 || $projectId <= 0 || $originalEmployeeId <= 0 || $replacementEmployeeId <= 0 || $assignmentRoleId <= 0) {
    redirect_replacement_error("Invalid replacement data.");
}

if ($originalEmployeeId === $replacementEmployeeId) {
    redirect_replacement_error("Replacement employee cannot be the same as original employee.");
}

if ($replacementFrom === "" || $replacementTo === "") {
    redirect_replacement_error("Replacement from and to dates are required.");
}

if ($replacementTo < $replacementFrom) {
    redirect_replacement_error("Replacement to date cannot be earlier than replacement from date.");
}

$assignmentCheck = mysqli_prepare($conn, "
    SELECT id
    FROM project_assignments
    WHERE id = ? AND project_id = ? AND employee_id = ? AND assignment_role_id = ? AND status = 'active'
    LIMIT 1
");
mysqli_stmt_bind_param($assignmentCheck, "iiii", $originalAssignmentId, $projectId, $originalEmployeeId, $assignmentRoleId);
mysqli_stmt_execute($assignmentCheck);
$assignmentExists = mysqli_fetch_assoc(mysqli_stmt_get_result($assignmentCheck));
mysqli_stmt_close($assignmentCheck);

if (!$assignmentExists) {
    redirect_replacement_error("Original active assignment not found.");
}

$replacementCheck = mysqli_prepare($conn, "SELECT id FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($replacementCheck, "i", $replacementEmployeeId);
mysqli_stmt_execute($replacementCheck);
$replacementExists = mysqli_fetch_assoc(mysqli_stmt_get_result($replacementCheck));
mysqli_stmt_close($replacementCheck);

if (!$replacementExists) {
    redirect_replacement_error("Replacement employee is not active.");
}

$overlapCheck = mysqli_prepare($conn, "
    SELECT id
    FROM project_assignment_replacements
    WHERE original_assignment_id = ?
      AND status IN ('scheduled', 'active')
      AND NOT (replacement_to < ? OR replacement_from > ?)
    LIMIT 1
");
mysqli_stmt_bind_param($overlapCheck, "iss", $originalAssignmentId, $replacementFrom, $replacementTo);
mysqli_stmt_execute($overlapCheck);
$overlap = mysqli_fetch_assoc(mysqli_stmt_get_result($overlapCheck));
mysqli_stmt_close($overlapCheck);

if ($overlap) {
    redirect_replacement_error("A replacement already exists for this assignment in the selected date range.");
}

$status = date("Y-m-d") >= $replacementFrom && date("Y-m-d") <= $replacementTo ? "active" : "scheduled";

mysqli_begin_transaction($conn);

try {
    $stmt = mysqli_prepare($conn, "
        INSERT INTO project_assignment_replacements
        (project_id, leave_request_id, original_assignment_id, original_employee_id, replacement_employee_id,
         assignment_role_id, replacement_from, replacement_to, reason, status, created_by)
        VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param(
        $stmt,
        "iiiiissssi",
        $projectId,
        $originalAssignmentId,
        $originalEmployeeId,
        $replacementEmployeeId,
        $assignmentRoleId,
        $replacementFrom,
        $replacementTo,
        $reason,
        $status,
        $userId
    );
    mysqli_stmt_execute($stmt);
    $replacementId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $employeeSessionId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, 'CREATE', 'project_assignment_replacements', 'Scheduled temporary project replacement', ?, ?)
    ");
    mysqli_stmt_bind_param($log, "issssis", $employeeSessionId, $employeeName, $username, $designation, $department, $replacementId, $ip);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    mysqli_commit($conn);

    header("Location: ../project-assignments.php?replacement=1");
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirect_replacement_error("Unable to schedule replacement.");
}
?>
