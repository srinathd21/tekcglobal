<?php
session_start();
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/vendor-helper.php";

if(empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])){ header("Location: login.php"); exit; }
vf_require($conn, "can_view", "vendors.php");

function e($v){ return vf_e($v); }
function badge_class($s){ return vf_badge($s); }

$canCreate = vf_can($conn, 'can_create', 'vendors.php');
$canEdit = vf_can($conn, 'can_edit', 'vendors.php');
$canDelete = vf_can($conn, 'can_delete', 'vendors.php');

$pageMessageType = "";
$pageMessageText = "";
if(isset($_GET['saved'])){ $pageMessageType='success'; $pageMessageText='Vendor saved successfully.'; }
elseif(isset($_GET['updated'])){ $pageMessageType='success'; $pageMessageText='Vendor updated successfully.'; }
elseif(isset($_GET['deleted'])){ $pageMessageType='success'; $pageMessageText='Vendor deleted successfully.'; }
elseif(isset($_GET['error'])){ $pageMessageType='error'; $pageMessageText=trim($_GET['error']); }

$qText = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$category = trim($_GET['category'] ?? '');

$where = ["deleted_at IS NULL"];
if($qText !== ''){
    $s = mysqli_real_escape_string($conn, $qText);
    $where[] = "(vendor_code LIKE '%$s%' OR vendor_name LIKE '%$s%' OR contact_person LIKE '%$s%' OR mobile_number LIKE '%$s%' OR email LIKE '%$s%' OR gst_number LIKE '%$s%')";
}
if($status !== '') $where[] = "vendor_status='".mysqli_real_escape_string($conn,$status)."'";
if($category !== '') $where[] = "vendor_category='".mysqli_real_escape_string($conn,$category)."'";
$whereSql = implode(" AND ", $where);

$perPage = (int)($_GET['per_page'] ?? 10);
if(!in_array($perPage, [10,25,50,100], true)) $perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$totalVendors = 0;
$countQ = mysqli_query($conn, "SELECT COUNT(*) AS c FROM vendors WHERE $whereSql");
if($countQ && ($cr = mysqli_fetch_assoc($countQ))) $totalVendors = (int)$cr['c'];

