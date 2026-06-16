<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';
date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

function sat_e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function sat_employee_id(mysqli $conn): int {
    if (!empty($_SESSION['employee_id'])) return (int)$_SESSION['employee_id'];
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $q = mysqli_query($conn, "SELECT employee_id FROM users WHERE id=$uid LIMIT 1");
        if ($q && ($r=mysqli_fetch_assoc($q)) && !empty($r['employee_id'])) {
            $_SESSION['employee_id']=(int)$r['employee_id'];
            return (int)$r['employee_id'];
        }
    }
    return 0;
}

function sat_is_super_admin(mysqli $conn): bool {
    $uid=(int)($_SESSION['user_id']??0);
    if ($uid<=0) return false;
    $q=mysqli_query($conn,"SELECT r.id FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id
        WHERE ur.user_id=$uid AND r.is_active=1
        AND (r.role_slug='super-admin' OR LOWER(r.role_name)='super admin') LIMIT 1");
    return $q && mysqli_num_rows($q)>0;
}

function sat_report_access(mysqli $conn,string $col): bool {
    if (sat_is_super_admin($conn)) return true;
    $allowed=['can_submit','can_view','can_remark_tl','can_remark_manager'];
    if (!in_array($col,$allowed,true)) return false;
    $uid=(int)($_SESSION['user_id']??0);
    $q=mysqli_query($conn,"SELECT MAX(COALESCE(rta.$col,0)) ok
        FROM user_roles ur
        INNER JOIN report_type_role_access rta ON rta.role_id=ur.role_id
        INNER JOIN master_report_types rt ON rt.id=rta.report_type_id
        WHERE ur.user_id=$uid AND rt.report_code='SAT' AND rt.is_active=1");
    return $q && ($r=mysqli_fetch_assoc($q)) && (int)($r['ok']??0)===1;
}

function sat_project_allowed(mysqli $conn,int $projectId,int $employeeId,bool $super): bool {
    if ($super) return true;
    $q=mysqli_query($conn,"SELECT p.id FROM projects p WHERE p.id=$projectId AND p.deleted_at IS NULL
        AND (p.manager_employee_id=$employeeId OR p.team_lead_employee_id=$employeeId OR EXISTS(
            SELECT 1 FROM project_assignments pa
            WHERE pa.project_id=p.id AND pa.employee_id=$employeeId AND pa.status='active'
        )) LIMIT 1");
    return $q && mysqli_num_rows($q)>0;
}

function sat_columns(mysqli $conn,string $table): array {
    $table=preg_replace('/[^A-Za-z0-9_]/','',$table);
    $out=[];$q=mysqli_query($conn,"SHOW COLUMNS FROM `$table`");
    while($q&&($r=mysqli_fetch_assoc($q)))$out[$r['Field']]=true;
    return $out;
}

function sat_sql(mysqli $conn,$v): string {
    if ($v===null) return 'NULL';
    if (is_int($v)||is_float($v)) return (string)$v;
    return "'".mysqli_real_escape_string($conn,(string)$v)."'";
}

function sat_report_type_id(mysqli $conn): int {
    $q=mysqli_query($conn,"SELECT id FROM master_report_types WHERE report_code='SAT' LIMIT 1");
    return ($q&&($r=mysqli_fetch_assoc($q)))?(int)$r['id']:0;
}

function sat_sync_submission(mysqli $conn,int $submissionId,int $projectId,int $employeeId,string $date,int $satId,string $satNo): void {
    $rt=sat_report_type_id($conn); if($rt<=0)return;
    $cols=sat_columns($conn,'project_report_submissions');
    $uid=(int)($_SESSION['user_id']??0);
    $dateEsc=mysqli_real_escape_string($conn,$date);
    if($submissionId<=0){
        $q=mysqli_query($conn,"SELECT id FROM project_report_submissions
            WHERE project_id=$projectId AND report_type_id=$rt AND submission_for_date='$dateEsc'
            ORDER BY id DESC LIMIT 1");
        if($q&&($r=mysqli_fetch_assoc($q)))$submissionId=(int)$r['id'];
    }
    $map=[
        'report_no'=>$satNo,'report_number'=>$satNo,'submission_no'=>$satNo,
        'title'=>'Samples Approval Tracker',
        'submitted_by_employee_id'=>$employeeId,'submitted_by_user_id'=>$uid?:null,
        'submission_for_date'=>$date,'period_start'=>$date,'period_end'=>$date,
        'status'=>'submitted','submitted_at'=>date('Y-m-d H:i:s'),
        'source_table'=>'sat_reports','source_id'=>$satId,
        'report_reference_table'=>'sat_reports','report_reference_id'=>$satId,'reference_id'=>$satId,
        'updated_by'=>$uid?:null,'updated_at'=>date('Y-m-d H:i:s')
    ];
    if($submissionId>0){
        $sets=[]; foreach($map as $f=>$v) if(isset($cols[$f])) $sets[]="`$f`=".sat_sql($conn,$v);
        if($sets) mysqli_query($conn,"UPDATE project_report_submissions SET ".implode(',',$sets)." WHERE id=$submissionId");
        return;
    }
    $data=array_merge(['project_id'=>$projectId,'report_type_id'=>$rt,'created_by'=>$uid?:null,'created_at'=>date('Y-m-d H:i:s')],$map);
    $ic=[];$iv=[]; foreach($data as $f=>$v) if(isset($cols[$f])){$ic[]="`$f`";$iv[]=sat_sql($conn,$v);}
    if($ic) mysqli_query($conn,"INSERT INTO project_report_submissions(".implode(',',$ic).") VALUES(".implode(',',$iv).")");
}

function sat_find_existing(mysqli $conn,int $submissionId,int $projectId,int $employeeId,string $date): int {
    if($submissionId>0){
        $cols=sat_columns($conn,'project_report_submissions');
        $sel=['id']; foreach(['report_reference_id','source_id','reference_id'] as $c) if(isset($cols[$c]))$sel[]=$c;
        $q=mysqli_query($conn,"SELECT ".implode(',',$sel)." FROM project_report_submissions WHERE id=$submissionId LIMIT 1");
        if($q&&($s=mysqli_fetch_assoc($q))){
            foreach(['report_reference_id','source_id','reference_id'] as $c){
                $id=(int)($s[$c]??0); if($id>0){
                    $x=mysqli_query($conn,"SELECT id FROM sat_reports WHERE id=$id LIMIT 1");
                    if($x&&mysqli_num_rows($x)>0)return $id;
                }
            }
        }
    }
    $d=mysqli_real_escape_string($conn,$date);
    $q=mysqli_query($conn,"SELECT id FROM sat_reports WHERE project_id=$projectId AND employee_id=$employeeId AND report_date='$d' ORDER BY id DESC LIMIT 1");
    return ($q&&($r=mysqli_fetch_assoc($q)))?(int)$r['id']:0;
}

function sat_log(mysqli $conn, string $type, string $desc, int $ref): void
{
    $eid = sat_employee_id($conn);
    $name = (string)($_SESSION['employee_name'] ?? $_SESSION['name'] ?? '');
    $user = (string)($_SESSION['username'] ?? '');
    $des = (string)($_SESSION['designation'] ?? '');
    $dep = (string)($_SESSION['department'] ?? '');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $type = strtoupper($type);

    $st = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (
            employee_id, employee_name, username, designation, department,
            activity_type, module, description, reference_id, ip_address
        )
        VALUES (?, ?, ?, ?, ?, ?, 'SAT', ?, ?, ?)
    ");

    if (!$st) {
        return;
    }

    mysqli_stmt_bind_param(
        $st,
        'issssssis',
        $eid,
        $name,
        $user,
        $des,
        $dep,
        $type,
        $desc,
        $ref,
        $ip
    );

    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
}

function sat_redirect(int $projectId,string $date,string $flag): void {
    header('Location: reports-hub.php?project_id='.$projectId.'&report_date='.urlencode($date).'&period_start='.urlencode($date).'&period_end='.urlencode($date).'&'.$flag.'=1');
    exit;
}

$employeeId=sat_employee_id($conn);
$super=sat_is_super_admin($conn);
if($employeeId<=0){header('Location: login.php');exit;}
if(!sat_report_access($conn,'can_submit')){header('Location: reports-hub.php?error='.urlencode('You do not have SAT submit access.'));exit;}

$empQ=mysqli_query($conn,"SELECT * FROM employees WHERE id=$employeeId LIMIT 1");
$emp=$empQ?mysqli_fetch_assoc($empQ):null;
$preparedBy=(string)($emp['full_name']??$_SESSION['employee_name']??$_SESSION['name']??'');

$projectId=(int)($_GET['project_id']??$_POST['project_id']??0);
$reportDate=trim((string)($_GET['report_date']??$_POST['report_date']??date('Y-m-d')));
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$reportDate))$reportDate=date('Y-m-d');
$resubmitId=(int)($_GET['resubmit_submission_id']??$_POST['resubmit_submission_id']??0);

$projects=[];
if($super){
    $pq=mysqli_query($conn,"SELECT p.id,p.project_name,p.project_code,p.project_location,c.client_name FROM projects p LEFT JOIN clients c ON c.id=p.client_id WHERE p.deleted_at IS NULL ORDER BY p.project_name");
}else{
    $pq=mysqli_query($conn,"SELECT DISTINCT p.id,p.project_name,p.project_code,p.project_location,c.client_name FROM projects p
        LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN project_assignments pa ON pa.project_id=p.id AND pa.status='active'
        WHERE p.deleted_at IS NULL AND (p.manager_employee_id=$employeeId OR p.team_lead_employee_id=$employeeId OR pa.employee_id=$employeeId)
        ORDER BY p.project_name");
}
while($pq&&($r=mysqli_fetch_assoc($pq)))$projects[]=$r;

$project=null;
if($projectId>0&&sat_project_allowed($conn,$projectId,$employeeId,$super)){
    $q=mysqli_query($conn,"SELECT p.*,c.client_name,c.company_name,mpt.project_type_name
        FROM projects p LEFT JOIN clients c ON c.id=p.client_id
        LEFT JOIN master_project_types mpt ON mpt.id=p.project_type_id
        WHERE p.id=$projectId AND p.deleted_at IS NULL LIMIT 1");
    if($q)$project=mysqli_fetch_assoc($q);
}
$pmsSchedule=$projectId>0?pms_project_schedule($conn,$projectId):null;
[$pmsStart,$pmsEnd]=pms_schedule_date_range($pmsSchedule,$project);

$defaultNo='';
if($projectId>0){
    $prefix='SAT/'.$projectId.'/'.date('Ym',strtotime($reportDate)).'/';
    $pe=mysqli_real_escape_string($conn,$prefix);
    $q=mysqli_query($conn,"SELECT COUNT(*) c FROM sat_reports WHERE sat_no LIKE '$pe%'");
    $n=$q?(int)(mysqli_fetch_assoc($q)['c']??0):0;
    $defaultNo=$prefix.str_pad((string)($n+1),3,'0',STR_PAD_LEFT);
}

$existing=null;
if($resubmitId>0){
    $id=sat_find_existing($conn,$resubmitId,$projectId,$employeeId,$reportDate);
    if($id>0){$q=mysqli_query($conn,"SELECT * FROM sat_reports WHERE id=$id LIMIT 1");$existing=$q?mysqli_fetch_assoc($q):null;}
}
$previous=null;
if($projectId>0){
    $d=mysqli_real_escape_string($conn,$reportDate);
    foreach([
        "project_id=$projectId AND employee_id=$employeeId AND report_date<'$d'",
        "project_id=$projectId AND employee_id=$employeeId",
        "project_id=$projectId"
    ] as $where){
        $q=mysqli_query($conn,"SELECT * FROM sat_reports WHERE $where ORDER BY report_date DESC,created_at DESC,id DESC LIMIT 1");
        if($q&&($previous=mysqli_fetch_assoc($q)))break;
    }
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['submit_sat'])){
    $postProject=(int)($_POST['project_id']??0);
    $date=trim((string)($_POST['report_date']??''));
    $satNo=trim((string)($_POST['sat_no']??''));
    $architects=trim((string)($_POST['architects']??''));
    $pmc=trim((string)($_POST['pmc']??''));
    $revisions=trim((string)($_POST['revisions']??''));
    $items=json_decode((string)($_POST['items_json']??'[]'),true);
    $error='';
    if(!sat_project_allowed($conn,$postProject,$employeeId,$super))$error='Invalid project.';
    elseif($satNo==='')$error='SAT No is required.';
    elseif(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$error='Valid report date is required.';
    elseif(!is_array($items))$error='Invalid sample rows.';
    $items=array_values(array_filter($items,function($r){return trim((string)($r['sample_name']??''))!==''||trim((string)($r['vendor_name']??''))!=='';}));
    if($error===''&&!$items)$error='Enter at least one sample row.';
    if($error===''){
        $q=mysqli_query($conn,"SELECT p.project_name,p.client_id,c.client_name FROM projects p LEFT JOIN clients c ON c.id=p.client_id WHERE p.id=$postProject LIMIT 1");
        $pd=$q?mysqli_fetch_assoc($q):null;
        $pn=(string)($pd['project_name']??'');$cid=(int)($pd['client_id']??0);$cn=(string)($pd['client_name']??'');
        mysqli_begin_transaction($conn);
        try{
            if($resubmitId>0){
                $satId=sat_find_existing($conn,$resubmitId,$postProject,$employeeId,$date);
                if($satId<=0)throw new RuntimeException('Original SAT not found.');
                $st=mysqli_prepare($conn,"UPDATE sat_reports SET sat_no=?,project_id=?,site_id=?,client_id=?,project_name=?,client_name=?,architects=?,pmc=?,revisions=?,report_date=?,prepared_by=?,updated_at=NOW() WHERE id=?");
                mysqli_stmt_bind_param($st,'siiisssssssi',$satNo,$postProject,$postProject,$cid,$pn,$cn,$architects,$pmc,$revisions,$date,$preparedBy,$satId);
                if(!mysqli_stmt_execute($st))throw new RuntimeException(mysqli_stmt_error($st));
                mysqli_stmt_close($st);mysqli_query($conn,"DELETE FROM sat_report_items WHERE sat_report_id=$satId");
            }else{
                $st=mysqli_prepare($conn,"INSERT INTO sat_reports(sat_no,project_id,site_id,client_id,project_name,client_name,architects,pmc,revisions,report_date,employee_id,prepared_by)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
                mysqli_stmt_bind_param($st,'siiissssssis',$satNo,$postProject,$postProject,$cid,$pn,$cn,$architects,$pmc,$revisions,$date,$employeeId,$preparedBy);
                if(!mysqli_stmt_execute($st))throw new RuntimeException(mysqli_stmt_error($st));
                $satId=(int)mysqli_insert_id($conn);mysqli_stmt_close($st);
            }
            $ds=mysqli_prepare($conn,"INSERT INTO sat_report_items(sat_report_id,sl_no,sample_name,vendor_name,sample_delivered,sample_delivered_date,quote_received,quote_received_date,approved,rejected,approval_date,comments)
                VALUES(?,?,?,?,?,NULLIF(?,''),?,NULLIF(?,''),?,?,NULLIF(?,''),?)");
            foreach($items as $i=>$r){
                $sl=$i+1;$sd=!empty($r['sample_delivered'])?1:0;$qr=!empty($r['quote_received'])?1:0;$ap=!empty($r['approved'])?1:0;$rej=!empty($r['rejected'])?1:0;
                $sn=trim((string)($r['sample_name']??''));$vn=trim((string)($r['vendor_name']??''));$sdd=(string)($r['sample_delivered_date']??'');
                $qrd=(string)($r['quote_received_date']??'');$ad=(string)($r['approval_date']??'');$cm=trim((string)($r['comments']??''));
                mysqli_stmt_bind_param($ds,'iissisisiiss',$satId,$sl,$sn,$vn,$sd,$sdd,$qr,$qrd,$ap,$rej,$ad,$cm);
                if(!mysqli_stmt_execute($ds))throw new RuntimeException(mysqli_stmt_error($ds));
            }
            mysqli_stmt_close($ds);
            sat_sync_submission(
                $conn,
                $resubmitId,
                $postProject,
                $employeeId,
                $date,
                $satId,
                $satNo
            );

            sat_log(
                $conn,
                $resubmitId > 0 ? 'UPDATE' : 'CREATE',
                ($resubmitId > 0 ? 'Resubmitted SAT ' : 'Submitted SAT ') . $satNo,
                $satId
            );

            mysqli_commit($conn);
            sat_redirect($postProject,$date,$resubmitId>0?'resubmitted':'saved');
        }catch(Throwable $e){mysqli_rollback($conn);$error=$e->getMessage();}
    }
    if($error!==''){header('Location: sat.php?project_id='.$postProject.'&report_date='.urlencode($date).'&error='.urlencode($error));exit;}
}

$form=['sat_no'=>$existing['sat_no']??$defaultNo,'report_date'=>$existing['report_date']??$reportDate,'architects'=>$existing['architects']??'','pmc'=>$existing['pmc']??'','revisions'=>$existing['revisions']??'','items'=>[]];
if($existing){
    $q=mysqli_query($conn,"SELECT * FROM sat_report_items WHERE sat_report_id=".(int)$existing['id']." ORDER BY sl_no,id");
    while($q&&($r=mysqli_fetch_assoc($q)))$form['items'][]=$r;
}
if(!$form['items'])$form['items']=array_fill(0,5,['sample_name'=>'','vendor_name'=>'','sample_delivered'=>0,'sample_delivered_date'=>'','quote_received'=>0,'quote_received_date'=>'','approved'=>0,'rejected'=>0,'approval_date'=>'','comments'=>'']);

$prevPayload=null;
if($previous){
    $items=[];$q=mysqli_query($conn,"SELECT * FROM sat_report_items WHERE sat_report_id=".(int)$previous['id']." ORDER BY sl_no,id");
    while($q&&($r=mysqli_fetch_assoc($q)))$items[]=$r;
    $prevPayload=['sat_no'=>$previous['sat_no'],'report_date'=>$previous['report_date'],'architects'=>$previous['architects'],'pmc'=>$previous['pmc'],'revisions'=>$previous['revisions'],'items'=>$items];
}

$recent=[];$q=mysqli_query($conn,"SELECT r.id,r.sat_no,r.report_date,r.project_name,COUNT(i.id) item_count FROM sat_reports r
 LEFT JOIN sat_report_items i ON i.sat_report_id=r.id WHERE r.employee_id=$employeeId GROUP BY r.id ORDER BY r.created_at DESC LIMIT 10");
while($q&&($r=mysqli_fetch_assoc($q)))$recent[]=$r;

$pageMessageType=isset($_GET['error'])?'error':'';
$pageMessageText=isset($_GET['error'])?trim((string)$_GET['error']):'';
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SAT - TEK-C PMC Construction</title>
<?php include 'includes/links.php'; ?>
<style>
.page-head-card,.section-box{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
.section-box{padding:18px}.mini-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}.mini-icon{width:44px;height:44px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:rgba(37,99,235,.12);color:#2563eb}
.form-control,.form-select{background:var(--card-bg);color:var(--text-main);border-color:var(--border-soft);min-height:42px;font-size:13px;font-weight:700}
.sat-table{min-width:1450px}.sat-table th{font-size:11px;text-transform:uppercase;color:var(--text-muted);font-weight:900;background:rgba(148,163,184,.10);white-space:nowrap;text-align:center;vertical-align:middle}.sat-table td{vertical-align:top}.badge-soft{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--border-soft);background:rgba(148,163,184,.08);border-radius:999px;padding:7px 12px;font-size:12px;font-weight:900}.recent-card{border:1px solid var(--border-soft);border-radius:18px;padding:12px;background:rgba(148,163,184,.06)}
</style>
</head>
<body>
<div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>
<?php include 'includes/page-message.php'; ?>
<div class="min-vh-100 d-flex">
<?php include 'includes/sidebar.php'; ?>
<main id="main"><?php include 'includes/nav.php'; ?>
<section class="page-section p-3">
<div class="page-head-card mb-3"><div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
<div><h1 class="h4 fw-bold mb-1"><?= $resubmitId>0?'Resubmit Samples Approval Tracker (SAT)':'Samples Approval Tracker (SAT)' ?></h1><p class="text-muted-custom mb-0 small">Sample, quotation and approval tracking.</p></div>
<div class="d-flex flex-wrap gap-2 align-items-center">
<label class="badge-soft mb-0 <?= !$prevPayload?'opacity-75':'' ?>"><input type="checkbox" id="loadPrevious" <?= !$prevPayload?'disabled':'' ?>><span><strong>Load previous data</strong><small class="d-block text-muted-custom"><?= $prevPayload?sat_e($prevPayload['sat_no']).' · '.sat_e(date('d M Y',strtotime($prevPayload['report_date']))):'No previous data' ?></small></span></label>
<span class="badge-soft"><i data-lucide="user" style="width:15px"></i><?= sat_e($preparedBy) ?></span>
<a href="reports-hub.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Back to Reports Hub</a>
</div></div></div>

<div class="section-box mb-3"><div class="mini-head"><div class="mini-icon"><i data-lucide="map-pin"></i></div><div><h2 class="fw-bold fs-6 mb-1">Project Selection</h2><p class="text-muted-custom small mb-0">Choose assigned project.</p></div></div>
<div class="row g-3 align-items-end"><div class="col-lg-9"><label class="form-label fw-bold small">Assigned Project</label><select id="projectPicker" class="form-select rounded-4"><option value="">-- Select Project --</option><?php foreach($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id']===$projectId?'selected':'' ?>><?= sat_e($p['project_name']) ?><?= $p['project_code']?' ('.sat_e($p['project_code']).')':'' ?> - <?= sat_e($p['project_location']?:'-') ?></option><?php endforeach; ?></select></div><div class="col-lg-3"><a href="sat.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">Reset</a></div></div></div>

<div class="section-box mb-3"><div class="mini-head"><div class="mini-icon"><i data-lucide="building-2"></i></div><div><h2 class="fw-bold fs-6 mb-1">Project Details</h2><p class="text-muted-custom small mb-0">Auto-filled from project.</p></div></div>
<?php if(!$project): ?><p class="text-muted-custom fw-bold mb-0">Please select a project.</p><?php else: ?><div class="row g-3">
<div class="col-md-4"><small class="text-muted-custom fw-bold">Project</small><div class="fw-bold"><?= sat_e($project['project_name']) ?></div></div>
<div class="col-md-4"><small class="text-muted-custom fw-bold">Client</small><div class="fw-bold"><?= sat_e($project['client_name']?:'-') ?></div></div>
<div class="col-md-4"><small class="text-muted-custom fw-bold">Location</small><div class="fw-bold"><?= sat_e($project['project_location']?:'-') ?></div></div>
<div class="col-md-4"><small class="text-muted-custom fw-bold">PMS Schedule</small><div class="fw-bold"><?= sat_e($pmsSchedule['schedule_name']??'PMS Schedule') ?></div></div>
<div class="col-md-4"><small class="text-muted-custom fw-bold">PMS Start</small><div class="fw-bold"><?= sat_e($pmsStart?:($project['start_date']??'-')) ?></div></div>
<div class="col-md-4"><small class="text-muted-custom fw-bold">PMS End</small><div class="fw-bold"><?= sat_e($pmsEnd?:($project['expected_completion_date']??'-')) ?></div></div>
</div><?php endif; ?></div>

<form method="POST" id="satForm">
<input type="hidden" name="submit_sat" value="1"><input type="hidden" name="project_id" value="<?= $projectId ?>"><input type="hidden" name="resubmit_submission_id" value="<?= $resubmitId ?>"><input type="hidden" name="items_json" id="items_json">
<div class="section-box mb-3"><div class="mini-head"><div class="mini-icon"><i data-lucide="clipboard-check"></i></div><div><h2 class="fw-bold fs-6 mb-1">SAT Header</h2><p class="text-muted-custom small mb-0">Report and project-party details.</p></div></div>
<div class="row g-3">
<div class="col-md-4"><label class="form-label fw-bold small">SAT No</label><input class="form-control rounded-4" name="sat_no" value="<?= sat_e($form['sat_no']) ?>" required></div>
<div class="col-md-4"><label class="form-label fw-bold small">Report Date</label><input type="date" class="form-control rounded-4" name="report_date" id="report_date" value="<?= sat_e($form['report_date']) ?>" required></div>
<div class="col-md-4"><label class="form-label fw-bold small">Revisions / Dated</label><input class="form-control rounded-4" name="revisions" id="revisions" value="<?= sat_e($form['revisions']) ?>"></div>
<div class="col-md-6"><label class="form-label fw-bold small">Architects</label><input class="form-control rounded-4" name="architects" id="architects" value="<?= sat_e($form['architects']) ?>"></div>
<div class="col-md-6"><label class="form-label fw-bold small">PMC</label><input class="form-control rounded-4" name="pmc" id="pmc" value="<?= sat_e($form['pmc']) ?>"></div>
</div></div>

<div class="section-box mb-3"><div class="d-flex justify-content-between align-items-center mb-3"><div class="mini-head mb-0"><div class="mini-icon"><i data-lucide="table"></i></div><div><h2 class="fw-bold fs-6 mb-1">Sample & Quotation Matrix</h2><p class="text-muted-custom small mb-0">Track samples, quotes and approval.</p></div></div><button type="button" id="addRow" class="btn btn-outline-primary rounded-4 fw-bold">Add Row</button></div>
<div class="table-responsive thin-scrollbar"><table class="table table-bordered sat-table"><thead><tr><th>SL</th><th>Sample</th><th>Vendor</th><th>Delivered</th><th>Delivered Date</th><th>Quote Received</th><th>Quote Date</th><th>Approved</th><th>Rejected</th><th>Approval Date</th><th>Comments / Action</th><th>Del</th></tr></thead><tbody id="satBody"></tbody></table></div></div>
<div class="section-box mb-3"><div class="d-flex justify-content-end"><button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4" <?= !$project?'disabled':'' ?>><?= $resubmitId>0?'Resubmit SAT':'Submit SAT' ?></button></div></div>
</form>

<section class="card-ui overflow-hidden"><div class="p-3 p-lg-4"><h2 class="fw-bold fs-6 mb-1">Recent SAT</h2><p class="text-muted-custom small mb-0">Your latest submissions.</p></div><div class="px-3 px-lg-4 pb-4"><div class="row g-2"><?php foreach($recent as $r): ?><div class="col-md-6 col-xl-4"><div class="recent-card"><div class="fw-bold"><?= sat_e($r['sat_no']) ?></div><small class="text-muted-custom"><?= sat_e($r['project_name']) ?> · <?= sat_e(date('d M Y',strtotime($r['report_date']))) ?> · <?= (int)$r['item_count'] ?> item(s)</small><br><a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2" target="_blank" href="reports-print/report-sat-print.php?view=<?= (int)$r['id'] ?>">Print</a></div></div><?php endforeach; ?></div></div></section>
<?php include 'includes/footer.php'; ?>
</section></main><div id="settingsOverlay"></div><?php include 'includes/rightsidbar.php'; ?></div>
<?php include 'includes/script.php'; ?><script src="assets/js/script.js?v=43"></script>
<script>
const initialItems=<?= json_encode($form['items'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const previous=<?= json_encode($prevPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const body=document.getElementById('satBody');
const snapshot={architects:document.getElementById('architects')?.value||'',pmc:document.getElementById('pmc')?.value||'',revisions:document.getElementById('revisions')?.value||'',items:initialItems};
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
function row(v={}){const tr=document.createElement('tr');tr.innerHTML=`<td class="sl text-center fw-bold"></td><td><input class="form-control rounded-4 sample" value="${esc(v.sample_name||'')}"></td><td><input class="form-control rounded-4 vendor" value="${esc(v.vendor_name||'')}"></td><td class="text-center"><input type="checkbox" class="delivered" ${+v.sample_delivered?'checked':''}></td><td><input type="date" class="form-control rounded-4 delivered-date" value="${esc(v.sample_delivered_date||'')}"></td><td class="text-center"><input type="checkbox" class="quote" ${+v.quote_received?'checked':''}></td><td><input type="date" class="form-control rounded-4 quote-date" value="${esc(v.quote_received_date||'')}"></td><td class="text-center"><input type="checkbox" class="approved" ${+v.approved?'checked':''}></td><td class="text-center"><input type="checkbox" class="rejected" ${+v.rejected?'checked':''}></td><td><input type="date" class="form-control rounded-4 approval-date" value="${esc(v.approval_date||'')}"></td><td><textarea class="form-control rounded-4 comments" rows="2">${esc(v.comments||'')}</textarea></td><td><button type="button" class="btn btn-sm btn-outline-danger rounded-4 del"><i data-lucide="trash-2"></i></button></td>`;body.appendChild(tr);renumber();}
function renumber(){[...body.rows].forEach((r,i)=>r.querySelector('.sl').textContent=i+1);if(window.lucide)lucide.createIcons();}
function load(src){document.getElementById('architects').value=src?.architects||'';document.getElementById('pmc').value=src?.pmc||'';document.getElementById('revisions').value=src?.revisions||'';body.innerHTML='';(src?.items?.length?src.items:[{}]).forEach(row);}
initialItems.forEach(row);
document.getElementById('addRow').onclick=()=>row({});
document.addEventListener('click',e=>{const b=e.target.closest('.del');if(!b)return;const r=b.closest('tr');if(body.rows.length<=1){r.querySelectorAll('input,textarea').forEach(x=>x.type==='checkbox'?x.checked=false:x.value='');}else r.remove();renumber();});
document.getElementById('loadPrevious')?.addEventListener('change',function(){load(this.checked?previous:snapshot);});
document.getElementById('projectPicker')?.addEventListener('change',function(){const d=document.getElementById('report_date')?.value||'<?= sat_e($reportDate) ?>';location.href=this.value?'sat.php?project_id='+encodeURIComponent(this.value)+'&report_date='+encodeURIComponent(d):'sat.php';});
document.getElementById('satForm').addEventListener('submit',function(e){const items=[...body.rows].map((r,i)=>({sl_no:i+1,sample_name:r.querySelector('.sample').value,vendor_name:r.querySelector('.vendor').value,sample_delivered:r.querySelector('.delivered').checked?1:0,sample_delivered_date:r.querySelector('.delivered-date').value,quote_received:r.querySelector('.quote').checked?1:0,quote_received_date:r.querySelector('.quote-date').value,approved:r.querySelector('.approved').checked?1:0,rejected:r.querySelector('.rejected').checked?1:0,approval_date:r.querySelector('.approval-date').value,comments:r.querySelector('.comments').value}));document.getElementById('items_json').value=JSON.stringify(items);});
</script></body></html>
