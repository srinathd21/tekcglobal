<?php
if (!function_exists('lm_table_exists')) {
    function lm_table_exists($conn, $table) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $q = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
        return $q && mysqli_num_rows($q) > 0;
    }
}

if (!function_exists('lm_column_exists')) {
    function lm_column_exists($conn, $table, $column) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $column = mysqli_real_escape_string($conn, $column);
        $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $q && mysqli_num_rows($q) > 0;
    }
}

if (!function_exists('lm_clean')) {
    function lm_clean($v) {
        return trim($v ?? "");
    }
}

if (!function_exists('lm_login_employee_id')) {
    function lm_login_employee_id($conn) {
        if (!empty($_SESSION["employee_id"])) {
            return (int)$_SESSION["employee_id"];
        }

        if (!empty($_SESSION["user_id"]) && lm_table_exists($conn, "users") && lm_column_exists($conn, "users", "employee_id")) {
            $userId = (int)$_SESSION["user_id"];
            $q = mysqli_query($conn, "SELECT employee_id FROM users WHERE id = $userId LIMIT 1");
            if ($q && ($row = mysqli_fetch_assoc($q)) && !empty($row["employee_id"])) {
                $_SESSION["employee_id"] = (int)$row["employee_id"];
                return (int)$row["employee_id"];
            }
        }

        return 0;
    }
}

if (!function_exists('lm_user_has_approve_access')) {
    function lm_user_has_approve_access($conn, $pageUrl) {
        if (empty($_SESSION["user_id"])) {
            return false;
        }

        if (!lm_table_exists($conn, "user_roles") || !lm_table_exists($conn, "role_sidebar_access") || !lm_table_exists($conn, "sidebar_menus")) {
            return false;
        }

        $userId = (int)$_SESSION["user_id"];
        $pageUrl = mysqli_real_escape_string($conn, $pageUrl);

        $sql = "
            SELECT rsa.can_approve
            FROM user_roles ur
            INNER JOIN role_sidebar_access rsa ON rsa.role_id = ur.role_id
            INNER JOIN sidebar_menus sm ON sm.id = rsa.menu_id
            WHERE ur.user_id = $userId
              AND sm.menu_url = '$pageUrl'
              AND rsa.can_approve = 1
              AND sm.is_active = 1
            LIMIT 1
        ";

        $q = mysqli_query($conn, $sql);
        return $q && mysqli_num_rows($q) > 0;
    }
}

if (!function_exists('lm_reason_id')) {
    function lm_reason_id($conn, $reasonCode) {
        if (!lm_table_exists($conn, "master_notification_reasons")) {
            return null;
        }

        $reasonCode = mysqli_real_escape_string($conn, $reasonCode);
        $q = mysqli_query($conn, "SELECT id FROM master_notification_reasons WHERE reason_code = '$reasonCode' AND is_active = 1 LIMIT 1");
        if ($q && ($row = mysqli_fetch_assoc($q))) {
            return (int)$row["id"];
        }

        return null;
    }
}

