<?php
session_start();
require_once __DIR__ . "/includes/db.php";

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($id <= 0) {
    header("Location: employees.php?error=" . urlencode("Invalid employee."));
    exit;
}

$stmt = mysqli_prepare($conn, "
    SELECT
        e.*,
        d.department_name,
        r.role_name,
        manager.full_name AS reporting_to_name,
        manager.employee_code AS reporting_to_code
    FROM employees e
    LEFT JOIN master_departments d ON d.id = e.department_id
    LEFT JOIN roles r ON r.id = e.role_id
    LEFT JOIN employees manager ON manager.id = e.reporting_to
    WHERE e.id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$employee = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$employee) {
    header("Location: employees.php?error=" . urlencode("Employee not found."));
    exit;
}

$projectStmt = mysqli_prepare($conn, "
    SELECT
        pa.id AS assignment_id,
        pa.status AS assignment_status,
        pa.assigned_from,
        pa.assigned_to,
        p.id AS project_id,
        p.project_name,
        p.project_code,
        p.project_location,
        p.contract_value,
        p.start_date,
        p.expected_completion_date,
        c.client_name,
        c.company_name,
        mpt.project_type_name,
        mps.status_name,
        mps.badge_class,
        par.role_name AS assignment_role,
        pmc_latest.id AS pms_schedule_id,
        pmc_latest.schedule_name AS pms_schedule_name,
        pmc_latest.overall_start_date AS pms_start_date,
        pmc_latest.overall_end_date AS pms_finish_date,
        pmc_latest.overall_duration_days AS pms_duration_days,
        pmc_latest.schedule_status AS pms_schedule_status
    FROM project_assignments pa
    INNER JOIN projects p ON p.id = pa.project_id
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN master_project_types mpt ON mpt.id = p.project_type_id
    LEFT JOIN master_project_statuses mps ON mps.id = p.project_status_id
    LEFT JOIN project_assignment_roles par ON par.id = pa.assignment_role_id
    LEFT JOIN (
        SELECT s1.*
        FROM project_pmc_schedules s1
        INNER JOIN (
            SELECT project_id, MAX(id) AS latest_schedule_id
            FROM project_pmc_schedules
            WHERE schedule_status <> 'cancelled'
            GROUP BY project_id
        ) s2 ON s2.latest_schedule_id = s1.id
    ) pmc_latest ON pmc_latest.project_id = p.id
    WHERE pa.employee_id = ?
      AND pa.status = 'active'
      AND p.deleted_at IS NULL
    ORDER BY p.id DESC
");
mysqli_stmt_bind_param($projectStmt, "i", $id);
mysqli_stmt_execute($projectRes = $projectStmt);
$projectRes = mysqli_stmt_get_result($projectStmt);
$includedProjects = [];
while ($row = mysqli_fetch_assoc($projectRes)) {
    $includedProjects[] = $row;
}
mysqli_stmt_close($projectStmt);

$replacementStmt = mysqli_prepare($conn, "
    SELECT
        r.*,
        p.id AS project_id,
        p.project_name,
        p.project_code,
        p.project_location,
        par.role_name AS assignment_role,
        oe.full_name AS original_employee_name,
        oe.employee_code AS original_employee_code
    FROM project_assignment_replacements r
    INNER JOIN projects p ON p.id = r.project_id
    INNER JOIN project_assignment_roles par ON par.id = r.assignment_role_id
    INNER JOIN employees oe ON oe.id = r.original_employee_id
    WHERE r.replacement_employee_id = ?
      AND r.status IN ('scheduled','active')
      AND p.deleted_at IS NULL
    ORDER BY r.replacement_from ASC
");
mysqli_stmt_bind_param($replacementStmt, "i", $id);
mysqli_stmt_execute($replacementRes = $replacementStmt);
$replacementRes = mysqli_stmt_get_result($replacementStmt);
$temporaryProjects = [];
while ($row = mysqli_fetch_assoc($replacementRes)) {
    $temporaryProjects[] = $row;
}
mysqli_stmt_close($replacementStmt);

function detail_value($value)
{
    return $value !== null && $value !== "" ? e($value) : "-";
}

function date_show($date)
{
    return ($date && $date !== "0000-00-00") ? date("d M Y", strtotime($date)) : "-";
}

function money_indian_view($amount)
{
    return "₹" . number_format((float)$amount, 2);
}

function project_status_class($status)
{
    $status = strtolower(trim($status ?? ""));

    if (in_array($status, ["completed", "active", "ongoing"], true)) {
        return "green";
    }

    if (in_array($status, ["planning", "pending", "draft"], true)) {
        return "amber";
    }

    if (in_array($status, ["cancelled", "hold", "on hold"], true)) {
        return "red";
    }

    return "blue";
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>View Employee - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>

    <style>
        .page-head-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px;
        }

        .employee-profile-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 24px;
            box-shadow: var(--shadow-card);
            padding: 22px;
        }

        .employee-view-avatar {
            width: 86px;
            height: 86px;
            border-radius: 28px;
            object-fit: cover;
            background: rgba(148, 163, 184, .14);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            font-size: 32px;
            font-weight: 900;
        }

        .detail-card {
            background: rgba(148, 163, 184, .06);
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 14px;
            height: 100%;
        }

        .detail-label {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 5px;
        }

        .detail-value {
            color: var(--text-main);
            font-size: 13px;
            font-weight: 800;
            margin: 0;
            word-break: break-word;
        }

        .project-mini-card {
            background: rgba(148, 163, 184, .06);
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 14px;
            height: 100%;
        }

        .project-code-pill,
        .pms-finish-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            max-width: 100%;
            border-radius: 999px;
            padding: 5px 11px;
            font-size: 11px;
            line-height: 1;
            font-weight: 900;
            white-space: nowrap;
            vertical-align: middle;
            border: 1px solid var(--border-soft);
        }

        .project-code-pill {
            color: var(--text-main);
            background: rgba(148, 163, 184, .10);
        }

        .pms-finish-pill {
            color: #0f172a;
            background: rgba(148, 163, 184, .12);
        }

        .project-status-pill {
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            text-transform: capitalize;
        }

        .project-status-pill.green { background: rgba(16,185,129,.15); color: #059669; }
        .project-status-pill.blue { background: rgba(37,99,235,.15); color: #2563eb; }
        .project-status-pill.amber { background: rgba(245,158,11,.15); color: #d97706; }
        .project-status-pill.red { background: rgba(239,68,68,.15); color: #dc2626; }

        .project-meta-line {
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 700;
            margin: 4px 0 0;
        }

        .project-table-mini th,
        .project-table-mini td {
            vertical-align: middle;
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
                            <h1 class="h4 fw-bold mb-1">View Employee</h1>
                            <p class="text-muted-custom mb-0 small">Employee full profile and work details.</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="employees.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm px-3">Back</a>
                            <a href="employee-edit.php?id=<?= (int)$employee["id"] ?>" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3">
                                Edit Employee
                            </a>
                        </div>
                    </div>
                </div>

                <section class="employee-profile-card mb-3">
                    <div class="d-flex flex-column flex-md-row align-items-md-center gap-3">
                        <?php if (!empty($employee["photo"])): ?>
                            <img src="<?= e($employee["photo"]) ?>" class="employee-view-avatar" alt="">
                        <?php else: ?>
                            <div class="employee-view-avatar"><?= e(strtoupper(substr($employee["full_name"], 0, 1))) ?></div>
                        <?php endif; ?>

                        <div class="flex-grow-1">
                            <h2 class="fw-bold h5 mb-1"><?= e($employee["full_name"]) ?></h2>
                            <p class="text-muted-custom small fw-semibold mb-2">
                                <?= e($employee["employee_code"]) ?> · <?= detail_value($employee["role_name"] ?? "") ?> · <?= detail_value($employee["department_name"] ?? "") ?>
                            </p>
                            <span class="pill <?= $employee["employee_status"] === "active" ? "green" : "amber" ?>">
                                <?= e(ucfirst($employee["employee_status"])) ?>
                            </span>
                        </div>
                    </div>
                </section>

                <section class="card-ui p-3 p-lg-4 mb-3">
                    <h2 class="fw-bold fs-6 mb-3">Basic Details</h2>
                    <div class="row g-3">
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Mobile</div><p class="detail-value"><?= detail_value($employee["mobile_number"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Email</div><p class="detail-value"><?= detail_value($employee["email"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Date of Birth</div><p class="detail-value"><?= detail_value($employee["date_of_birth"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Gender</div><p class="detail-value"><?= detail_value($employee["gender"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Blood Group</div><p class="detail-value"><?= detail_value($employee["blood_group"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Joining Date</div><p class="detail-value"><?= detail_value($employee["date_of_joining"]) ?></p></div></div>
                        <div class="col-12"><div class="detail-card"><div class="detail-label">Current Address</div><p class="detail-value"><?= detail_value($employee["current_address"]) ?></p></div></div>
                    </div>
                </section>

                <section class="card-ui p-3 p-lg-4 mb-3">
                    <h2 class="fw-bold fs-6 mb-3">Work Details</h2>
                    <div class="row g-3">
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Department</div><p class="detail-value"><?= detail_value($employee["department_name"] ?? "") ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Designation</div><p class="detail-value"><?= detail_value($employee["role_name"] ?? "") ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Reporting To</div><p class="detail-value"><?= detail_value(($employee["reporting_to_name"] ?? "") ? $employee["reporting_to_name"] . " (" . ($employee["reporting_to_code"] ?? "") . ")" : "") ?></p></div></div>
                        <div class="col-md-6"><div class="detail-card"><div class="detail-label">Work Location</div><p class="detail-value"><?= detail_value($employee["work_location"]) ?></p></div></div>
                        <div class="col-md-6"><div class="detail-card"><div class="detail-label">Site Name</div><p class="detail-value"><?= detail_value($employee["site_name"]) ?></p></div></div>
                    </div>
                </section>

                <section class="card-ui p-3 p-lg-4 mb-3">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-2 mb-3">
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">Included Projects</h2>
                            <p class="text-muted-custom small mb-0">Projects where this employee is currently assigned.</p>
                        </div>
                        <span class="project-code-pill"><?= count($includedProjects) ?> Active Project<?= count($includedProjects) === 1 ? "" : "s" ?></span>
                    </div>

                    <div class="d-none d-md-block overflow-auto thin-scrollbar">
                        <table class="project-table project-table-mini w-100">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Client</th>
                                    <th>Role</th>
                                    <th>Value</th>
                                    <th>Timeline</th>
                                    <th>Status</th>
                                    <th style="width:140px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($includedProjects as $projectRow): ?>
                                    <?php
                                        $finishDate = !empty($projectRow["pms_finish_date"]) ? $projectRow["pms_finish_date"] : $projectRow["expected_completion_date"];
                                        $startDate = !empty($projectRow["pms_start_date"]) ? $projectRow["pms_start_date"] : $projectRow["start_date"];
                                        $finishLabel = !empty($projectRow["pms_finish_date"]) ? "PMS Finish" : "Target Finish";
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= e($projectRow["project_name"]) ?></div>
                                            <span class="project-code-pill"><?= e($projectRow["project_code"] ?: "PRJ-" . $projectRow["project_id"]) ?></span>
                                            <div class="project-meta-line"><?= e($projectRow["project_location"] ?: "-") ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?= detail_value($projectRow["client_name"]) ?></div>
                                            <small class="text-muted-custom"><?= detail_value($projectRow["company_name"]) ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?= detail_value($projectRow["assignment_role"]) ?></div>
                                            <small class="text-muted-custom">Assigned: <?= e(date_show($projectRow["assigned_from"] ?? "")) ?></small>
                                        </td>
                                        <td class="fw-bold"><?= money_indian_view($projectRow["contract_value"]) ?></td>
                                        <td>
                                            <div class="fw-bold"><?= e(date_show($startDate)) ?></div>
                                            <small class="text-muted-custom">to <?= e(date_show($finishDate)) ?></small><br>
                                            <span class="pms-finish-pill"><?= e($finishLabel) ?></span>
                                            <?php if (!empty($projectRow["pms_schedule_id"])): ?>
                                                <div class="project-meta-line">
                                                    <?= e($projectRow["pms_schedule_name"]) ?><?= !empty($projectRow["pms_duration_days"]) ? " · " . (int)$projectRow["pms_duration_days"] . " days" : "" ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="project-status-pill <?= e(project_status_class($projectRow["status_name"] ?: $projectRow["pms_schedule_status"])) ?>">
                                                <?= e($projectRow["status_name"] ?: $projectRow["pms_schedule_status"] ?: "-") ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <a href="project-view.php?id=<?= (int)$projectRow["project_id"] ?>" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">View</a>
                                                <?php if (!empty($projectRow["pms_schedule_id"])): ?>
                                                    <a href="pms-detail.php?schedule_id=<?= (int)$projectRow["pms_schedule_id"] ?>" class="btn btn-sm btn-outline-success rounded-4 fw-bold">PMS</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($includedProjects)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted-custom py-4">This employee is not assigned to any active project.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-md-none row g-3">
                        <?php foreach ($includedProjects as $projectRow): ?>
                            <?php
                                $finishDate = !empty($projectRow["pms_finish_date"]) ? $projectRow["pms_finish_date"] : $projectRow["expected_completion_date"];
                                $finishLabel = !empty($projectRow["pms_finish_date"]) ? "PMS Finish" : "Target Finish";
                            ?>
                            <div class="col-12">
                                <div class="project-mini-card">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <div>
                                            <p class="fw-bold small mb-1"><?= e($projectRow["project_name"]) ?></p>
                                            <span class="project-code-pill"><?= e($projectRow["project_code"] ?: "PRJ-" . $projectRow["project_id"]) ?></span>
                                        </div>
                                        <span class="project-status-pill <?= e(project_status_class($projectRow["status_name"] ?: $projectRow["pms_schedule_status"])) ?>">
                                            <?= e($projectRow["status_name"] ?: $projectRow["pms_schedule_status"] ?: "-") ?>
                                        </span>
                                    </div>
                                    <div class="mobile-info"><span>Role</span><span><?= detail_value($projectRow["assignment_role"]) ?></span></div>
                                    <div class="mobile-info"><span>Client</span><span><?= detail_value($projectRow["client_name"]) ?></span></div>
                                    <div class="mobile-info"><span><?= e($finishLabel) ?></span><span><?= e(date_show($finishDate)) ?></span></div>
                                    <?php if (!empty($projectRow["pms_schedule_id"])): ?>
                                        <div class="mobile-info"><span>PMS Duration</span><span><?= (int)$projectRow["pms_duration_days"] ?> days</span></div>
                                    <?php endif; ?>
                                    <div class="mt-3 d-flex flex-wrap gap-2">
                                        <a href="project-view.php?id=<?= (int)$projectRow["project_id"] ?>" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">View Project</a>
                                        <?php if (!empty($projectRow["pms_schedule_id"])): ?>
                                            <a href="pms-detail.php?schedule_id=<?= (int)$projectRow["pms_schedule_id"] ?>" class="btn btn-sm btn-outline-success rounded-4 fw-bold">View PMS</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($includedProjects)): ?>
                            <div class="col-12 text-center text-muted-custom py-3">This employee is not assigned to any active project.</div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="card-ui p-3 p-lg-4 mb-3">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-2 mb-3">
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">Temporary Replacement Projects</h2>
                            <p class="text-muted-custom small mb-0">Projects where this employee is scheduled or active as a temporary replacement.</p>
                        </div>
                        <span class="project-code-pill"><?= count($temporaryProjects) ?> Temporary</span>
                    </div>

                    <div class="row g-3">
                        <?php foreach ($temporaryProjects as $replacementProject): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="project-mini-card">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <div>
                                            <p class="fw-bold small mb-1"><?= e($replacementProject["project_name"]) ?></p>
                                            <span class="project-code-pill"><?= e($replacementProject["project_code"] ?: "PRJ-" . $replacementProject["project_id"]) ?></span>
                                        </div>
                                        <span class="project-status-pill <?= e(project_status_class($replacementProject["status"])) ?>">
                                            <?= e($replacementProject["status"]) ?>
                                        </span>
                                    </div>
                                    <p class="project-meta-line mb-2"><?= e($replacementProject["project_location"] ?: "-") ?></p>
                                    <div class="mobile-info"><span>Role</span><span><?= detail_value($replacementProject["assignment_role"]) ?></span></div>
                                    <div class="mobile-info"><span>Replacing</span><span><?= e($replacementProject["original_employee_name"]) ?> <?= $replacementProject["original_employee_code"] ? "(" . e($replacementProject["original_employee_code"]) . ")" : "" ?></span></div>
                                    <div class="mobile-info"><span>Period</span><span><?= e(date_show($replacementProject["replacement_from"])) ?> to <?= e(date_show($replacementProject["replacement_to"])) ?></span></div>
                                    <div class="mt-3">
                                        <a href="project-view.php?id=<?= (int)$replacementProject["project_id"] ?>" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">View Project</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($temporaryProjects)): ?>
                            <div class="col-12 text-center text-muted-custom py-3">No temporary replacement project found.</div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="card-ui p-3 p-lg-4">
                    <h2 class="fw-bold fs-6 mb-3">Emergency, KYC & Bank</h2>
                    <div class="row g-3">
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Emergency Contact</div><p class="detail-value"><?= detail_value($employee["emergency_contact_name"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Emergency Phone</div><p class="detail-value"><?= detail_value($employee["emergency_contact_phone"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Aadhar Number</div><p class="detail-value"><?= detail_value($employee["aadhar_card_number"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">PAN Number</div><p class="detail-value"><?= detail_value($employee["pancard_number"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Bank Account</div><p class="detail-value"><?= detail_value($employee["bank_account_number"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">IFSC Code</div><p class="detail-value"><?= detail_value($employee["ifsc_code"]) ?></p></div></div>
                        <div class="col-md-6"><div class="detail-card"><div class="detail-label">Passbook File</div><p class="detail-value">
                            <?php if (!empty($employee["passbook_photo"])): ?>
                                <a href="<?= e($employee["passbook_photo"]) ?>" target="_blank" class="fw-bold">Open File</a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </p></div></div>
                    </div>
                </section>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include("includes/rightsidbar.php"); ?>
    </div>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=13"></script>
</body>

</html>
