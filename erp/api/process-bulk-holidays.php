<?php
session_start();
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/leave-module-helper.php";

$holidays = $_POST["holidays"] ?? [];
$userId = $_SESSION["user_id"] ?? null;

if (empty($holidays) || !is_array($holidays)) {
    header("Location: ../master-control.php?type=holidays&error=" . urlencode("Please add at least one holiday."));
    exit;
}

try {
    mysqli_begin_transaction($conn);

    foreach ($holidays as $row) {
        $name = lm_clean($row["holiday_name"] ?? "");
        $code = lm_clean($row["holiday_code"] ?? "");
        $date = lm_clean($row["holiday_date"] ?? "");
        $type = lm_clean($row["holiday_type"] ?? "company");
        $officeId = lm_clean($row["office_location_id"] ?? "") !== "" ? (int)$row["office_location_id"] : null;
        $description = lm_clean($row["description"] ?? "");

        if ($name === "" || $date === "") continue;

        if ($code === "") {
            $code = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $name)) . "-" . date("Ymd", strtotime($date));
        }

        $stmt = mysqli_prepare($conn, "
            INSERT INTO master_holidays
            (holiday_name, holiday_code, holiday_date, holiday_type, office_location_id, description, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE
                holiday_code = VALUES(holiday_code),
                holiday_type = VALUES(holiday_type),
                description = VALUES(description),
                is_active = 1,
                updated_by = VALUES(created_by)
        ");
        mysqli_stmt_bind_param($stmt, "ssssisi", $name, $code, $date, $type, $officeId, $description, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    mysqli_commit($conn);
    header("Location: ../master-control.php?type=holidays&success=1");
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    header("Location: ../master-control.php?type=holidays&error=" . urlencode($e->getMessage()));
    exit;
}
?>