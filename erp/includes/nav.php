<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists("nav_e")) {
    function nav_e($v)
    {
        return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
    }
}

$currentUserName = $_SESSION["employee_name"] ?? ($_SESSION["name"] ?? "User");
$currentUserEmail = $_SESSION["email"] ?? "";
$currentUserDesignation = $_SESSION["designation"] ?? ($_SESSION["roles_text"] ?? "User");
$currentUserInitial = strtoupper(substr(trim($currentUserName), 0, 1));
$currentUserInitial = $currentUserInitial !== "" ? $currentUserInitial : "U";

$canCompanySettings = function_exists("can_view") && isset($conn) ? can_view($conn, "website-colors.php") : false;
$canInvoices = function_exists("can_view") && isset($conn) ? can_view($conn, "billing.php") : false;
$canProfile = file_exists(__DIR__ . "/../profile.php") || file_exists(__DIR__ . "/../my-profile.php");

$notificationRows = [];
$notificationCount = 0;

if (isset($conn) && !empty($_SESSION["user_id"])) {
    $userId = (int) $_SESSION["user_id"];

    $notificationTableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
    $notificationStatusTableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'notification_user_status'");

    if ($notificationTableCheck && mysqli_num_rows($notificationTableCheck) > 0) {
        if ($notificationStatusTableCheck && mysqli_num_rows($notificationStatusTableCheck) > 0) {
            $countStmt = mysqli_prepare($conn, "
                SELECT COUNT(*) AS total
                FROM notifications n
                LEFT JOIN notification_user_status nus
                    ON nus.notification_id = n.id
                   AND nus.user_id = ?
                WHERE n.is_active = 1
                  AND COALESCE(nus.is_read, 0) = 0
            ");

            if ($countStmt) {
                mysqli_stmt_bind_param($countStmt, "i", $userId);
                mysqli_stmt_execute($countStmt);
                $countRow = mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt));
                $notificationCount = (int)($countRow["total"] ?? 0);
                mysqli_stmt_close($countStmt);
            }

            $notifyStmt = mysqli_prepare($conn, "
                SELECT
                    n.id,
                    n.title,
                    n.message,
                    n.notification_type,
                    n.created_at,
                    COALESCE(nus.is_read, 0) AS is_read
                FROM notifications n
                LEFT JOIN notification_user_status nus
                    ON nus.notification_id = n.id
                   AND nus.user_id = ?
                WHERE n.is_active = 1
                ORDER BY COALESCE(nus.is_read, 0) ASC, n.created_at DESC
                LIMIT 5
            ");

            if ($notifyStmt) {
                mysqli_stmt_bind_param($notifyStmt, "i", $userId);
                mysqli_stmt_execute($notifyResult = $notifyStmt);
                $notifyResult = mysqli_stmt_get_result($notifyStmt);

                while ($row = mysqli_fetch_assoc($notifyResult)) {
                    $notificationRows[] = $row;
                }

                mysqli_stmt_close($notifyStmt);
            }
        } else {
            $notifyQ = mysqli_query($conn, "
                SELECT id, title, message, notification_type, created_at
                FROM notifications
                WHERE is_active = 1
                ORDER BY created_at DESC
                LIMIT 5
            ");

            if ($notifyQ) {
                while ($row = mysqli_fetch_assoc($notifyQ)) {
                    $notificationRows[] = $row;
                }
            }

            $notificationCount = count($notificationRows);
        }
    }
}

function nav_notification_icon($type)
{
    $type = strtolower(trim($type ?? ""));

    if (str_contains($type, "payment") || str_contains($type, "invoice") || str_contains($type, "billing")) {
        return "indian-rupee";
    }

    if (str_contains($type, "warning") || str_contains($type, "alert")) {
        return "alert-circle";
    }

    if (str_contains($type, "project")) {
        return "building-2";
    }

    if (str_contains($type, "user") || str_contains($type, "employee")) {
        return "users";
    }

    return "bell";
}

function nav_notification_color($type)
{
    $type = strtolower(trim($type ?? ""));

    if (str_contains($type, "payment") || str_contains($type, "success")) {
        return "bg-success-subtle text-success";
    }

    if (str_contains($type, "warning") || str_contains($type, "alert")) {
        return "bg-warning-subtle text-warning";
    }

    if (str_contains($type, "danger") || str_contains($type, "error")) {
        return "bg-danger-subtle text-danger";
    }

    return "bg-primary-subtle text-primary";
}
?>


<style>
    
</style>

<header id="topbar" class="d-flex align-items-center px-3 px-lg-4">
    <div class="d-flex align-items-center gap-3 w-100">
        <button id="sidebarToggle" class="icon-btn border-0" type="button">
            <i data-lucide="menu"></i>
        </button>

        <div class="ms-auto d-flex align-items-center gap-2 gap-sm-3">
            <button id="settingsToggle" class="icon-btn" type="button" title="Customize dashboard">
                <i data-lucide="settings" style="width:16px;height:16px;"></i>
            </button>

            <button id="themeToggle" class="icon-btn" type="button" title="Toggle light/dark mode">
                <i id="themeIcon" data-lucide="moon" style="width:16px;height:16px;"></i>
            </button>

            <div class="position-relative">
                <button data-dropdown-target="notificationDropdown"
                    class="dropdown-btn icon-btn border-0 position-relative" type="button">
                    <i data-lucide="bell"></i>

                    <?php if ($notificationCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                            style="font-size:9px;">
                            <?= $notificationCount > 99 ? "99+" : (int)$notificationCount ?>
                        </span>
                    <?php endif; ?>
                </button>

                <div id="notificationDropdown" class="dropdown-menu-custom dropdown-card p-3" style="width:320px;right:0;left:auto;">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h3 class="fw-bold fs-6 mb-0">Notifications</h3>

                        <?php if (!empty($notificationRows)): ?>
                            <a href="notifications.php" class="btn btn-link p-0 text-primary fw-bold small">View all</a>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if (!empty($notificationRows)): ?>
                            <?php foreach ($notificationRows as $notification): ?>
                                <?php
                                $notificationType = $notification["notification_type"] ?? "";
                                $notificationIcon = nav_notification_icon($notificationType);
                                $notificationColor = nav_notification_color($notificationType);
                                ?>
                                <div class="notify-item">
                                    <span class="notify-icon <?= nav_e($notificationColor) ?>">
                                        <i data-lucide="<?= nav_e($notificationIcon) ?>"></i>
                                    </span>
                                    <div>
                                        <p class="mb-0 fw-bold small"><?= nav_e($notification["title"] ?? "Notification") ?></p>
                                        <small class="text-muted-custom">
                                            <?= nav_e($notification["message"] ?? "") ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <div class="rounded-circle bg-body-secondary d-inline-flex align-items-center justify-content-center mb-2"
                                    style="width:42px;height:42px;">
                                    <i data-lucide="bell-off" style="width:18px;height:18px;"></i>
                                </div>
                                <p class="fw-bold small mb-0">No notifications</p>
                                <p class="text-muted-custom small mb-0">You are all caught up.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="position-relative">
                <button data-dropdown-target="profileDropdown"
                    class="dropdown-btn btn border-0 d-flex align-items-center gap-2 rounded-4 p-1" type="button">
                    <div class="rounded-circle bg-body-secondary d-flex align-items-center justify-content-center fw-bold"
                        style="width:32px;height:32px;font-size:13px;">
                        <?= nav_e($currentUserInitial) ?>
                    </div>

                    <div class="d-none d-md-block text-start lh-sm">
                        <p class="fw-bold mb-0 topbar-user-name" style="font-size:12px;"><?= nav_e($currentUserName) ?></p>
                        <p class="text-muted-custom mb-0 topbar-user-role" style="font-size:11px;"><?= nav_e($currentUserDesignation) ?></p>
                    </div>

                    <i data-lucide="chevron-down" class="text-muted-custom" style="width:16px;height:16px;"></i>
                </button>

                <div id="profileDropdown" class="dropdown-menu-custom dropdown-card p-2" style="width:224px;right:0;left:auto;">
                    <div class="px-3 py-2 border-bottom border-soft mb-1">
                        <p class="fw-bold small mb-0"><?= nav_e($currentUserName) ?></p>
                        <p class="text-muted-custom small mb-0"><?= nav_e($currentUserEmail ?: $currentUserDesignation) ?></p>
                    </div>

                    <?php if ($canProfile): ?>
                        <a href="my-profile.php" class="dropdown-item-custom">
                            <i data-lucide="user" style="width:16px;height:16px;"></i>My Profile
                        </a>
                    <?php endif; ?>

                    <?php if ($canCompanySettings): ?>
                        <a href="website-colors.php" class="dropdown-item-custom">
                            <i data-lucide="settings" style="width:16px;height:16px;"></i>Theme Settings
                        </a>
                    <?php endif; ?>

                    <?php if ($canInvoices): ?>
                        <a href="billing.php" class="dropdown-item-custom">
                            <i data-lucide="credit-card" style="width:16px;height:16px;"></i>Invoices
                        </a>
                    <?php endif; ?>

                    <a href="logout.php" class="dropdown-item-custom text-danger">
                        <i data-lucide="log-out" style="width:16px;height:16px;"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>
