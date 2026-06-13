<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/includes/db.php";

/*
|--------------------------------------------------------------------------
| Optional logout activity log
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION["user_id"]) && isset($conn)) {
    $employeeId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? ($_SESSION["name"] ?? "User");
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $referenceId = (int) $_SESSION["user_id"];
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, 'LOGOUT', 'auth', 'User logged out', ?, ?)
    ");

    if ($log) {
        mysqli_stmt_bind_param($log, "issssis", $employeeId, $employeeName, $username, $designation, $department, $referenceId, $ip);
        mysqli_stmt_execute($log);
        mysqli_stmt_close($log);
    }
}

/*
|--------------------------------------------------------------------------
| Destroy session safely
|--------------------------------------------------------------------------
*/

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

header("Location: login.php");
exit;
?>
