<?php
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
  <?php include("includes/script.php") ?>
  <script src="assets/js/script.js?v=20"></script>
</body>

</html>