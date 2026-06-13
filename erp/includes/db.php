<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = "srv2204.hstgr.io";
$user = "u209621005_tekcglobal";
$pass = "Ra8$3^>lP2";
$dbname = "u209621005_tekcglobal";

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

date_default_timezone_set("Asia/Kolkata");

mysqli_query($conn, "SET time_zone = '+05:30'");

/*
|--------------------------------------------------------------------------
| Basic Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists("e")) {
    function e($v)
    {
        return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
    }
}

if (!function_exists("is_logged_in")) {
    function is_logged_in()
    {
        return !empty($_SESSION["user_id"]);
    }
}

if (!function_exists("redirect_if_not_logged_in")) {
    function redirect_if_not_logged_in()
    {
        if (!is_logged_in()) {
            header("Location: login.php");
            exit;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/

if (!function_exists("current_user_id")) {
    function current_user_id()
    {
        return isset($_SESSION["user_id"]) ? (int) $_SESSION["user_id"] : 0;
    }
}

if (!function_exists("current_employee_id")) {
    function current_employee_id()
    {
        return isset($_SESSION["employee_id"]) ? (int) $_SESSION["employee_id"] : null;
    }
}

/*
|--------------------------------------------------------------------------
| User Role / Permission Access
|--------------------------------------------------------------------------
| Tables used:
| users
| user_roles
| roles
| sidebar_menus
| role_sidebar_access
|--------------------------------------------------------------------------
*/

if (!function_exists("get_user_roles")) {
    function get_user_roles($conn, $userId = null)
    {
        $userId = $userId ? (int) $userId : current_user_id();

        if ($userId <= 0) {
            return [];
        }

        $roles = [];

        $stmt = mysqli_prepare($conn, "
            SELECT r.id, r.role_name, r.role_slug
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ?
              AND r.is_active = 1
            ORDER BY r.role_name ASC
        ");

        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($res)) {
            $roles[] = $row;
        }

        mysqli_stmt_close($stmt);

        return $roles;
    }
}

if (!function_exists("is_super_admin")) {
    function is_super_admin($conn, $userId = null)
    {
        $userId = $userId ? (int) $userId : current_user_id();

        if ($userId <= 0) {
            return false;
        }

        $stmt = mysqli_prepare($conn, "
            SELECT r.id
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ?
              AND r.is_active = 1
              AND r.role_slug = 'super-admin'
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $isSuperAdmin = mysqli_num_rows($res) > 0;
        mysqli_stmt_close($stmt);

        return $isSuperAdmin;
    }
}

if (!function_exists("get_current_page")) {
    function get_current_page()
    {
        return basename(parse_url($_SERVER["PHP_SELF"], PHP_URL_PATH));
    }
}

if (!function_exists("get_menu_by_url")) {
    function get_menu_by_url($conn, $menuUrl)
    {
        $menuUrl = trim($menuUrl);

        $stmt = mysqli_prepare($conn, "
            SELECT id, menu_title, menu_slug, menu_url
            FROM sidebar_menus
            WHERE menu_url = ?
              AND is_active = 1
            LIMIT 1
        ");

        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, "s", $menuUrl);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $menu = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        return $menu ?: null;
    }
}

if (!function_exists("user_can")) {
    function user_can($conn, $permission = "can_view", $menuUrl = null, $userId = null)
    {
        $allowedPermissions = [
            "can_view",
            "can_create",
            "can_edit",
            "can_delete",
            "can_approve"
        ];

        if (!in_array($permission, $allowedPermissions, true)) {
            return false;
        }

        $userId = $userId ? (int) $userId : current_user_id();

        if ($userId <= 0) {
            return false;
        }

        if (is_super_admin($conn, $userId)) {
            return true;
        }

        $menuUrl = $menuUrl ?: get_current_page();

        $stmt = mysqli_prepare($conn, "
            SELECT rsa.$permission
            FROM role_sidebar_access rsa
            INNER JOIN user_roles ur ON ur.role_id = rsa.role_id
            INNER JOIN sidebar_menus sm ON sm.id = rsa.menu_id
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ?
              AND sm.menu_url = ?
              AND sm.is_active = 1
              AND r.is_active = 1
              AND rsa.$permission = 1
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "is", $userId, $menuUrl);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $hasAccess = mysqli_num_rows($res) > 0;
        mysqli_stmt_close($stmt);

        return $hasAccess;
    }
}

if (!function_exists("require_permission")) {
    function require_permission($conn, $permission = "can_view", $menuUrl = null)
    {
        redirect_if_not_logged_in();

        if (!user_can($conn, $permission, $menuUrl)) {
            http_response_code(403);
            echo "<h2 style='font-family:Arial;padding:30px;'>403 - Access Denied</h2>";
            echo "<p style='font-family:Arial;padding:0 30px;'>You do not have permission to access this page.</p>";
            exit;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Button Permission Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists("can_view")) {
    function can_view($conn, $menuUrl = null)
    {
        return user_can($conn, "can_view", $menuUrl);
    }
}

if (!function_exists("can_create")) {
    function can_create($conn, $menuUrl = null)
    {
        return user_can($conn, "can_create", $menuUrl);
    }
}

if (!function_exists("can_edit")) {
    function can_edit($conn, $menuUrl = null)
    {
        return user_can($conn, "can_edit", $menuUrl);
    }
}

if (!function_exists("can_delete")) {
    function can_delete($conn, $menuUrl = null)
    {
        return user_can($conn, "can_delete", $menuUrl);
    }
}

if (!function_exists("can_approve")) {
    function can_approve($conn, $menuUrl = null)
    {
        return user_can($conn, "can_approve", $menuUrl);
    }
}

?>