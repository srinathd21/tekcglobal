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
    $pageMessageText = "User account saved successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$employeesQ = mysqli_query($conn, "
    SELECT 
        e.id,
        e.full_name,
        e.employee_code,
        e.email,
        e.mobile_number,
        r.role_name AS designation_name,
        d.department_name,
        e.division_id,
        cd.division_name
    FROM employees e
    LEFT JOIN roles r ON r.id = e.role_id
    LEFT JOIN master_departments d ON d.id = e.department_id
    LEFT JOIN company_divisions cd ON cd.id = e.division_id
    LEFT JOIN users u ON u.employee_id = e.id
    WHERE e.employee_status = 'active'
      AND u.id IS NULL
      AND (e.user_id IS NULL OR e.user_id = 0)
    ORDER BY e.full_name ASC
");

$roleRows = [];
$rolesQ = mysqli_query($conn, "
    SELECT id, role_name, role_slug
    FROM roles
    WHERE is_active = 1
    ORDER BY role_name ASC
");

if ($rolesQ) {
    while ($role = mysqli_fetch_assoc($rolesQ)) {
        $roleRows[] = $role;
    }
}

$divisionRows = [];
$divisionsQ = mysqli_query($conn, "
    SELECT id, division_name, division_code
    FROM company_divisions
    WHERE is_active = 1
    ORDER BY sort_order ASC, division_name ASC
");

if ($divisionsQ) {
    while ($division = mysqli_fetch_assoc($divisionsQ)) {
        $divisionRows[] = $division;
    }
}

$usersQ = mysqli_query($conn, "
    SELECT 
        u.id,
        u.name,
        u.username,
        u.email,
        u.mobile_number,
        u.status,
        u.last_login_at,
        u.created_at,
        e.employee_code,
        e.full_name AS employee_name,
        e.division_id,
        cd.division_name,
        GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR ', ') AS roles_text,
        GROUP_CONCAT(r.id ORDER BY r.id SEPARATOR ',') AS role_ids_text
    FROM users u
    LEFT JOIN employees e ON e.id = u.employee_id
    LEFT JOIN company_divisions cd ON cd.id = e.division_id
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    GROUP BY u.id
    ORDER BY u.id DESC
");

$users = [];
if ($usersQ) {
    while ($u = mysqli_fetch_assoc($usersQ)) {
        $users[] = $u;
    }
}

$stats = [
    "total_users" => 0,
    "active_users" => 0,
    "available_employees" => 0,
    "roles" => 0,
    "divisions" => 0
];

$stats["total_users"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM users"))["total"] ?? 0);
$stats["active_users"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE status = 'active'"))["total"] ?? 0);
$stats["available_employees"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM employees e
    LEFT JOIN users u ON u.employee_id = e.id
    WHERE e.employee_status = 'active'
      AND u.id IS NULL
      AND (e.user_id IS NULL OR e.user_id = 0)
"))["total"] ?? 0);
$stats["roles"] = count($roleRows);
$divisionsCountQ = mysqli_query($conn, "SELECT COUNT(*) AS total FROM company_divisions WHERE is_active = 1");
$stats["divisions"] = $divisionsCountQ ? (int)(mysqli_fetch_assoc($divisionsCountQ)["total"] ?? 0) : 0;
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Users - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>

    <style>
        .page-head-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px;
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

        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 24px;
            box-shadow: var(--shadow-card);
        }

        .role-box {
            border: 1px solid var(--border-soft);
            background: rgba(148, 163, 184, .06);
            border-radius: 18px;
            padding: 12px;
            max-height: 240px;
            overflow: auto;
        }

        .role-check-row {
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--border-soft);
            background: var(--card-bg);
            border-radius: 14px;
            padding: 9px 10px;
            margin-bottom: 8px;
        }

        .role-check-row:last-child {
            margin-bottom: 0;
        }

        .role-check-row label {
            font-size: 13px;
            font-weight: 800;
            color: var(--text-main);
            cursor: pointer;
            margin: 0;
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            min-width: 42px;
            border-radius: 16px;
            background: rgba(148, 163, 184, .14);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            font-weight: 900;
        }

        .password-note {
            border: 1px solid rgba(245, 158, 11, .28);
            background: rgba(245, 158, 11, .08);
            border-radius: 18px;
            padding: 12px 14px;
        }

        .modal-content {
            background: var(--card-bg);
            color: var(--text-main);
            border: 1px solid var(--border-soft);
            border-radius: 24px;
            box-shadow: var(--shadow-card);
        }

        .modal-header,
        .modal-footer {
            border-color: var(--border-soft);
        }

        .password-input-wrap {
            position: relative;
        }

        .password-input-wrap .form-control {
            padding-right: 46px;
        }

        .password-eye-btn {
            position: absolute;
            right: 7px;
            top: 50%;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            border: 0;
            background: transparent;
            color: var(--text-muted);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
        }

        .password-eye-btn:hover {
            background: rgba(148, 163, 184, .12);
            color: var(--text-main);
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
                <div class="page-head-card mb-3">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                        <div>
                            <h1 class="h4 fw-bold mb-1">User Creation</h1>
                            <p class="text-muted-custom mb-0 small">Create and edit login users only for already registered active employees.</p>
                        </div>
                        <button type="button" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3"
                            data-bs-toggle="modal" data-bs-target="#userModal" onclick="openAddUserModal()">
                            <i data-lucide="user-plus" style="width:15px;height:15px;"></i> Add User
                        </button>
                    </div>
                </div>

                <div class="row g-3 mb-3 kpi-row">
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                                <i data-lucide="users" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Total Users <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["total_users"] ?></p>
                                <p class="kpi-sub"><span>↑ <?= (int)$stats["total_users"] ?></span> login accounts</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-success-subtle text-success">
                                <i data-lucide="check-circle-2" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Active Users <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["active_users"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["active_users"] ?></span> enabled accounts</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                                <i data-lucide="user-plus" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Available Employees <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["available_employees"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["available_employees"] ?></span> without user login</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-warning-subtle text-warning">
                                <i data-lucide="shield-check" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Active Roles <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["roles"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["roles"] ?></span> assignable roles</p>
                            </div>
                        </article>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <section class="card-ui overflow-hidden">
                            <div class="p-3 p-lg-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-3">
                                <div>
                                    <h2 class="fw-bold fs-6 mb-1">Created Users</h2>
                                    <p class="text-muted-custom small mb-0">Manage employee login accounts and role access.</p>
                                </div>
                                <button type="button" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3"
                                    data-bs-toggle="modal" data-bs-target="#userModal" onclick="openAddUserModal()">
                                    <i data-lucide="user-plus" style="width:15px;height:15px;"></i> Add User
                                </button>
                            </div>

                            <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                                <table class="project-table w-100">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Employee</th>
                                            <th>Division</th>
                                            <th>Roles</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th style="width:110px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $u): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="user-avatar"><?= e(strtoupper(substr($u["name"], 0, 1))) ?></div>
                                                        <div>
                                                            <div class="fw-bold"><?= e($u["name"]) ?></div>
                                                            <small class="text-muted-custom"><?= e($u["username"]) ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-bold small"><?= e($u["employee_name"] ?? "-") ?></div>
                                                    <small class="text-muted-custom"><?= e($u["employee_code"] ?? "") ?></small>
                                                </td>
                                                <td><?= e($u["division_name"] ?: "-") ?></td>
                                                <td><?= e($u["roles_text"] ?: "-") ?></td>
                                                <td>
                                                    <span class="pill <?= $u["status"] === "active" ? "green" : "amber" ?>">
                                                        <?= e(ucfirst($u["status"])) ?>
                                                    </span>
                                                </td>
                                                <td><?= e(date("d M Y", strtotime($u["created_at"]))) ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                                        data-bs-toggle="modal" data-bs-target="#userModal"
                                                        onclick='openEditUserModal(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <?php if (empty($users)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted-custom py-4">No users created yet.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3">
                                <?php foreach ($users as $u): ?>
                                    <article class="mobile-project-card">
                                        <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                            <div>
                                                <p class="fw-bold small mb-1"><?= e($u["name"]) ?></p>
                                                <small class="text-muted-custom"><?= e($u["username"]) ?></small>
                                            </div>
                                            <span class="pill <?= $u["status"] === "active" ? "green" : "amber" ?>">
                                                <?= e(ucfirst($u["status"])) ?>
                                            </span>
                                        </div>

                                        <div class="mobile-info"><span>Employee</span><span><?= e($u["employee_name"] ?? "-") ?></span></div>
                                        <div class="mobile-info"><span>Code</span><span><?= e($u["employee_code"] ?? "-") ?></span></div>
                                        <div class="mobile-info"><span>Division</span><span><?= e($u["division_name"] ?: "-") ?></span></div>
                                        <div class="mobile-info"><span>Roles</span><span><?= e($u["roles_text"] ?: "-") ?></span></div>

                                        <div class="mt-3">
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                                data-bs-toggle="modal" data-bs-target="#userModal"
                                                onclick='openEditUserModal(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
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

    <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form action="api/process-user.php" method="POST" class="modal-content">
                <input type="hidden" name="user_id" id="user_id">

                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold" id="userModalTitle">Add User</h5>
                        <p class="text-muted-custom small mb-0">Create or edit user login for registered employees.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="mb-3" id="employeeSelectWrap">
                        <label class="form-label fw-bold small">Registered Employee <span class="text-danger">*</span></label>
                        <select name="employee_id" id="employee_id" class="form-select rounded-4" onchange="fillEmployeeDetails()">
                            <option value="">Select Employee</option>
                            <?php if ($employeesQ): ?>
                                <?php while ($emp = mysqli_fetch_assoc($employeesQ)): ?>
                                    <option value="<?= (int)$emp["id"] ?>"
                                        data-name="<?= e($emp["full_name"]) ?>"
                                        data-email="<?= e($emp["email"]) ?>"
                                        data-mobile="<?= e($emp["mobile_number"]) ?>"
                                        data-code="<?= e($emp["employee_code"]) ?>"
                                        data-division-id="<?= (int)($emp["division_id"] ?? 0) ?>">
                                        <?= e($emp["full_name"]) ?> (<?= e($emp["employee_code"]) ?>)<?= $emp["division_name"] ? " - " . e($emp["division_name"]) : "" ?><?= $emp["designation_name"] ? " - " . e($emp["designation_name"]) : "" ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                        <small class="text-muted-custom fw-semibold">Only employees without login are shown for new user creation.</small>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" class="form-control rounded-4" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="username" class="form-control rounded-4" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Email</label>
                            <input type="email" name="email" id="email" class="form-control rounded-4">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Mobile</label>
                            <input type="text" name="mobile_number" id="mobile_number" class="form-control rounded-4">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Password <span class="text-danger user-password-required">*</span></label>
                            <div class="password-input-wrap">
                                <input type="password" name="password" id="password" class="form-control rounded-4" minlength="6">
                                <button type="button" class="password-eye-btn" onclick="togglePassword('password', this)">
                                    <i data-lucide="eye" style="width:18px;height:18px;"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Confirm Password <span class="text-danger user-password-required">*</span></label>
                            <div class="password-input-wrap">
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control rounded-4" minlength="6">
                                <button type="button" class="password-eye-btn" onclick="togglePassword('confirm_password', this)">
                                    <i data-lucide="eye" style="width:18px;height:18px;"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-12">
                            <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm" onclick="generatePassword()">
                                <i data-lucide="key-round" style="width:15px;height:15px;"></i> Auto Generate Password
                            </button>
                            <small class="text-muted-custom fw-semibold ms-2" id="passwordHelpText">Password is required for new user.</small>
                        </div>
                    </div>

                    <div class="password-note my-3">
                        <div class="d-flex gap-2 align-items-start">
                            <i data-lucide="info" class="text-warning" style="width:18px;height:18px;"></i>
                            <p class="small fw-semibold text-muted-custom mb-0">
                                For edit, leave password empty to keep old password. Click auto generate to reset password.
                            </p>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Role <span class="text-danger">*</span></label>
                            <select name="role_id" id="role_id" class="form-select rounded-4" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roleRows as $role): ?>
                                    <option value="<?= (int)$role["id"] ?>">
                                        <?= e($role["role_name"]) ?> (<?= e($role["role_slug"]) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted-custom fw-semibold">One login role will be assigned to this user.</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Division <span class="text-danger">*</span></label>
                            <select name="division_id" id="division_id" class="form-select rounded-4" required>
                                <option value="">Select Division</option>
                                <?php foreach ($divisionRows as $division): ?>
                                    <option value="<?= (int)$division["id"] ?>">
                                        <?= e($division["division_name"]) ?><?= $division["division_code"] ? " (" . e($division["division_code"]) . ")" : "" ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted-custom fw-semibold">This updates the employee division.</small>
                        </div>
                    </div>

                    <div>
                        <label class="form-label fw-bold small">Status</label>
                        <select name="status" id="status" class="form-select rounded-4">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="blocked">Blocked</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4" id="userSubmitBtn">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=18"></script>
    <script>
        function makeUsername(value) {
            return (value || "").toLowerCase().replace(/[^a-z0-9]+/g, "").substring(0, 45);
        }

        function fillEmployeeDetails() {
            const select = document.getElementById("employee_id");
            const option = select.options[select.selectedIndex];

            if (!option || !option.value) {
                document.getElementById("name").value = "";
                document.getElementById("email").value = "";
                document.getElementById("mobile_number").value = "";
                document.getElementById("username").value = "";
                return;
            }

            const name = option.dataset.name || "";
            const email = option.dataset.email || "";
            const mobile = option.dataset.mobile || "";
            const code = option.dataset.code || "";
            const divisionId = option.dataset.divisionId || "";

            document.getElementById("division_id").value = divisionId;
            document.getElementById("email").value = email;
            document.getElementById("mobile_number").value = mobile;
            document.getElementById("username").value = makeUsername(code || name);
        }

        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;

            input.type = input.type === "password" ? "text" : "password";

            const icon = btn.querySelector("i");
            if (icon) {
                icon.setAttribute("data-lucide", input.type === "password" ? "eye" : "eye-off");
            }

            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        }

        function generatePassword() {
            const chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$%";
            let password = "";

            for (let i = 0; i < 10; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }

            document.getElementById("password").value = password;
            document.getElementById("confirm_password").value = password;
            document.getElementById("password").type = "text";
            document.getElementById("confirm_password").type = "text";
        }

        function resetUserModal() {
            document.getElementById("user_id").value = "";
            document.getElementById("employee_id").value = "";
            document.getElementById("name").value = "";
            document.getElementById("username").value = "";
            document.getElementById("email").value = "";
            document.getElementById("mobile_number").value = "";
            document.getElementById("password").value = "";
            document.getElementById("confirm_password").value = "";
            document.getElementById("password").type = "password";
            document.getElementById("confirm_password").type = "password";
            document.getElementById("status").value = "active";

            document.getElementById("role_id").value = "";
            document.getElementById("division_id").value = "";
        }

        function setPasswordRequired(isRequired) {
            document.getElementById("password").required = isRequired;
            document.getElementById("confirm_password").required = isRequired;

            document.querySelectorAll(".user-password-required").forEach(function (el) {
                el.classList.toggle("d-none", !isRequired);
            });

            document.getElementById("passwordHelpText").textContent = isRequired
                ? "Password is required for new user."
                : "Leave password empty to keep old password.";
        }

        function openAddUserModal() {
            resetUserModal();
            document.getElementById("userModalTitle").textContent = "Add User";
            document.getElementById("userSubmitBtn").textContent = "Create User";
            document.getElementById("employeeSelectWrap").classList.remove("d-none");
            document.getElementById("employee_id").setAttribute("required", "required");
            setPasswordRequired(true);
        }

        function openEditUserModal(user) {
            resetUserModal();
            document.getElementById("userModalTitle").textContent = "Edit User";
            document.getElementById("userSubmitBtn").textContent = "Update User";
            document.getElementById("employeeSelectWrap").classList.add("d-none");
            document.getElementById("employee_id").removeAttribute("required");

            document.getElementById("user_id").value = user.id || "";
            document.getElementById("name").value = user.name || "";
            document.getElementById("username").value = user.username || "";
            document.getElementById("email").value = user.email || "";
            document.getElementById("mobile_number").value = user.mobile_number || "";
            document.getElementById("status").value = user.status || "active";

            const roleIds = (user.role_ids_text || "").split(",").filter(Boolean);
            document.getElementById("role_id").value = roleIds.length ? roleIds[0] : "";
            document.getElementById("division_id").value = user.division_id || "";

            setPasswordRequired(false);
        }

        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
