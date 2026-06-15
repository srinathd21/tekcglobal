<?php
session_start();
require_once __DIR__ . "/includes/db.php";

require_permission($conn, "can_view", "reports-hub.php");

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function rh_employee_id($conn)
{
    if (!empty($_SESSION["employee_id"])) {
        return (int)$_SESSION["employee_id"];
    }

    if (!empty($_SESSION["user_id"])) {
        $uid = (int)$_SESSION["user_id"];
        $q = mysqli_query($conn, "SELECT employee_id FROM users WHERE id = $uid LIMIT 1");

        if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r["employee_id"])) {
            $_SESSION["employee_id"] = (int)$r["employee_id"];
            return (int)$r["employee_id"];
        }
    }

    return 0;
}

function rh_role_ids($conn)
{
    if (empty($_SESSION["user_id"])) {
        return [0];
    }

    $uid = (int)$_SESSION["user_id"];
    $ids = [];

    $q = mysqli_query($conn, "SELECT role_id FROM user_roles WHERE user_id = $uid");

    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $ids[] = (int)$r["role_id"];
    }

    return $ids ?: [0];
}

function rh_is_super_admin($conn)
{
    if (empty($_SESSION["user_id"])) {
        return false;
    }

    $uid = (int)$_SESSION["user_id"];

    $q = mysqli_query($conn, "
        SELECT r.id
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = $uid
          AND (r.role_slug = 'super-admin' OR LOWER(r.role_name) = 'super admin')
        LIMIT 1
    ");

    return $q && mysqli_num_rows($q) > 0;
}

function rh_show_date($d)
{
    return ($d && $d !== "0000-00-00") ? date("d M Y", strtotime($d)) : "-";
}

function rh_freq($type, $days)
{
    return $type === "custom_days" ? "Every " . (int)$days . " days" : ucwords(str_replace("_", " ", $type));
}

function rh_period($type, $customDays, $date)
{
    $ts = strtotime($date);
    $date = date("Y-m-d", $ts);

    if ($type === "weekly") {
        return [
            date("Y-m-d", strtotime("monday this week", $ts)),
            date("Y-m-d", strtotime("sunday this week", $ts)),
            null
        ];
    }

    if ($type === "monthly") {
        return [date("Y-m-01", $ts), date("Y-m-t", $ts), null];
    }

    if ($type === "custom_days") {
        $days = max(1, (int)$customDays);
        $base = strtotime(date("Y-01-01", $ts));
        $diff = (int)floor(($ts - $base) / 86400);
        $slot = (int)floor($diff / $days);
        $start = date("Y-m-d", strtotime("+" . ($slot * $days) . " days", $base));
        $end = date("Y-m-d", strtotime("+" . ($days - 1) . " days", strtotime($start)));
        return [$start, $end, null];
    }

    return [$date, $date, $date];
}

function rh_url($overrides = [])
{
    $q = $_GET;
    unset($q["saved"], $q["resubmitted"], $q["reminder"], $q["remark"], $q["success"], $q["error"], $q["deleted"], $q["access"]);
    $q = array_merge($q, $overrides);
    return "reports-hub.php?" . http_build_query($q);
}

function rh_clean_return_url()
{
    $params = $_GET;
    unset($params["saved"], $params["resubmitted"], $params["reminder"], $params["remark"], $params["success"], $params["error"], $params["deleted"], $params["access"]);

    $url = "reports-hub.php";
    if (!empty($params)) {
        $url .= "?" . http_build_query($params);
    }

    return $url;
}

function rh_project_hierarchy($conn, $projectId)
{
    $projectId = (int)$projectId;
    $data = [
        "manager" => 0,
        "team_lead" => 0,
    ];

    $q = mysqli_query($conn, "
        SELECT manager_employee_id, team_lead_employee_id
        FROM projects
        WHERE id = $projectId
        LIMIT 1
    ");

    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $data["manager"] = (int)($r["manager_employee_id"] ?? 0);
        $data["team_lead"] = (int)($r["team_lead_employee_id"] ?? 0);
    }

    $fallbackQ = mysqli_query($conn, "
        SELECT pa.employee_id, par.role_key
        FROM project_assignments pa
        INNER JOIN project_assignment_roles par ON par.id = pa.assignment_role_id
        WHERE pa.project_id = $projectId
          AND pa.status = 'active'
          AND par.role_key IN ('MANAGER','TEAM_LEAD')
        ORDER BY pa.is_primary DESC, pa.id ASC
    ");

    while ($fallbackQ && ($row = mysqli_fetch_assoc($fallbackQ))) {
        if ($row["role_key"] === "MANAGER" && $data["manager"] <= 0) {
            $data["manager"] = (int)$row["employee_id"];
        }

        if ($row["role_key"] === "TEAM_LEAD" && $data["team_lead"] <= 0) {
            $data["team_lead"] = (int)$row["employee_id"];
        }
    }

    return $data;
}

function rh_is_project_assigned($conn, $projectId, $employeeId)
{
    $projectId = (int)$projectId;
    $employeeId = (int)$employeeId;

    if ($projectId <= 0 || $employeeId <= 0) {
        return false;
    }

    $q = mysqli_query($conn, "
        SELECT id
        FROM project_assignments
        WHERE project_id = $projectId
          AND employee_id = $employeeId
          AND status = 'active'
        LIMIT 1
    ");

    return $q && mysqli_num_rows($q) > 0;
}

function rh_report_role_access($conn, $reportTypeId, $roleSql)
{
    $reportTypeId = (int)$reportTypeId;

    $access = [
        "can_submit" => 0,
        "can_view" => 0,
        "can_remark_tl" => 0,
        "can_remark_manager" => 0,
    ];

    $q = mysqli_query($conn, "
        SELECT
            MAX(COALESCE(can_submit,0)) AS can_submit,
            MAX(COALESCE(can_view,0)) AS can_view,
            MAX(COALESCE(can_remark_tl,0)) AS can_remark_tl,
            MAX(COALESCE(can_remark_manager,0)) AS can_remark_manager
        FROM report_type_role_access
        WHERE report_type_id = $reportTypeId
          AND role_id IN ($roleSql)
    ");

    if ($q && ($row = mysqli_fetch_assoc($q))) {
        foreach ($access as $key => $value) {
            $access[$key] = (int)($row[$key] ?? 0);
        }
    }

    return $access;
}


function rh_latest_pending_reminder($conn, $projectId, $reportTypeId, $periodStart, $periodEnd, $reportDate)
{
    $projectId = (int)$projectId;
    $reportTypeId = (int)$reportTypeId;
    $periodStart = mysqli_real_escape_string($conn, $periodStart);
    $periodEnd = mysqli_real_escape_string($conn, $periodEnd);
    $reportDate = mysqli_real_escape_string($conn, $reportDate);

    $q = mysqli_query($conn, "
        SELECT prr.*, emp.full_name AS sent_by_name
        FROM project_report_pending_remarks prr
        LEFT JOIN employees emp ON emp.id = prr.remark_by_employee_id
        WHERE prr.project_id = $projectId
          AND prr.report_type_id = $reportTypeId
          AND (
                (prr.period_start = '$periodStart' AND prr.period_end = '$periodEnd')
             OR prr.report_date = '$reportDate'
          )
        ORDER BY prr.id DESC
        LIMIT 1
    ");

    return $q ? mysqli_fetch_assoc($q) : null;
}

function rh_report_reminder_targets($conn, $projectId, $reportTypeId, $hierarchy, $canRemarkTl, $canRemarkManager, $isSuperAdmin)
{
    $projectId = (int)$projectId;
    $reportTypeId = (int)$reportTypeId;
    $targets = [];

    if ($canRemarkTl || $canRemarkManager || $isSuperAdmin) {
        $submitterQ = mysqli_query($conn, "
            SELECT DISTINCT e.id, e.full_name, e.employee_code
            FROM employees e
            INNER JOIN users u ON u.employee_id = e.id
            INNER JOIN user_roles ur ON ur.user_id = u.id
            INNER JOIN report_type_role_access rtra ON rtra.role_id = ur.role_id
            INNER JOIN project_assignments pa ON pa.employee_id = e.id
            WHERE rtra.report_type_id = $reportTypeId
              AND rtra.can_submit = 1
              AND pa.project_id = $projectId
              AND pa.status = 'active'
              AND e.employee_status = 'active'
            ORDER BY e.full_name ASC
        ");

        while ($submitterQ && ($emp = mysqli_fetch_assoc($submitterQ))) {
            $targets[(int)$emp["id"]] = [
                "id" => (int)$emp["id"],
                "name" => $emp["full_name"] . (!empty($emp["employee_code"]) ? " (" . $emp["employee_code"] . ")" : ""),
                "type" => "Submitter"
            ];
        }
    }

    if (($canRemarkManager || $isSuperAdmin) && (int)$hierarchy["team_lead"] > 0) {
        $tlId = (int)$hierarchy["team_lead"];
        $q = mysqli_query($conn, "SELECT id, full_name, employee_code FROM employees WHERE id = $tlId LIMIT 1");
        if ($q && ($emp = mysqli_fetch_assoc($q))) {
            $targets[$tlId] = [
                "id" => $tlId,
                "name" => $emp["full_name"] . (!empty($emp["employee_code"]) ? " (" . $emp["employee_code"] . ")" : ""),
                "type" => "Team Lead"
            ];
        }
    }

    if ($isSuperAdmin && (int)$hierarchy["manager"] > 0) {
        $managerId = (int)$hierarchy["manager"];
        $q = mysqli_query($conn, "SELECT id, full_name, employee_code FROM employees WHERE id = $managerId LIMIT 1");
        if ($q && ($emp = mysqli_fetch_assoc($q))) {
            $targets[$managerId] = [
                "id" => $managerId,
                "name" => $emp["full_name"] . (!empty($emp["employee_code"]) ? " (" . $emp["employee_code"] . ")" : ""),
                "type" => "Manager"
            ];
        }
    }

    return array_values($targets);
}


function rh_table_columns($conn, $tableName)
{
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $cols = [];
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$tableName`");
    while ($q && ($row = mysqli_fetch_assoc($q))) {
        $cols[$row["Field"]] = true;
    }
    return $cols;
}

function rh_sql_value($conn, $value)
{
    if ($value === null) {
        return "NULL";
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function rh_ensure_dar_submission_row($conn, $darRow, $reportTypeId, $periodStart, $periodEnd, $selectedDate)
{
    $reportTypeId = (int)$reportTypeId;
    $projectId = (int)($darRow["project_id"] ?? 0);
    $employeeId = (int)($darRow["employee_id"] ?? 0);
    $darId = (int)($darRow["id"] ?? 0);
    $darDate = $darRow["dar_date"] ?? $selectedDate;

    if ($projectId <= 0 || $reportTypeId <= 0 || $employeeId <= 0 || $darDate === "") {
        return 0;
    }

    $dateEsc = mysqli_real_escape_string($conn, $darDate);
    $periodStartEsc = mysqli_real_escape_string($conn, $periodStart ?: $darDate);
    $periodEndEsc = mysqli_real_escape_string($conn, $periodEnd ?: $darDate);

    $cols = rh_table_columns($conn, "project_report_submissions");

    $existingConditions = [];

    if (isset($cols["submission_for_date"])) {
        $existingConditions[] = "submission_for_date = '$dateEsc'";
    }

    if (isset($cols["period_start"]) && isset($cols["period_end"])) {
        $existingConditions[] = "(period_start = '$periodStartEsc' AND period_end = '$periodEndEsc')";
    }

    if (isset($cols["report_reference_table"]) && isset($cols["report_reference_id"])) {
        $existingConditions[] = "(report_reference_table = 'dar_reports' AND report_reference_id = $darId)";
    }

    if (isset($cols["source_table"]) && isset($cols["source_id"])) {
        $existingConditions[] = "(source_table = 'dar_reports' AND source_id = $darId)";
    }

    if (isset($cols["reference_id"])) {
        $existingConditions[] = "reference_id = $darId";
    }

    if (!$existingConditions) {
        $existingConditions[] = "1 = 0";
    }

    $existingWhere = implode(" OR ", $existingConditions);

    $existingQ = mysqli_query($conn, "
        SELECT id
        FROM project_report_submissions
        WHERE project_id = $projectId
          AND report_type_id = $reportTypeId
          AND ($existingWhere)
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($existingQ && ($existing = mysqli_fetch_assoc($existingQ))) {
        return (int)$existing["id"];
    }

    $userId = 0;
    $userQ = mysqli_query($conn, "SELECT user_id FROM employees WHERE id = $employeeId LIMIT 1");
    if ($userQ && ($userRow = mysqli_fetch_assoc($userQ))) {
        $userId = (int)($userRow["user_id"] ?? 0);
    }

    $createdAt = $darRow["created_at"] ?? date("Y-m-d H:i:s");

    $data = [
        "project_id" => $projectId,
        "report_type_id" => $reportTypeId,
        "submitted_by_employee_id" => $employeeId,
        "submitted_by_user_id" => $userId > 0 ? $userId : null,
        "submission_for_date" => $darDate,
        "period_start" => $periodStart ?: $darDate,
        "period_end" => $periodEnd ?: $darDate,
        "status" => "submitted",
        "submitted_at" => $createdAt,
        "report_reference_table" => "dar_reports",
        "report_reference_id" => $darId,
        "source_table" => "dar_reports",
        "source_id" => $darId,
        "reference_id" => $darId,
        "created_by" => $userId > 0 ? $userId : null,
        "updated_by" => $userId > 0 ? $userId : null,
        "created_at" => $createdAt,
        "updated_at" => date("Y-m-d H:i:s"),
    ];

    $insertCols = [];
    $insertVals = [];

    foreach ($data as $field => $value) {
        if (isset($cols[$field])) {
            $insertCols[] = "`$field`";
            $insertVals[] = rh_sql_value($conn, $value);
        }
    }

    if (!$insertCols) {
        return 0;
    }

    $sql = "INSERT INTO project_report_submissions (" . implode(",", $insertCols) . ") VALUES (" . implode(",", $insertVals) . ")";
    if (!mysqli_query($conn, $sql)) {
        return 0;
    }

    return (int)mysqli_insert_id($conn);
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["saved"])) {
    $pageMessageType = "success";
    $pageMessageText = "Report submitted successfully.";
} elseif (isset($_GET["resubmitted"])) {
    $pageMessageType = "success";
    $pageMessageText = "Report resubmitted successfully.";
} elseif (isset($_GET["reminder"])) {
    $pageMessageType = "success";
    $pageMessageText = "Submit reminder sent successfully.";
} elseif (isset($_GET["remark"])) {
    $pageMessageType = "success";
    $pageMessageText = "Remark sent successfully.";
} elseif (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = trim($_GET["success"]) !== "" ? trim($_GET["success"]) : "Action completed successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$employeeId = rh_employee_id($conn);
$roleIds = rh_role_ids($conn);
$roleSql = implode(",", array_map("intval", $roleIds));
$isSuperAdmin = rh_is_super_admin($conn);

$selectedDate = $_GET["report_date"] ?? date("Y-m-d");
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date("Y-m-d");
}

$projectFilter = (int)($_GET["project_id"] ?? 0);
$frequencyFilter = trim($_GET["frequency"] ?? "");
$statusFilter = trim($_GET["status"] ?? "");

$projectWhere = "p.deleted_at IS NULL";

if (!$isSuperAdmin) {
    $projectWhere .= "
        AND (
            EXISTS (
                SELECT 1
                FROM project_assignments pa
                WHERE pa.project_id = p.id
                  AND pa.employee_id = " . (int)$employeeId . "
                  AND pa.status = 'active'
            )
            OR p.manager_employee_id = " . (int)$employeeId . "
            OR p.team_lead_employee_id = " . (int)$employeeId . "
        )
    ";
}

$projects = [];
$pq = mysqli_query($conn, "
    SELECT p.id, p.project_name, p.project_code, p.manager_employee_id, p.team_lead_employee_id
    FROM projects p
    WHERE $projectWhere
    ORDER BY p.project_name ASC
");

while ($pq && ($p = mysqli_fetch_assoc($pq))) {
    $projects[] = $p;
}

if ($projectFilter <= 0 && $projects) {
    $projectFilter = (int)$projects[0]["id"];
}

$selectedProject = null;

foreach ($projects as $p) {
    if ((int)$p["id"] === $projectFilter) {
        $selectedProject = $p;
        break;
    }
}

$selectedHierarchy = $selectedProject ? rh_project_hierarchy($conn, (int)$selectedProject["id"]) : ["manager" => 0, "team_lead" => 0];

$reportWhere = ["rt.is_active = 1"];

if ($frequencyFilter !== "" && in_array($frequencyFilter, ["daily", "weekly", "monthly", "custom_days", "on_demand"], true)) {
    $reportWhere[] = "rt.frequency_type = '" . mysqli_real_escape_string($conn, $frequencyFilter) . "'";
}

if (!$isSuperAdmin) {
    $reportWhere[] = "
        EXISTS (
            SELECT 1
            FROM report_type_role_access rta
            WHERE rta.report_type_id = rt.id
              AND rta.role_id IN ($roleSql)
              AND (
                    rta.can_submit = 1
                 OR rta.can_view = 1
                 OR rta.can_remark_tl = 1
                 OR rta.can_remark_manager = 1
              )
        )
    ";
}

$reports = [];
$rw = implode(" AND ", $reportWhere);

$rq = mysqli_query($conn, "
    SELECT rt.*
    FROM master_report_types rt
    WHERE $rw
    ORDER BY rt.sort_order ASC, rt.report_name ASC
");

while ($rq && ($r = mysqli_fetch_assoc($rq))) {
    $reports[] = $r;
}

$hubRows = [];

if ($selectedProject) {
    foreach ($reports as $report) {
        [$periodStart, $periodEnd, $submissionForDate] = rh_period($report["frequency_type"], $report["custom_days"], $selectedDate);

        $rid = (int)$report["id"];
        $pid = (int)$selectedProject["id"];

        if ($report["frequency_type"] === "daily" || $report["frequency_type"] === "on_demand") {
            $sw = "prs.submission_for_date = '" . mysqli_real_escape_string($conn, $submissionForDate ?: $selectedDate) . "'";
        } else {
            $sw = "prs.period_start = '" . mysqli_real_escape_string($conn, $periodStart) . "'
                   AND prs.period_end = '" . mysqli_real_escape_string($conn, $periodEnd) . "'";
        }

        $sq = mysqli_query($conn, "
            SELECT prs.*, emp.full_name AS submitted_by_name
            FROM project_report_submissions prs
            LEFT JOIN employees emp ON emp.id = prs.submitted_by_employee_id
            WHERE prs.project_id = $pid
              AND prs.report_type_id = $rid
              AND $sw
            ORDER BY prs.id DESC
            LIMIT 1
        ");

        $submission = $sq ? mysqli_fetch_assoc($sq) : null;

        /*
         * DAR is saved in the existing dar_reports table, not only in
         * project_report_submissions. So Reports Hub must also check dar_reports
         * for the selected project/date and mark DAR as submitted.
         */
        if (!$submission && strtoupper((string)$report["report_code"]) === "DAR") {
            $darDate = mysqli_real_escape_string($conn, $submissionForDate ?: $selectedDate);

            $darQ = mysqli_query($conn, "
                SELECT r.*
                FROM dar_reports r
                WHERE r.project_id = $pid
                  AND r.dar_date = '$darDate'
                ORDER BY r.id DESC
                LIMIT 1
            ");

            $darRow = $darQ ? mysqli_fetch_assoc($darQ) : null;

            if ($darRow) {
                $compatSubmissionId = rh_ensure_dar_submission_row($conn, $darRow, $rid, $periodStart, $periodEnd, $submissionForDate ?: $selectedDate);

                if ($compatSubmissionId > 0) {
                    $compatQ = mysqli_query($conn, "
                        SELECT prs.*, emp.full_name AS submitted_by_name
                        FROM project_report_submissions prs
                        LEFT JOIN employees emp ON emp.id = prs.submitted_by_employee_id
                        WHERE prs.id = $compatSubmissionId
                        LIMIT 1
                    ");
                    $submission = $compatQ ? mysqli_fetch_assoc($compatQ) : null;
                }

                if (!$submission) {
                    $submittedBy = "";
                    $empId = (int)($darRow["employee_id"] ?? 0);
                    $empQ = mysqli_query($conn, "SELECT full_name FROM employees WHERE id = $empId LIMIT 1");
                    if ($empQ && ($empRow = mysqli_fetch_assoc($empQ))) {
                        $submittedBy = $empRow["full_name"];
                    }

                    $submission = [
                        "id" => (int)$darRow["id"],
                        "submitted_by_employee_id" => $empId,
                        "submission_for_date" => $darRow["dar_date"],
                        "submitted_at" => $darRow["created_at"],
                        "created_at" => $darRow["created_at"],
                        "status" => "submitted",
                        "submitted_by_name" => $submittedBy,
                        "tl_remark" => "",
                        "manager_remark" => "",
                        "source_table" => "dar_reports"
                    ];
                }
            }
        }

        $printUrl = "";
        if ($submission) {
            if (strtoupper((string)$report["report_code"]) === "DAR" || (($submission["source_table"] ?? "") === "dar_reports")) {
                $darPrintId = (int)($submission["report_reference_id"] ?? $submission["source_id"] ?? $submission["reference_id"] ?? 0);
                if ($darPrintId <= 0 && (($submission["source_table"] ?? "") === "dar_reports")) {
                    $darPrintId = (int)$submission["id"];
                }
                if ($darPrintId <= 0) {
                    $lookupDate = mysqli_real_escape_string($conn, $submission["submission_for_date"] ?? ($submissionForDate ?: $selectedDate));
                    $lookupQ = mysqli_query($conn, "SELECT id FROM dar_reports WHERE project_id = $pid AND dar_date = '$lookupDate' ORDER BY id DESC LIMIT 1");
                    if ($lookupQ && ($lookupRow = mysqli_fetch_assoc($lookupQ))) {
                        $darPrintId = (int)$lookupRow["id"];
                    }
                }
                $printUrl = "reports-print/report-dar-print.php?id=" . $darPrintId;
            } else {
                $printUrl = $report["print_file_name"] . "?view=" . (int)$submission["id"];
            }
        }

        $submissionRawStatus = strtolower((string)($submission["status"] ?? ""));
        $isResubmitRequired = $submission && in_array($submissionRawStatus, [
            "tl_resubmit",
            "manager_resubmit",
            "resubmit",
            "resubmit_requested",
            "sent_back",
            "revision_required"
        ], true);

        if ($statusFilter === "submitted" && (!$submission || $isResubmitRequired)) {
            continue;
        }

        if ($statusFilter === "not_submitted" && $submission && !$isResubmitRequired) {
            continue;
        }

        $access = $isSuperAdmin
            ? ["can_submit" => 1, "can_view" => 1, "can_remark_tl" => 1, "can_remark_manager" => 1]
            : rh_report_role_access($conn, $rid, $roleSql);

        $isAssigned = rh_is_project_assigned($conn, $pid, $employeeId);
        $isProjectManager = (int)$selectedHierarchy["manager"] === (int)$employeeId;
        $isProjectTeamLead = (int)$selectedHierarchy["team_lead"] === (int)$employeeId;

        $canSubmit = $isSuperAdmin || ((int)$access["can_submit"] === 1 && $isAssigned);
        $canView = $isSuperAdmin || (int)$access["can_view"] === 1 || $canSubmit;

        $canRemarkTl = $isSuperAdmin || ((int)$access["can_remark_tl"] === 1 && $isProjectTeamLead);
        $canRemarkManager = $isSuperAdmin || ((int)$access["can_remark_manager"] === 1 && $isProjectManager);

        $reminderTargets = (!$submission || $isResubmitRequired)
            ? rh_report_reminder_targets($conn, $pid, $rid, $selectedHierarchy, $canRemarkTl, $canRemarkManager, $isSuperAdmin)
            : [];

        $pendingReminder = !$submission
            ? rh_latest_pending_reminder($conn, $pid, $rid, $periodStart, $periodEnd, $submissionForDate ?: $selectedDate)
            : null;

        $hubRows[] = [
            "report" => $report,
            "submission" => $submission,
            "period_start" => $periodStart,
            "period_end" => $periodEnd,
            "date" => $submissionForDate ?: $selectedDate,
            "can_submit" => $canSubmit,
            "can_view" => $canView,
            "can_remark_tl" => $canRemarkTl,
            "can_remark_manager" => $canRemarkManager,
            "reminder_targets" => $reminderTargets,
            "pending_reminder" => $pendingReminder,
            "print_url" => $printUrl,
            "is_resubmit_required" => $isResubmitRequired,
            "status" => $submission ? ($isResubmitRequired ? "resubmit_required" : "submitted") : "not_submitted",
        ];
    }
}

$stats = ["total" => count($hubRows), "submitted" => 0, "not_submitted" => 0, "daily_due" => 0];

foreach ($hubRows as $row) {
    if ($row["status"] === "submitted") {
        $stats["submitted"]++;
    } else {
        $stats["not_submitted"]++;
    }

    if ($row["report"]["frequency_type"] === "daily") {
        $stats["daily_due"]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Reports Hub - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>
    <style>
        .page-head-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
        .kpi-row>[class*="col"]{display:flex}.kpi-card{width:100%;height:100%;min-height:118px;background:var(--card-bg);border:1px solid var(--border-soft);border-radius:24px;box-shadow:var(--shadow-card);padding:22px 24px;display:flex;align-items:center;gap:22px}.kpi-icon{width:58px;height:58px;min-width:58px;border-radius:22px;display:inline-flex;align-items:center;justify-content:center}.kpi-label{color:var(--text-muted);font-size:13px;font-weight:800;display:flex;align-items:center;gap:4px;white-space:nowrap}.kpi-value{color:var(--text-main);font-size:24px;font-weight:900;margin:4px 0 2px;line-height:1.15}.kpi-sub{color:var(--text-muted);font-size:12px;font-weight:600;margin:0}.kpi-sub span{color:#008a5b;font-weight:900}
        .filter-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:14px}
        .report-code-pill,.frequency-pill,.period-pill{display:inline-flex;align-items:center;justify-content:center;width:fit-content;max-width:100%;border-radius:999px;padding:5px 12px;font-size:12px;line-height:1;font-weight:900;white-space:nowrap;vertical-align:middle}
        .report-code-pill{color:var(--text-main);background:rgba(148,163,184,.10);border:1px solid var(--border-soft)}
        .frequency-pill{color:#2563eb;background:rgba(37,99,235,.10);border:1px solid rgba(37,99,235,.18)}
        .period-pill{color:#0f172a;background:rgba(148,163,184,.12);border:1px solid var(--border-soft)}
        .modal-content{background:var(--card-bg);color:var(--text-main);border:1px solid var(--border-soft);border-radius:24px;box-shadow:var(--shadow-card)}.modal-header,.modal-footer{border-color:var(--border-soft)}
        .remark-box,.reminder-box{border:1px solid rgba(37,99,235,.20);background:rgba(37,99,235,.06);border-radius:18px;padding:14px}
        .reminder-box{border-color:rgba(245,158,11,.28);background:rgba(245,158,11,.08)}
        .submission-meta{display:block;color:var(--text-muted);font-size:12px;font-weight:700;margin-top:3px}
    </style>
</head>

<body>
    <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>
    <?php include("includes/page-message.php"); ?>

    <div class="min-vh-100 d-flex">
        <?php include("includes/sidebar.php"); ?>

        <main id="main">
            <?php include("includes/nav.php"); ?>

            <section class="page-section p-3 p-lg-3">
                <div class="page-head-card mb-3">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                        <div>
                            <h1 class="h4 fw-bold mb-1">Reports Hub</h1>
                            <p class="text-muted-custom mb-0 small">Access-based report tracker for submit, print, remark, resubmit and reminder actions.</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="<?= e(rh_url(["report_date" => date("Y-m-d", strtotime($selectedDate . " -1 day"))])) ?>" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Previous Day</a>
                            <a href="<?= e(rh_url(["report_date" => date("Y-m-d")])) ?>" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm">Today</a>
                            <a href="<?= e(rh_url(["report_date" => date("Y-m-d", strtotime($selectedDate . " +1 day"))])) ?>" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Next Day</a>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3 kpi-row">
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl"><article class="kpi-card"><div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);"><i data-lucide="clipboard-list"></i></div><div><div class="kpi-label">Total Reports <i data-lucide="info" style="width:12px;height:12px;"></i></div><p class="kpi-value"><?= (int)$stats["total"] ?></p><p class="kpi-sub"><span><?= (int)$stats["total"] ?></span> access-based reports</p></div></article></div>
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl"><article class="kpi-card"><div class="kpi-icon bg-success-subtle text-success"><i data-lucide="check-circle-2"></i></div><div><div class="kpi-label">Submitted <i data-lucide="info" style="width:12px;height:12px;"></i></div><p class="kpi-value"><?= (int)$stats["submitted"] ?></p><p class="kpi-sub"><span><?= (int)$stats["submitted"] ?></span> completed</p></div></article></div>
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl"><article class="kpi-card"><div class="kpi-icon bg-warning-subtle text-warning"><i data-lucide="clock-alert"></i></div><div><div class="kpi-label">Not Submitted <i data-lucide="info" style="width:12px;height:12px;"></i></div><p class="kpi-value"><?= (int)$stats["not_submitted"] ?></p><p class="kpi-sub"><span><?= (int)$stats["not_submitted"] ?></span> pending</p></div></article></div>
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl"><article class="kpi-card"><div class="kpi-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);"><i data-lucide="calendar-days"></i></div><div><div class="kpi-label">Daily Due <i data-lucide="info" style="width:12px;height:12px;"></i></div><p class="kpi-value"><?= (int)$stats["daily_due"] ?></p><p class="kpi-sub"><span><?= (int)$stats["daily_due"] ?></span> daily reports</p></div></article></div>
                </div>

                <form method="GET" class="filter-card mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-3">
                            <label class="form-label fw-bold small">Project</label>
                            <select name="project_id" class="form-select rounded-4">
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= (int)$project["id"] ?>" <?= $projectFilter === (int)$project["id"] ? "selected" : "" ?>>
                                        <?= e($project["project_name"]) ?><?= $project["project_code"] ? " (" . e($project["project_code"]) . ")" : "" ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-2">
                            <label class="form-label fw-bold small">Date</label>
                            <input type="date" name="report_date" class="form-control rounded-4" value="<?= e($selectedDate) ?>">
                        </div>
                        <div class="col-12 col-sm-6 col-lg-2">
                            <label class="form-label fw-bold small">Frequency</label>
                            <select name="frequency" class="form-select rounded-4">
                                <option value="">All</option>
                                <option value="daily" <?= $frequencyFilter === "daily" ? "selected" : "" ?>>Daily</option>
                                <option value="weekly" <?= $frequencyFilter === "weekly" ? "selected" : "" ?>>Weekly</option>
                                <option value="monthly" <?= $frequencyFilter === "monthly" ? "selected" : "" ?>>Monthly</option>
                                <option value="custom_days" <?= $frequencyFilter === "custom_days" ? "selected" : "" ?>>Custom Days</option>
                                <option value="on_demand" <?= $frequencyFilter === "on_demand" ? "selected" : "" ?>>On Demand</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-2">
                            <label class="form-label fw-bold small">Status</label>
                            <select name="status" class="form-select rounded-4">
                                <option value="">All</option>
                                <option value="submitted" <?= $statusFilter === "submitted" ? "selected" : "" ?>>Submitted</option>
                                <option value="not_submitted" <?= $statusFilter === "not_submitted" ? "selected" : "" ?>>Not Submitted</option>
                            </select>
                        </div>
                        <div class="col-12 col-lg-3 d-flex gap-2">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button>
                            <a href="reports-hub.php" class="btn btn-outline-secondary rounded-4 fw-bold">Reset</a>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4">
                        <h2 class="fw-bold fs-6 mb-1">Day-wise Reports Status</h2>
                        <p class="text-muted-custom small mb-0">
                            Selected date: <?= e(rh_show_date($selectedDate)) ?><?= $selectedProject ? " · Project: " . e($selectedProject["project_name"]) : "" ?>
                        </p>
                    </div>

                    <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                        <table class="project-table w-100">
                            <thead>
                                <tr>
                                    <th>Report</th>
                                    <th>Frequency</th>
                                    <th>Period</th>
                                    <th>Status</th>
                                    <th>Submitted By</th>
                                    <th>Remarks</th>
                                    <th style="width:330px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hubRows as $row): ?>
                                    <?php
                                    $report = $row["report"];
                                    $sub = $row["submission"];
                                    $isResubmitRequired = !empty($row["is_resubmit_required"]);
                                    $statusText = $isResubmitRequired ? "Resubmit Required" : ($sub ? "Submitted" : "Not Submitted");
                                    $statusClass = $sub && !$isResubmitRequired ? "green" : "amber";
                                    $statusMetaText = "";
                                    if ($sub) {
                                        $rawStatus = strtolower((string)($sub["status"] ?? "submitted"));
                                        $statusMetaText = $isResubmitRequired ? "resubmit requested" : str_replace("_", " ", $rawStatus);
                                    }
                                    $resubmitRemarkText = "";
                                    if ($sub && $isResubmitRequired) {
                                        $resubmitRemarkText = trim((string)($sub["manager_remark"] ?: $sub["tl_remark"] ?: ""));
                                    }
                                    $remarkAllowed = $sub && !$isResubmitRequired && ($row["can_remark_tl"] || $row["can_remark_manager"]);
                                    $tlReminderAllowed = (!$sub || $isResubmitRequired) && (bool)$row["can_remark_tl"];
                                    $managerReminderAllowed = (!$sub || $isResubmitRequired) && (bool)$row["can_remark_manager"];
                                    $reminderAllowed = $tlReminderAllowed || $managerReminderAllowed;
                                    $remarkLevel = $row["can_remark_manager"] ? "manager" : "tl";
                                    ?>
                                    <tr>
                                        <td><div class="fw-bold"><?= e($report["report_name"]) ?></div><small class="report-code-pill"><?= e($report["report_code"]) ?></small></td>
                                        <td><span class="frequency-pill"><?= e(rh_freq($report["frequency_type"], $report["custom_days"])) ?></span></td>
                                        <td><span class="period-pill"><?= e(rh_show_date($row["period_start"])) ?> - <?= e(rh_show_date($row["period_end"])) ?></span></td>
                                        <td><span class="pill <?= e($statusClass) ?>"><?= e($statusText) ?></span><?php if ($sub && $statusMetaText !== ""): ?><span class="submission-meta"><?= e($statusMetaText) ?></span><?php endif; ?></td>
                                        <td><?= $sub ? e($sub["submitted_by_name"] ?: "-") : "-" ?><?php if ($sub): ?><span class="submission-meta"><?= e(rh_show_date($sub["submitted_at"] ?? $sub["created_at"] ?? "")) ?></span><?php endif; ?></td>
                                        <td>
                                            <?php if ($sub): ?>
                                                <?php if ($isResubmitRequired): ?>
                                                    <div class="small"><b>Resubmit Request:</b> <?= e($resubmitRemarkText !== "" ? $resubmitRemarkText : "-") ?></div>
                                                    <span class="submission-meta">Sent back for correction</span>
                                                <?php else: ?>
                                                    <div class="small"><b>TL:</b> <?= e($sub["tl_remark"] ?: "-") ?></div>
                                                    <div class="small"><b>Manager:</b> <?= e($sub["manager_remark"] ?: "-") ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if (!empty($row["pending_reminder"])): ?>
                                                    <div class="small"><b><?= e(ucwords(str_replace("_", " ", $row["pending_reminder"]["remark_level"]))) ?>:</b> <?= e($row["pending_reminder"]["remark"]) ?></div>
                                                    <span class="submission-meta">Reminder sent<?= !empty($row["pending_reminder"]["sent_by_name"]) ? " by " . e($row["pending_reminder"]["sent_by_name"]) : "" ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted-custom small fw-bold">Pending submission</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php if ($sub && !$isResubmitRequired): ?>
                                                    <?php if ($row["can_view"]): ?>
                                                        <a href="<?= e($row["print_url"]) ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">Print</a>
                                                    <?php endif; ?>

                                                    <?php if ($remarkAllowed): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-warning rounded-4 fw-bold" data-bs-toggle="modal" data-bs-target="#remarkModal" onclick='openRemarkModal(<?= json_encode(["submission_id" => (int)$sub["id"], "report" => $report["report_name"], "level" => $remarkLevel], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Remark / Resubmit</button>
                                                    <?php endif; ?>
                                                <?php elseif ($isResubmitRequired): ?>
                                                    <?php if ($row["can_submit"]): ?>
                                                        <a href="<?= e($report["submit_file_name"]) ?>?project_id=<?= (int)$projectFilter ?>&report_date=<?= e($selectedDate) ?>&period_start=<?= e($row["period_start"]) ?>&period_end=<?= e($row["period_end"]) ?>&resubmit_submission_id=<?= (int)$sub["id"] ?>" class="btn btn-sm brand-gradient text-white rounded-4 fw-bold">Resubmit</a>
                                                    <?php endif; ?>

                                                    <?php if ($row["can_view"]): ?>
                                                        <a href="<?= e($row["print_url"]) ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold">Old Print</a>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if ($row["can_submit"]): ?>
                                                        <a href="<?= e($report["submit_file_name"]) ?>?project_id=<?= (int)$projectFilter ?>&report_date=<?= e($selectedDate) ?>&period_start=<?= e($row["period_start"]) ?>&period_end=<?= e($row["period_end"]) ?>" class="btn btn-sm brand-gradient text-white rounded-4 fw-bold">Submit</a>
                                                    <?php endif; ?>

                                                    <?php if ($reminderAllowed): ?>
                                                        <button type="button"
                                                            class="btn btn-sm btn-outline-warning rounded-4 fw-bold"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#reminderModal"
                                                            onclick='openReminderModal(<?= json_encode([
                                                                "report_type_id" => (int)$report["id"],
                                                                "report" => $report["report_name"],
                                                                "project_id" => (int)$projectFilter,
                                                                "project" => $selectedProject["project_name"] ?? "",
                                                                "period_start" => $row["period_start"],
                                                                "period_end" => $row["period_end"],
                                                                "report_date" => $selectedDate,
                                                                "default_level" => $row["can_remark_manager"] ? "manager" : "tl",
                                                                "targets" => $row["reminder_targets"]
                                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                            Remark
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if (!$row["can_submit"] && !$reminderAllowed): ?>
                                                        <span class="text-muted-custom small fw-bold">No action access</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($hubRows)): ?>
                                    <tr><td colspan="7" class="text-center text-muted-custom py-4">No reports found for selected project/date.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3">
                        <?php foreach ($hubRows as $row): ?>
                            <?php
                            $report = $row["report"];
                            $sub = $row["submission"];
                            $isResubmitRequired = !empty($row["is_resubmit_required"]);
                            $statusText = $isResubmitRequired ? "Resubmit Required" : ($sub ? "Submitted" : "Not Submitted");
                            $statusClass = $sub && !$isResubmitRequired ? "green" : "amber";
                            $resubmitRemarkText = "";
                            if ($sub && $isResubmitRequired) {
                                $resubmitRemarkText = trim((string)($sub["manager_remark"] ?: $sub["tl_remark"] ?: ""));
                            }
                            $remarkAllowed = $sub && !$isResubmitRequired && ($row["can_remark_tl"] || $row["can_remark_manager"]);
                            $tlReminderAllowed = (!$sub || $isResubmitRequired) && (bool)$row["can_remark_tl"];
                            $managerReminderAllowed = (!$sub || $isResubmitRequired) && (bool)$row["can_remark_manager"];
                            $reminderAllowed = $tlReminderAllowed || $managerReminderAllowed;
                            $remarkLevel = $row["can_remark_manager"] ? "manager" : "tl";
                            ?>
                            <article class="mobile-project-card">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                    <div><p class="fw-bold small mb-1"><?= e($report["report_name"]) ?></p><small class="text-muted-custom"><?= e($report["report_code"]) ?></small></div>
                                    <span class="pill <?= e($statusClass) ?>"><?= e($statusText) ?></span>
                                </div>
                                <div class="mobile-info"><span>Frequency</span><span><?= e(rh_freq($report["frequency_type"], $report["custom_days"])) ?></span></div>
                                <div class="mobile-info"><span>Period</span><span><?= e(rh_show_date($row["period_start"])) ?> - <?= e(rh_show_date($row["period_end"])) ?></span></div>
                                <div class="mobile-info"><span>Submitted By</span><span><?= $sub ? e($sub["submitted_by_name"] ?: "-") : "-" ?></span></div>
                                <div class="mobile-info"><span>TL Remark</span><span><?= $sub ? e($sub["tl_remark"] ?: "-") : "-" ?></span></div>
                                <div class="mobile-info"><span>Manager Remark</span><span><?= $sub ? e($sub["manager_remark"] ?: "-") : "-" ?></span></div>
                                <?php if (!$sub && !empty($row["pending_reminder"])): ?>
                                    <div class="mobile-info"><span>Latest Remark</span><span><?= e($row["pending_reminder"]["remark"]) ?></span></div>
                                <?php endif; ?>
                                <div class="mt-3 d-flex flex-wrap gap-2">
                                    <?php if ($sub && !$isResubmitRequired): ?>
                                        <?php if ($row["can_view"]): ?>
                                            <a href="<?= e($row["print_url"]) ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">Print</a>
                                        <?php endif; ?>
                                        <?php if ($remarkAllowed): ?>
                                            <button type="button" class="btn btn-sm btn-outline-warning rounded-4 fw-bold" data-bs-toggle="modal" data-bs-target="#remarkModal" onclick='openRemarkModal(<?= json_encode(["submission_id" => (int)$sub["id"], "report" => $report["report_name"], "level" => $remarkLevel], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Remark</button>
                                        <?php endif; ?>
                                    <?php elseif ($isResubmitRequired): ?>
                                        <?php if ($row["can_submit"]): ?>
                                            <a href="<?= e($report["submit_file_name"]) ?>?project_id=<?= (int)$projectFilter ?>&report_date=<?= e($selectedDate) ?>&period_start=<?= e($row["period_start"]) ?>&period_end=<?= e($row["period_end"]) ?>&resubmit_submission_id=<?= (int)$sub["id"] ?>" class="btn btn-sm brand-gradient text-white rounded-4 fw-bold">Resubmit</a>
                                        <?php endif; ?>
                                        <?php if ($row["can_view"]): ?>
                                            <a href="<?= e($row["print_url"]) ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold">Old Print</a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($row["can_submit"]): ?>
                                            <a href="<?= e($report["submit_file_name"]) ?>?project_id=<?= (int)$projectFilter ?>&report_date=<?= e($selectedDate) ?>&period_start=<?= e($row["period_start"]) ?>&period_end=<?= e($row["period_end"]) ?>" class="btn btn-sm brand-gradient text-white rounded-4 fw-bold">Submit</a>
                                        <?php endif; ?>
                                        <?php if ($reminderAllowed): ?>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-warning rounded-4 fw-bold"
                                                data-bs-toggle="modal"
                                                data-bs-target="#reminderModal"
                                                onclick='openReminderModal(<?= json_encode([
                                                    "report_type_id" => (int)$report["id"],
                                                    "report" => $report["report_name"],
                                                    "project_id" => (int)$projectFilter,
                                                    "project" => $selectedProject["project_name"] ?? "",
                                                    "period_start" => $row["period_start"],
                                                    "period_end" => $row["period_end"],
                                                    "report_date" => $selectedDate,
                                                    "default_level" => $row["can_remark_manager"] ? "manager" : "tl",
                                                    "targets" => $row["reminder_targets"]
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                Remark
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include("includes/rightsidbar.php"); ?>
    </div>

    <div class="modal fade" id="remarkModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form action="api/process-report-hub-remark.php" method="POST" class="modal-content">
                <input type="hidden" name="submission_id" id="remark_submission_id">
                <input type="hidden" name="remark_level" id="remark_level">
                <input type="hidden" name="return_url" value="<?= e(rh_clean_return_url()) ?>">
                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Send Remark / Resubmit</h5>
                        <p class="text-muted-custom small mb-0" id="remarkReportName">Send remark to required person.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4">
                    <div class="remark-box mb-3">
                        <p class="fw-bold mb-1">Access Rules</p>
                        <p class="text-muted-custom small mb-0">This action works only when your role has TL/Manager remark access for this report.</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Action</label>
                        <select name="remark_action" class="form-select rounded-4" required>
                            <option value="remark">Only Remark</option>
                            <option value="resubmit">Ask Resubmit</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold small">Remark</label>
                        <textarea name="remark" class="form-control rounded-4" rows="4" required placeholder="Enter remark"></textarea>
                    </div>
                </div>
                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Send</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="reminderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form action="api/process-report-hub-reminder.php" method="POST" class="modal-content">
                <input type="hidden" name="report_type_id" id="reminder_report_type_id">
                <input type="hidden" name="project_id" id="reminder_project_id">
                <input type="hidden" name="period_start" id="reminder_period_start">
                <input type="hidden" name="period_end" id="reminder_period_end">
                <input type="hidden" name="report_date" id="reminder_report_date">
                <input type="hidden" name="return_url" value="<?= e(rh_clean_return_url()) ?>">
                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold" id="reminderModalTitle">Send Remark</h5>
                        <p class="text-muted-custom small mb-0" id="reminderReportName">Select recipients and send remark.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4">
                    <div class="reminder-box mb-3">
                        <p class="fw-bold mb-1">Who should receive this remark?</p>
                        <p class="text-muted-custom small mb-0">Select one or more employees below. Only valid access-based recipients are shown.</p>
                    </div>

                    <div class="mb-3" id="reminderLevelWrap">
                        <label class="form-label fw-bold small">Send As</label>
                        <select name="remark_level" id="reminder_level" class="form-select rounded-4" required>
                            <option value="tl">Team Lead Remark</option>
                            <option value="manager">Manager Remark</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Recipients</label>
                        <div id="reminderTargets" class="d-grid gap-2"></div>
                        <small class="text-muted-custom fw-bold" id="reminderNoTargets" style="display:none;">No valid recipients found for this report.</small>
                    </div>

                    <div>
                        <label class="form-label fw-bold small">Remark</label>
                        <textarea name="remark" class="form-control rounded-4" rows="4" required placeholder="Please submit this report for the selected date/period."></textarea>
                    </div>
                </div>
                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Send Remark</button>
                </div>
            </form>
        </div>
    </div>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=34"></script>
    <script>
        function openRemarkModal(data) {
            document.getElementById("remark_submission_id").value = data.submission_id || "";
            document.getElementById("remark_level").value = data.level || "";
            document.getElementById("remarkReportName").textContent = "Send remark for " + (data.report || "this report");
        }

        function openReminderModal(data) {
            document.getElementById("reminder_report_type_id").value = data.report_type_id || "";
            document.getElementById("reminder_project_id").value = data.project_id || "";
            document.getElementById("reminder_period_start").value = data.period_start || "";
            document.getElementById("reminder_period_end").value = data.period_end || "";
            document.getElementById("reminder_report_date").value = data.report_date || "";
            document.getElementById("reminder_level").value = data.default_level || "tl";
            document.getElementById("reminderModalTitle").textContent = "Send Remark";
            document.getElementById("reminderReportName").textContent = "Send remark for " + (data.report || "this report") + " - " + (data.project || "");

            const wrap = document.getElementById("reminderTargets");
            const empty = document.getElementById("reminderNoTargets");
            wrap.innerHTML = "";

            const targets = Array.isArray(data.targets) ? data.targets : [];
            empty.style.display = targets.length ? "none" : "block";

            targets.forEach(function (target, index) {
                const id = "target_employee_" + target.id;
                const checked = index === 0 ? "checked" : "";
                const label = document.createElement("label");
                label.className = "d-flex align-items-start gap-2 p-2 rounded-4 border";
                label.innerHTML = `
                    <input class="form-check-input mt-1" type="checkbox" name="target_employees[]" value="${target.id}" id="${id}" ${checked}>
                    <span>
                        <span class="fw-bold d-block">${target.name || "Employee"}</span>
                        <small class="text-muted-custom">${target.type || "Recipient"}</small>
                    </span>
                `;
                wrap.appendChild(label);
            });
        }

        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
