<?php
session_start();
require_once __DIR__ . "/includes/db.php";

$defaults = [
    "body_bg" => "#f7f9fc",
    "sidebar_bg" => "#ffffff",
    "sidebar_text" => "#334155",
    "sidebar_active_bg_1" => "#0f766e",
    "sidebar_active_bg_2" => "#2563eb",
    "sidebar_active_text" => "#ffffff",
    "sidebar_hover_bg" => "rgba(148, 163, 184, .12)",
    "sidebar_hover_text" => "#334155",
    "topbar_bg" => "#ffffff",
    "card_bg" => "#ffffff",
    "border_soft" => "#e2e8f0",
    "text_main" => "#0f172a",
    "text_muted" => "#64748b",
    "brand_1" => "#0f766e",
    "brand_2" => "#2563eb"
];

$settings = $defaults;
$result = mysqli_query($conn, "SELECT setting_key, setting_value FROM website_color_settings WHERE is_active = 1");

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        if (array_key_exists($row["setting_key"], $settings)) {
            $settings[$row["setting_key"]] = $row["setting_value"];
        }
    }
}

function val($key, $default = "") {
    global $settings;
    return htmlspecialchars($settings[$key] ?? $default, ENT_QUOTES, "UTF-8");
}

function e($v) {
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Colors updated successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Website Colors - TEK-C PMC Construction</title>
  <?php include("includes/links.php"); ?>

  <style>
    .theme-page-head {
      background: var(--card-bg);
      border: 1px solid var(--border-soft);
      border-radius: 22px;
      box-shadow: var(--shadow-card);
      padding: 16px;
    }

    .theme-stat-card {
      height: 100%;
      min-height: 118px;
      background: var(--card-bg);
      border: 1px solid var(--border-soft);
      border-radius: 24px;
      box-shadow: var(--shadow-card);
      padding: 22px 24px;
      display: flex;
      align-items: center;
      gap: 22px;
    }

    .theme-stat-icon {
      width: 58px;
      height: 58px;
      min-width: 58px;
      border-radius: 22px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .theme-stat-label {
      color: var(--text-muted);
      font-size: 13px;
      font-weight: 800;
      display: flex;
      align-items: center;
      gap: 4px;
      white-space: nowrap;
    }

    .theme-stat-value {
      color: var(--text-main);
      font-size: 20px;
      font-weight: 900;
      margin: 4px 0 2px;
      line-height: 1.15;
      word-break: break-word;
    }

    .theme-stat-sub {
      color: var(--text-muted);
      font-size: 12px;
      font-weight: 600;
      margin: 0;
    }

    .theme-stat-sub span {
      color: #008a5b;
      font-weight: 900;
    }

    .color-section-title {
      font-size: 12px;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--text-muted);
      margin: 0 0 12px;
    }

    .color-control-card {
      border: 1px solid var(--border-soft);
      background: rgba(148, 163, 184, .06);
      border-radius: 18px;
      padding: 14px;
      height: 100%;
      transition: .2s;
    }

    .color-control-card:hover {
      background: rgba(148, 163, 184, .10);
      transform: translateY(-1px);
    }

    .color-control-card label {
      font-size: 12px;
      font-weight: 800;
      color: var(--text-main);
      margin-bottom: 8px;
    }

    .color-control-card small {
      display: block;
      margin-top: 7px;
      font-size: 11px;
      color: var(--text-muted);
    }

    .color-input-row {
      display: flex;
      gap: 8px;
      align-items: center;
    }

    .color-input-row input[type="color"] {
      width: 52px !important;
      min-width: 52px;
      height: 42px;
      padding: 4px;
      border-radius: 14px;
      border: 1px solid var(--border-soft);
      background: var(--card-bg);
    }

    .color-input-row input[type="text"] {
      height: 42px;
      border-radius: 14px;
      border: 1px solid var(--border-soft);
      background: var(--card-bg);
      color: var(--text-main);
      font-size: 13px;
      font-weight: 700;
    }

    .live-preview-card {
      position: sticky;
      top: 76px;
    }

    .preview-shell {
      border: 1px solid var(--border-soft);
      background: var(--body-bg);
      border-radius: 20px;
      overflow: hidden;
      min-height: 310px;
    }

    .preview-topbar {
      height: 42px;
      background: var(--topbar-bg);
      border-bottom: 1px solid var(--border-soft);
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 0 12px;
    }

    .preview-dot {
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: var(--text-muted);
      opacity: .35;
    }

    .preview-layout {
      display: grid;
      grid-template-columns: 115px 1fr;
      min-height: 268px;
    }

    .preview-sidebar {
      background: var(--sidebar-bg);
      border-right: 1px solid var(--border-soft);
      padding: 12px 9px;
    }

    .preview-logo {
      height: 28px;
      width: 28px;
      border-radius: 10px;
      background-image: linear-gradient(135deg, var(--brand-1), var(--brand-2));
      margin-bottom: 14px;
    }

    .preview-nav-item {
      height: 28px;
      border-radius: 10px;
      color: var(--sidebar-text);
      display: flex;
      align-items: center;
      padding: 0 8px;
      font-size: 10px;
      font-weight: 800;
      margin-bottom: 7px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .preview-nav-item.active {
      color: var(--sidebar-active-text);
      background-image: linear-gradient(135deg, var(--sidebar-active-bg-1), var(--sidebar-active-bg-2));
    }

    .preview-nav-item.hover {
      color: var(--sidebar-hover-text);
      background: var(--sidebar-hover-bg);
    }

    .preview-content {
      padding: 12px;
    }

    .preview-card-mini {
      background: var(--card-bg);
      border: 1px solid var(--border-soft);
      border-radius: 16px;
      padding: 12px;
      margin-bottom: 10px;
    }

    .preview-title {
      color: var(--text-main);
      font-size: 13px;
      font-weight: 900;
      margin: 0 0 4px;
    }

    .preview-muted {
      color: var(--text-muted);
      font-size: 10px;
      margin: 0;
    }

    .preview-btn {
      height: 30px;
      border-radius: 12px;
      background-image: linear-gradient(135deg, var(--brand-1), var(--brand-2));
      color: #fff;
      display: inline-flex;
      align-items: center;
      padding: 0 12px;
      font-size: 11px;
      font-weight: 800;
      margin-top: 8px;
    }

    @media(max-width: 1199px) {
      .live-preview-card {
        position: static;
      }
    }
  </style>
</head>

<body>
  <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>

  <?php include("includes/page-message.php"); ?>

  <div class="min-vh-100 d-flex">
    <?php include("includes/sidebar.php"); ?>

    <main id="main">
      <?php include("includes/nav.php"); ?>

      <section class="page-section p-3 p-lg-3">
        <div class="theme-page-head mb-3">
          <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
          <div>
            <h1 class="h4 fw-bold mb-1">Website Colors</h1>
            <p class="text-muted-custom mb-0 small">Update admin panel theme colors. Changes preview live before saving.</p>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <button type="button" id="resetPreviewBtn" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm px-3">Reset Preview</button>
            <button type="submit" form="websiteColorsForm" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3">Save Colors</button>
          </div>
          </div>
        </div>

        <div class="row g-3 mb-3 kpi-row">
          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="theme-stat-card">
              <div class="theme-stat-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                <i data-lucide="panel-left" style="width:24px;height:24px;"></i>
              </div>
              <div>
                <div class="theme-stat-label">Sidebar <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="theme-stat-value"><?= val("sidebar_bg", $defaults["sidebar_bg"]) ?></p>
                <p class="theme-stat-sub"><span>Active</span> sidebar background</p>
              </div>
            </article>
          </div>

          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="theme-stat-card">
              <div class="theme-stat-icon bg-success-subtle text-success">
                <i data-lucide="palette" style="width:24px;height:24px;"></i>
              </div>
              <div>
                <div class="theme-stat-label">Brand Primary <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="theme-stat-value"><?= val("brand_1", $defaults["brand_1"]) ?></p>
                <p class="theme-stat-sub"><span>Live</span> primary color</p>
              </div>
            </article>
          </div>

          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="theme-stat-card">
              <div class="theme-stat-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                <i data-lucide="layout-template" style="width:24px;height:24px;"></i>
              </div>
              <div>
                <div class="theme-stat-label">Body BG <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="theme-stat-value"><?= val("body_bg", $defaults["body_bg"]) ?></p>
                <p class="theme-stat-sub"><span>Page</span> background</p>
              </div>
            </article>
          </div>

          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="theme-stat-card">
              <div class="theme-stat-icon bg-warning-subtle text-warning">
                <i data-lucide="type" style="width:24px;height:24px;"></i>
              </div>
              <div>
                <div class="theme-stat-label">Text Main <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="theme-stat-value"><?= val("text_main", $defaults["text_main"]) ?></p>
                <p class="theme-stat-sub"><span>UI</span> main text</p>
              </div>
            </article>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-12 col-xl-8">
            <form id="websiteColorsForm" action="api/process-website-colors.php" method="POST" class="card-ui p-3 p-lg-4">
              <p class="color-section-title">Layout Colors</p>
              <div class="row g-3 mb-4">
                <div class="col-md-6 col-lg-4">
                  <div class="color-control-card">
                    <label>Body Background</label>
                    <div class="color-input-row">
                      <input type="color" name="body_bg" value="<?= val("body_bg", $defaults["body_bg"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="body_bg" value="<?= val("body_bg", $defaults["body_bg"]) ?>">
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-lg-4">
                  <div class="color-control-card">
                    <label>Topbar Background</label>
                    <div class="color-input-row">
                      <input type="color" name="topbar_bg" value="<?= val("topbar_bg", $defaults["topbar_bg"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="topbar_bg" value="<?= val("topbar_bg", $defaults["topbar_bg"]) ?>">
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-lg-4">
                  <div class="color-control-card">
                    <label>Card Background</label>
                    <div class="color-input-row">
                      <input type="color" name="card_bg" value="<?= val("card_bg", $defaults["card_bg"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="card_bg" value="<?= val("card_bg", $defaults["card_bg"]) ?>">
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-lg-4">
                  <div class="color-control-card">
                    <label>Border Color</label>
                    <div class="color-input-row">
                      <input type="color" name="border_soft" value="<?= val("border_soft", $defaults["border_soft"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="border_soft" value="<?= val("border_soft", $defaults["border_soft"]) ?>">
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-lg-4">
                  <div class="color-control-card">
                    <label>Main Text Color</label>
                    <div class="color-input-row">
                      <input type="color" name="text_main" value="<?= val("text_main", $defaults["text_main"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="text_main" value="<?= val("text_main", $defaults["text_main"]) ?>">
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-lg-4">
                  <div class="color-control-card">
                    <label>Muted Text Color</label>
                    <div class="color-input-row">
                      <input type="color" name="text_muted" value="<?= val("text_muted", $defaults["text_muted"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="text_muted" value="<?= val("text_muted", $defaults["text_muted"]) ?>">
                    </div>
                  </div>
                </div>
              </div>

              <p class="color-section-title">Sidebar Colors</p>
              <div class="row g-3 mb-4">
                <div class="col-md-6 col-lg-4">
                  <div class="color-control-card">
                    <label>Sidebar Background</label>
                    <div class="color-input-row">
                      <input type="color" name="sidebar_bg" value="<?= val("sidebar_bg", $defaults["sidebar_bg"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="sidebar_bg" value="<?= val("sidebar_bg", $defaults["sidebar_bg"]) ?>">
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-lg-4">
                  <div class="color-control-card">
                    <label>Sidebar Text</label>
                    <div class="color-input-row">
                      <input type="color" name="sidebar_text" value="<?= val("sidebar_text", $defaults["sidebar_text"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="sidebar_text" value="<?= val("sidebar_text", $defaults["sidebar_text"]) ?>">
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-lg-4">
                  <div class="color-control-card">
                    <label>Active Text</label>
                    <div class="color-input-row">
                      <input type="color" name="sidebar_active_text" value="<?= val("sidebar_active_text", $defaults["sidebar_active_text"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="sidebar_active_text" value="<?= val("sidebar_active_text", $defaults["sidebar_active_text"]) ?>">
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-lg-4">
                  <div class="color-control-card">
                    <label>Active BG Start</label>
                    <div class="color-input-row">
                      <input type="color" name="sidebar_active_bg_1" value="<?= val("sidebar_active_bg_1", $defaults["sidebar_active_bg_1"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="sidebar_active_bg_1" value="<?= val("sidebar_active_bg_1", $defaults["sidebar_active_bg_1"]) ?>">
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-lg-4">
                  <div class="color-control-card">
                    <label>Active BG End</label>
                    <div class="color-input-row">
                      <input type="color" name="sidebar_active_bg_2" value="<?= val("sidebar_active_bg_2", $defaults["sidebar_active_bg_2"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="sidebar_active_bg_2" value="<?= val("sidebar_active_bg_2", $defaults["sidebar_active_bg_2"]) ?>">
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-lg-4">
                  <div class="color-control-card">
                    <label>Hover Text</label>
                    <div class="color-input-row">
                      <input type="color" name="sidebar_hover_text" value="<?= val("sidebar_hover_text", $defaults["sidebar_hover_text"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="sidebar_hover_text" value="<?= val("sidebar_hover_text", $defaults["sidebar_hover_text"]) ?>">
                    </div>
                  </div>
                </div>

                <div class="col-12">
                  <div class="color-control-card">
                    <label>Hover Background</label>
                    <input type="text" name="sidebar_hover_bg" class="form-control rounded-4 live-any-color" value="<?= val("sidebar_hover_bg", $defaults["sidebar_hover_bg"]) ?>">
                    <small>Use hex or rgba. Example: #eef2ff or rgba(148, 163, 184, .12)</small>
                  </div>
                </div>
              </div>

              <p class="color-section-title">Brand Colors</p>
              <div class="row g-3">
                <div class="col-md-6">
                  <div class="color-control-card">
                    <label>Primary Brand Color</label>
                    <div class="color-input-row">
                      <input type="color" name="brand_1" value="<?= val("brand_1", $defaults["brand_1"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="brand_1" value="<?= val("brand_1", $defaults["brand_1"]) ?>">
                    </div>
                  </div>
                </div>

                <div class="col-md-6">
                  <div class="color-control-card">
                    <label>Secondary Brand Color</label>
                    <div class="color-input-row">
                      <input type="color" name="brand_2" value="<?= val("brand_2", $defaults["brand_2"]) ?>">
                      <input type="text" class="form-control live-color-text" data-color-name="brand_2" value="<?= val("brand_2", $defaults["brand_2"]) ?>">
                    </div>
                  </div>
                </div>
              </div>

              <div class="mt-4 d-flex flex-column flex-sm-row gap-2 justify-content-end">
                <button type="button" id="resetPreviewBtnBottom" class="btn btn-outline-secondary rounded-4 fw-bold px-4">Reset Preview</button>
                <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Save Colors</button>
              </div>
            </form>
          </div>

          <div class="col-12 col-xl-4">
            <section class="card-ui p-3 p-lg-4 live-preview-card">
              <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                <div>
                  <h2 class="fw-bold fs-6 mb-1">Live Preview</h2>
                  <p class="text-muted-custom small mb-0">This preview updates instantly.</p>
                </div>
                <span class="pill green">Live</span>
              </div>

              <div class="preview-shell">
                <div class="preview-topbar">
                  <span class="preview-dot"></span>
                  <span class="preview-dot"></span>
                  <span class="preview-dot"></span>
                </div>
                <div class="preview-layout">
                  <div class="preview-sidebar">
                    <div class="preview-logo"></div>
                    <div class="preview-nav-item active">Dashboard</div>
                    <div class="preview-nav-item hover">Projects</div>
                    <div class="preview-nav-item">Clients</div>
                    <div class="preview-nav-item">Reports</div>
                  </div>
                  <div class="preview-content">
                    <div class="preview-card-mini">
                      <p class="preview-title">Dashboard Card</p>
                      <p class="preview-muted">Main text, muted text, card and border colors.</p>
                      <span class="preview-btn">Brand Button</span>
                    </div>
                    <div class="preview-card-mini">
                      <p class="preview-title">Sidebar State</p>
                      <p class="preview-muted">Active, hover and default sidebar colors.</p>
                    </div>
                  </div>
                </div>
              </div>
            </section>
          </div>
        </div>

        <?php include("includes/footer.php"); ?>
      </section>
    </main>

    <div id="settingsOverlay"></div>
    <?php include("includes/rightsidbar.php"); ?>
  </div>
  <?php include("includes/script.php") ?>
  <script src="assets/js/script.js?v=8"></script>
  <script>
    document.addEventListener("DOMContentLoaded", function () {
      const root = document.documentElement;
      const defaults = <?= json_encode($defaults, JSON_UNESCAPED_SLASHES) ?>;

      const colorMap = {
        body_bg: "--body-bg",
        sidebar_bg: "--sidebar-bg",
        sidebar_text: "--sidebar-text",
        sidebar_active_bg_1: "--sidebar-active-bg-1",
        sidebar_active_bg_2: "--sidebar-active-bg-2",
        sidebar_active_text: "--sidebar-active-text",
        sidebar_hover_bg: "--sidebar-hover-bg",
        sidebar_hover_text: "--sidebar-hover-text",
        topbar_bg: "--topbar-bg",
        card_bg: "--card-bg",
        border_soft: "--border-soft",
        text_main: "--text-main",
        text_muted: "--text-muted",
        brand_1: "--brand-1",
        brand_2: "--brand-2"
      };

      function isHex(value) {
        return /^#[0-9A-Fa-f]{6}$/.test(value);
      }

      function applyLiveColor(name, value) {
        if (!colorMap[name] || !value) return;
        root.style.setProperty(colorMap[name], value);
      }

      function syncTextInput(name, value) {
        const textInput = document.querySelector('.live-color-text[data-color-name="' + name + '"]');
        if (textInput) textInput.value = value;
      }

      function syncColorInput(name, value) {
        const colorInput = document.querySelector('input[type="color"][name="' + name + '"]');
        if (colorInput && isHex(value)) colorInput.value = value;
      }

      document.querySelectorAll('input[type="color"][name]').forEach(function (input) {
        input.addEventListener("input", function () {
          applyLiveColor(input.name, input.value);
          syncTextInput(input.name, input.value);
        });

        input.addEventListener("change", function () {
          applyLiveColor(input.name, input.value);
          syncTextInput(input.name, input.value);
        });
      });

      document.querySelectorAll(".live-color-text").forEach(function (input) {
        input.addEventListener("input", function () {
          const name = input.dataset.colorName;
          const value = input.value.trim();
          applyLiveColor(name, value);
          syncColorInput(name, value);
        });
      });

      document.querySelectorAll(".live-any-color").forEach(function (input) {
        input.addEventListener("input", function () {
          applyLiveColor(input.name, input.value.trim());
        });
      });

      function resetPreview() {
        Object.keys(defaults).forEach(function (name) {
          applyLiveColor(name, defaults[name]);
          syncTextInput(name, defaults[name]);
          syncColorInput(name, defaults[name]);

          const anyInput = document.querySelector('.live-any-color[name="' + name + '"]');
          if (anyInput) anyInput.value = defaults[name];
        });
      }

      ["resetPreviewBtn", "resetPreviewBtnBottom"].forEach(function (id) {
        const btn = document.getElementById(id);
        if (btn) btn.addEventListener("click", resetPreview);
      });
    });
  </script>
  <script>
window.addEventListener("load", function () {
  if (window.lucide && typeof window.lucide.createIcons === "function") {
    window.lucide.createIcons();
  }
});
</script>
</body>

</html>
