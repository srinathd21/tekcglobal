<?php
session_start();
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/hiring-helper.php";

if (empty($_SESSION["user_id"]) && empty($_SESSION["employee_id"])) {
    header("Location: login.php");
    exit;
}

if (function_exists("require_permission")) {
    require_permission($conn, "can_view", "hiring-interviews.php");
} elseif (!hiring_can($conn, "can_view", "hiring-interviews.php")) {
    header("Location: dashboard.php?error=" . urlencode("You do not have hiring interviews access."));
    exit;
}

function e($v){ return hiring_e($v); }

if (!function_exists('hiring_badge_class')) {
    function hiring_badge_class($status) {
        $status = strtolower((string)$status);

        if (in_array($status, ['open','active','selected','approved','joined','converted','onboarded','passed','completed','pass','select','next_round'], true)) {
            return 'green';
        }

        if (in_array($status, ['draft','applied','screening','shortlisted','interview','scheduled','pending_approval','pending','hold'], true)) {
            return 'amber';
        }

        if (in_array($status, ['rejected','failed','cancelled','closed','withdrawn','no_show','fail'], true)) {
            return 'red';
        }

        return 'blue';
    }
}

function badge_class($status){ return hiring_badge_class($status); }
function dt_show($v){ return ($v && $v !== "0000-00-00 00:00:00") ? date("d M Y h:i A", strtotime($v)) : "-"; }
function date_input($v){ return ($v && $v !== "0000-00-00 00:00:00") ? date("Y-m-d\TH:i", strtotime($v)) : ""; }
function score_show($v){ return ($v !== null && $v !== "") ? e($v) : "-"; }

$canCreate = hiring_can($conn, "can_create", "hiring-interviews.php");
$canEdit = hiring_can($conn, "can_edit", "hiring-interviews.php");
$canDelete = hiring_can($conn, "can_delete", "hiring-interviews.php");

$pageMessageType = "";
$pageMessageText = "";
if (isset($_GET["saved"])) { $pageMessageType = "success"; $pageMessageText = "Interview round scheduled successfully."; }
elseif (isset($_GET["updated"])) { $pageMessageType = "success"; $pageMessageText = "Interview result updated successfully."; }
elseif (isset($_GET["deleted"])) { $pageMessageType = "success"; $pageMessageText = "Interview round deleted successfully."; }
elseif (isset($_GET["error"])) { $pageMessageType = "error"; $pageMessageText = trim($_GET["error"]) ?: "Something went wrong."; }

$applicationId = (int)($_GET["application_id"] ?? 0);
if ($applicationId <= 0) {
    header("Location: hiring-interviews.php?error=" . urlencode("Invalid application."));
    exit;
}

