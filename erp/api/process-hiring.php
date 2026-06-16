<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/hiring-helper.php';
if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
  header('Location: ../login.php');
  exit;
}
function pv($k, $d = '')
{
  return trim((string) ($_POST[$k] ?? $d));
}
function sv($conn, $v)
{
  return "'" . mysqli_real_escape_string($conn, (string) $v) . "'";
}
function sn($v)
{
  return ($v !== '' && is_numeric($v)) ? (string) $v : 'NULL';
}
function sd($conn, $v)
{
  $d = hiring_date_or_null($v);
  return $d ? sv($conn, $d) : 'NULL';
}
$action = $_POST['action'] ?? '';
$uid = hiring_uid();
try {
  if ($action === 'create_post' || $action === 'update_post') {
    if ($action === 'create_post' && !hiring_can($conn, 'can_create'))
      throw new Exception('No create permission.');
    if ($action === 'update_post' && !hiring_can($conn, 'can_edit'))
      throw new Exception('No edit permission.');
    $id = (int) pv('id');
    $title = pv('title');
    if ($title === '')
      throw new Exception('Hiring title is required.');
    if ($action === 'create_post') {
      $code = hiring_next_code($conn, 'hiring_posts', 'hiring_code', 'HP-');
      $sql = "INSERT INTO hiring_posts(hiring_code,title,division_id,role_id,department_id,employment_type,work_location,openings,experience_min,experience_max,salary_min,salary_max,priority,target_joining_date,application_deadline,job_description,requirements,benefits,status,created_by,updated_by) VALUES(" . sv($conn, $code) . "," . sv($conn, $title) . "," . sn(pv('division_id')) . "," . sn(pv('role_id')) . "," . sn(pv('department_id')) . "," . sv($conn, pv('employment_type', 'full_time')) . "," . sv($conn, pv('work_location')) . "," . (int) pv('openings', '1') . "," . sn(pv('experience_min')) . "," . sn(pv('experience_max')) . "," . sn(pv('salary_min')) . "," . sn(pv('salary_max')) . "," . sv($conn, pv('priority', 'normal')) . "," . sd($conn, pv('target_joining_date')) . "," . sd($conn, pv('application_deadline')) . "," . sv($conn, pv('job_description')) . "," . sv($conn, pv('requirements')) . "," . sv($conn, pv('benefits')) . "," . sv($conn, pv('status', 'open')) . "," . ($uid ?: 'NULL') . "," . ($uid ?: 'NULL') . ")";
      if (!mysqli_query($conn, $sql))
        throw new Exception(mysqli_error($conn));
      $id = mysqli_insert_id($conn);
      hiring_log($conn, 'post', $id, 'CREATE', null, pv('status', 'open'), 'Created hiring post ' . $code);
      header('Location: ../hiring-posts.php?saved=1');
      exit;
    } else {
      $oldQ = mysqli_query($conn, "SELECT status,hiring_code FROM hiring_posts WHERE id=$id LIMIT 1");
      $old = $oldQ ? mysqli_fetch_assoc($oldQ) : null;
      $sql = "UPDATE hiring_posts SET title=" . sv($conn, $title) . ",division_id=" . sn(pv('division_id')) . ",role_id=" . sn(pv('role_id')) . ",department_id=" . sn(pv('department_id')) . ",employment_type=" . sv($conn, pv('employment_type', 'full_time')) . ",work_location=" . sv($conn, pv('work_location')) . ",openings=" . (int) pv('openings', '1') . ",experience_min=" . sn(pv('experience_min')) . ",experience_max=" . sn(pv('experience_max')) . ",salary_min=" . sn(pv('salary_min')) . ",salary_max=" . sn(pv('salary_max')) . ",priority=" . sv($conn, pv('priority', 'normal')) . ",target_joining_date=" . sd($conn, pv('target_joining_date')) . ",application_deadline=" . sd($conn, pv('application_deadline')) . ",job_description=" . sv($conn, pv('job_description')) . ",requirements=" . sv($conn, pv('requirements')) . ",benefits=" . sv($conn, pv('benefits')) . ",status=" . sv($conn, pv('status', 'open')) . ",updated_by=" . ($uid ?: 'NULL') . " WHERE id=$id";
      if (!mysqli_query($conn, $sql))
        throw new Exception(mysqli_error($conn));
      hiring_log($conn, 'post', $id, 'UPDATE', $old['status'] ?? null, pv('status', 'open'), 'Updated hiring post ' . ($old['hiring_code'] ?? ''));
      header('Location: ../hiring-posts.php?updated=1');
      exit;
    }
  }
  if ($action === 'delete_post') {
    if (!hiring_can($conn, 'can_delete'))
      throw new Exception('No delete permission.');
    $id = (int) pv('id');
    mysqli_query($conn, "UPDATE hiring_posts SET deleted_at=NOW(),deleted_by=" . ($uid ?: 'NULL') . " WHERE id=$id");
    hiring_log($conn, 'post', $id, 'DELETE', null, 'deleted', 'Deleted hiring post');
    header('Location: ../hiring-posts.php?deleted=1');
    exit;
  }
  if ($action === 'add_candidate') {
    if (!hiring_can($conn, 'can_create'))
      throw new Exception('No create permission.');
    $postId = (int) pv('post_id');
    $name = pv('full_name');
    if ($postId <= 0 || $name === '')
      throw new Exception('Post and candidate name are required.');
    $code = hiring_next_code($conn, 'hiring_candidates', 'candidate_code', 'CAN-');
    $resume = '';
    if (!empty($_FILES['resume']['name'])) {
      $dir = __DIR__ . '/../uploads/hiring/resumes/';
      if (!is_dir($dir))
        mkdir($dir, 0777, true);
      $ext = strtolower(pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION));
      $file = 'resume_' . time() . '_' . rand(1000, 9999) . ($ext ? '.' . $ext : '');
      if (move_uploaded_file($_FILES['resume']['tmp_name'], $dir . $file))
        $resume = 'uploads/hiring/resumes/' . $file;
    }
    $sql = "INSERT INTO hiring_candidates(candidate_code,full_name,email,mobile_number,gender,date_of_birth,current_location,current_company,current_designation,total_experience,current_ctc,expected_ctc,notice_period_days,source,source_details,resume_path,portfolio_url,linkedin_url,notes,candidate_status,created_by,updated_by) VALUES(" . sv($conn, $code) . "," . sv($conn, $name) . "," . sv($conn, pv('email')) . "," . sv($conn, pv('mobile_number')) . "," . (pv('gender') ? sv($conn, pv('gender')) : 'NULL') . "," . sd($conn, pv('date_of_birth')) . "," . sv($conn, pv('current_location')) . "," . sv($conn, pv('current_company')) . "," . sv($conn, pv('current_designation')) . "," . sn(pv('total_experience')) . "," . sn(pv('current_ctc')) . "," . sn(pv('expected_ctc')) . "," . sn(pv('notice_period_days')) . "," . sv($conn, pv('source', 'direct')) . "," . sv($conn, pv('source_details')) . "," . sv($conn, $resume) . "," . sv($conn, pv('portfolio_url')) . "," . sv($conn, pv('linkedin_url')) . "," . sv($conn, pv('notes')) . ",'active'," . ($uid ?: 'NULL') . "," . ($uid ?: 'NULL') . ")";
    if (!mysqli_query($conn, $sql))
      throw new Exception(mysqli_error($conn));
    $cid = mysqli_insert_id($conn);
    $appCode = hiring_next_code($conn, 'hiring_applications', 'application_code', 'APP-');
    $as = pv('application_status', 'applied');
    $appSql = "INSERT INTO hiring_applications(application_code,post_id,candidate_id,applied_date,application_status,screening_score,screening_notes,expected_joining_date,created_by,updated_by) VALUES(" . sv($conn, $appCode) . ",$postId,$cid," . sd($conn, pv('applied_date', date('Y-m-d'))) . "," . sv($conn, $as) . "," . sn(pv('screening_score')) . "," . sv($conn, pv('screening_notes')) . "," . sd($conn, pv('expected_joining_date')) . "," . ($uid ?: 'NULL') . "," . ($uid ?: 'NULL') . ")";
    if (!mysqli_query($conn, $appSql))
      throw new Exception(mysqli_error($conn));
    $aid = mysqli_insert_id($conn);
    hiring_log($conn, 'candidate', $cid, 'CREATE', null, 'active', 'Added candidate ' . $code);
    hiring_log($conn, 'application', $aid, 'APPLY', null, $as, 'Candidate applied');
    header('Location: ../hiring-candidates.php?saved=1');
    exit;
  }
  if ($action === 'update_application_status') {
    if (!hiring_can($conn, 'can_edit'))
      throw new Exception('No edit permission.');
    $id = (int) pv('application_id');
    $new = pv('application_status');
    $remarks = pv('remarks');
    $oldQ = mysqli_query($conn, "SELECT application_status FROM hiring_applications WHERE id=$id");
    $old = $oldQ ? mysqli_fetch_assoc($oldQ) : null;
    $extra = '';
    if ($new === 'selected')
      $extra = ', selected_at=NOW()';
    if ($new === 'rejected')
      $extra = ', rejected_at=NOW(), rejection_reason=' . sv($conn, $remarks);
    mysqli_query($conn, "UPDATE hiring_applications SET application_status=" . sv($conn, $new) . ",updated_by=" . ($uid ?: 'NULL') . "$extra WHERE id=$id");
    hiring_log($conn, 'application', $id, 'STATUS', $old['application_status'] ?? null, $new, $remarks);
    header('Location: ../hiring-interview-view.php?application_id=' . $id . '&updated=1');
    exit;
  }
  if ($action === 'add_interview_round') {
    if (!hiring_can($conn, 'can_create') && !hiring_can($conn, 'can_edit'))
      throw new Exception('No interview permission.');
    $aid = (int) pv('application_id');
    $q = mysqli_query($conn, "SELECT COALESCE(MAX(round_no),0)+1 n FROM hiring_interview_rounds WHERE application_id=$aid");
    $round = ($q && ($r = mysqli_fetch_assoc($q))) ? (int) $r['n'] : 1;
    $sql = "INSERT INTO hiring_interview_rounds(application_id,round_no,round_title,round_type,interviewer_employee_id,scheduled_at,completed_at,mode,location_or_link,technical_score,communication_score,attitude_score,overall_score,performance_summary,strengths,weaknesses,interviewer_notes,round_status,recommendation,created_by,updated_by) VALUES($aid,$round," . sv($conn, pv('round_title', 'Round ' . $round)) . "," . sv($conn, pv('round_type', 'technical')) . "," . sn(pv('interviewer_employee_id')) . "," . (pv('scheduled_at') ? sv($conn, pv('scheduled_at')) : 'NULL') . "," . (pv('completed_at') ? sv($conn, pv('completed_at')) : 'NULL') . "," . sv($conn, pv('mode', 'in_person')) . "," . sv($conn, pv('location_or_link')) . "," . sn(pv('technical_score')) . "," . sn(pv('communication_score')) . "," . sn(pv('attitude_score')) . "," . sn(pv('overall_score')) . "," . sv($conn, pv('performance_summary')) . "," . sv($conn, pv('strengths')) . "," . sv($conn, pv('weaknesses')) . "," . sv($conn, pv('interviewer_notes')) . "," . sv($conn, pv('round_status', 'scheduled')) . "," . sv($conn, pv('recommendation', 'pending')) . "," . ($uid ?: 'NULL') . "," . ($uid ?: 'NULL') . ")";
    if (!mysqli_query($conn, $sql))
      throw new Exception(mysqli_error($conn));
    $rid = mysqli_insert_id($conn);
    mysqli_query($conn, "UPDATE hiring_applications SET application_status='interview',updated_by=" . ($uid ?: 'NULL') . " WHERE id=$aid AND application_status NOT IN ('selected','rejected','onboarding','converted')");
    hiring_log($conn, 'interview', $rid, 'CREATE', null, pv('round_status', 'scheduled'), 'Added interview round');
    header('Location: ../hiring-interview-view.php?application_id=' . $aid . '&saved=1');
    exit;
  }

  if ($action === 'update_interview_round') {
    if (!hiring_can($conn, 'can_edit'))
      throw new Exception('No permission to update interview round.');
    $rid = (int) pv('round_id');
    if ($rid <= 0)
      throw new Exception('Invalid interview round.');
    $oldQ = mysqli_query($conn, "SELECT ir.*,a.application_status FROM hiring_interview_rounds ir INNER JOIN hiring_applications a ON a.id=ir.application_id WHERE ir.id=$rid LIMIT 1");
    $old = $oldQ ? mysqli_fetch_assoc($oldQ) : null;
    if (!$old)
      throw new Exception('Interview round not found.');
    $aid = (int) $old['application_id'];
    $roundStatus = pv('round_status', 'scheduled');
    $rec = pv('recommendation', 'pending');
    $scheduled = pv('scheduled_at') ? str_replace('T', ' ', pv('scheduled_at')) : '';
    $completed = pv('completed_at') ? str_replace('T', ' ', pv('completed_at')) : '';
    $sql = "UPDATE hiring_interview_rounds SET round_title=" . sv($conn, pv('round_title', 'Round')) . ",round_type=" . sv($conn, pv('round_type', 'technical')) . ",interviewer_employee_id=" . sn(pv('interviewer_employee_id')) . ",scheduled_at=" . ($scheduled !== '' ? sv($conn, $scheduled) : 'NULL') . ",completed_at=" . ($completed !== '' ? sv($conn, $completed) : 'NULL') . ",mode=" . sv($conn, pv('mode', 'in_person')) . ",location_or_link=" . sv($conn, pv('location_or_link')) . ",technical_score=" . sn(pv('technical_score')) . ",communication_score=" . sn(pv('communication_score')) . ",attitude_score=" . sn(pv('attitude_score')) . ",overall_score=" . sn(pv('overall_score')) . ",performance_summary=" . sv($conn, pv('performance_summary')) . ",strengths=" . sv($conn, pv('strengths')) . ",weaknesses=" . sv($conn, pv('weaknesses')) . ",interviewer_notes=" . sv($conn, pv('interviewer_notes')) . ",round_status=" . sv($conn, $roundStatus) . ",recommendation=" . sv($conn, $rec) . ",updated_by=" . ($uid ?: 'NULL') . " WHERE id=$rid";
    if (!mysqli_query($conn, $sql))
      throw new Exception(mysqli_error($conn));

    $newStatus = '';
    if ($rec === 'select')
      $newStatus = 'selected';
    elseif ($rec === 'fail' || $roundStatus === 'failed')
      $newStatus = 'rejected';
    elseif ($rec === 'next_round' || $rec === 'hold' || $roundStatus === 'completed' || $roundStatus === 'passed')
      $newStatus = 'interview';

    if ($newStatus !== '') {
      $extra = '';
      if ($newStatus === 'selected')
        $extra = ', selected_at=NOW()';
      if ($newStatus === 'rejected')
        $extra = ', rejected_at=NOW()';
      mysqli_query($conn, "UPDATE hiring_applications SET application_status=" . sv($conn, $newStatus) . ",updated_by=" . ($uid ?: 'NULL') . "$extra WHERE id=$aid");
    }

    hiring_log($conn, 'interview', $rid, 'UPDATE', $old['round_status'] ?? null, $roundStatus, 'Updated interview round result for application #' . $aid);
    header('Location: ../hiring-interview-view.php?application_id=' . $aid . '&updated=1');
    exit;
  }

  if ($action === 'delete_interview_round') {
    if (!hiring_can($conn, 'can_delete'))
      throw new Exception('No permission to delete interview round.');
    $rid = (int) pv('round_id');
    if ($rid <= 0)
      throw new Exception('Invalid interview round.');
    $oldQ = mysqli_query($conn, "SELECT application_id,round_status FROM hiring_interview_rounds WHERE id=$rid LIMIT 1");
    $old = $oldQ ? mysqli_fetch_assoc($oldQ) : null;
    if (!$old)
      throw new Exception('Interview round not found.');
    $aid = (int) $old['application_id'];
    mysqli_query($conn, "DELETE FROM hiring_interview_rounds WHERE id=$rid");
    hiring_log($conn, 'interview', $rid, 'DELETE', $old['round_status'] ?? null, 'deleted', 'Deleted interview round from application #' . $aid);
    header('Location: ../hiring-interview-view.php?application_id=' . $aid . '&deleted=1');
    exit;
  }


  if ($action === 'move_to_onboarding') {
    if (!hiring_can($conn, 'can_edit'))
      throw new Exception('No edit permission.');
    $aid = (int) pv('application_id');
    $oldQ = mysqli_query($conn, "SELECT application_status FROM hiring_applications WHERE id=$aid");
    $old = $oldQ ? mysqli_fetch_assoc($oldQ) : null;
    $sql = "INSERT INTO hiring_onboarding(application_id,offer_date,offered_ctc,joining_date,reporting_manager_employee_id,office_location_id,onboarding_status,document_status,verification_status,approval_remarks,created_by,updated_by) VALUES($aid," . sd($conn, pv('offer_date', date('Y-m-d'))) . "," . sn(pv('offered_ctc')) . "," . sd($conn, pv('joining_date')) . "," . sn(pv('reporting_manager_employee_id')) . "," . sn(pv('office_location_id')) . ",'pending_approval'," . sv($conn, pv('document_status', 'pending')) . "," . sv($conn, pv('verification_status', 'pending')) . "," . sv($conn, pv('approval_remarks')) . "," . ($uid ?: 'NULL') . "," . ($uid ?: 'NULL') . ") ON DUPLICATE KEY UPDATE offer_date=VALUES(offer_date),offered_ctc=VALUES(offered_ctc),joining_date=VALUES(joining_date),reporting_manager_employee_id=VALUES(reporting_manager_employee_id),office_location_id=VALUES(office_location_id),onboarding_status='pending_approval',document_status=VALUES(document_status),verification_status=VALUES(verification_status),approval_remarks=VALUES(approval_remarks),updated_by=VALUES(updated_by)";
    if (!mysqli_query($conn, $sql))
      throw new Exception(mysqli_error($conn));
    mysqli_query($conn, "UPDATE hiring_applications SET application_status='onboarding',final_offered_ctc=" . sn(pv('offered_ctc')) . ",expected_joining_date=" . sd($conn, pv('joining_date')) . ",updated_by=" . ($uid ?: 'NULL') . " WHERE id=$aid");
    hiring_log($conn, 'application', $aid, 'ONBOARDING', $old['application_status'] ?? null, 'onboarding', 'Moved to onboarding');
    header('Location: ../hiring-onboarding.php?saved=1');
    exit;
  }
  if ($action === 'approve_and_convert') {
    if (!hiring_can($conn, 'can_edit'))
      throw new Exception('No approval permission.');
    $oid = (int) pv('onboarding_id');
    $q = mysqli_query($conn, "SELECT o.*,a.id application_id,a.candidate_id,c.full_name,c.email,c.mobile_number,c.date_of_birth,c.gender,c.current_location,p.role_id,p.division_id FROM hiring_onboarding o INNER JOIN hiring_applications a ON a.id=o.application_id INNER JOIN hiring_candidates c ON c.id=a.candidate_id INNER JOIN hiring_posts p ON p.id=a.post_id WHERE o.id=$oid LIMIT 1");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if (!$row)
      throw new Exception('Onboarding not found.');
    if (!empty($row['employee_id']))
      $empId = (int) $row['employee_id'];
    else {
      $eq = mysqli_query($conn, "SELECT employee_code FROM employees WHERE employee_code LIKE 'EMP%' ORDER BY id DESC LIMIT 1");
      $next = 1;
      if ($eq && ($er = mysqli_fetch_assoc($eq))) {
        $num = (int) preg_replace('/[^0-9]/', '', $er['employee_code']);
        $next = max(1, $num + 1);
      }
      $empCode = 'EMP' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
      $join = hiring_date_or_null(pv('joining_date', $row['joining_date'] ?? date('Y-m-d'))) ?: date('Y-m-d');
      $office = sn(pv('office_location_id', $row['office_location_id'] ?? ''));
      $manager = sn(pv('reporting_manager_employee_id', $row['reporting_manager_employee_id'] ?? ''));
      $empSql = "INSERT INTO employees(user_id,full_name,employee_code,date_of_birth,gender,mobile_number,email,current_address,date_of_joining,division_id,office_location_id,role_id,reporting_to,work_location,employee_status,created_by,updated_by) VALUES(NULL," . sv($conn, $row['full_name']) . "," . sv($conn, $empCode) . "," . sd($conn, $row['date_of_birth']) . "," . (!empty($row['gender']) ? sv($conn, $row['gender']) : 'NULL') . "," . sv($conn, $row['mobile_number'] ?? '') . "," . sv($conn, $row['email'] ?? '') . "," . sv($conn, $row['current_location'] ?? '') . "," . sd($conn, $join) . "," . sn($row['division_id'] ?? '') . ",$office," . sn($row['role_id'] ?? '') . ",$manager," . sv($conn, $row['current_location'] ?? '') . ",'active'," . ($uid ?: 'NULL') . "," . ($uid ?: 'NULL') . ")";
      if (!mysqli_query($conn, $empSql))
        throw new Exception(mysqli_error($conn));
      $empId = mysqli_insert_id($conn);
      hiring_log($conn, 'employee', $empId, 'CREATE', null, 'active', 'Converted candidate to employee');
    }
    mysqli_query($conn, "UPDATE hiring_onboarding SET onboarding_status='converted',approved_by=" . ($uid ?: 'NULL') . ",approved_at=NOW(),employee_id=$empId,approval_remarks=" . sv($conn, pv('approval_remarks', 'Approved and converted to employee')) . ",updated_by=" . ($uid ?: 'NULL') . " WHERE id=$oid");
    mysqli_query($conn, "UPDATE hiring_applications SET application_status='converted',employee_id=$empId,onboarded_at=NOW(),updated_by=" . ($uid ?: 'NULL') . " WHERE id=" . (int) $row['application_id']);
    mysqli_query($conn, "UPDATE hiring_candidates SET candidate_status='converted',updated_by=" . ($uid ?: 'NULL') . " WHERE id=" . (int) $row['candidate_id']);
    hiring_log($conn, 'onboarding', $oid, 'APPROVE_CONVERT', $row['onboarding_status'] ?? null, 'converted', 'Approved and converted to employee #' . $empId);
    header('Location: ../hiring-onboarding.php?converted=1');
    exit;
  }
  throw new Exception('Invalid action.');
} catch (Throwable $e) {
  $errorUrl = '../hiring-posts.php?error=' . urlencode($e->getMessage());
  if (!empty($_POST['application_id'])) {
    $errorUrl = '../hiring-interview-view.php?application_id=' . (int) $_POST['application_id'] . '&error=' . urlencode($e->getMessage());
  } elseif (!empty($_POST['round_id'])) {
    $rid = (int) $_POST['round_id'];
    $rq = mysqli_query($conn, "SELECT application_id FROM hiring_interview_rounds WHERE id=$rid LIMIT 1");
    if ($rq && ($rr = mysqli_fetch_assoc($rq)))
      $errorUrl = '../hiring-interview-view.php?application_id=' . (int) $rr['application_id'] . '&error=' . urlencode($e->getMessage());
  }
  header('Location: ' . $errorUrl);
  exit;
}
?>