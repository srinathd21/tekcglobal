<?php
require_once __DIR__ . "/includes/db.php";
require_permission($conn, "can_view", "pmc.php");

function pms_xls_e($v) { return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8"); }
function pms_xls_date($date) { return ($date && $date !== "0000-00-00") ? date("D d-m-y", strtotime($date)) : "-"; }
function pms_xls_days($days) { $days = (int)$days; return $days . " day" . ($days === 1 ? "" : "s"); }
function pms_flatten_items($itemsByParent, $parentId = 0, $depth = 0, &$rows = []) {
    if (empty($itemsByParent[$parentId])) return;
    foreach ($itemsByParent[$parentId] as $item) {
        $item["_depth"] = $depth;
        $rows[] = $item;
        pms_flatten_items($itemsByParent, (int)$item["id"], $depth + 1, $rows);
    }
}

$scheduleId = (int)($_GET["schedule_id"] ?? 0);
if ($scheduleId <= 0) { die("Invalid schedule."); }

$scheduleQ = mysqli_query($conn, "
    SELECT s.*, p.project_name, p.project_code, p.project_location
    FROM project_pmc_schedules s
    INNER JOIN projects p ON p.id = s.project_id
    WHERE s.id = $scheduleId AND p.deleted_at IS NULL
    LIMIT 1
");
$schedule = $scheduleQ ? mysqli_fetch_assoc($scheduleQ) : null;
if (!$schedule) { die("Schedule not found."); }

$itemsByParent = [];
$itemsQ = mysqli_query($conn, "
    SELECT *
    FROM project_pmc_schedule_items
    WHERE schedule_id = $scheduleId AND is_active = 1
    ORDER BY COALESCE(parent_id, 0) ASC, sort_order ASC, id ASC
");
if ($itemsQ) {
    while ($item = mysqli_fetch_assoc($itemsQ)) {
        $parentKey = $item["parent_id"] ? (int)$item["parent_id"] : 0;
        $itemsByParent[$parentKey][] = $item;
    }
}
$rows = [];
pms_flatten_items($itemsByParent, 0, 0, $rows);

$filename = "pms_" . preg_replace('/[^A-Za-z0-9_-]+/', '_', $schedule["schedule_name"]) . "_" . date("Y-m-d_H-i-s") . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");
echo "\xEF\xBB\xBF";
?>
<html>
<head>
<meta charset="utf-8">
<style>
    table { border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #9ca3af; font-family: Arial, sans-serif; font-size: 9pt; padding: 3px 5px; vertical-align: middle; white-space: normal; }
    th { background: #d9e2f3; color: #000000; font-weight: bold; text-align: left; }
    .project-row td { background: #92d050; color: #000000; font-weight: bold; font-size: 10pt; }
    .topic-row td { background: #ffff00; color: #000000; font-weight: bold; }
    .task-row td { background: #ffffff; color: #000000; font-weight: normal; }
    .vendor-task td:first-child { color: #00b050; font-weight: bold; }
    .duration { font-weight: bold; text-align: left; }
    .date-cell { text-align: left; }
    .text-cell { text-align: left; }
</style>
</head>
<body>
<table>
    <col style="width:520px;">
    <col style="width:115px;">
    <col style="width:115px;">
    <col style="width:115px;">
    <tr>
        <th>Task Name</th>
        <th>Duration</th>
        <th>Start</th>
        <th>Finish</th>
    </tr>
    <tr class="project-row">
        <td><?= pms_xls_e(strtoupper($schedule["project_name"])) ?></td>
        <td class="duration"><?= pms_xls_e(pms_xls_days($schedule["overall_duration_days"])) ?></td>
        <td class="date-cell"><?= pms_xls_e(pms_xls_date($schedule["overall_start_date"])) ?></td>
        <td class="date-cell"><?= pms_xls_e(pms_xls_date($schedule["overall_end_date"])) ?></td>
    </tr>
    <?php foreach ($rows as $item): ?>
        <?php
            $isTopic = $item["item_type"] === "topic";
            $depth = (int)$item["_depth"];
            $indent = str_repeat("&nbsp;&nbsp;&nbsp;&nbsp;", max(0, $depth + 1));
            $title = $indent . pms_xls_e($item["title"]);
            $rowClass = $isTopic ? "topic-row" : "task-row";
            if (!$isTopic && stripos($item["title"], "vendor") !== false) { $rowClass .= " vendor-task"; }
        ?>
        <tr class="<?= $rowClass ?>">
            <td class="text-cell"><?= $title ?></td>
            <td class="duration"><?= pms_xls_e(pms_xls_days($item["duration_days"])) ?></td>
            <td class="date-cell"><?= pms_xls_e(pms_xls_date($item["planned_start_date"])) ?></td>
            <td class="date-cell"><?= pms_xls_e(pms_xls_date($item["planned_end_date"])) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
        <tr><td colspan="4">No PMS items found.</td></tr>
    <?php endif; ?>
</table>
</body>
</html>
