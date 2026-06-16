<?php
session_start();
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/hiring-helper.php";

if (empty($_SESSION["user_id"]) && empty($_SESSION["employee_id"])) {
    header("Location: login.php");
    exit;
}

if (function_exists("require_permission")) {
    require_permission($conn, "can_view", "hiring-posts.php");
} elseif (!hiring_can($conn, "can_view", "hiring-posts.php")) {
    header("Location: dashboard.php?error=" . urlencode("You do not have hiring posts access."));
    exit;
}

function e($v){ return hiring_e($v); }

function badge_class($status) {
    $status = strtolower((string)$status);
    if (in_array($status, ["open","active"], true)) return "green";
    if (in_array($status, ["draft","paused"], true)) return "amber";
    if (in_array($status, ["closed","cancelled"], true)) return "red";
    return "blue";
}

function money_show($v) {
    return ($v !== null && $v !== "") ? number_format((float)$v, 2) : "-";
}

$canCreate = hiring_can($conn, "can_create", "hiring-posts.php");
$canEdit = hiring_can($conn, "can_edit", "hiring-posts.php");
$canDelete = hiring_can($conn, "can_delete", "hiring-posts.php");

$pageMessageType = "";
$pageMessageText = "";
if (isset($_GET["saved"])) { $pageMessageType = "success"; $pageMessageText = "Hiring post saved successfully."; }
elseif (isset($_GET["updated"])) { $pageMessageType = "success"; $pageMessageText = "Hiring post updated successfully."; }
elseif (isset($_GET["deleted"])) { $pageMessageType = "success"; $pageMessageText = "Hiring post deleted successfully."; }
elseif (isset($_GET["error"])) { $pageMessageType = "error"; $pageMessageText = trim($_GET["error"]) ?: "Something went wrong."; }

$search = trim($_GET["q"] ?? "");
$statusFilter = trim($_GET["status"] ?? "");
$divisionFilter = (int)($_GET["division_id"] ?? 0);

$divisions = [];
$q = mysqli_query($conn, "SELECT id, division_name FROM company_divisions WHERE is_active=1 ORDER BY sort_order ASC, division_name ASC");
while ($q && ($r = mysqli_fetch_assoc($q))) $divisions[] = $r;

$roles = [];
$q = mysqli_query($conn, "SELECT id, role_name FROM roles ORDER BY role_name ASC");
while ($q && ($r = mysqli_fetch_assoc($q))) $roles[] = $r;

$departments = [];
$q = mysqli_query($conn, "SELECT id, department_name FROM master_departments WHERE is_active=1 ORDER BY department_name ASC");
while ($q && ($r = mysqli_fetch_assoc($q))) $departments[] = $r;

$where = ["hp.deleted_at IS NULL"];
if ($search !== "") {
    $s = mysqli_real_escape_string($conn, $search);
    $where[] = "(hp.hiring_code LIKE '%$s%' OR hp.title LIKE '%$s%' OR r.role_name LIKE '%$s%' OR cd.division_name LIKE '%$s%')";
}
if ($statusFilter !== "") {
    $where[] = "hp.status='" . mysqli_real_escape_string($conn, $statusFilter) . "'";
}
if ($divisionFilter > 0) {
    $where[] = "hp.division_id=$divisionFilter";
}
$whereSql = implode(" AND ", $where);

