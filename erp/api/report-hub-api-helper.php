<?php
function rh_api_return_url($url)
{
    $url = trim((string)$url);

    if ($url === "" || preg_match('/^https?:\/\//i', $url)) {
        $url = "../reports-hub.php";
    }

    /*
     * Fix duplicate URL issue:
     * If return_url is /git/tekcglobal/erp/reports-hub.php, do NOT prefix "..".
     * Prefixing ".." caused:
     * /git/tekcglobal/erp/git/tekcglobal/erp/reports-hub.php
     */
    if (strpos($url, "/") === 0) {
        $cleanUrl = $url;
    } else {
        $cleanUrl = "../" . ltrim($url, "/");
    }

    /*
     * Remove old flash query params before appending new success/error params.
     * This prevents:
     * ?reminder=1&reminder=1
     */
    $parts = parse_url($cleanUrl);
    $path = $parts["path"] ?? $cleanUrl;
    $query = [];

    if (!empty($parts["query"])) {
        parse_str($parts["query"], $query);
    }

    unset($query["reminder"], $query["remark"], $query["success"], $query["error"], $query["deleted"], $query["access"]);

    $rebuilt = $path;
    if (!empty($query)) {
        $rebuilt .= "?" . http_build_query($query);
    }

    if (!empty($parts["fragment"])) {
        $rebuilt .= "#" . $parts["fragment"];
    }

    return $rebuilt;
}

function rh_api_redirect_with($url, array $params)
{
    $baseUrl = rh_api_return_url($url);
    $separator = strpos($baseUrl, "?") === false ? "?" : "&";
    header("Location: " . $baseUrl . $separator . http_build_query($params));
    exit;
}

function rh_api_employee_id($conn)
{
    if (!empty($_SESSION["employee_id"])) {
        return (int)$_SESSION["employee_id"];
    }

    if (!empty($_SESSION["user_id"])) {
        $uid = (int)$_SESSION["user_id"];
        $q = mysqli_query($conn, "SELECT employee_id FROM users WHERE id = $uid LIMIT 1");

        if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r["employee_id"])) {
            $_SESSION["employee_id"] = (int)$r["employee_id"];
            return (int)$r["employee_id"];
        }
    }

    return 0;
}

function rh_api_is_super_admin($conn, $userId)
{
    $userId = (int)$userId;

    $q = mysqli_query($conn, "
        SELECT r.id
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = $userId
          AND (r.role_slug = 'super-admin' OR LOWER(r.role_name) = 'super admin')
        LIMIT 1
    ");

    return $q && mysqli_num_rows($q) > 0;
}

function rh_api_hierarchy($conn, $projectId)
{
    $projectId = (int)$projectId;

    $data = ["manager" => 0, "team_lead" => 0];

    $q = mysqli_query($conn, "SELECT manager_employee_id, team_lead_employee_id FROM projects WHERE id = $projectId LIMIT 1");

    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $data["manager"] = (int)($r["manager_employee_id"] ?? 0);
        $data["team_lead"] = (int)($r["team_lead_employee_id"] ?? 0);
    }

    $fallbackQ = mysqli_query($conn, "
        SELECT pa.employee_id, par.role_key
        FROM project_assignments pa
        INNER JOIN project_assignment_roles par ON par.id = pa.assignment_role_id
        WHERE pa.project_id = $projectId
          AND pa.status = 'active'
          AND par.role_key IN ('MANAGER','TEAM_LEAD')
        ORDER BY pa.is_primary DESC, pa.id ASC
    ");

    while ($fallbackQ && ($row = mysqli_fetch_assoc($fallbackQ))) {
        if ($row["role_key"] === "MANAGER" && $data["manager"] <= 0) {
            $data["manager"] = (int)$row["employee_id"];
        }

        if ($row["role_key"] === "TEAM_LEAD" && $data["team_lead"] <= 0) {
            $data["team_lead"] = (int)$row["employee_id"];
        }
    }

    return $data;
}

function rh_api_has_report_access($conn, $userId, $reportTypeId, $accessColumn)
{
    $allowed = ["can_submit", "can_view", "can_remark_tl", "can_remark_manager"];

    if (!in_array($accessColumn, $allowed, true)) {
        return false;
    }

    $userId = (int)$userId;
    $reportTypeId = (int)$reportTypeId;

    $q = mysqli_query($conn, "
        SELECT MAX(COALESCE(rtra.$accessColumn, 0)) AS allowed
        FROM user_roles ur
        INNER JOIN report_type_role_access rtra ON rtra.role_id = ur.role_id
        WHERE ur.user_id = $userId
          AND rtra.report_type_id = $reportTypeId
    ");

    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return (int)($row["allowed"] ?? 0) === 1;
    }

    return false;
}

function rh_api_reason_id($conn, $reasonCode)
{
    $reasonCode = mysqli_real_escape_string($conn, $reasonCode);

    $q = mysqli_query($conn, "SELECT id FROM master_notification_reasons WHERE reason_code = '$reasonCode' LIMIT 1");

    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return (int)$row["id"];
    }

    return null;
}

