<?php
require_once __DIR__ . "/includes/db.php";

require_permission($conn, "can_view", "employees.php");

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Employee saved successfully.";
} elseif (isset($_GET["deleted"])) {
    $pageMessageType = "success";
    $pageMessageText = "Employee deleted successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$canEmployeeCreate = can_create($conn, "employees.php");
$canEmployeeEdit = can_edit($conn, "employees.php");
$canEmployeeDelete = can_delete($conn, "employees.php");

$search = trim($_GET["search"] ?? "");
$departmentFilter = isset($_GET["department_id"]) ? (int)$_GET["department_id"] : 0;
$statusFilter = trim($_GET["status"] ?? "");

$where = ["1=1"];
$params = [];
$types = "";

if ($search !== "") {
    $where[] = "(e.full_name LIKE ? OR e.employee_code LIKE ? OR e.email LIKE ? OR e.mobile_number LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

if ($departmentFilter > 0) {
    $where[] = "e.department_id = ?";
    $params[] = $departmentFilter;
    $types .= "i";
}

if ($statusFilter !== "") {
    $where[] = "e.employee_status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$whereSql = implode(" AND ", $where);

$page = isset($_GET["page"]) ? (int)$_GET["page"] : 1;
$page = $page <= 0 ? 1 : $page;

$perPage = isset($_GET["per_page"]) ? (int)$_GET["per_page"] : 10;
$allowedPerPage = [10, 25, 50, 100];

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM employees e
    LEFT JOIN master_departments d ON d.id = e.department_id
    LEFT JOIN roles r ON r.id = e.role_id
    LEFT JOIN employees manager ON manager.id = e.reporting_to
    WHERE $whereSql
";

$countStmt = mysqli_prepare($conn, $countSql);
if (!empty($params)) {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
}
mysqli_stmt_execute($countStmt);
$countRow = mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt));
$totalRows = (int)($countRow["total"] ?? 0);
mysqli_stmt_close($countStmt);

$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
        e.*,
        d.department_name,
        r.role_name,
        manager.full_name AS reporting_to_name
    FROM employees e
    LEFT JOIN master_departments d ON d.id = e.department_id
    LEFT JOIN roles r ON r.id = e.role_id
    LEFT JOIN employees manager ON manager.id = e.reporting_to
    WHERE $whereSql
    ORDER BY e.id DESC
    LIMIT ? OFFSET ?
";

$listParams = $params;
$listTypes = $types . "ii";
$listParams[] = $perPage;
$listParams[] = $offset;

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $listTypes, ...$listParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$employees = [];
while ($row = mysqli_fetch_assoc($result)) {
    $employees[] = $row;
}
mysqli_stmt_close($stmt);

function page_url($pageNumber)
{
    $query = $_GET;
    $query["page"] = $pageNumber;

    return "employees.php?" . http_build_query($query);
}

function per_page_url($perPage)
{
    $query = $_GET;
    $query["per_page"] = $perPage;
    $query["page"] = 1;

    return "employees.php?" . http_build_query($query);
}

$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalRows);

$departmentsQ = mysqli_query($conn, "SELECT id, department_name FROM master_departments WHERE is_active = 1 ORDER BY department_name ASC");

$stats = [
    "total" => 0,
    "active" => 0,
    "inactive" => 0,
    "resigned" => 0
];

