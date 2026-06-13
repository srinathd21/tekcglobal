<?php
require_once __DIR__ . "/includes/db.php";
require_permission($conn, "can_view", "pms.php");

function ex($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function date_show($date)
{
    return ($date && $date !== "0000-00-00") ? $date : "-";
}

function days_label($days)
{
    $days = (int)$days;
    return $days . " day" . ($days === 1 ? "" : "s");
}

function status_class($status)
{
    $status = strtolower(trim($status ?? ""));
    if ($status === "completed") return "completed";
    if ($status === "ongoing") return "ongoing";
    return "pending";
}

function render_pdf_rows($itemsByParent, $parentId = 0, $depth = 0, &$serial = 1)
{
    if (empty($itemsByParent[$parentId])) return;

    foreach ($itemsByParent[$parentId] as $item) {
        $isTopic = $item["item_type"] === "topic";
        $class = $isTopic ? "topic level-" . (int)$item["hierarchy_level"] : "task";
        $indent = min($depth * 14, 70);
        ?>
        <tr class="<?= ex($class) ?>">
            <td class="center"><?= $serial++ ?></td>
            <td>
                <div style="padding-left: <?= (int)$indent ?>px;">
                    <b><?= ex($item["title"]) ?></b><br>
                    <small><?= $isTopic ? "Topic Level " . (int)$item["hierarchy_level"] : "Content / Task" ?></small>
                </div>
            </td>
            <td class="center"><?= (int)$item["duration_days"] ?></td>
            <td><?= ex(date_show($item["planned_start_date"])) ?></td>
            <td><?= ex(date_show($item["planned_end_date"])) ?></td>
            <td><?= ex(date_show($item["actual_start_date"])) ?></td>
            <td><?= ex(date_show($item["actual_end_date"])) ?></td>
            <td class="status <?= status_class($item["item_status"]) ?>"><?= ex($item["item_status"]) ?></td>
            <td class="center"><?= (float)$item["progress_percent"] ?>%</td>
            <td class="center"><?= (int)$item["sort_order"] ?></td>
        </tr>
        <?php
        render_pdf_rows($itemsByParent, (int)$item["id"], $depth + 1, $serial);
    }
}

$scheduleId = (int)($_GET["schedule_id"] ?? 0);

if ($scheduleId <= 0) {
    die("Invalid schedule.");
}

$scheduleQ = mysqli_query($conn, "
    SELECT s.*, p.project_name, p.project_code, p.project_location
    FROM project_pmc_schedules s
    INNER JOIN projects p ON p.id = s.project_id
    WHERE s.id = $scheduleId
      AND p.deleted_at IS NULL
    LIMIT 1
");

$schedule = $scheduleQ ? mysqli_fetch_assoc($scheduleQ) : null;

if (!$schedule) {
    die("Schedule not found.");
}

$itemsByParent = [];
$itemsQ = mysqli_query($conn, "
    SELECT *
    FROM project_pmc_schedule_items
    WHERE schedule_id = $scheduleId
      AND is_active = 1
    ORDER BY COALESCE(parent_id, 0) ASC, sort_order ASC, id ASC
");

if ($itemsQ) {
    while ($item = mysqli_fetch_assoc($itemsQ)) {
        $parentKey = $item["parent_id"] ? (int)$item["parent_id"] : 0;
        $itemsByParent[$parentKey][] = $item;
    }
}

$changes = [];
$changesQ = mysqli_query($conn, "
    SELECT dc.*, i.title AS item_title, u.name AS changed_by_name
    FROM project_pmc_schedule_date_changes dc
    INNER JOIN project_pmc_schedule_items i ON i.id = dc.item_id
    LEFT JOIN users u ON u.id = dc.changed_by
    WHERE dc.schedule_id = $scheduleId
    ORDER BY dc.id DESC
");

if ($changesQ) {
    while ($row = mysqli_fetch_assoc($changesQ)) {
        $changes[] = $row;
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>PMS Detail PDF</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 9mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #ffffff;
            font-family: Arial, sans-serif;
            color: #111827;
            font-size: 10px;
        }

        .toolbar {
            padding: 12px;
            text-align: right;
            background: #f3f4f6;
            border-bottom: 1px solid #d1d5db;
            position: sticky;
            top: 0;
            z-index: 5;
        }

        .btn {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 8px;
            background: #dc2626;
            color: #ffffff;
            text-decoration: none;
            border: 0;
            font-weight: 700;
            cursor: pointer;
        }

        .sheet {
            padding: 8px;
        }

        .header {
            border: 2px solid #111827;
            padding: 10px;
            margin-bottom: 8px;
        }

        .title {
            margin: 0 0 5px;
            font-size: 19px;
            text-transform: uppercase;
            font-weight: 800;
        }

        .sub {
            color: #4b5563;
            margin: 2px 0;
            font-size: 10px;
        }

        .summary-grid {
            display: table;
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }

        .summary-cell {
            display: table-cell;
            border: 1px solid #111827;
            padding: 7px;
            width: 20%;
            vertical-align: top;
        }

        .summary-cell b {
            display: block;
            font-size: 13px;
            margin-bottom: 2px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        th {
            background: #111827;
            color: #ffffff;
            border: 1px solid #111827;
            padding: 6px 4px;
            font-size: 9px;
            text-align: left;
            font-weight: 800;
        }

        td {
            border: 1px solid #6b7280;
            padding: 5px 4px;
            vertical-align: top;
            font-size: 9px;
            word-wrap: break-word;
        }

        .center {
            text-align: center;
        }

        tr.topic.level-1 td {
            background: #fff3c4;
            font-weight: 700;
        }

        tr.topic.level-2 td {
            background: #eaf2ff;
        }

        tr.topic.level-3 td,
        tr.topic.level-4 td,
        tr.topic.level-5 td {
            background: #f0ecff;
        }

        tr.task td {
            background: #ffffff;
        }

        .status {
            text-transform: capitalize;
            font-weight: 800;
        }

        .status.completed {
            color: #047857;
        }

        .status.ongoing {
            color: #2563eb;
        }

        .status.pending {
            color: #d97706;
        }

        .section-title {
            margin: 12px 0 5px;
            font-size: 13px;
            font-weight: 800;
        }

        .footer {
            margin-top: 10px;
            color: #6b7280;
            font-size: 9px;
            text-align: right;
        }

        @media print {
            .toolbar {
                display: none !important;
            }

            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            thead {
                display: table-header-group;
            }

            tr {
                page-break-inside: avoid;
            }

            .page-break {
                page-break-before: always;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Download / Save PDF</button>
    </div>

    <div class="sheet">
        <div class="header">
            <h1 class="title"><?= ex($schedule["schedule_name"]) ?></h1>
            <p class="sub"><b>Project:</b> <?= ex($schedule["project_name"]) ?> <?= $schedule["project_code"] ? "(" . ex($schedule["project_code"]) . ")" : "" ?></p>
            <p class="sub"><b>Location:</b> <?= ex($schedule["project_location"] ?: "-") ?> | <b>Generated:</b> <?= date("d-m-Y h:i A") ?></p>
        </div>

        <div class="summary-grid">
            <div class="summary-cell"><b><?= ex(date_show($schedule["overall_start_date"])) ?></b>Start Date</div>
            <div class="summary-cell"><b><?= ex(date_show($schedule["overall_end_date"])) ?></b>Finish Date</div>
            <div class="summary-cell"><b><?= (int)$schedule["overall_duration_days"] ?></b>Total Days</div>
            <div class="summary-cell"><b><?= ex(ucfirst($schedule["schedule_status"])) ?></b>Status</div>
            <div class="summary-cell"><b>#<?= (int)$schedule["id"] ?></b>Schedule ID</div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:4%;">S.No</th>
                    <th style="width:28%;">Task Name / Topic</th>
                    <th style="width:7%;">Days</th>
                    <th style="width:9%;">Start</th>
                    <th style="width:9%;">Finish</th>
                    <th style="width:9%;">Actual Start</th>
                    <th style="width:9%;">Actual Finish</th>
                    <th style="width:9%;">Status</th>
                    <th style="width:7%;">Progress</th>
                    <th style="width:5%;">Sort</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $serial = 1;
                render_pdf_rows($itemsByParent, 0, 0, $serial);

                if ($serial === 1):
                ?>
                    <tr>
                        <td colspan="10" class="center">No PMS items found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if (!empty($changes)): ?>
            <div class="section-title">End Date Change History</div>
            <table>
                <thead>
                    <tr>
                        <th style="width:5%;">S.No</th>
                        <th style="width:28%;">Task</th>
                        <th style="width:12%;">Old Finish</th>
                        <th style="width:12%;">New Finish</th>
                        <th style="width:30%;">Remark</th>
                        <th style="width:13%;">Changed By / At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($changes as $i => $change): ?>
                        <tr>
                            <td class="center"><?= $i + 1 ?></td>
                            <td><?= ex($change["item_title"]) ?></td>
                            <td><?= ex(date_show($change["old_planned_end_date"])) ?></td>
                            <td><?= ex(date_show($change["new_planned_end_date"])) ?></td>
                            <td><?= ex($change["remark"]) ?></td>
                            <td><?= ex($change["changed_by_name"] ?: "User") ?><br><?= ex($change["changed_at"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="footer">TEK-C PMS Construction - PMS Detail Report</div>
    </div>

    <?php if (isset($_GET["print"])): ?>
        <script>
            window.addEventListener("load", function () {
                setTimeout(function () {
                    window.print();
                }, 400);
            });
        </script>
    <?php endif; ?>
</body>
</html>
