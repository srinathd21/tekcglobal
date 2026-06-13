<?php
session_start();
require_once "../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../website-colors.php");
    exit;
}

$allowed = [
    "body_bg" => "Body Background",
    "sidebar_bg" => "Sidebar Background",
    "sidebar_text" => "Sidebar Text Color",
    "sidebar_active_bg_1" => "Sidebar Active Background Start",
    "sidebar_active_bg_2" => "Sidebar Active Background End",
    "sidebar_active_text" => "Sidebar Active Text Color",
    "sidebar_hover_bg" => "Sidebar Hover Background",
    "sidebar_hover_text" => "Sidebar Hover Text Color",
    "topbar_bg" => "Topbar Background",
    "card_bg" => "Card Background",
    "border_soft" => "Border Color",
    "text_main" => "Main Text Color",
    "text_muted" => "Muted Text Color",
    "brand_1" => "Primary Brand Color",
    "brand_2" => "Secondary Brand Color"
];

$updatedBy = $_SESSION["employee_id"] ?? null;

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO website_color_settings 
    (setting_key, setting_value, setting_label, setting_group, updated_by)
    VALUES (?, ?, ?, 'layout', ?)
    ON DUPLICATE KEY UPDATE
      setting_value = VALUES(setting_value),
      setting_label = VALUES(setting_label),
      updated_by = VALUES(updated_by),
      updated_at = CURRENT_TIMESTAMP"
);

foreach ($allowed as $key => $label) {
    $value = trim($_POST[$key] ?? "");

    if ($value === "") {
        continue;
    }

    if ($key !== "sidebar_hover_bg") {
        if (!preg_match('/^#[a-fA-F0-9]{6}$/', $value)) {
            continue;
        }
    } else {
        if (
            !preg_match('/^#[a-fA-F0-9]{6}$/', $value) &&
            !preg_match('/^rgba?\([0-9\s,.]+\)$/', $value)
        ) {
            continue;
        }
    }

    mysqli_stmt_bind_param($stmt, "sssi", $key, $value, $label, $updatedBy);
    mysqli_stmt_execute($stmt);
}

mysqli_stmt_close($stmt);

/* Optional activity log */
if (mysqli_query($conn, "SHOW TABLES LIKE 'activity_logs'")->num_rows > 0) {
    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $logStmt = mysqli_prepare(
        $conn,
        "INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, ip_address)
        VALUES (?, ?, ?, ?, ?, 'UPDATE', 'website_colors', 'Updated website color settings', ?)"
    );

    mysqli_stmt_bind_param(
        $logStmt,
        "isssss",
        $updatedBy,
        $employeeName,
        $username,
        $designation,
        $department,
        $ip
    );

    mysqli_stmt_execute($logStmt);
    mysqli_stmt_close($logStmt);
}

header("Location: ../website-colors.php?success=1");
exit;
?>