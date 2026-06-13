<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

function redirect_back($type, $params = [])
{
    $params["type"] = $type;
    header("Location: ../master-control.php?" . http_build_query($params));
    exit;
}

function clean($value)
{
    return trim($value ?? "");
}

function log_master_activity($conn, $activityType, $module, $description, $referenceId = null)
{
    $employeeId = (int)($_SESSION["employee_id"] ?? 0);
    $employeeName = mysqli_real_escape_string($conn, $_SESSION["employee_name"] ?? $_SESSION["name"] ?? "Admin");
    $username = mysqli_real_escape_string($conn, $_SESSION["username"] ?? "");
    $designation = mysqli_real_escape_string($conn, $_SESSION["designation"] ?? "");
    $department = mysqli_real_escape_string($conn, $_SESSION["department"] ?? "");
    $activityType = mysqli_real_escape_string($conn, strtoupper($activityType));
    $module = mysqli_real_escape_string($conn, $module);
    $description = mysqli_real_escape_string($conn, $description);
    $referenceSql = $referenceId !== null ? (int)$referenceId : "NULL";
    $ip = mysqli_real_escape_string($conn, $_SERVER["REMOTE_ADDR"] ?? "");

    mysqli_query($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES ($employeeId, '$employeeName', '$username', '$designation', '$department', '$activityType', '$module', '$description', $referenceSql, '$ip')
    ");
}

$masters = [
    'departments' => ['table' => 'master_departments', 'name' => 'department_name', 'code' => 'department_code', 'module' => 'Departments'],
    'divisions' => ['table' => 'company_divisions', 'name' => 'division_name', 'code' => 'division_code', 'module' => 'Divisions'],
    'office_locations' => ['table' => 'master_office_locations', 'name' => 'location_name', 'code' => 'location_code', 'module' => 'Office Locations'],
    'client_types' => ['table' => 'master_client_types', 'name' => 'client_type_name', 'code' => 'client_type_code', 'module' => 'Client Types'],
    'project_types' => ['table' => 'master_project_types', 'name' => 'project_type_name', 'code' => 'project_type_code', 'module' => 'Project Types'],
    'project_statuses' => ['table' => 'master_project_statuses', 'name' => 'status_name', 'code' => 'status_code', 'module' => 'Project Statuses'],
    'notification_reasons' => ['table' => 'master_notification_reasons', 'name' => 'reason_name', 'code' => 'reason_code', 'module' => 'Notification Reasons'],
    'assignment_roles' => ['table' => 'project_assignment_roles', 'name' => 'role_name', 'code' => 'role_key', 'module' => 'Project Assignment Roles']
];

$type = $_POST["type"] ?? "departments";

if (!isset($masters[$type])) {
    redirect_back("departments", ["error" => "Invalid master type."]);
}

$config = $masters[$type];
$table = $config["table"];
$nameCol = $config["name"];
$codeCol = $config["code"];
$module = $config["module"];

$id = (int)($_POST["id"] ?? 0);
$name = clean($_POST["name"] ?? "");
$code = clean($_POST["code"] ?? "");

if ($name === "" || $code === "") {
    redirect_back($type, ["error" => "Name and code are required."]);
}

try {
    if ($type === "office_locations") {
        $address = clean($_POST["address"] ?? "");
        $city = clean($_POST["city"] ?? "");
        $state = clean($_POST["state"] ?? "");
        $pincode = clean($_POST["pincode"] ?? "");
        $latitude = clean($_POST["latitude"] ?? "");
        $longitude = clean($_POST["longitude"] ?? "");
        $radius = (int)($_POST["location_radius"] ?? 100);
        $isHeadOffice = (int)($_POST["is_head_office"] ?? 0);
        $sortOrder = (int)($_POST["office_sort_order"] ?? 0);
        $isActive = (int)($_POST["is_active"] ?? 1);

        if ($radius < 10 || $radius > 5000) {
            redirect_back($type, ["error" => "Employee punch-in radius must be between 10 and 5000 meters."]);
        }

        if ($latitude === "" || $longitude === "") {
            redirect_back($type, ["error" => "Please select office location on Google Map. Latitude and longitude are required for punch-in radius."]);
        }

        $latitudeValue = (float)$latitude;
        $longitudeValue = (float)$longitude;

        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "
                UPDATE master_office_locations
                SET location_name = ?, location_code = ?, address = ?, city = ?, state = ?, pincode = ?,
                    latitude = ?, longitude = ?, location_radius = ?, is_head_office = ?, sort_order = ?, is_active = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, "ssssssddiiiii", $name, $code, $address, $city, $state, $pincode, $latitudeValue, $longitudeValue, $radius, $isHeadOffice, $sortOrder, $isActive, $id);
        } else {
            $stmt = mysqli_prepare($conn, "
                INSERT INTO master_office_locations
                (location_name, location_code, address, city, state, pincode, latitude, longitude, location_radius, is_head_office, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            mysqli_stmt_bind_param($stmt, "ssssssddiiii", $name, $code, $address, $city, $state, $pincode, $latitudeValue, $longitudeValue, $radius, $isHeadOffice, $sortOrder, $isActive);
        }

        mysqli_stmt_execute($stmt);
        $referenceId = $id > 0 ? $id : mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        log_master_activity($conn, $id > 0 ? "UPDATE" : "CREATE", $module, ($id > 0 ? "Updated " : "Created ") . $name, $referenceId);

        redirect_back($type, ["success" => 1]);
    }

    if ($type === "assignment_roles") {
        $isFixed = (int)($_POST["is_fixed"] ?? 0);
        $sortOrder = (int)($_POST["sort_order"] ?? ($_POST["assignment_sort_order"] ?? 0));

        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE project_assignment_roles SET role_name = ?, role_key = ?, is_fixed = ?, sort_order = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ssiii", $name, $code, $isFixed, $sortOrder, $id);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO project_assignment_roles (role_name, role_key, is_fixed, sort_order) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "ssii", $name, $code, $isFixed, $sortOrder);
        }

        mysqli_stmt_execute($stmt);
        $referenceId = $id > 0 ? $id : mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        log_master_activity($conn, $id > 0 ? "UPDATE" : "CREATE", $module, ($id > 0 ? "Updated " : "Created ") . $name, $referenceId);

        redirect_back($type, ["success" => 1]);
    }

    $description = clean($_POST["description"] ?? "");
    $isActive = (int)($_POST["is_active"] ?? 1);

    if ($type === "project_statuses") {
        $badgeClass = clean($_POST["badge_class"] ?? "");
        $colorCode = clean($_POST["color_code"] ?? "#2563eb");
        $sortOrder = (int)($_POST["sort_order"] ?? 0);
        $isDefault = (int)($_POST["is_default"] ?? 0);

        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "
                UPDATE master_project_statuses
                SET status_name = ?, status_code = ?, description = ?, badge_class = ?, color_code = ?,
                    sort_order = ?, is_default = ?, is_active = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, "sssssiiii", $name, $code, $description, $badgeClass, $colorCode, $sortOrder, $isDefault, $isActive, $id);
        } else {
            $stmt = mysqli_prepare($conn, "
                INSERT INTO master_project_statuses
                (status_name, status_code, description, badge_class, color_code, sort_order, is_default, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            mysqli_stmt_bind_param($stmt, "sssssiii", $name, $code, $description, $badgeClass, $colorCode, $sortOrder, $isDefault, $isActive);
        }

        mysqli_stmt_execute($stmt);
        $referenceId = $id > 0 ? $id : mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        log_master_activity($conn, $id > 0 ? "UPDATE" : "CREATE", $module, ($id > 0 ? "Updated " : "Created ") . $name, $referenceId);

        redirect_back($type, ["success" => 1]);
    }

    if ($type === "notification_reasons") {
        $defaultIcon = clean($_POST["default_icon"] ?? "bell");
        $defaultColor = clean($_POST["default_color"] ?? "#2563eb");

        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "
                UPDATE master_notification_reasons
                SET reason_name = ?, reason_code = ?, description = ?, default_icon = ?, default_color = ?, is_active = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, "sssssii", $name, $code, $description, $defaultIcon, $defaultColor, $isActive, $id);
        } else {
            $stmt = mysqli_prepare($conn, "
                INSERT INTO master_notification_reasons
                (reason_name, reason_code, description, default_icon, default_color, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            mysqli_stmt_bind_param($stmt, "sssssi", $name, $code, $description, $defaultIcon, $defaultColor, $isActive);
        }

        mysqli_stmt_execute($stmt);
        $referenceId = $id > 0 ? $id : mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        log_master_activity($conn, $id > 0 ? "UPDATE" : "CREATE", $module, ($id > 0 ? "Updated " : "Created ") . $name, $referenceId);

        redirect_back($type, ["success" => 1]);
    }

    if ($id > 0) {
        $stmt = mysqli_prepare($conn, "
            UPDATE `$table`
            SET `$nameCol` = ?, `$codeCol` = ?, description = ?, is_active = ?
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($stmt, "sssii", $name, $code, $description, $isActive, $id);
    } else {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO `$table` (`$nameCol`, `$codeCol`, description, is_active)
            VALUES (?, ?, ?, ?)
        ");
        mysqli_stmt_bind_param($stmt, "sssi", $name, $code, $description, $isActive);
    }

    mysqli_stmt_execute($stmt);
    $referenceId = $id > 0 ? $id : mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    log_master_activity($conn, $id > 0 ? "UPDATE" : "CREATE", $module, ($id > 0 ? "Updated " : "Created ") . $name, $referenceId);

    redirect_back($type, ["success" => 1]);
} catch (Throwable $e) {
    redirect_back($type, ["error" => "Unable to save master data. " . $e->getMessage()]);
}
?>