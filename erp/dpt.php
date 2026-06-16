<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';
date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) { header('Location: login.php'); exit; }
function e($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function currentEmployeeId(mysqli $conn): int {
    if (!empty($_SESSION['employee_id'])) return (int)$_SESSION['employee_id'];
    $uid=(int)($_SESSION['user_id']??0); if($uid<=0) return 0;
    $q=mysqli_query($conn,"SELECT employee_id FROM users WHERE id=$uid LIMIT 1");
    $r=$q?mysqli_fetch_assoc($q):null; $id=(int)($r['employee_id']??0); if($id>0) $_SESSION['employee_id']=$id; return $id;
}
function isSuperAdmin(mysqli $conn): bool {
    $uid=(int)($_SESSION['user_id']??0); if($uid<=0) return false;
    $q=mysqli_query($conn,"SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=$uid AND r.is_active=1 AND (r.role_slug='super-admin' OR LOWER(r.role_name)='super admin') LIMIT 1");
    return $q && mysqli_num_rows($q)>0;
}
function projectAllowed(mysqli $conn,int $projectId,int $employeeId,bool $super): bool {
    if($super) return true; if($projectId<=0||$employeeId<=0) return false;
    $q=mysqli_query($conn,"SELECT p.id FROM projects p WHERE p.id=$projectId AND p.deleted_at IS NULL AND (p.manager_employee_id=$employeeId OR p.team_lead_employee_id=$employeeId OR EXISTS(SELECT 1 FROM project_assignments pa WHERE pa.project_id=p.id AND pa.employee_id=$employeeId AND pa.status='active')) LIMIT 1");
    return $q && mysqli_num_rows($q)>0;
}
function tableColumns(mysqli $conn,string $table): array { $out=[]; $q=mysqli_query($conn,"SHOW COLUMNS FROM `".preg_replace('/[^A-Za-z0-9_]/','',$table)."`"); while($q&&$r=mysqli_fetch_assoc($q)) $out[$r['Field']]=1; return $out; }
function sqlValue(mysqli $conn,$v): string { if($v===null) return 'NULL'; if(is_int($v)||is_float($v)) return (string)$v; return "'".mysqli_real_escape_string($conn,(string)$v)."'"; }
function reportTypeId(mysqli $conn): int { $q=mysqli_query($conn,"SELECT id FROM master_report_types WHERE report_code='DPT' LIMIT 1"); $r=$q?mysqli_fetch_assoc($q):null; return (int)($r['id']??0); }
function findExisting(mysqli $conn,int $submissionId,int $projectId,int $employeeId,string $date): int {
    if($submissionId>0){ $cols=tableColumns($conn,'project_report_submissions'); $sel=['id']; foreach(['report_reference_id','source_id','reference_id'] as $c) if(isset($cols[$c])) $sel[]=$c; $q=mysqli_query($conn,"SELECT ".implode(',',array_unique($sel))." FROM project_report_submissions WHERE id=$submissionId LIMIT 1"); if($q&&$s=mysqli_fetch_assoc($q)){ foreach(['report_reference_id','source_id','reference_id'] as $c){ $id=(int)($s[$c]??0); if($id>0){$x=mysqli_query($conn,"SELECT id FROM dpt_main WHERE id=$id LIMIT 1"); if($x&&mysqli_num_rows($x)>0) return $id;}}}}
    $d=mysqli_real_escape_string($conn,$date); $q=mysqli_query($conn,"SELECT id FROM dpt_main WHERE project_id=$projectId AND created_by=$employeeId AND dated='$d' ORDER BY id DESC LIMIT 1"); $r=$q?mysqli_fetch_assoc($q):null; return (int)($r['id']??0);
}
function syncSubmission(mysqli $conn,int $submissionId,int $projectId,int $employeeId,string $date,int $dptId,string $dptNo): void {
    $rt=reportTypeId($conn); if($rt<=0||$dptId<=0) return; $cols=tableColumns($conn,'project_report_submissions'); $uid=(int)($_SESSION['user_id']??0);
    $map=['report_no'=>$dptNo,'report_number'=>$dptNo,'submission_no'=>$dptNo,'title'=>'Daily Progress Tracker','submitted_by_employee_id'=>$employeeId,'submitted_by_user_id'=>$uid?:null,'submission_for_date'=>$date,'period_start'=>$date,'period_end'=>$date,'status'=>'submitted','submitted_at'=>date('Y-m-d H:i:s'),'source_table'=>'dpt_main','source_id'=>$dptId,'report_reference_table'=>'dpt_main','report_reference_id'=>$dptId,'reference_id'=>$dptId,'updated_by'=>$uid?:null,'updated_at'=>date('Y-m-d H:i:s')];
    if($submissionId>0){$sets=[];foreach($map as $f=>$v) if(isset($cols[$f])) $sets[]="`$f`=".sqlValue($conn,$v); if($sets) mysqli_query($conn,"UPDATE project_report_submissions SET ".implode(',',$sets)." WHERE id=$submissionId"); return;}
    $data=array_merge(['project_id'=>$projectId,'report_type_id'=>$rt,'created_by'=>$uid?:null,'created_at'=>date('Y-m-d H:i:s')],$map); $ic=[];$iv=[];foreach($data as $f=>$v) if(isset($cols[$f])){$ic[]="`$f`";$iv[]=sqlValue($conn,$v);} if($ic) mysqli_query($conn,"INSERT INTO project_report_submissions (".implode(',',$ic).") VALUES (".implode(',',$iv).")");
}

$employeeId=currentEmployeeId($conn); $super=isSuperAdmin($conn); if($employeeId<=0){header('Location: login.php');exit;}
$employeeQ=mysqli_query($conn,"SELECT e.*,r.role_name AS designation_name FROM employees e LEFT JOIN roles r ON r.id=e.role_id WHERE e.id=$employeeId LIMIT 1"); $employee=$employeeQ?mysqli_fetch_assoc($employeeQ):[]; $employeeName=$employee['full_name']??($_SESSION['name']??'');
$projectId=(int)($_GET['project_id']??$_POST['project_id']??0); $reportDate=(string)($_GET['report_date']??$_POST['dated']??date('Y-m-d')); if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$reportDate)) $reportDate=date('Y-m-d');
$resubmitId=(int)($_GET['resubmit_submission_id']??$_POST['resubmit_submission_id']??0);
$projects=[];
if($super){$pq=mysqli_query($conn,"SELECT p.id,p.project_name,p.project_code,p.project_location,p.client_id,c.client_name FROM projects p LEFT JOIN clients c ON c.id=p.client_id WHERE p.deleted_at IS NULL ORDER BY p.project_name");}
else{$pq=mysqli_query($conn,"SELECT DISTINCT p.id,p.project_name,p.project_code,p.project_location,p.client_id,c.client_name FROM projects p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN project_assignments pa ON pa.project_id=p.id AND pa.status='active' WHERE p.deleted_at IS NULL AND (p.manager_employee_id=$employeeId OR p.team_lead_employee_id=$employeeId OR pa.employee_id=$employeeId) ORDER BY p.project_name");}
while($pq&&$r=mysqli_fetch_assoc($pq)) $projects[]=$r;
$project=null; if($projectId>0&&projectAllowed($conn,$projectId,$employeeId,$super)){ $q=mysqli_query($conn,"SELECT p.*,c.client_name,c.company_name FROM projects p LEFT JOIN clients c ON c.id=p.client_id WHERE p.id=$projectId AND p.deleted_at IS NULL LIMIT 1"); $project=$q?mysqli_fetch_assoc($q):null; }
$pmsSchedule=$projectId>0?pms_project_schedule($conn,$projectId):null; [$pmsStart,$pmsEnd]=pms_schedule_date_range($pmsSchedule,$project);
$prefix=$projectId>0?'DPT/'.$projectId.'/'.date('Ym',strtotime($reportDate)).'/':''; $defaultNo=''; if($prefix){$pe=mysqli_real_escape_string($conn,$prefix);$q=mysqli_query($conn,"SELECT COUNT(*) total FROM dpt_main WHERE dpt_no LIKE '$pe%'");$c=$q?(int)(mysqli_fetch_assoc($q)['total']??0):0;$defaultNo=$prefix.str_pad((string)($c+1),3,'0',STR_PAD_LEFT);} 
$existing=null; if($resubmitId>0&&$projectId>0){$id=findExisting($conn,$resubmitId,$projectId,$employeeId,$reportDate);if($id>0){$q=mysqli_query($conn,"SELECT * FROM dpt_main WHERE id=$id LIMIT 1");$existing=$q?mysqli_fetch_assoc($q):null;}}
$previous=null; if($projectId>0){$d=mysqli_real_escape_string($conn,$reportDate);$q=mysqli_query($conn,"SELECT * FROM dpt_main WHERE project_id=$projectId AND created_by=$employeeId AND dated<'$d' ORDER BY dated DESC,id DESC LIMIT 1");$previous=$q?mysqli_fetch_assoc($q):null;}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['submit_dpt'])){
    $pid=(int)($_POST['project_id']??0);$sid=(int)($_POST['resubmit_submission_id']??0);$dptNo=trim((string)($_POST['dpt_no']??''));$dated=trim((string)($_POST['dated']??''));$pmc=trim((string)($_POST['pmc']??''));$remarks=trim((string)($_POST['remarks']??''));$items=json_decode((string)($_POST['items_json']??'[]'),true);$error='';
    if(!projectAllowed($conn,$pid,$employeeId,$super))$error='Invalid project selection.';elseif($dptNo==='')$error='DPT No is required.';elseif(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dated))$error='Valid DPT date is required.';elseif(!is_array($items))$error='Invalid work rows.';
    $items=array_values(array_filter(is_array($items)?$items:[],fn($r)=>trim((string)($r['list_of_work']??''))!=='')); if($error===''&&!$items)$error='Please enter at least one pending work.';
    if($error===''){
        $q=mysqli_query($conn,"SELECT p.project_name,p.client_id,c.client_name FROM projects p LEFT JOIN clients c ON c.id=p.client_id WHERE p.id=$pid LIMIT 1");$pd=$q?mysqli_fetch_assoc($q):[];$projectName=$pd['project_name']??'';$clientId=(int)($pd['client_id']??0);$clientName=$pd['client_name']??'';
        mysqli_begin_transaction($conn);
        try{
            if($sid>0){$dptId=findExisting($conn,$sid,$pid,$employeeId,$dated);if($dptId<=0)throw new RuntimeException('Original DPT not found for resubmission.');$st=mysqli_prepare($conn,"UPDATE dpt_main SET dpt_no=?,project_id=?,site_id=?,client_id=?,project_name=?,client_name=?,pmc=?,dated=?,created_by_name=?,remarks=?,updated_at=NOW() WHERE id=?");mysqli_stmt_bind_param($st,'siiissssssi',$dptNo,$pid,$pid,$clientId,$projectName,$clientName,$pmc,$dated,$employeeName,$remarks,$dptId);if(!mysqli_stmt_execute($st))throw new RuntimeException(mysqli_stmt_error($st));mysqli_stmt_close($st);mysqli_query($conn,"DELETE FROM dpt_details WHERE dpt_main_id=$dptId");}
            else{$st=mysqli_prepare($conn,"INSERT INTO dpt_main (dpt_no,project_id,site_id,client_id,project_name,client_name,pmc,dated,created_by,created_by_name,remarks) VALUES (?,?,?,?,?,?,?,?,?,?,?)");mysqli_stmt_bind_param($st,'siiissssiss',$dptNo,$pid,$pid,$clientId,$projectName,$clientName,$pmc,$dated,$employeeId,$employeeName,$remarks);if(!mysqli_stmt_execute($st))throw new RuntimeException(mysqli_stmt_error($st));$dptId=(int)mysqli_insert_id($conn);mysqli_stmt_close($st);}
            $ds=mysqli_prepare($conn,"INSERT INTO dpt_details (dpt_main_id,sl_no,list_of_work,scheduled_finish,actual_targeted_finish,status,remark) VALUES (?,?,?,NULLIF(?,''),NULLIF(?,''),?,?)");
            foreach($items as $i=>$it){$sl=$i+1;$work=trim((string)($it['list_of_work']??''));$sf=trim((string)($it['scheduled_finish']??''));$af=trim((string)($it['actual_targeted_finish']??''));$status=strtoupper(trim((string)($it['status']??'ONTRACK')));if(!in_array($status,['ONTRACK','DELAY','COMPLETED','BLOCKED','CANCELLED'],true))$status='ONTRACK';$remark=trim((string)($it['remark']??''));mysqli_stmt_bind_param($ds,'iisssss',$dptId,$sl,$work,$sf,$af,$status,$remark);if(!mysqli_stmt_execute($ds))throw new RuntimeException(mysqli_stmt_error($ds));}
            mysqli_stmt_close($ds);syncSubmission($conn,$sid,$pid,$employeeId,$dated,$dptId,$dptNo);mysqli_commit($conn);header('Location: reports-hub.php?project_id='.$pid.'&report_date='.urlencode($dated).'&'.($sid>0?'resubmitted':'saved').'=1');exit;
        }catch(Throwable $ex){mysqli_rollback($conn);$error=$ex->getMessage();}
    }
    if($error!==''){header('Location: dpt.php?project_id='.$pid.'&report_date='.urlencode($dated).'&error='.urlencode($error));exit;}
}

