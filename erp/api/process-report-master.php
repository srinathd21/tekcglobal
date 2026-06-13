<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

function redirect_back($params = [])
{
    header("Location: ../report-master.php" . (!empty($params) ? "?" . http_build_query($params) : ""));
    exit;
}

function clean_report_file_name($value)
{
    $value = basename(trim((string)$value));

    if (!preg_match('/^[a-zA-Z0-9_\-]+\.php$/', $value)) {
        throw new Exception("File name must be a valid PHP file name. Example: dar.php");
    }

    return $value;
}

function log_report_activity($conn, $type, $description, $referenceId = null)
{
    if (!function_exists("mysqli_query")) {
        return;
    }

    $employeeId = (int)($_SESSION["employee_id"] ?? 0);
    $employeeName = mysqli_real_escape_string($conn, $_SESSION["employee_name"] ?? $_SESSION["name"] ?? "");
    $username = mysqli_real_escape_string($conn, $_SESSION["username"] ?? "");
    $designation = mysqli_real_escape_string($conn, $_SESSION["designation"] ?? "");
    $department = mysqli_real_escape_string($conn, $_SESSION["department"] ?? "");
    $activityType = mysqli_real_escape_string($conn, $type);
    $module = "Report Master";
    $desc = mysqli_real_escape_string($conn, $description);
    $ref = $referenceId !== null ? (int)$referenceId : "NULL";
    $ip = mysqli_real_escape_string($conn, $_SERVER["REMOTE_ADDR"] ?? "");

    mysqli_query($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES ($employeeId, '$employeeName', '$username', '$designation', '$department', '$activityType', '$module', '$desc', $ref, '$ip')
    ");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    redirect_back(["error" => "Invalid request."]);
}

$reportId = (int)($_POST["report_id"] ?? 0);

try {
    if ($reportId > 0) {
        require_permission($conn, "can_edit", "report-master.php");
    } else {
        require_permission($conn, "can_create", "report-master.php");
    }

    $reportName = trim($_POST["report_name"] ?? "");
    $reportCode = strtoupper(trim($_POST["report_code"] ?? ""));
    $submitFileName = clean_report_file_name($_POST["submit_file_name"] ?? "");
    $printFileName = clean_report_file_name($_POST["print_file_name"] ?? "");
    $frequencyType = trim($_POST["frequency_type"] ?? "daily");
    $customDays = (int)($_POST["custom_days"] ?? 0);
    $description = trim($_POST["description"] ?? "");
    $sortOrder = (int)($_POST["sort_order"] ?? 0);
    $requiresTlRemark = (int)($_POST["requires_tl_remark"] ?? 1);
    $requiresManagerRemark = (int)($_POST["requires_manager_remark"] ?? 1);
    $isActive = (int)($_POST["is_active"] ?? 1);
    $userId = (int)($_SESSION["user_id"] ?? 0);

    if ($reportName === "" || $reportCode === "") {
        throw new Exception("Report name and report code are required.");
    }

    if (!preg_match('/^[A-Z0-9_]{2,30}$/', $reportCode)) {
        throw new Exception("Report code must be uppercase letters/numbers only.");
    }

    if (!in_array($frequencyType, ["daily", "weekly", "monthly", "custom_days", "on_demand"], true)) {
        throw new Exception("Invalid frequency.");
    }

    $customDaysSql = "NULL";
    if ($frequencyType === "custom_days") {
        if ($customDays <= 0) {
            throw new Exception("Custom days must be greater than zero.");
        }
        $customDaysSql = (string)$customDays;
    }

    if ($reportId > 0) {
        $stmt = mysqli_prepare($conn, "
            UPDATE master_report_types
            SET report_name = ?,
                report_code = ?,
                submit_file_name = ?,
                print_file_name = ?,
                frequency_type = ?,
                custom_days = $customDaysSql,
                description = ?,
                sort_order = ?,
                requires_tl_remark = ?,
                requires_manager_remark = ?,
                is_active = ?,
                updated_by = ?
            WHERE id = ?
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "ssssssiiiiii",
            $reportName,
            $reportCode,
            $submitFileName,
            $printFileName,
            $frequencyType,
            $description,
            $sortOrder,
            $requiresTlRemark,
            $requiresManagerRemark,
            $isActive,
            $userId,
            $reportId
        );

        if (!$stmt || !mysqli_stmt_execute($stmt)) {
            throw new Exception("Unable to update report: " . mysqli_error($conn));
        }

        mysqli_stmt_close($stmt);
        log_report_activity($conn, "update", "Updated report master: " . $reportName, $reportId);
    } else {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO master_report_types
            (report_name, report_code, submit_file_name, print_file_name, frequency_type, custom_days,
             description, sort_order, requires_tl_remark, requires_manager_remark, is_active, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, $customDaysSql, ?, ?, ?, ?, ?, ?, ?)
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "ssssssiiiiii",
            $reportName,
            $reportCode,
            $submitFileName,
            $printFileName,
            $frequencyType,
            $description,
            $sortOrder,
            $requiresTlRemark,
            $requiresManagerRemark,
            $isActive,
            $userId,
            $userId
        );

        if (!$stmt || !mysqli_stmt_execute($stmt)) {
            throw new Exception("Unable to create report: " . mysqli_error($conn));
        }

        $reportId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        log_report_activity($conn, "create", "Created report master: " . $reportName, $reportId);
    }

    redirect_back(["success" => 1]);
} catch (Throwable $e) {
    redirect_back(["error" => $e->getMessage()]);
}
?>