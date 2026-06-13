<?php
session_start();
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/report-hub-api-helper.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../reports-hub.php");
    exit;
}

function pr_sql_value($conn, $value)
{
    if ($value === null) {
        return "NULL";
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function pr_table_columns($conn, $tableName)
{
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $cols = [];
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$tableName`");

    while ($q && ($row = mysqli_fetch_assoc($q))) {
        $cols[$row["Field"]] = true;
    }

    return $cols;
}

function pr_ensure_dar_submission_row($conn, $darId)
{
    $darId = (int)$darId;

    $darQ = mysqli_query($conn, "
        SELECT r.*
        FROM dar_reports r
        WHERE r.id = $darId
        LIMIT 1
    ");

    $dar = $darQ ? mysqli_fetch_assoc($darQ) : null;

    if (!$dar) {
        return 0;
    }

    $reportQ = mysqli_query($conn, "
        SELECT id
        FROM master_report_types
        WHERE report_code = 'DAR'
        LIMIT 1
    ");

    $report = $reportQ ? mysqli_fetch_assoc($reportQ) : null;
    $reportTypeId = (int)($report["id"] ?? 0);

    if ($reportTypeId <= 0) {
        return 0;
    }

    $projectId = (int)($dar["project_id"] ?? $dar["site_id"] ?? 0);
    $employeeId = (int)$dar["employee_id"];
    $darDate = $dar["dar_date"];

    $dateEsc = mysqli_real_escape_string($conn, $darDate);

    $cols = pr_table_columns($conn, "project_report_submissions");

    $existingConditions = [];

    if (isset($cols["submission_for_date"])) {
        $existingConditions[] = "submission_for_date = '$dateEsc'";
    }

    if (isset($cols["period_start"]) && isset($cols["period_end"])) {
        $existingConditions[] = "(period_start = '$dateEsc' AND period_end = '$dateEsc')";
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
    if ($userQ && ($user = mysqli_fetch_assoc($userQ))) {
        $userId = (int)($user["user_id"] ?? 0);
    }

    $createdAt = $dar["created_at"] ?? date("Y-m-d H:i:s");

    $data = [
        "project_id" => $projectId,
        "report_type_id" => $reportTypeId,
        "submitted_by_employee_id" => $employeeId,
        "submitted_by_user_id" => $userId > 0 ? $userId : null,
        "submission_for_date" => $darDate,
        "period_start" => $darDate,
        "period_end" => $darDate,
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
            $insertVals[] = pr_sql_value($conn, $value);
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

$submissionId = (int)($_POST["submission_id"] ?? 0);
$remarkLevel = trim($_POST["remark_level"] ?? "");
$remarkAction = trim($_POST["remark_action"] ?? "remark");
$remark = trim($_POST["remark"] ?? "");
$userId = (int)($_SESSION["user_id"] ?? 0);
$employeeId = rh_api_employee_id($conn);
$returnUrl = $_POST["return_url"] ?? "reports-hub.php";

if ($submissionId <= 0 || !in_array($remarkLevel, ["tl", "manager"], true) || !in_array($remarkAction, ["remark", "resubmit"], true) || $remark === "") {
    rh_api_redirect_with($returnUrl, ["error" => "Invalid remark details."]);
}

try {
    mysqli_begin_transaction($conn);

    $q = mysqli_query($conn, "
        SELECT
            prs.*,
            rt.report_name,
            rt.report_code,
            p.project_name
        FROM project_report_submissions prs
        INNER JOIN master_report_types rt ON rt.id = prs.report_type_id
        INNER JOIN projects p ON p.id = prs.project_id
        WHERE prs.id = $submissionId
        LIMIT 1
        FOR UPDATE
    ");

    $submission = $q ? mysqli_fetch_assoc($q) : null;

    if (!$submission) {
        $compatSubmissionId = pr_ensure_dar_submission_row($conn, $submissionId);

        if ($compatSubmissionId > 0) {
            $submissionId = $compatSubmissionId;

            $q = mysqli_query($conn, "
                SELECT
                    prs.*,
                    rt.report_name,
                    rt.report_code,
                    p.project_name
                FROM project_report_submissions prs
                INNER JOIN master_report_types rt ON rt.id = prs.report_type_id
                INNER JOIN projects p ON p.id = prs.project_id
                WHERE prs.id = $submissionId
                LIMIT 1
                FOR UPDATE
            ");

            $submission = $q ? mysqli_fetch_assoc($q) : null;
        }
    }

    if (!$submission) {
        throw new Exception("Submission not found.");
    }

    $isSuperAdmin = rh_api_is_super_admin($conn, $userId);
    $hierarchy = rh_api_hierarchy($conn, (int)$submission["project_id"]);

    $accessColumn = $remarkLevel === "manager" ? "can_remark_manager" : "can_remark_tl";
    $requiredEmployee = $remarkLevel === "manager" ? (int)$hierarchy["manager"] : (int)$hierarchy["team_lead"];

    $hasAccess = $isSuperAdmin || rh_api_has_report_access($conn, $userId, (int)$submission["report_type_id"], $accessColumn);
    $isHierarchyPerson = (int)$requiredEmployee === (int)$employeeId;

    if (!$isSuperAdmin && (!$hasAccess || !$isHierarchyPerson)) {
        throw new Exception("You do not have access to send remark/resubmit for this report.");
    }

    $status = $remarkLevel === "manager" ? "manager_remark" : "tl_remark";

    if ($remarkAction === "resubmit") {
        $status = $remarkLevel === "manager" ? "manager_resubmit" : "tl_resubmit";
    }

    if ($remarkLevel === "manager") {
        $stmt = mysqli_prepare($conn, "
            UPDATE project_report_submissions
            SET status = ?,
                manager_remark = ?,
                last_resubmit_by_employee_id = CASE WHEN ? = 'resubmit' THEN ? ELSE last_resubmit_by_employee_id END,
                last_resubmit_at = CASE WHEN ? = 'resubmit' THEN NOW() ELSE last_resubmit_at END,
                updated_by = ?
            WHERE id = ?
        ");
    } else {
        $stmt = mysqli_prepare($conn, "
            UPDATE project_report_submissions
            SET status = ?,
                tl_remark = ?,
                last_resubmit_by_employee_id = CASE WHEN ? = 'resubmit' THEN ? ELSE last_resubmit_by_employee_id END,
                last_resubmit_at = CASE WHEN ? = 'resubmit' THEN NOW() ELSE last_resubmit_at END,
                updated_by = ?
            WHERE id = ?
        ");
    }

    if (!$stmt) {
        throw new Exception("Unable to prepare remark update: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "sssisii", $status, $remark, $remarkAction, $employeeId, $remarkAction, $userId, $submissionId);

    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Unable to save remark: " . mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "
        INSERT INTO project_report_remarks
        (submission_id, remark_by_employee_id, remark_by_user_id, remark_level, action_status, remark)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "iiisss", $submissionId, $employeeId, $userId, $remarkLevel, $remarkAction, $remark);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    $reasonCode = $remarkAction === "resubmit" ? "PROJECT_REPORT_RESUBMIT" : "PROJECT_REPORT_REMARK";
    $titlePrefix = $remarkAction === "resubmit" ? "Resubmit Requested" : "New Remark";
    $link = "reports-hub.php?project_id=" . (int)$submission["project_id"];
    $priority = $remarkAction === "resubmit" ? "high" : "normal";
    $type = $remarkAction === "resubmit" ? "warning" : "info";

    if ($remarkLevel === "manager") {
        $message = "Manager remark: " . $remark . ". TL remark: " . ($submission["tl_remark"] ?: "-");

        if ((int)$hierarchy["team_lead"] > 0) {
            rh_api_send_notification($conn, (int)$hierarchy["team_lead"], "$titlePrefix by Manager", $message, $link, $submissionId, $reasonCode, $priority, $type);
        }

        rh_api_send_notification($conn, (int)$submission["submitted_by_employee_id"], "$titlePrefix by Manager", $message, $link, $submissionId, $reasonCode, $priority, $type);
    } else {
        $message = "TL remark: " . $remark;
        rh_api_send_notification($conn, (int)$submission["submitted_by_employee_id"], "$titlePrefix by TL", $message, $link, $submissionId, $reasonCode, $priority, $type);
    }

    rh_api_log($conn, "update", $titlePrefix . " sent for " . $submission["report_code"] . " - " . $submission["project_name"], $submissionId);

    mysqli_commit($conn);

    rh_api_redirect_with($returnUrl, ["remark" => 1]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    rh_api_redirect_with($returnUrl, ["error" => $e->getMessage()]);
}
?>