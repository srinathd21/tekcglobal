<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../users.php");
    exit;
}

function redirect_error($message)
{
    header("Location: ../users.php?error=" . urlencode($message));
    exit;
}

function clean($value)
{
    return trim($value ?? "");
}

$userId = (int)($_POST["user_id"] ?? 0);
$employeeId = (int)($_POST["employee_id"] ?? 0);
$divisionId = (int)($_POST["division_id"] ?? 0);
$name = clean($_POST["name"] ?? "");
$username = clean($_POST["username"] ?? "");
$email = clean($_POST["email"] ?? "");
$mobileNumber = clean($_POST["mobile_number"] ?? "");
$password = clean($_POST["password"] ?? "");
$confirmPassword = clean($_POST["confirm_password"] ?? "");
$status = clean($_POST["status"] ?? "active");
$roleId = (int)($_POST["role_id"] ?? 0);
$sessionUserId = $_SESSION["user_id"] ?? null;

if ($name === "" || $username === "") {
    redirect_error("Name and username are required.");
}

if ($divisionId <= 0) {
    redirect_error("Please select division.");
}

if ($roleId <= 0) {
    redirect_error("Please select role.");
}

if (!in_array($status, ["active", "inactive", "blocked"], true)) {
    $status = "active";
}

if ($password !== "" || $userId <= 0) {
    if ($password === "" || strlen($password) < 6) {
        redirect_error("Password must be at least 6 characters.");
    }

    if ($password !== $confirmPassword) {
        redirect_error("Password and confirm password do not match.");
    }
}

$divisionCheck = mysqli_prepare($conn, "SELECT id FROM company_divisions WHERE id = ? AND is_active = 1 LIMIT 1");
mysqli_stmt_bind_param($divisionCheck, "i", $divisionId);
mysqli_stmt_execute($divisionCheck);
$validDivision = mysqli_fetch_assoc(mysqli_stmt_get_result($divisionCheck));
mysqli_stmt_close($divisionCheck);

if (!$validDivision) {
    redirect_error("Selected division is invalid or inactive.");
}

$roleCheck = mysqli_prepare($conn, "SELECT id FROM roles WHERE id = ? AND is_active = 1 LIMIT 1");
mysqli_stmt_bind_param($roleCheck, "i", $roleId);
mysqli_stmt_execute($roleCheck);
$validRole = mysqli_fetch_assoc(mysqli_stmt_get_result($roleCheck));
mysqli_stmt_close($roleCheck);

if (!$validRole) {
    redirect_error("Selected role is invalid or inactive.");
}

$checkUser = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
mysqli_stmt_bind_param($checkUser, "si", $username, $userId);
mysqli_stmt_execute($checkUser);
$usernameExists = mysqli_fetch_assoc(mysqli_stmt_get_result($checkUser));
mysqli_stmt_close($checkUser);

if ($usernameExists) {
    redirect_error("Username already exists.");
}

if ($email !== "") {
    $checkEmail = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
    mysqli_stmt_bind_param($checkEmail, "si", $email, $userId);
    mysqli_stmt_execute($checkEmail);
    $emailExists = mysqli_fetch_assoc(mysqli_stmt_get_result($checkEmail));
    mysqli_stmt_close($checkEmail);

    if ($emailExists) {
        redirect_error("Email already exists.");
    }
}

mysqli_begin_transaction($conn);

