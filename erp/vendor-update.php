<?php
session_start();
require_once __DIR__ . "/includes/db.php";
if (file_exists(__DIR__ . "/includes/vendor-helper.php")) {
  require_once __DIR__ . "/includes/vendor-helper.php";
}

if (empty($_SESSION["user_id"]) && empty($_SESSION["employee_id"])) {
  header("Location: login.php");
  exit;
}

function h($v)
{
  return htmlspecialchars((string) ($v ?? ""), ENT_QUOTES, "UTF-8");
}

function arrv($arr, $key, $default = "")
{
  return (is_array($arr) && array_key_exists($key, $arr) && $arr[$key] !== null) ? $arr[$key] : $default;
}

if (!function_exists("vf_table_exists")) {
  function vf_table_exists($conn, $table)
  {
    $table = preg_replace("/[^a-zA-Z0-9_]/", "", (string) $table);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    return $q && mysqli_num_rows($q) > 0;
  }
}

if (!function_exists("vf_col_exists")) {
  function vf_col_exists($conn, $table, $col)
  {
    $table = preg_replace("/[^a-zA-Z0-9_]/", "", (string) $table);
    $col = mysqli_real_escape_string($conn, (string) $col);
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    return $q && mysqli_num_rows($q) > 0;
  }
}

if (!function_exists("vf_uid")) {
  function vf_uid()
  {
    return (int) ($_SESSION["user_id"] ?? 0);
  }
}

if (!function_exists("vf_employee_id")) {
  function vf_employee_id($conn)
  {
    if (!empty($_SESSION["employee_id"])) {
      return (int) $_SESSION["employee_id"];
    }

    $uid = vf_uid();

    if ($uid > 0 && vf_table_exists($conn, "users") && vf_col_exists($conn, "users", "employee_id")) {
      $q = mysqli_query($conn, "SELECT employee_id FROM users WHERE id=$uid LIMIT 1");
      if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r["employee_id"])) {
        $_SESSION["employee_id"] = (int) $r["employee_id"];
        return (int) $r["employee_id"];
      }
    }

    if ($uid > 0 && vf_table_exists($conn, "employees") && vf_col_exists($conn, "employees", "user_id")) {
      $q = mysqli_query($conn, "SELECT id FROM employees WHERE user_id=$uid LIMIT 1");
      if ($q && ($r = mysqli_fetch_assoc($q))) {
        $_SESSION["employee_id"] = (int) $r["id"];
        return (int) $r["id"];
      }
    }

    return 0;
  }
}

