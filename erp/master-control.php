<?php
session_start();
require_once __DIR__ . "/includes/db.php";
function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function table_has_column($conn, $table, $column)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && mysqli_num_rows($q) > 0;
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
    'divisions' => ['title' => 'Divisions', 'table' => 'company_divisions', 'name' => 'division_name', 'code' => 'division_code', 'icon' => 'network'],
    'office_locations' => ['title' => 'Office Locations', 'table' => 'master_office_locations', 'name' => 'location_name', 'code' => 'location_code', 'icon' => 'map-pin'],
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
$orderBy = table_has_column($conn, $table, "sort_order") ? "sort_order ASC, id DESC" : "id DESC";
$list = mysqli_query($conn, "SELECT * FROM `$table` ORDER BY $orderBy");
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

        .office-location-map {
            height: 280px;
            border-radius: 18px;
            border: 1px solid var(--border-soft);
            background: rgba(148, 163, 184, .10);
            overflow: hidden;
        }

        .office-location-search {
            position: relative;
        }

        .office-location-suggestions {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            z-index: 2000;
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 16px;
            box-shadow: var(--shadow-card);
            max-height: 230px;
            overflow: auto;
        }

        .office-location-suggestion {
            padding: 10px 13px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border-bottom: 1px solid var(--border-soft);
        }

        .office-location-suggestion:hover {
            background: rgba(148, 163, 184, .10);
        }

        .office-location-help {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
        }

        .office-radius-box {
            border: 1px solid var(--border-soft);
            background: rgba(245, 158, 11, .08);
            border-radius: 16px;
            padding: 10px 12px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
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
                            <p class="text-muted-custom mb-0 small">Manage reusable dynamic values used across divisions, clients,
                                projects, employees, office locations, and notifications.</p>
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
                                                    class="text-muted-custom ms-2"><?= e($row['default_color'] ?? '') ?></small><?php elseif ($type === 'office_locations'): ?><small
                                                    class="text-muted-custom d-block"><?= e($row['address'] ?? '-') ?></small><span
                                                    class="pill green"><?= !empty($row['is_head_office']) ? 'Head Office' : 'Branch' ?></span><small
                                                    class="text-muted-custom ms-2"><?= e(($row['city'] ?? '') . (($row['state'] ?? '') ? ', ' . $row['state'] : '')) ?></small><br><span
                                                    class="pill amber mt-2">Punch Radius: <?= (int)($row['location_radius'] ?? 100) ?>m</span><?php elseif ($type === 'assignment_roles'): ?><span
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
        <div class="modal-dialog modal-dialog-centered <?= $type === 'office_locations' ? 'modal-lg' : '' ?>">
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
                    <?php if ($type !== 'assignment_roles' && $type !== 'office_locations'): ?>
                        <div class="mb-3"><label class="form-label fw-bold small">Description</label><textarea
                                name="description" id="description" class="form-control rounded-4" rows="3"></textarea>
                        </div><?php endif; ?><?php if ($type === 'office_locations'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Search Office Location</label>
                            <div class="office-location-search">
                                <div class="input-group">
                                    <input type="text" id="office_location_search" class="form-control rounded-start-4"
                                        placeholder="Search office address or place">
                                    <button type="button" class="btn btn-outline-primary fw-bold"
                                        onclick="getOfficeCurrentLocation()">
                                        <i data-lucide="crosshair" style="width:15px;height:15px;"></i> Current
                                    </button>
                                </div>
                                <div id="office_location_suggestions" class="office-location-suggestions"></div>
                            </div>
                            <div class="office-location-help mt-1">Search using Google Places, click on the map, drag marker, or use current location.</div>
                        </div>

                        <div class="mb-3">
                            <div id="office_location_map" class="office-location-map"></div>
                            <div class="office-radius-box mt-2">
                                Select office location on map and set radius meter. Employees can punch in only when their current GPS is inside this circle.
                            </div>
                        </div>

                        <div class="mb-3"><label class="form-label fw-bold small">Office Address</label><textarea
                                name="address" id="address" class="form-control rounded-4" rows="3"
                                placeholder="Full office address"></textarea></div>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-bold small">City</label><input type="text"
                                    name="city" id="city" class="form-control rounded-4"></div>
                            <div class="col-6"><label class="form-label fw-bold small">State</label><input type="text"
                                    name="state" id="state" class="form-control rounded-4"></div>
                            <div class="col-6"><label class="form-label fw-bold small">Pincode</label><input type="text"
                                    name="pincode" id="pincode" class="form-control rounded-4"></div>
                            <div class="col-6"><label class="form-label fw-bold small">Employee Punch-in Radius (meters)</label><input
                                    type="number" name="location_radius" id="location_radius" class="form-control rounded-4"
                                    value="100" min="10" max="5000" step="10">
                                <small class="text-muted-custom fw-semibold">Office punch-in is allowed only inside this radius.</small>
                            </div>
                            <div class="col-6"><label class="form-label fw-bold small">Latitude</label><input type="text"
                                    name="latitude" id="latitude" class="form-control rounded-4"></div>
                            <div class="col-6"><label class="form-label fw-bold small">Longitude</label><input type="text"
                                    name="longitude" id="longitude" class="form-control rounded-4"></div>
                            <div class="col-6"><label class="form-label fw-bold small">Head Office?</label><select
                                    name="is_head_office" id="is_head_office" class="form-select rounded-4">
                                    <option value="0">No</option>
                                    <option value="1">Yes</option>
                                </select></div>
                            <div class="col-6"><label class="form-label fw-bold small">Sort Order</label><input
                                    type="number" name="office_sort_order" id="office_sort_order"
                                    class="form-control rounded-4" value="0"></div>
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
    <script>const nameCol = <?= json_encode($nameCol) ?>; const codeCol = <?= json_encode($codeCol) ?>; function openAddMasterModal() { document.getElementById('masterModalTitle').textContent = 'Add <?= e($config['title']) ?>'; document.getElementById('master_id').value = ''; document.getElementById('master_name').value = ''; document.getElementById('master_code').value = '';['description', 'badge_class', 'default_icon', 'address', 'city', 'state', 'pincode', 'latitude', 'longitude'].forEach(id => { const el = document.getElementById(id); if (el) el.value = (id === 'default_icon' ? 'bell' : '') });['color_code', 'default_color'].forEach(id => { const el = document.getElementById(id); if (el) el.value = '#2563eb' });['sort_order', 'assignment_sort_order', 'office_sort_order'].forEach(id => { const el = document.getElementById(id); if (el) el.value = '0' }); const radius = document.getElementById('location_radius'); if (radius) radius.value = '100'; const headOffice = document.getElementById('is_head_office'); if (headOffice) headOffice.value = '0'; const isDefault = document.getElementById('is_default'); if (isDefault) isDefault.value = '0'; const isFixed = document.getElementById('is_fixed'); if (isFixed) isFixed.value = '1'; const isActive = document.getElementById('is_active'); if (isActive) isActive.value = '1' } function openEditMasterModal(row) { document.getElementById('masterModalTitle').textContent = 'Edit <?= e($config['title']) ?>'; document.getElementById('master_id').value = row.id || ''; document.getElementById('master_name').value = row[nameCol] || ''; document.getElementById('master_code').value = row[codeCol] || ''; const desc = document.getElementById('description'); if (desc) desc.value = row.description || ''; const isActive = document.getElementById('is_active'); if (isActive) isActive.value = row.is_active || '1'; const badge = document.getElementById('badge_class'); if (badge) badge.value = row.badge_class || ''; const color = document.getElementById('color_code'); if (color) color.value = row.color_code || '#2563eb'; const sort = document.getElementById('sort_order'); if (sort) sort.value = row.sort_order || '0'; const def = document.getElementById('is_default'); if (def) def.value = row.is_default || '0'; const icon = document.getElementById('default_icon'); if (icon) icon.value = row.default_icon || 'bell'; const dcolor = document.getElementById('default_color'); if (dcolor) dcolor.value = row.default_color || '#2563eb'; const fixed = document.getElementById('is_fixed'); if (fixed) fixed.value = row.is_fixed || '1'; const asort = document.getElementById('assignment_sort_order'); if (asort) asort.value = row.sort_order || '0'; const address = document.getElementById('address'); if (address) address.value = row.address || ''; const city = document.getElementById('city'); if (city) city.value = row.city || ''; const state = document.getElementById('state'); if (state) state.value = row.state || ''; const pincode = document.getElementById('pincode'); if (pincode) pincode.value = row.pincode || ''; const lat = document.getElementById('latitude'); if (lat) lat.value = row.latitude || ''; const lng = document.getElementById('longitude'); if (lng) lng.value = row.longitude || ''; const radius = document.getElementById('location_radius'); if (radius) radius.value = row.location_radius || '100'; const headOffice = document.getElementById('is_head_office'); if (headOffice) headOffice.value = row.is_head_office || '0'; const osort = document.getElementById('office_sort_order'); if (osort) osort.value = row.sort_order || '0'; if (typeof initOfficeLocationMap === 'function') { setTimeout(function(){ initOfficeLocationMap(); if (row.latitude && row.longitude) { setOfficeLocationMarker(parseFloat(row.latitude), parseFloat(row.longitude), parseInt(row.location_radius || 100)); } }, 400); } }

let officeMap;
let officeMarker;
let officeCircle;
let officeGeocoder;
let officePlacesService;
let officeAutocompleteService;

function officeById(id) {
    return document.getElementById(id);
}

function initOfficeLocationMap() {
    if ('<?= e($type) ?>' !== 'office_locations') return;

    const mapEl = officeById('office_location_map');
    if (!mapEl || typeof google === 'undefined' || !google.maps) return;

    const latInput = officeById('latitude');
    const lngInput = officeById('longitude');
    const radiusInput = officeById('location_radius');

    const defaultLat = 20.5937;
    const defaultLng = 78.9629;

    const savedLat = parseFloat(latInput?.value) || defaultLat;
    const savedLng = parseFloat(lngInput?.value) || defaultLng;
    const savedRadius = parseInt(radiusInput?.value || '100', 10) || 100;

    officeGeocoder = new google.maps.Geocoder();
    officeMap = new google.maps.Map(mapEl, {
        center: { lat: savedLat, lng: savedLng },
        zoom: latInput?.value && lngInput?.value ? 16 : 5,
        mapTypeControl: true,
        streetViewControl: false,
        fullscreenControl: true,
        mapTypeId: google.maps.MapTypeId.ROADMAP
    });

    officePlacesService = new google.maps.places.PlacesService(officeMap);
    officeAutocompleteService = new google.maps.places.AutocompleteService();

    if (latInput?.value && lngInput?.value) {
        setOfficeLocationMarker(savedLat, savedLng, savedRadius);
    }

    officeMap.addListener('click', function (event) {
        const lat = event.latLng.lat();
        const lng = event.latLng.lng();
        setOfficeLocationMarker(lat, lng);
        reverseOfficeLocation(lat, lng);
    });

    setupOfficeLocationSearch();
}

window.initOfficeLocationMap = initOfficeLocationMap;

function setOfficeLocationMarker(lat, lng, radius) {
    if (!officeMap || typeof google === 'undefined') return;

    const position = {
        lat: parseFloat(lat),
        lng: parseFloat(lng)
    };

    if (officeMarker) officeMarker.setMap(null);
    if (officeCircle) officeCircle.setMap(null);

    officeMarker = new google.maps.Marker({
        position: position,
        map: officeMap,
        draggable: true,
        animation: google.maps.Animation.DROP,
        title: 'Office Location'
    });

    officeMarker.addListener('dragend', function () {
        const pos = officeMarker.getPosition();
        fillOfficeLatLng(pos.lat(), pos.lng());
        reverseOfficeLocation(pos.lat(), pos.lng());
        updateOfficeCircle(pos.lat(), pos.lng());
    });

    const radiusInput = officeById('location_radius');
    const radiusValue = radius || parseInt(radiusInput?.value || '100', 10) || 100;

    officeCircle = new google.maps.Circle({
        map: officeMap,
        center: position,
        radius: radiusValue,
        fillColor: '#10b981',
        fillOpacity: 0.12,
        strokeColor: '#10b981',
        strokeOpacity: 0.70,
        strokeWeight: 2
    });

    fillOfficeLatLng(lat, lng);
    officeMap.setCenter(position);
    officeMap.setZoom(16);
}

function fillOfficeLatLng(lat, lng) {
    const latInput = officeById('latitude');
    const lngInput = officeById('longitude');

    if (latInput) latInput.value = Number(lat).toFixed(8);
    if (lngInput) lngInput.value = Number(lng).toFixed(8);
}

function updateOfficeCircle(lat, lng) {
    if (!officeCircle) return;

    officeCircle.setCenter({
        lat: parseFloat(lat),
        lng: parseFloat(lng)
    });
}

function fillOfficeAddressFromResult(result) {
    if (!result) return;

    const address = officeById('address');
    if (address) address.value = result.formatted_address || '';

    let city = '';
    let state = '';
    let pincode = '';

    (result.address_components || []).forEach(function (component) {
        const types = component.types || [];

        if (types.includes('locality')) city = component.long_name;
        if (!city && types.includes('administrative_area_level_3')) city = component.long_name;
        if (types.includes('administrative_area_level_1')) state = component.long_name;
        if (types.includes('postal_code')) pincode = component.long_name;
    });

    const cityInput = officeById('city');
    const stateInput = officeById('state');
    const pincodeInput = officeById('pincode');

    if (cityInput && city) cityInput.value = city;
    if (stateInput && state) stateInput.value = state;
    if (pincodeInput && pincode) pincodeInput.value = pincode;
}

function reverseOfficeLocation(lat, lng) {
    if (!officeGeocoder) return;

    officeGeocoder.geocode({
        location: {
            lat: parseFloat(lat),
            lng: parseFloat(lng)
        }
    }, function (results, status) {
        if (status === 'OK' && results[0]) {
            fillOfficeAddressFromResult(results[0]);
        }
    });
}

function getOfficeCurrentLocation() {
    if (!navigator.geolocation) {
        alert('Geolocation is not supported by your browser.');
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function (position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            setOfficeLocationMarker(lat, lng);
            reverseOfficeLocation(lat, lng);
        },
        function (error) {
            let message = 'Unable to get current location. ';

            if (error.code === error.PERMISSION_DENIED) message += 'Please allow location permission.';
            else if (error.code === error.POSITION_UNAVAILABLE) message += 'Location unavailable.';
            else if (error.code === error.TIMEOUT) message += 'Location request timed out.';

            alert(message);
        },
        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        }
    );
}

window.getOfficeCurrentLocation = getOfficeCurrentLocation;

function setupOfficeLocationSearch() {
    const searchInput = officeById('office_location_search');
    const suggestions = officeById('office_location_suggestions');

    if (!searchInput || !suggestions || !officeAutocompleteService || !officePlacesService) return;

    searchInput.addEventListener('input', function () {
        const query = this.value.trim();

        if (query.length < 3) {
            suggestions.style.display = 'none';
            suggestions.innerHTML = '';
            return;
        }

        officeAutocompleteService.getPlacePredictions({
            input: query,
            componentRestrictions: { country: 'in' }
        }, function (predictions, status) {
            if (status === google.maps.places.PlacesServiceStatus.OK && predictions) {
                suggestions.innerHTML = '';

                predictions.forEach(function (prediction) {
                    const item = document.createElement('div');
                    item.className = 'office-location-suggestion';
                    item.textContent = prediction.description;

                    item.onclick = function () {
                        selectOfficePlace(prediction.place_id, prediction.description);
                        suggestions.style.display = 'none';
                        searchInput.value = prediction.description;
                    };

                    suggestions.appendChild(item);
                });

                suggestions.style.display = 'block';
            } else {
                suggestions.style.display = 'none';
            }
        });
    });

    document.addEventListener('click', function (event) {
        if (!searchInput.contains(event.target) && !suggestions.contains(event.target)) {
            suggestions.style.display = 'none';
        }
    });
}

function selectOfficePlace(placeId, description) {
    if (!officePlacesService) return;

    officePlacesService.getDetails({
        placeId: placeId,
        fields: ['geometry', 'formatted_address', 'address_components', 'name']
    }, function (place, status) {
        if (status === google.maps.places.PlacesServiceStatus.OK && place.geometry) {
            const lat = place.geometry.location.lat();
            const lng = place.geometry.location.lng();

            setOfficeLocationMarker(lat, lng);
            fillOfficeAddressFromResult(place);

            const address = officeById('address');
            if (address && !address.value) address.value = description;
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const radiusInput = officeById('location_radius');

    if (radiusInput) {
        radiusInput.addEventListener('input', function () {
            if (officeCircle) {
                officeCircle.setRadius(parseInt(this.value || '100', 10) || 100);
            }
        });
    }

    const modal = officeById('masterModal');
    if (modal && '<?= e($type) ?>' === 'office_locations') {
        modal.addEventListener('shown.bs.modal', function () {
            setTimeout(function () {
                initOfficeLocationMap();
            }, 250);
        });
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const masterForm = document.querySelector('#masterModal form');

    if (masterForm && '<?= e($type) ?>' === 'office_locations') {
        masterForm.addEventListener('submit', function (event) {
            const radiusInput = officeById('location_radius');
            const latInput = officeById('latitude');
            const lngInput = officeById('longitude');

            const radius = parseInt(radiusInput?.value || '0', 10);

            if (radius < 10 || radius > 5000) {
                event.preventDefault();
                alert('Employee punch-in radius must be between 10 and 5000 meters.');
                return;
            }

            if (!latInput?.value || !lngInput?.value) {
                event.preventDefault();
                alert('Please select office location on Google Map. Latitude and longitude are required for punch-in radius.');
            }
        });
    }
});

window.addEventListener('load', function () { if (window.lucide && typeof window.lucide.createIcons === 'function') { window.lucide.createIcons() } });</script>
<?php if ($type === 'office_locations'): ?>
    <!-- Replace YOUR_GOOGLE_MAPS_API_KEY with your actual key. Enable Maps JavaScript API, Places API, and Geocoding API. -->
    <script
        src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCyBiTiehtlXq0UxU-CTy_odcLF33eekBE&libraries=places,geometry&callback=initOfficeLocationMap"
        async
        defer>
    </script>
<?php endif; ?>
</body>

</html>