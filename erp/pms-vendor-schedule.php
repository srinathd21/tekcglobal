<?php
session_start();
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/vendor-helper.php";

if(empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])){ header("Location: login.php"); exit; }
vf_require($conn, "can_view", "pms-vendor-schedule.php");

function e($v){ return vf_e($v); }
function badge_class($s){ return vf_badge($s); }
function show_date($d){ return ($d && $d !== "0000-00-00") ? date("d M Y", strtotime($d)) : "-"; }
function money_show($v){ return ($v!==null && $v!=='') ? number_format((float)$v,2) : "-"; }
function remark_badge_class($urgency){
    $urgency = strtolower((string)$urgency);
    if($urgency === 'critical') return 'critical';
    if($urgency === 'urgent') return 'urgent';
    return 'normal';
}
function remark_label($urgency){
    $urgency = strtolower((string)$urgency);
    if($urgency === 'critical') return 'Critical';
    if($urgency === 'urgent') return 'Urgent';
    return 'Normal';
}

$canEdit = vf_can($conn,'can_edit','pms-vendor-schedule.php');
$canCreateVendor = vf_can($conn,'can_create','vendors.php');
$canAddAndApprove = vf_can($conn,'can_create','pms-vendor-schedule.php') && vf_can($conn,'can_edit','pms-vendor-schedule.php');

$loginEmployeeId = vf_employee_id($conn);
$assignedProjectIds = [];
if(!vf_is_super_admin($conn) && $loginEmployeeId > 0){
    $assignQ = mysqli_query($conn, "SELECT DISTINCT project_id FROM project_assignments WHERE employee_id=$loginEmployeeId AND status='active'");
    while($assignQ && ($ar=mysqli_fetch_assoc($assignQ))) $assignedProjectIds[] = (int)$ar['project_id'];
}
$hasAssignedProjects = count($assignedProjectIds) > 0;
$assignedProjectSql = $hasAssignedProjects ? implode(',', array_map('intval', $assignedProjectIds)) : '';

$pageMessageType = "";
$pageMessageText = "";
if(isset($_GET['updated'])){ $pageMessageType='success'; $pageMessageText='Vendor finalization updated successfully.'; }
elseif(isset($_GET['error'])){ $pageMessageType='error'; $pageMessageText=trim($_GET['error']); }