if (!function_exists('lm_create_notification')) {
    function lm_create_notification($conn, $employeeId, $title, $message, $module, $referenceId = null, $link = "", $type = "info", $priority = "normal", $reasonCode = null) {
        if ($employeeId <= 0 || !lm_table_exists($conn, "notifications")) {
            return false;
        }

        $createdBy = $_SESSION["user_id"] ?? null;
        $createdByName = $_SESSION["name"] ?? $_SESSION["employee_name"] ?? "System";
        $reasonId = $reasonCode ? lm_reason_id($conn, $reasonCode) : null;
        $icon = $module === "leave" ? "calendar-days" : "clock";
        $color = $module === "leave" ? "#f59e0b" : "#10b981";

        $stmt = mysqli_prepare($conn, "
            INSERT INTO notifications
            (reason_id, title, message, target_type, module, reference_id, link, priority, notification_type, icon, color_code, is_system, is_active, sent_at, created_by, created_by_name)
            VALUES (?, ?, ?, 'employees', ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW(), ?, ?)
        ");

        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param(
            $stmt,
            "isssisssssis",
            $reasonId,
            $title,
            $message,
            $module,
            $referenceId,
            $link,
            $priority,
            $type,
            $icon,
            $color,
            $createdBy,
            $createdByName
        );

        $ok = mysqli_stmt_execute($stmt);
        $notificationId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        if (!$ok || $notificationId <= 0) {
            return false;
        }

        if (lm_table_exists($conn, "notification_employee_targets")) {
            $stmt = mysqli_prepare($conn, "
                INSERT IGNORE INTO notification_employee_targets (notification_id, employee_id)
                VALUES (?, ?)
            ");
            mysqli_stmt_bind_param($stmt, "ii", $notificationId, $employeeId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        $userId = null;
        if (lm_table_exists($conn, "users") && lm_column_exists($conn, "users", "employee_id")) {
            $q = mysqli_query($conn, "SELECT id FROM users WHERE employee_id = " . (int)$employeeId . " LIMIT 1");
            if ($q && ($row = mysqli_fetch_assoc($q))) {
                $userId = (int)$row["id"];
            }
        }

        if ($userId && lm_table_exists($conn, "notification_user_targets")) {
            $stmt = mysqli_prepare($conn, "
                INSERT IGNORE INTO notification_user_targets (notification_id, user_id)
                VALUES (?, ?)
            ");
            mysqli_stmt_bind_param($stmt, "ii", $notificationId, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        if ($userId && lm_table_exists($conn, "notification_user_status")) {
            $stmt = mysqli_prepare($conn, "
                INSERT IGNORE INTO notification_user_status (notification_id, user_id, is_read, delivered_at)
                VALUES (?, ?, 0, NOW())
            ");
            mysqli_stmt_bind_param($stmt, "ii", $notificationId, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        return true;
    }
}

if (!function_exists('lm_project_higher_officials')) {
    function lm_project_higher_officials($conn, $projectId, $excludeEmployeeId = 0) {
        $ids = [];

        if ($projectId <= 0) {
            return $ids;
        }

        if (lm_table_exists($conn, "projects")) {
            $q = mysqli_query($conn, "
                SELECT manager_employee_id, team_lead_employee_id
                FROM projects
                WHERE id = " . (int)$projectId . "
                LIMIT 1
            ");
            if ($q && ($p = mysqli_fetch_assoc($q))) {
                foreach (["manager_employee_id", "team_lead_employee_id"] as $col) {
                    if (!empty($p[$col]) && (int)$p[$col] !== (int)$excludeEmployeeId) {
                        $ids[(int)$p[$col]] = (int)$p[$col];
                    }
                }
            }
        }

        if (lm_table_exists($conn, "project_assignments") && lm_table_exists($conn, "project_assignment_roles")) {
            $sql = "
                SELECT DISTINCT pa.employee_id
                FROM project_assignments pa
                INNER JOIN project_assignment_roles par ON par.id = pa.assignment_role_id
                WHERE pa.project_id = " . (int)$projectId . "
                  AND pa.status = 'active'
                  AND pa.employee_id <> " . (int)$excludeEmployeeId . "
                  AND (
                        LOWER(par.role_key) IN ('manager','project_manager','pm','tl','team_lead','site_engineer','manager')
                        OR LOWER(par.role_name) LIKE '%manager%'
                        OR LOWER(par.role_name) LIKE '%lead%'
                  )
            ";

            $q = mysqli_query($conn, $sql);
            while ($q && ($row = mysqli_fetch_assoc($q))) {
                $ids[(int)$row["employee_id"]] = (int)$row["employee_id"];
            }
        }

        return array_values($ids);
    }
}

if (!function_exists('lm_log_activity')) {
    function lm_log_activity($conn, $activityType, $module, $description, $referenceId = null) {
        if (!lm_table_exists($conn, "activity_logs")) {
            return;
        }

        $employeeId = $_SESSION["employee_id"] ?? null;
        $employeeName = $_SESSION["employee_name"] ?? $_SESSION["name"] ?? "Admin";
        $username = $_SESSION["username"] ?? null;
        $designation = $_SESSION["designation"] ?? null;
        $department = $_SESSION["department"] ?? null;
        $ip = $_SERVER["REMOTE_ADDR"] ?? null;

        $stmt = mysqli_prepare($conn, "
            INSERT INTO activity_logs
            (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "isssssssis", $employeeId, $employeeName, $username, $designation, $department, $activityType, $module, $description, $referenceId, $ip);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}
?>