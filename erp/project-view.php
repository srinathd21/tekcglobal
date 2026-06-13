<?php
require_once __DIR__ . "/includes/db.php";
require_permission($conn, "can_view", "projects.php");

function e($v){ return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8"); }
function money_indian_view($amount){ return "₹" . number_format((float)$amount, 2); }
function detail_value($v){ return ($v !== null && $v !== "") ? e($v) : "-"; }
function project_date_show($date){ return ($date && $date !== "0000-00-00") ? date("d M Y", strtotime($date)) : "-"; }
function pms_finish_date($project){ return !empty($project["pms_finish_date"]) ? $project["pms_finish_date"] : ($project["expected_completion_date"] ?? null); }
function pms_start_date($project){ return !empty($project["pms_start_date"]) ? $project["pms_start_date"] : ($project["start_date"] ?? null); }
function pms_timeline_label($project){ return !empty($project["pms_finish_date"]) ? "PMS Finish" : "Target Finish"; }
function pms_status_class($status){
    $status = strtolower(trim($status ?? ""));
    if ($status === "completed") return "green";
    if ($status === "ongoing" || $status === "active") return "blue";
    if ($status === "cancelled") return "red";
    return "amber";
}

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) { header("Location: projects.php?error=" . urlencode("Invalid project.")); exit; }

$stmt = mysqli_prepare($conn, "
    SELECT p.*, c.client_name, c.company_name, c.mobile_number AS client_mobile, c.email AS client_email,
           mpt.project_type_name, mps.status_name, mps.badge_class,
           manager.full_name AS manager_name, lead.full_name AS team_lead_name,
           pmc_latest.id AS pms_schedule_id,
           pmc_latest.schedule_name AS pms_schedule_name,
           pmc_latest.overall_start_date AS pms_start_date,
           pmc_latest.overall_end_date AS pms_finish_date,
           pmc_latest.overall_duration_days AS pms_duration_days,
           pmc_latest.schedule_status AS pms_schedule_status
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN master_project_types mpt ON mpt.id = p.project_type_id
    LEFT JOIN master_project_statuses mps ON mps.id = p.project_status_id
    LEFT JOIN employees manager ON manager.id = p.manager_employee_id
    LEFT JOIN employees lead ON lead.id = p.team_lead_employee_id
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
    WHERE p.id = ? AND p.deleted_at IS NULL
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$project = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$project) { header("Location: projects.php?error=" . urlencode("Project not found.")); exit; }

