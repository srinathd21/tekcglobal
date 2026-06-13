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

function slugify($text)
{
    $text = strtolower(trim($text));
    $text = preg_replace("/[^a-z0-9]+/", "-", $text);
    return trim($text, "-");
}

function normalize_page_url($url)
{
    $url = trim($url);
    $url = str_replace("\\", "/", $url);
    $url = ltrim($url, "/");

    if ($url === "") {
        return "";
    }

    if ($url === "#") {
        return "#";
    }

    if (preg_match("/^(https?:)?\\/\\//i", $url)) {
        return "";
    }

    if (strpos($url, "..") !== false) {
        return "";
    }

    $url = preg_replace("/\\?.*$/", "", $url);
    $url = preg_replace("/#.*$/", "", $url);

    if ($url === "") {
        return "";
    }

    if (!preg_match("/\\.php$/i", $url)) {
        $url .= ".php";
    }

    if (!preg_match("/^[a-zA-Z0-9_\\-\\/]+\\.php$/", $url)) {
        return "";
    }

    return $url;
}

function make_page_title($menuTitle)
{
    return htmlspecialchars($menuTitle, ENT_QUOTES, "UTF-8");
}

/*
|--------------------------------------------------------------------------
| Sidebar Sort Helpers
|--------------------------------------------------------------------------
| Rules:
| 1. Main menus are sorted only with main menus: parent_id IS NULL.
| 2. Submenus are sorted only inside their own parent menu: parent_id = parent_id.
| 3. If user inserts/updates sort_order = 2 and 2 already exists,
|    existing 2 becomes 3, 3 becomes 4, etc.
| 4. Update also realigns old position when moving from one sort/group to another.
*/

function normalize_sort_order($sortOrder)
{
    $sortOrder = (int)$sortOrder;
    return $sortOrder <= 0 ? 1 : $sortOrder;
}

function get_group_count($conn, $parentId)
{
    if ($parentId === null) {
        $res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM sidebar_menus WHERE parent_id IS NULL");
        $row = mysqli_fetch_assoc($res);
        return (int)($row["total"] ?? 0);
    }

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM sidebar_menus WHERE parent_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $parentId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return (int)($row["total"] ?? 0);
}

function get_menu_sort_group($conn, $menuId)
{
    $stmt = mysqli_prepare($conn, "SELECT parent_id, sort_order FROM sidebar_menus WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $menuId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        return null;
    }

    return [
        "parent_id" => $row["parent_id"] === null ? null : (int)$row["parent_id"],
        "sort_order" => (int)$row["sort_order"]
    ];
}

