<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . "/includes/db.php";

require_permission($conn, "can_view", "index.php");
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>TEK-C PMC Construction - Admin Dashboard</title>

  <?php include("includes/links.php") ?>

  <style>
    .urgent-float-btn{
      position:fixed;right:24px;bottom:24px;z-index:1050;border:0;border-radius:999px;
      background:linear-gradient(135deg,#ef4444,#f59e0b);color:#fff;font-weight:900;
      padding:12px 18px;box-shadow:0 18px 40px rgba(239,68,68,.25);display:flex;align-items:center;gap:8px;
    }
    .urgent-modal .modal-content{border:0;border-radius:24px;box-shadow:0 24px 80px rgba(15,23,42,.22);overflow:hidden}
    .urgent-modal-head{background:linear-gradient(135deg,#0f172a,#334155);color:#fff;padding:20px}
    .urgent-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
    .urgent-tab{border:1px solid var(--border-soft);background:var(--card-bg);border-radius:999px;padding:7px 13px;font-weight:900;font-size:13px;color:var(--text-main);cursor:pointer}
    .urgent-tab.active{background:#fbbf24;border-color:#fbbf24;color:#fff}
    .urgent-box{border:1px solid var(--border-soft);border-radius:18px;padding:13px;background:rgba(148,163,184,.06);margin-bottom:10px}
    .urgent-box.critical{background:rgba(239,68,68,.07);border-color:rgba(239,68,68,.30)}
    .urgent-box.urgent{background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.30)}
    .urgent-chip{display:inline-flex;align-items:center;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:900}
    .urgent-chip.critical{background:rgba(239,68,68,.14);color:#dc2626}
    .urgent-chip.urgent{background:rgba(245,158,11,.16);color:#b45309}
    .urgent-chip.pms{background:rgba(59,130,246,.12);color:#2563eb}
    .urgent-title{font-weight:900;color:var(--text-main);line-height:1.35}
    .urgent-meta{color:var(--text-muted);font-size:12px;font-weight:800;line-height:1.55}
    .urgent-message{font-weight:700;color:var(--text-main);white-space:pre-wrap;line-height:1.5}
    .urgent-pane{display:none}
    .urgent-pane.active{display:block}
    @media(max-width:767px){
      .urgent-float-btn{right:14px;bottom:14px;padding:10px 13px;font-size:13px}
      .urgent-modal .modal-dialog{margin:8px}
      .urgent-modal-head{padding:16px}
    }
  </style>

  
</head>

<body>
  <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none">
  </div>

  <?php include("includes/page-message.php"); ?>

  <div class="min-vh-100 d-flex">
    <?php include("includes/sidebar.php") ?>
    <main id="main">
      <?php include("includes/nav.php") ?>
      <section class="page-section p-3 p-lg-3">
        <?php
        $canDashboardView = can_view($conn, "index.php");
        $canProjectsView = can_view($conn, "projects.php");
        $canBillingView = can_view($conn, "billing.php");

        function dashboard_table_exists($conn, $table) {
          $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
          $q = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
          return $q && mysqli_num_rows($q) > 0;
        }

        function dashboard_col_exists($conn, $table, $col) {
          $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
          $col = mysqli_real_escape_string($conn, (string)$col);
          $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
          return $q && mysqli_num_rows($q) > 0;
        }

        function dashboard_emp_id($conn) {
          if (!empty($_SESSION['employee_id'])) return (int)$_SESSION['employee_id'];
          $uid = (int)($_SESSION['user_id'] ?? 0);

          if ($uid > 0 && dashboard_table_exists($conn, 'users') && dashboard_col_exists($conn, 'users', 'employee_id')) {
            $q = mysqli_query($conn, "SELECT employee_id FROM users WHERE id=$uid LIMIT 1");
            if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r['employee_id'])) return (int)$r['employee_id'];
          }

          if ($uid > 0 && dashboard_table_exists($conn, 'employees') && dashboard_col_exists($conn, 'employees', 'user_id')) {
            $q = mysqli_query($conn, "SELECT id FROM employees WHERE user_id=$uid LIMIT 1");
            if ($q && ($r = mysqli_fetch_assoc($q))) return (int)$r['id'];
          }

          return 0;
        }

        function dashboard_is_super_admin($conn) {
          if (!empty($_SESSION['role_name']) && strtolower((string)$_SESSION['role_name']) === 'super admin') return true;
          if (!empty($_SESSION['role']) && strtolower((string)$_SESSION['role']) === 'super admin') return true;

          $uid = (int)($_SESSION['user_id'] ?? 0);
          if ($uid <= 0 || !dashboard_table_exists($conn, 'user_roles') || !dashboard_table_exists($conn, 'roles')) return false;

          $slugSql = dashboard_col_exists($conn, 'roles', 'role_slug') ? " OR LOWER(COALESCE(r.role_slug,''))='super-admin' " : "";
          $q = mysqli_query($conn, "
            SELECT r.id
            FROM user_roles ur
            INNER JOIN roles r ON r.id=ur.role_id
            WHERE ur.user_id=$uid
              AND (r.id=1 OR LOWER(COALESCE(r.role_name,''))='super admin' $slugSql)
            LIMIT 1
          ");

          return $q && mysqli_num_rows($q) > 0;
        }

        function dashboard_short($text, $limit = 110) {
          $text = trim((string)$text);
          if (strlen($text) <= $limit) return $text;
          return substr($text, 0, $limit) . '...';
        }

        $dashboardEmployeeId = dashboard_emp_id($conn);
        $dashboardIsSuperAdmin = dashboard_is_super_admin($conn);
        $assignedProjectIds = [];

        if (!$dashboardIsSuperAdmin && $dashboardEmployeeId > 0 && dashboard_table_exists($conn, 'project_assignments')) {
          $assignQ = mysqli_query($conn, "SELECT DISTINCT project_id FROM project_assignments WHERE employee_id=$dashboardEmployeeId AND status='active'");
          while ($assignQ && ($ar = mysqli_fetch_assoc($assignQ))) $assignedProjectIds[] = (int)$ar['project_id'];
        }

        $hasAssignedProjects = count($assignedProjectIds) > 0;
        $assignedProjectSql = $hasAssignedProjects ? implode(',', array_map('intval', $assignedProjectIds)) : '';

        $urgentVendorRemarks = [];
        if (dashboard_table_exists($conn, 'project_vendor_finalization_remarks')) {
          $projectAccessSql = $hasAssignedProjects ? "AND vr.project_id IN ($assignedProjectSql)" : "";
          $remarkQ = mysqli_query($conn, "
            SELECT
              vr.id,
              vr.project_id,
              vr.pms_item_id,
              vr.package_title,
              vr.urgency,
              vr.remark,
              vr.created_at,
              p.project_name,
              p.project_code,
              COALESCE(e.full_name,u.username,'Employee') AS employee_name
            FROM project_vendor_finalization_remarks vr
            LEFT JOIN projects p ON p.id=vr.project_id
            LEFT JOIN users u ON u.id=vr.created_by
            LEFT JOIN employees e ON e.id=u.employee_id
            WHERE vr.urgency IN ('urgent','critical')
              $projectAccessSql
            ORDER BY FIELD(vr.urgency,'critical','urgent','normal'), vr.created_at DESC, vr.id DESC
            LIMIT 8
          ");
          while ($remarkQ && ($rr = mysqli_fetch_assoc($remarkQ))) $urgentVendorRemarks[] = $rr;
        }

        $urgentPmsItems = [];
        if (dashboard_table_exists($conn, 'project_pmc_schedule_items')) {
          $projectAccessSql = $hasAssignedProjects ? "AND task.project_id IN ($assignedProjectSql)" : "";
          $pmsQ = mysqli_query($conn, "
            SELECT
              task.id,
              task.project_id,
              task.schedule_id,
              task.title,
              task.planned_start_date,
              task.planned_end_date,
              task.duration_days,
              task.item_status,
              task.progress_percent,
              p.project_name,
              p.project_code,
              topic.title AS topic_title
            FROM project_pmc_schedule_items task
            LEFT JOIN project_pmc_schedule_items topic ON topic.id=task.parent_id
            LEFT JOIN projects p ON p.id=task.project_id
            WHERE task.is_active=1
              AND task.item_type='task'
              AND COALESCE(task.item_status,'pending') <> 'completed'
              AND task.planned_end_date IS NOT NULL
              AND task.planned_end_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
              $projectAccessSql
            ORDER BY task.planned_end_date ASC, p.project_name ASC
            LIMIT 10
          ");
          while ($pmsQ && ($pr = mysqli_fetch_assoc($pmsQ))) $urgentPmsItems[] = $pr;
        }

        $showUrgentPopup = (count($urgentVendorRemarks) + count($urgentPmsItems)) > 0;
        ?>
        <div class="row g-3 mt-2 kpi-row">
          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="kpi-card">
              <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);"><span
                  class="fs-3 fw-semibold">₹</span></div>
              <div>
                <div class="kpi-label">Total Invoices <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="kpi-value">₹2,84,54,000</p>
                <p class="kpi-sub"><span>↑ 18.6%</span> vs Previous Month</p>
              </div>
            </article>
          </div>
          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="kpi-card">
              <div class="kpi-icon bg-success-subtle text-success"><i data-lucide="trending-up"
                  style="width:24px;height:24px;"></i></div>
              <div>
                <div class="kpi-label">Monthly Invoices <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="kpi-value">₹48,73,000</p>
                <p class="kpi-sub"><span>↑ 12.4%</span> vs Last Month</p>
              </div>
            </article>
          </div>
          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="kpi-card">
              <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);"><i
                  data-lucide="building-2" style="width:24px;height:24px;"></i></div>
              <div>
                <div class="kpi-label">Active Projects <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="kpi-value">128</p>
                <p class="kpi-sub"><span>↑ 6</span> vs Last Month</p>
              </div>
            </article>
          </div>
          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="kpi-card">
              <div class="kpi-icon bg-warning-subtle text-warning"><i data-lucide="users"
                  style="width:24px;height:24px;"></i></div>
              <div>
                <div class="kpi-label">Total Sites <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="kpi-value">24,560</p>
                <p class="kpi-sub"><span>↑ 15.3%</span> vs Last Month</p>
              </div>
            </article>
          </div>
          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="kpi-card">
              <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);"><i
                  data-lucide="pie-chart" style="width:24px;height:24px;"></i></div>
              <div>
                <div class="kpi-label">Avg. Progress <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="kpi-value">68.7%</p>
                <p class="kpi-sub"><span>↑ 4.8%</span> vs Last Month</p>
              </div>
            </article>
          </div>
        </div>
        <div class="row g-3 mt-1 analytics-row">
          <div class="col-12 col-xl-5">
            <section class="card-ui p-3 p-lg-4 h-100">
              <div class="d-flex align-items-start justify-content-between gap-3">
                <div>
                  <h2 class="section-title">Invoices Trend <i data-lucide="info" class="text-muted-custom"
                      style="width:14px;height:14px;"></i></h2>
                  <p class="fs-4 fw-bold mt-1 mb-0">₹2,84,54,000 <span class="small text-success fw-bold">↑ 18.6%</span>
                  </p>
                </div><button class="small-select" type="button">Last 30 Days <i data-lucide="chevron-down"
                    style="width:12px;height:12px;"></i></button>
              </div>
              <div class="mt-3 position-relative" style="height:155px;"><svg viewBox="0 0 560 190"
                  class="w-100 h-100 overflow-visible">
                  <defs>
                    <linearGradient id="lineFill" x1="0" x2="0" y1="0" y2="1">
                      <stop offset="0%" stop-color="#315CFF" stop-opacity="0.18" />
                      <stop offset="100%" stop-color="#315CFF" stop-opacity="0.02" />
                    </linearGradient>
                  </defs>
                  <g stroke="#e5e7eb" stroke-width="1">
                    <line x1="48" y1="15" x2="545" y2="15" />
                    <line x1="48" y1="53" x2="545" y2="53" />
                    <line x1="48" y1="91" x2="545" y2="91" />
                    <line x1="48" y1="129" x2="545" y2="129" />
                    <line x1="48" y1="162" x2="545" y2="162" />
                  </g>
                  <g font-size="11" fill="#64748b" font-weight="600"><text x="0" y="19">₹60L</text><text x="0"
                      y="57">₹45L</text><text x="0" y="95">₹30L</text><text x="0" y="133">₹15L</text><text x="8"
                      y="166">₹0</text></g>
                  <path
                    d="M48,132 C70,108 85,102 105,118 C128,135 145,44 178,50 C210,57 205,122 242,122 C280,122 285,76 320,72 C360,69 365,130 398,125 C435,118 435,50 470,50 C500,50 495,74 518,60 C530,50 540,37 545,27 L545,162 L48,162 Z"
                    fill="url(#lineFill)" />
                  <path
                    d="M48,132 C70,108 85,102 105,118 C128,135 145,44 178,50 C210,57 205,122 242,122 C280,122 285,76 320,72 C360,69 365,130 398,125 C435,118 435,50 470,50 C500,50 495,74 518,60 C530,50 540,37 545,27"
                    fill="none" stroke="#315CFF" stroke-width="3" stroke-linecap="round" />
                  <g fill="#315CFF" stroke="#fff" stroke-width="2.5">
                    <circle cx="48" cy="132" r="5" />
                    <circle cx="78" cy="109" r="5" />
                    <circle cx="105" cy="118" r="5" />
                    <circle cx="178" cy="50" r="5" />
                    <circle cx="242" cy="122" r="5" />
                    <circle cx="320" cy="72" r="5" />
                    <circle cx="398" cy="125" r="5" />
                    <circle cx="470" cy="50" r="5" />
                    <circle cx="518" cy="60" r="5" />
                    <circle cx="545" cy="27" r="6" />
                  </g>
                  <g font-size="11" fill="#64748b" font-weight="600"><text x="38" y="184">May 12</text><text x="145"
                      y="184">May 19</text><text x="245" y="184">May 26</text><text x="352" y="184">Jun 2</text><text
                      x="455" y="184">Jun 9</text><text x="520" y="184">Jun 12</text></g>
                </svg></div>
            </section>
          </div>
          <div class="col-12 col-xl-4">
            <section class="card-ui p-3 p-lg-4 h-100">
              <div class="d-flex align-items-start justify-content-between gap-3">
                <h2 class="section-title">Project Cost by Category <i data-lucide="info" class="text-muted-custom"
                    style="width:14px;height:14px;"></i></h2><button class="small-select" type="button">This Month <i
                    data-lucide="chevron-down" style="width:12px;height:12px;"></i></button>
              </div>
              <div class="mt-3 d-flex gap-3" style="height:175px;">
                <div class="d-flex flex-column justify-content-between text-muted-custom fw-semibold pt-1 pb-4"
                  style="width:36px;font-size:11px;">
                  <span>₹80L</span><span>₹60L</span><span>₹40L</span><span>₹20L</span><span>₹0</span>
                </div>
                <div class="flex-fill d-grid align-items-end position-relative pb-4 border-bottom border-soft"
                  style="grid-template-columns:repeat(6,1fr);gap:12px;">
                  <div class="position-absolute start-0 end-0 top-0 border-top border-soft"></div>
                  <div class="position-absolute start-0 end-0 border-top border-soft" style="top:25%;"></div>
                  <div class="position-absolute start-0 end-0 border-top border-soft" style="top:50%;"></div>
                  <div class="position-absolute start-0 end-0 border-top border-soft" style="top:75%;"></div>
                  <div class="bar-wrap">
                    <div class="bar bg-primary" style="height:86%;"></div><span>Civil</span>
                  </div>
                  <div class="bar-wrap">
                    <div class="bar" style="height:63%;background:#8b5cf6;"></div><span>MEP</span>
                  </div>
                  <div class="bar-wrap">
                    <div class="bar" style="height:45%;background:#f97316;"></div><span>Steel</span>
                  </div>
                  <div class="bar-wrap">
                    <div class="bar" style="height:35%;background:#14b8a6;"></div><span>Fin.</span>
                  </div>
                  <div class="bar-wrap">
                    <div class="bar" style="height:27%;background:#ec4899;"></div><span>Int.</span>
                  </div>
                  <div class="bar-wrap">
                    <div class="bar bg-secondary" style="height:18%;"></div><span>Other</span>
                  </div>
                </div>
              </div>
            </section>
          </div>
          <div class="col-12 col-xl-3">
            <section class="card-ui p-3 p-lg-4 h-100">
              <h2 class="section-title">Project Portfolio <i data-lucide="info" class="text-muted-custom"
                  style="width:14px;height:14px;"></i></h2>
              <div class="mt-4 d-flex flex-column flex-sm-row flex-xl-column flex-xxl-row align-items-center gap-4">
                <div class="position-relative rounded-circle flex-shrink-0"
                  style="width:144px;height:144px;background:conic-gradient(#315CFF 0 51.2%, #8B5CF6 51.2% 76.7%, #14B8A6 76.7% 97%, #F97316 97% 100%);">
                  <div
                    class="position-absolute rounded-circle bg-card d-flex flex-column align-items-center justify-content-center"
                    style="inset:27px;">
                    <p class="fs-5 fw-bold mb-0">24,560</p>
                    <p class="small text-muted-custom mb-0">Total</p>
                  </div>
                </div>
                <div class="d-grid gap-2" style="min-width:150px;">
                  <div class="legend"><span class="legend-dot bg-primary"></span>
                    <p>Commercial<br><b>12,560</b> <em>(51.2%)</em></p>
                  </div>
                  <div class="legend"><span class="legend-dot" style="background:#8b5cf6;"></span>
                    <p>Residential<br><b>6,250</b> <em>(25.5%)</em></p>
                  </div>
                  <div class="legend"><span class="legend-dot" style="background:#14b8a6;"></span>
                    <p>Industrial<br><b>4,980</b> <em>(20.3%)</em></p>
                  </div>
                  <div class="legend"><span class="legend-dot" style="background:#f97316;"></span>
                    <p>Other<br><b>770</b> <em>(3.0%)</em></p>
                  </div>
                </div>
              </div>
            </section>
          </div>
        </div>
        <div class="row g-3 mt-1 management-row">
          <div class="col-12 col-xl-8">
            <section class="card-ui overflow-hidden h-100">
              <div
                class="p-3 p-lg-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-3">
                <h2 class="fw-bold fs-6 mb-0">Recent Project</h2>
                
              </div>
              <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                <table class="project-table w-100">
                  <thead>
                    <tr>
                      <th>Project Name</th>
                      <th>Project Manager</th>
                      <th>Budget</th>
                      <th>Progress</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody id="projectTableBody"></tbody>
                </table>
              </div>
              <div id="projectMobileCards" class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3"></div>
            </section>
          </div>
          <div class="col-12 col-xl-4">
            <section class="card-ui p-3 p-lg-4 h-100">
              <div class="d-flex align-items-center justify-content-between">
                <h2 class="fw-bold fs-6 mb-0">Recent Transactions</h2>
                <?php if ($canBillingView): ?>
                  <a href="billing.php" class="text-primary small fw-bold">View All</a>
                <?php endif; ?>
              </div>
              <div class="mt-3">
                <div class="transaction">
                  <div class="txn-icon bg-success-subtle text-success"><i data-lucide="landmark"></i></div>
                  <div class="txn-text">
                    <p>Payout to Bank **** 4242</p><small>Jun 12, 2025 · 10:45 AM</small>
                  </div>
                  <div class="txn-amount">
                    <p>-₹12,56,000.00</p><span>Completed</span>
                  </div>
                </div>
                <div class="transaction">
                  <div class="txn-icon bg-primary-subtle text-primary fw-bold">P</div>
                  <div class="txn-text">
                    <p>Vendor Payout</p><small>Jun 11, 2025 · 04:20 PM</small>
                  </div>
                  <div class="txn-amount">
                    <p>-₹8,43,000.00</p><span>Completed</span>
                  </div>
                </div>
                <div class="transaction">
                  <div class="txn-icon fw-bold" style="color:#7c3aed;background:#ede9fe;">S</div>
                  <div class="txn-text">
                    <p>Subcontractor Payout</p><small>Jun 10, 2025 · 11:15 AM</small>
                  </div>
                  <div class="txn-amount">
                    <p>-₹6,25,000.00</p><span>Completed</span>
                  </div>
                </div>
                <div class="transaction">
                  <div class="txn-icon bg-warning-subtle text-warning"><i data-lucide="shopping-cart"></i></div>
                  <div class="txn-text">
                    <p>Site Material Purchase</p><small>Jun 9, 2025 · 09:30 PM</small>
                  </div>
                  <div class="txn-amount">
                    <p>+₹6,999.00</p><span>Completed</span>
                  </div>
                </div>
                <div class="transaction">
                  <div class="txn-icon bg-info-subtle text-info"><i data-lucide="shopping-cart"></i></div>
                  <div class="txn-text">
                    <p>Consultant Payment</p><small>Jun 8, 2025 · 08:21 PM</small>
                  </div>
                  <div class="txn-amount">
                    <p>+₹4,499.00</p><span>Completed</span>
                  </div>
                </div>
              </div>
              <?php if ($canBillingView): ?>
                <a href="billing.php"
                  class="mt-2 d-flex align-items-center justify-content-between text-primary fw-bold small">View All
                  Transactions <i data-lucide="arrow-right" style="width:16px;height:16px;"></i></a>
              <?php endif; ?>
            </section>
          </div>
        </div>
        <?php include("includes/footer.php") ?>
      </section>
    </main>
    <div id="settingsOverlay"></div>
    <?php include("includes/rightsidbar.php") ?>
  </div>

  <?php if($showUrgentPopup): ?>
    <button type="button" class="urgent-float-btn" data-bs-toggle="modal" data-bs-target="#urgentInfoModal">
      <i data-lucide="alert-triangle" style="width:18px;height:18px;"></i>
      Urgent Info <?= (int)(count($urgentVendorRemarks) + count($urgentPmsItems)) ?>
    </button>

    <div class="modal fade urgent-modal" id="urgentInfoModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="urgent-modal-head">
            <div class="d-flex justify-content-between align-items-start gap-3">
              <div>
                <h5 class="modal-title fw-bold mb-1">Urgent Information</h5>
                <div class="small opacity-75">Vendor urgent remarks and PMS near/overdue tasks that need attention.</div>
              </div>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
          </div>

          <div class="modal-body p-3 p-lg-4">
            <div class="urgent-tabs">
              <button type="button" class="urgent-tab active" data-urgent-tab="remarksPane">Urgent Remarks (<?= count($urgentVendorRemarks) ?>)</button>
              <button type="button" class="urgent-tab" data-urgent-tab="pmsPane">PMS Alerts (<?= count($urgentPmsItems) ?>)</button>
            </div>

            <div id="remarksPane" class="urgent-pane active">
              <?php if(!$urgentVendorRemarks): ?>
                <div class="text-muted-custom fw-bold">No urgent vendor remarks found.</div>
              <?php endif; ?>

              <?php foreach($urgentVendorRemarks as $rm): ?>
                <?php $urgencyClass = strtolower($rm['urgency'] ?? 'urgent') === 'critical' ? 'critical' : 'urgent'; ?>
                <div class="urgent-box <?= e($urgencyClass) ?>">
                  <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div>
                      <div class="urgent-title"><?= e($rm['package_title'] ?: 'Vendor Finalization') ?></div>
                      <div class="urgent-meta"><?= e($rm['project_name'] ?: '-') ?><?= !empty($rm['project_code']) ? ' · '.e($rm['project_code']) : '' ?></div>
                    </div>
                    <span class="urgent-chip <?= e($urgencyClass) ?>"><?= e(ucwords($rm['urgency'] ?: 'urgent')) ?></span>
                  </div>
                  <div class="urgent-message"><?= nl2br(e($rm['remark'])) ?></div>
                  <div class="urgent-meta mt-2">
                    Posted by <?= e($rm['employee_name'] ?: 'Employee') ?>
                    · <?= !empty($rm['created_at']) ? e(date('d M Y h:i A', strtotime($rm['created_at']))) : '-' ?>
                  </div>
                  <div class="mt-2">
                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold" href="pms-vendor-schedule.php?project_id=<?= (int)$rm['project_id'] ?>#vendor-packages-section">Open Vendor Schedule</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div id="pmsPane" class="urgent-pane">
              <?php if(!$urgentPmsItems): ?>
                <div class="text-muted-custom fw-bold">No near/overdue PMS tasks found.</div>
              <?php endif; ?>

              <?php foreach($urgentPmsItems as $pms): ?>
                <?php
                  $dueTs = !empty($pms['planned_end_date']) ? strtotime($pms['planned_end_date']) : 0;
                  $isOverdue = $dueTs && $dueTs < strtotime(date('Y-m-d'));
                ?>
                <div class="urgent-box <?= $isOverdue ? 'critical' : 'urgent' ?>">
                  <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div>
                      <div class="urgent-title"><?= e($pms['title'] ?: 'PMS Task') ?></div>
                      <div class="urgent-meta">
                        <?= e($pms['project_name'] ?: '-') ?><?= !empty($pms['project_code']) ? ' · '.e($pms['project_code']) : '' ?>
                        <?= !empty($pms['topic_title']) ? ' · '.e($pms['topic_title']) : '' ?>
                      </div>
                    </div>
                    <span class="urgent-chip <?= $isOverdue ? 'critical' : 'pms' ?>"><?= $isOverdue ? 'Overdue' : 'Near Due' ?></span>
                  </div>
                  <div class="urgent-meta">
                    Required by: <b><?= !empty($pms['planned_end_date']) ? e(date('d M Y', strtotime($pms['planned_end_date']))) : '-' ?></b>
                    · Progress: <?= (int)($pms['progress_percent'] ?? 0) ?>%
                    · Status: <?= e(ucwords(str_replace('_',' ', $pms['item_status'] ?: 'pending'))) ?>
                  </div>
                  <div class="mt-2">
                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold" href="pms.php?project_id=<?= (int)$pms['project_id'] ?>">Open PMS</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>


  <?php include("includes/script.php") ?>
  <script src="assets/js/script.js?v=20"></script>

  <script>
    document.addEventListener("DOMContentLoaded", function () {
      if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();

      document.querySelectorAll("[data-urgent-tab]").forEach(function(btn){
        btn.addEventListener("click", function(){
          const target = btn.getAttribute("data-urgent-tab");
          document.querySelectorAll("[data-urgent-tab]").forEach(b => b.classList.remove("active"));
          document.querySelectorAll(".urgent-pane").forEach(p => p.classList.remove("active"));
          btn.classList.add("active");
          const pane = document.getElementById(target);
          if (pane) pane.classList.add("active");
        });
      });

      const urgentModalEl = document.getElementById("urgentInfoModal");
      if (urgentModalEl && !sessionStorage.getItem("urgentInfoSeen")) {
        setTimeout(function(){
          if (window.bootstrap && bootstrap.Modal) {
            new bootstrap.Modal(urgentModalEl).show();
            sessionStorage.setItem("urgentInfoSeen", "1");
          }
        }, 700);
      }
    });
  </script>

</body>

</html>