$teamQ = mysqli_prepare($conn, "
    SELECT pa.*, e.full_name, e.employee_code, par.role_name
    FROM project_assignments pa
    JOIN employees e ON e.id = pa.employee_id
    JOIN project_assignment_roles par ON par.id = pa.assignment_role_id
    WHERE pa.project_id = ? AND pa.status = 'active'
    ORDER BY par.sort_order ASC, e.full_name ASC
");
mysqli_stmt_bind_param($teamQ, "i", $id);
mysqli_stmt_execute($teamQ);
$teamRes = mysqli_stmt_get_result($teamQ);
$teamRows = [];
while($row=mysqli_fetch_assoc($teamRes)){ $teamRows[]=$row; }
mysqli_stmt_close($teamQ);
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>View Project - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>
    <style>
        .page-head-card,
        .profile-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px;
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

        .team-avatar {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            background: rgba(148, 163, 184, .14);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
        }

        .map-link-card {
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 14px;
            background: rgba(37, 99, 235, .06);
        }

        .pms-summary-card {
            background: linear-gradient(135deg, rgba(255, 198, 26, .16), rgba(255, 198, 26, .04));
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            padding: 16px;
            height: 100%;
        }

        .pms-badge,
        .pms-status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            max-width: 100%;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 12px;
            line-height: 1;
            font-weight: 900;
            white-space: nowrap;
            vertical-align: middle;
        }

        .pms-badge {
            color: #0f172a;
            background: rgba(148, 163, 184, .12);
            border: 1px solid var(--border-soft);
        }

        .pms-status-badge.green { background: rgba(16,185,129,.15); color: #059669; }
        .pms-status-badge.blue { background: rgba(37,99,235,.15); color: #2563eb; }
        .pms-status-badge.amber { background: rgba(245,158,11,.15); color: #d97706; }
        .pms-status-badge.red { background: rgba(239,68,68,.15); color: #dc2626; }

        .timeline-date {
            display: block;
            font-weight: 900;
            color: var(--text-main);
            line-height: 1.25;
        }

        .timeline-sub {
            display: block;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 700;
            margin-top: 3px;
            line-height: 1.25;
        }
    </style>
</head>
<body>
    <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>
    <div class="min-vh-100 d-flex">
        <?php include("includes/sidebar.php"); ?>
        <main id="main">
            <?php include("includes/nav.php"); ?>
            <section class="page-section p-3 p-lg-3">
                <div class="page-head-card mb-3"><div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3"><div><h1 class="h4 fw-bold mb-1">View Project</h1><p class="text-muted-custom mb-0 small">Project full profile and site location details.</p></div><div class="d-flex flex-wrap gap-2"><a href="projects.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm px-3">Back</a><?php if(can_edit($conn,"projects.php")): ?><a href="project-edit.php?id=<?= (int)$project["id"] ?>" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3">Edit Project</a><?php endif; ?></div></div></div>

                <section class="profile-card mb-3">
                    <div class="d-flex flex-column flex-md-row gap-3 align-items-md-center justify-content-between">
                        <div>
                            <h2 class="fw-bold h5 mb-1"><?= e($project["project_name"]) ?></h2>
                            <p class="text-muted-custom small fw-semibold mb-2">
                                <?= e($project["project_code"] ?: "PRJ-" . $project["id"]) ?> ·
                                <?= e($project["client_name"]) ?> ·
                                <?= e($project["project_type_name"]) ?>
                            </p>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="pill <?= e($project["badge_class"] ?: "green") ?>"><?= e($project["status_name"] ?: "-") ?></span>
                                <span class="pms-badge"><?= e(pms_timeline_label($project)) ?>: <?= e(project_date_show(pms_finish_date($project))) ?></span>
                                <?php if (!empty($project["pms_schedule_id"])): ?>
                                    <span class="pms-status-badge <?= e(pms_status_class($project["pms_schedule_status"])) ?>">
                                        PMS <?= e($project["pms_schedule_status"]) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-md-end">
                            <p class="fw-bold h4 mb-0"><?= money_indian_view($project["contract_value"]) ?></p>
                            <small class="text-muted-custom">Contract Value</small>
                        </div>
                    </div>
                </section>

                <section class="card-ui p-3 p-lg-4 mb-3">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-2 mb-3">
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">PMS Timeline</h2>
                            <p class="text-muted-custom small mb-0">Finish date is decided from latest PMS schedule when available.</p>
                        </div>
                        <?php if (!empty($project["pms_schedule_id"])): ?>
                            <a href="pms-detail.php?schedule_id=<?= (int)$project["pms_schedule_id"] ?>" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">
                                View PMS
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="pms-summary-card">
                                <div class="detail-label">Start Date</div>
                                <p class="detail-value"><?= e(project_date_show(pms_start_date($project))) ?></p>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="pms-summary-card">
                                <div class="detail-label"><?= e(pms_timeline_label($project)) ?></div>
                                <p class="detail-value"><?= e(project_date_show(pms_finish_date($project))) ?></p>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="pms-summary-card">
                                <div class="detail-label">Duration</div>
                                <p class="detail-value">
                                    <?= !empty($project["pms_duration_days"]) ? (int)$project["pms_duration_days"] . " days" : "-" ?>
                                </p>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="pms-summary-card">
                                <div class="detail-label">PMS Schedule</div>
                                <p class="detail-value"><?= detail_value($project["pms_schedule_name"]) ?></p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card-ui p-3 p-lg-4 mb-3"><h2 class="fw-bold fs-6 mb-3">Project Details</h2><div class="row g-3">
                    <div class="col-md-4"><div class="detail-card"><div class="detail-label">Client</div><p class="detail-value"><?= detail_value($project["client_name"]) ?></p></div></div>
                    <div class="col-md-4"><div class="detail-card"><div class="detail-label">Company</div><p class="detail-value"><?= detail_value($project["company_name"]) ?></p></div></div>
                    <div class="col-md-4"><div class="detail-card"><div class="detail-label">Project Type</div><p class="detail-value"><?= detail_value($project["project_type_name"]) ?></p></div></div>
                    <div class="col-md-4"><div class="detail-card"><div class="detail-label">Project Location</div><p class="detail-value"><?= detail_value($project["project_location"]) ?></p></div></div>
                    <div class="col-md-4"><div class="detail-card"><div class="detail-label">Project Start Date</div><p class="detail-value"><?= e(project_date_show($project["start_date"])) ?></p></div></div>
                    <div class="col-md-4"><div class="detail-card"><div class="detail-label">Expected Completion</div><p class="detail-value"><?= e(project_date_show($project["expected_completion_date"])) ?></p></div></div>
                    <div class="col-md-6"><div class="detail-card"><div class="detail-label">Scope of Work</div><p class="detail-value"><?= detail_value($project["scope_of_work"]) ?></p></div></div>
                    <div class="col-md-6"><div class="detail-card"><div class="detail-label">BOQ Details</div><p class="detail-value"><?= detail_value($project["boq_details"]) ?></p></div></div>
                </div></section>

                <section class="card-ui p-3 p-lg-4 mb-3"><h2 class="fw-bold fs-6 mb-3">Site Location</h2><div class="row g-3">
                    <div class="col-md-3"><div class="detail-card"><div class="detail-label">Latitude</div><p class="detail-value"><?= detail_value($project["latitude"]) ?></p></div></div>
                    <div class="col-md-3"><div class="detail-card"><div class="detail-label">Longitude</div><p class="detail-value"><?= detail_value($project["longitude"]) ?></p></div></div>
                    <div class="col-md-3"><div class="detail-card"><div class="detail-label">Radius</div><p class="detail-value"><?= (int)$project["location_radius"] ?> meters</p></div></div>
                    <div class="col-md-3"><div class="detail-card"><div class="detail-label">Place ID</div><p class="detail-value"><?= detail_value($project["place_id"]) ?></p></div></div>
                    <div class="col-12"><div class="map-link-card"><p class="fw-bold mb-1">Full Address</p><p class="text-muted-custom small mb-2"><?= detail_value($project["location_address"]) ?></p><?php if($project["latitude"] && $project["longitude"]): ?><a class="btn btn-sm btn-outline-primary rounded-4 fw-bold" target="_blank" href="https://www.google.com/maps?q=<?= e($project["latitude"]) ?>,<?= e($project["longitude"]) ?>">Open in Google Maps</a><?php endif; ?></div></div>
                </div></section>

                <section class="card-ui p-3 p-lg-4 mb-3"><h2 class="fw-bold fs-6 mb-3">Contract & Communication</h2><div class="row g-3">
                    <div class="col-md-4"><div class="detail-card"><div class="detail-label">Agreement No</div><p class="detail-value"><?= detail_value($project["agreement_number"]) ?></p></div></div>
                    <div class="col-md-4"><div class="detail-card"><div class="detail-label">Agreement Date</div><p class="detail-value"><?= detail_value($project["agreement_date"]) ?></p></div></div>
                    <div class="col-md-4"><div class="detail-card"><div class="detail-label">Work Order Date</div><p class="detail-value"><?= detail_value($project["work_order_date"]) ?></p></div></div>
                    <div class="col-md-4"><div class="detail-card"><div class="detail-label">PMC Charges</div><p class="detail-value"><?= money_indian_view($project["pmc_charges"]) ?></p></div></div>
                    <div class="col-md-4"><div class="detail-card"><div class="detail-label">Signatory</div><p class="detail-value"><?= detail_value($project["authorized_signatory_name"]) ?></p></div></div>
                    <div class="col-md-4"><div class="detail-card"><div class="detail-label">Contact</div><p class="detail-value"><?= detail_value($project["authorized_signatory_contact"]) ?></p></div></div>
                    <div class="col-md-6"><div class="detail-card"><div class="detail-label">Contract Document</div><p class="detail-value"><?php if($project["contract_document"]): ?><a href="<?= e($project["contract_document"]) ?>" target="_blank">Open document</a><?php else: ?>-<?php endif; ?></p></div></div>
                    <div class="col-md-6"><div class="detail-card"><div class="detail-label">Approval Authority</div><p class="detail-value"><?= detail_value($project["approval_authority"]) ?></p></div></div>
                </div></section>

                <section class="card-ui p-3 p-lg-4"><h2 class="fw-bold fs-6 mb-3">Assigned Team</h2><div class="row g-3">
                    <?php foreach($teamRows as $member): ?><div class="col-md-4"><div class="detail-card d-flex gap-2 align-items-center"><div class="team-avatar"><?= e(strtoupper(substr($member["full_name"],0,1))) ?></div><div><p class="fw-bold small mb-0"><?= e($member["full_name"]) ?></p><small class="text-muted-custom"><?= e($member["role_name"]) ?> · <?= e($member["employee_code"]) ?></small></div></div></div><?php endforeach; ?>
                    <?php if(empty($teamRows)): ?><div class="col-12 text-center text-muted-custom py-3">No team assigned.</div><?php endif; ?>
                </div></section>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>
        <div id="settingsOverlay"></div><?php include("includes/rightsidbar.php"); ?>
    </div>
    <?php include("includes/script.php") ?><script src="assets/js/script.js?v=30"></script>
</body>
</html>
