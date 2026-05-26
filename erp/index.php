<!DOCTYPE html>
<html lang="en" class="light">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>TEK-C PMC Construction - Admin Dashboard</title>

  <!-- Bootstrap 5 version. Tailwind removed. -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest"></script>

  <style>
    :root {
      --sidebar-bg: #ffffff;
      --sidebar-text: #334155;
      --topbar-bg: rgba(255, 255, 255, .95);
      --brand-1: #0f766e;
      --brand-2: #2563eb;
      --body-bg: #f7f9fc;
      --text-main: #0f172a;
      --text-muted: #64748b;
      --border-soft: #e2e8f0;
      --card-bg: #ffffff;
      --shadow-card: 0 6px 18px rgba(15, 23, 42, .06);
    }

    html.dark {
      --sidebar-bg: #0f172a;
      --sidebar-text: #cbd5e1;
      --topbar-bg: rgba(15, 23, 42, .95);
      --body-bg: #020617;
      --text-main: #f8fafc;
      --text-muted: #94a3b8;
      --border-soft: #1e293b;
      --card-bg: #0f172a;
      color-scheme: dark;
    }

    * {
      box-sizing: border-box
    }

    body {
      font-family: Inter, sans-serif;
      background: var(--body-bg);
      color: var(--text-main);
      overflow-x: hidden;
      transition: .3s
    }

    a {
      text-decoration: none
    }

    .text-muted-custom {
      color: var(--text-muted) !important
    }

    .brand-gradient {
      background-image: linear-gradient(135deg, var(--brand-1), var(--brand-2))
    }

    .brand-accent-text {
      color: var(--brand-2)
    }

    .border-soft {
      border-color: var(--border-soft) !important
    }

    .bg-card {
      background: var(--card-bg) !important
    }

    .thin-scrollbar::-webkit-scrollbar {
      width: 5px;
      height: 5px
    }

    .thin-scrollbar::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 999px
    }

    #sidebar {
      width: 245px;
      background: var(--sidebar-bg) !important;
      color: var(--sidebar-text);
      border-right: 1px solid var(--border-soft);
      position: fixed;
      left: 0;
      top: 0;
      bottom: 0;
      z-index: 1040;
      display: flex;
      flex-direction: column;
      transition: .3s
    }

    #main {
      width: 100%;
      padding-left: 245px;
      transition: .3s
    }

    #topbar {
      height: 60px;
      background: var(--topbar-bg) !important;
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border-soft);
      position: sticky;
      top: 0;
      z-index: 1020
    }

    .sidebar-header {
      height: 60px;
      padding: 0 16px;
      border-bottom: 1px solid var(--border-soft);
      display: flex;
      align-items: center
    }

    #sidebarLogo {
      width: 36px;
      height: 36px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      box-shadow: 0 8px 18px rgba(15, 23, 42, .18)
    }

    .sidebar-brand-text {
      font-size: 20px;
      font-weight: 800;
      letter-spacing: -.03em;
      line-height: 1;
      white-space: nowrap
    }

    .sidebar-nav {
      padding: 16px 12px;
      flex: 1;
      overflow-y: auto
    }

    .nav-link-custom {
      height: 40px;
      padding: 0 12px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      gap: 12px;
      color: var(--sidebar-text);
      font-size: 14px;
      font-weight: 600;
      transition: .2s;
      margin-bottom: 4px
    }

    .nav-link-custom:hover {
      background: rgba(148, 163, 184, .12);
      color: var(--sidebar-text)
    }

    .nav-link-custom.active {
      background-image: linear-gradient(135deg, var(--brand-1), var(--brand-2));
      color: #fff;
      box-shadow: 0 8px 18px rgba(15, 23, 42, .15)
    }

    .nav-link-custom svg {
      width: 16px;
      height: 16px;
      flex-shrink: 0
    }

    .sidebar-promo {
      padding: 12px
    }

    .promo-card {
      border-radius: 22px;
      padding: 16px;
      text-align: center;
      background: linear-gradient(135deg, #eef2ff, #faf5ff, #ecfeff);
      border: 1px solid #e0e7ff;
      box-shadow: var(--shadow-card)
    }

    html.dark .promo-card {
      background: linear-gradient(135deg, #1e293b, #0f172a);
      border-color: #334155
    }

    .promo-icon {
      width: 44px;
      height: 44px;
      border-radius: 999px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      background: linear-gradient(135deg, #7c3aed, #4f46e5);
      box-shadow: 0 8px 18px rgba(124, 58, 237, .25)
    }

    .sidebar-footer {
      border-top: 1px solid var(--border-soft);
      padding: 12px
    }

    .icon-btn {
      width: 36px;
      height: 36px;
      border-radius: 14px;
      border: 1px solid var(--border-soft);
      background: var(--card-bg);
      color: var(--text-main);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: .2s
    }

    .icon-btn:hover {
      background: rgba(148, 163, 184, .14)
    }

    .top-search {
      position: relative;
      max-width: 520px;
      flex: 1
    }

    .top-search svg {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      width: 16px;
      height: 16px;
      color: #94a3b8
    }

    .top-search input {
      height: 40px;
      border-radius: 14px;
      border: 1px solid var(--border-soft);
      background: var(--card-bg);
      color: var(--text-main);
      padding-left: 40px;
      padding-right: 54px;
      font-size: 14px;
      outline: none;
      width: 100%
    }

    .top-search input:focus {
      border-color: #60a5fa;
      box-shadow: 0 0 0 4px rgba(96, 165, 250, .2)
    }

    .kbd-pill {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 11px;
      font-weight: 700;
      color: var(--text-muted);
      border: 1px solid var(--border-soft);
      border-radius: 7px;
      padding: 2px 6px
    }

    .card-ui {
      background: var(--card-bg);
      border: 1px solid var(--border-soft);
      border-radius: 22px;
      box-shadow: var(--shadow-card)
    }

    .dropdown-card {
      background: var(--card-bg);
      border: 1px solid var(--border-soft);
      border-radius: 18px;
      box-shadow: var(--shadow-card)
    }

    .dropdown-menu-custom {
      position: absolute;
      right: 0;
      top: calc(100% + 8px);
      z-index: 1055;
      display: none
    }

    .dropdown-menu-custom.show {
      display: block
    }

    .dropdown-item-custom {
      width: 100%;
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 14px;
      color: var(--text-main);
      font-size: 14px;
      font-weight: 600;
      background: transparent;
      border: 0;
      text-align: left
    }

    .dropdown-item-custom:hover {
      background: rgba(148, 163, 184, .14);
      color: var(--text-main)
    }

    .notify-item {
      display: flex;
      gap: 12px;
      padding: 8px;
      border-radius: 14px
    }

    .notify-item:hover {
      background: rgba(148, 163, 184, .12)
    }

    .notify-icon {
      width: 36px;
      height: 36px;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0
    }

    .notify-icon svg {
      width: 16px;
      height: 16px
    }

    .kpi-card {
      background: var(--card-bg);
      border: 1px solid var(--border-soft);
      border-radius: 22px;
      box-shadow: var(--shadow-card);
      padding: 16px;
      display: flex;
      align-items: center;
      gap: 12px;
      min-height: 105px
    }

    .kpi-icon {
      width: 48px;
      height: 48px;
      border-radius: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0
    }

    .kpi-label {
      display: flex;
      align-items: center;
      gap: 4px;
      color: var(--text-muted);
      font-size: 12px;
      font-weight: 700
    }

    .kpi-value {
      font-size: 20px;
      font-weight: 800;
      margin: 4px 0 0;
      letter-spacing: -.02em
    }

    .kpi-sub {
      font-size: 11px;
      color: var(--text-muted);
      margin: 3px 0 0
    }

    .kpi-sub span {
      color: #059669;
      font-weight: 800
    }

    .section-title {
      font-size: 14px;
      font-weight: 800;
      display: flex;
      align-items: center;
      gap: 4px;
      margin: 0
    }

    .small-select {
      height: 32px;
      padding: 0 10px;
      border-radius: 10px;
      border: 1px solid var(--border-soft);
      background: var(--card-bg);
      color: var(--text-muted);
      font-size: 11px;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      gap: 4px
    }

    .bar-wrap {
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: end;
      height: 100%;
      z-index: 1
    }

    .bar {
      width: 100%;
      max-width: 32px;
      border-radius: 8px 8px 0 0
    }

    .bar-wrap span {
      position: absolute;
      bottom: -24px;
      color: var(--text-muted);
      font-size: 10px;
      font-weight: 700
    }

    .legend {
      display: flex;
      align-items: flex-start;
      gap: 8px
    }

    .legend-dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
      margin-top: 4px;
      flex-shrink: 0
    }

    .legend p {
      margin: 0;
      color: var(--text-muted);
      font-size: 12px;
      line-height: 1.35
    }

    .legend b {
      color: var(--text-main);
      font-weight: 800
    }

    .legend em {
      color: var(--text-muted);
      font-style: normal;
      font-weight: 700
    }

    .project-table {
      min-width: 860px;
      border-collapse: separate;
      border-spacing: 0
    }

    .project-table thead tr {
      background: rgba(148, 163, 184, .11);
      color: var(--text-muted);
      font-size: 11px;
      font-weight: 800
    }

    .project-table th {
      padding: 12px 8px;
      text-align: left
    }

    .project-table th:first-child {
      border-radius: 14px 0 0 14px
    }

    .project-table th:last-child {
      border-radius: 0 14px 14px 0
    }

    .project-table td {
      padding: 12px 8px;
      font-size: 12px;
      border-bottom: 1px solid var(--border-soft);
      vertical-align: middle
    }

    .project-table tbody tr:hover {
      background: rgba(148, 163, 184, .08)
    }

    .project-title {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 700
    }

    .project-icon {
      width: 32px;
      height: 32px;
      border-radius: 10px;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      font-size: 12px
    }

    .person {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 600
    }

    .person img {
      width: 28px;
      height: 28px;
      border-radius: 999px
    }

    .progress-cell {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 700
    }

    .progress-track {
      width: 64px;
      height: 6px;
      background: #e2e8f0;
      border-radius: 999px
    }

    html.dark .progress-track {
      background: #334155
    }

    .progress-fill {
      height: 100%;
      background: #2563eb;
      border-radius: 999px;
      display: block
    }

    .pill {
      padding: 4px 8px;
      border-radius: 7px;
      font-size: 10px;
      font-weight: 800
    }

    .pill.green {
      background: #d1fae5;
      color: #047857
    }

    .pill.amber {
      background: #fef3c7;
      color: #b45309
    }

    .action-btn {
      width: 32px;
      height: 32px;
      border-radius: 10px;
      border: 1px solid var(--border-soft);
      background: var(--card-bg);
      color: var(--text-main);
      display: inline-flex;
      align-items: center;
      justify-content: center
    }

    .action-btn:hover {
      background: rgba(148, 163, 184, .12)
    }

    .action-btn svg {
      width: 14px;
      height: 14px
    }

    .page-btn {
      width: 32px;
      height: 32px;
      border-radius: 10px;
      border: 1px solid transparent;
      background: transparent;
      color: var(--text-muted);
      font-size: 12px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      justify-content: center
    }

    .page-btn:hover {
      background: rgba(148, 163, 184, .12)
    }

    .page-btn.active {
      background: #dbeafe;
      color: #1d4ed8;
      border-color: #bfdbfe
    }

    .transaction {
      padding: 12px 0;
      display: flex;
      align-items: center;
      gap: 12px;
      border-bottom: 1px solid var(--border-soft)
    }

    .transaction:last-child {
      border-bottom: 0
    }

    .txn-icon {
      width: 36px;
      height: 36px;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0
    }

    .txn-icon svg {
      width: 16px;
      height: 16px
    }

    .txn-text {
      flex: 1;
      min-width: 0
    }

    .txn-text p {
      margin: 0;
      font-size: 12px;
      font-weight: 800;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis
    }

    .txn-text small {
      display: block;
      margin-top: 2px;
      font-size: 11px;
      color: var(--text-muted)
    }

    .txn-amount {
      text-align: right;
      flex-shrink: 0
    }

    .txn-amount p {
      margin: 0;
      font-size: 12px;
      font-weight: 800
    }

    .txn-amount span {
      font-size: 10px;
      padding: 2px 6px;
      border-radius: 7px;
      background: #d1fae5;
      color: #047857;
      font-weight: 800
    }

    .mobile-project-card {
      border-radius: 22px;
      border: 1px solid var(--border-soft);
      background: rgba(148, 163, 184, .07);
      padding: 12px
    }

    .mobile-info {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      font-size: 12px;
      padding: 6px 0;
      border-bottom: 1px solid var(--border-soft)
    }

    .mobile-info:last-child {
      border-bottom: 0
    }

    .mobile-info span:first-child {
      color: var(--text-muted);
      font-weight: 700
    }

    .mobile-info span:last-child {
      font-weight: 800;
      text-align: right
    }

    #settingsOverlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(2, 6, 23, .4);
      z-index: 1050
    }

    #settingsPanel {
      position: fixed;
      right: 0;
      top: 0;
      bottom: 0;
      z-index: 1060;
      width: 100%;
      max-width: 384px;
      transform: translateX(100%);
      transition: .3s;
      background: var(--card-bg);
      border-left: 1px solid var(--border-soft);
      display: flex;
      flex-direction: column;
      box-shadow: -12px 0 28px rgba(15, 23, 42, .22)
    }

    .settings-panel-open #settingsOverlay {
      display: block
    }

    .settings-panel-open #settingsPanel {
      transform: translateX(0)
    }

    .sidebar-collapsed #sidebar {
      width: 78px
    }

    .sidebar-collapsed #main {
      padding-left: 78px
    }

    .sidebar-collapsed .sidebar-text,
    .sidebar-collapsed .sidebar-brand-text,
    .sidebar-collapsed .sidebar-promo,
    .sidebar-collapsed .sidebar-help-text,
    .sidebar-collapsed .sidebar-arrow {
      display: none !important
    }

    .sidebar-collapsed .nav-link-custom {
      justify-content: center;
      padding-left: 12px;
      padding-right: 12px
    }

    .sidebar-collapsed #sidebarLogo {
      margin-left: auto;
      margin-right: auto
    }

    .compact-layout .kpi-card,
    .compact-layout .card-ui {
      border-radius: 16px
    }

    .compact-layout .page-section {
      padding: 12px !important
    }

    @media(max-width:1279px) {
      #main {
        padding-left: 0
      }

      .sidebar-collapsed #main {
        padding-left: 0
      }

      #sidebar {
        transform: translateX(-100%)
      }

      body.mobile-sidebar-open #sidebar {
        transform: translateX(0)
      }
    }

    @media(max-width:575px) {
      .page-section {
        padding: 12px !important
      }

      .top-search {
        display: none
      }
    }
  </style>
