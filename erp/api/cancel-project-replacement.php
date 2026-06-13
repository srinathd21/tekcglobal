<?php
require_once __DIR__ . "/../includes/db.php";
require_permission($conn, "can_edit", "project-assignments.php");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../project-assignments.php");
    exit;
}

function redirect_cancel_replacement_error($message)
{
    header("Location: ../project-assignments.php?error=" . urlencode($message));
    exit;
}

$replacementId = (int)($_POST["replacement_id"] ?? 0);
$userId = $_SESSION["user_id"] ?? null;

if ($replacementId <= 0) {
    redirect_cancel_replacement_error("Invalid replacement.");
}

$stmt = mysqli_prepare($conn, "
    SELECT id
    FROM project_assignment_replacements
    WHERE id = ? AND status IN ('scheduled', 'active')
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, "i", $replacementId);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$row) {
    redirect_cancel_replacement_error("Replacement not found or already closed.");
}

mysqli_begin_transaction($conn);

try {
    $update = mysqli_prepare($conn, "
        UPDATE project_assignment_replacements
        SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW()
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($update, "ii", $userId, $replacementId);
    mysqli_stmt_execute($update);
    mysqli_stmt_close($update);

    $employeeSessionId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, 'UPDATE', 'project_assignment_replacements', 'Cancelled temporary project replacement', ?, ?)
    ");
    mysqli_stmt_bind_param($log, "issssis", $employeeSessionId, $employeeName, $username, $designation, $department, $replacementId, $ip);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    mysqli_commit($conn);

    header("Location: ../project-assignments.php?cancelled=1");
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirect_cancel_replacement_error("Unable to cancel replacement.");
}
?>
