<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

if (empty($_SESSION["user_id"])) {
    header("Location: ../login.php?error=" . urlencode("Please login first."));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../profile.php");
    exit;
}

function redirect_profile($params = [])
{
    header("Location: ../profile.php" . ($params ? "?" . http_build_query($params) : ""));
    exit;
}

function redirect_profile_error($message)
{
    redirect_profile(["error" => $message]);
}

function clean($value)
{
    return trim($value ?? "");
}

function table_exists_profile_process($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $q && mysqli_num_rows($q) > 0;
}

function column_exists_profile_process($conn, $table, $column)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && mysqli_num_rows($q) > 0;
}

function upload_profile_photo($fieldName)
{
    if (empty($_FILES[$fieldName]["name"])) {
        return "";
    }

    $allowed = ["jpg", "jpeg", "png", "webp"];
    $ext = strtolower(pathinfo($_FILES[$fieldName]["name"], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        throw new Exception("Only JPG, PNG and WEBP image files are allowed.");
    }

    if (!empty($_FILES[$fieldName]["size"]) && $_FILES[$fieldName]["size"] > 3 * 1024 * 1024) {
        throw new Exception("Profile photo size must be less than 3MB.");
    }

    $uploadDir = __DIR__ . "/../uploads/profile/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileName = "profile_" . time() . "_" . mt_rand(1000, 9999) . "." . $ext;
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES[$fieldName]["tmp_name"], $targetPath)) {
        throw new Exception("Unable to upload profile photo.");
    }

    return "uploads/profile/" . $fileName;
}

function log_profile_activity($conn, $type, $description, $referenceId = null)
{
    if (!table_exists_profile_process($conn, "activity_logs")) {
        return;
    }

    $employeeName = $_SESSION["employee_name"] ?? $_SESSION["name"] ?? "User";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $employeeId = $_SESSION["employee_id"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $stmt = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, 'profile', ?, ?, ?)
    ");

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "issssssis", $employeeId, $employeeName, $username, $designation, $department, $type, $description, $referenceId, $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

$userId = (int)$_SESSION["user_id"];
$action = $_POST["action"] ?? "";

$stmt = mysqli_prepare($conn, "SELECT id, employee_id, name, email, mobile_number, username, password FROM users WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$user) {
    header("Location: ../logout.php");
    exit;
}

try {
    if ($action === "update_profile") {
        $name = clean($_POST["name"] ?? "");
        $email = clean($_POST["email"] ?? "");
        $mobileNumber = clean($_POST["mobile_number"] ?? "");
        $currentAddress = clean($_POST["current_address"] ?? "");
        $emergencyContactName = clean($_POST["emergency_contact_name"] ?? "");
        $emergencyContactPhone = clean($_POST["emergency_contact_phone"] ?? "");

        if ($name === "") {
            redirect_profile_error("Name is required.");
        }

        if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirect_profile_error("Please enter a valid email address.");
        }

        if ($email !== "") {
            $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, "si", $email, $userId);
            mysqli_stmt_execute($stmt);
            $duplicateUserEmail = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if ($duplicateUserEmail) {
                redirect_profile_error("Email is already used by another user.");
            }

            if (!empty($user["employee_id"])) {
                $employeeId = (int)$user["employee_id"];
                $stmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE email = ? AND id <> ? LIMIT 1");
                mysqli_stmt_bind_param($stmt, "si", $email, $employeeId);
                mysqli_stmt_execute($stmt);
                $duplicateEmployeeEmail = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if ($duplicateEmployeeEmail) {
                    redirect_profile_error("Email is already used by another employee.");
                }
            }
        }

        $profilePhoto = upload_profile_photo("profile_photo");

        if ($profilePhoto !== "") {
            $stmt = mysqli_prepare($conn, "
                UPDATE users
                SET name = ?, email = ?, mobile_number = ?, profile_photo = ?, updated_by = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, "ssssii", $name, $email, $mobileNumber, $profilePhoto, $userId, $userId);
        } else {
            $stmt = mysqli_prepare($conn, "
                UPDATE users
                SET name = ?, email = ?, mobile_number = ?, updated_by = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, "sssii", $name, $email, $mobileNumber, $userId, $userId);
        }

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!empty($user["employee_id"])) {
            $employeeId = (int)$user["employee_id"];

            $sets = [];
            $values = [];
            $types = "";

            if (column_exists_profile_process($conn, "employees", "full_name")) {
                $sets[] = "full_name = ?";
                $values[] = $name;
                $types .= "s";
            }

            if (column_exists_profile_process($conn, "employees", "email")) {
                $sets[] = "email = ?";
                $values[] = $email;
                $types .= "s";
            }

            if (column_exists_profile_process($conn, "employees", "mobile_number")) {
                $sets[] = "mobile_number = ?";
                $values[] = $mobileNumber;
                $types .= "s";
            }

            if (column_exists_profile_process($conn, "employees", "current_address")) {
                $sets[] = "current_address = ?";
                $values[] = $currentAddress;
                $types .= "s";
            }

            if (column_exists_profile_process($conn, "employees", "emergency_contact_name")) {
                $sets[] = "emergency_contact_name = ?";
                $values[] = $emergencyContactName;
                $types .= "s";
            }

            if (column_exists_profile_process($conn, "employees", "emergency_contact_phone")) {
                $sets[] = "emergency_contact_phone = ?";
                $values[] = $emergencyContactPhone;
                $types .= "s";
            }

            if ($profilePhoto !== "" && column_exists_profile_process($conn, "employees", "photo")) {
                $sets[] = "photo = ?";
                $values[] = $profilePhoto;
                $types .= "s";
            }

            if (column_exists_profile_process($conn, "employees", "updated_by")) {
                $sets[] = "updated_by = ?";
                $values[] = $userId;
                $types .= "i";
            }

            if (!empty($sets)) {
                $values[] = $employeeId;
                $types .= "i";

                $sql = "UPDATE employees SET " . implode(", ", $sets) . " WHERE id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, $types, ...$values);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }

        $_SESSION["name"] = $name;
        $_SESSION["employee_name"] = $name;
        $_SESSION["email"] = $email;
        $_SESSION["mobile_number"] = $mobileNumber;

        log_profile_activity($conn, "UPDATE", "Updated own profile", $userId);
        redirect_profile(["success" => 1]);
    }

    if ($action === "update_password") {
        $currentPassword = $_POST["current_password"] ?? "";
        $newPassword = $_POST["new_password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";

        if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
            redirect_profile_error("All password fields are required.");
        }

        if (!password_verify($currentPassword, $user["password"])) {
            redirect_profile_error("Current password is incorrect.");
        }

        if (strlen($newPassword) < 8) {
            redirect_profile_error("New password must be at least 8 characters.");
        }

        if ($newPassword !== $confirmPassword) {
            redirect_profile_error("New password and confirm password do not match.");
        }

        if (password_verify($newPassword, $user["password"])) {
            redirect_profile_error("New password must be different from current password.");
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, updated_by = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "sii", $passwordHash, $userId, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        log_profile_activity($conn, "UPDATE", "Updated own password", $userId);
        redirect_profile(["password_updated" => 1]);
    }

    redirect_profile_error("Invalid profile action.");
} catch (Throwable $e) {
    redirect_profile_error($e->getMessage());
}
?>
