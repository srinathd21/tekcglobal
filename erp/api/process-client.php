<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../clients.php");
    exit;
}

function redirect_error($message, $clientId = 0)
{
    $target = $clientId > 0 ? "../client-edit.php?id=" . (int)$clientId : "../client-add.php";
    $separator = strpos($target, "?") !== false ? "&" : "?";
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

function table_has_column($conn, $table, $column)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && mysqli_num_rows($res) > 0;
}

$clientId = (int)($_POST["client_id"] ?? 0);

$clientName = clean($_POST["client_name"] ?? "");
$mobileNumber = clean($_POST["mobile_number"] ?? "");

if ($clientName === "" || $mobileNumber === "") {
    redirect_error("Client name and mobile number are required.", $clientId);
}

$email = clean($_POST["email"] ?? "");
$companyName = clean($_POST["company_name"] ?? "");
$officeAddress = clean($_POST["office_address"] ?? "");
$siteAddress = clean($_POST["site_address"] ?? "");
$state = clean($_POST["state"] ?? "");
$panNumber = clean($_POST["pan_number"] ?? "");
$gstNumber = clean($_POST["gst_number"] ?? "");
$aadhaarNumber = clean($_POST["aadhaar_number"] ?? "");
$billingAddress = clean($_POST["billing_address"] ?? "");
$shippingAddress = clean($_POST["shipping_address"] ?? "");
$password = clean($_POST["password"] ?? "");
$userId = $_SESSION["user_id"] ?? null;

$hasClientTypeId = table_has_column($conn, "clients", "client_type_id");
$hasClientTypeText = table_has_column($conn, "clients", "client_type");
$hasPassword = table_has_column($conn, "clients", "password");
$hasCreatedBy = table_has_column($conn, "clients", "created_by");
$hasUpdatedBy = table_has_column($conn, "clients", "updated_by");

$clientTypeId = $hasClientTypeId ? nullable_int($_POST["client_type_id"] ?? null) : null;
$clientTypeText = $hasClientTypeText ? clean($_POST["client_type"] ?? "") : "";

if ($hasClientTypeText && $clientTypeText === "" && $hasClientTypeId && $clientTypeId) {
    $typeStmt = mysqli_prepare($conn, "SELECT client_type_name FROM master_client_types WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($typeStmt, "i", $clientTypeId);
    mysqli_stmt_execute($typeStmt);
    $typeRow = mysqli_fetch_assoc(mysqli_stmt_get_result($typeStmt));
    mysqli_stmt_close($typeStmt);
    $clientTypeText = $typeRow["client_type_name"] ?? "Individual";
}

if ($hasClientTypeText && $clientTypeText === "") {
    $clientTypeText = "Individual";
}

try {
    if ($clientId > 0) {
        $fields = [
            "client_name" => $clientName,
            "mobile_number" => $mobileNumber,
            "email" => $email,
            "company_name" => $companyName,
            "office_address" => $officeAddress,
            "site_address" => $siteAddress,
            "state" => $state,
            "pan_number" => $panNumber,
            "gst_number" => $gstNumber,
            "aadhaar_number" => $aadhaarNumber,
            "billing_address" => $billingAddress,
            "shipping_address" => $shippingAddress
        ];

        if ($hasClientTypeId) {
            $fields["client_type_id"] = $clientTypeId;
        }

        if ($hasClientTypeText) {
            $fields["client_type"] = $clientTypeText;
        }

        if ($hasPassword && $password !== "") {
            $fields["password"] = password_hash($password, PASSWORD_DEFAULT);
        }

        if ($hasUpdatedBy) {
            $fields["updated_by"] = $userId;
        }

        $setParts = [];
        $values = [];
        $types = "";

        foreach ($fields as $column => $value) {
            $setParts[] = "`$column` = ?";
            $values[] = $value;

            if (in_array($column, ["client_type_id", "updated_by"], true)) {
                $types .= "i";
            } else {
                $types .= "s";
            }
        }

        $values[] = $clientId;
        $types .= "i";

        $stmt = mysqli_prepare($conn, "UPDATE clients SET " . implode(", ", $setParts) . " WHERE id = ?");
        mysqli_stmt_bind_param($stmt, $types, ...$values);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $activityType = "UPDATE";
        $description = "Updated client";
    } else {
        $fields = [
            "client_name" => $clientName,
            "mobile_number" => $mobileNumber,
            "email" => $email,
            "company_name" => $companyName,
            "office_address" => $officeAddress,
            "site_address" => $siteAddress,
            "state" => $state,
            "pan_number" => $panNumber,
            "gst_number" => $gstNumber,
            "aadhaar_number" => $aadhaarNumber,
            "billing_address" => $billingAddress,
            "shipping_address" => $shippingAddress
        ];

        if ($hasClientTypeId) {
            $fields["client_type_id"] = $clientTypeId;
        }

        if ($hasClientTypeText) {
            $fields["client_type"] = $clientTypeText;
        }

        if ($hasPassword) {
            if ($password === "") {
                redirect_error("Password is required.", 0);
            }

            $fields["password"] = password_hash($password, PASSWORD_DEFAULT);
        }

        if ($hasCreatedBy) {
            $fields["created_by"] = $userId;
        }

        $columns = array_keys($fields);
        $placeholders = array_fill(0, count($columns), "?");
        $values = array_values($fields);
        $types = "";

        foreach ($columns as $column) {
            if (in_array($column, ["client_type_id", "created_by"], true)) {
                $types .= "i";
            } else {
                $types .= "s";
            }
        }

        $stmt = mysqli_prepare($conn, "
            INSERT INTO clients (`" . implode("`, `", $columns) . "`)
            VALUES (" . implode(", ", $placeholders) . ")
        ");
        mysqli_stmt_bind_param($stmt, $types, ...$values);
        mysqli_stmt_execute($stmt);
        $clientId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $activityType = "CREATE";
        $description = "Created client";
    }

    $employeeId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, 'clients', ?, ?, ?)
    ");
    mysqli_stmt_bind_param($log, "issssssis", $employeeId, $employeeName, $username, $designation, $department, $activityType, $description, $clientId, $ip);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    header("Location: ../clients.php?success=1");
    exit;
} catch (Throwable $e) {
    redirect_error("Unable to save client: " . $e->getMessage(), $clientId);
}
?>