$form=['dpt_no'=>$existing['dpt_no']??$defaultNo,'dated'=>$existing['dated']??$reportDate,'pmc'=>$existing['pmc']??'','remarks'=>$existing['remarks']??'','items'=>[]];
if($existing){$q=mysqli_query($conn,"SELECT * FROM dpt_details WHERE dpt_main_id=".(int)$existing['id']." ORDER BY sl_no,id");while($q&&$r=mysqli_fetch_assoc($q))$form['items'][]=$r;}
if(!$form['items'])$form['items']=array_fill(0,3,['list_of_work'=>'','scheduled_finish'=>'','actual_targeted_finish'=>'','status'=>'ONTRACK','remark'=>'']);
$previousPayload=null;if($previous){$items=[];$q=mysqli_query($conn,"SELECT * FROM dpt_details WHERE dpt_main_id=".(int)$previous['id']." ORDER BY sl_no,id");while($q&&$r=mysqli_fetch_assoc($q))$items[]=$r;$previousPayload=['dpt_no'=>$previous['dpt_no']??'','dated'=>$previous['dated']??'','pmc'=>$previous['pmc']??'','remarks'=>$previous['remarks']??'','items'=>$items];}
$recent=[];$q=mysqli_query($conn,"SELECT m.id,m.dpt_no,m.dated,m.project_name,COUNT(d.id) item_count FROM dpt_main m LEFT JOIN dpt_details d ON d.dpt_main_id=m.id WHERE m.created_by=$employeeId GROUP BY m.id ORDER BY m.created_at DESC LIMIT 10");while($q&&$r=mysqli_fetch_assoc($q))$recent[]=$r;
$pageMessageType=isset($_GET['error'])?'error':'';$pageMessageText=isset($_GET['error'])?trim((string)$_GET['error']):'';
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>DPT - TEK-C</title><?php include 'includes/links.php'; ?>
    <style>
    .page-head-card,
    .section-box {
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 22px;
        box-shadow: var(--shadow-card);
        padding: 16px
    }

    .section-box {
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
        background: rgba(37, 99, 235, .12);
        color: #2563eb
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

    .dpt-table {
        min-width: 1300px
    }

    .dpt-table th {
        font-size: 11px;
        text-transform: uppercase;
        color: var(--text-muted);
        font-weight: 900;
        background: rgba(148, 163, 184, .10);
        white-space: nowrap;
        text-align: center
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

    .status-ontrack {
        background: #dcfce7;
        color: #166534
    }

    .status-delay {
        background: #fee2e2;
        color: #991b1b
    }

    .status-completed {
        background: #dbeafe;
        color: #1e40af
    }
    </style>
</head>

<body>
    <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none">
    </div><?php include 'includes/page-message.php'; ?><div class="min-vh-100 d-flex">
        <?php include 'includes/sidebar.php'; ?><main id="main"><?php include 'includes/nav.php'; ?><section
                class="page-section p-3">
                <div class="page-head-card mb-3">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                        <div>
                            <h1 class="h4 fw-bold mb-1">
                                <?= $resubmitId>0?'Resubmit Daily Progress Tracker (DPT)':'Daily Progress Tracker (DPT)' ?>
                            </h1>
                            <p class="text-muted-custom mb-0 small">Track pending works, finish dates and status.</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center"><label
                                class="badge-soft mb-0 <?= !$previousPayload?'opacity-75':'' ?>"><input type="checkbox"
                                    id="loadPrevious" <?= !$previousPayload?'disabled':'' ?>><span><strong>Load previous
                                        data</strong><small
                                        class="d-block text-muted-custom"><?= $previousPayload?e($previousPayload['dpt_no']).' · '.e(date('d M Y',strtotime($previousPayload['dated']))):'No previous data' ?></small></span></label><span
                                class="badge-soft"><?= e($employeeName) ?></span><span
                                class="badge-soft"><?= e($employee['designation_name']??'') ?></span><a
                                href="reports-hub.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Back
                                to Reports Hub</a></div>
                    </div>
                </div>
                <div class="section-box mb-3">
                    <div class="mini-head">
                        <div class="mini-icon"><i data-lucide="map-pin"></i></div>
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">Project Selection</h2>
                            <p class="text-muted-custom small mb-0">Choose an assigned project.</p>
                        </div>
                    </div>
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-9"><label class="form-label fw-bold small">Assigned Project</label><select
                                id="projectPicker" class="form-select rounded-4">
                                <option value="">-- Select Assigned Project --</option>
                                <?php foreach($projects as $po): ?><option value="<?= (int)$po['id'] ?>"
                                    <?= (int)$po['id']===$projectId?'selected':'' ?>>
                                    <?= e($po['project_name']) ?><?= !empty($po['project_code'])?' ('.e($po['project_code']).')':'' ?>
                                    - <?= e($po['project_location']?:'-') ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-lg-3"><a href="dpt.php"
                                class="btn btn-outline-secondary rounded-4 fw-bold w-100">Reset</a></div>
                    </div>
                </div>
                <div class="section-box mb-3">
                    <div class="mini-head">
                        <div class="mini-icon"><i data-lucide="building-2"></i></div>
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">Project Details</h2>
                        </div>
                    </div><?php if(!$project): ?><p class="text-muted-custom fw-bold mb-0">Please select a project.</p>
                    <?php else: ?><div class="row g-3">
                        <div class="col-md-4"><small class="text-muted-custom fw-bold">Project</small>
                            <div class="fw-bold"><?= e($project['project_name']) ?></div>
                        </div>
                        <div class="col-md-4"><small class="text-muted-custom fw-bold">Client</small>
                            <div class="fw-bold"><?= e($project['client_name']?:'-') ?></div>
                        </div>
                        <div class="col-md-4"><small class="text-muted-custom fw-bold">Location</small>
                            <div class="fw-bold"><?= e($project['project_location']?:'-') ?></div>
                        </div>
                        <div class="col-md-4"><small class="text-muted-custom fw-bold">PMS Schedule</small>
                            <div class="fw-bold"><?= e($pmsSchedule['schedule_name']??'PMS Schedule') ?></div>
                        </div>
                        <div class="col-md-4"><small class="text-muted-custom fw-bold">PMS Start</small>
                            <div class="fw-bold"><?= e($pmsStart?:'-') ?></div>
                        </div>
                        <div class="col-md-4"><small class="text-muted-custom fw-bold">PMS End</small>
                            <div class="fw-bold"><?= e($pmsEnd?:'-') ?></div>
                        </div>
                    </div><?php endif; ?>
                </div>
                <form method="POST" id="dptForm"><input type="hidden" name="submit_dpt" value="1"><input type="hidden"
                        name="project_id" value="<?= (int)$projectId ?>"><input type="hidden"
                        name="resubmit_submission_id" value="<?= (int)$resubmitId ?>"><input type="hidden"
                        name="items_json" id="items_json">
                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon"><i data-lucide="clipboard-check"></i></div>
                            <div>
                                <h2 class="fw-bold fs-6 mb-1">DPT Header</h2>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label fw-bold small">DPT No</label><input
                                    class="form-control rounded-4" name="dpt_no" value="<?= e($form['dpt_no']) ?>"
                                    required></div>
                            <div class="col-md-4"><label class="form-label fw-bold small">Dated</label><input
                                    type="date" class="form-control rounded-4" id="dated" name="dated"
                                    value="<?= e($form['dated']) ?>" required></div>
                            <div class="col-md-4"><label class="form-label fw-bold small">PMC</label><input
                                    class="form-control rounded-4" id="pmc" name="pmc" value="<?= e($form['pmc']) ?>"
                                    placeholder="Enter PMC name"></div>
                        </div>
                    </div>
                    <div class="section-box mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="mini-head mb-0">
                                <div class="mini-icon"><i data-lucide="list-checks"></i></div>
                                <div>
                                    <h2 class="fw-bold fs-6 mb-1">Pending Works</h2>
                                </div>
                            </div><button type="button" id="addRow"
                                class="btn btn-outline-primary rounded-4 fw-bold">Add Work</button>
                        </div>
                        <div class="table-responsive thin-scrollbar">
                            <table class="table table-bordered dpt-table">
                                <thead>
                                    <tr>
                                        <th>SL</th>
                                        <th>List of Pending Works</th>
                                        <th>Scheduled Finish</th>
                                        <th>Actual / Targeted Finish</th>
                                        <th>Status</th>
                                        <th>Remarks</th>
                                        <th>Del</th>
                                    </tr>
                                </thead>
                                <tbody id="dptBody"></tbody>
                            </table>
                        </div>
                        <div class="mt-3"><label class="form-label fw-bold small">General Remarks</label><textarea
                                class="form-control rounded-4" id="remarks" name="remarks"
                                rows="3"><?= e($form['remarks']) ?></textarea></div>
                    </div>
                    <div class="section-box mb-3">
                        <div class="d-flex justify-content-end"><button type="submit"
                                class="btn brand-gradient text-white rounded-4 fw-bold px-4"
                                <?= !$project?'disabled':'' ?>><?= $resubmitId>0?'Resubmit DPT':'Submit DPT' ?></button>
                        </div>
                    </div>
                </form>
                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4">
                        <h2 class="fw-bold fs-6 mb-1">Recent DPT</h2>
                    </div>
                    <div class="px-3 px-lg-4 pb-4">
                        <div class="row g-2"><?php foreach($recent as $rr): ?><div class="col-md-6 col-xl-4">
                                <div class="recent-card">
                                    <div class="fw-bold"><?= e($rr['dpt_no']) ?></div><small
                                        class="text-muted-custom"><?= e($rr['project_name']) ?> ·
                                        <?= e(date('d M Y',strtotime($rr['dated']))) ?> · <?= (int)$rr['item_count'] ?>
                                        item(s)</small><br><a
                                        class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2" target="_blank"
                                        href="reports-print/report-dpt-print.php?view=<?= (int)$rr['id'] ?>">Print</a>
                                </div>
                            </div><?php endforeach; ?></div>
                    </div>
                </section><?php include 'includes/footer.php'; ?>
            </section>
        </main>
        <div id="settingsOverlay"></div><?php include 'includes/rightsidbar.php'; ?>
    </div><?php include 'includes/script.php'; ?><script src="assets/js/script.js?v=51"></script>
    <script>
    const initialItems = <?= json_encode($form['items'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
    const previousData = <?= json_encode($previousPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
    const body = document.getElementById('dptBody');
    const snapshot = {
        pmc: document.getElementById('pmc')?.value || '',
        remarks: document.getElementById('remarks')?.value || '',
        items: initialItems
    };

    function esc(v) {
        return String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"',
            '&quot;').replaceAll("'", '&#039;')
    }

    function cls(s) {
        return s === 'DELAY' ? 'status-delay' : s === 'COMPLETED' ? 'status-completed' : s === 'ONTRACK' ?
            'status-ontrack' : ''
    }

    function addRow(v = {}) {
        const s = v.status || 'ONTRACK',
            r = document.createElement('tr');
        r.innerHTML =
            `<td class="sl text-center fw-bold"></td><td><input class="form-control rounded-4 work" value="${esc(v.list_of_work||'')}" placeholder="Pending work"></td><td><input type="date" class="form-control rounded-4 scheduled" value="${esc(v.scheduled_finish||'')}"></td><td><input type="date" class="form-control rounded-4 actual" value="${esc(v.actual_targeted_finish||'')}"></td><td><select class="form-select rounded-4 status ${cls(s)}"><option value="ONTRACK" ${s==='ONTRACK'?'selected':''}>ON TRACK</option><option value="DELAY" ${s==='DELAY'?'selected':''}>DELAY</option><option value="COMPLETED" ${s==='COMPLETED'?'selected':''}>COMPLETED</option><option value="BLOCKED" ${s==='BLOCKED'?'selected':''}>BLOCKED</option><option value="CANCELLED" ${s==='CANCELLED'?'selected':''}>CANCELLED</option></select></td><td><input class="form-control rounded-4 remark" value="${esc(v.remark||'')}" placeholder="Remark"></td><td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger rounded-4 del"><i data-lucide="trash-2"></i></button></td>`;
        r.querySelector('.status').addEventListener('change', function() {
            this.classList.remove('status-ontrack', 'status-delay', 'status-completed');
            const c = cls(this.value);
            if (c) this.classList.add(c)
        });
        body.appendChild(r);
        renumber()
    }

    function renumber() {
        [...body.rows].forEach((r, i) => r.querySelector('.sl').textContent = i + 1);
        if (window.lucide) window.lucide.createIcons()
    }

    function load(src) {
        document.getElementById('pmc').value = src?.pmc || '';
        document.getElementById('remarks').value = src?.remarks || '';
        body.innerHTML = '';
        (src?.items?.length ? src.items : [{}]).forEach(addRow)
    }
    initialItems.forEach(addRow);
    document.getElementById('addRow')?.addEventListener('click', () => addRow({}));
    document.addEventListener('click', e => {
        const b = e.target.closest('.del');
        if (!b) return;
        const r = b.closest('tr');
        if (body.rows.length <= 1) {
            r.querySelectorAll('input').forEach(i => i.value = '');
            const s = r.querySelector('.status');
            s.value = 'ONTRACK';
            s.dispatchEvent(new Event('change'))
        } else r.remove();
        renumber()
    });
    document.getElementById('loadPrevious')?.addEventListener('change', function() {
        load(this.checked ? previousData : snapshot)
    });
    document.getElementById('projectPicker')?.addEventListener('change', function() {
        const d = document.getElementById('dated')?.value || '<?= e($reportDate) ?>';
        location.href = this.value ? 'dpt.php?project_id=' + encodeURIComponent(this.value) + '&report_date=' +
            encodeURIComponent(d) : 'dpt.php'
    });
    document.getElementById('dptForm')?.addEventListener('submit', () => {
        document.getElementById('items_json').value = JSON.stringify([...body.rows].map((r, i) => ({
            sl_no: i + 1,
            list_of_work: r.querySelector('.work').value,
            scheduled_finish: r.querySelector('.scheduled').value,
            actual_targeted_finish: r.querySelector('.actual').value,
            status: r.querySelector('.status').value,
            remark: r.querySelector('.remark').value
        })))
    })
    </script>
</body>

</html>