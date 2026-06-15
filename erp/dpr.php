<?php
// dpr.php — DPR submit/update based on latest uploaded DPR file design.
// Current DB version:
// - Uses includes/db.php
// - Uses projects table, not old sites table
// - Uses project_assignments for access
// - Uses PMS schedule from project_pmc_schedules
// - Uses Reports Hub submission tracking
// - Shows Load previous data checkbox

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';
date_default_timezone_set("Asia/Kolkata");

// ---------------- AUTH ----------------
if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
  header("Location: login.php");
  exit;
}

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function emp_id($conn) {
  if (!empty($_SESSION['employee_id'])) return (int)$_SESSION['employee_id'];
  if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $q = mysqli_query($conn, "SELECT employee_id FROM users WHERE id=$uid LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r['employee_id'])) {
      $_SESSION['employee_id'] = (int)$r['employee_id'];
      return (int)$r['employee_id'];
    }
  }
  return 0;
}

function is_super_admin($conn) {
  $uid = (int)($_SESSION['user_id'] ?? 0);
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

function report_perm($conn, $col) {
  $ok = ['can_submit','can_view','can_remark_tl','can_remark_manager'];
  if (!in_array($col, $ok, true)) return false;
  if (is_super_admin($conn)) return true;

  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) return false;

  $q = mysqli_query($conn, "
    SELECT MAX(COALESCE(rtra.$col,0)) allowed
    FROM user_roles ur
    INNER JOIN report_type_role_access rtra ON rtra.role_id = ur.role_id
    INNER JOIN master_report_types rt ON rt.id = rtra.report_type_id
    WHERE ur.user_id = $uid
      AND rt.report_code = 'DPR'
      AND rt.is_active = 1
  ");
  return $q && ($r = mysqli_fetch_assoc($q)) && (int)($r['allowed'] ?? 0) === 1;
}

function jsonCleanRows(array $rows): array {
  $out = [];
  foreach ($rows as $r) {
    $has = false;
    foreach ($r as $v) {
      if (trim((string)$v) !== '') { $has = true; break; }
    }
    if ($has) $out[] = $r;
  }
  return $out;
}

function ymdOrNull($v) {
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return null;
  return $v;
}

function calcDurations($startYmd, $endYmd) {
  $startYmd = trim((string)$startYmd);
  $endYmd = trim((string)$endYmd);

  $total = null; $elapsed = null; $balance = null;

  if ($startYmd !== '' && $endYmd !== '' && $startYmd !== '0000-00-00' && $endYmd !== '0000-00-00') {
    $s = DateTime::createFromFormat('Y-m-d', $startYmd);
    $e = DateTime::createFromFormat('Y-m-d', $endYmd);
    if ($s && $e) {
      if ($e < $s) { $tmp = $s; $s = $e; $e = $tmp; }

      $total = $s->diff($e)->days + 1;

      $today = new DateTime(date('Y-m-d'));
      if ($today < $s) $elapsed = 0;
      else {
        $elapsed = $s->diff($today)->days + 1;
        if ($elapsed > $total) $elapsed = $total;
      }

      $balance = max(0, $total - $elapsed);
    }
  }

  return [$total, $elapsed, $balance];
}

function project_allowed($conn, int $projectId, int $employeeId, bool $super): bool {
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

function hasDprForDate(mysqli $conn, int $employeeId, int $projectId, string $ymd): bool {
  if ($employeeId <= 0 || $projectId <= 0 || $ymd === '') return false;

  $st = mysqli_prepare($conn, "SELECT id FROM dpr_reports WHERE employee_id=? AND project_id=? AND dpr_date=? LIMIT 1");
  if (!$st) return false;

  mysqli_stmt_bind_param($st, "iis", $employeeId, $projectId, $ymd);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);

  return !empty($row);
}

function getEditableDprById(mysqli $conn, int $dprId, int $employeeId, bool $super): ?array {
  if ($dprId <= 0) return null;

  if ($super) {
    $sql = "
      SELECT r.*
      FROM dpr_reports r
      INNER JOIN projects p ON p.id = r.project_id
      WHERE r.id = ?
        AND r.editable_status = 1
      LIMIT 1
    ";
    $st = mysqli_prepare($conn, $sql);
    if (!$st) return null;
    mysqli_stmt_bind_param($st, "i", $dprId);
  } else {
    $sql = "
      SELECT r.*
      FROM dpr_reports r
      INNER JOIN projects p ON p.id = r.project_id
      WHERE r.id = ?
        AND r.editable_status = 1
        AND (
              r.employee_id = ?
           OR p.manager_employee_id = ?
           OR p.team_lead_employee_id = ?
        )
      LIMIT 1
    ";
    $st = mysqli_prepare($conn, $sql);
    if (!$st) return null;
    mysqli_stmt_bind_param($st, "iiii", $dprId, $employeeId, $employeeId, $employeeId);
  }

  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = mysqli_fetch_assoc($res) ?: null;
  mysqli_stmt_close($st);

  return $row;
}

function dpr_no($conn, $projectId) {
  $projectId = (int)$projectId;
  $q = mysqli_query($conn, "SELECT COUNT(*) cnt FROM dpr_reports WHERE project_id=$projectId");
  $r = $q ? mysqli_fetch_assoc($q) : ['cnt' => 0];
  return '#' . str_pad(((int)($r['cnt'] ?? 0) + 1), 2, '0', STR_PAD_LEFT);
}

function table_cols($conn, $t) {
  $t = preg_replace('/[^a-zA-Z0-9_]/', '', $t);
  $cols = [];
  $q = mysqli_query($conn, "SHOW COLUMNS FROM `$t`");
  while ($q && ($r = mysqli_fetch_assoc($q))) $cols[$r['Field']] = true;
  return $cols;
}

function sqlv($conn, $v) {
  if ($v === null) return 'NULL';
  if (is_int($v) || is_float($v)) return (string)$v;
  return "'" . mysqli_real_escape_string($conn, (string)$v) . "'";
}

function report_type_id($conn) {
  $q = mysqli_query($conn, "SELECT id FROM master_report_types WHERE report_code='DPR' LIMIT 1");
  return ($q && ($r = mysqli_fetch_assoc($q))) ? (int)$r['id'] : 0;
}

function mark_submitted($conn, $projectId, $employeeId, $date, $dprId, $dprNo) {
  $projectId = (int)$projectId;
  $employeeId = (int)$employeeId;
  $dprId = (int)$dprId;
  $rt = report_type_id($conn);
  $uid = (int)($_SESSION['user_id'] ?? 0);

  if ($projectId <= 0 || $employeeId <= 0 || $dprId <= 0 || $rt <= 0) return;

  $cols = table_cols($conn, 'project_report_submissions');
  $safe = trim((string)$dprNo) ?: ('DPR-' . $projectId . '-' . str_replace('-', '', $date) . '-' . $dprId);
  $d = mysqli_real_escape_string($conn, $date);

  $conditions = [];
  if (isset($cols['submission_for_date'])) $conditions[] = "submission_for_date='$d'";
  if (isset($cols['report_reference_table']) && isset($cols['report_reference_id'])) $conditions[] = "(report_reference_table='dpr_reports' AND report_reference_id=$dprId)";
  if (isset($cols['source_table']) && isset($cols['source_id'])) $conditions[] = "(source_table='dpr_reports' AND source_id=$dprId)";
  if (isset($cols['reference_id'])) $conditions[] = "reference_id=$dprId";

  $id = 0;
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
    $id = ($q && ($r = mysqli_fetch_assoc($q))) ? (int)$r['id'] : 0;
  }

  if ($id > 0) {
    $sets = [];
    foreach (['report_no','report_number','submission_no'] as $c) {
      if (isset($cols[$c])) $sets[] = "`$c`='" . mysqli_real_escape_string($conn, $safe) . "'";
    }

    $map = [
      'title' => "'" . mysqli_real_escape_string($conn, 'DPR ' . $safe) . "'",
      'status' => "'submitted'",
      'submitted_at' => 'NOW()',
      'submitted_by_employee_id' => $employeeId,
      'submitted_by_user_id' => $uid ?: 'NULL',
      'submission_for_date' => "'$d'",
      'period_start' => "'$d'",
      'period_end' => "'$d'",
      'reference_id' => $dprId,
      'source_table' => "'dpr_reports'",
      'source_id' => $dprId,
      'report_reference_table' => "'dpr_reports'",
      'report_reference_id' => $dprId,
      'updated_by' => $uid ?: 'NULL',
      'updated_at' => 'NOW()'
    ];

    foreach ($map as $c => $v) {
      if (isset($cols[$c])) $sets[] = "`$c`=$v";
    }

    if ($sets) mysqli_query($conn, "UPDATE project_report_submissions SET " . implode(',', $sets) . " WHERE id=$id");
    return;
  }

  $data = [
    'project_id' => $projectId,
    'report_type_id' => $rt,
    'report_no' => $safe,
    'report_number' => $safe,
    'submission_no' => $safe,
    'submitted_by_employee_id' => $employeeId,
    'submitted_by_user_id' => $uid ?: null,
    'submission_for_date' => $date,
    'period_start' => $date,
    'period_end' => $date,
    'title' => 'DPR ' . $safe,
    'status' => 'submitted',
    'submitted_at' => date('Y-m-d H:i:s'),
    'reference_id' => $dprId,
    'source_table' => 'dpr_reports',
    'source_id' => $dprId,
    'report_reference_table' => 'dpr_reports',
    'report_reference_id' => $dprId,
    'created_by' => $uid ?: null,
    'updated_by' => $uid ?: null,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s')
  ];

  $ic = [];
  $iv = [];
  foreach ($data as $k => $v) {
    if (isset($cols[$k])) {
      $ic[] = "`$k`";
      $iv[] = sqlv($conn, $v);
    }
  }

  if ($ic) {
    $up = [];
    foreach ($ic as $c) {
      $cc = trim($c, '`');
      if (!in_array($cc, ['id','created_at','created_by'], true)) $up[] = "`$cc`=VALUES(`$cc`)";
    }
    $sql = "INSERT INTO project_report_submissions (" . implode(',', $ic) . ") VALUES (" . implode(',', $iv) . ")";
    if ($up) $sql .= " ON DUPLICATE KEY UPDATE " . implode(',', $up);
    mysqli_query($conn, $sql);
  }
}