$posts = [];
$q = mysqli_query($conn, "
    SELECT hp.*, cd.division_name, r.role_name, md.department_name,
           COALESCE(creator_emp.full_name, creator.username) AS created_username, COALESCE(updater_emp.full_name, updater.username) AS updated_username,
           (SELECT COUNT(*) FROM hiring_applications a WHERE a.post_id=hp.id) AS application_count
    FROM hiring_posts hp
    LEFT JOIN company_divisions cd ON cd.id=hp.division_id
    LEFT JOIN roles r ON r.id=hp.role_id
    LEFT JOIN master_departments md ON md.id=hp.department_id
    LEFT JOIN users creator ON creator.id=hp.created_by
    LEFT JOIN employees creator_emp ON creator_emp.id=creator.employee_id
    LEFT JOIN users updater ON updater.id=hp.updated_by
    LEFT JOIN employees updater_emp ON updater_emp.id=updater.employee_id
    WHERE $whereSql
    ORDER BY hp.created_at DESC
");
while ($q && ($r = mysqli_fetch_assoc($q))) $posts[] = $r;
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Hiring Posts - TEK-C</title>
  <?php include("includes/links.php"); ?>
  <style>
    .page-head-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
    .filter-card,.section-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:14px}
    .status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:5px 11px;font-size:12px;font-weight:900}
    .status-pill.green{background:rgba(16,185,129,.12);color:#059669}
    .status-pill.amber{background:rgba(245,158,11,.14);color:#b45309}
    .status-pill.red{background:rgba(239,68,68,.12);color:#dc2626}
    .status-pill.blue{background:rgba(37,99,235,.10);color:#2563eb}
    .modal-content{background:var(--card-bg);color:var(--text-main);border:1px solid var(--border-soft);border-radius:24px;box-shadow:var(--shadow-card)}
    .modal-header,.modal-footer{border-color:var(--border-soft)}
    @media(max-width:767px){.project-table{min-width:1050px}}
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
            <h1 class="h4 fw-bold mb-1">Hiring Posts</h1>
            <p class="text-muted-custom mb-0 small">Create, edit and delete hiring posts only.</p>
          </div>
          <?php if ($canCreate): ?>
            <button class="btn brand-gradient text-white rounded-4 fw-bold btn-sm" data-bs-toggle="modal" data-bs-target="#postModal">
              <i data-lucide="plus" style="width:15px"></i> New Hiring Post
            </button>
          <?php endif; ?>
        </div>
      </div>

      <form class="filter-card mb-3" method="GET" action="hiring-posts.php">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-lg-4">
            <label class="form-label fw-bold small">Search</label>
            <input class="form-control rounded-4" name="q" value="<?= e($search) ?>" placeholder="Search hiring title, role, division">
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <label class="form-label fw-bold small">Division</label>
            <select class="form-select rounded-4" name="division_id">
              <option value="0">All</option>
              <?php foreach($divisions as $d): ?>
                <option value="<?= (int)$d["id"] ?>" <?= $divisionFilter===(int)$d["id"]?"selected":"" ?>><?= e($d["division_name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <label class="form-label fw-bold small">Status</label>
            <select class="form-select rounded-4" name="status">
              <option value="">All</option>
              <?php foreach(["draft","open","paused","closed","cancelled"] as $s): ?>
                <option value="<?= e($s) ?>" <?= $statusFilter===$s?"selected":"" ?>><?= e(ucwords(str_replace("_"," ",$s))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-2 d-flex gap-2">
            <button class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button>
            <a href="hiring-posts.php" class="btn btn-outline-secondary rounded-4 fw-bold">Reset</a>
          </div>
        </div>
      </form>

      <section class="section-card overflow-hidden">
        <div class="d-flex align-items-center justify-content-between p-2 pb-3">
          <div>
            <h2 class="fw-bold fs-6 mb-1">All Hiring Posts</h2>
            <p class="text-muted-custom small mb-0">Only add, edit and delete post actions are available here.</p>
          </div>
        </div>

        <div class="d-none d-md-block overflow-auto thin-scrollbar">
          <table class="project-table w-100">
            <thead>
              <tr>
                <th>Hiring</th>
                <th>Division / Role</th>
                <th>Openings</th>
                <th>Experience</th>
                <th>Salary</th>
                <th>Status</th>
                <th>Applications</th>
                <th>Audit</th>
                <th style="width:180px">Action</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$posts): ?>
              <tr><td colspan="9" class="text-center text-muted-custom fw-bold py-4">No hiring posts found.</td></tr>
            <?php endif; ?>
            <?php foreach($posts as $p): ?>
              <tr>
                <td><div class="fw-bold"><?= e($p["title"]) ?></div><small class="text-muted-custom fw-bold"><?= e($p["hiring_code"]) ?></small></td>
                <td><div class="fw-bold"><?= e($p["division_name"] ?: "-") ?></div><small class="text-muted-custom"><?= e($p["role_name"] ?: "-") ?></small></td>
                <td><?= (int)$p["openings"] ?></td>
                <td><?= e($p["experience_min"]) ?> - <?= e($p["experience_max"]) ?> yrs</td>
                <td><?= money_show($p["salary_min"]) ?> - <?= money_show($p["salary_max"]) ?></td>
                <td><span class="status-pill <?= badge_class($p["status"]) ?>"><?= e(ucwords($p["status"])) ?></span></td>
                <td><b><?= (int)$p["application_count"] ?></b> applied</td>
                <td><small class="text-muted-custom">Created: <?= e($p["created_username"] ?: "-") ?><br>Updated: <?= e($p["updated_username"] ?: "-") ?></small></td>
                <td>
                  <?php if($canEdit): ?>
                    <button class="btn btn-sm btn-outline-primary rounded-4 fw-bold editPostBtn" data-post='<?= e(json_encode($p)) ?>' data-bs-toggle="modal" data-bs-target="#postModal">Edit</button>
                  <?php endif; ?>
                  <?php if($canDelete): ?>
                    <form method="POST" action="api/process-hiring.php" class="d-inline" onsubmit="return confirm('Delete this hiring post?')">
                      <input type="hidden" name="action" value="delete_post">
                      <input type="hidden" name="id" value="<?= (int)$p["id"] ?>">
                      <button class="btn btn-sm btn-outline-danger rounded-4 fw-bold">Delete</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="d-md-none">
          <?php foreach($posts as $p): ?>
            <div class="mobile-project-card">
              <div class="d-flex justify-content-between gap-2">
                <div>
                  <h6 class="fw-bold mb-1"><?= e($p["title"]) ?></h6>
                  <small class="text-muted-custom fw-bold"><?= e($p["hiring_code"]) ?></small>
                </div>
                <span class="status-pill <?= badge_class($p["status"]) ?>"><?= e(ucwords($p["status"])) ?></span>
              </div>
              <hr>
              <div class="small text-muted-custom fw-bold mb-2"><?= e($p["division_name"] ?: "-") ?> · <?= e($p["role_name"] ?: "-") ?></div>
              <div class="d-flex gap-2">
                <?php if($canEdit): ?><button class="btn btn-sm btn-outline-primary rounded-4 fw-bold editPostBtn" data-post='<?= e(json_encode($p)) ?>' data-bs-toggle="modal" data-bs-target="#postModal">Edit</button><?php endif; ?>
                <?php if($canDelete): ?><form method="POST" action="api/process-hiring.php" onsubmit="return confirm('Delete this hiring post?')"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="id" value="<?= (int)$p["id"] ?>"><button class="btn btn-sm btn-outline-danger rounded-4 fw-bold">Delete</button></form><?php endif; ?>
              </div>
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

<div class="modal fade" id="postModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <form class="modal-content" method="POST" action="api/process-hiring.php">
      <input type="hidden" name="action" id="postAction" value="create_post">
      <input type="hidden" name="id" id="postId">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Hiring Post</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label fw-bold small">Hiring Title *</label><input class="form-control rounded-4" name="title" id="postTitle" required></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Status</label><select class="form-select rounded-4" name="status" id="postStatus"><?php foreach(["draft","open","paused","closed","cancelled"] as $s): ?><option value="<?= e($s) ?>"><?= e(ucwords($s)) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Division</label><select class="form-select rounded-4" name="division_id" id="postDivision"><option value="">Select</option><?php foreach($divisions as $d): ?><option value="<?= (int)$d["id"] ?>"><?= e($d["division_name"]) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Role / Designation</label><select class="form-select rounded-4" name="role_id" id="postRole"><option value="">Select</option><?php foreach($roles as $r): ?><option value="<?= (int)$r["id"] ?>"><?= e($r["role_name"]) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Department</label><select class="form-select rounded-4" name="department_id" id="postDepartment"><option value="">Select</option><?php foreach($departments as $d): ?><option value="<?= (int)$d["id"] ?>"><?= e($d["department_name"]) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Employment Type</label><select class="form-select rounded-4" name="employment_type" id="postEmployment"><?php foreach(["full_time","part_time","contract","internship","temporary"] as $s): ?><option value="<?= e($s) ?>"><?= e(ucwords(str_replace("_"," ",$s))) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Openings</label><input type="number" class="form-control rounded-4" name="openings" id="postOpenings" value="1"></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Priority</label><select class="form-select rounded-4" name="priority" id="postPriority"><?php foreach(["low","normal","high","urgent"] as $s): ?><option value="<?= e($s) ?>"><?= e(ucwords($s)) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Work Location</label><input class="form-control rounded-4" name="work_location" id="postLocation"></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Exp Min</label><input class="form-control rounded-4" name="experience_min" id="postExpMin"></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Exp Max</label><input class="form-control rounded-4" name="experience_max" id="postExpMax"></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Salary Min</label><input class="form-control rounded-4" name="salary_min" id="postSalaryMin"></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Salary Max</label><input class="form-control rounded-4" name="salary_max" id="postSalaryMax"></div>
          <div class="col-md-6"><label class="form-label fw-bold small">Target Joining Date</label><input type="date" class="form-control rounded-4" name="target_joining_date" id="postJoining"></div>
          <div class="col-md-6"><label class="form-label fw-bold small">Application Deadline</label><input type="date" class="form-control rounded-4" name="application_deadline" id="postDeadline"></div>
          <div class="col-12"><label class="form-label fw-bold small">Job Description</label><textarea class="form-control rounded-4" name="job_description" id="postDescription" rows="3"></textarea></div>
          <div class="col-md-6"><label class="form-label fw-bold small">Requirements</label><textarea class="form-control rounded-4" name="requirements" id="postRequirements" rows="3"></textarea></div>
          <div class="col-md-6"><label class="form-label fw-bold small">Benefits</label><textarea class="form-control rounded-4" name="benefits" id="postBenefits" rows="3"></textarea></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Close</button><button class="btn brand-gradient text-white rounded-4 fw-bold">Save Hiring Post</button></div>
    </form>
  </div>
</div>

<?php include("includes/script.php"); ?>
<script src="assets/js/script.js?v=41"></script>
<script>
document.addEventListener("DOMContentLoaded", function(){
  if(window.lucide && window.lucide.createIcons) window.lucide.createIcons();

  document.querySelectorAll(".editPostBtn").forEach(btn => {
    btn.addEventListener("click", () => {
      const p = JSON.parse(btn.dataset.post || "{}");
      document.getElementById("postAction").value = "update_post";
      document.getElementById("postId").value = p.id || "";
      document.getElementById("postTitle").value = p.title || "";
      document.getElementById("postStatus").value = p.status || "open";
      document.getElementById("postDivision").value = p.division_id || "";
      document.getElementById("postRole").value = p.role_id || "";
      document.getElementById("postDepartment").value = p.department_id || "";
      document.getElementById("postEmployment").value = p.employment_type || "full_time";
      document.getElementById("postOpenings").value = p.openings || "1";
      document.getElementById("postPriority").value = p.priority || "normal";
      document.getElementById("postLocation").value = p.work_location || "";
      document.getElementById("postExpMin").value = p.experience_min || "";
      document.getElementById("postExpMax").value = p.experience_max || "";
      document.getElementById("postSalaryMin").value = p.salary_min || "";
      document.getElementById("postSalaryMax").value = p.salary_max || "";
      document.getElementById("postJoining").value = p.target_joining_date || "";
      document.getElementById("postDeadline").value = p.application_deadline || "";
      document.getElementById("postDescription").value = p.job_description || "";
      document.getElementById("postRequirements").value = p.requirements || "";
      document.getElementById("postBenefits").value = p.benefits || "";
    });
  });

  document.getElementById("postModal")?.addEventListener("hidden.bs.modal", function(){
    document.getElementById("postAction").value = "create_post";
    document.getElementById("postId").value = "";
    this.querySelector("form").reset();
  });
});
</script>
</body>
</html>