if (!function_exists("vf_is_super_admin")) {
  function vf_is_super_admin($conn)
  {
    if (!empty($_SESSION["role_name"]) && strtolower((string) $_SESSION["role_name"]) === "super admin")
      return true;
    if (!empty($_SESSION["role"]) && strtolower((string) $_SESSION["role"]) === "super admin")
      return true;

    $uid = vf_uid();
    if ($uid <= 0 || !vf_table_exists($conn, "user_roles") || !vf_table_exists($conn, "roles")) {
      return false;
    }

    $roleSlugCheck = vf_col_exists($conn, "roles", "role_slug") ? " OR LOWER(COALESCE(r.role_slug,''))='super-admin' " : "";

    $q = mysqli_query($conn, "
            SELECT r.id
            FROM user_roles ur
            INNER JOIN roles r ON r.id=ur.role_id
            WHERE ur.user_id=$uid
              AND (
                r.id=1
                OR LOWER(COALESCE(r.role_name,''))='super admin'
                $roleSlugCheck
              )
            LIMIT 1
        ");

    return $q && mysqli_num_rows($q) > 0;
  }
}

if (!function_exists("vf_can")) {
  function vf_can($conn, $permission, $menuUrl)
  {
    if (vf_is_super_admin($conn)) {
      return true;
    }

    $allowed = ["can_view", "can_create", "can_edit", "can_delete"];
    if (!in_array($permission, $allowed, true)) {
      return false;
    }

    if (!vf_table_exists($conn, "role_sidebar_access") || !vf_table_exists($conn, "sidebar_menus") || !vf_table_exists($conn, "user_roles")) {
      return false;
    }

    $uid = vf_uid();
    $menuUrl = mysqli_real_escape_string($conn, $menuUrl);

    $q = mysqli_query($conn, "
            SELECT MAX(COALESCE(rsa.$permission,0)) AS allowed
            FROM user_roles ur
            INNER JOIN role_sidebar_access rsa ON rsa.role_id=ur.role_id
            INNER JOIN sidebar_menus sm ON sm.id=rsa.menu_id
            WHERE ur.user_id=$uid
              AND sm.menu_url='$menuUrl'
              AND sm.is_active=1
        ");

    return $q && ($r = mysqli_fetch_assoc($q)) && (int) ($r["allowed"] ?? 0) === 1;
  }
}

function badge_class($status)
{
  $status = strtolower((string) $status);
  if (in_array($status, ["active", "approved", "finalized", "completed", "selected"], true))
    return "green";
  if (in_array($status, ["pending", "shortlisted", "negotiation", "ongoing", "draft"], true))
    return "amber";
  if (in_array($status, ["rejected", "cancelled", "inactive", "blacklisted", "overdue"], true))
    return "red";
  return "blue";
}

function show_date($d)
{
  return ($d && $d !== "0000-00-00") ? date("d M Y", strtotime($d)) : "-";
}

$itemId = (int) ($_GET["pms_item_id"] ?? ($_GET["item_id"] ?? ($_GET["id"] ?? 0)));
if ($itemId <= 0) {
  header("Location: pms-vendor-schedule.php?error=" . urlencode("Invalid PMS item id."));
  exit;
}

$loginEmployeeId = vf_employee_id($conn);
$assignedProjectIds = [];

if (!vf_is_super_admin($conn) && $loginEmployeeId > 0 && vf_table_exists($conn, "project_assignments")) {
  $assignQ = mysqli_query($conn, "SELECT DISTINCT project_id FROM project_assignments WHERE employee_id=$loginEmployeeId AND status='active'");
  while ($assignQ && ($ar = mysqli_fetch_assoc($assignQ))) {
    $assignedProjectIds[] = (int) $ar["project_id"];
  }
}

$hasAssignedProjects = count($assignedProjectIds) > 0;
$canAddAndApprove = vf_can($conn, "can_create", "pms-vendor-schedule.php") && vf_can($conn, "can_edit", "pms-vendor-schedule.php");

$vfSelect = "
    NULL AS finalization_id,
    NULL AS vendor_id,
    NULL AS selected_date,
    NULL AS quotation_amount,
    NULL AS final_amount,
    NULL AS comparison_notes,
    'pending' AS finalization_status,
    NULL AS finalization_remarks,
    NULL AS finalized_by,
    NULL AS finalized_at,
    NULL AS vendor_code,
    NULL AS vendor_name,
    NULL AS vendor_category,
    NULL AS vendor_mobile,
    NULL AS vendor_email
";
$vfJoin = "";

if (vf_table_exists($conn, "project_vendor_finalizations")) {
  $vfSelect = "
        vf.id AS finalization_id,
        vf.vendor_id,
        vf.selected_date,
        vf.quotation_amount,
        vf.final_amount,
        vf.comparison_notes,
        COALESCE(vf.finalization_status,'pending') AS finalization_status,
        vf.remarks AS finalization_remarks,
        vf.finalized_by,
        vf.finalized_at,
        v.vendor_code,
        v.vendor_name,
        v.vendor_category,
        v.mobile_number AS vendor_mobile,
        v.email AS vendor_email
    ";
  $vfJoin = "
        LEFT JOIN project_vendor_finalizations vf ON vf.pms_item_id=task.id
        LEFT JOIN vendors v ON v.id=vf.vendor_id
    ";
}

$sql = "
    SELECT
        task.id AS pms_item_id,
        task.schedule_id,
        task.project_id,
        task.parent_id,
        task.item_type,
        task.hierarchy_level,
        COALESCE(NULLIF(task.title,''), 'Vendor Finalization') AS package_title,
        task.description,
        task.planned_start_date,
        task.planned_end_date,
        task.duration_days,
        task.actual_start_date,
        task.actual_end_date,
        task.progress_percent,
        task.item_status,
        COALESCE(topic.title, task.title, 'PMS Schedule') AS topic_title,
        s.schedule_name,
        p.project_name,
        p.project_code,
        p.project_location,
        p.start_date AS project_start_date,
        p.expected_completion_date,
        c.client_name,
        c.company_name AS client_company_name,
        $vfSelect
    FROM project_pmc_schedule_items task
    LEFT JOIN project_pmc_schedule_items topic ON topic.id=task.parent_id
    LEFT JOIN project_pmc_schedules s ON s.id=task.schedule_id
    LEFT JOIN projects p ON p.id=task.project_id
    LEFT JOIN clients c ON c.id=p.client_id
    $vfJoin
    WHERE task.id=$itemId
      AND task.is_active=1
    LIMIT 1
";

$q = mysqli_query($conn, $sql);
$row = ($q && mysqli_num_rows($q) > 0) ? mysqli_fetch_assoc($q) : [];
$queryError = mysqli_error($conn);

$rowFound = is_array($row) && !empty($row["project_id"]);

$accessAllowed = true;
if ($rowFound && !vf_is_super_admin($conn) && $hasAssignedProjects) {
  $accessAllowed = in_array((int) arrv($row, "project_id", 0), $assignedProjectIds, true);
}

if (!$rowFound || !$accessAllowed) {
  $message = !$rowFound
    ? "PMS schedule item not found for ID $itemId. Please open Vendor Update from PMS Vendor Schedule. " . ($queryError ? "SQL Error: $queryError" : "")
    : "You do not have access to this project.";
  ?>
  <!DOCTYPE html>
  <html lang="en" class="light">

  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Vendor Update</title>
    <?php include("includes/links.php"); ?>
    <style>
      .page-head-card,
      .section-card {
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 22px;
        box-shadow: var(--shadow-card);
        padding: 16px
      }
    </style>
  </head>

  <body>
    <div class="min-vh-100 d-flex">
      <?php include("includes/sidebar.php"); ?>
      <main id="main">
        <?php include("includes/nav.php"); ?>
        <section class="page-section p-3 p-lg-3">
          <div class="page-head-card mb-3">
            <h1 class="h4 fw-bold mb-1">Vendor Update</h1>
            <p class="text-muted-custom mb-0 small">Unable to load PMS item ID <?= (int) $itemId ?>.</p>
          </div>
          <div class="section-card">
            <div class="alert alert-danger rounded-4 fw-bold mb-3"><?= h($message) ?></div>
            <a href="pms-vendor-schedule.php#vendor-packages-section"
              class="btn btn-outline-secondary rounded-4 fw-bold">Back to PMS Vendor Schedule</a>
          </div>
          <?php include("includes/footer.php"); ?>
        </section>
      </main>
    </div>
    <?php include("includes/script.php"); ?>
    <script src="assets/js/script.js?v=41"></script>
  </body>

  </html>
  <?php
  exit;
}

/* Convert every row value to scalar variables so the HTML never uses array offsets directly. */
$pmsItemId = (int) arrv($row, "pms_item_id", $itemId);
$projectId = (int) arrv($row, "project_id", 0);
$scheduleId = (int) arrv($row, "schedule_id", 0);
$projectName = arrv($row, "project_name", "-") ?: "-";
$projectCode = arrv($row, "project_code", "-") ?: "-";
$projectLocation = arrv($row, "project_location", "-") ?: "-";
$clientName = arrv($row, "client_name", "") ?: (arrv($row, "client_company_name", "-") ?: "-");
$packageTitle = arrv($row, "package_title", "-") ?: "-";
$topicTitle = arrv($row, "topic_title", "-") ?: "-";
$requiredDate = arrv($row, "planned_end_date", "");
$durationDays = (int) arrv($row, "duration_days", 0);
$currentStatus = arrv($row, "finalization_status", "pending") ?: "pending";
$selectedDate = arrv($row, "selected_date", "") ?: date("Y-m-d");
$selectedVendorId = (int) arrv($row, "vendor_id", 0);
$quotationAmount = arrv($row, "quotation_amount", "");
$finalAmount = arrv($row, "final_amount", "");
$comparisonNotes = arrv($row, "comparison_notes", "");
$finalizationRemarks = arrv($row, "finalization_remarks", "");
$finalizationId = (int) arrv($row, "finalization_id", 0);

$pageMessageType = "";
$pageMessageText = "";
if (isset($_GET["updated"])) {
  $pageMessageType = "success";
  $pageMessageText = "Vendor finalization updated successfully.";
} elseif (isset($_GET["error"])) {
  $pageMessageType = "error";
  $pageMessageText = trim($_GET["error"]);
}

$vendors = [];
if (vf_table_exists($conn, "vendors")) {
  $vq = mysqli_query($conn, "SELECT id, vendor_code, vendor_name, vendor_category, mobile_number, email FROM vendors WHERE deleted_at IS NULL AND vendor_status='active' ORDER BY vendor_name ASC");
  while ($vq && ($vr = mysqli_fetch_assoc($vq))) {
    $vendors[] = is_array($vr) ? $vr : [];
  }
}

$files = [];
if ($finalizationId > 0 && vf_table_exists($conn, "project_vendor_finalization_files")) {
  $fq = mysqli_query($conn, "
        SELECT f.*, u.username AS uploaded_username, e.full_name AS uploaded_name
        FROM project_vendor_finalization_files f
        LEFT JOIN users u ON u.id=f.uploaded_by
        LEFT JOIN employees e ON e.id=u.employee_id
        WHERE f.finalization_id=$finalizationId
        ORDER BY f.created_at DESC, f.id DESC
    ");
  while ($fq && ($fr = mysqli_fetch_assoc($fq))) {
    $files[] = is_array($fr) ? $fr : [];
  }
}

$remarks = [];
if (vf_table_exists($conn, "project_vendor_finalization_remarks")) {
  $rq = mysqli_query($conn, "
        SELECT vr.*, u.username AS created_username, e.full_name AS created_name
        FROM project_vendor_finalization_remarks vr
        LEFT JOIN users u ON u.id=vr.created_by
        LEFT JOIN employees e ON e.id=u.employee_id
        WHERE vr.pms_item_id=$pmsItemId
        ORDER BY vr.created_at DESC, vr.id DESC
    ");
  while ($rq && ($rr = mysqli_fetch_assoc($rq))) {
    $remarks[] = is_array($rr) ? $rr : [];
  }
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Vendor Update</title>
  <?php include("includes/links.php"); ?>
  <style>
    .page-head-card,
    .section-card {
      background: var(--card-bg);
      border: 1px solid var(--border-soft);
      border-radius: 22px;
      box-shadow: var(--shadow-card);
      padding: 16px
    }

    .detail-mini {
      border: 1px solid var(--border-soft);
      border-radius: 16px;
      padding: 12px;
      background: rgba(148, 163, 184, .06);
      height: 100%
    }

    .detail-mini small {
      color: var(--text-muted);
      font-weight: 900
    }

    .detail-mini .value {
      font-weight: 900;
      color: var(--text-main);
      line-height: 1.35
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 5px 11px;
      font-size: 12px;
      font-weight: 900
    }

    .status-pill.green {
      background: rgba(16, 185, 129, .12);
      color: #059669
    }

    .status-pill.amber {
      background: rgba(245, 158, 11, .14);
      color: #b45309
    }

    .status-pill.red {
      background: rgba(239, 68, 68, .12);
      color: #dc2626
    }

    .status-pill.blue {
      background: rgba(37, 99, 235, .10);
      color: #2563eb
    }

    .upload-row {
      border: 1px dashed var(--border-soft);
      border-radius: 18px;
      padding: 12px;
      background: rgba(148, 163, 184, .05);
      margin-bottom: 10px
    }

    .file-card {
      border: 1px solid var(--border-soft);
      border-radius: 16px;
      padding: 12px;
      background: rgba(148, 163, 184, .05);
      margin-bottom: 10px
    }

    .remark-card {
      border: 1px solid var(--border-soft);
      border-radius: 16px;
      padding: 12px;
      background: rgba(245, 158, 11, .06);
      margin-bottom: 10px
    }

    @media(max-width:767px) {
      .page-section {
        padding: 12px !important
      }

      .page-head-card,
      .section-card {
        border-radius: 18px;
        padding: 14px
      }

      .modal-dialog {
        margin: 8px
      }
    }
  </style>
</head>

<body>
  <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none">
  </div>
  <?php if (file_exists(__DIR__ . "/includes/page-message.php"))
    include("includes/page-message.php"); ?>

  <div class="min-vh-100 d-flex">
    <?php include("includes/sidebar.php"); ?>
    <main id="main">
      <?php include("includes/nav.php"); ?>
      <section class="page-section p-3 p-lg-3">
        <div class="page-head-card mb-3">
          <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
            <div>
              <h1 class="h4 fw-bold mb-1">Vendor Update</h1>
              <p class="text-muted-custom mb-0 small">Update vendor, quotation, project drawing/reference files and
                finalization status.</p>
            </div>
            <a href="pms-vendor-schedule.php?project_id=<?= $projectId ?>#vendor-packages-section"
              class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Back to Schedule</a>
          </div>
        </div>

        <div class="section-card mb-3">
          <div class="row g-3">
            <div class="col-md-3">
              <div class="detail-mini">
                <small>Project</small>
                <div class="value"><?= h($projectName) ?></div>
                <small><?= h($projectCode) ?></small>
              </div>
            </div>
            <div class="col-md-3">
              <div class="detail-mini">
                <small>Client</small>
                <div class="value"><?= h($clientName) ?></div>
                <small><?= h($projectLocation) ?></small>
              </div>
            </div>
            <div class="col-md-3">
              <div class="detail-mini">
                <small>Package</small>
                <div class="value"><?= h($packageTitle) ?></div>
                <small><?= h($topicTitle) ?></small>
              </div>
            </div>
            <div class="col-md-3">
              <div class="detail-mini">
                <small>Required Date</small>
                <div class="value"><?= h(show_date($requiredDate)) ?></div>
                <small><?= $durationDays ?> days</small>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-xl-8">
            <form class="section-card" method="POST" action="api/process-vendor.php" enctype="multipart/form-data">
              <input type="hidden" name="action" value="save_vendor_update">
              <input type="hidden" name="pms_item_id" value="<?= $pmsItemId ?>">
              <input type="hidden" name="project_id" value="<?= $projectId ?>">
              <input type="hidden" name="schedule_id" value="<?= $scheduleId ?>">
              <input type="hidden" name="package_title" value="<?= h($packageTitle) ?>">

              <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                  <h2 class="fw-bold fs-6 mb-1">Vendor & Quotation Details</h2>
                  <p class="text-muted-custom small mb-0">Only users with add and approve access can update this page.
                  </p>
                </div>
                <span class="status-pill <?= h(badge_class($currentStatus)) ?>"><?= h(ucwords($currentStatus)) ?></span>
              </div>

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label fw-bold small">Select Vendor</label>
                  <select class="form-select rounded-4" name="vendor_id" required>
                    <option value="">Select Vendor</option>
                    <?php foreach ($vendors as $v): ?>
                      <?php
                      $vendorId = (int) arrv($v, "id", 0);
                      $vendorText = arrv($v, "vendor_name", "");
                      $vendorCategory = arrv($v, "vendor_category", "");
                      ?>
                      <option value="<?= $vendorId ?>" <?= $selectedVendorId === $vendorId ? "selected" : "" ?>>
                        <?= h($vendorText) ?>  <?= $vendorCategory ? " - " . h($vendorCategory) : "" ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-3">
                  <label class="form-label fw-bold small">Selected Date</label>
                  <input type="date" class="form-control rounded-4" name="selected_date"
                    value="<?= h($selectedDate) ?>">
                </div>

                <div class="col-md-3">
                  <label class="form-label fw-bold small">Status</label>
                  <select class="form-select rounded-4" name="finalization_status" required>
                    <?php foreach (["pending", "shortlisted", "negotiation", "finalized", "rejected", "cancelled"] as $s): ?>
                      <option value="<?= h($s) ?>" <?= $currentStatus === $s ? "selected" : "" ?>><?= h(ucwords($s)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label fw-bold small">Quotation Amount</label>
                  <input class="form-control rounded-4" name="quotation_amount" value="<?= h($quotationAmount) ?>">
                </div>

                <div class="col-md-6">
                  <label class="form-label fw-bold small">Final Amount</label>
                  <input class="form-control rounded-4" name="final_amount" value="<?= h($finalAmount) ?>">
                </div>

                <div class="col-12">
                  <label class="form-label fw-bold small">Comparison / Negotiation Notes</label>
                  <textarea class="form-control rounded-4" name="comparison_notes"
                    rows="3"><?= h($comparisonNotes) ?></textarea>
                </div>

                <div class="col-12">
                  <label class="form-label fw-bold small">Remarks</label>
                  <textarea class="form-control rounded-4" name="remarks"
                    rows="3"><?= h($finalizationRemarks) ?></textarea>
                </div>
              </div>

              <hr class="my-4">

              <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                  <h3 class="fw-bold fs-6 mb-1">Upload Files</h3>
                  <p class="text-muted-custom small mb-0">Add vendor quotation files, project drawing files or support
                    documents.</p>
                </div>
                <button type="button" class="btn btn-outline-primary rounded-4 fw-bold btn-sm" id="addFileRowBtn">Add
                  More</button>
              </div>

              <div id="uploadRows">
                <div class="upload-row">
                  <div class="row g-2">
                    <div class="col-md-4">
                      <label class="form-label fw-bold small">File Title</label>
                      <input class="form-control rounded-4" name="file_title[]" placeholder="Quotation / Drawing / BOQ">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label fw-bold small">File Type</label>
                      <select class="form-select rounded-4" name="file_type[]">
                        <option value="quotation">Quotation</option>
                        <option value="drawing">Project Drawing</option>
                        <option value="comparison">Comparison</option>
                        <option value="approval">Approval</option>
                        <option value="other">Other</option>
                      </select>
                    </div>
                    <div class="col-md-5">
                      <label class="form-label fw-bold small">File</label>
                      <input type="file" class="form-control rounded-4" name="vendor_files[]">
                    </div>
                  </div>
                </div>
              </div>

              <div class="mt-3">
                <button class="btn brand-gradient text-white rounded-4 fw-bold" <?= !$canAddAndApprove ? "disabled" : "" ?>>Save Vendor Update</button>
                <?php if (!$canAddAndApprove): ?>
                  <div class="text-danger fw-bold small mt-2">You do not have add and approve access to save this update.
                  </div>
                <?php endif; ?>
              </div>
            </form>
          </div>

          <div class="col-xl-4">
            <div class="section-card mb-3">
              <h2 class="fw-bold fs-6 mb-3">Uploaded Files</h2>
              <?php if (!$files): ?>
                <div class="text-muted-custom fw-bold">No files uploaded.</div><?php endif; ?>
              <?php foreach ($files as $f): ?>
                <div class="file-card">
                  <div class="fw-bold"><?= h(arrv($f, "file_title", "-")) ?></div>
                  <small class="text-muted-custom"><?= h(ucwords(str_replace("_", " ", arrv($f, "file_type", "other")))) ?>
                    · <?= h(arrv($f, "uploaded_name", "") ?: arrv($f, "uploaded_username", "-")) ?></small>
                  <div class="mt-2">
                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                      href="<?= h(arrv($f, "file_path", "#")) ?>" target="_blank">View File</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="section-card">
              <h2 class="fw-bold fs-6 mb-3">Urgent Remarks</h2>
              <?php if (!$remarks): ?>
                <div class="text-muted-custom fw-bold">No remarks yet.</div><?php endif; ?>
              <?php foreach ($remarks as $rm): ?>
                <div class="remark-card">
                  <div class="d-flex justify-content-between gap-2">
                    <div class="fw-bold"><?= h(ucwords(arrv($rm, "urgency", "normal"))) ?></div>
                    <small
                      class="text-muted-custom"><?= h(arrv($rm, "created_at", "") ? date("d M Y h:i A", strtotime(arrv($rm, "created_at"))) : "-") ?></small>
                  </div>
                  <div class="mt-2"><?= nl2br(h(arrv($rm, "remark", ""))) ?></div>
                  <small class="text-muted-custom">By:
                    <?= h(arrv($rm, "created_name", "") ?: arrv($rm, "created_username", "-")) ?></small>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <?php include("includes/footer.php"); ?>
      </section>
    </main>
    <div id="settingsOverlay"></div>
    <?php if (file_exists(__DIR__ . "/includes/rightsidbar.php"))
      include("includes/rightsidbar.php"); ?>
  </div>

  <?php include("includes/script.php"); ?>
  <script src="assets/js/script.js?v=41"></script>
  <script>
    document.addEventListener("DOMContentLoaded", function () {
      if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();

      document.getElementById("addFileRowBtn")?.addEventListener("click", function () {
        const wrap = document.getElementById("uploadRows");
        const div = document.createElement("div");
        div.className = "upload-row";
        div.innerHTML = `
      <div class="row g-2">
        <div class="col-md-4">
          <label class="form-label fw-bold small">File Title</label>
          <input class="form-control rounded-4" name="file_title[]" placeholder="Quotation / Drawing / BOQ">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-bold small">File Type</label>
          <select class="form-select rounded-4" name="file_type[]">
            <option value="quotation">Quotation</option>
            <option value="drawing">Project Drawing</option>
            <option value="comparison">Comparison</option>
            <option value="approval">Approval</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-bold small">File</label>
          <input type="file" class="form-control rounded-4" name="vendor_files[]">
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <button type="button" class="btn btn-outline-danger rounded-4 fw-bold removeFileRowBtn">X</button>
        </div>
      </div>
    `;
        wrap.appendChild(div);
      });

      document.getElementById("uploadRows")?.addEventListener("click", function (e) {
        const btn = e.target.closest(".removeFileRowBtn");
        if (btn) btn.closest(".upload-row").remove();
      });
    });
  </script>
</body>

</html>