</head>

<body>
  <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none">
  </div>
  <div class="min-vh-100 d-flex">
    <aside id="sidebar">
      <div class="sidebar-header">
        <div class="d-flex align-items-center gap-2 w-100">
          <div id="sidebarLogo" class="brand-gradient"><i data-lucide="hard-hat" class="text-white"></i></div>
          <div class="sidebar-brand-text"><span>TEK-C</span> <span class="brand-accent-text">PMC</span></div><button
            id="closeMobileSidebar" class="icon-btn ms-auto d-xl-none border-0" type="button"><i
              data-lucide="x"></i></button>
        </div>
      </div>
      <nav class="sidebar-nav thin-scrollbar">
        <a href="#" class="nav-link-custom active"><i data-lucide="layout-dashboard"></i><span
            class="sidebar-text">Dashboard</span></a>
        <a href="#" class="nav-link-custom"><i data-lucide="building-2"></i><span
            class="sidebar-text">Projects</span></a>
        <a href="#" class="nav-link-custom"><i data-lucide="map-pin"></i><span class="sidebar-text">Sites</span></a>
        <a href="#" class="nav-link-custom"><i data-lucide="users"></i><span class="sidebar-text">Clients</span></a>
        <a href="#" class="nav-link-custom"><i data-lucide="hard-hat"></i><span
            class="sidebar-text">Engineers</span></a>
        <a href="#" class="nav-link-custom"><i data-lucide="truck"></i><span class="sidebar-text">Materials</span></a>
        <a href="#" class="nav-link-custom"><i data-lucide="wallet"></i><span class="sidebar-text">Billing</span></a>
        <a href="#" class="nav-link-custom"><i data-lucide="bar-chart-3"></i><span
            class="sidebar-text">Reports</span></a>
        <a href="#" class="nav-link-custom"><i data-lucide="clipboard-check"></i><span
            class="sidebar-text">Approvals</span></a>
      </nav>
      <div class="sidebar-promo">
        <div class="promo-card">
          <div class="promo-icon"><i data-lucide="crown" style="width:20px;height:20px;"></i></div>
          <h3 class="mt-3 mb-0 fw-bold fs-6">PMC Control Room</h3>
          <p class="mt-1 mb-0 small text-muted-custom lh-base">Track estimates, site progress, approvals and billing
            from one place.</p><button class="btn brand-gradient text-white fw-semibold w-100 mt-3 rounded-4 btn-sm"
            type="button">Open Workspace</button>
        </div>
      </div>
      <div class="sidebar-footer">
        <div class="d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <div class="rounded-circle bg-body-secondary d-flex align-items-center justify-content-center flex-shrink-0"
              style="width:36px;height:36px;"><i data-lucide="headphones" style="width:16px;height:16px;"></i></div>
            <div class="sidebar-help-text">
              <p class="fw-bold small mb-0">Need Site Support?</p>
              <p class="mb-0 text-muted-custom" style="font-size:11px;">Contact TEK-C Helpdesk</p>
            </div>
          </div><i data-lucide="chevron-right" class="sidebar-arrow text-muted-custom"
            style="width:16px;height:16px;"></i>
        </div>
      </div>
    </aside>
    <main id="main">
      <header id="topbar" class="d-flex align-items-center px-3 px-lg-4">
        <div class="d-flex align-items-center gap-3 w-100"><button id="sidebarToggle" class="icon-btn border-0"
            type="button"><i data-lucide="menu"></i></button>
          <div class="top-search d-none d-sm-block"><i data-lucide="search"></i><input type="text"
              placeholder="Search projects, sites, clients, engineers..." /><span class="kbd-pill">⌘K</span></div>
          <div class="ms-auto d-flex align-items-center gap-2 gap-sm-3">
            <div class="position-relative d-none d-lg-block"><button data-dropdown-target="dateDropdown"
                class="dropdown-btn small-select bg-card" type="button"><i data-lucide="calendar-days"
                  style="width:16px;height:16px;"></i><span id="selectedDateLabel">May 12 - Jun 12, 2025</span><i
                  data-lucide="chevron-down" style="width:14px;height:14px;"></i></button>
              <div id="dateDropdown" class="dropdown-menu-custom dropdown-card p-2" style="width:240px;"><button
                  class="date-option dropdown-item-custom" data-label="Last 7 Days" type="button">Last 7
                  Days</button><button class="date-option dropdown-item-custom" data-label="Last 30 Days"
                  type="button">Last 30 Days</button><button class="date-option dropdown-item-custom"
                  data-label="This Month" type="button">This Month</button><button
                  class="date-option dropdown-item-custom" data-label="May 12 - Jun 12, 2025" type="button">May 12 - Jun
                  12, 2025</button></div>
            </div>
            <button id="settingsToggle" class="icon-btn" type="button" title="Customize dashboard"><i
                data-lucide="settings" style="width:16px;height:16px;"></i></button><button id="themeToggle"
              class="icon-btn" type="button" title="Toggle light/dark mode"><i id="themeIcon" data-lucide="moon"
                style="width:16px;height:16px;"></i></button>
            <div class="position-relative"><button data-dropdown-target="notificationDropdown"
                class="dropdown-btn icon-btn border-0 position-relative" type="button"><i data-lucide="bell"></i><span
                  class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                  style="font-size:9px;">3</span></button>
              <div id="notificationDropdown" class="dropdown-menu-custom dropdown-card p-3" style="width:320px;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <h3 class="fw-bold fs-6 mb-0">Notifications</h3><button
                    class="btn btn-link p-0 text-primary fw-bold small" type="button">Mark all read</button>
                </div>
                <div>
                  <div class="notify-item"><span class="notify-icon bg-primary-subtle text-primary"><i
                        data-lucide="building-2"></i></span>
                    <div>
                      <p class="mb-0 fw-bold small">New project submitted</p><small class="text-muted-custom">Tower
                        block estimate needs review</small>
                    </div>
                  </div>
                  <div class="notify-item"><span class="notify-icon bg-success-subtle text-success"><i
                        data-lucide="indian-rupee"></i></span>
                    <div>
                      <p class="mb-0 fw-bold small">Payment received</p><small class="text-muted-custom">₹6,99,000 from
                        a client</small>
                    </div>
                  </div>
                  <div class="notify-item"><span class="notify-icon bg-warning-subtle text-warning"><i
                        data-lucide="alert-circle"></i></span>
                    <div>
                      <p class="mb-0 fw-bold small">Report ready</p><small class="text-muted-custom">Monthly project
                        report generated</small>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="position-relative"><button data-dropdown-target="profileDropdown"
                class="dropdown-btn btn border-0 d-flex align-items-center gap-2 rounded-4 p-1" type="button"><img
                  src="https://i.pravatar.cc/80?img=12" alt="Admin" class="rounded-circle object-fit-cover"
                  style="width:32px;height:32px;" />
                <div class="d-none d-md-block text-start lh-sm">
                  <p class="fw-bold mb-0" style="font-size:12px;">Admin User</p>
                  <p class="text-muted-custom mb-0" style="font-size:11px;">Super Administrator</p>
                </div><i data-lucide="chevron-down" class="text-muted-custom" style="width:16px;height:16px;"></i>
              </button>
              <div id="profileDropdown" class="dropdown-menu-custom dropdown-card p-2" style="width:224px;">
                <div class="px-3 py-2 border-bottom border-soft mb-1">
                  <p class="fw-bold small mb-0">Admin User</p>
                  <p class="text-muted-custom small mb-0">admin@tek-c.com</p>
                </div><a href="#" class="dropdown-item-custom"><i data-lucide="user"
                    style="width:16px;height:16px;"></i>My Profile</a><a href="#" class="dropdown-item-custom"><i
                    data-lucide="settings" style="width:16px;height:16px;"></i>Company Settings</a><a href="#"
                  class="dropdown-item-custom"><i data-lucide="credit-card"
                    style="width:16px;height:16px;"></i>Invoices</a><a href="#"
                  class="dropdown-item-custom text-danger"><i data-lucide="log-out"
                    style="width:16px;height:16px;"></i>Logout</a>
              </div>
            </div>
          </div>
        </div>
      </header>
      <section class="page-section p-3 p-lg-4">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
          <div>
            <h1 class="h3 fw-bold mb-1">Dashboard</h1>
            <p class="mb-0 text-muted-custom small">Welcome back! Here's what's happening across your construction
              projects today.</p>
          </div><button
            class="btn brand-gradient text-white shadow-sm rounded-4 fw-bold btn-sm px-3 py-2 d-flex align-items-center gap-2 w-auto"
            type="button"><i data-lucide="download" style="width:16px;height:16px;"></i>Export Project Report</button>
        </div>
        <div class="row g-3 mt-2">
          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="kpi-card">
              <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);"><span
                  class="fs-3 fw-semibold">₹</span></div>
              <div>
                <div class="kpi-label">Total Invoices <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="kpi-value">₹2,84,54,000</p>
                <p class="kpi-sub"><span>↑ 18.6%</span> vs Apr 12 - May 11</p>
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
        <div class="row g-3 mt-1">
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
                  <span>₹80L</span><span>₹60L</span><span>₹40L</span><span>₹20L</span><span>₹0</span></div>
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
        <div class="row g-3 mt-1">
          <div class="col-12 col-xl-8">
            <section class="card-ui overflow-hidden h-100">
              <div
                class="p-3 p-lg-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-3">
                <h2 class="fw-bold fs-6 mb-0">Project Management</h2>
                <div class="d-flex gap-2"><button class="btn btn-outline-primary rounded-4 fw-bold btn-sm"
                    type="button">View All Projects</button><button
                    class="btn brand-gradient text-white rounded-4 fw-bold btn-sm d-flex align-items-center gap-1"
                    type="button"><i data-lucide="plus" style="width:16px;height:16px;"></i>Add Project</button></div>
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
                      <th>Invoices</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody id="projectTableBody"></tbody>
                </table>
              </div>
              <div id="projectMobileCards" class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3"></div>
              <div
                class="px-3 px-lg-4 pb-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-3">
                <div class="d-flex align-items-center gap-1"><button class="page-btn"><i data-lucide="chevron-left"
                      style="width:14px;height:14px;"></i></button><button class="page-btn active">1</button><button
                    class="page-btn">2</button><button class="page-btn">3</button><button
                    class="page-btn">4</button><button class="page-btn">5</button><span
                    class="px-1 text-muted-custom small">...</span><button class="page-btn">10</button><button
                    class="page-btn"><i data-lucide="chevron-right" style="width:14px;height:14px;"></i></button></div>
                <p class="text-muted-custom fw-medium small mb-0">Showing 1 to 5 of 48 projects</p>
              </div>
            </section>
          </div>
          <div class="col-12 col-xl-4">
            <section class="card-ui p-3 p-lg-4 h-100">
              <div class="d-flex align-items-center justify-content-between">
                <h2 class="fw-bold fs-6 mb-0">Recent Transactions</h2><a href="#"
                  class="text-primary small fw-bold">View All</a>
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
              </div><a href="#"
                class="mt-2 d-flex align-items-center justify-content-between text-primary fw-bold small">View All
                Transactions <i data-lucide="arrow-right" style="width:16px;height:16px;"></i></a>
            </section>
          </div>
        </div>
        <footer class="mt-4 card-ui px-3 px-lg-4 py-3">
          <div
            class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-2 small text-muted-custom">
            <p class="mb-0">© <span id="currentYear"></span> <span class="fw-bold"
                style="color:var(--text-main);">TEK-C</span>. All rights reserved.</p>
            <div class="d-flex align-items-center gap-3"><a href="#" class="text-muted-custom fw-semibold">Privacy
                Policy</a><span class="rounded-circle bg-secondary-subtle" style="width:4px;height:4px;"></span><a
                href="#" class="text-muted-custom fw-semibold">Terms</a><span class="rounded-circle bg-secondary-subtle"
                style="width:4px;height:4px;"></span><a href="#" class="text-muted-custom fw-semibold">Support</a></div>
          </div>
        </footer>
      </section>
    </main>
    <div id="settingsOverlay"></div>
    <aside id="settingsPanel">
      <div class="d-flex align-items-center justify-content-between px-4 border-bottom border-soft"
        style="height:60px;">
        <div>
          <h2 class="fw-bold fs-6 mb-0">Dashboard Settings</h2>
          <p class="small text-muted-custom mb-0">Customize TEK-C appearance</p>
        </div><button id="settingsClose" class="icon-btn border-0" type="button"><i data-lucide="x"></i></button>
      </div>
      <div class="p-4 d-grid gap-4 overflow-auto thin-scrollbar">
        <div class="rounded-4 border border-primary-subtle bg-primary-subtle p-3 small text-muted-custom lh-base">
          Sidebar, topbar and sidebar text colors are customizable in light mode only. Dark mode automatically uses
          readable dark colors.</div>
        <div><label class="small fw-bold text-muted-custom text-uppercase">Sidebar Color</label><input
            id="sidebarColorPicker" type="color" value="#ffffff"
            class="form-control form-control-color w-100 mt-2 rounded-4" style="height:44px;" /></div>
        <div><label class="small fw-bold text-muted-custom text-uppercase">Topbar Color</label><input
            id="topbarColorPicker" type="color" value="#ffffff"
            class="form-control form-control-color w-100 mt-2 rounded-4" style="height:44px;" /></div>
        <div><label class="small fw-bold text-muted-custom text-uppercase">Primary Brand Color</label><input
            id="primaryColorPicker" type="color" value="#0f766e"
            class="form-control form-control-color w-100 mt-2 rounded-4" style="height:44px;" /></div>
        <div><label class="small fw-bold text-muted-custom text-uppercase">Secondary Brand Color</label><input
            id="secondaryColorPicker" type="color" value="#2563eb"
            class="form-control form-control-color w-100 mt-2 rounded-4" style="height:44px;" /></div>
        <div><label class="small fw-bold text-muted-custom text-uppercase">Sidebar Text Color</label><select
            id="sidebarTextSelect" class="form-select mt-2 rounded-4 fw-semibold">
            <option value="#334155">Dark Slate</option>
            <option value="#ffffff">White</option>
            <option value="#0f172a">Black</option>
            <option value="#dbeafe">Soft Blue</option>
          </select></div>
        <div><label class="small fw-bold text-muted-custom text-uppercase">Layout Density</label><select
            id="densitySelect" class="form-select mt-2 rounded-4 fw-semibold">
            <option value="comfortable">Comfortable</option>
            <option value="compact">Compact</option>
          </select></div>
        <div class="rounded-4 border border-soft bg-body-tertiary p-3">
          <p class="fw-bold small mb-1">Preview</p>
          <p class="small text-muted-custom mb-0">Changes are saved in your browser automatically.</p><button
            id="resetCustomization" class="btn btn-outline-secondary w-100 mt-3 rounded-4 fw-bold" type="button">Reset
            Defaults</button>
        </div>
      </div>
    </aside>
  </div>
  <script>
    const projects = [{ icon: 'TB', iconClass: 'bg-warning text-dark', title: 'TEK-C Tower Block A', manager: 'Ravi Kumar', avatar: 'https://i.pravatar.cc/40?img=11', budget: '₹4.8 Cr', progressLabel: '82%', progress: 82, status: 'On Track', statusClass: 'green', billing: '₹3.92 Cr' }, { icon: 'VC', iconClass: 'bg-dark text-info', title: 'Villas Civil Package', manager: 'Meera Nair', avatar: 'https://i.pravatar.cc/40?img=47', budget: '₹2.6 Cr', progressLabel: '64%', progress: 64, status: 'On Track', statusClass: 'green', billing: '₹1.72 Cr' }, { icon: 'MR', iconClass: 'text-white', customBg: '#ec4899', title: 'Mall Renovation Phase 2', manager: 'Arun Prakash', avatar: 'https://i.pravatar.cc/40?img=13', budget: '₹1.9 Cr', progressLabel: '48%', progress: 48, status: 'Delayed', statusClass: 'amber', billing: '₹86.5 L' }, { icon: 'WH', iconClass: 'text-white', customBg: '#0891b2', title: 'Warehouse Roofing Works', manager: 'Sara Wilson', avatar: 'https://i.pravatar.cc/40?img=45', budget: '₹78 L', progressLabel: '71%', progress: 71, status: 'On Track', statusClass: 'green', billing: '₹55.2 L' }, { icon: 'RD', iconClass: 'bg-dark text-warning', title: 'Road & Drainage Package', manager: 'David Lee', avatar: 'https://i.pravatar.cc/40?img=14', budget: '₹1.2 Cr', progressLabel: '36%', progress: 36, status: 'Planning', statusClass: 'amber', billing: '₹28.4 L' }];
    function projectIconStyle(p) { return p.customBg ? `style="background:${p.customBg};"` : '' }
    function renderProjects() { const table = document.getElementById('projectTableBody'), cards = document.getElementById('projectMobileCards'); table.innerHTML = projects.map(p => `<tr><td><div class="project-title"><div class="project-icon ${p.iconClass}" ${projectIconStyle(p)}>${p.icon}</div><span>${p.title}</span></div></td><td><div class="person"><img src="${p.avatar}" alt=""><span>${p.manager}</span></div></td><td class="fw-semibold">${p.budget}</td><td><div class="progress-cell"><span>${p.progressLabel}</span><div class="progress-track"><b class="progress-fill" style="width:${p.progress}%"></b></div></div></td><td><span class="pill ${p.statusClass}">${p.status}</span></td><td class="fw-bold">${p.billing}</td><td><div class="d-flex gap-2"><button class="action-btn"><i data-lucide="pencil"></i></button><button class="action-btn"><i data-lucide="more-vertical"></i></button></div></td></tr>`).join(''); cards.innerHTML = projects.map(p => `<article class="mobile-project-card"><div class="d-flex align-items-start justify-content-between gap-3 mb-3"><div class="project-title"><div class="project-icon ${p.iconClass}" ${projectIconStyle(p)}>${p.icon}</div><div><p class="fw-bold small mb-1 lh-base">${p.title}</p><div class="person mt-1"><img src="${p.avatar}" alt=""><span class="small">${p.manager}</span></div></div></div><span class="pill ${p.statusClass}">${p.status}</span></div><div class="mobile-info"><span>Budget</span><span>${p.budget}</span></div><div class="mobile-info"><span>Progress</span><span>${p.progressLabel}</span></div><div class="mobile-info"><span>Invoices</span><span>${p.billing}</span></div><div class="mt-3 d-flex align-items-center justify-content-between gap-3"><div class="progress-track flex-fill"><div class="progress-fill" style="width:${p.progress}%"></div></div><div class="d-flex gap-2"><button class="action-btn"><i data-lucide="pencil"></i></button><button class="action-btn"><i data-lucide="more-vertical"></i></button></div></div></article>`).join('') }
    function closeDropdowns(exceptId = null) { document.querySelectorAll('.dropdown-menu-custom').forEach(m => { if (m.id !== exceptId) m.classList.remove('show') }) }
    document.addEventListener('DOMContentLoaded', () => {
      renderProjects(); lucide.createIcons(); document.getElementById('currentYear').textContent = new Date().getFullYear(); const body = document.body, html = document.documentElement, sidebarToggle = document.getElementById('sidebarToggle'), mobileOverlay = document.getElementById('mobileOverlay'), closeMobileSidebar = document.getElementById('closeMobileSidebar'), themeToggle = document.getElementById('themeToggle'), themeIcon = document.getElementById('themeIcon'); sidebarToggle.addEventListener('click', () => { if (window.innerWidth < 1280) { body.classList.toggle('mobile-sidebar-open'); mobileOverlay.classList.toggle('d-none', !body.classList.contains('mobile-sidebar-open')) } else { body.classList.toggle('sidebar-collapsed'); localStorage.setItem('tekc-sidebar-collapsed', body.classList.contains('sidebar-collapsed') ? 'true' : 'false') } });[mobileOverlay, closeMobileSidebar].forEach(el => el.addEventListener('click', () => { body.classList.remove('mobile-sidebar-open'); mobileOverlay.classList.add('d-none') })); document.querySelectorAll('.dropdown-btn').forEach(btn => btn.addEventListener('click', e => { e.stopPropagation(); const id = btn.getAttribute('data-dropdown-target'), menu = document.getElementById(id), willOpen = !menu.classList.contains('show'); closeDropdowns(id); menu.classList.toggle('show', willOpen) })); document.querySelectorAll('.dropdown-menu-custom').forEach(menu => menu.addEventListener('click', e => e.stopPropagation())); document.addEventListener('click', () => closeDropdowns()); document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeDropdowns(); body.classList.remove('mobile-sidebar-open'); mobileOverlay.classList.add('d-none') } }); document.querySelectorAll('.date-option').forEach(option => option.addEventListener('click', () => { document.getElementById('selectedDateLabel').textContent = option.dataset.label; closeDropdowns() }));
      const settingsToggle = document.getElementById('settingsToggle'), settingsClose = document.getElementById('settingsClose'), settingsOverlay = document.getElementById('settingsOverlay'), sidebarColorPicker = document.getElementById('sidebarColorPicker'), topbarColorPicker = document.getElementById('topbarColorPicker'), primaryColorPicker = document.getElementById('primaryColorPicker'), secondaryColorPicker = document.getElementById('secondaryColorPicker'), sidebarTextSelect = document.getElementById('sidebarTextSelect'), densitySelect = document.getElementById('densitySelect'), resetCustomization = document.getElementById('resetCustomization'), rootStyle = document.documentElement.style, customizationKey = 'tekc-customization', defaultCustomization = { sidebarBg: '#ffffff', topbarBg: '#ffffff', brand1: '#0f766e', brand2: '#2563eb', sidebarText: '#334155', density: 'comfortable' }; function hexToRgba(hex, opacity = .95) { const v = hex.replace('#', ''), r = parseInt(v.slice(0, 2), 16), g = parseInt(v.slice(2, 4), 16), b = parseInt(v.slice(4, 6), 16); return `rgba(${r}, ${g}, ${b}, ${opacity})` } function applyCustomization(s) { const isDark = html.classList.contains('dark'); rootStyle.setProperty('--sidebar-bg', isDark ? '#0f172a' : s.sidebarBg); rootStyle.setProperty('--topbar-bg', isDark ? 'rgba(15, 23, 42, 0.95)' : hexToRgba(s.topbarBg)); rootStyle.setProperty('--sidebar-text', isDark ? '#cbd5e1' : s.sidebarText); rootStyle.setProperty('--brand-1', s.brand1); rootStyle.setProperty('--brand-2', s.brand2); body.classList.toggle('compact-layout', s.density === 'compact'); sidebarColorPicker.value = s.sidebarBg; topbarColorPicker.value = s.topbarBg; primaryColorPicker.value = s.brand1; secondaryColorPicker.value = s.brand2; sidebarTextSelect.value = s.sidebarText; densitySelect.value = s.density;[sidebarColorPicker, topbarColorPicker, sidebarTextSelect].forEach(c => { c.disabled = isDark; c.classList.toggle('opacity-50', isDark) }) } function saveCustomization() { const s = { sidebarBg: sidebarColorPicker.value, topbarBg: topbarColorPicker.value, brand1: primaryColorPicker.value, brand2: secondaryColorPicker.value, sidebarText: sidebarTextSelect.value, density: densitySelect.value }; localStorage.setItem(customizationKey, JSON.stringify(s)); applyCustomization(s) } function openSettingsPanel() { body.classList.add('settings-panel-open'); closeDropdowns() } function closeSettingsPanel() { body.classList.remove('settings-panel-open') } settingsToggle.addEventListener('click', openSettingsPanel); settingsClose.addEventListener('click', closeSettingsPanel); settingsOverlay.addEventListener('click', closeSettingsPanel);[sidebarColorPicker, topbarColorPicker, primaryColorPicker, secondaryColorPicker, sidebarTextSelect, densitySelect].forEach(c => { c.addEventListener('input', saveCustomization); c.addEventListener('change', saveCustomization) }); resetCustomization.addEventListener('click', () => { localStorage.removeItem(customizationKey); applyCustomization(defaultCustomization) }); const savedCustomization = JSON.parse(localStorage.getItem(customizationKey) || 'null'), savedSidebar = localStorage.getItem('tekc-sidebar-collapsed'); if (savedSidebar === 'true' && window.innerWidth >= 1280) body.classList.add('sidebar-collapsed'); const savedTheme = localStorage.getItem('tekc-theme'); if (savedTheme === 'dark') html.classList.add('dark'); if (savedTheme === 'light') html.classList.remove('dark'); applyCustomization(savedCustomization || defaultCustomization); updateThemeIcon(); themeToggle.addEventListener('click', () => { html.classList.toggle('dark'); localStorage.setItem('tekc-theme', html.classList.contains('dark') ? 'dark' : 'light'); applyCustomization(JSON.parse(localStorage.getItem(customizationKey) || 'null') || defaultCustomization); updateThemeIcon() }); function updateThemeIcon() { const isDark = html.classList.contains('dark'); themeIcon.setAttribute('data-lucide', isDark ? 'sun' : 'moon'); lucide.createIcons() }
    });
  </script>
</body>

</html>