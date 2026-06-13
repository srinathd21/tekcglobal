<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

$type = $_GET["type"] ?? "departments";
$id = (int)($_GET["id"] ?? 0);

$masters = [
    'departments' => ['table' => 'master_departments'],
    'office_locations' => ['table' => 'master_office_locations'],
    'client_types' => ['table' => 'master_client_types'],
    'project_types' => ['table' => 'master_project_types'],
    'project_statuses' => ['table' => 'master_project_statuses'],
    'notification_reasons' => ['table' => 'master_notification_reasons']
];

if (!isset($masters[$type]) || $id <= 0) {
    header("Location: ../master-control.php?type=" . urlencode($type) . "&error=" . urlencode("Invalid request."));
    exit;
}

$table = $masters[$type]["table"];

mysqli_query($conn, "UPDATE `$table` SET is_active = IF(is_active = 1, 0, 1) WHERE id = $id");

header("Location: ../master-control.php?type=" . urlencode($type) . "&status=1");
exit;
