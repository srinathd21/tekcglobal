<?php
session_start();
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/leave-module-helper.php";

function master_redirect($type, $params = []) {
    $params["type"] = $type;
    header("Location: ../master-control.php?" . http_build_query($params));
    exit;
}

$type = $_POST["type"] ?? "";
$id = (int)($_POST["id"] ?? 0);
$userId = $_SESSION["user_id"] ?? null;

try {
    if ($type === "leave_types") {
        $name = lm_clean($_POST["name"] ?? "");
        $code = lm_clean($_POST["code"] ?? "");
        $allowedDays = (float)($_POST["allowed_days"] ?? 0);
        $carryForward = (int)($_POST["carry_forward_allowed"] ?? 0);
        $requiresDocument = (int)($_POST["requires_document"] ?? 0);
        $description = lm_clean($_POST["description"] ?? "");
        $sortOrder = (int)($_POST["sort_order"] ?? 0);
        $isActive = (int)($_POST["is_active"] ?? 1);

        if ($name === "" || $code === "") master_redirect($type, ["error" => "Name and code are required."]);

        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "
                UPDATE master_leave_types
                SET leave_type_name=?, leave_type_code=?, allowed_days=?, carry_forward_allowed=?,
                    requires_document=?, description=?, sort_order=?, is_active=?, updated_by=?
                WHERE id=?
            ");
            mysqli_stmt_bind_param($stmt, "ssdiiisiii", $name, $code, $allowedDays, $carryForward, $requiresDocument, $description, $sortOrder, $isActive, $userId, $id);
        } else {
            $stmt = mysqli_prepare($conn, "
                INSERT INTO master_leave_types
                (leave_type_name, leave_type_code, allowed_days, carry_forward_allowed, requires_document, description, sort_order, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            mysqli_stmt_bind_param($stmt, "ssdiiisii", $name, $code, $allowedDays, $carryForward, $requiresDocument, $description, $sortOrder, $isActive, $userId);
        }

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        master_redirect($type, ["success" => 1]);
    }

    if ($type === "holidays") {
        $name = lm_clean($_POST["name"] ?? "");
        $code = lm_clean($_POST["code"] ?? "");
        $holidayDate = lm_clean($_POST["holiday_date"] ?? "");
        $holidayType = lm_clean($_POST["holiday_type"] ?? "company");
        $officeLocationId = lm_clean($_POST["office_location_id"] ?? "") !== "" ? (int)$_POST["office_location_id"] : null;
        $description = lm_clean($_POST["description"] ?? "");
        $isActive = (int)($_POST["is_active"] ?? 1);

        if ($name === "" || $holidayDate === "") master_redirect($type, ["error" => "Name and holiday date are required."]);
        if ($code === "") $code = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $name)) . "-" . date("Ymd", strtotime($holidayDate));

        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "
                UPDATE master_holidays
                SET holiday_name=?, holiday_code=?, holiday_date=?, holiday_type=?, office_location_id=?,
                    description=?, is_active=?, updated_by=?
                WHERE id=?
            ");
            mysqli_stmt_bind_param($stmt, "ssssissii", $name, $code, $holidayDate, $holidayType, $officeLocationId, $description, $isActive, $userId, $id);
        } else {
            $stmt = mysqli_prepare($conn, "
                INSERT INTO master_holidays
                (holiday_name, holiday_code, holiday_date, holiday_type, office_location_id, description, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            mysqli_stmt_bind_param($stmt, "ssssisii", $name, $code, $holidayDate, $holidayType, $officeLocationId, $description, $isActive, $userId);
        }

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        master_redirect($type, ["success" => 1]);
    }

    master_redirect("departments", ["error" => "Invalid master type."]);
} catch (Throwable $e) {
    master_redirect($type ?: "departments", ["error" => $e->getMessage()]);
}
?>