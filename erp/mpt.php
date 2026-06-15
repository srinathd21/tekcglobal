<?php
// mpt.php — Monthly Planned Tracker (MPT) submit
// Current ERP version based on uploaded MPT file.
// Keeps same inputs/labels/sections and keeps PMC wording where the uploaded file uses PMC.

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

function jsonCleanRows(array $rows): array {
  $out = [];
  foreach ($rows as $r) {
    $has = false;
    foreach ($r as $v) {
      if (trim((string)$v) !== "") { $has = true; break; }
    }
    if ($has) $out[] = $r;
  }
  return $out;
}

function ymdOrNull($v){
  $v = trim((string)$v);
  if ($v === "" || $v === "0000-00-00") return null;
  return $v;
}

function monthName(int $m): string {
  $names = [1=>"January",2=>"February",3=>"March",4=>"April",5=>"May",6=>"June",7=>"July",8=>"August",9=>"September",10=>"October",11=>"November",12=>"December"];
  return $names[$m] ?? "Month";
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

function mpt_table_columns($conn, $tableName){
  $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
  $cols = [];
  $q = mysqli_query($conn, "SHOW COLUMNS FROM `$tableName`");
  while ($q && ($row = mysqli_fetch_assoc($q))) $cols[$row["Field"]] = true;
  return $cols;
}

function mpt_sql_value($conn, $value){
  if ($value === null) return "NULL";
  if (is_int($value) || is_float($value)) return (string)$value;
  return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function mpt_report_type_id($conn){
  $q = mysqli_query($conn, "SELECT id FROM master_report_types WHERE report_code='MPT' LIMIT 1");
  return ($q && ($row = mysqli_fetch_assoc($q))) ? (int)$row["id"] : 0;
}

function mark_mpt_submitted($conn, $projectId, $employeeId, $mptDate, $mptId, $mptNo){
  $projectId = (int)$projectId;
  $employeeId = (int)$employeeId;
  $mptId = (int)$mptId;
  $rt = mpt_report_type_id($conn);
  $uid = (int)($_SESSION["user_id"] ?? 0);

  if ($projectId <= 0 || $employeeId <= 0 || $mptId <= 0 || $rt <= 0 || $mptDate === "") return;

  $cols = mpt_table_columns($conn, "project_report_submissions");
  $dateEsc = mysqli_real_escape_string($conn, $mptDate);
  $safeNo = trim((string)$mptNo);
  if ($safeNo === "") $safeNo = "MPT-" . $projectId . "-" . str_replace("-", "", $mptDate) . "-" . $mptId;

  $conditions = [];
  if (isset($cols["report_reference_table"]) && isset($cols["report_reference_id"])) $conditions[] = "(report_reference_table='mpt_reports' AND report_reference_id=$mptId)";
  if (isset($cols["source_table"]) && isset($cols["source_id"])) $conditions[] = "(source_table='mpt_reports' AND source_id=$mptId)";
  if (isset($cols["reference_id"])) $conditions[] = "reference_id=$mptId";
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
      "title" => "'" . mysqli_real_escape_string($conn, "MPT " . $safeNo) . "'",
      "status" => "'submitted'",
      "submitted_at" => "NOW()",
      "submitted_by_employee_id" => $employeeId,
      "submitted_by_user_id" => $uid ?: "NULL",
      "submission_for_date" => "'$dateEsc'",
      "period_start" => "'$dateEsc'",
      "period_end" => "'$dateEsc'",
      "reference_id" => $mptId,
      "source_table" => "'mpt_reports'",
      "source_id" => $mptId,
      "report_reference_table" => "'mpt_reports'",
      "report_reference_id" => $mptId,
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
    "submission_for_date" => $mptDate,
    "period_start" => $mptDate,
    "period_end" => $mptDate,
    "title" => "MPT " . $safeNo,
    "status" => "submitted",
    "submitted_at" => date("Y-m-d H:i:s"),
    "reference_id" => $mptId,
    "source_table" => "mpt_reports",
    "source_id" => $mptId,
    "report_reference_table" => "mpt_reports",
    "report_reference_id" => $mptId,
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
      $insertVals[] = mpt_sql_value($conn, $value);
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
    VALUES ($eid,'$name','$user','$des','$dep','$type','MPT','$desc',$refSql,'$ip')
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

if (!report_access($conn, "MPT", "can_submit") && !report_access($conn, "MPT", "can_view")) {
  header("Location: reports-hub.php?error=" . urlencode("You do not have MPT report access."));
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
$nowMonth = (int)date("n");
$nowYear = (int)date("Y");

$formMonth = isset($_GET["m"]) ? (int)$_GET["m"] : (int)($_POST["mpt_month"] ?? $nowMonth);
$formYear = isset($_GET["y"]) ? (int)$_GET["y"] : (int)($_POST["mpt_year"] ?? $nowYear);
if ($formMonth < 1 || $formMonth > 12) $formMonth = $nowMonth;
if ($formYear < 2000 || $formYear > 2100) $formYear = $nowYear;

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
  SELECT p.id, p.project_name, p.project_location, p.expected_completion_date, c.client_name
  FROM projects p
  LEFT JOIN clients c ON c.id = p.client_id
  WHERE $projectWhere
  ORDER BY p.created_at DESC, p.project_name ASC
");
while ($pq && ($p = mysqli_fetch_assoc($pq))) $projects[] = $p;

$project = null;
if ($projectId > 0 && project_allowed($conn, $projectId, $employeeId, $super)) {
  $q = mysqli_query($conn, "
    SELECT p.*, c.client_name, mpt.project_type_name
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN master_project_types mpt ON mpt.id = p.project_type_id
    WHERE p.id=$projectId
    LIMIT 1
  ");
  if ($q) $project = mysqli_fetch_assoc($q);
}

$todayYmd = date("Y-m-d");
$defaultMptNo = "";
if ($projectId > 0) {
  $seq = 1;
  $q = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM mpt_reports WHERE project_id=$projectId");
  if ($q && ($r = mysqli_fetch_assoc($q))) $seq = ((int)($r["cnt"] ?? 0)) + 1;
  $defaultMptNo = "#" . str_pad((string)$seq, 2, "0", STR_PAD_LEFT);
}

$defaultHandOver = ($project && !empty($project["expected_completion_date"]) && $project["expected_completion_date"] !== "0000-00-00")
  ? $project["expected_completion_date"]
  : "";

$formProjectId = $projectId;
$formMptNo = $defaultMptNo;
$formMptDate = date("Y-m-d");

$success = "";
$error = "";
$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["saved"])) {
  $pageMessageType = "success";
  $pageMessageText = "MPT submitted successfully.";
}
if (isset($_GET["error"])) {
  $pageMessageType = "error";
  $pageMessageText = trim($_GET["error"]) ?: "Something went wrong.";
}

$previousMptTemplate = null;
if ($projectId > 0) {
  $tplQ = mysqli_query($conn, "
    SELECT *
    FROM mpt_reports
    WHERE project_id = $projectId
      AND employee_id = $employeeId
      AND (mpt_year < $formYear OR (mpt_year = $formYear AND mpt_month < $formMonth))
    ORDER BY mpt_year DESC, mpt_month DESC, created_at DESC, id DESC
    LIMIT 1
  ");
  if ($tplQ) $previousMptTemplate = mysqli_fetch_assoc($tplQ);

  if (!$previousMptTemplate) {
    $tplQ = mysqli_query($conn, "
      SELECT *
      FROM mpt_reports
      WHERE project_id = $projectId
        AND employee_id = $employeeId
      ORDER BY mpt_year DESC, mpt_month DESC, created_at DESC, id DESC
      LIMIT 1
    ");
    if ($tplQ) $previousMptTemplate = mysqli_fetch_assoc($tplQ);
  }

  if (!$previousMptTemplate) {
    $tplQ = mysqli_query($conn, "
      SELECT *
      FROM mpt_reports
      WHERE project_id = $projectId
      ORDER BY mpt_year DESC, mpt_month DESC, created_at DESC, id DESC
      LIMIT 1
    ");
    if ($tplQ) $previousMptTemplate = mysqli_fetch_assoc($tplQ);
  }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["submit_mpt"])) {
  $postProject = (int)($_POST["project_id"] ?? 0);
  $mpt_no = trim((string)($_POST["mpt_no"] ?? ""));
  $mpt_date = trim((string)($_POST["mpt_date"] ?? ""));
  $mpt_month = (int)($_POST["mpt_month"] ?? 0);
  $mpt_year = (int)($_POST["mpt_year"] ?? 0);
  $handover = trim((string)($_POST["project_handover_date"] ?? ""));

  if ($postProject <= 0 || !project_allowed($conn, $postProject, $employeeId, $super)) $error = "Invalid project selection.";
  if ($error === "" && $mpt_no === "") $error = "MPT No is required.";
  if ($error === "" && $mpt_date === "") $error = "MPT Date is required.";
  if ($error === "" && ($mpt_month < 1 || $mpt_month > 12)) $error = "Invalid month.";
  if ($error === "" && ($mpt_year < 2000 || $mpt_year > 2100)) $error = "Invalid year.";

  $sections = [
    "A" => "DESIGN DELIVERABLE",
    "B" => "VENDOR FINALIZATION",
    "C" => "SITE WORKS",
    "D" => "CLIENT DECISIONS",
  ];

  $items = [];
  foreach ($sections as $code => $title) {
    $task = $_POST[$code . "_task"] ?? [];
    $res = $_POST[$code . "_responsible"] ?? [];
    $date = $_POST[$code . "_plan_date"] ?? [];
    $pln = $_POST[$code . "_pct_planned"] ?? [];
    $act = $_POST[$code . "_pct_actual"] ?? [];
    $sta = $_POST[$code . "_status"] ?? [];
    $rem = $_POST[$code . "_remarks"] ?? [];

    $rows = [];
    $max = max(count($task), count($res), count($date), count($pln), count($act), count($sta), count($rem));
    for ($i=0; $i<$max; $i++) {
      $rows[] = [
        "section" => $code,
        "section_title" => $title,
        "planned_task" => $task[$i] ?? "",
        "responsible_by" => $res[$i] ?? "",
        "planned_completion_date" => $date[$i] ?? "",
        "pct_planned" => $pln[$i] ?? "",
        "pct_actual" => $act[$i] ?? "",
        "status" => $sta[$i] ?? "",
        "remarks" => $rem[$i] ?? "",
      ];
    }
    $rows = jsonCleanRows($rows);
    $items = array_merge($items, $rows);
  }

  if ($error === "" && empty($items)) $error = "Please enter at least one planned task in any section.";

  if ($error === "") {
    $items_json = json_encode($items, JSON_UNESCAPED_UNICODE);
    $handVal = ymdOrNull($handover);

    $ins = mysqli_prepare($conn, "
      INSERT INTO mpt_reports
      (project_id, site_id, employee_id, mpt_no, mpt_date, mpt_month, mpt_year, project_handover_date, items_json, prepared_by)
      VALUES (?,?,?,?,?,?,?,?,?,?)
    ");

    if (!$ins) {
      $error = "DB Error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param(
        $ins,
        "iiissiiiss",
        $postProject, $postProject, $employeeId, $mpt_no, $mpt_date, $mpt_month, $mpt_year, $handVal, $items_json, $preparedBy
      );

      if (!mysqli_stmt_execute($ins)) {
        $error = "Failed to save MPT: " . mysqli_stmt_error($ins);
      } else {
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        mark_mpt_submitted($conn, $postProject, $employeeId, $mpt_date, $newId, $mpt_no);
        log_activity($conn, "CREATE", "Submitted MPT " . $mpt_no, $newId);
        redirect_reports_hub($postProject, $mpt_date, "saved");
      }
      mysqli_stmt_close($ins);
    }
  }

  if ($error !== "") {
    header("Location: mpt.php?project_id=" . $postProject . "&m=" . $mpt_month . "&y=" . $mpt_year . "&error=" . urlencode($error));
    exit;
  }
}

$recent = [];
$rq = mysqli_query($conn, "
  SELECT r.id, r.mpt_no, r.mpt_date, r.mpt_month, r.mpt_year, p.project_name
  FROM mpt_reports r
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
  <title>MPT - TEK-C</title>
  <?php include("includes/links.php"); ?>
  <style>
    .page-head-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
    .section-box{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:18px}
    .mini-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
    .mini-icon{width:44px;height:44px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:rgba(45,156,219,.12);color:#2d9cdb}
    .form-control,.form-select{background:var(--card-bg);color:var(--text-main);border-color:var(--border-soft);min-height:42px;font-size:13px;font-weight:700}
    .mpt-table th{font-size:12px;text-transform:uppercase;color:var(--text-muted);font-weight:900;background:rgba(148,163,184,.10);white-space:nowrap}
    .mpt-table td{vertical-align:top}
    .badge-soft{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--border-soft);background:rgba(148,163,184,.08);border-radius:999px;padding:7px 12px;font-size:12px;font-weight:900}
    .badge-soft input[type="checkbox"]{width:15px;height:15px;flex:0 0 auto;margin:0}
    .badge-soft span{line-height:1.15}
    .recent-card{border:1px solid var(--border-soft);border-radius:18px;padding:12px;background:rgba(148,163,184,.06)}
    @media(max-width:767px){.mpt-table{min-width:1100px}.section-box{padding:14px}}
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
            <h1 class="h4 fw-bold mb-1">Monthly Planned Tracker (MPT)</h1>
            <p class="text-muted-custom mb-0 small">Submit your MPT for the selected project.</p>
          </div>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <label class="badge-soft mb-0 <?= !$previousMptTemplate ? 'opacity-75' : '' ?>" title="<?= $previousMptTemplate ? 'Load previous submitted MPT data without changing MPT No, Date, Project, Month or Year' : 'No previous MPT data found for this project' ?>">
              <input type="checkbox" class="form-check-input mt-0" id="loadPreviousMptData" <?= !$previousMptTemplate ? 'disabled' : '' ?>>
              <span>
                <strong>Load previous data</strong>
                <small class="d-block text-muted-custom fw-semibold">
                  <?php if ($previousMptTemplate): ?>
                    <?= e($previousMptTemplate["mpt_no"]) ?> · <?= e(monthName((int)$previousMptTemplate["mpt_month"])) ?> <?= e($previousMptTemplate["mpt_year"]) ?>
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
            <p class="text-muted-custom small mb-0">Choose the project to prepare MPT.</p>
          </div>
        </div>

        <div class="row g-3 align-items-end">
          <div class="col-lg-4">
            <label class="form-label fw-bold small">My Assigned Projects <span class="text-danger">*</span></label>
            <select class="form-select rounded-4" id="projectPicker">
              <option value="">-- Select Project --</option>
              <?php foreach ($projects as $p): ?>
                <?php $pid = (int)$p["id"]; ?>
                <option value="<?= $pid ?>" <?= $pid === $formProjectId ? "selected" : "" ?>>
                  <?= e($p["project_name"]) ?> — <?= e($p["project_location"] ?? "-") ?> (<?= e($p["client_name"] ?? "-") ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-3">
            <label class="form-label fw-bold small">Month</label>
            <select class="form-select rounded-4" id="monthPicker">
              <?php for ($m=1; $m<=12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $formMonth ? "selected" : "" ?>><?= e(monthName($m)) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-lg-3">
            <label class="form-label fw-bold small">Year</label>
            <select class="form-select rounded-4" id="yearPicker">
              <?php for ($y=(int)date("Y")-2; $y<=(int)date("Y")+3; $y++): ?>
                <option value="<?= $y ?>" <?= $y === $formYear ? "selected" : "" ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-lg-2"><a href="mpt.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">Reset</a></div>
        </div>
      </div>

      <div class="section-box mb-3">
        <div class="mini-head">
          <div class="mini-icon"><i data-lucide="building-2"></i></div>
          <div><h2 class="fw-bold fs-6 mb-1">Project Details</h2><p class="text-muted-custom small mb-0">Auto-filled from selected project.</p></div>
        </div>

        <?php if (!$project): ?>
          <p class="text-muted-custom fw-bold mb-0">Please select a project above to load project details.</p>
        <?php else: ?>
          <div class="row g-3">
            <div class="col-md-4"><small class="text-muted-custom fw-bold">Project</small><div class="fw-bold"><?= e($project["project_name"]) ?></div></div>
            <div class="col-md-4"><small class="text-muted-custom fw-bold">Client</small><div class="fw-bold"><?= e($project["client_name"]) ?></div></div>
            <div class="col-md-4"><small class="text-muted-custom fw-bold">Location</small><div class="fw-bold"><?= e($project["project_location"]) ?></div></div>
            <div class="col-md-4"><small class="text-muted-custom fw-bold">Project Type</small><div class="fw-bold"><?= e($project["project_type_name"] ?? "-") ?></div></div>
            <div class="col-md-4"><small class="text-muted-custom fw-bold">Project Start</small><div class="fw-bold"><?= e($project["start_date"]) ?></div></div>
            <div class="col-md-4"><small class="text-muted-custom fw-bold">Expected Completion</small><div class="fw-bold"><?= e($project["expected_completion_date"]) ?></div></div>
          </div>
          <div class="mt-2"><small class="text-muted-custom fw-bold">Scope of Work</small><div class="fw-bold"><?= e($project["scope_of_work"]) ?></div></div>
        <?php endif; ?>
      </div>

      <form method="POST" autocomplete="off">
        <input type="hidden" name="submit_mpt" value="1">
        <input type="hidden" name="project_id" value="<?= (int)$formProjectId ?>">

        <div class="section-box mb-3">
          <div class="mini-head">
            <div class="mini-icon"><i data-lucide="file-text"></i></div>
            <div>
              <h2 class="fw-bold fs-6 mb-1">MPT Header</h2>
              <p class="text-muted-custom small mb-0">Month: <?= e(monthName($formMonth)) ?> • <?= (int)$formYear ?></p>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-4"><label class="form-label fw-bold small">MPT No <span class="text-danger">*</span></label><input class="form-control rounded-4" name="mpt_no" value="<?= e($formMptNo) ?>" required></div>
            <div class="col-md-4"><label class="form-label fw-bold small">MPT Date <span class="text-danger">*</span></label><input type="date" class="form-control rounded-4" name="mpt_date" value="<?= e($formMptDate) ?>" required></div>
            <div class="col-md-4"><label class="form-label fw-bold small">Project Hand Over</label><input type="date" class="form-control rounded-4" name="project_handover_date" value="<?= e($defaultHandOver) ?>"></div>
            <div class="col-md-6"><label class="form-label fw-bold small">Month</label><input class="form-control rounded-4" value="<?= e(monthName($formMonth)) ?>" readonly><input type="hidden" name="mpt_month" value="<?= (int)$formMonth ?>"></div>
            <div class="col-md-6"><label class="form-label fw-bold small">Year</label><input class="form-control rounded-4" value="<?= (int)$formYear ?>" readonly><input type="hidden" name="mpt_year" value="<?= (int)$formYear ?>"></div>
          </div>
        </div>

        <?php
        function renderMptSection($code, $title){
        ?>
          <div class="section-box mb-3">
            <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-2 mb-3">
              <div class="mini-head mb-0">
                <div class="mini-icon"><i data-lucide="list-checks"></i></div>
                <div><h2 class="fw-bold fs-6 mb-1"><?= e($code . " — " . $title) ?></h2><p class="text-muted-custom small mb-0">Add as many rows as needed.</p></div>
              </div>
              <button type="button" class="btn btn-outline-primary rounded-4 fw-bold" data-add="<?= e($code) ?>">Add Row</button>
            </div>

            <div class="table-responsive thin-scrollbar">
              <table class="table table-bordered align-middle mb-0 mpt-table">
                <thead>
                  <tr>
                    <th style="width:70px;">SL.NO</th>
                    <th>PLANNED TASK</th>
                    <th style="width:160px;">RESPONSIBLE BY</th>
                    <th style="width:190px;">PLANNED COMPLETION DATE</th>
                    <th style="width:130px;">% PLANNED</th>
                    <th style="width:130px;">% ACTUAL</th>
                    <th style="width:150px;">STATUS</th>
                    <th>REMARKS</th>
                    <th style="width:70px;">Del</th>
                  </tr>
                </thead>
                <tbody id="secBody-<?= e($code) ?>">
                  <tr>
                    <td class="slno fw-bold text-center">1</td>
                    <td><input class="form-control rounded-4" name="<?= e($code) ?>_task[]"></td>
                    <td><input class="form-control rounded-4" name="<?= e($code) ?>_responsible[]" placeholder="e.g. MYVN / UKB"></td>
                    <td><input type="date" class="form-control rounded-4" name="<?= e($code) ?>_plan_date[]"></td>
                    <td><input class="form-control rounded-4" name="<?= e($code) ?>_pct_planned[]" placeholder="e.g. 100"></td>
                    <td><input class="form-control rounded-4" name="<?= e($code) ?>_pct_actual[]" placeholder="e.g. 25"></td>
                    <td><select class="form-select rounded-4" name="<?= e($code) ?>_status[]"><option value="">-- Select --</option><option value="ON TRACK">ON TRACK</option><option value="DONE">DONE</option><option value="DELAY">DELAY</option></select></td>
                    <td><input class="form-control rounded-4" name="<?= e($code) ?>_remarks[]"></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger rounded-4 delRow"><i data-lucide="trash-2" style="width:14px;height:14px;"></i></button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        <?php
        }
        renderMptSection("A", "DESIGN DELIVERABLE");
        renderMptSection("B", "VENDOR FINALIZATION");
        renderMptSection("C", "SITE WORKS");
        renderMptSection("D", "CLIENT DECISIONS");
        ?>

        <div class="section-box mb-3">
          <div class="d-flex justify-content-end">
            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4" <?= $formProjectId<=0 ? "disabled" : "" ?>>Submit MPT</button>
          </div>
          <?php if ($formProjectId<=0): ?><div class="text-muted-custom small fw-bold mt-2">Select a project above to enable submit.</div><?php endif; ?>
        </div>
      </form>

      <section class="card-ui overflow-hidden">
        <div class="p-3 p-lg-4"><h2 class="fw-bold fs-6 mb-1">Recent MPT</h2><p class="text-muted-custom small mb-0">Your last submissions.</p></div>
        <div class="px-3 px-lg-4 pb-4">
          <?php if (!$recent): ?>
            <p class="text-muted-custom fw-bold mb-0">No MPT submitted yet.</p>
          <?php else: ?>
            <div class="row g-2">
              <?php foreach ($recent as $r): ?>
                <div class="col-md-6 col-xl-4">
                  <div class="recent-card">
                    <div class="d-flex justify-content-between gap-2">
                      <div><div class="fw-bold"><?= e($r["mpt_no"]) ?></div><small class="text-muted-custom fw-bold"><?= e($r["project_name"]) ?></small></div>
                      <span class="pill green"><?= e(monthName((int)$r["mpt_month"])) ?> <?= (int)$r["mpt_year"] ?></span>
                    </div>
                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2" target="_blank" href="reports-print/report-mpt-print.php?view=<?= (int)$r["id"] ?>">Print</a>
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
const previousMptTemplate = <?php
$tpl = null;
if ($previousMptTemplate) {
  $tpl = [
    "project_handover_date" => $previousMptTemplate["project_handover_date"] ?? "",
    "items" => json_decode((string)($previousMptTemplate["items_json"] ?? ""), true) ?: []
  ];
}
echo json_encode($tpl, JSON_UNESCAPED_UNICODE);
?>;

document.addEventListener("DOMContentLoaded", function(){
  function icons(){ if(window.lucide && window.lucide.createIcons) window.lucide.createIcons(); }

  const projectPicker = document.getElementById("projectPicker");
  const monthPicker = document.getElementById("monthPicker");
  const yearPicker = document.getElementById("yearPicker");

  function reload(){
    const pid = projectPicker ? (projectPicker.value || "") : "";
    const m = monthPicker ? (monthPicker.value || "") : "";
    const y = yearPicker ? (yearPicker.value || "") : "";
    let url = "mpt.php";
    const params = [];
    if (pid) params.push("project_id=" + encodeURIComponent(pid));
    if (m) params.push("m=" + encodeURIComponent(m));
    if (y) params.push("y=" + encodeURIComponent(y));
    if (params.length) url += "?" + params.join("&");
    window.location.href = url;
  }

  if (projectPicker) projectPicker.addEventListener("change", reload);
  if (monthPicker) monthPicker.addEventListener("change", reload);
  if (yearPicker) yearPicker.addEventListener("change", reload);

  function addRow(sectionCode, rowData = {}){
    const tb = document.getElementById("secBody-" + sectionCode);
    if (!tb) return;

    const tr = document.createElement("tr");
    tr.innerHTML =
      '<td class="slno fw-bold text-center"></td>' +
      '<td><input class="form-control rounded-4" name="' + sectionCode + '_task[]"></td>' +
      '<td><input class="form-control rounded-4" name="' + sectionCode + '_responsible[]" placeholder="e.g. MYVN / UKB"></td>' +
      '<td><input type="date" class="form-control rounded-4" name="' + sectionCode + '_plan_date[]"></td>' +
      '<td><input class="form-control rounded-4" name="' + sectionCode + '_pct_planned[]" placeholder="e.g. 100"></td>' +
      '<td><input class="form-control rounded-4" name="' + sectionCode + '_pct_actual[]" placeholder="e.g. 25"></td>' +
      '<td><select class="form-select rounded-4" name="' + sectionCode + '_status[]"><option value="">-- Select --</option><option value="ON TRACK">ON TRACK</option><option value="DONE">DONE</option><option value="DELAY">DELAY</option></select></td>' +
      '<td><input class="form-control rounded-4" name="' + sectionCode + '_remarks[]"></td>' +
      '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger rounded-4 delRow"><i data-lucide="trash-2" style="width:14px;height:14px;"></i></button></td>';

    tr.querySelector('[name="' + sectionCode + '_task[]"]').value = rowData.planned_task || "";
    tr.querySelector('[name="' + sectionCode + '_responsible[]"]').value = rowData.responsible_by || "";
    tr.querySelector('[name="' + sectionCode + '_plan_date[]"]').value = rowData.planned_completion_date || "";
    tr.querySelector('[name="' + sectionCode + '_pct_planned[]"]').value = rowData.pct_planned || rowData.planned_percent || "";
    tr.querySelector('[name="' + sectionCode + '_pct_actual[]"]').value = rowData.pct_actual || rowData.actual_percent || "";
    tr.querySelector('[name="' + sectionCode + '_status[]"]').value = rowData.status || "";
    tr.querySelector('[name="' + sectionCode + '_remarks[]"]').value = rowData.remarks || "";
    tb.appendChild(tr);
    renumber(sectionCode);
    icons();
  }

  function renumber(sectionCode){
    const tb = document.getElementById("secBody-" + sectionCode);
    if (!tb) return;
    Array.from(tb.querySelectorAll("tr")).forEach((row, idx) => {
      const td = row.querySelector(".slno");
      if (td) td.textContent = String(idx + 1);
    });
  }

  function clearAllSections(){
    ["A","B","C","D"].forEach(function(code){
      const tb = document.getElementById("secBody-" + code);
      if (tb) tb.innerHTML = "";
    });
  }

  function loadPreviousMpt(){
    if (!previousMptTemplate) return;
    const hand = document.querySelector('[name="project_handover_date"]');
    if (hand && previousMptTemplate.project_handover_date) hand.value = previousMptTemplate.project_handover_date;

    clearAllSections();
    const items = Array.isArray(previousMptTemplate.items) ? previousMptTemplate.items : [];
    const groups = {A:[], B:[], C:[], D:[]};
    items.forEach(function(item){
      const code = String(item.section || "A").toUpperCase();
      if (!groups[code]) groups[code] = [];
      groups[code].push(item);
    });
    ["A","B","C","D"].forEach(function(code){
      const rows = groups[code] && groups[code].length ? groups[code] : [{}];
      rows.forEach(function(row){ addRow(code, row || {}); });
    });
  }

  document.getElementById("loadPreviousMptData")?.addEventListener("change", function(){
    if (this.checked) loadPreviousMpt();
  });

  document.addEventListener("click", function(ev){
    const addBtn = ev.target.closest("[data-add]");
    if (addBtn) {
      addRow(addBtn.getAttribute("data-add"));
      return;
    }

    const delBtn = ev.target.closest(".delRow");
    if (!delBtn) return;
    const tr = delBtn.closest("tr");
    const tb = tr ? tr.parentNode : null;
    if (!tr || !tb) return;
    const tbId = tb.id || "";
    const code = tbId.startsWith("secBody-") ? tbId.replace("secBody-", "") : "";

    if (tb.querySelectorAll("tr").length <= 1) {
      tr.querySelectorAll("input,select,textarea").forEach(el => el.value = "");
    } else {
      tr.remove();
    }
    if (code) renumber(code);
    icons();
  });

  ["A","B","C","D"].forEach(renumber);
  icons();
});
</script>
</body>
</html>