function shift_group_from_sort($conn, $parentId, $fromSort, $excludeMenuId = 0)
{
    $fromSort = normalize_sort_order($fromSort);
    $excludeMenuId = (int)$excludeMenuId;

    if ($parentId === null) {
        $stmt = mysqli_prepare($conn, "
            UPDATE sidebar_menus
            SET sort_order = sort_order + 1
            WHERE parent_id IS NULL
              AND sort_order >= ?
              AND id <> ?
        ");
        mysqli_stmt_bind_param($stmt, "ii", $fromSort, $excludeMenuId);
    } else {
        $stmt = mysqli_prepare($conn, "
            UPDATE sidebar_menus
            SET sort_order = sort_order + 1
            WHERE parent_id = ?
              AND sort_order >= ?
              AND id <> ?
        ");
        mysqli_stmt_bind_param($stmt, "iii", $parentId, $fromSort, $excludeMenuId);
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function close_gap_after_old_sort($conn, $parentId, $oldSort, $excludeMenuId)
{
    $oldSort = normalize_sort_order($oldSort);
    $excludeMenuId = (int)$excludeMenuId;

    if ($parentId === null) {
        $stmt = mysqli_prepare($conn, "
            UPDATE sidebar_menus
            SET sort_order = sort_order - 1
            WHERE parent_id IS NULL
              AND sort_order > ?
              AND id <> ?
        ");
        mysqli_stmt_bind_param($stmt, "ii", $oldSort, $excludeMenuId);
    } else {
        $stmt = mysqli_prepare($conn, "
            UPDATE sidebar_menus
            SET sort_order = sort_order - 1
            WHERE parent_id = ?
              AND sort_order > ?
              AND id <> ?
        ");
        mysqli_stmt_bind_param($stmt, "iii", $parentId, $oldSort, $excludeMenuId);
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function move_sort_inside_same_group($conn, $parentId, $oldSort, $newSort, $menuId)
{
    $oldSort = normalize_sort_order($oldSort);
    $newSort = normalize_sort_order($newSort);
    $menuId = (int)$menuId;

    if ($oldSort === $newSort) {
        return;
    }

    if ($newSort < $oldSort) {
        if ($parentId === null) {
            $stmt = mysqli_prepare($conn, "
                UPDATE sidebar_menus
                SET sort_order = sort_order + 1
                WHERE parent_id IS NULL
                  AND sort_order >= ?
                  AND sort_order < ?
                  AND id <> ?
            ");
            mysqli_stmt_bind_param($stmt, "iii", $newSort, $oldSort, $menuId);
        } else {
            $stmt = mysqli_prepare($conn, "
                UPDATE sidebar_menus
                SET sort_order = sort_order + 1
                WHERE parent_id = ?
                  AND sort_order >= ?
                  AND sort_order < ?
                  AND id <> ?
            ");
            mysqli_stmt_bind_param($stmt, "iiii", $parentId, $newSort, $oldSort, $menuId);
        }
    } else {
        if ($parentId === null) {
            $stmt = mysqli_prepare($conn, "
                UPDATE sidebar_menus
                SET sort_order = sort_order - 1
                WHERE parent_id IS NULL
                  AND sort_order > ?
                  AND sort_order <= ?
                  AND id <> ?
            ");
            mysqli_stmt_bind_param($stmt, "iii", $oldSort, $newSort, $menuId);
        } else {
            $stmt = mysqli_prepare($conn, "
                UPDATE sidebar_menus
                SET sort_order = sort_order - 1
                WHERE parent_id = ?
                  AND sort_order > ?
                  AND sort_order <= ?
                  AND id <> ?
            ");
            mysqli_stmt_bind_param($stmt, "iiii", $parentId, $oldSort, $newSort, $menuId);
        }
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function same_sort_group($a, $b)
{
    if ($a === null && $b === null) {
        return true;
    }

    return $a !== null && $b !== null && (int)$a === (int)$b;
}

function build_page_content($menuTitle)
{
    $safeTitle = make_page_title($menuTitle);

    return <<<PHP
<?php
session_start();
require_once __DIR__ . "/includes/db.php";

function e(\$v)
{
    return htmlspecialchars(\$v ?? "", ENT_QUOTES, "UTF-8");
}

\$pageMessageType = "";
\$pageMessageText = "";

if (isset(\$_GET["success"])) {
    \$pageMessageType = "success";
    \$pageMessageText = "$safeTitle saved successfully.";
} elseif (isset(\$_GET["error"])) {
    \$pageMessageType = "error";
    \$pageMessageText = trim(\$_GET["error"]) !== "" ? trim(\$_GET["error"]) : "Something went wrong. Please try again.";
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>$safeTitle - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>
</head>

<body>
    <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>

    <?php include("includes/page-message.php"); ?>

    <div class="min-vh-100 d-flex">
        <?php include("includes/sidebar.php"); ?>

        <main id="main">
            <?php include("includes/nav.php"); ?>

            <section class="page-section p-3 p-lg-3">
                <div class="card-ui p-3 p-lg-4 mb-3">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                        <div>
                            <h1 class="h4 fw-bold mb-1">$safeTitle</h1>
                            <p class="text-muted-custom mb-0 small">This page was created automatically from Sidebar Control.</p>
                        </div>
                    </div>
                </div>

                <section class="card-ui p-3 p-lg-4">
                    <h2 class="fw-bold fs-6 mb-1">Manage $safeTitle</h2>
                    <p class="text-muted-custom small mb-0">Add your page content here.</p>
                </section>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include("includes/rightsidbar.php"); ?>
    </div>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=10"></script>
    <script>
        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
PHP;
}

$menuId = (int)($_POST["menu_id"] ?? 0);
$menuKind = $_POST["menu_kind"] ?? "main";
$parentId = isset($_POST["parent_id"]) && $_POST["parent_id"] !== "" ? (int)$_POST["parent_id"] : null;

$menuTitle = trim($_POST["menu_title"] ?? "");
$menuSlug = trim($_POST["menu_slug"] ?? "");
$menuUrl = normalize_page_url($_POST["menu_url"] ?? "");
$icon = trim($_POST["icon"] ?? "circle");
$sortOrder = normalize_sort_order($_POST["sort_order"] ?? 1);
$hasSubmenu = (int)($_POST["has_submenu"] ?? 0);
$isActive = (int)($_POST["is_active"] ?? 1);
$createPage = isset($_POST["create_page"]) ? 1 : 0;

if ($menuTitle === "") {
    redirect_error("Menu title is required.");
}

if ($menuUrl === "") {
    redirect_error("Invalid page URL. Use projects, projects.php, sites, sites.php, reports/projects, or reports/projects.php.");
}

if ($menuUrl === "#" && $hasSubmenu !== 1) {
    redirect_error("Only menus with submenu can use # as URL.");
}

if ($menuSlug === "") {
    $menuSlug = slugify($menuTitle);
} else {
    $menuSlug = slugify($menuSlug);
}

if ($menuSlug === "") {
    redirect_error("Invalid menu slug.");
}

if ($menuKind === "sub") {
    if (!$parentId) {
        redirect_error("Parent main menu is required for submenu.");
    }
    $hasSubmenu = 0;
} else {
    $parentId = null;
}

$menuType = $parentId ? "sub" : "main";
$userId = $_SESSION["user_id"] ?? null;

mysqli_begin_transaction($conn);

try {
    /*
    |--------------------------------------------------------------------------
    | Auto realignment logic
    |--------------------------------------------------------------------------
    | Insert at sort 2:
    |   old 2 -> 3, old 3 -> 4, etc.
    |
    | Update to sort 2:
    |   if same group, menu moves and others shift.
    |   if parent/main group changed, old group closes gap and new group shifts.
    */

    if ($menuId > 0) {
        $oldGroup = get_menu_sort_group($conn, $menuId);

        if (!$oldGroup) {
            throw new Exception("Sidebar menu not found.");
        }

        $oldParentId = $oldGroup["parent_id"];
        $oldSortOrder = normalize_sort_order($oldGroup["sort_order"]);

        $newGroupCount = get_group_count($conn, $parentId);
        $maxSortForUpdate = same_sort_group($oldParentId, $parentId) ? $newGroupCount : $newGroupCount + 1;

        if ($sortOrder > $maxSortForUpdate) {
            $sortOrder = $maxSortForUpdate;
        }

        if (same_sort_group($oldParentId, $parentId)) {
            move_sort_inside_same_group($conn, $parentId, $oldSortOrder, $sortOrder, $menuId);
        } else {
            close_gap_after_old_sort($conn, $oldParentId, $oldSortOrder, $menuId);
            shift_group_from_sort($conn, $parentId, $sortOrder, $menuId);
        }

        $stmt = mysqli_prepare($conn, "
            UPDATE sidebar_menus
            SET parent_id = ?, menu_title = ?, menu_slug = ?, menu_url = ?, icon = ?, menu_type = ?, has_submenu = ?, sort_order = ?, is_active = ?, updated_by = ?
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($stmt, "isssssiiiii", $parentId, $menuTitle, $menuSlug, $menuUrl, $icon, $menuType, $hasSubmenu, $sortOrder, $isActive, $userId, $menuId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $activityType = "UPDATE";
        $activityDesc = "Updated sidebar menu";
    } else {
        $newGroupCount = get_group_count($conn, $parentId);
        $maxSortForInsert = $newGroupCount + 1;

        if ($sortOrder > $maxSortForInsert) {
            $sortOrder = $maxSortForInsert;
        }

        shift_group_from_sort($conn, $parentId, $sortOrder, 0);

        $stmt = mysqli_prepare($conn, "
            INSERT INTO sidebar_menus
            (parent_id, menu_title, menu_slug, menu_url, icon, menu_type, has_submenu, sort_order, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        mysqli_stmt_bind_param($stmt, "isssssiiii", $parentId, $menuTitle, $menuSlug, $menuUrl, $icon, $menuType, $hasSubmenu, $sortOrder, $isActive, $userId);
        mysqli_stmt_execute($stmt);
        $menuId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $activityType = "CREATE";
        $activityDesc = "Created sidebar menu";
    }

    if ($parentId) {
        $updateParent = mysqli_prepare($conn, "UPDATE sidebar_menus SET has_submenu = 1 WHERE id = ?");
        mysqli_stmt_bind_param($updateParent, "i", $parentId);
        mysqli_stmt_execute($updateParent);
        mysqli_stmt_close($updateParent);
    }

    $superAdminRoleQ = mysqli_query($conn, "SELECT id FROM roles WHERE role_slug = 'super-admin' LIMIT 1");
    if ($superAdminRoleQ && mysqli_num_rows($superAdminRoleQ) > 0) {
        $superAdmin = mysqli_fetch_assoc($superAdminRoleQ);
        $superAdminRoleId = (int)$superAdmin["id"];

        $access = mysqli_prepare($conn, "
            INSERT IGNORE INTO role_sidebar_access
            (role_id, menu_id, can_view, can_create, can_edit, can_delete, can_approve)
            VALUES (?, ?, 1, 1, 1, 1, 1)
        ");
        mysqli_stmt_bind_param($access, "ii", $superAdminRoleId, $menuId);
        mysqli_stmt_execute($access);
        mysqli_stmt_close($access);
    }

    if ($createPage && $menuUrl !== "#") {
        $rootDir = realpath(__DIR__ . "/..");
        $pagePath = $rootDir . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $menuUrl);

        $pageDir = dirname($pagePath);
        if (!is_dir($pageDir)) {
            mkdir($pageDir, 0755, true);
        }

        if (!file_exists($pagePath)) {
            file_put_contents($pagePath, build_page_content($menuTitle));
        }
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
        VALUES (?, ?, ?, ?, ?, ?, 'sidebar_menus', ?, ?, ?)
    ");
    mysqli_stmt_bind_param($log, "issssssis", $employeeId, $employeeName, $username, $designation, $department, $activityType, $activityDesc, $menuId, $ip);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    mysqli_commit($conn);

    if ($createPage && $menuUrl !== "#") {
        header("Location: ../sidebar-control.php?page_created=1");
    } else {
        header("Location: ../sidebar-control.php?success=1");
    }
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirect_error("Unable to save sidebar menu.");
}
?>
