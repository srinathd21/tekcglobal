<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

$id = (int)($_GET["id"] ?? 0);

if ($id <= 0) {
    header("Location: ../sidebar-control.php?error=" . urlencode("Invalid sidebar menu."));
    exit;
}

$userId = $_SESSION["user_id"] ?? null;

$stmt = mysqli_prepare($conn, "UPDATE sidebar_menus SET is_active = IF(is_active = 1, 0, 1), updated_by = ? WHERE id = ?");
mysqli_stmt_bind_param($stmt, "ii", $userId, $id);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

$employeeId = $_SESSION["employee_id"] ?? null;
$employeeName = $_SESSION["employee_name"] ?? "Admin";
$username = $_SESSION["username"] ?? null;
$designation = $_SESSION["designation"] ?? null;
$department = $_SESSION["department"] ?? null;
$ip = $_SERVER["REMOTE_ADDR"] ?? null;

$log = mysqli_prepare($conn, "
    INSERT INTO activity_logs
    (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
    VALUES (?, ?, ?, ?, ?, 'UPDATE', 'sidebar_menus', 'Toggled sidebar menu status', ?, ?)
");
mysqli_stmt_bind_param($log, "issssis", $employeeId, $employeeName, $username, $designation, $department, $id, $ip);
mysqli_stmt_execute($log);
mysqli_stmt_close($log);

header("Location: ../sidebar-control.php?status=1");
exit;
?>
