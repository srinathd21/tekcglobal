<?php
// ma.php — Meeting Agenda (MA) submit
// Current ERP version based on uploaded MA file.
// Keeps the same form inputs/labels and keeps PMC wording where the uploaded file uses PMC.

session_start();
require_once __DIR__ . "/includes/db.php";
date_default_timezone_set("Asia/Kolkata");

if (empty($_SESSION["user_id"]) && empty($_SESSION["employee_id"])) {
  header("Location: login.php");
  exit;
}

function e($v){ return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8"); }

function emp_id($conn){
  if (!empty($_SESSION["employee_id"])) return (int)$_SESSION["employee_id"];
  if (!empty($_SESSION["user_id"])) {
    $uid = (int)$_SESSION["user_id"];
    $q = mysqli_query($conn, "SELECT employee_id FROM users WHERE id=$uid LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r["employee_id"])) {
      $_SESSION["employee_id"] = (int)$r["employee_id"];
      return (int)$r["employee_id"];
    }
  }
  return 0;
}

function is_super_admin($conn){
  $uid = (int)($_SESSION["user_id"] ?? 0);
  if ($uid <= 0) return false;
  $q = mysqli_query($conn, "
    SELECT r.id
    FROM user_roles ur
    INNER JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = $uid
      AND (r.id = 1 OR r.role_slug = 'super-admin' OR LOWER(r.role_name) = 'super admin')
    LIMIT 1
  ");
  return $q && mysqli_num_rows($q) > 0;
}

function report_access($conn, $code, $col){
  if (is_super_admin($conn)) return true;
  $allowed = ["can_submit","can_view","can_remark_tl","can_remark_manager"];
  if (!in_array($col, $allowed, true)) return false;

  $uid = (int)($_SESSION["user_id"] ?? 0);
  if ($uid <= 0) return false;

  $code = mysqli_real_escape_string($conn, $code);
  $q = mysqli_query($conn, "
    SELECT MAX(COALESCE(rta.$col,0)) ok
    FROM user_roles ur
    INNER JOIN report_type_role_access rta ON rta.role_id = ur.role_id
    INNER JOIN master_report_types rt ON rt.id = rta.report_type_id
    WHERE ur.user_id = $uid
      AND rt.report_code = '$code'
      AND rt.is_active = 1
  ");
  return $q && ($r = mysqli_fetch_assoc($q)) && (int)($r["ok"] ?? 0) === 1;
}

function ymdOrNull($v){
  $v = trim((string)$v);
  if ($v === "" || $v === "0000-00-00") return null;
  return $v;
}

function clean_rows($rows){
  $out = [];
  foreach ($rows as $row) {
    $has = false;
    foreach ($row as $v) {
      if (trim((string)$v) !== "") { $has = true; break; }
    }
    if ($has) $out[] = $row;
  }
  return $out;
}

function project_allowed($conn, $projectId, $employeeId, $super){
  $projectId = (int)$projectId;
  $employeeId = (int)$employeeId;
  if ($projectId <= 0) return false;

  if ($super) {
    $q = mysqli_query($conn, "SELECT id FROM projects WHERE id=$projectId AND deleted_at IS NULL LIMIT 1");
    return $q && mysqli_num_rows($q) > 0;
  }

  $q = mysqli_query($conn, "
    SELECT p.id
    FROM projects p
    WHERE p.id = $projectId
      AND p.deleted_at IS NULL
      AND (
            p.manager_employee_id = $employeeId
         OR p.team_lead_employee_id = $employeeId
         OR EXISTS (
              SELECT 1
              FROM project_assignments pa
              WHERE pa.project_id = p.id
                AND pa.employee_id = $employeeId
                AND pa.status = 'active'
         )
      )
    LIMIT 1
  ");
  return $q && mysqli_num_rows($q) > 0;
}

function ma_table_columns($conn, $tableName){
  $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
  $cols = [];
  $q = mysqli_query($conn, "SHOW COLUMNS FROM `$tableName`");
  while ($q && ($row = mysqli_fetch_assoc($q))) $cols[$row["Field"]] = true;
  return $cols;
}

function ma_sql_value($conn, $value){
  if ($value === null) return "NULL";
  if (is_int($value) || is_float($value)) return (string)$value;
  return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function ma_report_type_id($conn){
  $q = mysqli_query($conn, "SELECT id FROM master_report_types WHERE report_code='MA' LIMIT 1");
  return ($q && ($row = mysqli_fetch_assoc($q))) ? (int)$row["id"] : 0;
}

function mark_ma_submitted($conn, $projectId, $employeeId, $maDate, $maId, $maNo){
  $projectId = (int)$projectId;
  $employeeId = (int)$employeeId;
  $maId = (int)$maId;
  $rt = ma_report_type_id($conn);
  $uid = (int)($_SESSION["user_id"] ?? 0);

  if ($projectId <= 0 || $employeeId <= 0 || $maId <= 0 || $rt <= 0 || $maDate === "") return;

  $cols = ma_table_columns($conn, "project_report_submissions");
  $dateEsc = mysqli_real_escape_string($conn, $maDate);
  $safeNo = trim((string)$maNo);
  if ($safeNo === "") $safeNo = "MA-" . $projectId . "-" . str_replace("-", "", $maDate) . "-" . $maId;

  $conditions = [];
  if (isset($cols["report_reference_table"]) && isset($cols["report_reference_id"])) $conditions[] = "(report_reference_table='ma_reports' AND report_reference_id=$maId)";
  if (isset($cols["source_table"]) && isset($cols["source_id"])) $conditions[] = "(source_table='ma_reports' AND source_id=$maId)";
  if (isset($cols["reference_id"])) $conditions[] = "reference_id=$maId";
  if (isset($cols["submission_for_date"])) $conditions[] = "submission_for_date='$dateEsc'";

  $targetId = 0;
  if ($conditions) {
    $q = mysqli_query($conn, "
      SELECT id
      FROM project_report_submissions
      WHERE project_id=$projectId
        AND report_type_id=$rt
        AND (" . implode(" OR ", $conditions) . ")
      ORDER BY id DESC
      LIMIT 1
    ");
    if ($q && ($row = mysqli_fetch_assoc($q))) $targetId = (int)$row["id"];
  }

  if ($targetId > 0) {
    $sets = [];
    foreach (["report_no","report_number","submission_no"] as $col) {
      if (isset($cols[$col])) $sets[] = "`$col`='" . mysqli_real_escape_string($conn, $safeNo) . "'";
    }
    $map = [
      "title" => "'" . mysqli_real_escape_string($conn, "MA " . $safeNo) . "'",
      "status" => "'submitted'",
      "submitted_at" => "NOW()",
      "submitted_by_employee_id" => $employeeId,
      "submitted_by_user_id" => $uid ?: "NULL",
      "submission_for_date" => "'$dateEsc'",
      "period_start" => "'$dateEsc'",
      "period_end" => "'$dateEsc'",
      "reference_id" => $maId,
      "source_table" => "'ma_reports'",
      "source_id" => $maId,
      "report_reference_table" => "'ma_reports'",
      "report_reference_id" => $maId,
      "updated_by" => $uid ?: "NULL",
      "updated_at" => "NOW()"
    ];
    foreach ($map as $col => $val) if (isset($cols[$col])) $sets[] = "`$col`=$val";
    if ($sets) mysqli_query($conn, "UPDATE project_report_submissions SET " . implode(",", $sets) . " WHERE id=$targetId");
    return;
  }

  $data = [
    "project_id" => $projectId,
    "report_type_id" => $rt,
    "report_no" => $safeNo,
    "report_number" => $safeNo,
    "submission_no" => $safeNo,
    "submitted_by_employee_id" => $employeeId,
    "submitted_by_user_id" => $uid > 0 ? $uid : null,
    "submission_for_date" => $maDate,
    "period_start" => $maDate,
    "period_end" => $maDate,
    "title" => "MA " . $safeNo,
    "status" => "submitted",
    "submitted_at" => date("Y-m-d H:i:s"),
    "reference_id" => $maId,
    "source_table" => "ma_reports",
    "source_id" => $maId,
    "report_reference_table" => "ma_reports",
    "report_reference_id" => $maId,
    "created_by" => $uid > 0 ? $uid : null,
    "updated_by" => $uid > 0 ? $uid : null,
    "created_at" => date("Y-m-d H:i:s"),
    "updated_at" => date("Y-m-d H:i:s"),
  ];

  $insertCols = [];
  $insertVals = [];
  foreach ($data as $field => $value) {
    if (isset($cols[$field])) {
      $insertCols[] = "`$field`";
      $insertVals[] = ma_sql_value($conn, $value);
    }
  }

  if ($insertCols) {
    $updates = [];
    foreach ($insertCols as $colName) {
      $clean = trim($colName, "`");
      if (!in_array($clean, ["id","created_at","created_by"], true)) $updates[] = "`$clean`=VALUES(`$clean`)";
    }
    $sql = "INSERT INTO project_report_submissions (" . implode(",", $insertCols) . ") VALUES (" . implode(",", $insertVals) . ")";
    if ($updates) $sql .= " ON DUPLICATE KEY UPDATE " . implode(",", $updates);
    mysqli_query($conn, $sql);
  }
}

function log_activity($conn, $type, $desc, $ref = null){
  $eid = emp_id($conn);
  $name = mysqli_real_escape_string($conn, $_SESSION["employee_name"] ?? $_SESSION["name"] ?? "");
  $user = mysqli_real_escape_string($conn, $_SESSION["username"] ?? "");
  $des = mysqli_real_escape_string($conn, $_SESSION["designation"] ?? "");
  $dep = mysqli_real_escape_string($conn, $_SESSION["department"] ?? "");
  $type = mysqli_real_escape_string($conn, strtoupper($type));
  $desc = mysqli_real_escape_string($conn, $desc);
  $refSql = $ref ? (int)$ref : "NULL";
  $ip = mysqli_real_escape_string($conn, $_SERVER["REMOTE_ADDR"] ?? "");
  mysqli_query($conn, "
    INSERT INTO activity_logs
    (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
    VALUES ($eid,'$name','$user','$des','$dep','$type','MA','$desc',$refSql,'$ip')
  ");
}

function redirect_reports_hub($projectId, $date, $flag = "saved"){
  $projectId = (int)$projectId;
  $d = urlencode((string)$date);
  $flag = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$flag);
  header("Location: reports-hub.php?project_id=$projectId&report_date=$d&period_start=$d&period_end=$d&$flag=1");
  exit;
}

$employeeId = emp_id($conn);
$super = is_super_admin($conn);

if ($employeeId <= 0) {
  header("Location: login.php");
  exit;
}

if (!report_access($conn, "MA", "can_submit") && !report_access($conn, "MA", "can_view")) {
  header("Location: reports-hub.php?error=" . urlencode("You do not have MA report access."));
  exit;
}

$empRow = null;
$st = mysqli_prepare($conn, "
  SELECT e.id, e.full_name, r.role_name AS designation
  FROM employees e
  LEFT JOIN roles r ON r.id = e.role_id
  WHERE e.id=? LIMIT 1
");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $empRow = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}
$preparedBy = $empRow["full_name"] ?? ($_SESSION["employee_name"] ?? $_SESSION["name"] ?? "");

$projectId = (int)($_GET["project_id"] ?? $_POST["project_id"] ?? 0);
$requestedReportDate = trim($_GET["report_date"] ?? $_POST["ma_date"] ?? date("Y-m-d"));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedReportDate)) $requestedReportDate = date("Y-m-d");

$projectWhere = "p.deleted_at IS NULL";
if (!$super) {
  $projectWhere .= "
    AND (
          p.manager_employee_id = $employeeId
       OR p.team_lead_employee_id = $employeeId
       OR EXISTS (
            SELECT 1 FROM project_assignments pa
            WHERE pa.project_id = p.id AND pa.employee_id = $employeeId AND pa.status = 'active'
       )
    )
  ";
}

$projects = [];
$pq = mysqli_query($conn, "
  SELECT p.id, p.project_name, p.project_location, c.client_name
  FROM projects p
  LEFT JOIN clients c ON c.id = p.client_id
  WHERE $projectWhere
  ORDER BY p.created_at DESC, p.project_name ASC
");
while ($pq && ($p = mysqli_fetch_assoc($pq))) $projects[] = $p;

$project = null;
if ($projectId > 0 && project_allowed($conn, $projectId, $employeeId, $super)) {
  $q = mysqli_query($conn, "
    SELECT p.*, c.client_name, c.email AS client_email, c.mobile_number AS client_mobile,
           mpt.project_type_name, cd.division_name AS project_division_name
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN master_project_types mpt ON mpt.id = p.project_type_id
    LEFT JOIN company_divisions cd ON cd.id = p.division_id
    WHERE p.id=$projectId
    LIMIT 1
  ");
  if ($q) $project = mysqli_fetch_assoc($q);
}

$companyName = "M/s. UKB Construction Management Pvt Ltd";
$cq = mysqli_query($conn, "SELECT company_name FROM company_details WHERE id=1 LIMIT 1");
if ($cq && ($cr = mysqli_fetch_assoc($cq)) && !empty($cr["company_name"])) $companyName = $cr["company_name"];

// Keep uploaded file label/value as PMC where the report asks for PMC.
$pmcName = "M/s. UKB Construction Management Pvt Ltd";
if (!empty($companyName)) $pmcName = $companyName;

$todayYmd = date("Y-m-d");
$defaultMaNo = "";
if ($projectId > 0) {
  $seq = 1;
  $q = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM ma_reports WHERE project_id=$projectId");
  if ($q && ($r = mysqli_fetch_assoc($q))) $seq = ((int)($r["cnt"] ?? 0)) + 1;
  $defaultMaNo = "#" . str_pad((string)$seq, 2, "0", STR_PAD_LEFT);
}

$formMaDate = $requestedReportDate;
$formMaNo = $defaultMaNo;
$success = "";
$error = "";

$pageMessageType = "";
$pageMessageText = "";
if (isset($_GET["saved"])) {
  $pageMessageType = "success";
  $pageMessageText = "Meeting Agenda submitted successfully.";
}
if (isset($_GET["error"])) {
  $pageMessageType = "error";
  $pageMessageText = trim($_GET["error"]) ?: "Something went wrong.";
}

$previousMaTemplate = null;
if ($projectId > 0) {
  $safeDate = mysqli_real_escape_string($conn, $requestedReportDate);

  $tplQ = mysqli_query($conn, "
    SELECT *
    FROM ma_reports
    WHERE project_id = $projectId
      AND employee_id = $employeeId
      AND ma_date < '$safeDate'
    ORDER BY ma_date DESC, created_at DESC, id DESC
    LIMIT 1
  ");
  if ($tplQ) $previousMaTemplate = mysqli_fetch_assoc($tplQ);

  if (!$previousMaTemplate) {
    $tplQ = mysqli_query($conn, "
      SELECT *
      FROM ma_reports
      WHERE project_id = $projectId
        AND employee_id = $employeeId
      ORDER BY ma_date DESC, created_at DESC, id DESC
      LIMIT 1
    ");
    if ($tplQ) $previousMaTemplate = mysqli_fetch_assoc($tplQ);
  }

  if (!$previousMaTemplate) {
    $tplQ = mysqli_query($conn, "
      SELECT *
      FROM ma_reports
      WHERE project_id = $projectId
      ORDER BY ma_date DESC, created_at DESC, id DESC
      LIMIT 1
    ");
    if ($tplQ) $previousMaTemplate = mysqli_fetch_assoc($tplQ);
  }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["submit_ma"])) {
  $postProject = (int)($_POST["project_id"] ?? 0);

  if ($postProject <= 0 || !project_allowed($conn, $postProject, $employeeId, $super)) $error = "Invalid project selection.";

  $ma_no = trim((string)($_POST["ma_no"] ?? ""));
  $ma_date = trim((string)($_POST["ma_date"] ?? ""));

  $facilitator = trim((string)($_POST["facilitator"] ?? ""));
  $meeting_date_place = trim((string)($_POST["meeting_date_place"] ?? ""));
  $meeting_taken_by = trim((string)($_POST["meeting_taken_by"] ?? ""));
  $meeting_number = trim((string)($_POST["meeting_number"] ?? ""));
  $meeting_start_time = trim((string)($_POST["meeting_start_time"] ?? ""));
  $meeting_end_time = trim((string)($_POST["meeting_end_time"] ?? ""));

  $obj = $_POST["objective"] ?? [];
  $objRows = [];
  for ($i=0; $i<max(3, count($obj)); $i++) $objRows[] = ["text" => $obj[$i] ?? ""];
  $objRows = clean_rows($objRows);

  $att_name = $_POST["att_name"] ?? [];
  $att_firm = $_POST["att_firm"] ?? [];
  $attRows = [];
  $max = max(10, count($att_name), count($att_firm));
  for ($i=0; $i<$max; $i++) $attRows[] = ["name" => $att_name[$i] ?? "", "firm" => $att_firm[$i] ?? ""];
  $attRows = clean_rows($attRows);

  $disc_topic = $_POST["disc_topic"] ?? [];
  $discRows = [];
  $max = max(6, count($disc_topic));
  for ($i=0; $i<$max; $i++) $discRows[] = ["topic" => $disc_topic[$i] ?? ""];
  $discRows = clean_rows($discRows);

  $ac_desc = $_POST["ac_desc"] ?? [];
  $ac_person = $_POST["ac_person"] ?? [];
  $ac_due = $_POST["ac_due"] ?? [];
  $acRows = [];
  $max = max(count($ac_desc), count($ac_person), count($ac_due));
  for ($i=0; $i<$max; $i++) {
    $acRows[] = ["description" => $ac_desc[$i] ?? "", "person" => $ac_person[$i] ?? "", "due" => $ac_due[$i] ?? ""];
  }
  $acRows = clean_rows($acRows);

  $next_meeting_date = trim((string)($_POST["next_meeting_date"] ?? ""));
  $next_meeting_start_time = trim((string)($_POST["next_meeting_start_time"] ?? ""));
  $next_meeting_end_time = trim((string)($_POST["next_meeting_end_time"] ?? ""));

  if ($error === "" && $ma_no === "") $error = "MA No is required.";
  if ($error === "" && $ma_date === "") $error = "MA Date is required.";
  if ($error === "" && empty($objRows)) $error = "Please enter at least one Meeting Objective.";

  if ($error === "") {
    $d = ymdOrNull($ma_date);
    $nmd = ymdOrNull($next_meeting_date);

    $objJson = $objRows ? json_encode($objRows, JSON_UNESCAPED_UNICODE) : null;
    $attJson = $attRows ? json_encode($attRows, JSON_UNESCAPED_UNICODE) : null;
    $discJson = $discRows ? json_encode($discRows, JSON_UNESCAPED_UNICODE) : null;
    $actJson = $acRows ? json_encode($acRows, JSON_UNESCAPED_UNICODE) : null;

    $ins = mysqli_prepare($conn, "
      INSERT INTO ma_reports
      (project_id, site_id, employee_id, ma_no, ma_date,
       facilitator, meeting_date_place, meeting_taken_by,
       meeting_number, meeting_start_time, meeting_end_time,
       objectives_json, attendees_json, discussions_json, actions_json,
       next_meeting_date, next_meeting_start_time, next_meeting_end_time)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    if (!$ins) {
      $error = "DB Error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param(
        $ins,
        "iiisssssssssssssss",
        $postProject, $postProject, $employeeId, $ma_no, $d,
        $facilitator, $meeting_date_place, $meeting_taken_by,
        $meeting_number, $meeting_start_time, $meeting_end_time,
        $objJson, $attJson, $discJson, $actJson,
        $nmd, $next_meeting_start_time, $next_meeting_end_time
      );

      if (!mysqli_stmt_execute($ins)) {
        $error = "Failed to save MA: " . mysqli_stmt_error($ins);
      } else {
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($ins);

        mark_ma_submitted($conn, $postProject, $employeeId, $ma_date, $newId, $ma_no);
        log_activity($conn, "CREATE", "Submitted MA " . $ma_no, $newId);
        redirect_reports_hub($postProject, $ma_date, "saved");
      }
      mysqli_stmt_close($ins);
    }
  }

  if ($error !== "") {
    header("Location: ma.php?project_id=" . $postProject . "&report_date=" . urlencode($ma_date) . "&error=" . urlencode($error));
    exit;
  }
}

$recent = [];
$rq = mysqli_query($conn, "
  SELECT r.id, r.ma_no, r.ma_date, p.project_name
  FROM ma_reports r
  INNER JOIN projects p ON p.id = r.project_id
  WHERE r.employee_id = $employeeId
  ORDER BY r.created_at DESC
  LIMIT 10
");
while ($rq && ($r = mysqli_fetch_assoc($rq))) $recent[] = $r;
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Meeting Agenda (MA) - TEK-C</title>
  <?php include("includes/links.php"); ?>
  <style>
    .page-head-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
    .section-box{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:18px}
    .mini-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
    .mini-icon{width:44px;height:44px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:rgba(255,193,7,.14);color:#f5b400}
    .form-control,.form-select{background:var(--card-bg);color:var(--text-main);border-color:var(--border-soft);min-height:42px;font-size:13px;font-weight:700}
    .ma-table th{font-size:12px;text-transform:uppercase;color:var(--text-muted);font-weight:900;background:rgba(148,163,184,.10);white-space:nowrap}
    .ma-table td{vertical-align:top}
    .badge-soft{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--border-soft);background:rgba(148,163,184,.08);border-radius:999px;padding:7px 12px;font-size:12px;font-weight:900}
    .badge-soft input[type="checkbox"]{width:15px;height:15px;flex:0 0 auto;margin:0}
    .badge-soft span{line-height:1.15}
    .recent-card{border:1px solid var(--border-soft);border-radius:18px;padding:12px;background:rgba(148,163,184,.06)}
    @media(max-width:767px){.ma-table{min-width:900px}.section-box{padding:14px}}
  </style>
</head>
<body>
<div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>
<?php if (file_exists(__DIR__ . "/includes/page-message.php")) include("includes/page-message.php"); ?>

<div class="min-vh-100 d-flex">
  <?php include("includes/sidebar.php"); ?>
  <main id="main">
    <?php include("includes/nav.php"); ?>
    <section class="page-section p-3 p-lg-3">
      <div class="page-head-card mb-3">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
          <div>
            <h1 class="h4 fw-bold mb-1">Meeting Agenda (MA)</h1>
            <p class="text-muted-custom mb-0 small">Prepare and submit agenda before meeting.</p>
          </div>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <label class="badge-soft mb-0 <?= !$previousMaTemplate ? 'opacity-75' : '' ?>" title="<?= $previousMaTemplate ? 'Load previous submitted MA data without changing MA No, Date or Project' : 'No previous MA data found for this project' ?>">
              <input type="checkbox" class="form-check-input mt-0" id="loadPreviousMaData" <?= !$previousMaTemplate ? 'disabled' : '' ?>>
              <span>
                <strong>Load previous data</strong>
                <small class="d-block text-muted-custom fw-semibold">
                  <?php if ($previousMaTemplate): ?>
                    <?= e($previousMaTemplate["ma_no"]) ?> · <?= e(date("d M Y", strtotime($previousMaTemplate["ma_date"]))) ?>
                  <?php else: ?>
                    No previous data
                  <?php endif; ?>
                </small>
              </span>
            </label>
            <span class="badge-soft"><i data-lucide="user" style="width:15px;height:15px;"></i><?= e($preparedBy) ?></span>
            <span class="badge-soft"><i data-lucide="award" style="width:15px;height:15px;"></i><?= e($empRow["designation"] ?? ($_SESSION["designation"] ?? "")) ?></span>
            <a href="reports-hub.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Back to Reports Hub</a>
          </div>
        </div>
      </div>

      <div class="section-box mb-3">
        <div class="mini-head">
          <div class="mini-icon"><i data-lucide="map-pin"></i></div>
          <div>
            <h2 class="fw-bold fs-6 mb-1">Project Selection</h2>
            <p class="text-muted-custom small mb-0">Choose project for Meeting Agenda.</p>
          </div>
        </div>
        <div class="row g-3 align-items-end">
          <div class="col-lg-9">
            <label class="form-label fw-bold small">My Assigned Projects <span class="text-danger">*</span></label>
            <select class="form-select rounded-4" id="projectPicker">
              <option value="">-- Select Project --</option>
              <?php foreach ($projects as $p): ?>
                <?php $pid = (int)$p["id"]; ?>
                <option value="<?= $pid ?>" <?= $pid === $projectId ? "selected" : "" ?>>
                  <?= e($p["project_name"]) ?> — <?= e($p["project_location"] ?? "-") ?> (<?= e($p["client_name"] ?? "-") ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-3"><a href="ma.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">Reset</a></div>
        </div>
      </div>

      <form method="POST" autocomplete="off">
        <input type="hidden" name="submit_ma" value="1">
        <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">

        <div class="section-box mb-3">
          <div class="mini-head">
            <div class="mini-icon"><i data-lucide="journal-text"></i></div>
            <div>
              <h2 class="fw-bold fs-6 mb-1">MA Header</h2>
              <p class="text-muted-custom small mb-0">Project / Client / PMC / Date.</p>
            </div>
          </div>

          <?php if (!$project): ?>
            <p class="text-muted-custom fw-bold mb-0">Please select a project above to load details.</p>
          <?php else: ?>
            <div class="row g-3">
              <div class="col-md-4"><label class="form-label fw-bold small">Project</label><input class="form-control rounded-4" value="<?= e($project["project_name"]) ?>" readonly></div>
              <div class="col-md-4"><label class="form-label fw-bold small">Client</label><input class="form-control rounded-4" value="<?= e($project["client_name"]) ?>" readonly></div>
              <div class="col-md-4"><label class="form-label fw-bold small">PMC</label><input class="form-control rounded-4" value="<?= e($pmcName) ?>" readonly></div>
              <div class="col-md-4"><label class="form-label fw-bold small">MA No <span class="text-danger">*</span></label><input class="form-control rounded-4" name="ma_no" value="<?= e($formMaNo) ?>" required></div>
              <div class="col-md-4"><label class="form-label fw-bold small">Date <span class="text-danger">*</span></label><input type="date" class="form-control rounded-4" name="ma_date" value="<?= e($formMaDate) ?>" required></div>
              <div class="col-md-4"><label class="form-label fw-bold small">Prepared By</label><input class="form-control rounded-4" value="<?= e($preparedBy) ?>" readonly></div>
            </div>
          <?php endif; ?>
        </div>

        <div class="section-box mb-3">
          <div class="mini-head">
            <div class="mini-icon"><i data-lucide="calendar-days"></i></div>
            <div><h2 class="fw-bold fs-6 mb-1">I. Meeting Schedule</h2><p class="text-muted-custom small mb-0">Schedule details.</p></div>
          </div>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-bold small">Facilitator</label><input class="form-control rounded-4" name="facilitator" placeholder="e.g. PMC Lead / Manager"></div>
            <div class="col-md-6"><label class="form-label fw-bold small">Meeting Number</label><input class="form-control rounded-4" name="meeting_number" placeholder="e.g. MA-01"></div>
            <div class="col-md-6"><label class="form-label fw-bold small">Meeting Date/Place</label><input class="form-control rounded-4" name="meeting_date_place" placeholder="e.g. 2026-02-13 / Site Office"></div>
            <div class="col-md-6"><label class="form-label fw-bold small">Meeting Taken By</label><input class="form-control rounded-4" name="meeting_taken_by" value="<?= e($preparedBy) ?>" placeholder="Name"></div>
            <div class="col-md-6"><label class="form-label fw-bold small">Meeting Start Time</label><input class="form-control rounded-4" name="meeting_start_time" placeholder="e.g. 11:00 AM"></div>
            <div class="col-md-6"><label class="form-label fw-bold small">Meeting End Time</label><input class="form-control rounded-4" name="meeting_end_time" placeholder="e.g. 12:00 PM"></div>
          </div>
        </div>

        <div class="section-box mb-3">
          <div class="mini-head">
            <div class="mini-icon"><i data-lucide="target"></i></div>
            <div><h2 class="fw-bold fs-6 mb-1">II. Meeting Objectives</h2><p class="text-muted-custom small mb-0">Enter up to 3 objectives.</p></div>
          </div>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-bold small">1</label><input class="form-control rounded-4" name="objective[]" placeholder="Objective 1"></div>
            <div class="col-md-6"><label class="form-label fw-bold small">2</label><input class="form-control rounded-4" name="objective[]" placeholder="Objective 2"></div>
            <div class="col-12"><label class="form-label fw-bold small">3</label><input class="form-control rounded-4" name="objective[]" placeholder="Objective 3"></div>
          </div>
        </div>

        <div class="section-box mb-3">
          <div class="mini-head">
            <div class="mini-icon"><i data-lucide="users"></i></div>
            <div><h2 class="fw-bold fs-6 mb-1">III. Requested Attendees</h2><p class="text-muted-custom small mb-0">Add up to 10 attendees.</p></div>
          </div>
          <div class="table-responsive thin-scrollbar">
            <table class="table table-bordered align-middle mb-0 ma-table">
              <thead><tr><th style="width:80px;">Sl.No.</th><th>Name</th><th>Firm</th></tr></thead>
              <tbody>
                <?php for ($i=1; $i<=10; $i++): ?>
                  <tr><td class="fw-bold"><?= $i ?></td><td><input class="form-control rounded-4" name="att_name[]" placeholder="Name"></td><td><input class="form-control rounded-4" name="att_firm[]" placeholder="Firm"></td></tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="section-box mb-3">
          <div class="mini-head">
            <div class="mini-icon"><i data-lucide="message-square-text"></i></div>
            <div><h2 class="fw-bold fs-6 mb-1">IV. Discussions/Decisions Items</h2><p class="text-muted-custom small mb-0">Topics to discuss.</p></div>
          </div>
          <div class="table-responsive thin-scrollbar">
            <table class="table table-bordered align-middle mb-0 ma-table">
              <thead><tr><th style="width:80px;">Sl.No.</th><th>Topics</th></tr></thead>
              <tbody>
                <?php for ($i=1; $i<=6; $i++): ?>
                  <tr><td class="fw-bold"><?= $i ?></td><td><input class="form-control rounded-4" name="disc_topic[]" placeholder="Topic"></td></tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="section-box mb-3">
          <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-2 mb-3">
            <div class="mini-head mb-0">
              <div class="mini-icon"><i data-lucide="list-todo"></i></div>
              <div><h2 class="fw-bold fs-6 mb-1">V. Action Assignments</h2><p class="text-muted-custom small mb-0">Add action items.</p></div>
            </div>
            <button type="button" class="btn btn-outline-primary rounded-4 fw-bold" id="addAction">Add Row</button>
          </div>
          <div class="table-responsive thin-scrollbar">
            <table class="table table-bordered align-middle mb-0 ma-table">
              <thead><tr><th style="width:80px;">Sl.No.</th><th>Descriptions</th><th style="width:220px;">Person Responsible</th><th style="width:160px;">Due Date</th><th style="width:70px;">Del</th></tr></thead>
              <tbody id="actionBody">
                <tr>
                  <td class="slno fw-bold">1</td>
                  <td><input class="form-control rounded-4" name="ac_desc[]" placeholder="Action description"></td>
                  <td><input class="form-control rounded-4" name="ac_person[]" placeholder="Responsible person"></td>
                  <td><input type="date" class="form-control rounded-4" name="ac_due[]"></td>
                  <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger rounded-4 delRow"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="text-muted-custom small fw-bold mt-2">Use “Add Row” to add multiple action items.</div>
        </div>

        <div class="section-box mb-3">
          <div class="mini-head">
            <div class="mini-icon"><i data-lucide="repeat"></i></div>
            <div><h2 class="fw-bold fs-6 mb-1">VI. Next Meeting</h2><p class="text-muted-custom small mb-0">Next schedule.</p></div>
          </div>
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label fw-bold small">Meeting Date</label><input type="date" class="form-control rounded-4" name="next_meeting_date"></div>
            <div class="col-md-4"><label class="form-label fw-bold small">Meeting Start Time</label><input class="form-control rounded-4" name="next_meeting_start_time" placeholder="e.g. 11:00 AM"></div>
            <div class="col-md-4"><label class="form-label fw-bold small">Meeting End Time</label><input class="form-control rounded-4" name="next_meeting_end_time" placeholder="e.g. 12:00 PM"></div>
          </div>
          <div class="d-flex justify-content-end mt-3">
            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4" <?= $projectId<=0 ? "disabled" : "" ?>>Submit MA</button>
          </div>
          <?php if ($projectId<=0): ?><div class="text-muted-custom small fw-bold mt-2">Select a project above to enable submit.</div><?php endif; ?>
        </div>
      </form>

      <section class="card-ui overflow-hidden">
        <div class="p-3 p-lg-4"><h2 class="fw-bold fs-6 mb-1">Recent MA</h2><p class="text-muted-custom small mb-0">Your last submissions.</p></div>
        <div class="px-3 px-lg-4 pb-4">
          <?php if (!$recent): ?>
            <p class="text-muted-custom fw-bold mb-0">No MA submitted yet.</p>
          <?php else: ?>
            <div class="row g-2">
              <?php foreach ($recent as $r): ?>
                <div class="col-md-6 col-xl-4">
                  <div class="recent-card">
                    <div class="d-flex justify-content-between gap-2">
                      <div><div class="fw-bold"><?= e($r["ma_no"]) ?></div><small class="text-muted-custom fw-bold"><?= e($r["project_name"]) ?></small></div>
                      <span class="pill green"><?= e(date("d M Y", strtotime($r["ma_date"]))) ?></span>
                    </div>
                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2" target="_blank" href="reports-print/report-ma-print.php?view=<?= (int)$r["id"] ?>">Print</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <?php include("includes/footer.php"); ?>
    </section>
  </main>
  <div id="settingsOverlay"></div>
  <?php if (file_exists(__DIR__ . "/includes/rightsidbar.php")) include("includes/rightsidbar.php"); ?>
</div>

<?php include("includes/script.php"); ?>
<script src="assets/js/script.js?v=41"></script>
<script>
const previousMaTemplate = <?php
$tpl = null;
if ($previousMaTemplate) {
  $tpl = [
    "facilitator" => $previousMaTemplate["facilitator"] ?? "",
    "meeting_date_place" => $previousMaTemplate["meeting_date_place"] ?? "",
    "meeting_taken_by" => $previousMaTemplate["meeting_taken_by"] ?? "",
    "meeting_number" => $previousMaTemplate["meeting_number"] ?? "",
    "meeting_start_time" => $previousMaTemplate["meeting_start_time"] ?? "",
    "meeting_end_time" => $previousMaTemplate["meeting_end_time"] ?? "",
    "objectives" => json_decode((string)($previousMaTemplate["objectives_json"] ?? ""), true) ?: [],
    "attendees" => json_decode((string)($previousMaTemplate["attendees_json"] ?? ""), true) ?: [],
    "discussions" => json_decode((string)($previousMaTemplate["discussions_json"] ?? ""), true) ?: [],
    "actions" => json_decode((string)($previousMaTemplate["actions_json"] ?? ""), true) ?: [],
    "next_meeting_date" => $previousMaTemplate["next_meeting_date"] ?? "",
    "next_meeting_start_time" => $previousMaTemplate["next_meeting_start_time"] ?? "",
    "next_meeting_end_time" => $previousMaTemplate["next_meeting_end_time"] ?? ""
  ];
}
echo json_encode($tpl, JSON_UNESCAPED_UNICODE);
?>;

document.addEventListener("DOMContentLoaded", function(){
  const picker = document.getElementById("projectPicker");
  if (picker) {
    picker.addEventListener("change", function(){
      const v = picker.value || "";
      window.location.href = v ? ("ma.php?project_id=" + encodeURIComponent(v) + "&report_date=<?= e($requestedReportDate) ?>") : "ma.php";
    });
  }

  function icons(){ if(window.lucide && window.lucide.createIcons) window.lucide.createIcons(); }

  const body = document.getElementById("actionBody");

  function renumber(){
    if (!body) return;
    let n = 1;
    body.querySelectorAll("tr").forEach(function(tr){
      const td = tr.querySelector(".slno");
      if (td) td.textContent = String(n++);
    });
    icons();
  }

  function makeActionRow(rowData = {}){
    const tr = document.createElement("tr");
    tr.innerHTML =
      '<td class="slno fw-bold"></td>' +
      '<td><input class="form-control rounded-4" name="ac_desc[]" placeholder="Action description"></td>' +
      '<td><input class="form-control rounded-4" name="ac_person[]" placeholder="Responsible person"></td>' +
      '<td><input type="date" class="form-control rounded-4" name="ac_due[]"></td>' +
      '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger rounded-4 delRow"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>';
    tr.querySelector('[name="ac_desc[]"]').value = rowData.description || "";
    tr.querySelector('[name="ac_person[]"]').value = rowData.person || rowData.person_responsible || rowData.responsible || "";
    tr.querySelector('[name="ac_due[]"]').value = rowData.due || rowData.due_date || "";
    return tr;
  }

  document.getElementById("addAction")?.addEventListener("click", function(){
    if (!body) return;
    body.appendChild(makeActionRow());
    renumber();
  });

  document.addEventListener("click", function(ev){
    const btn = ev.target.closest(".delRow");
    if (!btn) return;
    const tr = btn.closest("tr");
    if (!tr) return;
    if (body && body.querySelectorAll("tr").length <= 1) {
      tr.querySelectorAll("input").forEach(function(i){ i.value = ""; });
      return;
    }
    tr.remove();
    renumber();
  });

  function setValue(name, value){
    const el = document.querySelector('[name="' + name + '"]');
    if (el && value !== undefined && value !== null && String(value).trim() !== "") el.value = value;
  }

  function setListInputs(name, values, key){
    const els = document.querySelectorAll('[name="' + name + '"]');
    values.forEach(function(row, index){
      if (els[index]) els[index].value = row[key] || "";
    });
  }

  function loadPreviousMaData(){
    if (!previousMaTemplate) return;

    setValue("facilitator", previousMaTemplate.facilitator);
    setValue("meeting_date_place", previousMaTemplate.meeting_date_place);
    setValue("meeting_taken_by", previousMaTemplate.meeting_taken_by);
    setValue("meeting_number", previousMaTemplate.meeting_number);
    setValue("meeting_start_time", previousMaTemplate.meeting_start_time);
    setValue("meeting_end_time", previousMaTemplate.meeting_end_time);
    setValue("next_meeting_date", previousMaTemplate.next_meeting_date);
    setValue("next_meeting_start_time", previousMaTemplate.next_meeting_start_time);
    setValue("next_meeting_end_time", previousMaTemplate.next_meeting_end_time);

    setListInputs("objective[]", previousMaTemplate.objectives || [], "text");
    setListInputs("att_name[]", previousMaTemplate.attendees || [], "name");
    setListInputs("att_firm[]", previousMaTemplate.attendees || [], "firm");
    setListInputs("disc_topic[]", previousMaTemplate.discussions || [], "topic");

    if (body) {
      body.innerHTML = "";
      const actions = Array.isArray(previousMaTemplate.actions) && previousMaTemplate.actions.length ? previousMaTemplate.actions : [{}];
      actions.forEach(function(row){ body.appendChild(makeActionRow(row || {})); });
      renumber();
    }
  }

  document.getElementById("loadPreviousMaData")?.addEventListener("change", function(){
    if (this.checked) loadPreviousMaData();
  });

  renumber();
});
</script>
</body>
</html>