$qText = trim($_GET['q'] ?? '');
$projectFilter = (int)($_GET['project_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');
$dueFilter = trim($_GET['due'] ?? 'near');
$days = (int)($_GET['days'] ?? 30);
if($days <= 0) $days = 30;

$projects = [];
$q = mysqli_query($conn,"
    SELECT DISTINCT p.id,p.project_name,p.project_code
    FROM projects p
    INNER JOIN project_pmc_schedules s ON s.project_id=p.id
    INNER JOIN project_pmc_schedule_items t ON t.schedule_id=s.id AND t.project_id=p.id AND t.is_active=1
    WHERE p.deleted_at IS NULL
      " . ($hasAssignedProjects ? "AND p.id IN ($assignedProjectSql)" : "") . "
    ORDER BY p.project_name ASC
");
while($q && ($r=mysqli_fetch_assoc($q))) $projects[]=$r;

$vendors = [];
$q = mysqli_query($conn,"SELECT id,vendor_code,vendor_name,vendor_category,mobile_number,email FROM vendors WHERE deleted_at IS NULL AND vendor_status='active' ORDER BY vendor_name ASC");
while($q && ($r=mysqli_fetch_assoc($q))) $vendors[]=$r;

$where = ["task.is_active=1","task.item_type='task'","topic.is_active=1","topic.item_type='topic'","UPPER(topic.title) LIKE '%VENDOR%FINAL%'"];
if($hasAssignedProjects) $where[] = "p.id IN ($assignedProjectSql)";
if($projectFilter > 0) $where[] = "p.id=$projectFilter";
if($qText !== ''){
    $s = mysqli_real_escape_string($conn,$qText);
    $where[] = "(task.title LIKE '%$s%' OR p.project_name LIKE '%$s%' OR p.project_code LIKE '%$s%' OR v.vendor_name LIKE '%$s%')";
}
if($statusFilter !== ''){
    if($statusFilter === 'not_started') $where[] = "vf.id IS NULL";
    else $where[] = "COALESCE(vf.finalization_status,'pending')='".mysqli_real_escape_string($conn,$statusFilter)."'";
}
if($dueFilter === 'near'){
    $where[] = "task.planned_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $days DAY)";
} elseif($dueFilter === 'overdue') {
    $where[] = "task.planned_end_date < CURDATE() AND COALESCE(vf.finalization_status,'pending') <> 'finalized'";
} elseif($dueFilter === 'all_open') {
    $where[] = "COALESCE(vf.finalization_status,'pending') <> 'finalized'";
}
$whereSql = implode(" AND ", $where);

$rows = [];
$q = mysqli_query($conn,"
    SELECT
        task.id AS pms_item_id,
        task.schedule_id,
        task.project_id,
        task.title AS package_title,
        task.description,
        task.planned_start_date,
        task.planned_end_date,
        task.duration_days,
        task.progress_percent,
        task.item_status,
        topic.title AS topic_title,
        s.schedule_name,
        p.project_name,
        p.project_code,
        p.project_location,
        c.client_name,
        vf.id AS finalization_id,
        vf.vendor_id,
        vf.selected_date,
        vf.quotation_amount,
        vf.final_amount,
        vf.comparison_notes,
        vf.finalization_status,
        vf.remarks,
        vf.finalized_by,
        vf.finalized_at,
        (SELECT COUNT(*) FROM project_vendor_finalization_remarks vr WHERE vr.pms_item_id=task.id) AS remark_count,
        (SELECT vr.remark FROM project_vendor_finalization_remarks vr WHERE vr.pms_item_id=task.id ORDER BY vr.created_at DESC, vr.id DESC LIMIT 1) AS latest_remark,
        (SELECT vr.urgency FROM project_vendor_finalization_remarks vr WHERE vr.pms_item_id=task.id ORDER BY vr.created_at DESC, vr.id DESC LIMIT 1) AS latest_remark_urgency,
        (SELECT vr.created_at FROM project_vendor_finalization_remarks vr WHERE vr.pms_item_id=task.id ORDER BY vr.created_at DESC, vr.id DESC LIMIT 1) AS latest_remark_at,
        (SELECT COALESCE(e.full_name,u.username)
         FROM project_vendor_finalization_remarks vr
         LEFT JOIN users u ON u.id=vr.created_by
         LEFT JOIN employees e ON e.id=u.employee_id
         WHERE vr.pms_item_id=task.id
         ORDER BY vr.created_at DESC, vr.id DESC
         LIMIT 1) AS latest_remark_by,
        (SELECT COUNT(*) FROM project_vendor_finalization_files vf2 WHERE vf2.finalization_id=vf.id) AS file_count,
        v.vendor_code,
        v.vendor_name,
        v.vendor_category,
        v.mobile_number AS vendor_mobile,
        v.email AS vendor_email
    FROM project_pmc_schedule_items task
    INNER JOIN project_pmc_schedule_items topic ON topic.id=task.parent_id
    INNER JOIN project_pmc_schedules s ON s.id=task.schedule_id
    INNER JOIN projects p ON p.id=task.project_id
    LEFT JOIN clients c ON c.id=p.client_id
    LEFT JOIN project_vendor_finalizations vf ON vf.pms_item_id=task.id
    LEFT JOIN vendors v ON v.id=vf.vendor_id
    WHERE $whereSql
    ORDER BY task.planned_end_date ASC, p.project_name ASC, task.sort_order ASC
");
while($q && ($r=mysqli_fetch_assoc($q))) $rows[]=$r;

$stats = ['total'=>count($rows),'overdue'=>0,'near'=>0,'finalized'=>0,'pending'=>0];
foreach($rows as $r){
    $status = $r['finalization_status'] ?: 'pending';
    if($status === 'finalized') $stats['finalized']++;
    else $stats['pending']++;
    if(!empty($r['planned_end_date'])){
        $today = strtotime(date('Y-m-d'));
        $due = strtotime($r['planned_end_date']);
        if($status !== 'finalized' && $due < $today) $stats['overdue']++;
        if($status !== 'finalized' && $due >= $today && $due <= strtotime("+$days days")) $stats['near']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>PMS Vendor Schedule</title>
  <?php include("includes/links.php"); ?>
  <style>
    .page-head-card,.filter-card,.section-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
    .kpi-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:18px;display:flex;align-items:center;gap:14px;height:100%}
    .kpi-icon{width:50px;height:50px;border-radius:18px;display:flex;align-items:center;justify-content:center}
    .status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:5px 11px;font-size:12px;font-weight:900}
    .status-pill.green{background:rgba(16,185,129,.12);color:#059669}
    .status-pill.amber{background:rgba(245,158,11,.14);color:#b45309}
    .status-pill.red{background:rgba(239,68,68,.12);color:#dc2626}
    .status-pill.blue{background:rgba(37,99,235,.10);color:#2563eb}
    .remark-chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:900}
    .remark-chip.normal{background:rgba(37,99,235,.10);color:#2563eb}
    .remark-chip.urgent{background:rgba(245,158,11,.14);color:#b45309}
    .remark-chip.critical{background:rgba(239,68,68,.12);color:#dc2626}
    .remark-preview{margin-top:8px;border-left:3px solid rgba(245,158,11,.75);padding-left:9px;color:var(--text-muted);font-size:12px;font-weight:700;line-height:1.45;max-width:150px}
    .remark-by{font-size:11px;color:var(--text-muted);font-weight:800;margin-top:4px}
    .remark-list-wrap{max-height:360px;overflow:auto;padding-right:4px}
    .remark-item{border:1px solid var(--border-soft);border-radius:16px;padding:12px;background:rgba(148,163,184,.05);margin-bottom:10px}
    .remark-item.urgent{background:rgba(245,158,11,.06);border-color:rgba(245,158,11,.30)}
    .remark-item.critical{background:rgba(239,68,68,.06);border-color:rgba(239,68,68,.30)}
    .remark-message{font-weight:800;color:var(--text-main);line-height:1.45;white-space:pre-wrap}
    .remark-meta{color:var(--text-muted);font-size:12px;font-weight:800;margin-top:6px}
    .vf-table{min-width:1400px;table-layout:fixed}
    .vf-table th,.vf-table td{vertical-align:middle;padding:13px 10px}
    .vf-table th{font-size:12px;white-space:nowrap}
    .vf-title{font-weight:900;color:var(--text-main);line-height:1.35}
    .vf-muted{color:var(--text-muted);font-size:12px;font-weight:700;line-height:1.45}
    .modal-content{background:var(--card-bg);color:var(--text-main);border:1px solid var(--border-soft);border-radius:24px;box-shadow:var(--shadow-card)}
    .modal-header,.modal-footer{border-color:var(--border-soft)}
    .due-overdue{background:rgba(239,68,68,.06)}
    .due-near{background:rgba(245,158,11,.06)}
    .vf-action-stack{display:flex;flex-direction:column;gap:7px;align-items:center}
    .vf-action-stack .btn{min-width:108px;justify-content:center}
    .vf-table{min-width:1280px!important;table-layout:fixed}
    .vf-table th,.vf-table td{vertical-align:middle!important;padding:14px 12px!important}
    .vf-table th{font-size:12px!important;white-space:nowrap;text-align:left}
    .vf-table .col-package{width:260px}
    .vf-table .col-start{width:130px}
    .vf-table .col-required{width:130px}
    .vf-table .col-vendor{width:185px}
    .vf-table .col-amount{width:140px}
    .vf-table .col-status{width:125px}
    .vf-table .col-remarks{width:160px}
    .vf-table .col-action{width:135px}
    .vf-table .remarks-cell{word-break:break-word;line-height:1.45}
    .vf-table .amount-cell{line-height:1.65}
    .vf-title{font-size:13px}
    .vf-muted{font-size:12px}
    .vendor-section-header{padding:4px 8px 14px}
    .desktop-vf-table{border-radius:18px;overflow:auto}
    .desktop-vf-table table{border-collapse:separate;border-spacing:0}
    .desktop-vf-table thead th:first-child{border-radius:16px 0 0 16px}
    .desktop-vf-table thead th:last-child{border-radius:0 16px 16px 0}
    .mobile-vf-list{display:none}
    .mobile-vf-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:20px;box-shadow:var(--shadow-card);padding:14px;margin-bottom:12px}
    .mobile-vf-row{display:grid;grid-template-columns:120px 1fr;gap:8px;font-size:13px;margin-top:8px}
    .mobile-vf-row .label{color:var(--text-muted);font-weight:900}
    .mobile-vf-row .value{font-weight:700;color:var(--text-main);word-break:break-word}
    @media(max-width:767px){
      .desktop-vf-table{display:none!important}
      .mobile-vf-list{display:block}
      .page-section{padding:12px!important}
      .page-head-card,.filter-card,.section-card{border-radius:18px;padding:14px}
      .kpi-card{padding:14px;border-radius:18px;gap:10px}
      .kpi-icon{width:42px;height:42px;border-radius:15px}
      .kpi-card .h4{font-size:20px}
      .modal-dialog{margin:8px}
      .modal-content{border-radius:18px}
    }
  </style>
</head>
<body>
<div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>
<?php if(file_exists(__DIR__."/includes/page-message.php")) include("includes/page-message.php"); ?>

<div class="min-vh-100 d-flex">
  <?php include("includes/sidebar.php"); ?>
  <main id="main">
    <?php include("includes/nav.php"); ?>
    <section class="page-section p-3 p-lg-3">
      <div class="page-head-card mb-3">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
          <div>
            <h1 class="h4 fw-bold mb-1">PMS Vendor Schedule</h1>
            <p class="text-muted-custom mb-0 small">Vendor finalization tracker from PMS/PMC schedule topic: VENDORS FINALIZATIONS.</p>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <a href="vendors.php" class="btn btn-outline-primary rounded-4 fw-bold btn-sm">Vendors</a>
          </div>
        </div>
      </div>

      <?php if($pageMessageText): ?><div class="alert <?= $pageMessageType==='success'?'alert-success':'alert-danger' ?> rounded-4 fw-bold"><?= e($pageMessageText) ?></div><?php endif; ?>

      <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-primary-subtle text-primary"><i data-lucide="calendar-check"></i></div><div><div class="text-muted-custom small fw-bold">Packages</div><div class="h4 fw-bold mb-0"><?= (int)$stats['total'] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-warning-subtle text-warning"><i data-lucide="clock"></i></div><div><div class="text-muted-custom small fw-bold">Near Due</div><div class="h4 fw-bold mb-0"><?= (int)$stats['near'] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-danger-subtle text-danger"><i data-lucide="alert-triangle"></i></div><div><div class="text-muted-custom small fw-bold">Overdue</div><div class="h4 fw-bold mb-0"><?= (int)$stats['overdue'] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-success-subtle text-success"><i data-lucide="check-circle"></i></div><div><div class="text-muted-custom small fw-bold">Finalized</div><div class="h4 fw-bold mb-0"><?= (int)$stats['finalized'] ?></div></div></div></div>
      </div>

      <form class="filter-card mb-3" method="GET" action="pms-vendor-schedule.php#vendor-packages-section">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-lg-3"><label class="form-label fw-bold small">Search</label><input class="form-control rounded-4" name="q" value="<?= e($qText) ?>" placeholder="Project, package, vendor"></div>
          <div class="col-12 col-lg-3"><label class="form-label fw-bold small">Project</label><select class="form-select rounded-4" name="project_id"><option value="0">All Projects</option><?php foreach($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $projectFilter===(int)$p['id']?'selected':'' ?>><?= e($p['project_name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-12 col-lg-2"><label class="form-label fw-bold small">Due</label><select class="form-select rounded-4" name="due"><option value="near" <?= $dueFilter==='near'?'selected':'' ?>>Near Due</option><option value="overdue" <?= $dueFilter==='overdue'?'selected':'' ?>>Overdue</option><option value="all_open" <?= $dueFilter==='all_open'?'selected':'' ?>>All Open</option><option value="all" <?= $dueFilter==='all'?'selected':'' ?>>All</option></select></div>
          <div class="col-12 col-lg-2"><label class="form-label fw-bold small">Status</label><select class="form-select rounded-4" name="status"><option value="">All</option><option value="not_started" <?= $statusFilter==='not_started'?'selected':'' ?>>Not Started</option><?php foreach(['pending','shortlisted','negotiation','finalized','rejected','cancelled'] as $s): ?><option value="<?= e($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= e(ucwords($s)) ?></option><?php endforeach; ?></select></div>
          <div class="col-12 col-lg-2 d-flex gap-2"><button class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button><a href="pms-vendor-schedule.php#vendor-packages-section" class="btn btn-outline-secondary rounded-4 fw-bold">Reset</a></div>
        </div>
      </form>

      <section class="section-card overflow-hidden" id="vendor-packages-section">
        <div class="d-flex justify-content-between align-items-center vendor-section-header">
          <div><h2 class="fw-bold fs-6 mb-1">Vendor Finalization Packages</h2><p class="text-muted-custom small mb-0">Rows are taken from PMS topic VENDORS FINALIZATIONS and its child task rows.</p></div>
        </div>
        <div class="overflow-auto thin-scrollbar desktop-vf-table">
          <table class="project-table vf-table w-100">
            <thead>
              <tr>
                <th class="col-package">Vendor Package</th>
                <th class="col-start">Start</th>
                <th class="col-required">Required By</th>
                <th class="col-vendor">Selected Vendor</th>
                <th class="col-amount">Amount</th>
                <th class="col-status">Status</th>
                <th class="col-remarks">Remarks</th>
                <th class="col-action">Action</th>
              </tr>
            </thead>
            <tbody>
            <?php if(!$rows): ?><tr><td colspan="8" class="text-center text-muted-custom fw-bold py-4">No vendor finalization packages found.</td></tr><?php endif; ?>
            <?php foreach($rows as $r): ?>
              <?php
                $st = $r['finalization_status'] ?: 'pending';
                $rowClass = '';
                if($st !== 'finalized' && !empty($r['planned_end_date'])){
                    if(strtotime($r['planned_end_date']) < strtotime(date('Y-m-d'))) $rowClass='due-overdue';
                    elseif(strtotime($r['planned_end_date']) <= strtotime("+$days days")) $rowClass='due-near';
                }
              ?>
              <tr class="<?= e($rowClass) ?>">
                <td>
                  <div class="vf-title"><?= e($r['package_title']) ?></div>
                  <div class="vf-muted"><?= e($r['topic_title']) ?> · <?= e($r['schedule_name']) ?></div>
                  <div class="vf-muted mt-1"><?= e($r['project_name']) ?><?= $r['client_name'] ? ' · '.e($r['client_name']) : '' ?></div>
                </td>
                <td><?= e(show_date($r['planned_start_date'])) ?></td>
                <td><b><?= e(show_date($r['planned_end_date'])) ?></b><br><small class="text-muted-custom"><?= (int)$r['duration_days'] ?> days</small></td>
                <td><?php if($r['vendor_name']): ?><div class="fw-bold"><?= e($r['vendor_name']) ?></div><small class="text-muted-custom"><?= e($r['vendor_category'] ?: '-') ?><br><?= e($r['vendor_mobile'] ?: '-') ?></small><?php else: ?><span class="text-muted-custom">Not selected</span><?php endif; ?></td>
                <td><div class="vf-muted amount-cell"><b>Quote:</b> <?= money_show($r['quotation_amount']) ?><br><b>Final:</b> <?= money_show($r['final_amount']) ?></div></td>
                <td><span class="status-pill <?= badge_class($st) ?>"><?= e(ucwords($st)) ?></span></td>
                <td>
                  <?php if(!empty($r['latest_remark'])): ?>
                    <span class="remark-chip <?= e(remark_badge_class($r['latest_remark_urgency'])) ?>"><?= e(remark_label($r['latest_remark_urgency'])) ?></span>
                    <div class="remark-preview"><?= e(strlen($r['latest_remark']) > 90 ? substr($r['latest_remark'], 0, 90) . '...' : $r['latest_remark']) ?></div>
                    <div class="remark-by">By <?= e($r['latest_remark_by'] ?: '-') ?></div>
                  <?php else: ?>
                    <div class="vf-muted remarks-cell"><?= e($r['remarks'] ?: $r['comparison_notes'] ?: '-') ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="vf-action-stack">
                    <button class="btn btn-sm btn-outline-warning rounded-4 fw-bold remarkBtn" data-row='<?= e(json_encode($r)) ?>' data-bs-toggle="modal" data-bs-target="#remarkModal">
                      View / Add Remark <?= !empty($r['remark_count']) ? '(' . (int)$r['remark_count'] . ')' : '' ?>
                    </button>
                    <?php if($canAddAndApprove): ?>
                      <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold" href="vendor-update.php?pms_item_id=<?= (int)$r['pms_item_id'] ?>">
                        Update
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="mobile-vf-list">
          <?php if(!$rows): ?>
            <div class="text-center text-muted-custom fw-bold py-4">No vendor finalization packages found.</div>
          <?php endif; ?>

          <?php foreach($rows as $r): ?>
            <?php
              $st = $r['finalization_status'] ?: 'pending';
              $rowClass = '';
              if($st !== 'finalized' && !empty($r['planned_end_date'])){
                  if(strtotime($r['planned_end_date']) < strtotime(date('Y-m-d'))) $rowClass='due-overdue';
                  elseif(strtotime($r['planned_end_date']) <= strtotime("+$days days")) $rowClass='due-near';
              }
            ?>
            <div class="mobile-vf-card <?= e($rowClass) ?>">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                  <div class="vf-title"><?= e($r['package_title']) ?></div>
                  <div class="vf-muted"><?= e($r['project_name']) ?><br><?= e($r['project_code'] ?: '-') ?></div>
                </div>
                <span class="status-pill <?= badge_class($st) ?>"><?= e(ucwords($st)) ?></span>
              </div>

              <div class="mobile-vf-row"><div class="label">Client</div><div class="value"><?= e($r['client_name'] ?: '-') ?></div></div>
              <div class="mobile-vf-row"><div class="label">Required By</div><div class="value"><?= e(show_date($r['planned_end_date'])) ?> · <?= (int)$r['duration_days'] ?> days</div></div>
              <div class="mobile-vf-row"><div class="label">Vendor</div><div class="value"><?= e($r['vendor_name'] ?: 'Not selected') ?></div></div>
              <div class="mobile-vf-row"><div class="label">Amount</div><div class="value">Quote: <?= money_show($r['quotation_amount']) ?><br>Final: <?= money_show($r['final_amount']) ?></div></div>
              <div class="mobile-vf-row"><div class="label">Files</div><div class="value"><?= (int)($r['file_count'] ?? 0) ?> file(s)</div></div>
              <div class="mobile-vf-row">
                <div class="label">Remark</div>
                <div class="value">
                  <?php if(!empty($r['latest_remark'])): ?>
                    <span class="remark-chip <?= e(remark_badge_class($r['latest_remark_urgency'])) ?>"><?= e(remark_label($r['latest_remark_urgency'])) ?></span>
                    <div class="mt-1"><?= e($r['latest_remark']) ?></div>
                    <small class="text-muted-custom fw-bold">By <?= e($r['latest_remark_by'] ?: '-') ?></small>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </div>
              </div>

              <div class="vf-action-stack mt-3">
                <button class="btn btn-sm btn-outline-warning rounded-4 fw-bold remarkBtn" data-row='<?= e(json_encode($r)) ?>' data-bs-toggle="modal" data-bs-target="#remarkModal">
                  View / Add Remark <?= !empty($r['remark_count']) ? '(' . (int)$r['remark_count'] . ')' : '' ?>
                </button>
                <?php if($canAddAndApprove): ?>
                  <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold" href="vendor-update.php?pms_item_id=<?= (int)$r['pms_item_id'] ?>">Update</a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <?php include("includes/footer.php"); ?>
    </section>
  </main>
  <div id="settingsOverlay"></div>
  <?php if(file_exists(__DIR__."/includes/rightsidbar.php")) include("includes/rightsidbar.php"); ?>
</div>



<div class="modal fade" id="remarkModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST" action="api/process-vendor.php">
        <input type="hidden" name="action" value="save_vendor_remark">
        <input type="hidden" name="pms_item_id" id="remarkPmsItemId">
        <input type="hidden" name="project_id" id="remarkProjectId">
        <input type="hidden" name="schedule_id" id="remarkScheduleId">
        <input type="hidden" name="package_title" id="remarkPackageTitleHidden">

        <div class="modal-header">
          <div>
            <h5 class="modal-title fw-bold mb-1">Vendor Remarks</h5>
            <div class="text-muted-custom small fw-bold" id="remarkPackageTitle">Vendor Package</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="mb-3">
            <h6 class="fw-bold mb-2">Posted Remarks</h6>
            <div class="remark-list-wrap" id="remarkListBox">
              <div class="text-muted-custom fw-bold">No remarks posted yet.</div>
            </div>
          </div>

          <hr>

          <h6 class="fw-bold mb-2">Add New Remark</h6>
          <label class="form-label fw-bold small">Urgency / Status</label>
          <select class="form-select rounded-4 mb-3" name="urgency" required>
            <option value="normal">Normal</option>
            <option value="urgent">Urgent</option>
            <option value="critical">Critical</option>
          </select>

          <label class="form-label fw-bold small">Remark Message</label>
          <textarea class="form-control rounded-4" name="remark" rows="4" placeholder="Enter vendor finalization remark / urgency reason" required></textarea>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Close</button>
          <button class="btn btn-warning rounded-4 fw-bold">Save Remark</button>
        </div>
      </form>
    </div>
  </div>
</div>


<?php include("includes/script.php"); ?>
<script src="assets/js/script.js?v=41"></script>
<script>
document.addEventListener("DOMContentLoaded", function(){
  if(window.lucide && window.lucide.createIcons) window.lucide.createIcons();

  const section = document.getElementById("vendor-packages-section");
  const params = new URLSearchParams(window.location.search);
  if(section && (window.location.hash === "#vendor-packages-section" || params.has("q") || params.has("project_id") || params.has("due") || params.has("status"))){
    setTimeout(() => section.scrollIntoView({behavior:"smooth", block:"start"}), 120);
  }

  function escapeHtml(value){
    return String(value ?? "")
      .replaceAll("&","&amp;")
      .replaceAll("<","&lt;")
      .replaceAll(">","&gt;")
      .replaceAll('"',"&quot;")
      .replaceAll("'","&#039;");
  }

  function urgencyClass(urgency){
    urgency = String(urgency || "normal").toLowerCase();
    if(urgency === "critical") return "critical";
    if(urgency === "urgent") return "urgent";
    return "normal";
  }

  function urgencyLabel(urgency){
    urgency = String(urgency || "normal").toLowerCase();
    if(urgency === "critical") return "Critical";
    if(urgency === "urgent") return "Urgent";
    return "Normal";
  }

  function renderRemarks(list){
    const box = document.getElementById("remarkListBox");
    if(!box) return;

    if(!Array.isArray(list) || list.length === 0){
      box.innerHTML = '<div class="text-muted-custom fw-bold">No remarks posted yet.</div>';
      return;
    }

    box.innerHTML = list.map(item => {
      const cls = urgencyClass(item.urgency);
      return `
        <div class="remark-item ${cls}">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <span class="remark-chip ${cls}">${urgencyLabel(item.urgency)}</span>
            <small class="text-muted-custom fw-bold">${escapeHtml(item.created_at_label || item.created_at || "-")}</small>
          </div>
          <div class="remark-message mt-2">${escapeHtml(item.remark || "-")}</div>
          <div class="remark-meta">Posted by: ${escapeHtml(item.employee_name || item.created_by_name || "-")}</div>
        </div>
      `;
    }).join("");
  }

  document.querySelectorAll(".remarkBtn").forEach(btn=>{
    btn.addEventListener("click",()=>{
      const r = JSON.parse(btn.dataset.row || "{}");
      const pmsItemId = r.pms_item_id || "";

      document.getElementById("remarkPmsItemId").value = pmsItemId;
      document.getElementById("remarkProjectId").value = r.project_id || "";
      document.getElementById("remarkScheduleId").value = r.schedule_id || "";
      document.getElementById("remarkPackageTitleHidden").value = r.package_title || "";
      document.getElementById("remarkPackageTitle").textContent = (r.project_name || "") + " - " + (r.package_title || "");

      const listBox = document.getElementById("remarkListBox");
      if(listBox) listBox.innerHTML = '<div class="text-muted-custom fw-bold">Loading remarks...</div>';

      fetch("api/process-vendor.php?action=list_vendor_remarks&pms_item_id=" + encodeURIComponent(pmsItemId), {
        headers: {"Accept":"application/json"}
      })
      .then(res => res.json())
      .then(data => {
        if(data && data.success) renderRemarks(data.remarks || []);
        else renderRemarks([]);
      })
      .catch(() => renderRemarks([]));
    });
  });
});
</script>
</body>
</html>