try {
    if ($userId > 0) {
        $userEmployeeStmt = mysqli_prepare($conn, "SELECT employee_id FROM users WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($userEmployeeStmt, "i", $userId);
        mysqli_stmt_execute($userEmployeeStmt);
        $userEmployee = mysqli_fetch_assoc(mysqli_stmt_get_result($userEmployeeStmt));
        mysqli_stmt_close($userEmployeeStmt);

        $employeeId = (int)($userEmployee["employee_id"] ?? 0);

        if ($employeeId <= 0) {
            throw new Exception("User employee link not found.");
        }

        if ($password !== "") {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = mysqli_prepare($conn, "
                UPDATE users
                SET name = ?, email = ?, mobile_number = ?, username = ?, password = ?, status = ?, updated_by = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, "ssssssii", $name, $email, $mobileNumber, $username, $passwordHash, $status, $sessionUserId, $userId);
        } else {
            $stmt = mysqli_prepare($conn, "
                UPDATE users
                SET name = ?, email = ?, mobile_number = ?, username = ?, status = ?, updated_by = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, "sssssii", $name, $email, $mobileNumber, $username, $status, $sessionUserId, $userId);
        }

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $updateEmployee = mysqli_prepare($conn, "UPDATE employees SET division_id = ?, updated_by = ? WHERE id = ?");
        mysqli_stmt_bind_param($updateEmployee, "iii", $divisionId, $sessionUserId, $employeeId);
        mysqli_stmt_execute($updateEmployee);
        mysqli_stmt_close($updateEmployee);

        $deleteRoles = mysqli_prepare($conn, "DELETE FROM user_roles WHERE user_id = ?");
        mysqli_stmt_bind_param($deleteRoles, "i", $userId);
        mysqli_stmt_execute($deleteRoles);
        mysqli_stmt_close($deleteRoles);

        $activityType = "UPDATE";
        $description = "Updated user account and employee division";
    } else {
        if ($employeeId <= 0) {
            redirect_error("Please select a registered employee.");
        }

        $employeeStmt = mysqli_prepare($conn, "
            SELECT e.*, u.id AS existing_user_id
            FROM employees e
            LEFT JOIN users u ON u.employee_id = e.id
            WHERE e.id = ?
            LIMIT 1
        ");
        mysqli_stmt_bind_param($employeeStmt, "i", $employeeId);
        mysqli_stmt_execute($employeeStmt);
        $employeeRow = mysqli_fetch_assoc(mysqli_stmt_get_result($employeeStmt));
        mysqli_stmt_close($employeeStmt);

        if (!$employeeRow) {
            redirect_error("Selected employee not found.");
        }

        if ($employeeRow["employee_status"] !== "active") {
            redirect_error("Only active registered employees can get user login.");
        }

        if (!empty($employeeRow["existing_user_id"]) || !empty($employeeRow["user_id"])) {
            redirect_error("This employee already has a user account.");
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = mysqli_prepare($conn, "
            INSERT INTO users
            (employee_id, name, email, mobile_number, username, password, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        mysqli_stmt_bind_param($stmt, "issssssi", $employeeId, $name, $email, $mobileNumber, $username, $passwordHash, $status, $sessionUserId);
        mysqli_stmt_execute($stmt);
        $userId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $updateEmployee = mysqli_prepare($conn, "UPDATE employees SET user_id = ?, division_id = ?, updated_by = ? WHERE id = ?");
        mysqli_stmt_bind_param($updateEmployee, "iiii", $userId, $divisionId, $sessionUserId, $employeeId);
        mysqli_stmt_execute($updateEmployee);
        mysqli_stmt_close($updateEmployee);

        $activityType = "CREATE";
        $description = "Created user account for registered employee and assigned division";
    }

    $roleStmt = mysqli_prepare($conn, "
        INSERT INTO user_roles
        (user_id, role_id, assigned_by)
        VALUES (?, ?, ?)
    ");
    mysqli_stmt_bind_param($roleStmt, "iii", $userId, $roleId, $sessionUserId);
    mysqli_stmt_execute($roleStmt);
    mysqli_stmt_close($roleStmt);

    $sessionEmployeeId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $sessionUsername = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, 'users', ?, ?, ?)
    ");
    mysqli_stmt_bind_param($log, "issssssis", $sessionEmployeeId, $employeeName, $sessionUsername, $designation, $department, $activityType, $description, $userId, $ip);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    mysqli_commit($conn);

    header("Location: ../users.php?success=1");
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirect_error("Unable to save user account. " . $e->getMessage());
}
?>