<?php
session_start();
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/hiring-helper.php";

if (empty($_SESSION["user_id"]) && empty($_SESSION["employee_id"])) {
    header("Location: login.php");
    exit;
}

if (function_exists("require_permission")) {
    require_permission($conn, "can_view", "hiring-onboarding.php");
} elseif (!hiring_can($conn, "can_view", "hiring-onboarding.php")) {
    header("Location: dashboard.php?error=" . urlencode("You do not have hiring onboarding access."));
    exit;
}

function e($v){ return hiring_e($v); }

if (!function_exists('hiring_badge_class')) {
    function hiring_badge_class($status) {
        $status = strtolower((string)$status);
        if (in_array($status, ['open','active','selected','approved','joined','converted','onboarded','passed','completed','pass','select','next_round','verified'], true)) return 'green';
        if (in_array($status, ['draft','applied','screening','shortlisted','interview','scheduled','pending_approval','pending','partial','in_progress','hold'], true)) return 'amber';
        if (in_array($status, ['rejected','failed','cancelled','closed','withdrawn','no_show','fail'], true)) return 'red';
        return 'blue';
    }
}

function badge_class($status){ return hiring_badge_class($status); }
function show_date($d){ return ($d && $d !== "0000-00-00") ? date("d M Y", strtotime($d)) : "-"; }
function money_show($v){ return ($v !== null && $v !== "") ? number_format((float)$v, 2) : "-"; }

$canCreate = hiring_can($conn, "can_create", "hiring-onboarding.php");
$canEdit = hiring_can($conn, "can_edit", "hiring-onboarding.php");
$canDelete = hiring_can($conn, "can_delete", "hiring-onboarding.php");

$pageMessageType = "";
$pageMessageText = "";
if (isset($_GET["saved"])) { $pageMessageType = "success"; $pageMessageText = "Onboarding saved successfully."; }
elseif (isset($_GET["updated"])) { $pageMessageType = "success"; $pageMessageText = "Onboarding updated successfully."; }
elseif (isset($_GET["converted"])) { $pageMessageType = "success"; $pageMessageText = "Candidate approved and converted to employee successfully."; }
elseif (isset($_GET["error"])) { $pageMessageType = "error"; $pageMessageText = trim($_GET["error"]) ?: "Something went wrong."; }

$search = trim($_GET["q"] ?? "");
$statusFilter = trim($_GET["status"] ?? "");
$postFilter = (int)($_GET["post_id"] ?? 0);

$posts = [];
$q = mysqli_query($conn, "SELECT id, hiring_code, title FROM hiring_posts WHERE deleted_at IS NULL ORDER BY created_at DESC");
while ($q && ($r = mysqli_fetch_assoc($q))) $posts[] = $r;

$employees = [];
$q = mysqli_query($conn, "SELECT id, full_name, employee_code FROM employees WHERE employee_status='active' ORDER BY full_name ASC");
while ($q && ($r = mysqli_fetch_assoc($q))) $employees[] = $r;

$officeLocations = [];
$q = mysqli_query($conn, "SELECT id, location_name FROM master_office_locations WHERE is_active=1 ORDER BY sort_order ASC, location_name ASC");
while ($q && ($r = mysqli_fetch_assoc($q))) $officeLocations[] = $r;

$where = ["c.deleted_at IS NULL"];
if ($search !== "") {
    $s = mysqli_real_escape_string($conn, $search);
    $where[] = "(c.full_name LIKE '%$s%' OR c.email LIKE '%$s%' OR c.mobile_number LIKE '%$s%' OR c.candidate_code LIKE '%$s%' OR a.application_code LIKE '%$s%' OR hp.title LIKE '%$s%')";
}
if ($statusFilter !== "") {
    $where[] = "COALESCE(o.onboarding_status, a.application_status)='" . mysqli_real_escape_string($conn, $statusFilter) . "'";
}
if ($postFilter > 0) {
    $where[] = "a.post_id=$postFilter";
}
$whereSql = implode(" AND ", $where);

