<?php
session_start();
require_once __DIR__ . "/includes/db.php";
function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Master data saved successfully.";
} elseif (isset($_GET["status"])) {
    $pageMessageType = "success";
    $pageMessageText = "Master status changed successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}
$masters = [
    'departments' => ['title' => 'Departments', 'table' => 'master_departments', 'name' => 'department_name', 'code' => 'department_code', 'icon' => 'building'],
    'client_types' => ['title' => 'Client Types', 'table' => 'master_client_types', 'name' => 'client_type_name', 'code' => 'client_type_code', 'icon' => 'contact'],
    'project_types' => ['title' => 'Project Types', 'table' => 'master_project_types', 'name' => 'project_type_name', 'code' => 'project_type_code', 'icon' => 'layers'],
    'project_statuses' => ['title' => 'Project Statuses', 'table' => 'master_project_statuses', 'name' => 'status_name', 'code' => 'status_code', 'icon' => 'list-checks'],
    'notification_reasons' => ['title' => 'Notification Reasons', 'table' => 'master_notification_reasons', 'name' => 'reason_name', 'code' => 'reason_code', 'icon' => 'bell-ring'],
    'assignment_roles' => ['title' => 'Project Assignment Roles', 'table' => 'project_assignment_roles', 'name' => 'role_name', 'code' => 'role_key', 'icon' => 'user-check']
];
$type = $_GET['type'] ?? 'departments';
if (!isset($masters[$type]))
    $type = 'departments';
$config = $masters[$type];
$table = $config['table'];
$nameCol = $config['name'];
$codeCol = $config['code'];
$list = mysqli_query($conn, "SELECT * FROM `$table` ORDER BY id DESC");
$rows = [];
while ($r = mysqli_fetch_assoc($list)) {
    $rows[] = $r;
}
$stats = ['total' => count($rows), 'active' => 0, 'inactive' => 0];
foreach ($rows as $r) {
    if ($type === 'assignment_roles') {
        !empty($r['is_fixed']) ? $stats['active']++ : $stats['inactive']++;
    } else {
        !empty($r['is_active']) ? $stats['active']++ : $stats['inactive']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= e($config['title']) ?> - TEK-C PMC Construction</title><?php include("includes/links.php"); ?>
    <style>
        .page-head-card,
        .master-tab-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px
        }

        .master-tab-card {
            padding: 10px
        }

        .kpi-row>[class*="col"] {
            display: flex;
        }

        .kpi-card {
            width: 100%;
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

        .kpi-icon {
            width: 58px;
            height: 58px;
            min-width: 58px;
            border-radius: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .kpi-label {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .kpi-value {
            color: var(--text-main);
            font-size: 24px;
            font-weight: 900;
            margin: 4px 0 2px;
            line-height: 1.15;
        }

        .kpi-sub {
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
            margin: 0;
        }

        .kpi-sub span {
            color: #008a5b;
            font-weight: 900;
        }

        .modal-content {
            background: var(--card-bg);
            color: var(--text-main);
            border: 1px solid var(--border-soft);
            border-radius: 24px;
            box-shadow: var(--shadow-card)
        }

        .modal-header,
        .modal-footer {
            border-color: var(--border-soft)
        }

        .form-control,
        .form-select {
            background: var(--card-bg);
            color: var(--text-main);
            border-color: var(--border-soft);
            min-height: 42px;
            font-size: 13px;
            font-weight: 600
        }

        .form-control:focus,
        .form-select:focus {
            color: var(--text-main);
            background: var(--card-bg);
            border-color: var(--brand-2);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, .12)
        }

        .master-type-btn {
            border-radius: 16px !important;
            font-size: 12px;
            font-weight: 800;
            display: inline-flex;
            gap: 6px;
            align-items: center
        }

        .master-type-btn svg {
            width: 14px;
            height: 14px
        }
    </style>
</head>

<body>
    <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none">
    </div>

    <?php include("includes/page-message.php"); ?>
    <div class="min-vh-100 d-flex"><?php include("includes/sidebar.php"); ?>
        <main id="main"><?php include("includes/nav.php"); ?>
            <section class="page-section p-3 p-lg-3">
                <div class="page-head-card mb-3">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                        <div>
                            <h1 class="h4 fw-bold mb-1">Master Control</h1>
                            <p class="text-muted-custom mb-0 small">Manage reusable dynamic values used across clients,
                                projects, employees, and notifications.</p>
                        </div><button type="button" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3"
                            data-bs-toggle="modal" data-bs-target="#masterModal" onclick="openAddMasterModal()"><i
                                data-lucide="plus" style="width:15px;height:15px;"></i> Add
                            <?= e($config['title']) ?></button>
                    </div>
                </div>

                <div class="master-tab-card mb-3">
                    <div class="d-flex flex-wrap gap-2"><?php foreach ($masters as $key => $m): ?><a
                                href="master-control.php?type=<?= e($key) ?>"
                                class="btn btn-sm master-type-btn <?= $type === $key ? 'brand-gradient text-white' : 'btn-outline-secondary' ?>"><i
                                    data-lucide="<?= e($m['icon']) ?>"></i><?= e($m['title']) ?></a><?php endforeach; ?>
                    </div>
                </div>
                <div class="row g-3 mb-3 kpi-row">
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white"
                                style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                                <i data-lucide="<?= e($config['icon']) ?>" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Total <?= e($config['title']) ?> <i data-lucide="info"
                                        style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int) $stats['total'] ?></p>
                                <p class="kpi-sub"><span>↑ <?= (int) $stats['total'] ?></span> records created</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-success-subtle text-success">
                                <i data-lucide="<?= $type === 'assignment_roles' ? 'lock-keyhole' : 'check-circle-2' ?>"
                                    style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">
                                    <?= $type === 'assignment_roles' ? 'Fixed Roles' : 'Active Records' ?> <i
                                        data-lucide="info" style="width:12px;height:12px;"></i>
                                </div>
                                <p class="kpi-value"><?= (int) $stats['active'] ?></p>
                                <p class="kpi-sub"><span>↑ <?= (int) $stats['active'] ?></span>
                                    <?= $type === 'assignment_roles' ? 'fixed items' : 'enabled items' ?></p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white"
                                style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                                <i data-lucide="<?= $type === 'assignment_roles' ? 'settings-2' : 'pause-circle' ?>"
                                    style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">
                                    <?= $type === 'assignment_roles' ? 'Custom Roles' : 'Inactive Records' ?> <i
                                        data-lucide="info" style="width:12px;height:12px;"></i>
                                </div>
                                <p class="kpi-value"><?= (int) $stats['inactive'] ?></p>
                                <p class="kpi-sub"><span><?= (int) $stats['inactive'] ?></span>
                                    <?= $type === 'assignment_roles' ? 'custom items' : 'disabled items' ?></p>
                            </div>
                        </article>
                    </div>
                </div>
                <section class="card-ui overflow-hidden">
                    <div
                        class="p-3 p-lg-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-3">
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">Manage <?= e($config['title']) ?></h2>
                            <p class="text-muted-custom small mb-0">Add and edit records using Bootstrap modal forms.
                            </p>
                        </div>
                    </div>
                    <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                        <table class="project-table w-100">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Code</th>
                                    <th>Details</th>
                                    <th>Status</th>
                                    <th style="width:180px;">Action</th>
                                </tr>
                            </thead>
                            <tbody><?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td class="fw-bold"><?= e($row[$nameCol] ?? '') ?></td>
                                        <td><?= e($row[$codeCol] ?? '') ?></td>
                                        <td><?php if ($type === 'project_statuses'): ?><span
                                                    class="pill green"><?= e($row['badge_class'] ?? '-') ?></span><small
                                                    class="text-muted-custom ms-2"><?= e($row['color_code'] ?? '') ?></small><?php elseif ($type === 'notification_reasons'): ?><span
                                                    class="pill green"><?= e($row['default_icon'] ?? 'bell') ?></span><small
                                                    class="text-muted-custom ms-2"><?= e($row['default_color'] ?? '') ?></small><?php elseif ($type === 'assignment_roles'): ?><span
                                                    class="pill green">Sort
                                                    <?= e($row['sort_order'] ?? '0') ?></span><?php else: ?><small
                                                    class="text-muted-custom"><?= e($row['description'] ?? '-') ?></small><?php endif; ?>
                                        </td>
                                        <td><?php if ($type === 'assignment_roles'): ?><span
                                                    class="pill <?= !empty($row['is_fixed']) ? 'green' : 'amber' ?>"><?= !empty($row['is_fixed']) ? 'Fixed' : 'Custom' ?></span><?php else: ?><span
                                                    class="pill <?= !empty($row['is_active']) ? 'green' : 'amber' ?>"><?= !empty($row['is_active']) ? 'Active' : 'Inactive' ?></span><?php endif; ?>
                                        </td>
                                        <td><button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                                data-bs-toggle="modal" data-bs-target="#masterModal"
                                                onclick='openEditMasterModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button><?php if ($type !== 'assignment_roles'): ?>
                                                <a href="api/toggle-master.php?type=<?= e($type) ?>&id=<?= (int) $row['id'] ?>"
                                                    onclick="return confirm('Change status?')"
                                                    class="btn btn-sm btn-outline-warning rounded-4 fw-bold">Toggle</a><?php endif; ?>
                                        </td>
                                    </tr><?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3"><?php foreach ($rows as $row): ?>
                            <article class="mobile-project-card">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                    <div>
                                        <p class="fw-bold small mb-1"><?= e($row[$nameCol] ?? '') ?></p><small
                                            class="text-muted-custom"><?= e($row[$codeCol] ?? '') ?></small>
                                    </div><?php if ($type === 'assignment_roles'): ?><span
                                            class="pill <?= !empty($row['is_fixed']) ? 'green' : 'amber' ?>"><?= !empty($row['is_fixed']) ? 'Fixed' : 'Custom' ?></span><?php else: ?><span
                                            class="pill <?= !empty($row['is_active']) ? 'green' : 'amber' ?>"><?= !empty($row['is_active']) ? 'Active' : 'Inactive' ?></span><?php endif; ?>
                                </div>
                                <div class="mt-3 d-flex gap-2"><button type="button"
                                        class="btn btn-sm btn-outline-primary rounded-4 fw-bold" data-bs-toggle="modal"
                                        data-bs-target="#masterModal"
                                        onclick='openEditMasterModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button><?php if ($type !== 'assignment_roles'): ?><a
                                            href="api/toggle-master.php?type=<?= e($type) ?>&id=<?= (int) $row['id'] ?>"
                                            onclick="return confirm('Change status?')"
                                            class="btn btn-sm btn-outline-warning rounded-4 fw-bold">Toggle</a><?php endif; ?>
                                </div>
                            </article><?php endforeach; ?>
                    </div>
                </section>
                <?php include("includes/footer.php"); ?>
            </section>
        </main>
        <div id="settingsOverlay"></div><?php include("includes/rightsidbar.php"); ?>
    </div>
    <div class="modal fade" id="masterModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form action="api/process-master.php" method="POST" class="modal-content"><input type="hidden" name="type"
                    value="<?= e($type) ?>"><input type="hidden" name="id" id="master_id">
                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold" id="masterModalTitle">Add <?= e($config['title']) ?></h5>
                        <p class="text-muted-custom small mb-0">This value will be available dynamically in related
                            modules.</p>
                    </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4">
                    <div class="mb-3"><label class="form-label fw-bold small">Name</label><input type="text" name="name"
                            id="master_name" class="form-control rounded-4" required></div>
                    <div class="mb-3"><label class="form-label fw-bold small">Code</label><input type="text" name="code"
                            id="master_code" class="form-control rounded-4" required></div>
                    <?php if ($type !== 'assignment_roles'): ?>
                        <div class="mb-3"><label class="form-label fw-bold small">Description</label><textarea
                                name="description" id="description" class="form-control rounded-4" rows="3"></textarea>
                        </div><?php endif; ?><?php if ($type === 'project_statuses'): ?>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-bold small">Badge Class</label><input type="text"
                                    name="badge_class" id="badge_class" class="form-control rounded-4"></div>
                            <div class="col-6"><label class="form-label fw-bold small">Color</label><input type="color"
                                    name="color_code" id="color_code"
                                    class="form-control form-control-color w-100 rounded-4" value="#2563eb"></div>
                            <div class="col-6"><label class="form-label fw-bold small">Sort Order</label><input
                                    type="number" name="sort_order" id="sort_order" class="form-control rounded-4"
                                    value="0"></div>
                            <div class="col-6"><label class="form-label fw-bold small">Default?</label><select
                                    name="is_default" id="is_default" class="form-select rounded-4">
                                    <option value="0">No</option>
                                    <option value="1">Yes</option>
                                </select></div>
                        </div><?php endif; ?><?php if ($type === 'notification_reasons'): ?>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-bold small">Icon</label><input type="text"
                                    name="default_icon" id="default_icon" class="form-control rounded-4" value="bell"></div>
                            <div class="col-6"><label class="form-label fw-bold small">Color</label><input type="color"
                                    name="default_color" id="default_color"
                                    class="form-control form-control-color w-100 rounded-4" value="#2563eb"></div>
                        </div><?php endif; ?><?php if ($type === 'assignment_roles'): ?>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-bold small">Fixed?</label><select name="is_fixed"
                                    id="is_fixed" class="form-select rounded-4">
                                    <option value="1">Yes</option>
                                    <option value="0">No</option>
                                </select></div>
                            <div class="col-6"><label class="form-label fw-bold small">Sort Order</label><input
                                    type="number" name="sort_order" id="assignment_sort_order"
                                    class="form-control rounded-4" value="0"></div>
                        </div><?php endif; ?><?php if ($type !== 'assignment_roles'): ?>
                        <div class="mb-2"><label class="form-label fw-bold small">Status</label><select name="is_active"
                                id="is_active" class="form-select rounded-4">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select></div><?php endif; ?>
                </div>
                <div class="modal-footer px-4"><button type="button" class="btn btn-outline-secondary rounded-4 fw-bold"
                        data-bs-dismiss="modal">Cancel</button><button type="submit"
                        class="btn brand-gradient text-white rounded-4 fw-bold px-4">Save</button></div>
            </form>
        </div>
    </div>
    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=8"></script>
    <script>const nameCol = <?= json_encode($nameCol) ?>; const codeCol = <?= json_encode($codeCol) ?>; function openAddMasterModal() { document.getElementById('masterModalTitle').textContent = 'Add <?= e($config['title']) ?>'; document.getElementById('master_id').value = ''; document.getElementById('master_name').value = ''; document.getElementById('master_code').value = '';['description', 'badge_class', 'default_icon'].forEach(id => { const el = document.getElementById(id); if (el) el.value = (id === 'default_icon' ? 'bell' : '') });['color_code', 'default_color'].forEach(id => { const el = document.getElementById(id); if (el) el.value = '#2563eb' });['sort_order', 'assignment_sort_order'].forEach(id => { const el = document.getElementById(id); if (el) el.value = '0' }); const isDefault = document.getElementById('is_default'); if (isDefault) isDefault.value = '0'; const isFixed = document.getElementById('is_fixed'); if (isFixed) isFixed.value = '1'; const isActive = document.getElementById('is_active'); if (isActive) isActive.value = '1' } function openEditMasterModal(row) { document.getElementById('masterModalTitle').textContent = 'Edit <?= e($config['title']) ?>'; document.getElementById('master_id').value = row.id || ''; document.getElementById('master_name').value = row[nameCol] || ''; document.getElementById('master_code').value = row[codeCol] || ''; const desc = document.getElementById('description'); if (desc) desc.value = row.description || ''; const isActive = document.getElementById('is_active'); if (isActive) isActive.value = row.is_active || '1'; const badge = document.getElementById('badge_class'); if (badge) badge.value = row.badge_class || ''; const color = document.getElementById('color_code'); if (color) color.value = row.color_code || '#2563eb'; const sort = document.getElementById('sort_order'); if (sort) sort.value = row.sort_order || '0'; const def = document.getElementById('is_default'); if (def) def.value = row.is_default || '0'; const icon = document.getElementById('default_icon'); if (icon) icon.value = row.default_icon || 'bell'; const dcolor = document.getElementById('default_color'); if (dcolor) dcolor.value = row.default_color || '#2563eb'; const fixed = document.getElementById('is_fixed'); if (fixed) fixed.value = row.is_fixed || '1'; const asort = document.getElementById('assignment_sort_order'); if (asort) asort.value = row.sort_order || '0' }
        window.addEventListener('load', function () { if (window.lucide && typeof window.lucide.createIcons === 'function') { window.lucide.createIcons() } });</script>
</body>

</html>