$q = mysqli_query($conn, "
    SELECT a.*, c.full_name, c.email, c.mobile_number, c.candidate_code, c.total_experience,
           c.current_company, c.current_designation, c.current_location, c.current_ctc, c.expected_ctc,
           c.notice_period_days, c.source, c.source_details, c.resume_path, c.portfolio_url, c.linkedin_url, c.notes AS candidate_notes,
           hp.title AS post_title, hp.hiring_code, hp.openings, hp.employment_type, hp.work_location, hp.priority,
           hp.job_description, hp.requirements, hp.benefits, cd.division_name, r.role_name, md.department_name,
           COALESCE(creator_emp.full_name, creator.username) AS created_username,
           COALESCE(updater_emp.full_name, updater.username) AS updated_username
    FROM hiring_applications a
    INNER JOIN hiring_candidates c ON c.id=a.candidate_id
    INNER JOIN hiring_posts hp ON hp.id=a.post_id
    LEFT JOIN company_divisions cd ON cd.id=hp.division_id
    LEFT JOIN roles r ON r.id=hp.role_id
    LEFT JOIN master_departments md ON md.id=hp.department_id
    LEFT JOIN users creator ON creator.id=a.created_by
    LEFT JOIN employees creator_emp ON creator_emp.id=creator.employee_id
    LEFT JOIN users updater ON updater.id=a.updated_by
    LEFT JOIN employees updater_emp ON updater_emp.id=updater.employee_id
    WHERE a.id=$applicationId
    LIMIT 1
");
$app = $q ? mysqli_fetch_assoc($q) : null;
if (!$app) {
    header("Location: hiring-interviews.php?error=" . urlencode("Application not found."));
    exit;
}

$employees = [];
$q = mysqli_query($conn, "SELECT id, full_name, employee_code FROM employees WHERE employee_status='active' ORDER BY full_name ASC");
while ($q && ($r = mysqli_fetch_assoc($q))) $employees[] = $r;

$rounds = [];
$q = mysqli_query($conn, "
    SELECT ir.*, emp.full_name AS interviewer_name, emp.employee_code AS interviewer_code,
           COALESCE(creator_emp.full_name, creator.username) AS created_username,
           COALESCE(updater_emp.full_name, updater.username) AS updated_username
    FROM hiring_interview_rounds ir
    LEFT JOIN employees emp ON emp.id=ir.interviewer_employee_id
    LEFT JOIN users creator ON creator.id=ir.created_by
    LEFT JOIN employees creator_emp ON creator_emp.id=creator.employee_id
    LEFT JOIN users updater ON updater.id=ir.updated_by
    LEFT JOIN employees updater_emp ON updater_emp.id=updater.employee_id
    WHERE ir.application_id=$applicationId
    ORDER BY ir.round_no ASC, ir.id ASC
");
while ($q && ($r = mysqli_fetch_assoc($q))) $rounds[] = $r;

$roundCount = count($rounds);
$passed = 0;
$failed = 0;
$scheduled = 0;
$totalOverall = 0;
$overallCount = 0;

foreach ($rounds as $r) {
    if (in_array($r["recommendation"], ["pass","next_round","select"], true) || $r["round_status"] === "passed") $passed++;
    if (in_array($r["recommendation"], ["fail"], true) || $r["round_status"] === "failed") $failed++;
    if ($r["round_status"] === "scheduled") $scheduled++;
    if ($r["overall_score"] !== null && $r["overall_score"] !== "") {
        $totalOverall += (float)$r["overall_score"];
        $overallCount++;
    }
}
$avgOverall = $overallCount > 0 ? round($totalOverall / $overallCount, 2) : null;
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Interview Details - TEK-C</title>
  <?php include("includes/links.php"); ?>
  <style>
    .page-head-card,.section-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
    .detail-box{border:1px solid var(--border-soft);border-radius:18px;background:rgba(148,163,184,.06);padding:14px;height:100%}
    .detail-box small{color:var(--text-muted);font-weight:900}
    .detail-box .value{font-weight:900;color:var(--text-main)}
    .kpi-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:18px;display:flex;align-items:center;gap:14px;height:100%}
    .kpi-icon{width:50px;height:50px;border-radius:18px;display:flex;align-items:center;justify-content:center}
    .status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:5px 11px;font-size:12px;font-weight:900}
    .status-pill.green{background:rgba(16,185,129,.12);color:#059669}
    .status-pill.amber{background:rgba(245,158,11,.14);color:#b45309}
    .status-pill.red{background:rgba(239,68,68,.12);color:#dc2626}
    .status-pill.blue{background:rgba(37,99,235,.10);color:#2563eb}
    .timeline{position:relative}
    .timeline::before{content:"";position:absolute;left:18px;top:0;bottom:0;width:2px;background:var(--border-soft)}
    .round-card{position:relative;margin-left:42px;border:1px solid var(--border-soft);border-radius:20px;background:rgba(148,163,184,.05);padding:16px;margin-bottom:14px}
    .round-card::before{content:"";position:absolute;left:-34px;top:20px;width:18px;height:18px;border-radius:50%;background:#f59e0b;border:4px solid var(--card-bg);box-shadow:0 0 0 1px var(--border-soft)}
    .score-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
    .score-item{border:1px solid var(--border-soft);border-radius:14px;padding:8px;background:var(--card-bg)}
    .modal-content{background:var(--card-bg);color:var(--text-main);border:1px solid var(--border-soft);border-radius:24px;box-shadow:var(--shadow-card)}
    .modal-header,.modal-footer{border-color:var(--border-soft)}
    @media(max-width:767px){.score-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.round-card{margin-left:30px}}
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
            <h1 class="h4 fw-bold mb-1">Interview Details</h1>
            <p class="text-muted-custom mb-0 small">Schedule interviews, update round-wise result, and review candidate performance.</p>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <a href="hiring-interviews.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Back</a>
            <?php if ($canCreate || $canEdit): ?>
              <button class="btn brand-gradient text-white rounded-4 fw-bold btn-sm addRoundBtn" data-bs-toggle="modal" data-bs-target="#roundModal">
                <i data-lucide="calendar-plus" style="width:15px"></i> Schedule Round
              </button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-primary-subtle text-primary"><i data-lucide="messages-square"></i></div><div><div class="text-muted-custom small fw-bold">Total Rounds</div><div class="h4 fw-bold mb-0"><?= (int)$roundCount ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-warning-subtle text-warning"><i data-lucide="calendar-clock"></i></div><div><div class="text-muted-custom small fw-bold">Scheduled</div><div class="h4 fw-bold mb-0"><?= (int)$scheduled ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-success-subtle text-success"><i data-lucide="check-circle"></i></div><div><div class="text-muted-custom small fw-bold">Passed / Positive</div><div class="h4 fw-bold mb-0"><?= (int)$passed ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-info-subtle text-info"><i data-lucide="star"></i></div><div><div class="text-muted-custom small fw-bold">Avg Score</div><div class="h4 fw-bold mb-0"><?= $avgOverall !== null ? e($avgOverall) : "-" ?></div></div></div></div>
      </div>

      <div class="section-card mb-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
          <div>
            <h2 class="fw-bold fs-6 mb-1"><?= e($app["full_name"]) ?></h2>
            <p class="text-muted-custom small mb-0"><?= e($app["candidate_code"]) ?> / <?= e($app["application_code"]) ?></p>
          </div>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="status-pill <?= badge_class($app["application_status"]) ?>"><?= e(ucwords(str_replace("_"," ",$app["application_status"]))) ?></span>
            <?php if ($canEdit): ?>
              <button class="btn btn-sm btn-outline-primary rounded-4 fw-bold" data-bs-toggle="modal" data-bs-target="#applicationStatusModal">
                Update Status
              </button>
            <?php endif; ?>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-4"><div class="detail-box"><small>Hiring Post</small><div class="value"><?= e($app["post_title"]) ?></div><small><?= e($app["hiring_code"]) ?> · <?= e($app["division_name"] ?: "-") ?> · <?= e($app["role_name"] ?: "-") ?></small></div></div>
          <div class="col-md-4"><div class="detail-box"><small>Contact</small><div class="value"><?= e($app["mobile_number"] ?: "-") ?></div><small><?= e($app["email"] ?: "-") ?></small></div></div>
          <div class="col-md-4"><div class="detail-box"><small>Experience</small><div class="value"><?= e($app["total_experience"] ?: "-") ?> years</div><small><?= e($app["current_company"] ?: "-") ?> · <?= e($app["current_designation"] ?: "-") ?></small></div></div>
          <div class="col-md-4"><div class="detail-box"><small>CTC</small><div class="value">Current: <?= e($app["current_ctc"] ?: "-") ?></div><small>Expected: <?= e($app["expected_ctc"] ?: "-") ?></small></div></div>
          <div class="col-md-4"><div class="detail-box"><small>Notice Period</small><div class="value"><?= e($app["notice_period_days"] ?: "-") ?> days</div><small>Location: <?= e($app["current_location"] ?: "-") ?></small></div></div>
          <div class="col-md-4"><div class="detail-box"><small>Resume / Links</small><div class="value">
            <?php if (!empty($app["resume_path"])): ?><a href="<?= e($app["resume_path"]) ?>" target="_blank">Resume</a><?php else: ?>-<?php endif; ?>
          </div><small><?= !empty($app["linkedin_url"]) ? e($app["linkedin_url"]) : "-" ?></small></div></div>
        </div>
      </div>

      <div class="section-card">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
          <div>
            <h2 class="fw-bold fs-6 mb-1">Round-wise Interview Timeline</h2>
            <p class="text-muted-custom small mb-0">Each round stores schedule, interviewer, scores, notes, performance, recommendation and result.</p>
          </div>
          <?php if ($canCreate || $canEdit): ?>
            <button class="btn btn-outline-primary rounded-4 fw-bold btn-sm addRoundBtn" data-bs-toggle="modal" data-bs-target="#roundModal">Add Round</button>
          <?php endif; ?>
        </div>

        <?php if (!$rounds): ?>
          <div class="text-center text-muted-custom fw-bold py-4">No interview rounds scheduled yet.</div>
        <?php else: ?>
          <div class="timeline">
            <?php foreach($rounds as $r): ?>
              <div class="round-card">
                <div class="d-flex flex-column flex-lg-row justify-content-lg-between gap-2 mb-2">
                  <div>
                    <h3 class="fw-bold fs-6 mb-1">Round <?= (int)$r["round_no"] ?> - <?= e($r["round_title"]) ?></h3>
                    <small class="text-muted-custom fw-bold"><?= e(ucwords($r["round_type"])) ?> · <?= e(ucwords(str_replace("_"," ",$r["mode"]))) ?></small>
                  </div>
                  <div class="d-flex flex-wrap gap-2 align-items-start">
                    <span class="status-pill <?= badge_class($r["round_status"]) ?>"><?= e(ucwords(str_replace("_"," ",$r["round_status"]))) ?></span>
                    <span class="status-pill <?= badge_class($r["recommendation"]) ?>"><?= e(ucwords(str_replace("_"," ",$r["recommendation"]))) ?></span>
                  </div>
                </div>

                <div class="row g-2 mb-2">
                  <div class="col-md-4"><small class="text-muted-custom fw-bold">Interviewer</small><div class="fw-bold"><?= e($r["interviewer_name"] ?: "-") ?></div></div>
                  <div class="col-md-4"><small class="text-muted-custom fw-bold">Scheduled</small><div class="fw-bold"><?= e(dt_show($r["scheduled_at"])) ?></div></div>
                  <div class="col-md-4"><small class="text-muted-custom fw-bold">Completed</small><div class="fw-bold"><?= e(dt_show($r["completed_at"])) ?></div></div>
                  <div class="col-12"><small class="text-muted-custom fw-bold">Location / Link</small><div class="fw-bold"><?= e($r["location_or_link"] ?: "-") ?></div></div>
                </div>

                <div class="score-grid mb-2">
                  <div class="score-item"><small>Technical</small><div class="fw-bold"><?= score_show($r["technical_score"]) ?></div></div>
                  <div class="score-item"><small>Communication</small><div class="fw-bold"><?= score_show($r["communication_score"]) ?></div></div>
                  <div class="score-item"><small>Attitude</small><div class="fw-bold"><?= score_show($r["attitude_score"]) ?></div></div>
                  <div class="score-item"><small>Overall</small><div class="fw-bold"><?= score_show($r["overall_score"]) ?></div></div>
                </div>

                <div class="row g-2 mb-2">
                  <div class="col-md-6"><small class="text-muted-custom fw-bold">Performance Summary</small><div><?= nl2br(e($r["performance_summary"] ?: "-")) ?></div></div>
                  <div class="col-md-3"><small class="text-muted-custom fw-bold">Strengths</small><div><?= nl2br(e($r["strengths"] ?: "-")) ?></div></div>
                  <div class="col-md-3"><small class="text-muted-custom fw-bold">Weaknesses</small><div><?= nl2br(e($r["weaknesses"] ?: "-")) ?></div></div>
                  <div class="col-12"><small class="text-muted-custom fw-bold">Interviewer Notes</small><div><?= nl2br(e($r["interviewer_notes"] ?: "-")) ?></div></div>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <small class="text-muted-custom">Created: <?= e($r["created_username"] ?: "-") ?> · Updated: <?= e($r["updated_username"] ?: "-") ?></small>
                  <div class="d-flex gap-2">
                    <?php if ($canEdit): ?>
                      <button class="btn btn-sm btn-outline-primary rounded-4 fw-bold editRoundBtn" data-round='<?= e(json_encode($r)) ?>' data-bs-toggle="modal" data-bs-target="#roundModal">Update Result</button>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                      <form method="POST" action="api/process-hiring.php" onsubmit="return confirm('Delete this interview round?')">
                        <input type="hidden" name="action" value="delete_interview_round">
                        <input type="hidden" name="round_id" value="<?= (int)$r["id"] ?>">
                        <button class="btn btn-sm btn-outline-danger rounded-4 fw-bold">Delete</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php include("includes/footer.php"); ?>
    </section>
  </main>
  <div id="settingsOverlay"></div>
  <?php if (file_exists(__DIR__ . "/includes/rightsidbar.php")) include("includes/rightsidbar.php"); ?>
</div>


<div class="modal fade" id="applicationStatusModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="api/process-hiring.php">
      <input type="hidden" name="action" value="update_application_status">
      <input type="hidden" name="application_id" value="<?= (int)$applicationId ?>">

      <div class="modal-header">
        <h5 class="modal-title fw-bold">Update Application Status</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-bold small">Current Status</label>
          <div>
            <span class="status-pill <?= badge_class($app["application_status"]) ?>">
              <?= e(ucwords(str_replace("_"," ",$app["application_status"]))) ?>
            </span>
          </div>
        </div>

        <label class="form-label fw-bold small">New Status</label>
        <select class="form-select rounded-4 mb-3" name="application_status" required>
          <?php foreach(["applied","screening","shortlisted","interview","hold","selected","rejected","onboarding","onboarded","converted","withdrawn"] as $s): ?>
            <option value="<?= e($s) ?>" <?= $app["application_status"]===$s ? "selected" : "" ?>>
              <?= e(ucwords(str_replace("_"," ",$s))) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label class="form-label fw-bold small">Remarks</label>
        <textarea class="form-control rounded-4" name="remarks" rows="3" placeholder="Reason / note for this status change"></textarea>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Close</button>
        <button class="btn brand-gradient text-white rounded-4 fw-bold">Update Status</button>
      </div>
    </form>
  </div>
</div>


<div class="modal fade" id="roundModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <form class="modal-content" method="POST" action="api/process-hiring.php">
      <input type="hidden" name="action" id="roundAction" value="add_interview_round">
      <input type="hidden" name="application_id" value="<?= (int)$applicationId ?>">
      <input type="hidden" name="round_id" id="roundId">

      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="roundModalTitle">Schedule Interview Round</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label fw-bold small">Round Title</label><input class="form-control rounded-4" name="round_title" id="roundTitle" placeholder="Technical Round / HR Round"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Round Type</label><select class="form-select rounded-4" name="round_type" id="roundType"><?php foreach(["hr","technical","managerial","practical","client","final","other"] as $s): ?><option value="<?= e($s) ?>"><?= e(ucwords($s)) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Interviewer</label><select class="form-select rounded-4" name="interviewer_employee_id" id="roundInterviewer"><option value="">Select</option><?php foreach($employees as $emp): ?><option value="<?= (int)$emp["id"] ?>"><?= e($emp["full_name"]." (".$emp["employee_code"].")") ?></option><?php endforeach; ?></select></div>

          <div class="col-md-4"><label class="form-label fw-bold small">Scheduled At</label><input type="datetime-local" class="form-control rounded-4" name="scheduled_at" id="roundScheduled"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Completed At</label><input type="datetime-local" class="form-control rounded-4" name="completed_at" id="roundCompleted"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Mode</label><select class="form-select rounded-4" name="mode" id="roundMode"><?php foreach(["in_person","phone","video","online_test","other"] as $s): ?><option value="<?= e($s) ?>"><?= e(ucwords(str_replace("_"," ",$s))) ?></option><?php endforeach; ?></select></div>

          <div class="col-12"><label class="form-label fw-bold small">Location / Link</label><input class="form-control rounded-4" name="location_or_link" id="roundLocation"></div>

          <div class="col-md-3"><label class="form-label fw-bold small">Technical Score</label><input class="form-control rounded-4" name="technical_score" id="technicalScore"></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Communication Score</label><input class="form-control rounded-4" name="communication_score" id="communicationScore"></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Attitude Score</label><input class="form-control rounded-4" name="attitude_score" id="attitudeScore"></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Overall Score</label><input class="form-control rounded-4" name="overall_score" id="overallScore"></div>

          <div class="col-md-6"><label class="form-label fw-bold small">Round Status</label><select class="form-select rounded-4" name="round_status" id="roundStatus"><?php foreach(["scheduled","completed","passed","failed","hold","cancelled","no_show"] as $s): ?><option value="<?= e($s) ?>"><?= e(ucwords(str_replace("_"," ",$s))) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label fw-bold small">Recommendation</label><select class="form-select rounded-4" name="recommendation" id="roundRecommendation"><?php foreach(["pending","pass","fail","hold","next_round","select"] as $s): ?><option value="<?= e($s) ?>"><?= e(ucwords(str_replace("_"," ",$s))) ?></option><?php endforeach; ?></select></div>

          <div class="col-12"><label class="form-label fw-bold small">Performance Summary</label><textarea class="form-control rounded-4" name="performance_summary" id="performanceSummary" rows="2"></textarea></div>
          <div class="col-md-6"><label class="form-label fw-bold small">Strengths</label><textarea class="form-control rounded-4" name="strengths" id="strengths" rows="2"></textarea></div>
          <div class="col-md-6"><label class="form-label fw-bold small">Weaknesses</label><textarea class="form-control rounded-4" name="weaknesses" id="weaknesses" rows="2"></textarea></div>
          <div class="col-12"><label class="form-label fw-bold small">Interviewer Notes</label><textarea class="form-control rounded-4" name="interviewer_notes" id="interviewerNotes" rows="2"></textarea></div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Close</button>
        <button class="btn brand-gradient text-white rounded-4 fw-bold">Save Round</button>
      </div>
    </form>
  </div>
</div>

<?php include("includes/script.php"); ?>
<script src="assets/js/script.js?v=41"></script>
<script>
document.addEventListener("DOMContentLoaded", function(){
  if(window.lucide && window.lucide.createIcons) window.lucide.createIcons();

  const modal = document.getElementById("roundModal");

  function resetRoundForm(){
    document.getElementById("roundAction").value = "add_interview_round";
    document.getElementById("roundModalTitle").textContent = "Schedule Interview Round";
    document.getElementById("roundId").value = "";
    modal.querySelector("form").reset();
  }

  function toDateTimeLocal(v){
    if (!v || v === "0000-00-00 00:00:00") return "";
    return String(v).replace(" ", "T").slice(0, 16);
  }

  document.querySelectorAll(".addRoundBtn").forEach(btn => {
    btn.addEventListener("click", resetRoundForm);
  });

  document.querySelectorAll(".editRoundBtn").forEach(btn => {
    btn.addEventListener("click", () => {
      const r = JSON.parse(btn.dataset.round || "{}");
      document.getElementById("roundAction").value = "update_interview_round";
      document.getElementById("roundModalTitle").textContent = "Update Interview Result";
      document.getElementById("roundId").value = r.id || "";
      document.getElementById("roundTitle").value = r.round_title || "";
      document.getElementById("roundType").value = r.round_type || "technical";
      document.getElementById("roundInterviewer").value = r.interviewer_employee_id || "";
      document.getElementById("roundScheduled").value = toDateTimeLocal(r.scheduled_at);
      document.getElementById("roundCompleted").value = toDateTimeLocal(r.completed_at);
      document.getElementById("roundMode").value = r.mode || "in_person";
      document.getElementById("roundLocation").value = r.location_or_link || "";
      document.getElementById("technicalScore").value = r.technical_score || "";
      document.getElementById("communicationScore").value = r.communication_score || "";
      document.getElementById("attitudeScore").value = r.attitude_score || "";
      document.getElementById("overallScore").value = r.overall_score || "";
      document.getElementById("performanceSummary").value = r.performance_summary || "";
      document.getElementById("strengths").value = r.strengths || "";
      document.getElementById("weaknesses").value = r.weaknesses || "";
      document.getElementById("interviewerNotes").value = r.interviewer_notes || "";
      document.getElementById("roundStatus").value = r.round_status || "scheduled";
      document.getElementById("roundRecommendation").value = r.recommendation || "pending";
    });
  });

  modal?.addEventListener("hidden.bs.modal", resetRoundForm);
});
</script>
</body>
</html>
