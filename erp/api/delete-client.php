<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../clients.php");
    exit;
}

function redirect_error($message)
{
    header("Location: ../clients.php?error=" . urlencode($message));
    exit;
}

$clientId = (int)($_POST["client_id"] ?? 0);

if ($clientId <= 0) {
    redirect_error("Invalid client.");
}

$stmt = mysqli_prepare($conn, "SELECT client_name FROM clients WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $clientId);
mysqli_stmt_execute($stmt);
$client = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$client) {
    redirect_error("Client not found.");
}

mysqli_begin_transaction($conn);

try {
    $del = mysqli_prepare($conn, "DELETE FROM clients WHERE id = ?");
    mysqli_stmt_bind_param($del, "i", $clientId);
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
        VALUES (?, ?, ?, ?, ?, 'DELETE', 'clients', 'Deleted client', ?, ?)
    ");
    mysqli_stmt_bind_param($log, "issssis", $employeeId, $employeeName, $username, $designation, $department, $clientId, $ip);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    mysqli_commit($conn);

    header("Location: ../clients.php?deleted=1");
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirect_error("Unable to delete client. This client may be used in projects.");
}
?>