function rh_api_send_notification($conn, $employeeId, $title, $message, $link, $referenceId, $reasonCode, $priority = "normal", $type = "info")
{
    $employeeId = (int)$employeeId;

    if ($employeeId <= 0) {
        return false;
    }

    $reasonId = rh_api_reason_id($conn, $reasonCode);
    $createdBy = (int)($_SESSION["user_id"] ?? 0);
    $createdByName = $_SESSION["employee_name"] ?? $_SESSION["name"] ?? "System";

    $icon = "message-square-text";
    $color = "#2563eb";

    if ($reasonCode === "PROJECT_REPORT_RESUBMIT") {
        $icon = "rotate-ccw";
        $color = "#f59e0b";
    } elseif ($reasonCode === "PROJECT_REPORT_SUBMIT_REMINDER") {
        $icon = "bell-ring";
        $color = "#f59e0b";
    }

    $stmt = mysqli_prepare($conn, "
        INSERT INTO notifications
        (reason_id, title, message, target_type, module, reference_id, link, priority, notification_type, icon, color_code, is_system, is_active, sent_at, created_by, created_by_name)
        VALUES (?, ?, ?, 'employees', 'reports_hub', ?, ?, ?, ?, ?, ?, 1, 1, NOW(), ?, ?)
    ");

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "ississsssis",
        $reasonId,
        $title,
        $message,
        $referenceId,
        $link,
        $priority,
        $type,
        $icon,
        $color,
        $createdBy,
        $createdByName
    );

    mysqli_stmt_execute($stmt);
    $notificationId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    if ($notificationId <= 0) {
        return false;
    }

    mysqli_query($conn, "INSERT IGNORE INTO notification_employee_targets (notification_id, employee_id) VALUES ($notificationId, $employeeId)");

    $userQ = mysqli_query($conn, "SELECT id FROM users WHERE employee_id = $employeeId LIMIT 1");

    if ($userQ && ($user = mysqli_fetch_assoc($userQ))) {
        $targetUserId = (int)$user["id"];
        mysqli_query($conn, "INSERT IGNORE INTO notification_user_targets (notification_id, user_id) VALUES ($notificationId, $targetUserId)");
        mysqli_query($conn, "INSERT IGNORE INTO notification_user_status (notification_id, user_id, is_read, delivered_at) VALUES ($notificationId, $targetUserId, 0, NOW())");
    }

    return true;
}

function rh_api_log($conn, $type, $description, $referenceId)
{
    $employeeId = (int)($_SESSION["employee_id"] ?? 0);
    $employeeName = mysqli_real_escape_string($conn, $_SESSION["employee_name"] ?? $_SESSION["name"] ?? "");
    $username = mysqli_real_escape_string($conn, $_SESSION["username"] ?? "");
    $designation = mysqli_real_escape_string($conn, $_SESSION["designation"] ?? "");
    $department = mysqli_real_escape_string($conn, $_SESSION["department"] ?? "");
    $type = mysqli_real_escape_string($conn, $type);
    $description = mysqli_real_escape_string($conn, $description);
    $ip = mysqli_real_escape_string($conn, $_SERVER["REMOTE_ADDR"] ?? "");
    $referenceId = (int)$referenceId;

    mysqli_query($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES ($employeeId, '$employeeName', '$username', '$designation', '$department', '$type', 'Reports Hub', '$description', $referenceId, '$ip')
    ");
}
?>
