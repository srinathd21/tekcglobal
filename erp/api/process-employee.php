<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../employees.php");
    exit;
}

function redirect_error($message, $employeeId = 0)
{
    $target = $employeeId > 0 ? "../employee-edit.php?id=" . (int)$employeeId : "../employee-add.php";
    $separator = strpos($target, "?") === false ? "?" : "&";
    header("Location: " . $target . $separator . "error=" . urlencode($message));
    exit;
}

function clean($value)
{
    return trim($value ?? "");
}

function nullable_int($value)
{
    return $value === "" || $value === null ? null : (int)$value;
}

function table_exists($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $q && mysqli_num_rows($q) > 0;
}

function get_office_location_name($conn, $officeLocationId)
{
    if ($officeLocationId <= 0) {
        return "";
    }

    $stmt = mysqli_prepare($conn, "SELECT location_name FROM master_office_locations WHERE id = ? AND is_active = 1 LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $officeLocationId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $row["location_name"] ?? "";
}

function sync_employee_office_location($conn, $employeeId, $officeLocationId)
{
    if ($employeeId <= 0 || $officeLocationId <= 0) {
        return;
    }

    if (!table_exists($conn, "employee_office_locations")) {
        return;
    }

    mysqli_query($conn, "DELETE FROM employee_office_locations WHERE employee_id = " . (int)$employeeId);

    $stmt = mysqli_prepare($conn, "
        INSERT INTO employee_office_locations (employee_id, office_location_id, is_primary)
        VALUES (?, ?, 1)
    ");
    mysqli_stmt_bind_param($stmt, "ii", $employeeId, $officeLocationId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function upload_file($field, $oldPath = "")
{
    if (empty($_FILES[$field]["name"])) {
        return $oldPath;
    }

    $allowed = ["jpg", "jpeg", "png", "webp", "pdf"];
    $ext = strtolower(pathinfo($_FILES[$field]["name"], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        throw new Exception("Invalid file type for " . $field);
    }

    $uploadDir = __DIR__ . "/../uploads/employees/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileName = $field . "_" . time() . "_" . rand(1000, 9999) . "." . $ext;
    $target = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES[$field]["tmp_name"], $target)) {
        throw new Exception("Unable to upload " . $field);
    }

    return "uploads/employees/" . $fileName;
}

$employeeId = (int)($_POST["employee_id"] ?? 0);

$fullName = clean($_POST["full_name"] ?? "");
$employeeCode = clean($_POST["employee_code"] ?? "");

if ($fullName === "" || $employeeCode === "") {
    redirect_error("Full name and employee code are required.", $employeeId);
}

$dateOfBirth = clean($_POST["date_of_birth"] ?? "");
$gender = clean($_POST["gender"] ?? "");
$bloodGroup = clean($_POST["blood_group"] ?? "");
$mobileNumber = clean($_POST["mobile_number"] ?? "");
$email = clean($_POST["email"] ?? "");
$currentAddress = clean($_POST["current_address"] ?? "");
$emergencyContactName = clean($_POST["emergency_contact_name"] ?? "");
$emergencyContactPhone = clean($_POST["emergency_contact_phone"] ?? "");
$dateOfJoining = clean($_POST["date_of_joining"] ?? "");
$departmentId = nullable_int($_POST["department_id"] ?? null);
$officeLocationId = nullable_int($_POST["office_location_id"] ?? null);
$roleId = nullable_int($_POST["role_id"] ?? null);
$reportingTo = nullable_int($_POST["reporting_to"] ?? null);
$workLocation = clean($_POST["work_location"] ?? "");
$siteName = clean($_POST["site_name"] ?? "");
$employeeStatus = clean($_POST["employee_status"] ?? "active");
$aadharCardNumber = clean($_POST["aadhar_card_number"] ?? "");
$pancardNumber = clean($_POST["pancard_number"] ?? "");
$bankAccountNumber = clean($_POST["bank_account_number"] ?? "");
$ifscCode = clean($_POST["ifsc_code"] ?? "");
$oldPhoto = clean($_POST["old_photo"] ?? "");
$oldPassbookPhoto = clean($_POST["old_passbook_photo"] ?? "");
$userId = $_SESSION["user_id"] ?? null;

$dateOfBirth = $dateOfBirth === "" ? null : $dateOfBirth;
$gender = $gender === "" ? null : $gender;
$dateOfJoining = $dateOfJoining === "" ? null : $dateOfJoining;

if (!in_array($employeeStatus, ["active", "inactive", "resigned"], true)) {
    $employeeStatus = "active";
}

if (!$officeLocationId || $officeLocationId <= 0) {
    redirect_error("Please select work location.", $employeeId);
}

$officeLocationName = get_office_location_name($conn, (int)$officeLocationId);

if ($officeLocationName === "") {
    redirect_error("Selected work location is invalid or inactive.", $employeeId);
}

if ($workLocation === "") {
    $workLocation = $officeLocationName;
}

try {
    $photo = upload_file("photo", $oldPhoto);
    $passbookPhoto = upload_file("passbook_photo", $oldPassbookPhoto);

    if ($employeeId > 0) {
        $stmt = mysqli_prepare($conn, "
            UPDATE employees
            SET
                full_name = ?,
                employee_code = ?,
                photo = ?,
                date_of_birth = ?,
                gender = ?,
                blood_group = ?,
                mobile_number = ?,
                email = ?,
                current_address = ?,
                emergency_contact_name = ?,
                emergency_contact_phone = ?,
                date_of_joining = ?,
                department_id = ?,
                office_location_id = ?,
                role_id = ?,
                reporting_to = ?,
                work_location = ?,
                site_name = ?,
                employee_status = ?,
                aadhar_card_number = ?,
                pancard_number = ?,
                bank_account_number = ?,
                ifsc_code = ?,
                passbook_photo = ?,
                updated_by = ?
            WHERE id = ?
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "ssssssssssssiiiissssssssii",
            $fullName,
            $employeeCode,
            $photo,
            $dateOfBirth,
            $gender,
            $bloodGroup,
            $mobileNumber,
            $email,
            $currentAddress,
            $emergencyContactName,
            $emergencyContactPhone,
            $dateOfJoining,
            $departmentId,
            $officeLocationId,
            $roleId,
            $reportingTo,
            $workLocation,
            $siteName,
            $employeeStatus,
            $aadharCardNumber,
            $pancardNumber,
            $bankAccountNumber,
            $ifscCode,
            $passbookPhoto,
            $userId,
            $employeeId
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $activityType = "UPDATE";
        $description = "Updated employee";
    } else {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO employees
            (
                full_name,
                employee_code,
                photo,
                date_of_birth,
                gender,
                blood_group,
                mobile_number,
                email,
                current_address,
                emergency_contact_name,
                emergency_contact_phone,
                date_of_joining,
                department_id,
                office_location_id,
                role_id,
                reporting_to,
                work_location,
                site_name,
                employee_status,
                aadhar_card_number,
                pancard_number,
                bank_account_number,
                ifsc_code,
                passbook_photo,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "ssssssssssssiiiissssssssi",
            $fullName,
            $employeeCode,
            $photo,
            $dateOfBirth,
            $gender,
            $bloodGroup,
            $mobileNumber,
            $email,
            $currentAddress,
            $emergencyContactName,
            $emergencyContactPhone,
            $dateOfJoining,
            $departmentId,
            $officeLocationId,
            $roleId,
            $reportingTo,
            $workLocation,
            $siteName,
            $employeeStatus,
            $aadharCardNumber,
            $pancardNumber,
            $bankAccountNumber,
            $ifscCode,
            $passbookPhoto,
            $userId
        );

        mysqli_stmt_execute($stmt);
        $employeeId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $activityType = "CREATE";
        $description = "Created employee";
    }

    sync_employee_office_location($conn, (int)$employeeId, (int)$officeLocationId);

    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $sessionEmployeeId = $_SESSION["employee_id"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, 'employees', ?, ?, ?)
    ");
    mysqli_stmt_bind_param($log, "issssssis", $sessionEmployeeId, $employeeName, $username, $designation, $department, $activityType, $description, $employeeId, $ip);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    header("Location: ../employees.php?success=1");
    exit;
} catch (Throwable $e) {
    redirect_error($e->getMessage(), $employeeId);
}
?>
