<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../employees.php");
    exit;
}

function redirect_error($message)
{
    header("Location: ../employees.php?error=" . urlencode($message));
    exit;
}

$employeeId = (int)($_POST["employee_id"] ?? 0);

if ($employeeId <= 0) {
    redirect_error("Invalid employee.");
}

$stmt = mysqli_prepare($conn, "SELECT full_name FROM employees WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $employeeId);
mysqli_stmt_execute($stmt);
$employee = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$employee) {
    redirect_error("Employee not found.");
}

mysqli_begin_transaction($conn);

try {
    $del = mysqli_prepare($conn, "DELETE FROM employees WHERE id = ?");
    mysqli_stmt_bind_param($del, "i", $employeeId);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);

    $sessionEmployeeId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, 'DELETE', 'employees', 'Deleted employee', ?, ?)
    ");
    mysqli_stmt_bind_param($log, "issssis", $sessionEmployeeId, $employeeName, $username, $designation, $department, $employeeId, $ip);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    mysqli_commit($conn);

    header("Location: ../employees.php?deleted=1");
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirect_error("Unable to delete employee. This employee may be used in projects, leave requests or assignments.");
}
?>