$onboardingRows = [];
$q = mysqli_query($conn, "
    SELECT
        a.id AS application_id,
        a.application_code,
        a.application_status,
        a.final_offered_ctc,
        a.expected_joining_date,
        a.employee_id AS application_employee_id,
        c.id AS candidate_id,
        c.candidate_code,
        c.full_name,
        c.email,
        c.mobile_number,
        c.gender,
        c.date_of_birth,
        c.current_location,
        c.current_company,
        c.current_designation,
        c.total_experience,
        c.current_ctc,
        c.expected_ctc,
        c.notice_period_days,
        c.resume_path,
        hp.id AS post_id,
        hp.title AS post_title,
        hp.hiring_code,
        hp.role_id,
        hp.division_id,
        hp.department_id,
        hp.work_location,
        cd.division_name,
        r.role_name,
        md.department_name,
        o.id AS onboarding_id,
        o.offer_date,
        o.offered_ctc,
        o.joining_date,
        o.reporting_manager_employee_id,
        o.office_location_id,
        o.onboarding_status,
        o.document_status,
        o.verification_status,
        o.approval_remarks,
        o.approved_by,
        o.approved_at,
        o.rejected_by,
        o.rejected_at,
        o.employee_id,
        manager.full_name AS reporting_manager_name,
        office.location_name AS office_location_name,
        emp.employee_code AS converted_employee_code,
        emp.full_name AS converted_employee_name,
        COALESCE(creator_emp.full_name, creator.username) AS created_username,
        COALESCE(updater_emp.full_name, updater.username) AS updated_username
    FROM hiring_applications a
    INNER JOIN hiring_candidates c ON c.id=a.candidate_id
    INNER JOIN hiring_posts hp ON hp.id=a.post_id
    LEFT JOIN company_divisions cd ON cd.id=hp.division_id
    LEFT JOIN roles r ON r.id=hp.role_id
    LEFT JOIN master_departments md ON md.id=hp.department_id
    LEFT JOIN hiring_onboarding o ON o.application_id=a.id
    LEFT JOIN employees manager ON manager.id=o.reporting_manager_employee_id
    LEFT JOIN master_office_locations office ON office.id=o.office_location_id
    LEFT JOIN employees emp ON emp.id=o.employee_id
    LEFT JOIN users creator ON creator.id=COALESCE(o.created_by, a.created_by)
    LEFT JOIN employees creator_emp ON creator_emp.id=creator.employee_id
    LEFT JOIN users updater ON updater.id=COALESCE(o.updated_by, a.updated_by)
    LEFT JOIN employees updater_emp ON updater_emp.id=updater.employee_id
    WHERE $whereSql
      AND (
        a.application_status IN ('selected','onboarding','onboarded','converted')
        OR o.id IS NOT NULL
      )
    ORDER BY COALESCE(o.updated_at, a.updated_at, a.created_at) DESC
");
while ($q && ($r = mysqli_fetch_assoc($q))) $onboardingRows[] = $r;

$stats = [
    "total" => count($onboardingRows),
    "pending" => 0,
    "approved" => 0,
    "converted" => 0,
];
foreach ($onboardingRows as $row) {
    $st = $row["onboarding_status"] ?: $row["application_status"];
    if (in_array($st, ["pending_approval","draft","selected","onboarding"], true)) $stats["pending"]++;
    if (in_array($st, ["approved","joined"], true)) $stats["approved"]++;
    if (in_array($st, ["converted"], true) || !empty($row["employee_id"])) $stats["converted"]++;
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Hiring Onboarding - TEK-C</title>
  <?php include("includes/links.php"); ?>
  <style>
    .page-head-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
    .kpi-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:18px;display:flex;align-items:center;gap:14px;height:100%}
    .kpi-icon{width:50px;height:50px;border-radius:18px;display:flex;align-items:center;justify-content:center}
    .filter-card,.section-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:14px}
    .status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:5px 11px;font-size:12px;font-weight:900}
    .status-pill.green{background:rgba(16,185,129,.12);color:#059669}
    .status-pill.amber{background:rgba(245,158,11,.14);color:#b45309}
    .status-pill.red{background:rgba(239,68,68,.12);color:#dc2626}
    .status-pill.blue{background:rgba(37,99,235,.10);color:#2563eb}
    .modal-content{background:var(--card-bg);color:var(--text-main);border:1px solid var(--border-soft);border-radius:24px;box-shadow:var(--shadow-card)}
    .modal-header,.modal-footer{border-color:var(--border-soft)}
    .detail-mini{border:1px solid var(--border-soft);border-radius:16px;padding:10px;background:rgba(148,163,184,.06)}
    @media(max-width:767px){.project-table{min-width:1250px}}
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
            <h1 class="h4 fw-bold mb-1">Hiring Onboarding</h1>
            <p class="text-muted-custom mb-0 small">Move selected candidates into onboarding, approve onboarding and convert them to employees.</p>
          </div>
          <a href="hiring-interviews.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Back to Interviews</a>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-primary-subtle text-primary"><i data-lucide="users"></i></div><div><div class="text-muted-custom small fw-bold">Onboarding Rows</div><div class="h4 fw-bold mb-0"><?= (int)$stats["total"] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-warning-subtle text-warning"><i data-lucide="clock"></i></div><div><div class="text-muted-custom small fw-bold">Pending</div><div class="h4 fw-bold mb-0"><?= (int)$stats["pending"] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-info-subtle text-info"><i data-lucide="badge-check"></i></div><div><div class="text-muted-custom small fw-bold">Approved</div><div class="h4 fw-bold mb-0"><?= (int)$stats["approved"] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-success-subtle text-success"><i data-lucide="user-check"></i></div><div><div class="text-muted-custom small fw-bold">Converted</div><div class="h4 fw-bold mb-0"><?= (int)$stats["converted"] ?></div></div></div></div>
      </div>

      <form class="filter-card mb-3" method="GET" action="hiring-onboarding.php">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-lg-4">
            <label class="form-label fw-bold small">Search</label>
            <input class="form-control rounded-4" name="q" value="<?= e($search) ?>" placeholder="Candidate, post, mobile, email">
          </div>
          <div class="col-12 col-lg-3">
            <label class="form-label fw-bold small">Hiring Post</label>
            <select class="form-select rounded-4" name="post_id">
              <option value="0">All Posts</option>
              <?php foreach($posts as $p): ?>
                <option value="<?= (int)$p["id"] ?>" <?= $postFilter===(int)$p["id"]?"selected":"" ?>><?= e($p["hiring_code"]." - ".$p["title"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-3">
            <label class="form-label fw-bold small">Status</label>
            <select class="form-select rounded-4" name="status">
              <option value="">All</option>
              <?php foreach(["selected","onboarding","draft","pending_approval","approved","rejected","joined","converted","cancelled"] as $s): ?>
                <option value="<?= e($s) ?>" <?= $statusFilter===$s?"selected":"" ?>><?= e(ucwords(str_replace("_"," ",$s))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-2 d-flex gap-2">
            <button class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button>
            <a href="hiring-onboarding.php" class="btn btn-outline-secondary rounded-4 fw-bold">Reset</a>
          </div>
        </div>
      </form>

      <section class="section-card overflow-hidden">
        <div class="d-flex justify-content-between align-items-center p-2 pb-3">
          <div>
            <h2 class="fw-bold fs-6 mb-1">Selected / Onboarding Candidates</h2>
            <p class="text-muted-custom small mb-0">Review candidate details, update onboarding info, approve and convert to employee.</p>
          </div>
        </div>

        <div class="d-none d-md-block overflow-auto thin-scrollbar">
          <table class="project-table w-100">
            <thead>
              <tr>
                <th>Candidate</th>
                <th>Hiring Post</th>
                <th>Contact</th>
                <th>Offer / Joining</th>
                <th>Checks</th>
                <th>Status</th>
                <th>Converted Employee</th>
                <th>Audit</th>
                <th style="width:280px;">Action</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$onboardingRows): ?>
              <tr><td colspan="9" class="text-center text-muted-custom fw-bold py-4">No selected/onboarding candidates found.</td></tr>
            <?php endif; ?>

            <?php foreach($onboardingRows as $row): ?>
              <?php
                $status = $row["onboarding_status"] ?: $row["application_status"];
                $hasOnboarding = !empty($row["onboarding_id"]);
                $converted = !empty($row["employee_id"]);
              ?>
              <tr>
                <td>
                  <div class="fw-bold"><?= e($row["full_name"]) ?></div>
                  <small class="text-muted-custom fw-bold"><?= e($row["candidate_code"]) ?> / <?= e($row["application_code"]) ?></small>
                </td>
                <td>
                  <div class="fw-bold"><?= e($row["post_title"]) ?></div>
                  <small class="text-muted-custom"><?= e($row["division_name"] ?: "-") ?> · <?= e($row["role_name"] ?: "-") ?></small>
                </td>
                <td><small><?= e($row["mobile_number"] ?: "-") ?><br><?= e($row["email"] ?: "-") ?></small></td>
                <td>
                  <small>Offer: <?= money_show($row["offered_ctc"] ?: $row["final_offered_ctc"]) ?><br>Join: <?= show_date($row["joining_date"] ?: $row["expected_joining_date"]) ?></small>
                </td>
                <td>
                  <small>Docs: <span class="status-pill <?= badge_class($row["document_status"] ?: "pending") ?>"><?= e(ucwords(str_replace("_"," ",$row["document_status"] ?: "pending"))) ?></span><br>
                  Verify: <span class="status-pill <?= badge_class($row["verification_status"] ?: "pending") ?>"><?= e(ucwords(str_replace("_"," ",$row["verification_status"] ?: "pending"))) ?></span></small>
                </td>
                <td><span class="status-pill <?= badge_class($status) ?>"><?= e(ucwords(str_replace("_"," ",$status))) ?></span></td>
                <td>
                  <?php if ($converted): ?>
                    <div class="fw-bold"><?= e($row["converted_employee_name"] ?: "-") ?></div>
                    <small class="text-muted-custom"><?= e($row["converted_employee_code"] ?: "-") ?></small>
                  <?php else: ?>
                    <span class="text-muted-custom">-</span>
                  <?php endif; ?>
                </td>
                <td><small class="text-muted-custom">Created: <?= e($row["created_username"] ?: "-") ?><br>Updated: <?= e($row["updated_username"] ?: "-") ?></small></td>
                <td>
                  <?php if ($canEdit): ?>
                    <button
                      class="btn btn-sm btn-outline-primary rounded-4 fw-bold onboardBtn"
                      data-row='<?= e(json_encode($row)) ?>'
                      data-bs-toggle="modal"
                      data-bs-target="#onboardModal">
                      <?= $hasOnboarding ? "Update Onboarding" : "Move Onboarding" ?>
                    </button>
                  <?php endif; ?>

                  <?php if ($canEdit && $hasOnboarding && !$converted): ?>
                    <button
                      class="btn btn-sm btn-success rounded-4 fw-bold approveBtn"
                      data-row='<?= e(json_encode($row)) ?>'
                      data-bs-toggle="modal"
                      data-bs-target="#approveModal">
                      Approve & Convert
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="d-md-none">
          <?php foreach($onboardingRows as $row): ?>
            <?php $status = $row["onboarding_status"] ?: $row["application_status"]; ?>
            <div class="mobile-project-card">
              <div class="d-flex justify-content-between gap-2">
                <div>
                  <h6 class="fw-bold mb-1"><?= e($row["full_name"]) ?></h6>
                  <small class="text-muted-custom fw-bold"><?= e($row["post_title"]) ?></small>
                </div>
                <span class="status-pill <?= badge_class($status) ?>"><?= e(ucwords(str_replace("_"," ",$status))) ?></span>
              </div>
              <hr>
              <div class="small text-muted-custom fw-bold mb-2"><?= e($row["mobile_number"]) ?> · <?= e($row["email"]) ?></div>
              <?php if ($canEdit): ?>
                <button class="btn btn-sm btn-outline-primary rounded-4 fw-bold onboardBtn" data-row='<?= e(json_encode($row)) ?>' data-bs-toggle="modal" data-bs-target="#onboardModal">Update Onboarding</button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <?php include("includes/footer.php"); ?>
    </section>
  </main>
  <div id="settingsOverlay"></div>
  <?php if (file_exists(__DIR__ . "/includes/rightsidbar.php")) include("includes/rightsidbar.php"); ?>
</div>

<div class="modal fade" id="onboardModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <form class="modal-content" method="POST" action="api/process-hiring.php">
      <input type="hidden" name="action" value="move_to_onboarding">
      <input type="hidden" name="application_id" id="onboardApplicationId">

      <div class="modal-header">
        <h5 class="modal-title fw-bold">Onboarding Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-4"><div class="detail-mini"><small class="text-muted-custom fw-bold">Candidate</small><div class="fw-bold" id="onboardCandidateName">-</div></div></div>
          <div class="col-md-4"><div class="detail-mini"><small class="text-muted-custom fw-bold">Hiring Post</small><div class="fw-bold" id="onboardPostTitle">-</div></div></div>
          <div class="col-md-4"><div class="detail-mini"><small class="text-muted-custom fw-bold">Role / Division</small><div class="fw-bold" id="onboardRoleDivision">-</div></div></div>
        </div>

        <div class="row g-3">
          <div class="col-md-4"><label class="form-label fw-bold small">Offer Date</label><input type="date" class="form-control rounded-4" name="offer_date" id="offerDate" value="<?= date("Y-m-d") ?>"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Offered CTC</label><input class="form-control rounded-4" name="offered_ctc" id="offeredCtc"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Joining Date</label><input type="date" class="form-control rounded-4" name="joining_date" id="joiningDate"></div>

          <div class="col-md-6">
            <label class="form-label fw-bold small">Reporting Manager</label>
            <select class="form-select rounded-4" name="reporting_manager_employee_id" id="reportingManager">
              <option value="">Select</option>
              <?php foreach($employees as $emp): ?>
                <option value="<?= (int)$emp["id"] ?>"><?= e($emp["full_name"]." (".$emp["employee_code"].")") ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-bold small">Office Location</label>
            <select class="form-select rounded-4" name="office_location_id" id="officeLocation">
              <option value="">Select</option>
              <?php foreach($officeLocations as $ol): ?>
                <option value="<?= (int)$ol["id"] ?>"><?= e($ol["location_name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-bold small">Document Status</label>
            <select class="form-select rounded-4" name="document_status" id="documentStatus">
              <option value="pending">Pending</option>
              <option value="partial">Partial</option>
              <option value="completed">Completed</option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-bold small">Verification Status</label>
            <select class="form-select rounded-4" name="verification_status" id="verificationStatus">
              <option value="pending">Pending</option>
              <option value="in_progress">In Progress</option>
              <option value="verified">Verified</option>
              <option value="failed">Failed</option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label fw-bold small">Onboarding / Approval Notes</label>
            <textarea class="form-control rounded-4" name="approval_remarks" id="approvalRemarks" rows="3"></textarea>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Close</button>
        <button class="btn brand-gradient text-white rounded-4 fw-bold">Save Onboarding</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="approveModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="api/process-hiring.php">
      <input type="hidden" name="action" value="approve_and_convert">
      <input type="hidden" name="onboarding_id" id="approveOnboardingId">

      <div class="modal-header">
        <h5 class="modal-title fw-bold">Approve & Convert to Employee</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="alert alert-warning rounded-4 fw-bold">
          This will approve onboarding and create an employee record from the selected candidate.
        </div>

        <label class="form-label fw-bold small">Joining Date</label>
        <input type="date" class="form-control rounded-4 mb-3" name="joining_date" id="approveJoiningDate">

        <label class="form-label fw-bold small">Reporting Manager</label>
        <select class="form-select rounded-4 mb-3" name="reporting_manager_employee_id" id="approveReportingManager">
          <option value="">Select</option>
          <?php foreach($employees as $emp): ?>
            <option value="<?= (int)$emp["id"] ?>"><?= e($emp["full_name"]." (".$emp["employee_code"].")") ?></option>
          <?php endforeach; ?>
        </select>

        <label class="form-label fw-bold small">Office Location</label>
        <select class="form-select rounded-4 mb-3" name="office_location_id" id="approveOfficeLocation">
          <option value="">Select</option>
          <?php foreach($officeLocations as $ol): ?>
            <option value="<?= (int)$ol["id"] ?>"><?= e($ol["location_name"]) ?></option>
          <?php endforeach; ?>
        </select>

        <label class="form-label fw-bold small">Approval Remarks</label>
        <textarea class="form-control rounded-4" name="approval_remarks" rows="3">Approved and converted to employee</textarea>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-success rounded-4 fw-bold">Approve & Convert</button>
      </div>
    </form>
  </div>
</div>

<?php include("includes/script.php"); ?>
<script src="assets/js/script.js?v=41"></script>
<script>
document.addEventListener("DOMContentLoaded", function(){
  if(window.lucide && window.lucide.createIcons) window.lucide.createIcons();

  function val(v){ return v === null || v === undefined ? "" : v; }

  document.querySelectorAll(".onboardBtn").forEach(btn => {
    btn.addEventListener("click", () => {
      const row = JSON.parse(btn.dataset.row || "{}");

      document.getElementById("onboardApplicationId").value = val(row.application_id);
      document.getElementById("onboardCandidateName").textContent = val(row.full_name) || "-";
      document.getElementById("onboardPostTitle").textContent = (val(row.hiring_code) ? val(row.hiring_code) + " - " : "") + (val(row.post_title) || "-");
      document.getElementById("onboardRoleDivision").textContent = (val(row.division_name) || "-") + " / " + (val(row.role_name) || "-");

      document.getElementById("offerDate").value = val(row.offer_date);
      document.getElementById("offeredCtc").value = val(row.offered_ctc || row.final_offered_ctc);
      document.getElementById("joiningDate").value = val(row.joining_date || row.expected_joining_date);
      document.getElementById("reportingManager").value = val(row.reporting_manager_employee_id);
      document.getElementById("officeLocation").value = val(row.office_location_id);
      document.getElementById("documentStatus").value = val(row.document_status || "pending");
      document.getElementById("verificationStatus").value = val(row.verification_status || "pending");
      document.getElementById("approvalRemarks").value = val(row.approval_remarks);
    });
  });

  document.querySelectorAll(".approveBtn").forEach(btn => {
    btn.addEventListener("click", () => {
      const row = JSON.parse(btn.dataset.row || "{}");

      document.getElementById("approveOnboardingId").value = val(row.onboarding_id);
      document.getElementById("approveJoiningDate").value = val(row.joining_date || row.expected_joining_date);
      document.getElementById("approveReportingManager").value = val(row.reporting_manager_employee_id);
      document.getElementById("approveOfficeLocation").value = val(row.office_location_id);
    });
  });
});
</script>
</body>
</html>
