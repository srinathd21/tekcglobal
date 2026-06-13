<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../sidebar-control.php");
    exit;
}

function redirect_error($message)
{
    header("Location: ../sidebar-control.php?error=" . urlencode($message));
    exit;
}

function safe_file_path_from_url($url)
{
    $url = trim($url);
    $url = str_replace("\\", "/", $url);
    $url = ltrim($url, "/");

    if ($url === "" || $url === "#") {
        return "";
    }

    if (strpos($url, "..") !== false) {
        return "";
    }

    if (!preg_match("/^[a-zA-Z0-9_\\-\\/]+\\.php$/", $url)) {
        return "";
    }

    $blocked = [
        "index.php",
        "login.php",
        "logout.php",
        "includes/db.php",
        "includes/sidebar.php",
        "includes/nav.php",
        "includes/footer.php",
        "includes/links.php",
        "includes/script.php",
        "includes/page-message.php",
        "includes/rightsidbar.php",
        "sidebar-control.php",
        "website-colors.php",
        "roles.php",
        "master-control.php"
    ];

    if (in_array($url, $blocked, true)) {
        return "";
    }

    $rootDir = realpath(__DIR__ . "/..");
    $pagePath = $rootDir . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $url);
    $realParent = realpath(dirname($pagePath));

    if ($realParent === false || strpos($realParent, $rootDir) !== 0) {
        return "";
    }

    return $pagePath;
}

$menuId = (int)($_POST["menu_id"] ?? 0);
$deletePageFile = isset($_POST["delete_page_file"]) ? 1 : 0;

if ($menuId <= 0) {
    redirect_error("Invalid sidebar menu.");
}

$stmt = mysqli_prepare($conn, "SELECT id, menu_title, menu_url FROM sidebar_menus WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $menuId);
mysqli_stmt_execute($stmt);
$menu = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$menu) {
    redirect_error("Sidebar menu not found.");
}

mysqli_begin_transaction($conn);

try {
    $menuUrl = $menu["menu_url"] ?? "";

    $del = mysqli_prepare($conn, "DELETE FROM sidebar_menus WHERE id = ?");
    mysqli_stmt_bind_param($del, "i", $menuId);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);

    if ($deletePageFile) {
        $pagePath = safe_file_path_from_url($menuUrl);

        if ($pagePath !== "" && file_exists($pagePath) && is_file($pagePath)) {
            unlink($pagePath);
        }
    }

    $employeeId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $description = $deletePageFile ? "Deleted sidebar menu and page file" : "Deleted sidebar menu only";

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, 'DELETE', 'sidebar_menus', ?, ?, ?)
    ");
    mysqli_stmt_bind_param($log, "isssssis", $employeeId, $employeeName, $username, $designation, $department, $description, $menuId, $ip);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    mysqli_commit($conn);

    header("Location: ../sidebar-control.php?deleted=1");
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirect_error("Unable to delete sidebar menu.");
}
?>
