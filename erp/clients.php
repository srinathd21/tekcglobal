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
    $pageMessageText = "Client saved successfully.";
} elseif (isset($_GET["deleted"])) {
    $pageMessageType = "success";
    $pageMessageText = "Client deleted successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

function table_has_column($conn, $table, $column)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && mysqli_num_rows($res) > 0;
}

$hasClientTypeId = table_has_column($conn, "clients", "client_type_id");
$hasClientTypeText = table_has_column($conn, "clients", "client_type");

$search = trim($_GET["search"] ?? "");
$clientTypeFilter = isset($_GET["client_type_id"]) ? (int)$_GET["client_type_id"] : 0;
$stateFilter = trim($_GET["state"] ?? "");

$where = ["1=1"];
$params = [];
$types = "";

if ($search !== "") {
    $where[] = "(c.client_name LIKE ? OR c.company_name LIKE ? OR c.email LIKE ? OR c.mobile_number LIKE ? OR c.gst_number LIKE ? OR c.pan_number LIKE ?)";
    $like = "%" . $search . "%";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
    }
    $types .= "ssssss";
}

if ($clientTypeFilter > 0 && $hasClientTypeId) {
    $where[] = "c.client_type_id = ?";
    $params[] = $clientTypeFilter;
    $types .= "i";
}

if ($stateFilter !== "") {
    $where[] = "c.state = ?";
    $params[] = $stateFilter;
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

$typeSelect = $hasClientTypeId ? "mct.client_type_name" : ($hasClientTypeText ? "c.client_type" : "NULL");
$typeJoin = $hasClientTypeId ? "LEFT JOIN master_client_types mct ON mct.id = c.client_type_id" : "";

$countSql = "
    SELECT COUNT(*) AS total
    FROM clients c
    $typeJoin
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
        c.*,
        $typeSelect AS client_type_name
    FROM clients c
    $typeJoin
    WHERE $whereSql
    ORDER BY c.id DESC
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

$clients = [];
while ($row = mysqli_fetch_assoc($result)) {
    $clients[] = $row;
}
mysqli_stmt_close($stmt);

function page_url($pageNumber)
{
    $query = $_GET;
    $query["page"] = $pageNumber;
    return "clients.php?" . http_build_query($query);
}

function per_page_url($perPage)
{
    $query = $_GET;
    $query["per_page"] = $perPage;
    $query["page"] = 1;
    return "clients.php?" . http_build_query($query);
}

$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalRows);

$clientTypesQ = mysqli_query($conn, "SELECT id, client_type_name FROM master_client_types WHERE is_active = 1 ORDER BY client_type_name ASC");
$statesQ = mysqli_query($conn, "SELECT DISTINCT state FROM clients WHERE state IS NOT NULL AND state <> '' ORDER BY state ASC");

$stats = [
    "total" => 0,
    "individual" => 0,
    "company" => 0,
    "with_gst" => 0
];

