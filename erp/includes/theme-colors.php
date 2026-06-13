<?php
require_once __DIR__ . "/db.php";

$defaults = [
    "sidebar_bg" => "#ffffff",
    "sidebar_text" => "#334155",
    "sidebar_active_bg_1" => "#0f766e",
    "sidebar_active_bg_2" => "#2563eb",
    "sidebar_active_text" => "#ffffff",
    "sidebar_hover_bg" => "rgba(148, 163, 184, .12)",
    "sidebar_hover_text" => "#334155",
    "topbar_bg" => "#ffffff",
    "body_bg" => "#f7f9fc",
    "card_bg" => "#ffffff",
    "text_main" => "#0f172a",
    "text_muted" => "#64748b",
    "border_soft" => "#e2e8f0",
    "brand_1" => "#0f766e",
    "brand_2" => "#2563eb"
];

$colors = $defaults;

$result = mysqli_query($conn, "SELECT setting_key, setting_value FROM website_color_settings WHERE is_active = 1");

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        if (array_key_exists($row["setting_key"], $colors)) {
            $colors[$row["setting_key"]] = $row["setting_value"];
        }
    }
}

function esc_color($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}
?>

<style>
:root {
  --sidebar-bg: <?= esc_color($colors["sidebar_bg"]) ?>;
  --sidebar-text: <?= esc_color($colors["sidebar_text"]) ?>;

  --sidebar-active-bg-1: <?= esc_color($colors["sidebar_active_bg_1"]) ?>;
  --sidebar-active-bg-2: <?= esc_color($colors["sidebar_active_bg_2"]) ?>;
  --sidebar-active-text: <?= esc_color($colors["sidebar_active_text"]) ?>;

  --sidebar-hover-bg: <?= esc_color($colors["sidebar_hover_bg"]) ?>;
  --sidebar-hover-text: <?= esc_color($colors["sidebar_hover_text"]) ?>;

  --topbar-bg: <?= esc_color($colors["topbar_bg"]) ?>;
  --body-bg: <?= esc_color($colors["body_bg"]) ?>;
  --card-bg: <?= esc_color($colors["card_bg"]) ?>;

  --text-main: <?= esc_color($colors["text_main"]) ?>;
  --text-muted: <?= esc_color($colors["text_muted"]) ?>;
  --border-soft: <?= esc_color($colors["border_soft"]) ?>;

  --brand-1: <?= esc_color($colors["brand_1"]) ?>;
  --brand-2: <?= esc_color($colors["brand_2"]) ?>;
}
</style>