$totalPages = max(1, (int)ceil($totalVendors / $perPage));
if($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

function vendor_page_url($pageNo) {
    $params = $_GET;
    $params['page'] = max(1, (int)$pageNo);
    return 'vendors.php?' . http_build_query($params) . '#vendors-section';
}

$vendors = [];
$q = mysqli_query($conn, "
    SELECT v.*, cu.username created_username, uu.username updated_username,
           ce.full_name created_name, ue.full_name updated_name
    FROM vendors v
    LEFT JOIN users cu ON cu.id=v.created_by
    LEFT JOIN employees ce ON ce.id=cu.employee_id
    LEFT JOIN users uu ON uu.id=v.updated_by
    LEFT JOIN employees ue ON ue.id=uu.employee_id
    WHERE $whereSql
    ORDER BY v.vendor_name ASC
    LIMIT $perPage OFFSET $offset
");
while($q && ($r=mysqli_fetch_assoc($q))) $vendors[]=$r;

$categories = [];
$q = mysqli_query($conn, "SELECT DISTINCT vendor_category FROM vendors WHERE deleted_at IS NULL AND vendor_category IS NOT NULL AND vendor_category<>'' ORDER BY vendor_category ASC");
while($q && ($r=mysqli_fetch_assoc($q))) $categories[]=$r['vendor_category'];

$stats = [
    'total'=>0,'active'=>0,'inactive'=>0,'blacklisted'=>0
];
$stQ = mysqli_query($conn, "SELECT vendor_status, COUNT(*) c FROM vendors WHERE deleted_at IS NULL GROUP BY vendor_status");
while($stQ && ($r=mysqli_fetch_assoc($stQ))){
    $stats[$r['vendor_status']] = (int)$r['c'];
    $stats['total'] += (int)$r['c'];
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Vendors</title>
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
    .vendor-table{min-width:1220px;table-layout:fixed}
    .vendor-table th,.vendor-table td{vertical-align:middle;padding:13px 10px}
    .vendor-table th{font-size:12px;white-space:nowrap}
    .cell-title{font-weight:900;color:var(--text-main);line-height:1.35}
    .cell-code{color:var(--text-muted);font-size:12px;font-weight:900;line-height:1.45;word-break:break-word}
    .cell-muted{color:var(--text-muted);font-size:12px;font-weight:700;line-height:1.45}
    .action-stack{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
    .pagination-wrap{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-top:14px;border-top:1px solid var(--border-soft)}
    .pagination-clean{display:flex;gap:6px;flex-wrap:wrap;margin:0;padding:0;list-style:none}
    .pagination-clean a,.pagination-clean span{min-width:34px;height:34px;padding:0 10px;border-radius:12px;border:1px solid var(--border-soft);display:inline-flex;align-items:center;justify-content:center;font-weight:900;text-decoration:none;color:var(--text-main);background:var(--card-bg)}
    .pagination-clean .active span{background:#fbbf24;color:#fff;border-color:#fbbf24}
    .pagination-clean .disabled span{opacity:.45}
    .mobile-vendor-list{display:none}
    .mobile-vendor-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:20px;box-shadow:var(--shadow-card);padding:14px;margin-bottom:12px}
    .mobile-vendor-row{display:grid;grid-template-columns:110px 1fr;gap:8px;font-size:13px;margin-top:8px}
    .mobile-vendor-row .label{color:var(--text-muted);font-weight:900}
    .mobile-vendor-row .value{font-weight:700;color:var(--text-main);word-break:break-word}
    .modal-content{background:var(--card-bg);color:var(--text-main);border:1px solid var(--border-soft);border-radius:24px;box-shadow:var(--shadow-card)}
    .modal-header,.modal-footer{border-color:var(--border-soft)}
    @media(max-width:767px){.page-section{padding:12px!important}.page-head-card,.filter-card,.section-card{border-radius:18px;padding:14px}.kpi-card{padding:14px;border-radius:18px;gap:10px}.kpi-icon{width:42px;height:42px;border-radius:15px}.kpi-card .h4{font-size:20px}.desktop-vendor-table{display:none!important}.mobile-vendor-list{display:block}.filter-card .btn{min-height:42px}.pagination-clean a,.pagination-clean span{min-width:32px;height:32px;border-radius:10px;font-size:12px}.modal-dialog{margin:8px}.modal-content{border-radius:18px}}
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
            <h1 class="h4 fw-bold mb-1">Vendors</h1>
            <p class="text-muted-custom mb-0 small">Vendor master for PMS vendor finalization packages.</p>
          </div>
          <?php if($canCreate): ?>
            <button class="btn brand-gradient text-white rounded-4 fw-bold btn-sm" data-bs-toggle="modal" data-bs-target="#vendorModal">
              <i data-lucide="plus" style="width:15px"></i> New Vendor
            </button>
          <?php endif; ?>
        </div>
      </div>


      <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-primary-subtle text-primary"><i data-lucide="store"></i></div><div><div class="text-muted-custom small fw-bold">Vendors</div><div class="h4 fw-bold mb-0"><?= (int)$stats['total'] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-success-subtle text-success"><i data-lucide="check-circle"></i></div><div><div class="text-muted-custom small fw-bold">Active</div><div class="h4 fw-bold mb-0"><?= (int)$stats['active'] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-warning-subtle text-warning"><i data-lucide="pause-circle"></i></div><div><div class="text-muted-custom small fw-bold">Inactive</div><div class="h4 fw-bold mb-0"><?= (int)$stats['inactive'] ?></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi-card"><div class="kpi-icon bg-danger-subtle text-danger"><i data-lucide="ban"></i></div><div><div class="text-muted-custom small fw-bold">Blacklisted</div><div class="h4 fw-bold mb-0"><?= (int)$stats['blacklisted'] ?></div></div></div></div>
      </div>

      <form class="filter-card mb-3" method="GET" action="vendors.php#vendors-section">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-lg-3"><label class="form-label fw-bold small">Search</label><input class="form-control rounded-4" name="q" value="<?= e($qText) ?>" placeholder="Vendor, contact, mobile, GST"></div>
          <div class="col-12 col-lg-3"><label class="form-label fw-bold small">Category</label><select class="form-select rounded-4" name="category"><option value="">All</option><?php foreach($categories as $c): ?><option value="<?= e($c) ?>" <?= $category===$c?'selected':'' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
          <div class="col-12 col-lg-3"><label class="form-label fw-bold small">Status</label><select class="form-select rounded-4" name="status"><option value="">All</option><?php foreach(['active','inactive','blacklisted'] as $s): ?><option value="<?= e($s) ?>" <?= $status===$s?'selected':'' ?>><?= e(ucwords($s)) ?></option><?php endforeach; ?></select></div>
          <div class="col-12 col-lg-1"><label class="form-label fw-bold small">Rows</label><select class="form-select rounded-4" name="per_page"><?php foreach([10,25,50,100] as $pp): ?><option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option><?php endforeach; ?></select></div><div class="col-6 col-lg-1 d-flex gap-2"><button class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button></div><div class="col-6 col-lg-1 d-flex gap-2"><a href="vendors.php#vendors-section" class="btn btn-outline-secondary rounded-4 fw-bold w-100">Reset</a></div>
        </div>
      </form>

      <section class="section-card overflow-hidden" id="vendors-section">
        <div class="overflow-auto thin-scrollbar desktop-vendor-table">
          <table class="project-table vendor-table w-100">
            <thead><tr><th>Vendor</th><th>Category</th><th>Contact</th><th>GST / PAN</th><th>Location</th><th>Rating</th><th>Status</th><th>Audit</th><th style="width:160px">Action</th></tr></thead>
            <tbody>
            <?php if(!$vendors): ?><tr><td colspan="9" class="text-center text-muted-custom fw-bold py-4">No vendors found.</td></tr><?php endif; ?>
            <?php foreach($vendors as $v): ?>
              <tr>
                <td><div class="cell-title"><?= e($v['vendor_name']) ?></div><div class="cell-code"><?= e($v['vendor_code']) ?></div></td>
                <td><div class="cell-muted"><?= e($v['vendor_category'] ?: '-') ?></div></td>
                <td><div class="cell-muted"><?= e($v['contact_person'] ?: '-') ?><br><?= e($v['mobile_number'] ?: '-') ?><br><?= e($v['email'] ?: '-') ?></div></td>
                <td><div class="cell-muted"><b>GST:</b> <?= e($v['gst_number'] ?: '-') ?><br><b>PAN:</b> <?= e($v['pan_number'] ?: '-') ?></div></td>
                <td><div class="cell-muted"><?= e($v['city'] ?: '-') ?><br><?= e($v['state'] ?: '-') ?></div></td>
                <td><?= e($v['rating'] ?: '-') ?></td>
                <td><span class="status-pill <?= badge_class($v['vendor_status']) ?>"><?= e(ucwords($v['vendor_status'])) ?></span></td>
                <td><div class="cell-muted"><b>Created:</b><br><?= e($v['created_name'] ?: $v['created_username'] ?: '-') ?><br><b>Updated:</b><br><?= e($v['updated_name'] ?: $v['updated_username'] ?: '-') ?></div></td>
                <td><div class="action-stack"><?php if($canEdit): ?><button class="btn btn-sm btn-outline-primary rounded-4 fw-bold editVendorBtn" data-vendor='<?= e(json_encode($v)) ?>' data-bs-toggle="modal" data-bs-target="#vendorModal">Edit</button><?php endif; ?><?php if($canDelete): ?><form class="d-inline" method="POST" action="api/process-vendor.php" onsubmit="return confirm('Delete this vendor?')"><input type="hidden" name="action" value="delete_vendor"><input type="hidden" name="vendor_id" value="<?= (int)$v['id'] ?>"><button class="btn btn-sm btn-outline-danger rounded-4 fw-bold">Delete</button></form><?php endif; ?></div></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="mobile-vendor-list">
          <?php if(!$vendors): ?><div class="text-center text-muted-custom fw-bold py-4">No vendors found.</div><?php endif; ?>
          <?php foreach($vendors as $v): ?>
            <div class="mobile-vendor-card">
              <div class="d-flex justify-content-between align-items-start gap-2"><div><div class="cell-title"><?= e($v['vendor_name']) ?></div><div class="cell-code"><?= e($v['vendor_code']) ?></div></div><span class="status-pill <?= badge_class($v['vendor_status']) ?>"><?= e(ucwords($v['vendor_status'])) ?></span></div>
              <div class="mobile-vendor-row"><div class="label">Category</div><div class="value"><?= e($v['vendor_category'] ?: '-') ?></div></div>
              <div class="mobile-vendor-row"><div class="label">Contact</div><div class="value"><?= e($v['contact_person'] ?: '-') ?><br><?= e($v['mobile_number'] ?: '-') ?><br><?= e($v['email'] ?: '-') ?></div></div>
              <div class="mobile-vendor-row"><div class="label">GST / PAN</div><div class="value">GST: <?= e($v['gst_number'] ?: '-') ?><br>PAN: <?= e($v['pan_number'] ?: '-') ?></div></div>
              <div class="mobile-vendor-row"><div class="label">Location</div><div class="value"><?= e($v['city'] ?: '-') ?><?= $v['state'] ? ', '.e($v['state']) : '' ?></div></div>
              <div class="mobile-vendor-row"><div class="label">Audit</div><div class="value">Created: <?= e($v['created_name'] ?: $v['created_username'] ?: '-') ?><br>Updated: <?= e($v['updated_name'] ?: $v['updated_username'] ?: '-') ?></div></div>
              <div class="action-stack mt-3"><?php if($canEdit): ?><button class="btn btn-sm btn-outline-primary rounded-4 fw-bold editVendorBtn" data-vendor='<?= e(json_encode($v)) ?>' data-bs-toggle="modal" data-bs-target="#vendorModal">Edit</button><?php endif; ?><?php if($canDelete): ?><form method="POST" action="api/process-vendor.php" onsubmit="return confirm('Delete this vendor?')"><input type="hidden" name="action" value="delete_vendor"><input type="hidden" name="vendor_id" value="<?= (int)$v['id'] ?>"><button class="btn btn-sm btn-outline-danger rounded-4 fw-bold">Delete</button></form><?php endif; ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="pagination-wrap">
          <div class="cell-muted">Showing <?= $totalVendors ? (($offset + 1) . ' - ' . min($offset + $perPage, $totalVendors)) : '0' ?> of <?= (int)$totalVendors ?> vendors</div>
          <ul class="pagination-clean">
            <li class="<?= $page <= 1 ? 'disabled' : '' ?>"><?= $page <= 1 ? '<span>Prev</span>' : '<a href="'.e(vendor_page_url($page-1)).'">Prev</a>' ?></li>
            <?php $startPage=max(1,$page-2); $endPage=min($totalPages,$page+2); if($startPage>1) echo '<li><a href="'.e(vendor_page_url(1)).'">1</a></li>'; if($startPage>2) echo '<li class="disabled"><span>...</span></li>'; for($i=$startPage;$i<=$endPage;$i++): ?>
              <li class="<?= $i===$page ? 'active' : '' ?>"><?= $i===$page ? '<span>'.$i.'</span>' : '<a href="'.e(vendor_page_url($i)).'">'.$i.'</a>' ?></li>
            <?php endfor; if($endPage<$totalPages-1) echo '<li class="disabled"><span>...</span></li>'; if($endPage<$totalPages) echo '<li><a href="'.e(vendor_page_url($totalPages)).'">'.$totalPages.'</a></li>'; ?>
            <li class="<?= $page >= $totalPages ? 'disabled' : '' ?>"><?= $page >= $totalPages ? '<span>Next</span>' : '<a href="'.e(vendor_page_url($page+1)).'">Next</a>' ?></li>
          </ul>
        </div>
      </section>

      <?php include("includes/footer.php"); ?>
    </section>
  </main>
  <div id="settingsOverlay"></div>
  <?php if(file_exists(__DIR__."/includes/rightsidbar.php")) include("includes/rightsidbar.php"); ?>
</div>

<div class="modal fade" id="vendorModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <form class="modal-content" method="POST" action="api/process-vendor.php">
      <input type="hidden" name="action" value="save_vendor">
      <input type="hidden" name="vendor_id" id="vendorId">
      <div class="modal-header"><h5 class="modal-title fw-bold">Vendor Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label fw-bold small">Vendor Name *</label><input class="form-control rounded-4" name="vendor_name" id="vendorName" required></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Category</label><input class="form-control rounded-4" name="vendor_category" id="vendorCategory" placeholder="Civil, Electrical, Plumbing..."></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Status</label><select class="form-select rounded-4" name="vendor_status" id="vendorStatus"><option value="active">Active</option><option value="inactive">Inactive</option><option value="blacklisted">Blacklisted</option></select></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Contact Person</label><input class="form-control rounded-4" name="contact_person" id="contactPerson"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Mobile</label><input class="form-control rounded-4" name="mobile_number" id="mobileNumber"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Alternate Mobile</label><input class="form-control rounded-4" name="alternate_mobile" id="alternateMobile"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Email</label><input type="email" class="form-control rounded-4" name="email" id="email"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">GST Number</label><input class="form-control rounded-4" name="gst_number" id="gstNumber"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">PAN Number</label><input class="form-control rounded-4" name="pan_number" id="panNumber"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">City</label><input class="form-control rounded-4" name="city" id="city"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">State</label><input class="form-control rounded-4" name="state" id="state"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Pincode</label><input class="form-control rounded-4" name="pincode" id="pincode"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Bank Name</label><input class="form-control rounded-4" name="bank_name" id="bankName"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Bank Account Number</label><input class="form-control rounded-4" name="bank_account_number" id="bankAccount"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">IFSC</label><input class="form-control rounded-4" name="ifsc_code" id="ifscCode"></div>
          <div class="col-md-4"><label class="form-label fw-bold small">Rating</label><input class="form-control rounded-4" name="rating" id="rating" placeholder="0 - 5"></div>
          <div class="col-md-8"><label class="form-label fw-bold small">Address</label><textarea class="form-control rounded-4" name="address" id="address" rows="2"></textarea></div>
          <div class="col-12"><label class="form-label fw-bold small">Notes</label><textarea class="form-control rounded-4" name="notes" id="notes" rows="2"></textarea></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Close</button><button class="btn brand-gradient text-white rounded-4 fw-bold">Save Vendor</button></div>
    </form>
  </div>
</div>

<?php include("includes/script.php"); ?>
<script src="assets/js/script.js?v=41"></script>
<script>
document.addEventListener("DOMContentLoaded", function(){
  if(window.lucide && window.lucide.createIcons) window.lucide.createIcons();
  const section = document.getElementById("vendors-section");
  const params = new URLSearchParams(window.location.search);
  if(section && (window.location.hash === "#vendors-section" || params.has("page") || params.has("q") || params.has("status") || params.has("category"))){ setTimeout(() => section.scrollIntoView({behavior:"smooth", block:"start"}), 120); }
  const modal = document.getElementById("vendorModal");
  document.querySelectorAll(".editVendorBtn").forEach(btn=>{
    btn.addEventListener("click",()=>{
      const v = JSON.parse(btn.dataset.vendor || "{}");
      const map = {
        vendorId:"id",vendorName:"vendor_name",vendorCategory:"vendor_category",vendorStatus:"vendor_status",
        contactPerson:"contact_person",mobileNumber:"mobile_number",alternateMobile:"alternate_mobile",
        email:"email",gstNumber:"gst_number",panNumber:"pan_number",city:"city",state:"state",pincode:"pincode",
        bankName:"bank_name",bankAccount:"bank_account_number",ifscCode:"ifsc_code",rating:"rating",address:"address",notes:"notes"
      };
      Object.keys(map).forEach(id=>{
        const el=document.getElementById(id);
        if(el) el.value = v[map[id]] || "";
      });
    });
  });
  modal?.addEventListener("hidden.bs.modal",()=>{ modal.querySelector("form").reset(); document.getElementById("vendorId").value=""; });
});
</script>
</body>
</html>
