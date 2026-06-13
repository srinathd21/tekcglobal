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
  $pageMessageText = "Role saved successfully.";
} elseif (isset($_GET["access"])) {
  $pageMessageType = "success";
  $pageMessageText = "Role access updated successfully.";
} elseif (isset($_GET["status"])) {
  $pageMessageType = "success";
  $pageMessageText = "Role status changed successfully.";
} elseif (isset($_GET["error"])) {
  $pageMessageType = "error";
  $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$rolesQ = mysqli_query($conn, "SELECT * FROM roles ORDER BY id DESC");
$menusQ = mysqli_query($conn, "SELECT id,parent_id,menu_title,menu_slug,menu_type,sort_order FROM sidebar_menus WHERE is_active=1 ORDER BY COALESCE(parent_id,id), parent_id IS NOT NULL, sort_order ASC, id ASC");
$accessQ = mysqli_query($conn, "SELECT role_id,menu_id,can_view,can_create,can_edit,can_delete,can_approve FROM role_sidebar_access");

$roleAccess = [];
while ($a = mysqli_fetch_assoc($accessQ)) {
  $roleAccess[(int) $a['role_id']][(int) $a['menu_id']] = $a;
}
$menuRows = [];
while ($m = mysqli_fetch_assoc($menusQ)) {
  $menuRows[] = $m;
}
$roleRows = [];
$stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'designation' => 0];
while ($r = mysqli_fetch_assoc($rolesQ)) {
  $roleRows[] = $r;
  $stats['total']++;
  if ((int) $r['is_active'] === 1)
    $stats['active']++;
  else
    $stats['inactive']++;
  if ((int) $r['is_designation'] === 1)
    $stats['designation']++;
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Roles - TEK-C PMC Construction</title>
  <?php include("includes/links.php"); ?>
  <style>
    .page-head-card {
      background: var(--card-bg);
      border: 1px solid var(--border-soft);
      border-radius: 22px;
      box-shadow: var(--shadow-card);
      padding: 16px
    }

    .mini-stat {
      background: var(--card-bg);
      border: 1px solid var(--border-soft);
      border-radius: 24px;
      box-shadow: var(--shadow-card);
      padding: 22px 24px;
      height: 100%;
    }

    .mini-stat span {
      color: var(--text-muted);
      font-size: 13px;
      font-weight: 800;
      letter-spacing: 0;
    }

    .mini-stat p {
      margin: 4px 0 2px;
      color: var(--text-main);
      font-size: 24px;
      line-height: 1.15;
      font-weight: 900;
    }

    .kpi-row > [class*="col"] {
      display: flex;
    }

    .kpi-row .kpi-card {
      width: 100%;
    }

    .kpi-card {
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

    .permission-wrap {
      max-height: 58vh;
      overflow: auto;
      border: 1px solid #111827;
      border-radius: 18px
    }

    .permission-table {
      border-collapse: separate;
      border-spacing: 0;
    }

    .permission-table th,
    .permission-table td {
      padding: 10px 8px;
      font-size: 12px;
      vertical-align: middle;
      border-bottom: 1px solid #111827;
      border-right: 1px solid #111827;
    }

    .permission-table th:first-child,
    .permission-table td:first-child {
      border-left: 1px solid #111827;
    }

    .permission-table thead th {
      position: sticky;
      top: 0;
      z-index: 2;
      background: var(--card-bg);
      color: var(--text-main);
      font-size: 11px;
      font-weight: 900;
      text-transform: uppercase;
      border-top: 1px solid #111827;
    }

    .permission-table tbody tr.section-row td {
      background: rgba(17, 24, 39, .08);
      color: var(--text-main);
      font-weight: 900;
    }

    .permission-table tbody tr:hover td {
      background: rgba(148, 163, 184, .10);
    }

    .select-section-box {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      font-size: 11px;
      font-weight: 900;
      color: var(--text-main);
      white-space: nowrap;
    }

    .access-check,
    .section-select-all,
    .column-select-all {
      width: 17px;
      height: 17px;
      cursor: pointer;
      border: 2px solid #111827 !important;
      box-shadow: none !important;
    }

    .access-check:checked,
    .section-select-all:checked,
    .column-select-all:checked {
      background-color: #111827 !important;
      border-color: #111827 !important;
    }

    .access-check:focus,
    .section-select-all:focus,
    .column-select-all:focus {
      box-shadow: 0 0 0 4px rgba(17, 24, 39, .18) !important;
    }

    .access-tools {
      border: 1px solid #111827;
      border-radius: 18px;
      padding: 10px 12px;
      margin-bottom: 12px;
      background: rgba(148, 163, 184, .06);
    }
    
  </style>
</head>

<body>
  <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none">
  </div>

  <?php include("includes/page-message.php"); ?>

  <div class="min-vh-100 d-flex">
    <?php include("includes/sidebar.php"); ?>
    <main id="main">
      <?php include("includes/nav.php"); ?>
      <section class="page-section p-3 p-lg-3">
        <div class="page-head-card mb-3">
          <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
            <div>
              <h1 class="h4 fw-bold mb-1">Roles</h1>
              <p class="text-muted-custom mb-0 small">Create roles, mark designations, and manage role based sidebar
                access.</p>
            </div><button type="button" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3"
              data-bs-toggle="modal" data-bs-target="#roleModal" onclick="openAddRoleModal()"><i data-lucide="plus"
                style="width:15px;height:15px;"></i> Add Role</button>
          </div>
        </div>
        <div class="row g-3 mb-3 kpi-row">
          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="kpi-card">
              <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                <i data-lucide="shield-check" style="width:24px;height:24px;"></i>
              </div>
              <div>
                <div class="kpi-label">Total Roles <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="kpi-value"><?= (int) $stats['total'] ?></p>
                <p class="kpi-sub"><span>↑ <?= (int) $stats['total'] ?></span> roles created</p>
              </div>
            </article>
          </div>

          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="kpi-card">
              <div class="kpi-icon bg-success-subtle text-success">
                <i data-lucide="check-circle-2" style="width:24px;height:24px;"></i>
              </div>
              <div>
                <div class="kpi-label">Active Roles <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="kpi-value"><?= (int) $stats['active'] ?></p>
                <p class="kpi-sub"><span>↑ <?= (int) $stats['active'] ?></span> enabled roles</p>
              </div>
            </article>
          </div>

          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="kpi-card">
              <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                <i data-lucide="pause-circle" style="width:24px;height:24px;"></i>
              </div>
              <div>
                <div class="kpi-label">Inactive Roles <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="kpi-value"><?= (int) $stats['inactive'] ?></p>
                <p class="kpi-sub"><span><?= (int) $stats['inactive'] ?></span> disabled roles</p>
              </div>
            </article>
          </div>

          <div class="col-12 col-sm-6 col-lg-4 col-xxl">
            <article class="kpi-card">
              <div class="kpi-icon bg-warning-subtle text-warning">
                <i data-lucide="badge-check" style="width:24px;height:24px;"></i>
              </div>
              <div>
                <div class="kpi-label">Designations <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                <p class="kpi-value"><?= (int) $stats['designation'] ?></p>
                <p class="kpi-sub"><span>↑ <?= (int) $stats['designation'] ?></span> employee titles</p>
              </div>
            </article>
          </div>
        </div>
        <section class="card-ui overflow-hidden">
          <div class="p-3 p-lg-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-3">
            <div>
              <h2 class="fw-bold fs-6 mb-1">Manage Roles</h2>
              <p class="text-muted-custom small mb-0">Add, edit, activate, deactivate, and configure sidebar
                permissions.</p>
            </div>
          </div>
          <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
            <table class="project-table w-100">
              <thead>
                <tr>
                  <th>Role Name</th>
                  <th>Slug</th>
                  <th>Designation</th>
                  <th>System</th>
                  <th>Status</th>
                  <th style="width:230px;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($roleRows as $r): ?>
                  <tr>
                    <td class="fw-bold"><?= e($r['role_name']) ?></td>
                    <td><?= e($r['role_slug']) ?></td>
                    <td><?= (int) $r['is_designation'] === 1 ? 'Yes' : 'No' ?></td>
                    <td><?= (int) $r['is_system'] === 1 ? 'Yes' : 'No' ?></td>
                    <td><span
                        class="pill <?= (int) $r['is_active'] === 1 ? 'green' : 'amber' ?>"><?= (int) $r['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                    </td>
                    <td><button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                        data-bs-toggle="modal" data-bs-target="#roleModal"
                        onclick='openEditRoleModal(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold"
                        data-bs-toggle="modal" data-bs-target="#accessModal"
                        onclick='openAccessModal(<?= (int) $r['id'] ?>, <?= json_encode($roleAccess[(int) $r['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($r['role_name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Access</button>
                      <a href="api/toggle-role.php?id=<?= (int) $r['id'] ?>"
                        onclick="return confirm('Change this role status?')"
                        class="btn btn-sm btn-outline-warning rounded-4 fw-bold">Toggle</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3"><?php foreach ($roleRows as $r): ?>
              <article class="mobile-project-card">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                  <div>
                    <p class="fw-bold small mb-1"><?= e($r['role_name']) ?></p><small
                      class="text-muted-custom"><?= e($r['role_slug']) ?></small>
                  </div><span
                    class="pill <?= (int) $r['is_active'] === 1 ? 'green' : 'amber' ?>"><?= (int) $r['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                </div>
                <div class="mobile-info">
                  <span>Designation</span><span><?= (int) $r['is_designation'] === 1 ? 'Yes' : 'No' ?></span>
                </div>
                <div class="mobile-info"><span>System</span><span><?= (int) $r['is_system'] === 1 ? 'Yes' : 'No' ?></span>
                </div>
                <div class="mt-3 d-flex flex-wrap gap-2"><button type="button"
                    class="btn btn-sm btn-outline-primary rounded-4 fw-bold" data-bs-toggle="modal"
                    data-bs-target="#roleModal"
                    onclick='openEditRoleModal(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button><button
                    type="button" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold" data-bs-toggle="modal"
                    data-bs-target="#accessModal"
                    onclick='openAccessModal(<?= (int) $r['id'] ?>, <?= json_encode($roleAccess[(int) $r['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($r['role_name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Access</button><a
                    href="api/toggle-role.php?id=<?= (int) $r['id'] ?>"
                    onclick="return confirm('Change this role status?')"
                    class="btn btn-sm btn-outline-warning rounded-4 fw-bold">Toggle</a></div>
              </article><?php endforeach; ?>
          </div>
        </section>
        <?php include("includes/footer.php"); ?>
      </section>
    </main>
    <div id="settingsOverlay"></div><?php include("includes/rightsidbar.php"); ?>
  </div>
  <div class="modal fade" id="roleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form action="api/process-role.php" method="POST" class="modal-content"><input type="hidden" name="role_id"
          id="role_id">
        <div class="modal-header px-4">
          <div>
            <h5 class="modal-title fw-bold" id="roleModalTitle">Add Role</h5>
            <p class="text-muted-custom small mb-0">Role name can also be used as employee designation.</p>
          </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body px-4">
          <div class="mb-3"><label class="form-label fw-bold small">Role Name</label><input type="text" name="role_name"
              id="role_name" class="form-control rounded-4" required></div>
          <div class="mb-3"><label class="form-label fw-bold small">Role Slug</label><input type="text" name="role_slug"
              id="role_slug" class="form-control rounded-4" placeholder="auto generated if empty"></div>
          <div class="mb-3"><label class="form-label fw-bold small">Description</label><textarea name="description"
              id="description" rows="3" class="form-control rounded-4"></textarea></div>
          <div class="row g-2">
            <div class="col-6"><label class="form-label fw-bold small">Designation?</label><select name="is_designation"
                id="is_designation" class="form-select rounded-4">
                <option value="1">Yes</option>
                <option value="0">No</option>
              </select></div>
            <div class="col-6"><label class="form-label fw-bold small">Status</label><select name="is_active"
                id="is_active" class="form-select rounded-4">
                <option value="1">Active</option>
                <option value="0">Inactive</option>
              </select></div>
          </div>
        </div>
        <div class="modal-footer px-4"><button type="button" class="btn btn-outline-secondary rounded-4 fw-bold"
            data-bs-dismiss="modal">Cancel</button><button type="submit"
            class="btn brand-gradient text-white rounded-4 fw-bold px-4">Save Role</button></div>
      </form>
    </div>
  </div>
  <div class="modal fade" id="accessModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <form action="api/process-role-access.php" method="POST" class="modal-content"><input type="hidden" name="role_id"
          id="access_role_id">
        <div class="modal-header px-4">
          <div>
            <h5 class="modal-title fw-bold">Sidebar Access</h5>
            <p class="text-muted-custom small mb-0" id="accessRoleName">Configure role menu permissions.</p>
          </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body px-4">
          <div class="permission-wrap thin-scrollbar">
            <div class="access-tools d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-2">
              <label class="select-section-box mb-0">
                <input type="checkbox" class="form-check-input section-select-all" id="selectAllAccess">
                Select All Permissions
              </label>
              <div class="d-flex flex-wrap gap-3">
                <?php foreach (['can_view' => 'View', 'can_create' => 'Create', 'can_edit' => 'Edit', 'can_delete' => 'Delete', 'can_approve' => 'Approve'] as $perm => $label): ?>
                  <label class="select-section-box mb-0">
                    <input type="checkbox" class="form-check-input column-select-all" data-column-perm="<?= e($perm) ?>">
                    All <?= e($label) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <table class="w-100 permission-table">
              <thead>
                <tr>
                  <th>Menu / Section</th>
                  <th class="text-center">View</th>
                  <th class="text-center">Create</th>
                  <th class="text-center">Edit</th>
                  <th class="text-center">Delete</th>
                  <th class="text-center">Approve</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $currentSectionId = null;
                foreach ($menuRows as $m):
                  $sectionId = !empty($m['parent_id']) ? (int)$m['parent_id'] : (int)$m['id'];

                  if ((int)$m['parent_id'] === 0 || $m['parent_id'] === null):
                    $currentSectionId = (int)$m['id'];
                ?>
                    <tr class="section-row">
                      <td>
                        <label class="select-section-box mb-0">
                          <input type="checkbox" class="form-check-input section-select-all" data-section-id="<?= (int)$m['id'] ?>">
                          <?= e($m['menu_title']) ?>
                        </label>
                        <div><small class="text-muted-custom"><?= e($m['menu_slug']) ?></small></div>
                      </td>
                      <?php foreach (['can_view', 'can_create', 'can_edit', 'can_delete', 'can_approve'] as $perm): ?>
                        <td class="text-center">
                          <input class="form-check-input access-check"
                                 type="checkbox"
                                 data-section-id="<?= (int)$m['id'] ?>"
                                 data-menu-id="<?= (int)$m['id'] ?>"
                                 data-perm="<?= e($perm) ?>"
                                 name="access[<?= (int)$m['id'] ?>][<?= e($perm) ?>]"
                                 value="1">
                        </td>
                      <?php endforeach; ?>
                    </tr>
                  <?php else: ?>
                    <tr>
                      <td>
                        <div class="fw-bold ps-3">— <?= e($m['menu_title']) ?></div>
                        <small class="text-muted-custom ps-3"><?= e($m['menu_slug']) ?></small>
                      </td>
                      <?php foreach (['can_view', 'can_create', 'can_edit', 'can_delete', 'can_approve'] as $perm): ?>
                        <td class="text-center">
                          <input class="form-check-input access-check"
                                 type="checkbox"
                                 data-section-id="<?= (int)$m['parent_id'] ?>"
                                 data-menu-id="<?= (int)$m['id'] ?>"
                                 data-perm="<?= e($perm) ?>"
                                 name="access[<?= (int)$m['id'] ?>][<?= e($perm) ?>]"
                                 value="1">
                        </td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer px-4"><button type="button" class="btn btn-outline-secondary rounded-4 fw-bold"
            data-bs-dismiss="modal">Cancel</button><button type="submit"
            class="btn brand-gradient text-white rounded-4 fw-bold px-4">Save Access</button></div>
      </form>
    </div>
  </div>
  <?php include("includes/script.php") ?>
  <script src="assets/js/script.js?v=8"></script>
  <script>
    function openAddRoleModal() { document.getElementById('roleModalTitle').textContent = 'Add Role';['role_id', 'role_name', 'role_slug', 'description'].forEach(id => document.getElementById(id).value = ''); document.getElementById('is_designation').value = '1'; document.getElementById('is_active').value = '1' }
    function openEditRoleModal(role) { document.getElementById('roleModalTitle').textContent = 'Edit Role'; document.getElementById('role_id').value = role.id || ''; document.getElementById('role_name').value = role.role_name || ''; document.getElementById('role_slug').value = role.role_slug || ''; document.getElementById('description').value = role.description || ''; document.getElementById('is_designation').value = role.is_designation || '1'; document.getElementById('is_active').value = role.is_active || '1' }
    function refreshSectionSelectAll() {
      document.querySelectorAll('.section-select-all[data-section-id]').forEach(function (sectionBox) {
        const sectionId = sectionBox.dataset.sectionId;
        const checks = Array.from(document.querySelectorAll('.access-check[data-section-id="' + sectionId + '"]'));
        const checked = checks.filter(function (input) { return input.checked; }).length;

        sectionBox.checked = checks.length > 0 && checked === checks.length;
        sectionBox.indeterminate = checked > 0 && checked < checks.length;
      });

      ['can_view', 'can_create', 'can_edit', 'can_delete', 'can_approve'].forEach(function (perm) {
        const columnBox = document.querySelector('.column-select-all[data-column-perm="' + perm + '"]');
        const checks = Array.from(document.querySelectorAll('.access-check[data-perm="' + perm + '"]'));
        const checked = checks.filter(function (input) { return input.checked; }).length;

        if (columnBox) {
          columnBox.checked = checks.length > 0 && checked === checks.length;
          columnBox.indeterminate = checked > 0 && checked < checks.length;
        }
      });

      const allBox = document.getElementById('selectAllAccess');
      const allChecks = Array.from(document.querySelectorAll('.access-check'));
      const allChecked = allChecks.filter(function (input) { return input.checked; }).length;

      if (allBox) {
        allBox.checked = allChecks.length > 0 && allChecked === allChecks.length;
        allBox.indeterminate = allChecked > 0 && allChecked < allChecks.length;
      }
    }

    function openAccessModal(roleId, access, roleName) {
      document.getElementById('access_role_id').value = roleId;
      document.getElementById('accessRoleName').textContent = 'Configure access for ' + roleName;

      document.querySelectorAll('.access-check').forEach(function (input) {
        input.checked = false;
      });

      Object.keys(access || {}).forEach(function (menuId) {
        const row = access[menuId];
        ['can_view', 'can_create', 'can_edit', 'can_delete', 'can_approve'].forEach(function (perm) {
          const input = document.querySelector('.access-check[data-menu-id="' + menuId + '"][data-perm="' + perm + '"]');
          if (input && parseInt(row[perm] || 0) === 1) {
            input.checked = true;
          }
        });
      });

      refreshSectionSelectAll();
    }

    document.addEventListener('DOMContentLoaded', function () {
      const selectAllAccess = document.getElementById('selectAllAccess');

      if (selectAllAccess) {
        selectAllAccess.addEventListener('change', function () {
          document.querySelectorAll('.access-check').forEach(function (input) {
            input.checked = selectAllAccess.checked;
          });
          refreshSectionSelectAll();
        });
      }

      document.querySelectorAll('.section-select-all[data-section-id]').forEach(function (sectionBox) {
        sectionBox.addEventListener('change', function () {
          const sectionId = sectionBox.dataset.sectionId;
          document.querySelectorAll('.access-check[data-section-id="' + sectionId + '"]').forEach(function (input) {
            input.checked = sectionBox.checked;
          });
          refreshSectionSelectAll();
        });
      });

      document.querySelectorAll('.column-select-all[data-column-perm]').forEach(function (columnBox) {
        columnBox.addEventListener('change', function () {
          const perm = columnBox.dataset.columnPerm;
          document.querySelectorAll('.access-check[data-perm="' + perm + '"]').forEach(function (input) {
            input.checked = columnBox.checked;
          });
          refreshSectionSelectAll();
        });
      });

      document.querySelectorAll('.access-check').forEach(function (input) {
        input.addEventListener('change', refreshSectionSelectAll);
      });
    });

    window.addEventListener('load', function () { if (window.lucide && typeof window.lucide.createIcons === 'function') { window.lucide.createIcons() } });
  </script>
</body>

</html>