$statsQ = mysqli_query($conn, "
    SELECT employee_status, COUNT(*) AS total
    FROM employees
    GROUP BY employee_status
");

while ($s = mysqli_fetch_assoc($statsQ)) {
    $status = strtolower($s["employee_status"] ?? "");
    $count = (int)$s["total"];
    $stats["total"] += $count;

    if (isset($stats[$status])) {
        $stats[$status] = $count;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Employees - TEK-C PMC Construction</title>
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

        .employee-avatar {
            width: 42px;
            height: 42px;
            min-width: 42px;
            border-radius: 16px;
            object-fit: cover;
            background: rgba(148, 163, 184, .14);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            font-weight: 900;
        }

        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 14px;
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


        .pagination-wrap {
            border-top: 1px solid var(--border-soft);
            padding: 14px 16px;
            background: rgba(148, 163, 184, .04);
        }

        .page-link-custom {
            min-width: 36px;
            height: 36px;
            border-radius: 13px;
            border: 1px solid var(--border-soft);
            background: var(--card-bg);
            color: var(--text-main);
            font-size: 12px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            padding: 0 10px;
        }

        .page-link-custom:hover {
            color: var(--text-main);
            background: rgba(148, 163, 184, .10);
        }

        .page-link-custom.active {
            color: #fff;
            background-image: linear-gradient(135deg, var(--brand-1), var(--brand-2));
            border-color: transparent;
        }

        .page-link-custom.disabled {
            opacity: .45;
            pointer-events: none;
        }

        .per-page-select {
            width: 88px;
            min-height: 36px;
            border-radius: 13px;
            font-size: 12px;
            font-weight: 800;
        }

        .delete-warning-box {
            border: 1px solid rgba(239, 68, 68, .30);
            background: rgba(239, 68, 68, .08);
            border-radius: 18px;
            padding: 14px;
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
                            <h1 class="h4 fw-bold mb-1">Employees</h1>
                            <p class="text-muted-custom mb-0 small">Manage employees, department, designation, reporting manager and documents.</p>
                        </div>
                        <?php if ($canEmployeeCreate): ?>
                            <a href="employee-add.php" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3">
                                <i data-lucide="user-plus" style="width:15px;height:15px;"></i> Add Employee
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-3 mb-3 kpi-row">
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                                <i data-lucide="users" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Total Employees <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["total"] ?></p>
                                <p class="kpi-sub"><span>↑ <?= (int)$stats["total"] ?></span> employee records</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-success-subtle text-success">
                                <i data-lucide="check-circle-2" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Active <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["active"] ?></p>
                                <p class="kpi-sub"><span>↑ <?= (int)$stats["active"] ?></span> working now</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                                <i data-lucide="pause-circle" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Inactive <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["inactive"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["inactive"] ?></span> disabled records</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-warning-subtle text-warning">
                                <i data-lucide="log-out" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Resigned <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["resigned"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["resigned"] ?></span> old employees</p>
                            </div>
                        </article>
                    </div>
                </div>

                <form method="GET" class="filter-card mb-3">
                    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-5">
                            <label class="form-label fw-bold small">Search</label>
                            <input type="text" name="search" class="form-control rounded-4" value="<?= e($search) ?>" placeholder="Search name, code, email, mobile">
                        </div>

                        <div class="col-12 col-sm-6 col-lg-3">
                            <label class="form-label fw-bold small">Department</label>
                            <select name="department_id" class="form-select rounded-4">
                                <option value="0">All Departments</option>
                                <?php while ($d = mysqli_fetch_assoc($departmentsQ)): ?>
                                    <option value="<?= (int)$d["id"] ?>" <?= $departmentFilter === (int)$d["id"] ? "selected" : "" ?>>
                                        <?= e($d["department_name"]) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-2">
                            <label class="form-label fw-bold small">Status</label>
                            <select name="status" class="form-select rounded-4">
                                <option value="">All</option>
                                <option value="active" <?= $statusFilter === "active" ? "selected" : "" ?>>Active</option>
                                <option value="inactive" <?= $statusFilter === "inactive" ? "selected" : "" ?>>Inactive</option>
                                <option value="resigned" <?= $statusFilter === "resigned" ? "selected" : "" ?>>Resigned</option>
                            </select>
                        </div>

                        <div class="col-12 col-lg-2 d-flex gap-2">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button>
                            <a href="employees.php" class="btn btn-outline-secondary rounded-4 fw-bold">Reset</a>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-3">
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">Manage Employees</h2>
                            <p class="text-muted-custom small mb-0">Add, edit and delete employee records.</p>
                        </div>
                    </div>

                    <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                        <table class="project-table w-100">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Contact</th>
                                    <th>Department</th>
                                    <th>Designation</th>
                                    <th>Reporting To</th>
                                    <th>Joining</th>
                                    <th>Status</th>
                                    <th style="width:250px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employees as $emp): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <?php if (!empty($emp["photo"])): ?>
                                                    <img src="<?= e($emp["photo"]) ?>" class="employee-avatar" alt="">
                                                <?php else: ?>
                                                    <div class="employee-avatar"><?= e(strtoupper(substr($emp["full_name"], 0, 1))) ?></div>
                                                <?php endif; ?>

                                                <div>
                                                    <div class="fw-bold"><?= e($emp["full_name"]) ?></div>
                                                    <small class="text-muted-custom"><?= e($emp["employee_code"]) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-bold small"><?= e($emp["mobile_number"]) ?></div>
                                            <small class="text-muted-custom"><?= e($emp["email"]) ?></small>
                                        </td>
                                        <td><?= e($emp["department_name"] ?? "-") ?></td>
                                        <td><?= e($emp["role_name"] ?? "-") ?></td>
                                        <td><?= e($emp["reporting_to_name"] ?? $emp["reporting_manager"] ?? "-") ?></td>
                                        <td><?= e($emp["date_of_joining"] ?: "-") ?></td>
                                        <td>
                                            <span class="pill <?= $emp["employee_status"] === "active" ? "green" : "amber" ?>">
                                                <?= e(ucfirst($emp["employee_status"])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="employee-view.php?id=<?= (int)$emp["id"] ?>" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold">View</a>

                                            <?php if ($canEmployeeEdit): ?>
                                                <a href="employee-edit.php?id=<?= (int)$emp["id"] ?>" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">Edit</a>
                                            <?php endif; ?>

                                            <?php if ($canEmployeeDelete): ?>
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deleteEmployeeModal"
                                                    onclick='openDeleteEmployeeModal(<?= json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Delete</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($employees)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted-custom py-4">No employees found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3">
                        <?php foreach ($employees as $emp): ?>
                            <article class="mobile-project-card">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                    <div>
                                        <p class="fw-bold small mb-1"><?= e($emp["full_name"]) ?></p>
                                        <small class="text-muted-custom"><?= e($emp["employee_code"]) ?></small>
                                    </div>
                                    <span class="pill <?= $emp["employee_status"] === "active" ? "green" : "amber" ?>">
                                        <?= e(ucfirst($emp["employee_status"])) ?>
                                    </span>
                                </div>

                                <div class="mobile-info"><span>Mobile</span><span><?= e($emp["mobile_number"]) ?></span></div>
                                <div class="mobile-info"><span>Department</span><span><?= e($emp["department_name"] ?? "-") ?></span></div>
                                <div class="mobile-info"><span>Designation</span><span><?= e($emp["role_name"] ?? "-") ?></span></div>

                                <div class="mt-3 d-flex flex-wrap gap-2">
                                    <a href="employee-view.php?id=<?= (int)$emp["id"] ?>" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold">View</a>

                                    <?php if ($canEmployeeEdit): ?>
                                        <a href="employee-edit.php?id=<?= (int)$emp["id"] ?>" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">Edit</a>
                                    <?php endif; ?>

                                    <?php if ($canEmployeeDelete): ?>
                                        <button type="button"
                                            class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteEmployeeModal"
                                            onclick='openDeleteEmployeeModal(<?= json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Delete</button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="pagination-wrap">
                        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="text-muted-custom small fw-bold">
                                    Showing <?= (int)$fromRow ?> to <?= (int)$toRow ?> of <?= (int)$totalRows ?> employees
                                </span>

                                <select class="form-select per-page-select" onchange="window.location.href=this.value">
                                    <?php foreach ([10, 25, 50, 100] as $limit): ?>
                                        <option value="<?= e(per_page_url($limit)) ?>" <?= $perPage === $limit ? "selected" : "" ?>>
                                            <?= (int)$limit ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <a class="page-link-custom <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(page_url(1)) ?>">
                                    First
                                </a>
                                <a class="page-link-custom <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(page_url(max(1, $page - 1))) ?>">
                                    <i data-lucide="chevron-left" style="width:15px;height:15px;"></i>
                                </a>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);

                                if ($startPage > 1):
                                ?>
                                    <a class="page-link-custom" href="<?= e(page_url(1)) ?>">1</a>
                                    <?php if ($startPage > 2): ?>
                                        <span class="page-link-custom disabled">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <a class="page-link-custom <?= $i === $page ? 'active' : '' ?>" href="<?= e(page_url($i)) ?>">
                                        <?= (int)$i ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <span class="page-link-custom disabled">...</span>
                                    <?php endif; ?>
                                    <a class="page-link-custom" href="<?= e(page_url($totalPages)) ?>"><?= (int)$totalPages ?></a>
                                <?php endif; ?>

                                <a class="page-link-custom <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(page_url(min($totalPages, $page + 1))) ?>">
                                    <i data-lucide="chevron-right" style="width:15px;height:15px;"></i>
                                </a>
                                <a class="page-link-custom <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(page_url($totalPages)) ?>">
                                    Last
                                </a>
                            </div>
                        </div>
                    </div>
                </section>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include("includes/rightsidbar.php"); ?>
    </div>

    <?php if ($canEmployeeDelete): ?>
    <div class="modal fade" id="deleteEmployeeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form action="api/delete-employee.php" method="POST" class="modal-content">
                <input type="hidden" name="employee_id" id="delete_employee_id">

                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Delete Employee</h5>
                        <p class="text-muted-custom small mb-0">This will remove the employee record.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="delete-warning-box">
                        <div class="d-flex gap-2 align-items-start">
                            <i data-lucide="triangle-alert" class="text-danger" style="width:20px;height:20px;"></i>
                            <div>
                                <p class="fw-bold mb-1" id="delete_employee_title">Delete this employee?</p>
                                <p class="text-muted-custom small mb-0">This action cannot be undone. Related project assignments may prevent deletion if this employee is already used.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-4 fw-bold px-4">Delete</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=21"></script>
    <script>
        function openDeleteEmployeeModal(emp) {
            const idInput = document.getElementById("delete_employee_id");
            const title = document.getElementById("delete_employee_title");

            if (idInput) {
                idInput.value = emp.id || "";
            }

            if (title) {
                title.textContent = "Delete " + (emp.full_name || "this employee") + "?";
            }
        }

        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