function log_act($conn, $type, $desc, $ref = null) {
  $eid = emp_id($conn);
  $name = mysqli_real_escape_string($conn, $_SESSION['employee_name'] ?? $_SESSION['name'] ?? '');
  $user = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
  $des = mysqli_real_escape_string($conn, $_SESSION['designation'] ?? '');
  $dep = mysqli_real_escape_string($conn, $_SESSION['department'] ?? '');
  $type = mysqli_real_escape_string($conn, strtoupper($type));
  $desc = mysqli_real_escape_string($conn, $desc);
  $refSql = $ref ? (int)$ref : 'NULL';
  $ip = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR'] ?? '');
  mysqli_query($conn, "
    INSERT INTO activity_logs
    (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
    VALUES ($eid,'$name','$user','$des','$dep','$type','DPR','$desc',$refSql,'$ip')
  ");
}

function hub_redirect($projectId, $date) {
  $projectId = (int)$projectId;
  $d = urlencode($date);
  header("Location: reports-hub.php?project_id=$projectId&report_date=$d&period_start=$d&period_end=$d&saved=1");
  exit;
}

$employeeId = emp_id($conn);
$super = is_super_admin($conn);

if ($employeeId <= 0) {
  header("Location: login.php");
  exit;
}

if (!report_perm($conn, 'can_view') && !report_perm($conn, 'can_submit')) {
  header('Location: reports-hub.php?error=' . urlencode('You do not have DPR report access.'));
  exit;
}

// ---------------- Logged Employee ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT e.id, e.full_name, e.email, r.role_name AS designation FROM employees e LEFT JOIN roles r ON r.id=e.role_id WHERE e.id=? LIMIT 1");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $empRow = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}
$preparedBy = $empRow['full_name'] ?? ($_SESSION['employee_name'] ?? '');
$designationLabel = $empRow['designation'] ?? ($_SESSION['designation'] ?? '');

// ---------------- Assigned Projects ----------------
$projects = [];
$where = "p.deleted_at IS NULL";
if (!$super) {
  $where .= "
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
  ";
}

$pq = mysqli_query($conn, "
  SELECT p.id, p.project_name, p.project_location, c.client_name
  FROM projects p
  LEFT JOIN clients c ON c.id = p.client_id
  WHERE $where
  ORDER BY p.created_at DESC, p.project_name ASC
");
while ($pq && ($p = mysqli_fetch_assoc($pq))) $projects[] = $p;

// ---------------- EDIT MODE ----------------
$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$editMode = false;
$editRow = null;

if ($editId > 0) {
  $editRow = getEditableDprById($conn, $editId, $employeeId, $super);
  if ($editRow) {
    $editMode = true;
  }
}

// ---------------- Selected Project ----------------
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($editMode && $editRow) $projectId = (int)$editRow['project_id'];

$project = null;
$defaultDistribute = '';

if ($projectId > 0 && project_allowed($conn, $projectId, $employeeId, $super)) {
  $sql = "
    SELECT
      p.*,
      c.client_name,
      cd.division_name,
      mpt.project_type_name,
      mps.status_name AS project_status_name
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN company_divisions cd ON cd.id = p.division_id
    LEFT JOIN master_project_types mpt ON mpt.id = p.project_type_id
    LEFT JOIN master_project_statuses mps ON mps.id = p.project_status_id
    WHERE p.id = $projectId
    LIMIT 1
  ";
  $q = mysqli_query($conn, $sql);
  $project = $q ? mysqli_fetch_assoc($q) : null;

  if ($project) {
    $parts = [];

    $clientName = trim((string)($project['client_name'] ?? ''));
    $parts[] = $clientName !== '' ? 'Client - ' . $clientName : 'Client';

    if (!empty($project['manager_employee_id'])) {
      $mid = (int)$project['manager_employee_id'];
      $mq = mysqli_query($conn, "SELECT full_name, email FROM employees WHERE id=$mid LIMIT 1");
      if ($mq && ($m = mysqli_fetch_assoc($mq))) {
        $mLabel = trim((string)($m['email'] ?? ''));
        if ($mLabel === '') $mLabel = trim((string)($m['full_name'] ?? 'Manager'));
        $parts[] = $mLabel;
      }
    }

    $dir = [];
    $r = mysqli_query($conn, "
      SELECT e.full_name, e.email, r.role_name
      FROM employees e
      LEFT JOIN roles r ON r.id=e.role_id
      WHERE e.employee_status='active'
        AND r.role_name IN ('Director','Vice President','General Manager')
      ORDER BY r.role_name ASC, e.full_name ASC
    ");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
      $label = trim((string)($row['email'] ?? ''));
      if ($label === '') $label = trim((string)($row['full_name'] ?? ''));
      if ($label !== '') $dir[] = $label;
    }
    foreach ($dir as $d) $parts[] = $d;

    $parts = array_values(array_unique(array_filter($parts, fn($x) => trim((string)$x) !== '')));
    $defaultDistribute = implode(', ', $parts);
  }
}

// ---------------- PMS schedule and defaults ----------------
$pmsSchedule = $projectId > 0 ? pms_project_schedule($conn, $projectId) : null;
[$pmsScheduleStart, $pmsScheduleEnd] = pms_schedule_date_range($pmsSchedule, $project);

$todayYmd = date('Y-m-d');
$defaultDprNo = $projectId > 0 ? dpr_no($conn, $projectId) : '';

$formProjectId = $projectId;
$formDprNo = $defaultDprNo;
$formDprDate = $_GET['report_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formDprDate)) $formDprDate = date('Y-m-d');

$defaultScheduleStart = ($pmsScheduleStart && $pmsScheduleStart !== '0000-00-00') ? $pmsScheduleStart : (($project && !empty($project['start_date']) && $project['start_date'] !== '0000-00-00') ? $project['start_date'] : date('Y-m-d'));
$defaultScheduleEnd = ($pmsScheduleEnd && $pmsScheduleEnd !== '0000-00-00') ? $pmsScheduleEnd : (($project && !empty($project['expected_completion_date']) && $project['expected_completion_date'] !== '0000-00-00') ? $project['expected_completion_date'] : '');
$defaultProjected = date('Y-m-d');

[$defTotal, $defElapsed, $defBalance] = calcDurations($defaultScheduleStart, $defaultScheduleEnd);

if ($defaultDistribute === '' && $project) {
  $cn = trim((string)($project['client_name'] ?? ''));
  $defaultDistribute = $cn !== '' ? ('Client - ' . $cn . ', Manager, Director') : 'Client, Manager, Director';
}

// ---------------- Edit mode defaults ----------------
$editWeather = '';
$editSiteCondition = '';
$editMpRows = [];
$editMcRows = [];
$editMtRows = [];
$editWpRows = [];
$editCsRows = [];

if ($editMode && $editRow) {
  $formProjectId = (int)$editRow['project_id'];
  $formDprNo = (string)($editRow['dpr_no'] ?? '');
  $formDprDate = (string)($editRow['dpr_date'] ?? date('Y-m-d'));

  $defaultScheduleStart = (string)($editRow['schedule_start'] ?? $defaultScheduleStart);
  $defaultScheduleEnd = (string)($editRow['schedule_end'] ?? $defaultScheduleEnd);
  $defaultProjected = (string)($editRow['schedule_projected'] ?? date('Y-m-d'));

  [$defTotal, $defElapsed, $defBalance] = calcDurations($defaultScheduleStart, $defaultScheduleEnd);

  $editWeather = (string)($editRow['weather'] ?? '');
  $editSiteCondition = (string)($editRow['site_condition'] ?? '');
  $defaultDistribute = (string)($editRow['report_distribute_to'] ?? $defaultDistribute);

  $editMpRows = json_decode((string)($editRow['manpower_json'] ?? ''), true) ?: [];
  $editMcRows = json_decode((string)($editRow['machinery_json'] ?? ''), true) ?: [];
  $editMtRows = json_decode((string)($editRow['material_json'] ?? ''), true) ?: [];
  $editWpRows = json_decode((string)($editRow['work_progress_json'] ?? ''), true) ?: [];
  $editCsRows = json_decode((string)($editRow['constraints_json'] ?? ''), true) ?: [];
}

// ---------------- Previous DPR template for checkbox ----------------
$previousDprTemplate = null;
if ($formProjectId > 0) {
  $dateEsc = mysqli_real_escape_string($conn, $formDprDate);

  $prevQ = mysqli_query($conn, "
    SELECT *
    FROM dpr_reports
    WHERE project_id = $formProjectId
      AND employee_id = $employeeId
      AND dpr_date < '$dateEsc'
    ORDER BY dpr_date DESC, created_at DESC, id DESC
    LIMIT 1
  ");
  if ($prevQ) $previousDprTemplate = mysqli_fetch_assoc($prevQ);

  if (!$previousDprTemplate) {
    $prevQ = mysqli_query($conn, "
      SELECT *
      FROM dpr_reports
      WHERE project_id = $formProjectId
        AND employee_id = $employeeId
      ORDER BY dpr_date DESC, created_at DESC, id DESC
      LIMIT 1
    ");
    if ($prevQ) $previousDprTemplate = mysqli_fetch_assoc($prevQ);
  }

  if (!$previousDprTemplate) {
    $prevQ = mysqli_query($conn, "
      SELECT *
      FROM dpr_reports
      WHERE project_id = $formProjectId
      ORDER BY dpr_date DESC, created_at DESC, id DESC
      LIMIT 1
    ");
    if ($prevQ) $previousDprTemplate = mysqli_fetch_assoc($prevQ);
  }
}

// ---------------- Message/duplicate state ----------------
$success = '';
$error = '';

if (isset($_GET['saved']) && $_GET['saved'] === '1') $success = "DPR submitted successfully.";
if (isset($_GET['updated']) && $_GET['updated'] === '1') $success = "DPR updated successfully.";
if (isset($_GET['error'])) $error = trim((string)$_GET['error']);

$alreadySubmittedToday = false;
if (!$editMode && $formProjectId > 0) {
  $alreadySubmittedToday = hasDprForDate($conn, $employeeId, (int)$formProjectId, $formDprDate);
}

// ---------------- SUBMIT/UPDATE ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dpr'])) {
  $postEditId = (int)($_POST['edit_id'] ?? 0);
  $isUpdateRequest = ($postEditId > 0);

  if ($isUpdateRequest) {
    $editRow = getEditableDprById($conn, $postEditId, $employeeId, $super);
    if (!$editRow) {
      $error = "This DPR is not editable or you do not have permission.";
    } else {
      $editMode = true;
      $editId = $postEditId;
    }
  }

  $postProjectId = (int)($_POST['project_id'] ?? 0);
  $dpr_no = trim((string)($_POST['dpr_no'] ?? ''));
  $dpr_date = trim((string)($_POST['dpr_date'] ?? ''));

  $schedule_start = trim((string)($_POST['schedule_start'] ?? ''));
  $schedule_end = trim((string)($_POST['schedule_end'] ?? ''));
  $schedule_projected = trim((string)($_POST['schedule_projected'] ?? ''));

  $weather = trim((string)($_POST['weather'] ?? ''));
  $site_condition = trim((string)($_POST['site_condition'] ?? ''));
  $report_distribute_to = trim((string)($_POST['report_distribute_to'] ?? ''));

  if ($error === '' && !report_perm($conn, 'can_submit') && !$isUpdateRequest) $error = "You do not have permission to submit DPR.";
  if ($error === '' && ($postProjectId <= 0 || !project_allowed($conn, $postProjectId, $employeeId, $super))) $error = "Invalid project selection.";
  if ($error === '' && $dpr_no === '') $error = "DPR No is required.";
  if ($error === '' && $dpr_date === '') $error = "DPR Date is required.";
  if ($error === '' && $weather === '') $error = "Weather is required.";
  if ($error === '' && $site_condition === '') $error = "Site Condition is required.";
  if ($error === '' && $report_distribute_to === '') $error = "Report Distribute To is required.";

  if ($error === '' && !$editMode && hasDprForDate($conn, $employeeId, $postProjectId, $dpr_date)) {
    $error = "You already submitted a DPR for this project on $dpr_date. You can submit again from the next day.";
  }

  [$duration_total, $duration_elapsed, $duration_balance] = calcDurations($schedule_start, $schedule_end);

  $manpowerRows = [];
  $agency = $_POST['mp_agency'] ?? [];
  $cat = $_POST['mp_category'] ?? [];
  $unit = $_POST['mp_unit'] ?? [];
  $qty = $_POST['mp_qty'] ?? [];
  $remark = $_POST['mp_remark'] ?? [];
  $max = max(count($agency), count($cat), count($unit), count($qty), count($remark));
  for ($i=0; $i<$max; $i++) {
    $manpowerRows[] = [
      'agency' => $agency[$i] ?? '',
      'category' => $cat[$i] ?? '',
      'unit' => $unit[$i] ?? '',
      'qty' => $qty[$i] ?? '',
      'remark' => $remark[$i] ?? ''
    ];
  }
  $manpowerRows = jsonCleanRows($manpowerRows);

  $machRows = [];
  $eq = $_POST['mc_equipment'] ?? [];
  $mcu = $_POST['mc_unit'] ?? [];
  $mcq = $_POST['mc_qty'] ?? [];
  $mcr = $_POST['mc_remark'] ?? [];
  $max = max(count($eq), count($mcu), count($mcq), count($mcr));
  for ($i=0; $i<$max; $i++) {
    $machRows[] = [
      'equipment' => $eq[$i] ?? '',
      'unit' => $mcu[$i] ?? '',
      'qty' => $mcq[$i] ?? '',
      'remark' => $mcr[$i] ?? ''
    ];
  }
  $machRows = jsonCleanRows($machRows);

  $matRows = [];
  $vd = $_POST['mt_vendor'] ?? [];
  $mt = $_POST['mt_material'] ?? [];
  $mtu = $_POST['mt_unit'] ?? [];
  $mtq = $_POST['mt_qty'] ?? [];
  $mtr = $_POST['mt_remark'] ?? [];
  $max = max(count($vd), count($mt), count($mtu), count($mtq), count($mtr));
  for ($i=0; $i<$max; $i++) {
    $matRows[] = [
      'vendor' => $vd[$i] ?? '',
      'material' => $mt[$i] ?? '',
      'unit' => $mtu[$i] ?? '',
      'qty' => $mtq[$i] ?? '',
      'remark' => $mtr[$i] ?? ''
    ];
  }
  $matRows = jsonCleanRows($matRows);

  $wpRows = [];
  $task = $_POST['wp_task'] ?? [];
  $wpd = $_POST['wp_duration'] ?? [];
  $wps = $_POST['wp_start'] ?? [];
  $wpe = $_POST['wp_end'] ?? [];
  $wst = $_POST['wp_status'] ?? [];
  $wrs = $_POST['wp_reasons'] ?? [];
  $max = max(count($task), count($wpd), count($wps), count($wpe), count($wst), count($wrs));
  for ($i=0; $i<$max; $i++) {
    $wpRows[] = [
      'task' => $task[$i] ?? '',
      'duration' => $wpd[$i] ?? '',
      'start' => $wps[$i] ?? '',
      'end' => $wpe[$i] ?? '',
      'status' => $wst[$i] ?? '',
      'reasons' => $wrs[$i] ?? ''
    ];
  }
  $wpRows = jsonCleanRows($wpRows);
  if ($error === '' && empty($wpRows)) $error = "Please enter at least one Work Progress task.";

  $consRows = [];
  $issue = $_POST['cs_issue'] ?? [];
  $cst = $_POST['cs_status'] ?? [];
  $csd = $_POST['cs_date'] ?? [];
  $csr = $_POST['cs_remark'] ?? [];
  $max = max(count($issue), count($cst), count($csd), count($csr));
  for ($i=0; $i<$max; $i++) {
    $consRows[] = [
      'issue' => $issue[$i] ?? '',
      'status' => $cst[$i] ?? '',
      'date' => $csd[$i] ?? '',
      'remark' => $csr[$i] ?? ''
    ];
  }
  $consRows = jsonCleanRows($consRows);

  if ($error === '') {
    $manpower_json = !empty($manpowerRows) ? json_encode($manpowerRows, JSON_UNESCAPED_UNICODE) : null;
    $machinery_json = !empty($machRows) ? json_encode($machRows, JSON_UNESCAPED_UNICODE) : null;
    $material_json = !empty($matRows) ? json_encode($matRows, JSON_UNESCAPED_UNICODE) : null;
    $work_progress_json = !empty($wpRows) ? json_encode($wpRows, JSON_UNESCAPED_UNICODE) : null;
    $constraints_json = !empty($consRows) ? json_encode($consRows, JSON_UNESCAPED_UNICODE) : null;

    $schS = ymdOrNull($schedule_start);
    $schE = ymdOrNull($schedule_end);
    $schP = ymdOrNull($schedule_projected);

    if ($editMode && $editId > 0) {
      if (!empty($editRow) && (int)$editRow['project_id'] !== $postProjectId) {
        $error = "Project mismatch. Cannot move DPR to another project.";
      }

      if ($error === '') {
        $upd = mysqli_prepare($conn, "
          UPDATE dpr_reports SET
            dpr_no = ?, dpr_date = ?,
            schedule_start = ?, schedule_end = ?, schedule_projected = ?,
            duration_total = ?, duration_elapsed = ?, duration_balance = ?,
            weather = ?, site_condition = ?,
            manpower_json = ?, machinery_json = ?, material_json = ?, work_progress_json = ?, constraints_json = ?,
            report_distribute_to = ?, prepared_by = ?,
            editable_status = 2
          WHERE id = ? AND editable_status = 1
        ");

        if (!$upd) {
          $error = "DB Error: " . mysqli_error($conn);
        } else {
          mysqli_stmt_bind_param(
            $upd,
            "sssssiiisssssssssi",
            $dpr_no, $dpr_date,
            $schS, $schE, $schP,
            $duration_total, $duration_elapsed, $duration_balance,
            $weather, $site_condition,
            $manpower_json, $machinery_json, $material_json, $work_progress_json, $constraints_json,
            $report_distribute_to, $preparedBy,
            $editId
          );

          if (!mysqli_stmt_execute($upd)) {
            $error = "Failed to update DPR: " . mysqli_stmt_error($upd);
          } else {
            if (mysqli_stmt_affected_rows($upd) < 1) {
              $chk = mysqli_prepare($conn, "UPDATE dpr_reports SET editable_status=2 WHERE id=? AND editable_status=1");
              if ($chk) {
                mysqli_stmt_bind_param($chk, "i", $editId);
                mysqli_stmt_execute($chk);
                mysqli_stmt_close($chk);
              }
            }

            mysqli_stmt_close($upd);
            log_act($conn, "UPDATE", "Updated DPR " . $dpr_no, $editId);
            mark_submitted($conn, $postProjectId, $employeeId, $dpr_date, $editId, $dpr_no);
            header("Location: reports-hub.php?project_id=" . $postProjectId . "&report_date=" . urlencode($dpr_date) . "&period_start=" . urlencode($dpr_date) . "&period_end=" . urlencode($dpr_date) . "&updated=1");
            exit;
          }
          mysqli_stmt_close($upd);
        }
      }

    } else {
      $editableStatus = 2;

      $ins = mysqli_prepare($conn, "
        INSERT INTO dpr_reports
        (project_id, employee_id, dpr_no, dpr_date,
         schedule_start, schedule_end, schedule_projected,
         duration_total, duration_elapsed, duration_balance,
         weather, site_condition,
         manpower_json, machinery_json, material_json, work_progress_json, constraints_json,
         report_distribute_to, prepared_by, editable_status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");

      if (!$ins) {
        $error = "DB Error: " . mysqli_error($conn);
      } else {
        mysqli_stmt_bind_param(
          $ins,
          "iisssssiiisssssssssi",
          $postProjectId, $employeeId, $dpr_no, $dpr_date,
          $schS, $schE, $schP,
          $duration_total, $duration_elapsed, $duration_balance,
          $weather, $site_condition,
          $manpower_json, $machinery_json, $material_json, $work_progress_json, $constraints_json,
          $report_distribute_to, $preparedBy, $editableStatus
        );

        if (!mysqli_stmt_execute($ins)) {
          $error = "Failed to save DPR: " . mysqli_stmt_error($ins);
        } else {
          $newId = mysqli_insert_id($conn);
          mysqli_stmt_close($ins);
          log_act($conn, "CREATE", "Submitted DPR " . $dpr_no, $newId);
          mark_submitted($conn, $postProjectId, $employeeId, $dpr_date, $newId, $dpr_no);
          hub_redirect($postProjectId, $dpr_date);
        }
        mysqli_stmt_close($ins);
      }
    }
  }

  if ($error !== '') {
    header('Location: dpr.php?project_id=' . $postProjectId . '&report_date=' . urlencode($dpr_date) . '&error=' . urlencode($error));
    exit;
  }
}

// ---------------- Recent DPRs ----------------
$recent = [];
$rq = mysqli_query($conn, "
  SELECT r.id, r.dpr_no, r.dpr_date, r.editable_status, p.project_name
  FROM dpr_reports r
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
  <title>DPR - TEK-C</title>
  <?php include("includes/links.php"); ?>
  <style>
    .page-head-card{
      background:var(--card-bg);
      border:1px solid var(--border-soft);
      border-radius:22px;
      box-shadow:var(--shadow-card);
      padding:16px;
    }
    .section-box{
      background:var(--card-bg);
      border:1px solid var(--border-soft);
      border-radius:22px;
      box-shadow:var(--shadow-card);
      padding:18px;
      margin-bottom:14px;
    }
    .mini-head{
      display:flex;
      align-items:center;
      gap:12px;
      margin-bottom:14px;
    }
    .mini-icon{
      width:44px;
      height:44px;
      border-radius:16px;
      display:flex;
      align-items:center;
      justify-content:center;
      background:rgba(249,115,22,.12);
      color:#f97316;
      flex:0 0 auto;
    }
    .form-control,.form-select{
      background:var(--card-bg);
      color:var(--text-main);
      border-color:var(--border-soft);
      min-height:42px;
      font-size:13px;
      font-weight:700;
      border-radius:16px;
    }
    .dpr-table th{
      font-size:12px;
      text-transform:uppercase;
      color:var(--text-muted);
      font-weight:900;
      background:rgba(148,163,184,.10);
      white-space:nowrap;
    }
    .dpr-table td{vertical-align:top}
    .badge-soft{
      display:inline-flex;
      align-items:center;
      gap:8px;
      border:1px solid var(--border-soft);
      background:rgba(148,163,184,.08);
      border-radius:999px;
      padding:7px 12px;
      font-size:12px;
      font-weight:900;
      color:var(--text-main);
    }
    .badge-soft input[type="checkbox"]{
      width:15px;
      height:15px;
      margin:0;
      flex:0 0 auto;
    }
    .badge-soft span{line-height:1.15}
    .delete-row-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:34px;
      height:34px;
      border-radius:12px;
      border:1px solid rgba(220,38,38,.25);
      background:rgba(220,38,38,.08);
      color:#dc2626;
      cursor:pointer;
    }
    .recent-card{
      border:1px solid var(--border-soft);
      border-radius:18px;
      padding:12px;
      background:rgba(148,163,184,.06);
    }
    .project-kv small{
      color:var(--text-muted);
      font-size:12px;
      font-weight:900;
    }
    .project-kv div{
      color:var(--text-main);
      font-size:15px;
      font-weight:900;
    }
    .action-btn{
      border-radius:14px;
      font-weight:900;
      font-size:13px;
    }
    @media(max-width:767px){
      .dpr-table{min-width:980px}
      .section-box{padding:14px}
      .page-head-card{padding:14px}
    }
  </style>
</head>
<body>
<div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>
<?php if (file_exists(__DIR__ . "/includes/page-message.php")) include("includes/page-message.php"); ?>

<div class="min-vh-100 d-flex">
  <?php include("includes/sidebar.php"); ?>
  <main id="main">
    <?php
      if (file_exists(__DIR__ . "/includes/nav.php")) include("includes/nav.php");
      elseif (file_exists(__DIR__ . "/includes/topbar.php")) include("includes/topbar.php");
    ?>

    <section class="page-section p-3 p-lg-3">
      <div class="page-head-card mb-3">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
          <div>
            <h1 class="h4 fw-bold mb-1"><?php echo $editMode ? 'Edit Daily Progress Report (DPR)' : 'Daily Progress Report (DPR)'; ?></h1>
            <p class="text-muted-custom mb-0 small">
              <?php echo $editMode ? 'Update your DPR using the current report workflow.' : 'Submit your DPR for the selected project using PMS schedule details.'; ?>
            </p>
          </div>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <label class="badge-soft mb-0 <?php echo !$previousDprTemplate ? 'opacity-75' : ''; ?>" title="<?php echo $previousDprTemplate ? 'Load previous submitted DPR data without changing DPR No, date, project or PMS schedule' : 'No previous DPR data found for this project'; ?>">
              <input type="checkbox" class="form-check-input mt-0" id="loadPreviousDprData" <?php echo !$previousDprTemplate ? 'disabled' : ''; ?>>
              <span>
                <strong>Load previous data</strong>
                <small class="d-block text-muted-custom fw-semibold">
                  <?php if ($previousDprTemplate): ?>
                    <?php echo e($previousDprTemplate['dpr_no']); ?> · <?php echo e(date('d M Y', strtotime($previousDprTemplate['dpr_date']))); ?>
                  <?php else: ?>
                    No previous data
                  <?php endif; ?>
                </small>
              </span>
            </label>
            <span class="badge-soft"><i data-lucide="user" style="width:15px;height:15px;"></i><?php echo e($preparedBy); ?></span>
            <span class="badge-soft"><i data-lucide="award" style="width:15px;height:15px;"></i><?php echo e($designationLabel); ?></span>
            <a href="dpr.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Reset</a>
          </div>
        </div>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger rounded-4 fw-bold">
          <i data-lucide="alert-triangle" style="width:17px;height:17px;"></i>
          <?php echo e($error); ?>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success rounded-4 fw-bold">
          <i data-lucide="check-circle" style="width:17px;height:17px;"></i>
          <?php echo e($success); ?>
        </div>
      <?php endif; ?>

      <?php if ($alreadySubmittedToday && $formProjectId > 0): ?>
        <div class="alert alert-warning rounded-4 fw-bold">
          <i data-lucide="info" style="width:17px;height:17px;"></i>
          You already submitted DPR for this project on <?php echo e($formDprDate); ?>. Submit will be available next day.
        </div>
      <?php endif; ?>

      <div class="section-box">
        <div class="mini-head">
          <div class="mini-icon"><i data-lucide="map-pin"></i></div>
          <div>
            <h2 class="fw-bold fs-6 mb-1">Project Selection</h2>
            <p class="text-muted-custom small mb-0">Choose the assigned project to prepare DPR.</p>
          </div>
        </div>
        <div class="row g-3 align-items-end">
          <div class="col-lg-9">
            <label class="form-label fw-bold small">Assigned Project <span class="text-danger">*</span></label>
            <select class="form-select rounded-4" id="projectPicker" <?php echo $editMode ? 'disabled' : ''; ?>>
              <option value="">-- Select Project --</option>
              <?php foreach ($projects as $p): ?>
                <?php $pid = (int)$p['id']; ?>
                <option value="<?php echo $pid; ?>" <?php echo ($pid === $formProjectId ? 'selected' : ''); ?>>
                  <?php echo e($p['project_name']); ?> - <?php echo e($p['project_location']); ?> (<?php echo e($p['client_name']); ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-3">
            <a href="dpr.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">Reset</a>
          </div>
        </div>
      </div>

      <div class="section-box">
        <div class="mini-head">
          <div class="mini-icon"><i data-lucide="building-2"></i></div>
          <div>
            <h2 class="fw-bold fs-6 mb-1">Project Information</h2>
            <p class="text-muted-custom small mb-0">Auto-filled from selected project and PMS schedule.</p>
          </div>
        </div>

        <?php if (!$project): ?>
          <p class="text-muted-custom fw-bold mb-0">Please select a project above.</p>
        <?php else: ?>
          <div class="row g-3 project-kv">
            <div class="col-md-4"><small>Project</small><div><?php echo e($project['project_name']); ?></div></div>
            <div class="col-md-4"><small>Client</small><div><?php echo e($project['client_name']); ?></div></div>
            <div class="col-md-4"><small>Location</small><div><?php echo e($project['project_location']); ?></div></div>
            <div class="col-md-4"><small>Division</small><div><?php echo e($project['division_name'] ?? '-'); ?></div></div>
            <div class="col-md-4"><small>Project Type</small><div><?php echo e($project['project_type_name'] ?? '-'); ?></div></div>
            <div class="col-md-4"><small>PMS Status</small><div><?php echo e(ucwords(str_replace('_',' ', $pmsSchedule['schedule_status'] ?? '-'))); ?></div></div>
            <div class="col-md-4"><small>PMS Schedule Start</small><div><?php echo e($defaultScheduleStart); ?></div></div>
            <div class="col-md-4"><small>PMS Schedule End</small><div><?php echo e($defaultScheduleEnd); ?></div></div>
            <div class="col-md-4"><small>PMS Schedule</small><div><?php echo e($pmsSchedule['schedule_name'] ?? 'PMS Schedule'); ?></div></div>
          </div>
          <hr style="border-color:var(--border-soft);">
          <div class="project-kv">
            <small>Scope of Work</small>
            <div><?php echo e($project['scope_of_work']); ?></div>
          </div>
        <?php endif; ?>
      </div>

      <form method="POST" autocomplete="off">
        <input type="hidden" name="submit_dpr" value="1">
        <?php if ($editMode && $editId > 0): ?>
          <input type="hidden" name="edit_id" value="<?php echo (int)$editId; ?>">
        <?php endif; ?>
        <input type="hidden" name="project_id" value="<?php echo (int)$formProjectId; ?>">

        <div class="section-box">
          <div class="mini-head">
            <div class="mini-icon"><i data-lucide="file-text"></i></div>
            <div>
              <h2 class="fw-bold fs-6 mb-1">DPR Header</h2>
              <p class="text-muted-custom small mb-0">Schedule Start/End locked from PMS schedule dates.</p>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-bold small">DPR No <span class="text-danger">*</span></label>
              <input class="form-control rounded-4" name="dpr_no" value="<?php echo e($formDprNo); ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small">DPR Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control rounded-4" name="dpr_date" value="<?php echo e($formDprDate); ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small">Prepared By</label>
              <input class="form-control rounded-4" value="<?php echo e($preparedBy); ?>" readonly>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-bold small">Schedule Start</label>
              <input type="text" class="form-control rounded-4" value="<?php echo e($defaultScheduleStart); ?>" readonly>
              <input type="hidden" id="schedule_start" name="schedule_start" value="<?php echo e($defaultScheduleStart); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small">Schedule End</label>
              <input type="text" class="form-control rounded-4" value="<?php echo e($defaultScheduleEnd); ?>" readonly>
              <input type="hidden" id="schedule_end" name="schedule_end" value="<?php echo e($defaultScheduleEnd); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small">Projected</label>
              <input type="date" class="form-control rounded-4" id="schedule_projected" name="schedule_projected" value="<?php echo e($defaultProjected); ?>">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-bold small">Duration Total</label>
              <input type="number" class="form-control rounded-4" id="duration_total" name="duration_total" value="<?php echo e((string)($defTotal ?? '')); ?>" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small">Elapsed</label>
              <input type="number" class="form-control rounded-4" id="duration_elapsed" name="duration_elapsed" value="<?php echo e((string)($defElapsed ?? '')); ?>" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small">Balance</label>
              <input type="number" class="form-control rounded-4" id="duration_balance" name="duration_balance" value="<?php echo e((string)($defBalance ?? '')); ?>" readonly>
            </div>
          </div>
        </div>

        <div class="section-box">
          <div class="mini-head">
            <div class="mini-icon"><i data-lucide="cloud-sun"></i></div>
            <div>
              <h2 class="fw-bold fs-6 mb-1">Site</h2>
              <p class="text-muted-custom small mb-0">Weather and site conditions.</p>
            </div>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-bold small">Weather <span class="text-danger">*</span></label>
              <select class="form-select rounded-4" name="weather" required>
                <option value="">-- Select --</option>
                <option value="Normal" <?php echo ($editWeather === 'Normal' ? 'selected' : ''); ?>>Normal</option>
                <option value="Rainy" <?php echo ($editWeather === 'Rainy' ? 'selected' : ''); ?>>Rainy</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Site Condition <span class="text-danger">*</span></label>
              <select class="form-select rounded-4" name="site_condition" required>
                <option value="">-- Select --</option>
                <option value="Normal" <?php echo ($editSiteCondition === 'Normal' ? 'selected' : ''); ?>>Normal</option>
                <option value="Slushy" <?php echo ($editSiteCondition === 'Slushy' ? 'selected' : ''); ?>>Slushy</option>
              </select>
            </div>
          </div>
        </div>

        <?php
        function render_dpr_table($id, $title, $icon, $headers, $rows, $renderCallback, $addBtnId) {
        ?>
          <div class="section-box">
            <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-2 mb-3">
              <div class="mini-head mb-0">
                <div class="mini-icon"><i data-lucide="<?php echo e($icon); ?>"></i></div>
                <div>
                  <h2 class="fw-bold fs-6 mb-1"><?php echo e($title); ?></h2>
                  <p class="text-muted-custom small mb-0">Add as many rows as needed.</p>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary rounded-4 fw-bold add-row-btn" id="<?php echo e($addBtnId); ?>">
                <i data-lucide="plus-circle" style="width:16px;height:16px;"></i> Add Row
              </button>
            </div>
            <div class="table-responsive thin-scrollbar">
              <table class="table table-bordered align-middle mb-0 dpr-table">
                <thead>
                  <tr>
                    <?php foreach ($headers as $h): ?><th><?php echo e($h); ?></th><?php endforeach; ?>
                    <th style="width:70px;">Del</th>
                  </tr>
                </thead>
                <tbody id="<?php echo e($id); ?>">
                  <?php foreach ($rows as $row) { $renderCallback($row); } ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php } ?>

        <?php
        $mpRowsToRender = !empty($editMpRows) ? $editMpRows : [[]];
        render_dpr_table('manpowerBody', 'Manpower', 'users', ['Agency','Category','Unit','Qty','Remark'], $mpRowsToRender, function($row){
        ?>
          <tr>
            <td><input class="form-control rounded-4" name="mp_agency[]" value="<?php echo e($row['agency'] ?? ''); ?>"></td>
            <td><input class="form-control rounded-4" name="mp_category[]" value="<?php echo e($row['category'] ?? ''); ?>"></td>
            <td><input class="form-control rounded-4" name="mp_unit[]" value="<?php echo e($row['unit'] ?? ''); ?>"></td>
            <td><input class="form-control rounded-4" name="mp_qty[]" value="<?php echo e($row['qty'] ?? ''); ?>"></td>
            <td><input class="form-control rounded-4" name="mp_remark[]" value="<?php echo e($row['remark'] ?? ''); ?>"></td>
            <td class="text-center"><button type="button" class="delete-row-btn delRow"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
          </tr>
        <?php }, 'addManpower'); ?>

        <?php
        $mcRowsToRender = !empty($editMcRows) ? $editMcRows : [[]];
        render_dpr_table('machineryBody', 'Machineries', 'truck', ['Type of Equipment','Unit','Qty','Remark'], $mcRowsToRender, function($row){
        ?>
          <tr>
            <td><input class="form-control rounded-4" name="mc_equipment[]" value="<?php echo e($row['equipment'] ?? ''); ?>"></td>
            <td><input class="form-control rounded-4" name="mc_unit[]" value="<?php echo e($row['unit'] ?? ''); ?>"></td>
            <td><input class="form-control rounded-4" name="mc_qty[]" value="<?php echo e($row['qty'] ?? ''); ?>"></td>
            <td><input class="form-control rounded-4" name="mc_remark[]" value="<?php echo e($row['remark'] ?? ''); ?>"></td>
            <td class="text-center"><button type="button" class="delete-row-btn delRow"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
          </tr>
        <?php }, 'addMachinery'); ?>

        <?php
        $mtRowsToRender = !empty($editMtRows) ? $editMtRows : [[]];
        render_dpr_table('materialBody', 'Material', 'package', ['Vendor','Material','Unit','Qty','Remark'], $mtRowsToRender, function($row){
        ?>
          <tr>
            <td><input class="form-control rounded-4" name="mt_vendor[]" value="<?php echo e($row['vendor'] ?? ''); ?>"></td>
            <td><input class="form-control rounded-4" name="mt_material[]" value="<?php echo e($row['material'] ?? ''); ?>"></td>
            <td><input class="form-control rounded-4" name="mt_unit[]" value="<?php echo e($row['unit'] ?? ''); ?>"></td>
            <td><input class="form-control rounded-4" name="mt_qty[]" value="<?php echo e($row['qty'] ?? ''); ?>"></td>
            <td><input class="form-control rounded-4" name="mt_remark[]" value="<?php echo e($row['remark'] ?? ''); ?>"></td>
            <td class="text-center"><button type="button" class="delete-row-btn delRow"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
          </tr>
        <?php }, 'addMaterial'); ?>

        <div class="section-box">
          <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-2 mb-3">
            <div class="mini-head mb-0">
              <div class="mini-icon"><i data-lucide="list-checks"></i></div>
              <div>
                <h2 class="fw-bold fs-6 mb-1">Work Progress</h2>
                <p class="text-muted-custom small mb-0">Enter at least one task. End date auto-fills from duration + start date.</p>
              </div>
            </div>
            <button type="button" class="btn btn-outline-primary rounded-4 fw-bold add-row-btn" id="addWorkProgress">
              <i data-lucide="plus-circle" style="width:16px;height:16px;"></i> Add Row
            </button>
          </div>
          <div class="table-responsive thin-scrollbar">
            <table class="table table-bordered align-middle mb-0 dpr-table">
              <thead><tr><th>Task</th><th>Duration</th><th>Start</th><th>End</th><th>Status</th><th>Reasons</th></tr></thead>
              <tbody id="workProgressBody">
                <?php $wpRowsToRender = !empty($editWpRows) ? $editWpRows : [[], [], [], []]; foreach ($wpRowsToRender as $row): $wpStatus=(string)($row['status'] ?? ''); ?>
                  <tr class="wp-row">
                    <td><input class="form-control rounded-4" name="wp_task[]" value="<?php echo e($row['task'] ?? ''); ?>"></td>
                    <td><input class="form-control rounded-4 wp-duration" inputmode="numeric" name="wp_duration[]" value="<?php echo e($row['duration'] ?? ''); ?>" placeholder="e.g. 3"></td>
                    <td><input type="date" class="form-control rounded-4 wp-start" name="wp_start[]" value="<?php echo e($row['start'] ?? ''); ?>"></td>
                    <td><input type="date" class="form-control rounded-4 wp-end" name="wp_end[]" readonly value="<?php echo e($row['end'] ?? ''); ?>"></td>
                    <td>
                      <select class="form-select rounded-4" name="wp_status[]">
                        <option value="">-- Select --</option>
                        <option value="In Control" <?php echo ($wpStatus === 'In Control' ? 'selected' : ''); ?>>In Control</option>
                        <option value="Delay" <?php echo ($wpStatus === 'Delay' ? 'selected' : ''); ?>>Delay</option>
                      </select>
                    </td>
                    <td><input class="form-control rounded-4" name="wp_reasons[]" value="<?php echo e($row['reasons'] ?? ''); ?>"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <?php
        $csRowsToRender = !empty($editCsRows) ? $editCsRows : [[]];
        render_dpr_table('constraintBody', 'Constraints', 'triangle-alert', ['Issue','Status','Date','Remark'], $csRowsToRender, function($row){
          $csStatus = (string)($row['status'] ?? '');
        ?>
          <tr>
            <td><input class="form-control rounded-4" name="cs_issue[]" value="<?php echo e($row['issue'] ?? ''); ?>"></td>
            <td>
              <select class="form-select rounded-4" name="cs_status[]">
                <option value="">-- Select --</option>
                <option value="Open" <?php echo ($csStatus === 'Open' ? 'selected' : ''); ?>>Open</option>
                <option value="Closed" <?php echo ($csStatus === 'Closed' ? 'selected' : ''); ?>>Closed</option>
              </select>
            </td>
            <td><input type="date" class="form-control rounded-4" name="cs_date[]" value="<?php echo e($row['date'] ?? ''); ?>"></td>
            <td><input class="form-control rounded-4" name="cs_remark[]" value="<?php echo e($row['remark'] ?? ''); ?>"></td>
            <td class="text-center"><button type="button" class="delete-row-btn delRow"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
          </tr>
        <?php }, 'addConstraint'); ?>

        <div class="section-box">
          <div class="mini-head">
            <div class="mini-icon"><i data-lucide="send"></i></div>
            <div>
              <h2 class="fw-bold fs-6 mb-1">Report By</h2>
              <p class="text-muted-custom small mb-0">Default includes Client + Manager + Director. Editable.</p>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-bold small">Report Distribute To <span class="text-danger">*</span></label>
              <textarea class="form-control rounded-4" name="report_distribute_to" rows="2" required><?php echo e($defaultDistribute); ?></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small">Prepared By</label>
              <input class="form-control rounded-4" value="<?php echo e($preparedBy); ?>" readonly>
            </div>
          </div>

          <div class="d-flex justify-content-end mt-3">
            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4" <?php echo ($formProjectId <= 0 || $alreadySubmittedToday ? 'disabled' : ''); ?>>
              <?php echo $editMode ? 'Update DPR' : 'Submit DPR'; ?>
            </button>
          </div>

          <?php if ($formProjectId <= 0): ?>
            <div class="text-muted-custom small fw-bold mt-2">Select a project above to enable submit.</div>
          <?php elseif ($alreadySubmittedToday): ?>
            <div class="text-muted-custom small fw-bold mt-2">DPR already submitted for this project/date.</div>
          <?php endif; ?>
        </div>
      </form>

      <section class="card-ui overflow-hidden">
        <div class="p-3 p-lg-4">
          <h2 class="fw-bold fs-6 mb-1">Recent DPR</h2>
          <p class="text-muted-custom small mb-0">Your latest DPR submissions.</p>
        </div>
        <div class="px-3 px-lg-4 pb-4">
          <?php if (empty($recent)): ?>
            <p class="text-muted-custom fw-bold mb-0">No DPR submitted yet.</p>
          <?php else: ?>
            <div class="row g-2">
              <?php foreach ($recent as $r): ?>
                <div class="col-md-6 col-xl-4">
                  <div class="recent-card">
                    <div class="d-flex justify-content-between gap-2">
                      <div>
                        <div class="fw-bold"><?php echo e($r['dpr_no']); ?></div>
                        <small class="text-muted-custom fw-bold"><?php echo e($r['project_name']); ?></small>
                      </div>
                      <span class="pill green"><?php echo e(date('d M Y', strtotime($r['dpr_date']))); ?></span>
                    </div>
                    <div class="d-flex gap-2 flex-wrap mt-2">
                      <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold" target="_blank" href="reports-print/report-dpr-print.php?view=<?php echo (int)$r['id']; ?>">Print</a>
                      <?php if ((int)($r['editable_status'] ?? 2) === 1): ?>
                        <a class="btn btn-sm btn-outline-secondary rounded-4 fw-bold" href="dpr.php?edit_id=<?php echo (int)$r['id']; ?>">Edit</a>
                      <?php endif; ?>
                    </div>
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
const previousDprTemplate = <?php
  $tpl = null;
  if ($previousDprTemplate) {
    $tpl = [
      'weather' => $previousDprTemplate['weather'] ?? '',
      'site_condition' => $previousDprTemplate['site_condition'] ?? '',
      'report_distribute_to' => $previousDprTemplate['report_distribute_to'] ?? '',
      'manpower' => json_decode((string)($previousDprTemplate['manpower_json'] ?? ''), true) ?: [],
      'machinery' => json_decode((string)($previousDprTemplate['machinery_json'] ?? ''), true) ?: [],
      'material' => json_decode((string)($previousDprTemplate['material_json'] ?? ''), true) ?: [],
      'work_progress' => json_decode((string)($previousDprTemplate['work_progress_json'] ?? ''), true) ?: [],
      'constraints' => json_decode((string)($previousDprTemplate['constraints_json'] ?? ''), true) ?: []
    ];
  }
  echo json_encode($tpl, JSON_UNESCAPED_UNICODE);
?>;

document.addEventListener('DOMContentLoaded', function(){
  function icons(){ if(window.lucide && window.lucide.createIcons) window.lucide.createIcons(); }

  const picker = document.getElementById('projectPicker');
  if (picker) {
    picker.addEventListener('change', function(){
      const v = picker.value || '';
      window.location.href = v ? ('dpr.php?project_id=' + encodeURIComponent(v) + '&report_date=<?php echo e($formDprDate); ?>') : 'dpr.php';
    });
  }

  const sEl = document.getElementById('schedule_start');
  const eEl = document.getElementById('schedule_end');
  const tEl = document.getElementById('duration_total');
  const elEl = document.getElementById('duration_elapsed');
  const bEl = document.getElementById('duration_balance');

  function parseYmd(v){
    if (!v) return null;
    const parts = v.split('-');
    if (parts.length !== 3) return null;
    return new Date(parts[0], parts[1]-1, parts[2]);
  }

  function daysBetweenInclusive(a,b){
    const ms = 24*60*60*1000;
    return Math.floor((b - a) / ms) + 1;
  }

  function clamp(n, min, max){ return Math.max(min, Math.min(max, n)); }

  function recalcHeader(){
    const sd = parseYmd(sEl ? sEl.value : '');
    const ed = parseYmd(eEl ? eEl.value : '');
    if (!sd || !ed) {
      if (tEl) tEl.value = '';
      if (elEl) elEl.value = '';
      if (bEl) bEl.value = '';
      return;
    }

    let start = sd, end = ed;
    if (end < start) { const tmp = start; start = end; end = tmp; }

    const total = daysBetweenInclusive(start, end);
    const today = new Date();
    today.setHours(0,0,0,0);

    let elapsed = 0;
    if (today < start) elapsed = 0;
    else elapsed = clamp(daysBetweenInclusive(start, today), 0, total);

    const balance = Math.max(0, total - elapsed);
    if (tEl) tEl.value = total;
    if (elEl) elEl.value = elapsed;
    if (bEl) bEl.value = balance;
  }
  recalcHeader();

  function addDaysYmd(startYmd, daysToAdd){
    const d = parseYmd(startYmd);
    if (!d) return '';
    d.setDate(d.getDate() + daysToAdd);
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    return `${yyyy}-${mm}-${dd}`;
  }

  function wpRecalcRow(tr){
    const durEl = tr.querySelector('.wp-duration');
    const stEl = tr.querySelector('.wp-start');
    const endEl = tr.querySelector('.wp-end');
    if (!durEl || !stEl || !endEl) return;

    const dur = parseInt((durEl.value || '').trim(), 10);
    const st = (stEl.value || '').trim();
    if (!st || !Number.isFinite(dur) || dur <= 0) {
      endEl.value = '';
      return;
    }
    endEl.value = addDaysYmd(st, dur - 1);
  }

  function bindWpRow(tr){
    const durEl = tr.querySelector('.wp-duration');
    const stEl = tr.querySelector('.wp-start');
    if (durEl) durEl.addEventListener('input', function(){ wpRecalcRow(tr); });
    if (stEl) stEl.addEventListener('change', function(){ wpRecalcRow(tr); });
    wpRecalcRow(tr);
  }
  document.querySelectorAll('tr.wp-row').forEach(bindWpRow);

  function addRow(tbodyId, rowHtml){
    const tb = document.getElementById(tbodyId);
    if (!tb) return null;
    const html = (rowHtml || '').trim();
    if (html.toLowerCase().startsWith('<tr')) {
      tb.insertAdjacentHTML('beforeend', html);
      icons();
      return tb.lastElementChild;
    }
    const tr = document.createElement('tr');
    tr.innerHTML = html;
    tb.appendChild(tr);
    icons();
    return tr;
  }

  function clearRow(tr){
    if (!tr) return;
    tr.querySelectorAll('input,select,textarea').forEach(function(el){ el.value = ''; });
  }

  function resetRows(tbodyId){
    const tb = document.getElementById(tbodyId);
    if (!tb) return null;
    const tpl = tb.querySelector('tr');
    if (!tpl) return null;
    tb.innerHTML = '';
    return {tb, tpl};
  }

  function addTemplateRows(tbodyId, rows, filler){
    const target = resetRows(tbodyId);
    if (!target) return;
    const dataRows = Array.isArray(rows) && rows.length ? rows : [{}];

    dataRows.forEach(function(item){
      const row = target.tpl.cloneNode(true);
      clearRow(row);
      filler(row, item || {});
      target.tb.appendChild(row);
    });

    if (tbodyId === 'workProgressBody') {
      target.tb.querySelectorAll('tr.wp-row').forEach(bindWpRow);
    }
    icons();
  }

  function setSingle(name, value){
    const el = document.querySelector('[name="' + name + '"]');
    if (el && value !== undefined && value !== null && String(value).trim() !== '') {
      el.value = value;
    }
  }

  function loadPreviousDpr(){
    if (!previousDprTemplate) return;

    setSingle('weather', previousDprTemplate.weather);
    setSingle('site_condition', previousDprTemplate.site_condition);
    setSingle('report_distribute_to', previousDprTemplate.report_distribute_to);

    addTemplateRows('manpowerBody', previousDprTemplate.manpower, function(row, item){
      row.querySelector('[name="mp_agency[]"]').value = item.agency || '';
      row.querySelector('[name="mp_category[]"]').value = item.category || '';
      row.querySelector('[name="mp_unit[]"]').value = item.unit || '';
      row.querySelector('[name="mp_qty[]"]').value = item.qty || '';
      row.querySelector('[name="mp_remark[]"]').value = item.remark || '';
    });

    addTemplateRows('machineryBody', previousDprTemplate.machinery, function(row, item){
      row.querySelector('[name="mc_equipment[]"]').value = item.equipment || '';
      row.querySelector('[name="mc_unit[]"]').value = item.unit || '';
      row.querySelector('[name="mc_qty[]"]').value = item.qty || '';
      row.querySelector('[name="mc_remark[]"]').value = item.remark || '';
    });

    addTemplateRows('materialBody', previousDprTemplate.material, function(row, item){
      row.querySelector('[name="mt_vendor[]"]').value = item.vendor || '';
      row.querySelector('[name="mt_material[]"]').value = item.material || '';
      row.querySelector('[name="mt_unit[]"]').value = item.unit || '';
      row.querySelector('[name="mt_qty[]"]').value = item.qty || '';
      row.querySelector('[name="mt_remark[]"]').value = item.remark || '';
    });

    addTemplateRows('workProgressBody', previousDprTemplate.work_progress, function(row, item){
      row.querySelector('[name="wp_task[]"]').value = item.task || '';
      row.querySelector('[name="wp_duration[]"]').value = item.duration || '';
      row.querySelector('[name="wp_start[]"]').value = item.start || '';
      row.querySelector('[name="wp_end[]"]').value = item.end || '';
      row.querySelector('[name="wp_status[]"]').value = item.status || '';
      row.querySelector('[name="wp_reasons[]"]').value = item.reasons || '';
    });

    addTemplateRows('constraintBody', previousDprTemplate.constraints, function(row, item){
      row.querySelector('[name="cs_issue[]"]').value = item.issue || '';
      row.querySelector('[name="cs_status[]"]').value = item.status || '';
      row.querySelector('[name="cs_date[]"]').value = item.date || '';
      row.querySelector('[name="cs_remark[]"]').value = item.remark || '';
    });
  }

  document.getElementById('loadPreviousDprData')?.addEventListener('change', function(){
    if (this.checked) loadPreviousDpr();
  });

  document.getElementById('addManpower')?.addEventListener('click', function(){
    addRow('manpowerBody', `
      <td><input class="form-control rounded-4" name="mp_agency[]"></td>
      <td><input class="form-control rounded-4" name="mp_category[]"></td>
      <td><input class="form-control rounded-4" name="mp_unit[]"></td>
      <td><input class="form-control rounded-4" name="mp_qty[]"></td>
      <td><input class="form-control rounded-4" name="mp_remark[]"></td>
      <td class="text-center"><button type="button" class="delete-row-btn delRow"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
    `);
  });

  document.getElementById('addMachinery')?.addEventListener('click', function(){
    addRow('machineryBody', `
      <td><input class="form-control rounded-4" name="mc_equipment[]"></td>
      <td><input class="form-control rounded-4" name="mc_unit[]"></td>
      <td><input class="form-control rounded-4" name="mc_qty[]"></td>
      <td><input class="form-control rounded-4" name="mc_remark[]"></td>
      <td class="text-center"><button type="button" class="delete-row-btn delRow"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
    `);
  });

  document.getElementById('addMaterial')?.addEventListener('click', function(){
    addRow('materialBody', `
      <td><input class="form-control rounded-4" name="mt_vendor[]"></td>
      <td><input class="form-control rounded-4" name="mt_material[]"></td>
      <td><input class="form-control rounded-4" name="mt_unit[]"></td>
      <td><input class="form-control rounded-4" name="mt_qty[]"></td>
      <td><input class="form-control rounded-4" name="mt_remark[]"></td>
      <td class="text-center"><button type="button" class="delete-row-btn delRow"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
    `);
  });

  document.getElementById('addWorkProgress')?.addEventListener('click', function(){
    const tr = addRow('workProgressBody', `
      <tr class="wp-row">
        <td><input class="form-control rounded-4" name="wp_task[]"></td>
        <td><input class="form-control rounded-4 wp-duration" inputmode="numeric" name="wp_duration[]" placeholder="e.g. 3"></td>
        <td><input type="date" class="form-control rounded-4 wp-start" name="wp_start[]"></td>
        <td><input type="date" class="form-control rounded-4 wp-end" name="wp_end[]" readonly></td>
        <td>
          <select class="form-select rounded-4" name="wp_status[]">
            <option value="">-- Select --</option>
            <option value="In Control">In Control</option>
            <option value="Delay">Delay</option>
          </select>
        </td>
        <td><input class="form-control rounded-4" name="wp_reasons[]"></td>
      </tr>
    `);
    if (tr) bindWpRow(tr);
  });

  document.getElementById('addConstraint')?.addEventListener('click', function(){
    addRow('constraintBody', `
      <td><input class="form-control rounded-4" name="cs_issue[]"></td>
      <td>
        <select class="form-select rounded-4" name="cs_status[]">
          <option value="">-- Select --</option>
          <option value="Open">Open</option>
          <option value="Closed">Closed</option>
        </select>
      </td>
      <td><input type="date" class="form-control rounded-4" name="cs_date[]"></td>
      <td><input class="form-control rounded-4" name="cs_remark[]"></td>
      <td class="text-center"><button type="button" class="delete-row-btn delRow"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
    `);
  });

  document.addEventListener('click', function(ev){
    const btn = ev.target.closest('.delRow');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (tr && tr.parentNode) {
      const tb = tr.parentNode;
      if (tb.querySelectorAll('tr').length <= 1) {
        tr.querySelectorAll('input,select,textarea').forEach(function(el){ el.value = ''; });
        return;
      }
      tr.remove();
    }
  });

  icons();
});
</script>
</body>
</html>
