<?php
session_start();
require_once __DIR__ . "/includes/db.php";
date_default_timezone_set('Asia/Kolkata');

function e($v)
{
  return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function emp_id($conn)
{
  if (!empty($_SESSION['employee_id']))
    return (int) $_SESSION['employee_id'];
  if (!empty($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];
    $q = mysqli_query($conn, "SELECT employee_id FROM users WHERE id=$uid LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r['employee_id'])) {
      $_SESSION['employee_id'] = (int) $r['employee_id'];
      return (int) $r['employee_id'];
    }
  }
  return 0;
}
function is_super_admin($conn)
{
  $uid = (int) ($_SESSION['user_id'] ?? 0);
  if ($uid <= 0)
    return false;
  $q = mysqli_query($conn, "SELECT r.id FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=$uid AND (r.role_slug='super-admin' OR LOWER(r.role_name)='super admin') LIMIT 1");
  return $q && mysqli_num_rows($q) > 0;
}
function report_access($conn, $code, $col)
{
  if (is_super_admin($conn))
    return true;
  $allowed = ['can_submit', 'can_view', 'can_remark_tl', 'can_remark_manager'];
  if (!in_array($col, $allowed, true))
    return false;
  $uid = (int) ($_SESSION['user_id'] ?? 0);
  $code = mysqli_real_escape_string($conn, $code);
  $q = mysqli_query($conn, "SELECT MAX(COALESCE(rta.$col,0)) ok FROM user_roles ur INNER JOIN report_type_role_access rta ON rta.role_id=ur.role_id INNER JOIN master_report_types rt ON rt.id=rta.report_type_id WHERE ur.user_id=$uid AND rt.report_code='$code' AND rt.is_active=1");
  return $q && ($r = mysqli_fetch_assoc($q)) && (int) ($r['ok'] ?? 0) === 1;
}
function clean_rows($rows)
{
  $out = [];
  foreach ($rows as $row) {
    $has = false;
    foreach ($row as $v) {
      if (trim((string) $v) !== '') {
        $has = true;
        break;
      }
    }
    if ($has)
      $out[] = $row;
  }
  return $out;
}
function project_allowed($conn, $projectId, $employeeId, $super)
{
  if ($super)
    return true;
  $projectId = (int) $projectId;
  $employeeId = (int) $employeeId;
  $q = mysqli_query($conn, "SELECT p.id FROM projects p WHERE p.id=$projectId AND p.deleted_at IS NULL AND (p.manager_employee_id=$employeeId OR p.team_lead_employee_id=$employeeId OR EXISTS(SELECT 1 FROM project_assignments spe WHERE spe.project_id=p.id AND spe.employee_id=$employeeId AND spe.status='active')) LIMIT 1");
  return $q && mysqli_num_rows($q) > 0;
}
function log_activity($conn, $type, $desc, $ref = null)
{
  $eid = emp_id($conn);
  $name = mysqli_real_escape_string($conn, $_SESSION['employee_name'] ?? $_SESSION['name'] ?? '');
  $user = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
  $des = mysqli_real_escape_string($conn, $_SESSION['designation'] ?? '');
  $dep = mysqli_real_escape_string($conn, $_SESSION['department'] ?? '');
  $type = mysqli_real_escape_string($conn, strtoupper($type));
  $desc = mysqli_real_escape_string($conn, $desc);
  $refsql = $ref ? (int) $ref : 'NULL';
  $ip = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR'] ?? '');
  mysqli_query($conn, "INSERT INTO activity_logs (employee_id,employee_name,username,designation,department,activity_type,module,description,reference_id,ip_address) VALUES ($eid,'$name','$user','$des','$dep','$type','DAR','$desc',$refsql,'$ip')");
}
function notify_emp($conn, $eid, $title, $msg, $ref, $link)
{
  $eid = (int) $eid;
  if ($eid <= 0)
    return;

  $empQ = mysqli_query($conn, "SELECT user_id FROM employees WHERE id=$eid LIMIT 1");
  $empRow = $empQ ? mysqli_fetch_assoc($empQ) : null;
  $targetUserId = (int) ($empRow['user_id'] ?? 0);

  $titleEsc = mysqli_real_escape_string($conn, $title);
  $msgEsc = mysqli_real_escape_string($conn, $msg);
  $link = str_replace('reports-print/reports-print/', 'reports-print/', $link);
  $linkEsc = mysqli_real_escape_string($conn, $link);
  $ref = (int) $ref;

  $createdBy = (int) ($_SESSION['employee_id'] ?? 0);
  $createdByName = mysqli_real_escape_string($conn, $_SESSION['employee_name'] ?? $_SESSION['name'] ?? '');

  $reasonQ = mysqli_query($conn, "
    SELECT id, default_icon, default_color
    FROM master_notification_reasons
    WHERE reason_code='PROJECT_REPORT_REMARK'
       OR reason_code='PROJECT_REPORT_SUBMIT_REMINDER'
    ORDER BY FIELD(reason_code, 'PROJECT_REPORT_REMARK', 'PROJECT_REPORT_SUBMIT_REMINDER')
    LIMIT 1
  ");
  $reason = $reasonQ ? mysqli_fetch_assoc($reasonQ) : null;

  $reasonId = (int) ($reason['id'] ?? 0);
  $icon = mysqli_real_escape_string($conn, $reason['default_icon'] ?? 'file-text');
  $color = mysqli_real_escape_string($conn, $reason['default_color'] ?? '#2563eb');

  $ok = mysqli_query($conn, "
    INSERT INTO notifications
    (reason_id, title, message, target_type, module, reference_id, link, priority, notification_type, icon, color_code, is_system, is_active, sent_at, created_by, created_by_name)
    VALUES
    ($reasonId, '$titleEsc', '$msgEsc', 'employees', 'dar_reports', $ref, '$linkEsc', 'normal', 'info', '$icon', '$color', 1, 1, NOW(), $createdBy, '$createdByName')
  ");

  if (!$ok)
    return;

  $notificationId = (int) mysqli_insert_id($conn);
  if ($notificationId <= 0)
    return;

  mysqli_query($conn, "INSERT INTO notification_employee_targets (notification_id, employee_id) VALUES ($notificationId, $eid)");

  if ($targetUserId > 0) {
    mysqli_query($conn, "INSERT INTO notification_user_targets (notification_id, user_id) VALUES ($notificationId, $targetUserId)");
    mysqli_query($conn, "INSERT INTO notification_user_status (notification_id, user_id) VALUES ($notificationId, $targetUserId)");
  }
}


function dar_table_columns($conn, $tableName)
{
  $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
  $cols = [];
  $q = mysqli_query($conn, "SHOW COLUMNS FROM `$tableName`");
  while ($q && ($row = mysqli_fetch_assoc($q))) {
    $cols[$row["Field"]] = true;
  }
  return $cols;
}

function dar_sql_value($conn, $value)
{
  if ($value === null)
    return "NULL";
  if (is_int($value) || is_float($value))
    return (string) $value;
  return "'" . mysqli_real_escape_string($conn, (string) $value) . "'";
}

function dar_report_type_id($conn)
{
  $q = mysqli_query($conn, "SELECT id FROM master_report_types WHERE report_code='DAR' LIMIT 1");
  if ($q && ($row = mysqli_fetch_assoc($q))) {
    return (int) $row["id"];
  }
  return 0;
}


function dar_existing_for_resubmit($conn, $submissionId, $projectId, $employeeId, $darDate)
{
  $submissionId = (int) $submissionId;
  $projectId = (int) $projectId;
  $employeeId = (int) $employeeId;
  $darDateEsc = mysqli_real_escape_string($conn, $darDate);

  if ($submissionId > 0) {
    $cols = dar_table_columns($conn, "project_report_submissions");
    $selectParts = ["id", "project_id", "submitted_by_employee_id", "submission_for_date", "period_start", "period_end"];

    foreach (["reference_id", "source_id", "report_reference_id"] as $maybeCol) {
      if (isset($cols[$maybeCol])) {
        $selectParts[] = $maybeCol;
      }
    }

    $q = mysqli_query($conn, "
      SELECT " . implode(",", array_unique($selectParts)) . "
      FROM project_report_submissions
      WHERE id = $submissionId
      LIMIT 1
    ");

    if ($q && ($sub = mysqli_fetch_assoc($q))) {
      foreach (["report_reference_id", "source_id", "reference_id"] as $refCol) {
        if (!empty($sub[$refCol])) {
          $refId = (int) $sub[$refCol];
          $darQ = mysqli_query($conn, "SELECT id FROM dar_reports WHERE id = $refId LIMIT 1");
          if ($darQ && ($dar = mysqli_fetch_assoc($darQ))) {
            return (int) $dar["id"];
          }
        }
      }

      $subProjectId = (int) ($sub["project_id"] ?? 0);
      $subEmployeeId = (int) ($sub["submitted_by_employee_id"] ?? 0);
      $subDate = $sub["submission_for_date"] ?: ($sub["period_start"] ?: $darDate);

      if ($subProjectId > 0 && $subDate) {
        $subDateEsc = mysqli_real_escape_string($conn, $subDate);
        $empSql = $subEmployeeId > 0 ? "AND employee_id = $subEmployeeId" : "";
        $darQ = mysqli_query($conn, "
          SELECT id
          FROM dar_reports
          WHERE project_id = $subProjectId
            AND dar_date = '$subDateEsc'
            $empSql
          ORDER BY id DESC
          LIMIT 1
        ");
        if ($darQ && ($dar = mysqli_fetch_assoc($darQ))) {
          return (int) $dar["id"];
        }
      }
    }
  }

  $darQ = mysqli_query($conn, "
    SELECT id
    FROM dar_reports
    WHERE project_id = $projectId
      AND employee_id = $employeeId
      AND dar_date = '$darDateEsc'
    ORDER BY id DESC
    LIMIT 1
  ");

  if ($darQ && ($dar = mysqli_fetch_assoc($darQ))) {
    return (int) $dar["id"];
  }

  return 0;
}

function dar_mark_resubmitted($conn, $submissionId, $projectId, $employeeId, $darDate, $darId)
{
  $submissionId = (int) $submissionId;
  $projectId = (int) $projectId;
  $employeeId = (int) $employeeId;
  $darId = (int) $darId;
  $reportTypeId = dar_report_type_id($conn);
  $darDateEsc = mysqli_real_escape_string($conn, $darDate);
  $userId = (int) ($_SESSION["user_id"] ?? 0);

  if ($reportTypeId <= 0 || $projectId <= 0 || $employeeId <= 0 || $darDate === "") {
    return;
  }

  $cols = dar_table_columns($conn, "project_report_submissions");

  $targetSubmissionId = $submissionId;

  if ($targetSubmissionId <= 0) {
    $q = mysqli_query($conn, "
      SELECT id
      FROM project_report_submissions
      WHERE project_id = $projectId
        AND report_type_id = $reportTypeId
        AND (
              submission_for_date = '$darDateEsc'
           OR (period_start = '$darDateEsc' AND period_end = '$darDateEsc')
        )
      ORDER BY id DESC
      LIMIT 1
    ");
    if ($q && ($row = mysqli_fetch_assoc($q))) {
      $targetSubmissionId = (int) $row["id"];
    }
  }

  if ($targetSubmissionId > 0) {
    $sets = [];

    $safeReportNo = "DAR-" . $projectId . "-" . str_replace("-", "", $darDate) . "-" . $darId;

    if (isset($cols["report_no"]))
      $sets[] = "report_no = '" . mysqli_real_escape_string($conn, $safeReportNo) . "'";
    if (isset($cols["report_number"]))
      $sets[] = "report_number = '" . mysqli_real_escape_string($conn, $safeReportNo) . "'";
    if (isset($cols["submission_no"]))
      $sets[] = "submission_no = '" . mysqli_real_escape_string($conn, $safeReportNo) . "'";

    if (isset($cols["status"]))
      $sets[] = "status = 'submitted'";
    if (isset($cols["submitted_at"]))
      $sets[] = "submitted_at = NOW()";
    if (isset($cols["submitted_by_employee_id"]))
      $sets[] = "submitted_by_employee_id = $employeeId";
    if (isset($cols["submitted_by_user_id"]))
      $sets[] = "submitted_by_user_id = $userId";
    if (isset($cols["submission_for_date"]))
      $sets[] = "submission_for_date = '$darDateEsc'";
    if (isset($cols["period_start"]))
      $sets[] = "period_start = '$darDateEsc'";
    if (isset($cols["period_end"]))
      $sets[] = "period_end = '$darDateEsc'";
    if (isset($cols["reference_id"]))
      $sets[] = "reference_id = $darId";
    if (isset($cols["source_id"]))
      $sets[] = "source_id = $darId";
    if (isset($cols["source_table"]))
      $sets[] = "source_table = 'dar_reports'";
    if (isset($cols["report_reference_id"]))
      $sets[] = "report_reference_id = $darId";
    if (isset($cols["report_reference_table"]))
      $sets[] = "report_reference_table = 'dar_reports'";
    if (isset($cols["updated_by"]))
      $sets[] = "updated_by = $userId";
    if (isset($cols["updated_at"]))
      $sets[] = "updated_at = NOW()";

    if ($sets) {
      mysqli_query($conn, "UPDATE project_report_submissions SET " . implode(", ", $sets) . " WHERE id = $targetSubmissionId");
    }

    return;
  }

  $darNo = "";
  $darQ = mysqli_query($conn, "SELECT dar_no FROM dar_reports WHERE id = $darId LIMIT 1");
  if ($darQ && ($darRow = mysqli_fetch_assoc($darQ))) {
    $darNo = trim((string)($darRow["dar_no"] ?? ""));
  }

  $safeReportNo = $darNo !== "" ? $darNo : ("DAR-" . $projectId . "-" . $darDate . "-" . $darId);
  $safeReportNo = "DAR-" . $projectId . "-" . str_replace("-", "", $darDate) . "-" . $darId;

  $data = [
    "project_id" => $projectId,
    "report_type_id" => $reportTypeId,
    "report_no" => $safeReportNo,
    "report_number" => $safeReportNo,
    "submission_no" => $safeReportNo,
    "submitted_by_employee_id" => $employeeId,
    "submitted_by_user_id" => $userId > 0 ? $userId : null,
    "submission_for_date" => $darDate,
    "period_start" => $darDate,
    "period_end" => $darDate,
    "status" => "submitted",
    "submitted_at" => date("Y-m-d H:i:s"),
    "reference_id" => $darId,
    "source_table" => "dar_reports",
    "source_id" => $darId,
    "report_reference_table" => "dar_reports",
    "report_reference_id" => $darId,
    "created_by" => $userId > 0 ? $userId : null,
    "updated_by" => $userId > 0 ? $userId : null,
    "created_at" => date("Y-m-d H:i:s"),
    "updated_at" => date("Y-m-d H:i:s"),
  ];

  $insertCols = [];
  $insertVals = [];

  foreach ($data as $field => $value) {
    if (isset($cols[$field])) {
      $insertCols[] = "`$field`";
      $insertVals[] = dar_sql_value($conn, $value);
    }
  }

  if ($insertCols) {
    $updateParts = [];
    foreach ($insertCols as $colName) {
      $cleanCol = trim($colName, "`");
      if (!in_array($cleanCol, ["id", "created_at", "created_by"], true)) {
        $updateParts[] = "`$cleanCol` = VALUES(`$cleanCol`)";
      }
    }

    $insertSql = "INSERT INTO project_report_submissions (" . implode(",", $insertCols) . ") VALUES (" . implode(",", $insertVals) . ")";

    if ($updateParts) {
      $insertSql .= " ON DUPLICATE KEY UPDATE " . implode(", ", $updateParts);
    }

    mysqli_query($conn, $insertSql);
  }
}


function dar_redirect_reports_hub($projectId, $darDate, $flag = "saved")
{
  $projectId = (int)$projectId;
  $date = urlencode((string)$darDate);
  $flag = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$flag);

  header(
    "Location: reports-hub.php"
    . "?project_id=" . $projectId
    . "&report_date=" . $date
    . "&period_start=" . $date
    . "&period_end=" . $date
    . "&" . $flag . "=1"
  );
  exit;
}

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
  header('Location: login.php');
  exit;
}
$employeeId = emp_id($conn);
$super = is_super_admin($conn);
if (!report_access($conn, 'DAR', 'can_submit')) {
  header('Location: reports-hub.php?error=' . urlencode('You do not have DAR submit access.'));
  exit;
}

$pageMessageType = '';
$pageMessageText = '';
if (isset($_GET['saved'])) {
  $pageMessageType = 'success';
  $pageMessageText = 'DAR submitted successfully.';
}
if (isset($_GET['error'])) {
  $pageMessageType = 'error';
  $pageMessageText = trim($_GET['error']) ?: 'Something went wrong.';
}

$emp = null;
$eq = mysqli_query($conn, "SELECT * FROM employees WHERE id=$employeeId LIMIT 1");
if ($eq)
  $emp = mysqli_fetch_assoc($eq);
$preparedBy = $emp['full_name'] ?? ($_SESSION['employee_name'] ?? $_SESSION['name'] ?? '');

$projectId = (int) ($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$requestedReportDate = trim($_GET['report_date'] ?? $_POST['dar_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedReportDate)) {
  $requestedReportDate = date('Y-m-d');
}

$resubmitSubmissionId = (int) ($_GET['resubmit_submission_id'] ?? $_POST['resubmit_submission_id'] ?? 0);

$divisions = [];
$dq = mysqli_query($conn, "SELECT division_name FROM company_divisions WHERE is_active=1 AND TRIM(division_name)<>'' ORDER BY division_name ASC");
while ($dq && $d = mysqli_fetch_assoc($dq))
  $divisions[] = $d['division_name'];

$selectedProjectCondition = $projectId > 0 ? " OR p.id = $projectId" : "";

if ($super) {
  $sq = mysqli_query($conn, "
    SELECT p.id,p.project_name,p.project_location,c.client_name
    FROM projects p
    INNER JOIN clients c ON c.id=p.client_id
    WHERE p.deleted_at IS NULL
    ORDER BY p.created_at DESC
  ");
} else {
  $sq = mysqli_query($conn, "
    SELECT DISTINCT p.id,p.project_name,p.project_location,c.client_name
    FROM projects p
    INNER JOIN clients c ON c.id=p.client_id
    LEFT JOIN project_assignments spe ON spe.project_id = p.id AND spe.status = 'active'
    WHERE p.deleted_at IS NULL
      AND (
            p.manager_employee_id = $employeeId
         OR p.team_lead_employee_id = $employeeId
         OR spe.employee_id = $employeeId
         $selectedProjectCondition
      )
    ORDER BY p.created_at DESC
  ");
}

$projects = [];
while ($sq && $s = mysqli_fetch_assoc($sq)) {
  $projects[] = $s;
}
$project = null;
$defaultDistribute = '';
if ($projectId > 0 && project_allowed($conn, $projectId, $employeeId, $super)) {
  $q = mysqli_query($conn, "
    SELECT p.*, c.client_name, c.email client_email, c.mobile_number client_mobile,
           mpt.project_type_name, cd.division_name AS project_division_name
    FROM projects p
    INNER JOIN clients c ON c.id=p.client_id
    LEFT JOIN master_project_types mpt ON mpt.id=p.project_type_id
    LEFT JOIN company_divisions cd ON cd.id=p.division_id
    WHERE p.id=$projectId
    LIMIT 1
  ");
  if ($q)
    $project = mysqli_fetch_assoc($q);
}
if ($project) {
  $parts = [];
  $parts[] = 'Client - ' . trim((string) $project['client_name']);
  if (!empty($project['manager_employee_id'])) {
    $mid = (int) $project['manager_employee_id'];
    $mq = mysqli_query($conn, "SELECT full_name,email FROM employees WHERE id=$mid LIMIT 1");
    if ($mq && $m = mysqli_fetch_assoc($mq))
      $parts[] = trim($m['email'] ?: $m['full_name']);
  }
  $hq = mysqli_query($conn, "SELECT e.full_name, e.email, r.role_name FROM employees e INNER JOIN roles r ON r.id = e.role_id WHERE e.employee_status='active' AND r.role_name IN ('Director','Vice President','General Manager') ORDER BY r.role_name ASC, e.full_name ASC");
  while ($hq && $h = mysqli_fetch_assoc($hq))
    $parts[] = trim($h['email'] ?: $h['full_name']);
  $defaultDistribute = implode(', ', array_values(array_unique(array_filter($parts))));
}
$defaultDivision = $project['project_division_name'] ?? ($divisions[0] ?? '');
$defaultDarNo = '';
if ($projectId > 0) {
  $cq = mysqli_query($conn, "SELECT COUNT(*) cnt FROM dar_reports WHERE project_id=$projectId");
  $cr = $cq ? mysqli_fetch_assoc($cq) : ['cnt' => 0];
  $defaultDarNo = '#' . str_pad(((int) $cr['cnt'] + 1), 2, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dar'])) {
  $postProject = (int) ($_POST['project_id'] ?? 0);
  $resubmitSubmissionId = (int) ($_POST['resubmit_submission_id'] ?? 0);
  $darNo = trim($_POST['dar_no'] ?? '');
  $darDate = trim($_POST['dar_date'] ?? '');
  $division = trim($_POST['division'] ?? '');
  $incharge = trim($_POST['incharge'] ?? '');
  $dist = trim($_POST['report_distribute_to'] ?? '');
  $err = '';
  if ($postProject <= 0 || !project_allowed($conn, $postProject, $employeeId, $super))
    $err = 'Invalid project selection.';
  if ($err === '' && $darNo === '')
    $err = 'DAR No is required.';
  if ($err === '' && $darDate === '')
    $err = 'DAR Date is required.';
  if ($err === '' && $division === '')
    $err = 'Division is required.';
  if ($err === '' && $divisions && !in_array($division, $divisions, true))
    $err = 'Invalid division selected.';
  if ($err === '' && $dist === '')
    $err = 'Report Distribute To is required.';
  $planned = $_POST['ac_planned'] ?? [];
  $achieved = $_POST['ac_achieved'] ?? [];
  $tomorrow = $_POST['ac_tomorrow'] ?? [];
  $remarks = $_POST['ac_remarks'] ?? [];
  $rows = [];
  $max = max(count($planned), count($achieved), count($tomorrow), count($remarks));
  for ($i = 0; $i < $max; $i++) {
    $rows[] = ['planned' => trim($planned[$i] ?? ''), 'achieved' => trim($achieved[$i] ?? ''), 'tomorrow' => trim($tomorrow[$i] ?? ''), 'remarks' => trim($remarks[$i] ?? '')];
  }
  $rows = clean_rows($rows);
  if ($err === '' && !$rows)
    $err = 'Please enter at least one activity row.';
  if ($err === '') {
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE);

    if ($resubmitSubmissionId > 0) {
      $existingDarId = dar_existing_for_resubmit($conn, $resubmitSubmissionId, $postProject, $employeeId, $darDate);

      if ($existingDarId <= 0) {
        $err = 'Original DAR not found for resubmit. Please contact admin.';
      } else {
        $st = mysqli_prepare($conn, "
          UPDATE dar_reports
          SET dar_no = ?,
              division = ?,
              incharge = ?,
              activities_json = ?,
              report_distribute_to = ?,
              prepared_by = ?
          WHERE id = ?
        ");

        if (!$st) {
          $err = 'DB Error: ' . mysqli_error($conn);
        } else {
          mysqli_stmt_bind_param($st, 'ssssssi', $darNo, $division, $incharge, $json, $dist, $preparedBy, $existingDarId);

          if (!mysqli_stmt_execute($st)) {
            $err = 'Failed to update DAR resubmission: ' . mysqli_stmt_error($st);
          } else {
            mysqli_stmt_close($st);
            dar_mark_resubmitted($conn, $resubmitSubmissionId, $postProject, $employeeId, $darDate, $existingDarId);
            log_activity($conn, 'UPDATE', 'Resubmitted DAR ' . $darNo, $existingDarId);

            $siq = mysqli_query($conn, "SELECT project_name,manager_employee_id FROM projects WHERE id=$postProject LIMIT 1");
            $si = $siq ? mysqli_fetch_assoc($siq) : null;
            if ($si && !empty($si['manager_employee_id'])) {
              notify_emp($conn, (int) $si['manager_employee_id'], 'DAR Resubmitted', "$preparedBy resubmitted DAR $darNo for " . $si['project_name'], $existingDarId, "reports-print/report-dar-print.php?id=$existingDarId");
            }

            dar_redirect_reports_hub($postProject, $darDate, 'resubmitted');
          }
          mysqli_stmt_close($st);
        }
      }
    }

    if ($err === '') {
      $dup = mysqli_query($conn, "SELECT id FROM dar_reports WHERE project_id=$postProject AND employee_id=$employeeId AND dar_date='" . mysqli_real_escape_string($conn, $darDate) . "' LIMIT 1");
      if ($dup && ($du = mysqli_fetch_assoc($dup))) {
        header('Location: dar.php?project_id=' . $postProject . '&report_date=' . urlencode($darDate) . '&error=' . urlencode('DAR already submitted for this date. Use the Resubmit button from Reports Hub to update it.'));
        exit;
      }
    }

    if ($err === '') {
      $st = mysqli_prepare($conn, "INSERT INTO dar_reports (project_id,site_id,employee_id,dar_no,dar_date,division,incharge,activities_json,report_distribute_to,prepared_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
      if (!$st) {
        $err = 'DB Error: ' . mysqli_error($conn);
      } else {
        mysqli_stmt_bind_param($st, 'iiisssssss', $postProject, $postProject, $employeeId, $darNo, $darDate, $division, $incharge, $json, $dist, $preparedBy);
        if (!mysqli_stmt_execute($st)) {
          $err = 'Failed to save DAR: ' . mysqli_stmt_error($st);
        } else {
          $newId = mysqli_insert_id($conn);
          mysqli_stmt_close($st);
          if ($resubmitSubmissionId > 0) {
            dar_mark_resubmitted($conn, $resubmitSubmissionId, $postProject, $employeeId, $darDate, $newId);
            log_activity($conn, 'UPDATE', 'Resubmitted DAR ' . $darNo, $newId);
          } else {
            dar_mark_resubmitted($conn, 0, $postProject, $employeeId, $darDate, $newId);
            log_activity($conn, 'CREATE', 'Submitted DAR ' . $darNo, $newId);
          }
          $siq = mysqli_query($conn, "SELECT project_name,manager_employee_id FROM projects WHERE id=$postProject LIMIT 1");
          $si = $siq ? mysqli_fetch_assoc($siq) : null;
          if ($si && !empty($si['manager_employee_id']))
            notify_emp($conn, (int) $si['manager_employee_id'], 'New DAR Submitted', "$preparedBy submitted DAR $darNo for " . $si['project_name'], $newId, "reports-print/report-dar-print.php?id=$newId");
          dar_redirect_reports_hub($postProject, $darDate, $resubmitSubmissionId > 0 ? 'resubmitted' : 'saved');
        }
        mysqli_stmt_close($st);
      }
    }
  }
  if ($err !== '') {
    header('Location: dar.php?project_id=' . $postProject . '&error=' . urlencode($err));
    exit;
  }
}
$previousDarTemplate = null;
$recentTemplate = null;

if ($projectId > 0) {
  $safeRequestedDate = mysqli_real_escape_string($conn, $requestedReportDate);

  /*
   * Load previous data for user-friendly copy support:
   * 1. Prefer the latest DAR before the selected report date.
   * 2. If there is no earlier DAR, fallback to the latest DAR for this project/user.
   * This data is loaded into JavaScript JSON and applied only when the user checks
   * "Load previous data". Unique values like DAR No and Date are not overwritten.
   */
  $tplQ = mysqli_query($conn, "
    SELECT id, dar_no, dar_date, division, incharge, activities_json, report_distribute_to
    FROM dar_reports
    WHERE project_id = $projectId
      AND employee_id = $employeeId
      AND dar_date < '$safeRequestedDate'
    ORDER BY dar_date DESC, created_at DESC
    LIMIT 1
  ");

  if ($tplQ) {
    $previousDarTemplate = mysqli_fetch_assoc($tplQ);
  }

  if (!$previousDarTemplate) {
    $tplQ = mysqli_query($conn, "
      SELECT id, dar_no, dar_date, division, incharge, activities_json, report_distribute_to
      FROM dar_reports
      WHERE project_id = $projectId
        AND employee_id = $employeeId
      ORDER BY dar_date DESC, created_at DESC
      LIMIT 1
    ");

    if ($tplQ) {
      $previousDarTemplate = mysqli_fetch_assoc($tplQ);
    }
  }

  $recentTemplate = $previousDarTemplate;
}

$recent = [];
$rq = mysqli_query($conn, "
  SELECT r.id, r.dar_no, r.dar_date, p.project_name
  FROM dar_reports r
  INNER JOIN projects p ON p.id = r.project_id
  WHERE r.employee_id = $employeeId
  ORDER BY r.created_at DESC
  LIMIT 10
");
while ($rq && $r = mysqli_fetch_assoc($rq)) {
  $recent[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>DAR - TEK-C PMC Construction</title><?php include('includes/links.php'); ?>
  <style>
    .page-head-card {
      background: var(--card-bg);
      border: 1px solid var(--border-soft);
      border-radius: 22px;
      box-shadow: var(--shadow-card);
      padding: 16px
    }

    .section-box {
      background: var(--card-bg);
      border: 1px solid var(--border-soft);
      border-radius: 22px;
      box-shadow: var(--shadow-card);
      padding: 18px
    }

    .mini-head {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 14px
    }

    .mini-icon {
      width: 44px;
      height: 44px;
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(255, 193, 7, .14);
      color: #f5b400
    }

    .form-control,
    .form-select {
      background: var(--card-bg);
      color: var(--text-main);
      border-color: var(--border-soft);
      min-height: 42px;
      font-size: 13px;
      font-weight: 700
    }

    .dar-table th {
      font-size: 12px;
      text-transform: uppercase;
      color: var(--text-muted);
      font-weight: 900;
      background: rgba(148, 163, 184, .10);
      white-space: nowrap
    }

    .dar-table td {
      vertical-align: top
    }

    .badge-soft {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      border: 1px solid var(--border-soft);
      background: rgba(148, 163, 184, .08);
      border-radius: 999px;
      padding: 7px 12px;
      font-size: 12px;
      font-weight: 900
    }

    .recent-card {
      border: 1px solid var(--border-soft);
      border-radius: 18px;
      padding: 12px;
      background: rgba(148, 163, 184, .06)
    }


    .badge-soft input[type="checkbox"] {
      width: 15px;
      height: 15px;
      flex: 0 0 auto;
    }

    .badge-soft span {
      line-height: 1.15;
    }

    @media(max-width:767px) {
      .dar-table {
        min-width: 900px
      }
    }
  </style>
</head>

<body>
  <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none">
  </div><?php include('includes/page-message.php'); ?>
  <div class="min-vh-100 d-flex"><?php include('includes/sidebar.php'); ?>
    <main id="main"><?php include('includes/nav.php'); ?>
      <section class="page-section p-3 p-lg-3">
        <div class="page-head-card mb-3">
          <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
            <div>
              <h1 class="h4 fw-bold mb-1">
                <?= $resubmitSubmissionId > 0 ? 'Resubmit Daily Activity Report (DAR)' : 'Daily Activity Report (DAR)' ?>
              </h1>
              <p class="text-muted-custom mb-0 small">Planned, achieved, planned for tomorrow and remarks.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <?php if ($previousDarTemplate): ?>
                <label class="badge-soft mb-0" title="Load previous submitted DAR data without changing DAR No or Date">
                  <input type="checkbox" class="form-check-input mt-0" id="loadPreviousDarData">
                  <span>
                    <strong>Load previous data</strong>
                    <small class="d-block text-muted-custom fw-semibold">
                      <?= e($previousDarTemplate['dar_no']) ?> · <?= e(date('d M Y', strtotime($previousDarTemplate['dar_date']))) ?>
                    </small>
                  </span>
                </label>
              <?php endif; ?>
              <span class="badge-soft"><i data-lucide="user"
                  style="width:15px;height:15px;"></i><?= e($preparedBy) ?></span><a href="reports-hub.php"
                class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Back to Reports Hub</a></div>
          </div>
        </div>
        <div class="section-box mb-3">
          <div class="mini-head">
            <div class="mini-icon"><i data-lucide="map-pin"></i></div>
            <div>
              <h2 class="fw-bold fs-6 mb-1">Project Selection</h2>
              <p class="text-muted-custom small mb-0">Choose assigned project to prepare DAR.</p>
            </div>
          </div>
          <div class="row g-3 align-items-end">
            <div class="col-lg-9"><label class="form-label fw-bold small">Assigned Project <span
                  class="text-danger">*</span></label><select class="form-select rounded-4" id="projectPicker">
                <option value="">-- Select Project --</option>
                <?php foreach ($projects as $s): ?>
                  <?php $optionLabel = trim(($s['project_name'] ?: ('Project #' . $s['id'])) . ' - ' . ($s['project_location'] ?: '-') . ' (' . ($s['client_name'] ?: '-') . ')'); ?>
                  <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $projectId ? 'selected' : '' ?>>
                    <?= e($optionLabel) ?>
                  </option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-lg-3"><a href="dar.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">Reset</a>
            </div>
          </div>
        </div>
        <div class="section-box mb-3">
          <div class="mini-head">
            <div class="mini-icon"><i data-lucide="building-2"></i></div>
            <div>
              <h2 class="fw-bold fs-6 mb-1">Project Details</h2>
              <p class="text-muted-custom small mb-0">Auto-filled from selected project.</p>
            </div>
          </div><?php if (!$project): ?>
            <p class="text-muted-custom fw-bold mb-0">Please select a project above.</p><?php else: ?>
            <div class="row g-3">
              <div class="col-md-4"><small class="text-muted-custom fw-bold">Project</small>
                <div class="fw-bold"><?= e($project['project_name']) ?></div>
              </div>
              <div class="col-md-4"><small class="text-muted-custom fw-bold">Client</small>
                <div class="fw-bold"><?= e($project['client_name']) ?></div>
              </div>
              <div class="col-md-4"><small class="text-muted-custom fw-bold">Location</small>
                <div class="fw-bold"><?= e($project['project_location']) ?></div>
              </div>
              <div class="col-md-4"><small class="text-muted-custom fw-bold">Project Type</small>
                <div class="fw-bold"><?= e($project['project_type_name'] ?? '-') ?></div>
              </div>
              <div class="col-md-4"><small class="text-muted-custom fw-bold">Project Start</small>
                <div class="fw-bold"><?= e($project['start_date'] ?? '-') ?></div>
              </div>
              <div class="col-md-4"><small class="text-muted-custom fw-bold">Expected Completion</small>
                <div class="fw-bold"><?= e($project['expected_completion_date'] ?? '-') ?></div>
              </div>
              <div class="col-12"><small class="text-muted-custom fw-bold">Scope of Work</small>
                <div class="fw-bold"><?= e($project['scope_of_work'] ?? '-') ?></div>
              </div>
            </div><?php endif; ?>
        </div>
        <form method="POST" autocomplete="off"><input type="hidden" name="submit_dar" value="1"><input type="hidden"
            name="project_id" value="<?= (int) $projectId ?>"><input type="hidden" name="resubmit_submission_id"
            value="<?= (int) $resubmitSubmissionId ?>">
          <div class="section-box mb-3">
            <div class="mini-head">
              <div class="mini-icon"><i data-lucide="file-text"></i></div>
              <div>
                <h2 class="fw-bold fs-6 mb-1">DAR Header</h2>
                <p class="text-muted-custom small mb-0">Division, incharge, DAR number and date.</p>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-md-6"><label class="form-label fw-bold small">Division <span
                    class="text-danger">*</span></label><select class="form-select rounded-4" name="division" required>
                  <option value="">-- Select Division --</option><?php foreach ($divisions as $d): ?>
                    <option value="<?= e($d) ?>" <?= $defaultDivision === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                  <?php endforeach; ?>
                </select><?php if (!$divisions): ?><small class="text-danger fw-bold">No division found. Add in Company
                    Settings.</small><?php endif; ?></div>
              <div class="col-md-6"><label class="form-label fw-bold small">Incharge</label><input
                  class="form-control rounded-4" name="incharge" value="<?= e($preparedBy) ?>"></div>
              <div class="col-md-6"><label class="form-label fw-bold small">DAR No <span
                    class="text-danger">*</span></label><input class="form-control rounded-4" name="dar_no"
                  value="<?= e($defaultDarNo) ?>" required></div>
              <div class="col-md-6"><label class="form-label fw-bold small">Date <span
                    class="text-danger">*</span></label><input type="date" class="form-control rounded-4"
                  name="dar_date" id="dar_date" value="<?= e($requestedReportDate) ?>" required></div>
            </div>
          </div>
          <div class="section-box mb-3">
            <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-2 mb-3">
              <div class="mini-head mb-0">
                <div class="mini-icon"><i data-lucide="list-checks"></i></div>
                <div>
                  <h2 class="fw-bold fs-6 mb-1">Activity</h2>
                  <p class="text-muted-custom small mb-0">Planned / achieved / tomorrow / remarks.</p>
                </div>
              </div><button type="button" class="btn btn-outline-primary rounded-4 fw-bold" id="addActivity">Add
                Row</button>
            </div>

            <div class="table-responsive thin-scrollbar">
              <table class="table table-bordered align-middle mb-0 dar-table">
                <thead>
                  <tr>
                    <th style="width:80px">SL No</th>
                    <th>Planned</th>
                    <th style="width:160px">Achieved</th>
                    <th>Planned For Tomorrow</th>
                    <th>Remarks</th>
                    <th style="width:70px">Del</th>
                  </tr>
                </thead>
                <tbody id="activityBody"><?php for ($i = 0; $i < 6; $i++): ?>
                    <tr>
                      <td class="text-center fw-bold"><span class="slno"><?= $i + 1 ?></span></td>
                      <td><textarea class="form-control rounded-4" name="ac_planned[]" rows="2"></textarea></td>
                      <td><select class="form-select rounded-4" name="ac_achieved[]">
                          <option value="">-- Select --</option>
                          <option>COMPLETE</option>
                          <option>WIP</option>
                          <option>PENDING</option>
                        </select></td>
                      <td><textarea class="form-control rounded-4" name="ac_tomorrow[]" rows="2"></textarea></td>
                      <td><textarea class="form-control rounded-4" name="ac_remarks[]" rows="2"></textarea></td>
                      <td class="text-center"><button type="button"
                          class="btn btn-sm btn-outline-danger rounded-4 delRow"><i data-lucide="trash-2"
                            style="width:14px;height:14px"></i></button></td>
                    </tr><?php endfor; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div class="section-box mb-3">
            <div class="mini-head">
              <div class="mini-icon"><i data-lucide="send"></i></div>
              <div>
                <h2 class="fw-bold fs-6 mb-1">Report Distribution</h2>
                <p class="text-muted-custom small mb-0">Default list is editable.</p>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-lg-8"><label class="form-label fw-bold small">Report Distribute To <span
                    class="text-danger">*</span></label><textarea class="form-control rounded-4"
                  name="report_distribute_to" rows="3" required><?= e($defaultDistribute) ?></textarea></div>
              <div class="col-lg-4"><label class="form-label fw-bold small">Prepared By</label><input
                  class="form-control rounded-4" value="<?= e($preparedBy) ?>" readonly></div>
            </div>
            <div class="d-flex justify-content-end mt-3"><button type="submit"
                class="btn brand-gradient text-white rounded-4 fw-bold px-4" <?= (!$project || !$divisions) ? 'disabled' : '' ?>><?= $resubmitSubmissionId > 0 ? 'Resubmit DAR' : 'Submit DAR' ?></button></div>
          </div>
        </form>
        <section class="card-ui overflow-hidden">
          <div class="p-3 p-lg-4">
            <h2 class="fw-bold fs-6 mb-1">Recent DAR</h2>
            <p class="text-muted-custom small mb-0">Your latest submissions.</p>
          </div>
          <div class="px-3 px-lg-4 pb-4"><?php if (!$recent): ?>
              <p class="text-muted-custom fw-bold mb-0">No DAR submitted yet.</p><?php else: ?>
              <div class="row g-2"><?php foreach ($recent as $r): ?>
                  <div class="col-md-6 col-xl-4">
                    <div class="recent-card">
                      <div class="d-flex justify-content-between gap-2">
                        <div>
                          <div class="fw-bold"><?= e($r['dar_no']) ?></div><small
                            class="text-muted-custom fw-bold"><?= e($r['project_name']) ?></small>
                        </div><span class="pill green"><?= e(date('d M Y', strtotime($r['dar_date']))) ?></span>
                      </div><a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2" target="_blank"
                        href="reports-print/report-dar-print.php?id=<?= (int) $r['id'] ?>">Print</a>
                    </div>
                  </div><?php endforeach; ?>
              </div><?php endif; ?>
          </div>
        </section>
        <?php include('includes/footer.php'); ?>
      </section>
    </main>
    <div id="settingsOverlay"></div><?php include('includes/rightsidbar.php'); ?>
  </div><?php include('includes/script.php'); ?>
  <script src="assets/js/script.js?v=41"></script>
  <script>
    const previousDarTemplate = <?php
    $tplRows = [];
    if ($previousDarTemplate && !empty($previousDarTemplate['activities_json'])) {
      $decoded = json_decode($recentTemplate['activities_json'], true);
      if (is_array($decoded)) {
        $tplRows = $decoded;
      }
    }
    echo json_encode([
      'division' => $previousDarTemplate['division'] ?? '',
      'incharge' => $previousDarTemplate['incharge'] ?? '',
      'report_distribute_to' => $previousDarTemplate['report_distribute_to'] ?? '',
      'rows' => $tplRows
    ], JSON_UNESCAPED_UNICODE);
    ?>;

    document.addEventListener('DOMContentLoaded', function () {
      const picker = document.getElementById('projectPicker');

      if (picker) {
        picker.addEventListener('change', function () {
          const reportDate = document.getElementById('dar_date')?.value || '<?= e($requestedReportDate) ?>';
          location.href = picker.value
            ? 'dar.php?project_id=' + encodeURIComponent(picker.value) + '&report_date=' + encodeURIComponent(reportDate)
            : 'dar.php';
        });
      }

      function icons() {
        if (window.lucide && window.lucide.createIcons) {
          window.lucide.createIcons();
        }
      }

      function renumberRows() {
        document.querySelectorAll('#activityBody tr').forEach(function (row, index) {
          const sl = row.querySelector('.slno');
          if (sl) {
            sl.textContent = index + 1;
          }
        });
        icons();
      }

      function makeActivityRow(rowData = {}) {
        const tr = document.createElement('tr');
        const achieved = rowData.achieved || '';
        tr.innerHTML =
          '<td class="text-center fw-bold"><span class="slno"></span></td>' +
          '<td><textarea class="form-control rounded-4" name="ac_planned[]" rows="2"></textarea></td>' +
          '<td><select class="form-select rounded-4" name="ac_achieved[]">' +
          '<option value="">-- Select --</option><option>COMPLETE</option><option>WIP</option><option>PENDING</option>' +
          '</select></td>' +
          '<td><textarea class="form-control rounded-4" name="ac_tomorrow[]" rows="2"></textarea></td>' +
          '<td><textarea class="form-control rounded-4" name="ac_remarks[]" rows="2"></textarea></td>' +
          '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger rounded-4 delRow">' +
          '<i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>';

        tr.querySelector('[name="ac_planned[]"]').value = rowData.planned || '';
        tr.querySelector('[name="ac_achieved[]"]').value = achieved;
        tr.querySelector('[name="ac_tomorrow[]"]').value = rowData.tomorrow || '';
        tr.querySelector('[name="ac_remarks[]"]').value = rowData.remarks || '';
        return tr;
      }

      document.getElementById('addActivity')?.addEventListener('click', function () {
        const body = document.getElementById('activityBody');
        body.appendChild(makeActivityRow());
        renumberRows();
      });

      document.addEventListener('click', function (event) {
        const btn = event.target.closest('.delRow');
        if (!btn) return;

        const row = btn.closest('tr');
        const rows = document.querySelectorAll('#activityBody tr');

        if (rows.length <= 1) {
          row.querySelectorAll('input,select,textarea').forEach(function (input) {
            input.value = '';
          });
          return;
        }

        row.remove();
        renumberRows();
      });

      function loadPreviousDarData() {
        if (!previousDarTemplate) return;

        const body = document.getElementById('activityBody');
        const rows = Array.isArray(previousDarTemplate.rows) && previousDarTemplate.rows.length
          ? previousDarTemplate.rows
          : [{}];

        body.innerHTML = '';
        rows.forEach(function (rowData) {
          body.appendChild(makeActivityRow(rowData));
        });

        /*
         * Load reusable content only.
         * Do NOT overwrite unique values:
         * - DAR No
         * - DAR Date
         * - Project ID
         * - Resubmit submission ID
         * - Prepared By
         */
        const division = document.querySelector('[name="division"]');
        const incharge = document.querySelector('[name="incharge"]');
        const distribute = document.querySelector('[name="report_distribute_to"]');

        if (division && previousDarTemplate.division) {
          division.value = previousDarTemplate.division;
        }

        if (incharge && previousDarTemplate.incharge) {
          incharge.value = previousDarTemplate.incharge;
        }

        if (distribute && previousDarTemplate.report_distribute_to) {
          distribute.value = previousDarTemplate.report_distribute_to;
        }

        renumberRows();
      }

      document.getElementById('loadPreviousDarData')?.addEventListener('change', function () {
        if (this.checked) {
          loadPreviousDarData();
        }
      });

      // Backward compatibility if an older checkbox id still exists in cache/custom copy.
      document.getElementById('copyRecentDar')?.addEventListener('change', function () {
        if (this.checked) {
          loadPreviousDarData();
        }
      });

      renumberRows();
    });
  </script>

</body>

</html>