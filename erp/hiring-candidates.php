<?php
session_start();
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/hiring-helper.php";

if (empty($_SESSION["user_id"]) && empty($_SESSION["employee_id"])) {
    header("Location: login.php");
    exit;
}

if (function_exists("require_permission")) {
    require_permission($conn, "can_view", "hiring-candidates.php");
} elseif (!hiring_can($conn, "can_view", "hiring-candidates.php")) {
    header("Location: dashboard.php?error=" . urlencode("You do not have hiring candidates access."));
    exit;
}

function e($v){ return hiring_e($v); }
function badge_class($status) {
    $status = strtolower((string)$status);
    if (in_array($status, ["active","selected","converted"], true)) return "green";
    if (in_array($status, ["new","applied","screening","shortlisted","interview","hold"], true)) return "amber";
    if (in_array($status, ["rejected","withdrawn","blacklisted"], true)) return "red";
    return "blue";
}

$canCreate = hiring_can($conn, "can_create", "hiring-candidates.php");
$canEdit = hiring_can($conn, "can_edit", "hiring-candidates.php");

$pageMessageType = "";
$pageMessageText = "";
if (isset($_GET["saved"])) { $pageMessageType = "success"; $pageMessageText = "Candidate added successfully."; }
elseif (isset($_GET["updated"])) { $pageMessageType = "success"; $pageMessageText = "Candidate updated successfully."; }
elseif (isset($_GET["error"])) { $pageMessageType = "error"; $pageMessageText = trim($_GET["error"]) ?: "Something went wrong."; }

$postId = (int)($_GET["post_id"] ?? 0);
$search = trim($_GET["q"] ?? "");

