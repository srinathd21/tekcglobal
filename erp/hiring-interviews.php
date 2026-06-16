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

$canCreate = hiring_can($conn, "can_create", "hiring-interviews.php");
$canEdit = hiring_can($conn, "can_edit", "hiring-interviews.php");

$pageMessageType = "";
$pageMessageText = "";
if (isset($_GET["saved"])) { $pageMessageType = "success"; $pageMessageText = "Interview round saved successfully."; }
elseif (isset($_GET["updated"])) { $pageMessageType = "success"; $pageMessageText = "Interview updated successfully."; }
elseif (isset($_GET["error"])) { $pageMessageType = "error"; $pageMessageText = trim($_GET["error"]) ?: "Something went wrong."; }

$search = trim($_GET["q"] ?? "");
$statusFilter = trim($_GET["status"] ?? "");
$postFilter = (int)($_GET["post_id"] ?? 0);

$posts = [];
$q = mysqli_query($conn, "
    SELECT id, hiring_code, title
    FROM hiring_posts
    WHERE deleted_at IS NULL
    ORDER BY created_at DESC
");
while ($q && ($r = mysqli_fetch_assoc($q))) $posts[] = $r;

$where = ["c.deleted_at IS NULL"];
if ($search !== "") {
    $s = mysqli_real_escape_string($conn, $search);
    $where[] = "(c.full_name LIKE '%$s%' OR c.mobile_number LIKE '%$s%' OR c.email LIKE '%$s%' OR c.candidate_code LIKE '%$s%' OR a.application_code LIKE '%$s%' OR hp.title LIKE '%$s%')";
}
if ($statusFilter !== "") {
    $where[] = "a.application_status='" . mysqli_real_escape_string($conn, $statusFilter) . "'";
}
if ($postFilter > 0) {
    $where[] = "a.post_id=$postFilter";
}
$whereSql = implode(" AND ", $where);

$applications = [];
$q = mysqli_query($conn, "
    SELECT a.*, c.full_name, c.email, c.mobile_number, c.candidate_code, c.total_experience,
           hp.title AS post_title, hp.hiring_code, cd.division_name, r.role_name,
           (SELECT COUNT(*) FROM hiring_interview_rounds ir WHERE ir.application_id=a.id) AS round_count,
           (SELECT COUNT(*) FROM hiring_interview_rounds ir WHERE ir.application_id=a.id AND ir.recommendation IN ('pass','next_round','select')) AS positive_rounds,
           (SELECT ir.round_status FROM hiring_interview_rounds ir WHERE ir.application_id=a.id ORDER BY ir.round_no DESC, ir.id DESC LIMIT 1) AS last_round_status,
           (SELECT ir.recommendation FROM hiring_interview_rounds ir WHERE ir.application_id=a.id ORDER BY ir.round_no DESC, ir.id DESC LIMIT 1) AS last_recommendation,
           (SELECT ir.scheduled_at FROM hiring_interview_rounds ir WHERE ir.application_id=a.id AND ir.round_status='scheduled' ORDER BY ir.scheduled_at ASC LIMIT 1) AS next_schedule
    FROM hiring_applications a
    INNER JOIN hiring_candidates c ON c.id=a.candidate_id
    INNER JOIN hiring_posts hp ON hp.id=a.post_id
    LEFT JOIN company_divisions cd ON cd.id=hp.division_id
    LEFT JOIN roles r ON r.id=hp.role_id
    WHERE $whereSql
    ORDER BY a.updated_at DESC, a.created_at DESC
");
while ($q && ($r = mysqli_fetch_assoc($q))) $applications[] = $r;

$stats = [
    "applications" => count($applications),
    "scheduled" => 0,
    "completed" => 0,
    "selected" => 0,
];
foreach ($applications as $a) {
    if (!empty($a["next_schedule"])) $stats["scheduled"]++;
    if ((int)$a["round_count"] > 0) $stats["completed"]++;
    if (in_array($a["application_status"], ["selected","onboarding","converted"], true)) $stats["selected"]++;
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Hiring Interviews - TEK-C</title>
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
    @media(max-width:767px){.project-table{min-width:1150px}}
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
            <h1 class="h4 fw-bold mb-1">Hiring Interviews</h1>
            <p class="text-muted-custom mb-0 small">View applications, schedule interviews, and open full round-wise result details.</p>
          </div>
          <a href="hiring-candidates.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Back to Candidates</a>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-primary-subtle text-primary"><i data-lucide="users"></i></div><div><div class="text-muted-custom small fw-bold">Applications</div><div class="h4 fw-bold mb-0"><?= (int)$stats["applications"] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-warning-subtle text-warning"><i data-lucide="calendar-clock"></i></div><div><div class="text-muted-custom small fw-bold">Scheduled</div><div class="h4 fw-bold mb-0"><?= (int)$stats["scheduled"] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-info-subtle text-info"><i data-lucide="clipboard-check"></i></div><div><div class="text-muted-custom small fw-bold">With Rounds</div><div class="h4 fw-bold mb-0"><?= (int)$stats["completed"] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-success-subtle text-success"><i data-lucide="user-check"></i></div><div><div class="text-muted-custom small fw-bold">Selected</div><div class="h4 fw-bold mb-0"><?= (int)$stats["selected"] ?></div></div></div></div>
      </div>

      <form class="filter-card mb-3" method="GET" action="hiring-interviews.php">
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
            <label class="form-label fw-bold small">Application Status</label>
            <select class="form-select rounded-4" name="status">
              <option value="">All</option>
              <?php foreach(["applied","screening","shortlisted","interview","hold","selected","rejected","onboarding","converted","withdrawn"] as $s): ?>
                <option value="<?= e($s) ?>" <?= $statusFilter===$s?"selected":"" ?>><?= e(ucwords(str_replace("_"," ",$s))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-2 d-flex gap-2">
            <button class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button>
            <a href="hiring-interviews.php" class="btn btn-outline-secondary rounded-4 fw-bold">Reset</a>
          </div>
        </div>
      </form>

      <section class="section-card overflow-hidden">
        <div class="d-flex justify-content-between align-items-center p-2 pb-3">
          <div>
            <h2 class="fw-bold fs-6 mb-1">Interview Applications</h2>
            <p class="text-muted-custom small mb-0">Click View Details to schedule rounds and update results.</p>
          </div>
        </div>

        <div class="d-none d-md-block overflow-auto thin-scrollbar">
          <table class="project-table w-100">
            <thead>
              <tr>
                <th>Candidate</th>
                <th>Hiring Post</th>
                <th>Contact</th>
                <th>Status</th>
                <th>Rounds</th>
                <th>Next Schedule</th>
                <th>Last Result</th>
                <th style="width:170px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$applications): ?>
                <tr><td colspan="8" class="text-center text-muted-custom fw-bold py-4">No applications found.</td></tr>
              <?php endif; ?>
              <?php foreach($applications as $a): ?>
                <tr>
                  <td><div class="fw-bold"><?= e($a["full_name"]) ?></div><small class="text-muted-custom fw-bold"><?= e($a["candidate_code"]) ?> / <?= e($a["application_code"]) ?></small></td>
                  <td><div class="fw-bold"><?= e($a["post_title"]) ?></div><small class="text-muted-custom"><?= e($a["division_name"] ?: "-") ?> · <?= e($a["role_name"] ?: "-") ?></small></td>
                  <td><small><?= e($a["mobile_number"] ?: "-") ?><br><?= e($a["email"] ?: "-") ?></small></td>
                  <td><span class="status-pill <?= badge_class($a["application_status"]) ?>"><?= e(ucwords(str_replace("_"," ",$a["application_status"]))) ?></span></td>
                  <td><b><?= (int)$a["round_count"] ?></b> rounds<br><small><?= (int)$a["positive_rounds"] ?> positive</small></td>
                  <td><?= e(dt_show($a["next_schedule"])) ?></td>
                  <td>
                    <span class="status-pill <?= badge_class($a["last_round_status"]) ?>"><?= e($a["last_round_status"] ? ucwords(str_replace("_"," ",$a["last_round_status"])) : "-") ?></span>
                    <br><small class="text-muted-custom"><?= e($a["last_recommendation"] ? ucwords(str_replace("_"," ",$a["last_recommendation"])) : "-") ?></small>
                  </td>
                  <td><a class="btn btn-sm btn-outline-primary rounded-4 fw-bold" href="hiring-interview-view.php?application_id=<?= (int)$a["id"] ?>">View Details</a></td>
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
                  <small class="text-muted-custom fw-bold"><?= e($a["post_title"]) ?></small>
                </div>
                <span class="status-pill <?= badge_class($a["application_status"]) ?>"><?= e(ucwords(str_replace("_"," ",$a["application_status"]))) ?></span>
              </div>
              <hr>
              <div class="small fw-bold mb-2"><?= (int)$a["round_count"] ?> rounds · Next: <?= e(dt_show($a["next_schedule"])) ?></div>
              <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold" href="hiring-interview-view.php?application_id=<?= (int)$a["id"] ?>">View Details</a>
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

<?php include("includes/script.php"); ?>
<script src="assets/js/script.js?v=41"></script>
<script>
document.addEventListener("DOMContentLoaded", function(){
  if(window.lucide && window.lucide.createIcons) window.lucide.createIcons();
});
</script>
</body>
</html>