$stats["total"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM clients"))["total"] ?? 0);

if ($hasClientTypeId) {
    $individualQ = mysqli_query($conn, "
        SELECT COUNT(*) AS total
        FROM clients c
        JOIN master_client_types mct ON mct.id = c.client_type_id
        WHERE LOWER(mct.client_type_name) LIKE '%individual%'
    ");
    $companyQ = mysqli_query($conn, "
        SELECT COUNT(*) AS total
        FROM clients c
        JOIN master_client_types mct ON mct.id = c.client_type_id
        WHERE LOWER(mct.client_type_name) NOT LIKE '%individual%'
    ");
} else {
    $individualQ = mysqli_query($conn, "SELECT COUNT(*) AS total FROM clients WHERE LOWER(client_type) LIKE '%individual%'");
    $companyQ = mysqli_query($conn, "SELECT COUNT(*) AS total FROM clients WHERE LOWER(client_type) NOT LIKE '%individual%'");
}

$stats["individual"] = (int)(mysqli_fetch_assoc($individualQ)["total"] ?? 0);
$stats["company"] = (int)(mysqli_fetch_assoc($companyQ)["total"] ?? 0);
$stats["with_gst"] = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM clients WHERE gst_number IS NOT NULL AND gst_number <> ''"))["total"] ?? 0);
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Clients - TEK-C PMC Construction</title>
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

        .client-avatar {
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

        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 14px;
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
                            <h1 class="h4 fw-bold mb-1">Clients</h1>
                            <p class="text-muted-custom mb-0 small">Manage client contact, company, KYC, billing and site address details.</p>
                        </div>
                        <a href="client-add.php" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3">
                            <i data-lucide="user-plus" style="width:15px;height:15px;"></i> Add Client
                        </a>
                    </div>
                </div>

                <div class="row g-3 mb-3 kpi-row">
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                                <i data-lucide="users" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Total Clients <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["total"] ?></p>
                                <p class="kpi-sub"><span>↑ <?= (int)$stats["total"] ?></span> client records</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-success-subtle text-success">
                                <i data-lucide="user" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Individual <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["individual"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["individual"] ?></span> personal clients</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                                <i data-lucide="building-2" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Company Clients <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["company"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["company"] ?></span> organizations</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xxl">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-warning-subtle text-warning">
                                <i data-lucide="receipt-text" style="width:24px;height:24px;"></i>
                            </div>
                            <div>
                                <div class="kpi-label">GST Clients <i data-lucide="info" style="width:12px;height:12px;"></i></div>
                                <p class="kpi-value"><?= (int)$stats["with_gst"] ?></p>
                                <p class="kpi-sub"><span><?= (int)$stats["with_gst"] ?></span> GST available</p>
                            </div>
                        </article>
                    </div>
                </div>

                <form method="GET" class="filter-card mb-3">
                    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-4">
                            <label class="form-label fw-bold small">Search</label>
                            <input type="text" name="search" class="form-control rounded-4" value="<?= e($search) ?>" placeholder="Search name, company, email, mobile, GST, PAN">
                        </div>

                        <div class="col-12 col-sm-6 col-lg-3">
                            <label class="form-label fw-bold small">Client Type</label>
                            <select name="client_type_id" class="form-select rounded-4" <?= !$hasClientTypeId ? "disabled" : "" ?>>
                                <option value="0">All Client Types</option>
                                <?php if ($hasClientTypeId): ?>
                                    <?php while ($ct = mysqli_fetch_assoc($clientTypesQ)): ?>
                                        <option value="<?= (int)$ct["id"] ?>" <?= $clientTypeFilter === (int)$ct["id"] ? "selected" : "" ?>>
                                            <?= e($ct["client_type_name"]) ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-2">
                            <label class="form-label fw-bold small">State</label>
                            <select name="state" class="form-select rounded-4">
                                <option value="">All</option>
                                <?php while ($st = mysqli_fetch_assoc($statesQ)): ?>
                                    <option value="<?= e($st["state"]) ?>" <?= $stateFilter === $st["state"] ? "selected" : "" ?>>
                                        <?= e($st["state"]) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-12 col-lg-3 d-flex gap-2">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button>
                            <a href="clients.php" class="btn btn-outline-secondary rounded-4 fw-bold">Reset</a>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-3">
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">Manage Clients</h2>
                            <p class="text-muted-custom small mb-0">Add, view, edit and delete client records.</p>
                        </div>
                    </div>

                    <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                        <table class="project-table w-100">
                            <thead>
                                <tr>
                                    <th>Client</th>
                                    <th>Contact</th>
                                    <th>Type</th>
                                    <th>Company</th>
                                    <th>State</th>
                                    <th>GST</th>
                                    <th>Created</th>
                                    <th style="width:250px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients as $client): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="client-avatar"><?= e(strtoupper(substr($client["client_name"], 0, 1))) ?></div>
                                                <div>
                                                    <div class="fw-bold"><?= e($client["client_name"]) ?></div>
                                                    <small class="text-muted-custom">#<?= (int)$client["id"] ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-bold small"><?= e($client["mobile_number"]) ?></div>
                                            <small class="text-muted-custom"><?= e($client["email"]) ?></small>
                                        </td>
                                        <td><span class="pill green"><?= e($client["client_type_name"] ?? "-") ?></span></td>
                                        <td><?= e($client["company_name"] ?: "-") ?></td>
                                        <td><?= e($client["state"] ?: "-") ?></td>
                                        <td><?= e($client["gst_number"] ?: "-") ?></td>
                                        <td><?= e(date("d M Y", strtotime($client["created_at"]))) ?></td>
                                        <td>
                                            <a href="client-view.php?id=<?= (int)$client["id"] ?>" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold">View</a>
                                            <a href="client-edit.php?id=<?= (int)$client["id"] ?>" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">Edit</a>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteClientModal"
                                                onclick='openDeleteClientModal(<?= json_encode($client, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($clients)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted-custom py-4">No clients found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3">
                        <?php foreach ($clients as $client): ?>
                            <article class="mobile-project-card">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                    <div>
                                        <p class="fw-bold small mb-1"><?= e($client["client_name"]) ?></p>
                                        <small class="text-muted-custom"><?= e($client["company_name"] ?: $client["email"]) ?></small>
                                    </div>
                                    <span class="pill green"><?= e($client["client_type_name"] ?? "-") ?></span>
                                </div>

                                <div class="mobile-info"><span>Mobile</span><span><?= e($client["mobile_number"]) ?></span></div>
                                <div class="mobile-info"><span>State</span><span><?= e($client["state"] ?: "-") ?></span></div>
                                <div class="mobile-info"><span>GST</span><span><?= e($client["gst_number"] ?: "-") ?></span></div>

                                <div class="mt-3 d-flex flex-wrap gap-2">
                                    <a href="client-view.php?id=<?= (int)$client["id"] ?>" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold">View</a>
                                    <a href="client-edit.php?id=<?= (int)$client["id"] ?>" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">Edit</a>
                                    <button type="button"
                                        class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                                        data-bs-toggle="modal"
                                        data-bs-target="#deleteClientModal"
                                        onclick='openDeleteClientModal(<?= json_encode($client, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Delete</button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="pagination-wrap">
                        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="text-muted-custom small fw-bold">
                                    Showing <?= (int)$fromRow ?> to <?= (int)$toRow ?> of <?= (int)$totalRows ?> clients
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
                                <a class="page-link-custom <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(page_url(1)) ?>">First</a>
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
                                <a class="page-link-custom <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(page_url($totalPages)) ?>">Last</a>
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

    <div class="modal fade" id="deleteClientModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form action="api/delete-client.php" method="POST" class="modal-content">
                <input type="hidden" name="client_id" id="delete_client_id">

                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Delete Client</h5>
                        <p class="text-muted-custom small mb-0">This will remove the client record.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="delete-warning-box">
                        <div class="d-flex gap-2 align-items-start">
                            <i data-lucide="triangle-alert" class="text-danger" style="width:20px;height:20px;"></i>
                            <div>
                                <p class="fw-bold mb-1" id="delete_client_title">Delete this client?</p>
                                <p class="text-muted-custom small mb-0">This action cannot be undone. Related projects may prevent deletion if this client is already used.</p>
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

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=14"></script>
    <script>
        function openDeleteClientModal(client) {
            document.getElementById("delete_client_id").value = client.id || "";
            document.getElementById("delete_client_title").textContent = "Delete " + (client.client_name || "this client") + "?";
        }

        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