$posts = [];
$q = mysqli_query($conn, "
    SELECT hp.id, hp.hiring_code, hp.title, hp.status, cd.division_name, r.role_name
    FROM hiring_posts hp
    LEFT JOIN company_divisions cd ON cd.id=hp.division_id
    LEFT JOIN roles r ON r.id=hp.role_id
    WHERE hp.deleted_at IS NULL
    ORDER BY hp.created_at DESC
");
while ($q && ($r = mysqli_fetch_assoc($q))) $posts[] = $r;

$selectedPost = null;
if ($postId > 0) {
    $q = mysqli_query($conn, "
        SELECT hp.*, cd.division_name, r.role_name
        FROM hiring_posts hp
        LEFT JOIN company_divisions cd ON cd.id=hp.division_id
        LEFT JOIN roles r ON r.id=hp.role_id
        WHERE hp.id=$postId AND hp.deleted_at IS NULL
        LIMIT 1
    ");
    if ($q) $selectedPost = mysqli_fetch_assoc($q);
}

$applications = [];
if ($postId > 0 && $selectedPost) {
    $where = ["a.post_id=$postId", "c.deleted_at IS NULL"];
    if ($search !== "") {
        $s = mysqli_real_escape_string($conn, $search);
        $where[] = "(c.full_name LIKE '%$s%' OR c.email LIKE '%$s%' OR c.mobile_number LIKE '%$s%' OR c.candidate_code LIKE '%$s%' OR a.application_code LIKE '%$s%')";
    }
    $whereSql = implode(" AND ", $where);

    $q = mysqli_query($conn, "
        SELECT a.*, c.full_name, c.email, c.mobile_number, c.candidate_code, c.total_experience,
               c.current_company, c.current_designation, c.current_location, c.expected_ctc,
               c.notice_period_days, c.source, c.resume_path,
               COALESCE(creator_emp.full_name, creator.username) AS created_username,
               COALESCE(updater_emp.full_name, updater.username) AS updated_username
        FROM hiring_applications a
        INNER JOIN hiring_candidates c ON c.id=a.candidate_id
        LEFT JOIN users creator ON creator.id=a.created_by
        LEFT JOIN employees creator_emp ON creator_emp.id=creator.employee_id
        LEFT JOIN users updater ON updater.id=a.updated_by
        LEFT JOIN employees updater_emp ON updater_emp.id=updater.employee_id
        WHERE $whereSql
        ORDER BY a.created_at DESC
    ");
    while ($q && ($r = mysqli_fetch_assoc($q))) $applications[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Hiring Candidates - TEK-C</title>
  <?php include("includes/links.php"); ?>
  <style>
    .page-head-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
    .filter-card,.section-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:14px}
    .post-card{border:1px solid var(--border-soft);border-radius:18px;background:rgba(148,163,184,.06);padding:14px}
    .status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:5px 11px;font-size:12px;font-weight:900}
    .status-pill.green{background:rgba(16,185,129,.12);color:#059669}
    .status-pill.amber{background:rgba(245,158,11,.14);color:#b45309}
    .status-pill.red{background:rgba(239,68,68,.12);color:#dc2626}
    .status-pill.blue{background:rgba(37,99,235,.10);color:#2563eb}
    .modal-content{background:var(--card-bg);color:var(--text-main);border:1px solid var(--border-soft);border-radius:24px;box-shadow:var(--shadow-card)}
    .modal-header,.modal-footer{border-color:var(--border-soft)}
    @media(max-width:767px){.project-table{min-width:1100px}}
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
            <h1 class="h4 fw-bold mb-1">Hiring Candidates</h1>
            <p class="text-muted-custom mb-0 small">Select one hiring post, then add applied candidates only for that post.</p>
          </div>
          <?php if ($canCreate): ?>
            <button class="btn brand-gradient text-white rounded-4 fw-bold btn-sm" data-bs-toggle="modal" data-bs-target="#candidateModal" <?= !$selectedPost ? "disabled" : "" ?>>
              <i data-lucide="user-plus" style="width:15px"></i> Add Candidate
            </button>
          <?php endif; ?>
        </div>
      </div>

      <form class="filter-card mb-3" method="GET" action="hiring-candidates.php">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-lg-6">
            <label class="form-label fw-bold small">Select Hiring Post <span class="text-danger">*</span></label>
            <select class="form-select rounded-4" name="post_id" id="postPicker" required>
              <option value="">-- Select Hiring Post --</option>
              <?php foreach($posts as $p): ?>
                <option value="<?= (int)$p["id"] ?>" <?= $postId===(int)$p["id"]?"selected":"" ?>>
                  <?= e($p["hiring_code"]) ?> - <?= e($p["title"]) ?> (<?= e($p["division_name"] ?: "-") ?> / <?= e($p["role_name"] ?: "-") ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label fw-bold small">Search Candidate</label>
            <input class="form-control rounded-4" name="q" value="<?= e($search) ?>" placeholder="Candidate name, mobile, email">
          </div>
          <div class="col-12 col-lg-2 d-flex gap-2">
            <button class="btn brand-gradient text-white rounded-4 fw-bold w-100">Load</button>
            <a href="hiring-candidates.php" class="btn btn-outline-secondary rounded-4 fw-bold">Reset</a>
          </div>
        </div>
      </form>

      <?php if ($selectedPost): ?>
        <div class="post-card mb-3">
          <div class="d-flex flex-column flex-lg-row justify-content-lg-between gap-2">
            <div>
              <div class="fw-bold"><?= e($selectedPost["title"]) ?></div>
              <small class="text-muted-custom fw-bold"><?= e($selectedPost["hiring_code"]) ?> · <?= e($selectedPost["division_name"] ?: "-") ?> · <?= e($selectedPost["role_name"] ?: "-") ?></small>
            </div>
            <span class="status-pill <?= badge_class($selectedPost["status"]) ?>"><?= e(ucwords($selectedPost["status"])) ?></span>
          </div>
        </div>
      <?php else: ?>
        <div class="alert alert-warning rounded-4 fw-bold">Please select a hiring post first. Candidate add button will enable after selecting a post.</div>
      <?php endif; ?>

      <section class="section-card overflow-hidden">
        <div class="d-flex align-items-center justify-content-between p-2 pb-3">
          <div>
            <h2 class="fw-bold fs-6 mb-1">Applied Candidates</h2>
            <p class="text-muted-custom small mb-0">This page shows candidates only for the selected hiring post.</p>
          </div>
        </div>

        <div class="d-none d-md-block overflow-auto thin-scrollbar">
          <table class="project-table w-100">
            <thead>
              <tr>
                <th>Candidate</th>
                <th>Contact</th>
                <th>Experience</th>
                <th>Current Details</th>
                <th>Expected CTC</th>
                <th>Status</th>
                <th>Source</th>
                <th>Audit</th>
                <th>Resume</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$selectedPost): ?>
              <tr><td colspan="9" class="text-center text-muted-custom fw-bold py-4">Select a hiring post to view candidates.</td></tr>
            <?php elseif (!$applications): ?>
              <tr><td colspan="9" class="text-center text-muted-custom fw-bold py-4">No candidates added for this post yet.</td></tr>
            <?php endif; ?>
            <?php foreach($applications as $a): ?>
              <tr>
                <td><div class="fw-bold"><?= e($a["full_name"]) ?></div><small class="text-muted-custom fw-bold"><?= e($a["candidate_code"]) ?> / <?= e($a["application_code"]) ?></small></td>
                <td><small><?= e($a["mobile_number"] ?: "-") ?><br><?= e($a["email"] ?: "-") ?></small></td>
                <td><?= e($a["total_experience"] ?: "-") ?> yrs</td>
                <td><small><?= e($a["current_company"] ?: "-") ?><br><?= e($a["current_designation"] ?: "-") ?><br><?= e($a["current_location"] ?: "-") ?></small></td>
                <td><?= e($a["expected_ctc"] ?: "-") ?><br><small class="text-muted-custom">Notice: <?= e($a["notice_period_days"] ?: "-") ?> days</small></td>
                <td><span class="status-pill <?= badge_class($a["application_status"]) ?>"><?= e(ucwords(str_replace("_"," ",$a["application_status"]))) ?></span></td>
                <td><?= e(ucwords(str_replace("_"," ",$a["source"] ?: "-"))) ?></td>
                <td><small class="text-muted-custom">Created: <?= e($a["created_username"] ?: "-") ?><br>Updated: <?= e($a["updated_username"] ?: "-") ?></small></td>
                <td>
                  <?php if (!empty($a["resume_path"])): ?>
                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold" href="<?= e($a["resume_path"]) ?>" target="_blank">Resume</a>
                  <?php else: ?>
                    <span class="text-muted-custom">-</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="d-md-none">
          <?php foreach($applications as $a): ?>
            <div class="mobile-project-card">
              <div class="d-flex justify-content-between gap-2">
                <div>
                  <h6 class="fw-bold mb-1"><?= e($a["full_name"]) ?></h6>
                  <small class="text-muted-custom fw-bold"><?= e($a["candidate_code"]) ?></small>
                </div>
                <span class="status-pill <?= badge_class($a["application_status"]) ?>"><?= e(ucwords(str_replace("_"," ",$a["application_status"]))) ?></span>
              </div>
              <hr>
              <small class="text-muted-custom fw-bold"><?= e($a["mobile_number"]) ?> · <?= e($a["email"]) ?></small>
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

<div class="modal fade" id="candidateModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <form class="modal-content" method="POST" action="api/process-hiring.php" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add_candidate">
      <input type="hidden" name="post_id" value="<?= (int)$postId ?>">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Add Candidate for Selected Post</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if ($selectedPost): ?>
          <div class="alert alert-light border rounded-4 fw-bold">
            <?= e($selectedPost["hiring_code"]) ?> - <?= e($selectedPost["title"]) ?>
          </div>
        <?php endif; ?>

        <div class="row g-3">
          <div class="col-md-4"><label class="form-label fw-bold small">Candidate Name *</label><input class="form-control rounded-4" name="full_name" required></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Applied Date</label><input type="date" class="form-control rounded-4" name="applied_date" value="<?= date("Y-m-d") ?>"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Application Status</label><select class="form-select rounded-4" name="application_status"><option value="applied">Applied</option><option value="screening">Screening</option><option value="shortlisted">Shortlisted</option></select></div>

          <div class="col-md-4"><label class="form-label fw-bold small">Email</label><input type="email" class="form-control rounded-4" name="email"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Mobile</label><input class="form-control rounded-4" name="mobile_number"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Gender</label><select class="form-select rounded-4" name="gender"><option value="">Select</option><option>Male</option><option>Female</option><option>Other</option></select></div>

          <div class="col-md-3"><label class="form-label fw-bold small">DOB</label><input type="date" class="form-control rounded-4" name="date_of_birth"></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Experience</label><input class="form-control rounded-4" name="total_experience"></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Current CTC</label><input class="form-control rounded-4" name="current_ctc"></div>
          <div class="col-md-3"><label class="form-label fw-bold small">Expected CTC</label><input class="form-control rounded-4" name="expected_ctc"></div>

          <div class="col-md-4"><label class="form-label fw-bold small">Current Company</label><input class="form-control rounded-4" name="current_company"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Current Designation</label><input class="form-control rounded-4" name="current_designation"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Current Location</label><input class="form-control rounded-4" name="current_location"></div>

          <div class="col-md-4"><label class="form-label fw-bold small">Notice Period Days</label><input class="form-control rounded-4" name="notice_period_days"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Source</label><select class="form-select rounded-4" name="source"><?php foreach(["direct","referral","job_portal","social_media","consultancy","campus","walk_in","other"] as $s): ?><option value="<?= e($s) ?>"><?= e(ucwords(str_replace("_"," ",$s))) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Source Details</label><input class="form-control rounded-4" name="source_details"></div>

          <div class="col-md-4"><label class="form-label fw-bold small">Resume</label><input type="file" class="form-control rounded-4" name="resume"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Portfolio URL</label><input class="form-control rounded-4" name="portfolio_url"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">LinkedIn URL</label><input class="form-control rounded-4" name="linkedin_url"></div>

          <div class="col-md-4"><label class="form-label fw-bold small">Screening Score</label><input class="form-control rounded-4" name="screening_score"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Expected Joining</label><input type="date" class="form-control rounded-4" name="expected_joining_date"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Screening Notes</label><input class="form-control rounded-4" name="screening_notes"></div>

          <div class="col-12"><label class="form-label fw-bold small">Notes</label><textarea class="form-control rounded-4" name="notes" rows="2"></textarea></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Close</button><button class="btn brand-gradient text-white rounded-4 fw-bold" <?= !$selectedPost ? "disabled" : "" ?>>Save Candidate</button></div>
    </form>
  </div>
</div>

<?php include("includes/script.php"); ?>
<script src="assets/js/script.js?v=41"></script>
<script>
document.addEventListener("DOMContentLoaded", function(){
  if(window.lucide && window.lucide.createIcons) window.lucide.createIcons();

  document.getElementById("postPicker")?.addEventListener("change", function(){
    if (this.value) {
      window.location.href = "hiring-candidates.php?post_id=" + encodeURIComponent(this.value);
    }
  });
});
</script>
</body>
</html>
