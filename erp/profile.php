<?php
session_start();
require_once __DIR__ . "/includes/db.php";

if (empty($_SESSION["user_id"])) {
    header("Location: login.php?error=" . urlencode("Please login first."));
    exit;
}

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function show_date($date)
{
    return ($date && $date !== "0000-00-00") ? date("d M Y", strtotime($date)) : "-";
}

function time_show($dateTime)
{
    return ($dateTime && $dateTime !== "0000-00-00 00:00:00") ? date("h:i A", strtotime($dateTime)) : "-";
}

function status_class($status)
{
    $status = strtolower(trim($status ?? ""));

    if (in_array($status, ["approved", "present", "active", "completed"], true)) {
        return "green";
    }

    if (in_array($status, ["pending", "ongoing", "draft"], true)) {
        return "amber";
    }

    if (in_array($status, ["rejected", "absent", "inactive", "cancelled", "resigned", "terminated"], true)) {
        return "red";
    }

    return "blue";
}

function table_exists_profile($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $q && mysqli_num_rows($q) > 0;
}

function column_exists_profile($conn, $table, $column)
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
    $pageMessageText = "Profile updated successfully.";
} elseif (isset($_GET["password_updated"])) {
    $pageMessageType = "success";
    $pageMessageText = "Password updated successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$userId = (int)$_SESSION["user_id"];

$stmt = mysqli_prepare($conn, "
    SELECT
        u.*,
        e.id AS employee_id,
        e.full_name,
        e.employee_code,
        e.photo AS employee_photo,
        e.date_of_birth,
        e.gender,
        e.blood_group,
        e.mobile_number AS employee_mobile,
        e.email AS employee_email,
        e.current_address,
        e.emergency_contact_name,
        e.emergency_contact_phone,
        e.date_of_joining,
        e.department_id,
        e.office_location_id,
        e.role_id AS employee_role_id,
        e.reporting_to,
        e.reporting_manager,
        e.work_location,
        e.site_name,
        e.employee_status,
        e.aadhar_card_number,
        e.pancard_number,
        e.bank_account_number,
        e.ifsc_code,
        e.passbook_photo,
        d.department_name,
        r.role_name,
        ol.location_name AS office_location_name,
        ol.location_code AS office_location_code,
        ol.address AS office_address,
        ol.city AS office_city,
        ol.state AS office_state,
        ol.pincode AS office_pincode,
        ol.latitude AS office_latitude,
        ol.longitude AS office_longitude,
        ol.location_radius AS office_location_radius,
        mgr.full_name AS reporting_manager_name,
        mgr.employee_code AS reporting_manager_code
    FROM users u
    LEFT JOIN employees e ON e.id = u.employee_id
    LEFT JOIN master_departments d ON d.id = e.department_id
    LEFT JOIN roles r ON r.id = e.role_id
    LEFT JOIN master_office_locations ol ON ol.id = e.office_location_id
    LEFT JOIN employees mgr ON mgr.id = e.reporting_to
    WHERE u.id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$profile) {
    header("Location: logout.php");
    exit;
}

$userRoles = [];
if (table_exists_profile($conn, "user_roles")) {
    $q = mysqli_query($conn, "
        SELECT r.role_name, r.role_slug
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = " . (int)$userId . "
        ORDER BY r.role_name ASC
    ");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $userRoles[] = $row;
        }
    }
}

$assignedProjects = [];
if (!empty($profile["employee_id"]) && table_exists_profile($conn, "project_assignments")) {
    $employeeId = (int)$profile["employee_id"];
    $q = mysqli_query($conn, "
        SELECT
            p.id,
            p.project_name,
            p.project_code,
            p.project_location,
            pa.status,
            pa.assigned_from,
            pa.assigned_to,
            par.role_name AS assignment_role
        FROM project_assignments pa
        INNER JOIN projects p ON p.id = pa.project_id
        LEFT JOIN project_assignment_roles par ON par.id = pa.assignment_role_id
        WHERE pa.employee_id = $employeeId
          AND p.deleted_at IS NULL
        ORDER BY pa.status = 'active' DESC, pa.assigned_from DESC
        LIMIT 8
    ");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $assignedProjects[] = $row;
        }
    }
}

$recentAttendance = [];
if (!empty($profile["employee_id"]) && table_exists_profile($conn, "attendance_records")) {
    $employeeId = (int)$profile["employee_id"];
    $q = mysqli_query($conn, "
        SELECT
            ar.*,
            ol.location_name AS office_location_name,
            p.project_name
        FROM attendance_records ar
        LEFT JOIN master_office_locations ol ON ol.id = ar.office_location_id
        LEFT JOIN projects p ON p.id = ar.project_id
        WHERE ar.employee_id = $employeeId
        ORDER BY ar.attendance_date DESC
        LIMIT 5
    ");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $recentAttendance[] = $row;
        }
    }
}

$profilePhoto = "";
if (!empty($profile["profile_photo"])) {
    $profilePhoto = $profile["profile_photo"];
} elseif (!empty($profile["employee_photo"])) {
    $profilePhoto = $profile["employee_photo"];
}

$displayName = $profile["full_name"] ?: $profile["name"];
$displayMobile = $profile["employee_mobile"] ?: $profile["mobile_number"];
$displayEmail = $profile["employee_email"] ?: $profile["email"];
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Profile - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>

    <style>
        .page-head-card,
        .profile-card,
        .profile-section-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px;
        }

        .profile-hero {
            background: linear-gradient(135deg, rgba(255, 198, 26, .18), rgba(37, 99, 235, .08));
            border: 1px solid var(--border-soft);
            border-radius: 24px;
            padding: 20px;
            overflow: hidden;
            position: relative;
        }

        .profile-avatar {
            width: 92px;
            height: 92px;
            border-radius: 28px;
            border: 4px solid var(--card-bg);
            box-shadow: var(--shadow-card);
            object-fit: cover;
            background: rgba(148, 163, 184, .20);
        }

        .profile-avatar-fallback {
            width: 92px;
            height: 92px;
            border-radius: 28px;
            border: 4px solid var(--card-bg);
            box-shadow: var(--shadow-card);
            background: linear-gradient(135deg, var(--brand-1), var(--brand-2));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            font-weight: 900;
        }

        .detail-box {
            border: 1px solid var(--border-soft);
            background: rgba(148, 163, 184, .06);
            border-radius: 18px;
            padding: 13px;
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

        .profile-pill {
            border-radius: 999px;
            padding: 6px 11px;
            font-size: 11px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(37, 99, 235, .12);
            color: #2563eb;
        }

        .profile-pill.green {
            background: rgba(16,185,129,.15);
            color:#059669;
        }

        .profile-pill.amber {
            background: rgba(245,158,11,.15);
            color:#d97706;
        }

        .password-eye-btn {
            border-top-right-radius: 1rem !important;
            border-bottom-right-radius: 1rem !important;
            border-left: 0;
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

        .project-mini-card {
            border: 1px solid var(--border-soft);
            background: rgba(148, 163, 184, .05);
            border-radius: 18px;
            padding: 12px;
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
                            <h1 class="h4 fw-bold mb-1">My Profile</h1>
                            <p class="text-muted-custom mb-0 small">View your employee details, assigned roles, projects, attendance and update password.</p>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-primary rounded-4 fw-bold btn-sm px-3" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                                <i data-lucide="user-pen" style="width:15px;height:15px;"></i> Edit Profile
                            </button>
                            <button type="button" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3" data-bs-toggle="modal" data-bs-target="#passwordModal">
                                <i data-lucide="lock-keyhole" style="width:15px;height:15px;"></i> Update Password
                            </button>
                        </div>
                    </div>
                </div>

                <section class="profile-hero mb-3">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3">
                        <?php if ($profilePhoto): ?>
                            <img src="<?= e($profilePhoto) ?>" alt="Profile Photo" class="profile-avatar">
                        <?php else: ?>
                            <div class="profile-avatar-fallback"><?= e(strtoupper(substr($displayName ?: "U", 0, 1))) ?></div>
                        <?php endif; ?>

                        <div class="flex-grow-1">
                            <h2 class="fw-bold h4 mb-1"><?= e($displayName) ?></h2>
                            <p class="text-muted-custom fw-semibold mb-2">
                                <?= e($profile["employee_code"] ?: "USER-" . $profile["id"]) ?>
                                <?= $profile["role_name"] ? " · " . e($profile["role_name"]) : "" ?>
                                <?= $profile["department_name"] ? " · " . e($profile["department_name"]) : "" ?>
                            </p>

                            <div class="d-flex flex-wrap gap-2">
                                <span class="profile-pill green">
                                    <i data-lucide="circle-check" style="width:13px;height:13px;"></i>
                                    <?= e($profile["status"]) ?>
                                </span>

                                <?php if (!empty($profile["employee_status"])): ?>
                                    <span class="profile-pill">
                                        Employee: <?= e($profile["employee_status"]) ?>
                                    </span>
                                <?php endif; ?>

                                <?php foreach ($userRoles as $role): ?>
                                    <span class="profile-pill amber"><?= e($role["role_name"]) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="text-lg-end">
                            <p class="text-muted-custom small fw-bold mb-1">Last Login</p>
                            <p class="fw-bold mb-0"><?= !empty($profile["last_login_at"]) ? e(date("d M Y h:i A", strtotime($profile["last_login_at"]))) : "-" ?></p>
                        </div>
                    </div>
                </section>

                <div class="row g-3">
                    <div class="col-lg-8">
                        <section class="profile-section-card mb-3">
                            <h2 class="fw-bold fs-6 mb-3">Personal & Contact Details</h2>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Full Name</div>
                                        <p class="detail-value"><?= e($displayName) ?></p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Username</div>
                                        <p class="detail-value"><?= e($profile["username"]) ?></p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Email</div>
                                        <p class="detail-value"><?= e($displayEmail ?: "-") ?></p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Mobile</div>
                                        <p class="detail-value"><?= e($displayMobile ?: "-") ?></p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Date of Birth</div>
                                        <p class="detail-value"><?= e(show_date($profile["date_of_birth"] ?? "")) ?></p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Gender / Blood</div>
                                        <p class="detail-value"><?= e(($profile["gender"] ?: "-") . " / " . ($profile["blood_group"] ?: "-")) ?></p>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="detail-box">
                                        <div class="detail-label">Current Address</div>
                                        <p class="detail-value"><?= e($profile["current_address"] ?: "-") ?></p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="profile-section-card mb-3">
                            <h2 class="fw-bold fs-6 mb-3">Work Details</h2>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Employee Code</div>
                                        <p class="detail-value"><?= e($profile["employee_code"] ?: "-") ?></p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Role / Designation</div>
                                        <p class="detail-value"><?= e($profile["role_name"] ?: "-") ?></p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Department</div>
                                        <p class="detail-value"><?= e($profile["department_name"] ?: "-") ?></p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Joining Date</div>
                                        <p class="detail-value"><?= e(show_date($profile["date_of_joining"] ?? "")) ?></p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Reporting To</div>
                                        <p class="detail-value">
                                            <?= e($profile["reporting_manager_name"] ?: $profile["reporting_manager"] ?: "-") ?>
                                            <?= $profile["reporting_manager_code"] ? "(" . e($profile["reporting_manager_code"]) . ")" : "" ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="detail-box">
                                        <div class="detail-label">Work Location</div>
                                        <p class="detail-value"><?= e($profile["work_location"] ?: $profile["office_location_name"] ?: "-") ?></p>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="detail-box">
                                        <div class="detail-label">Office Location</div>
                                        <p class="detail-value">
                                            <?= e($profile["office_location_name"] ?: "-") ?>
                                            <?= $profile["office_location_code"] ? "(" . e($profile["office_location_code"]) . ")" : "" ?>
                                            <?= $profile["office_city"] ? " · " . e($profile["office_city"]) : "" ?>
                                            <?= $profile["office_location_radius"] ? " · Radius " . (int)$profile["office_location_radius"] . "m" : "" ?>
                                        </p>
                                        <?php if (!empty($profile["office_latitude"]) && !empty($profile["office_longitude"])): ?>
                                            <a target="_blank" class="small fw-bold" href="https://www.google.com/maps?q=<?= e($profile["office_latitude"]) ?>,<?= e($profile["office_longitude"]) ?>">
                                                Open Office Location in Google Maps
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="profile-section-card mb-3">
                            <h2 class="fw-bold fs-6 mb-3">Assigned Projects</h2>
                            <div class="row g-3">
                                <?php foreach ($assignedProjects as $project): ?>
                                    <div class="col-md-6">
                                        <div class="project-mini-card">
                                            <div class="d-flex justify-content-between align-items-start gap-2">
                                                <div>
                                                    <p class="fw-bold small mb-1"><?= e($project["project_name"]) ?></p>
                                                    <small class="text-muted-custom"><?= e($project["project_code"] ?: "-") ?></small>
                                                </div>
                                                <span class="profile-pill <?= $project["status"] === "active" ? "green" : "amber" ?>"><?= e($project["status"]) ?></span>
                                            </div>
                                            <hr class="my-2">
                                            <small class="text-muted-custom d-block">Role: <?= e($project["assignment_role"] ?: "-") ?></small>
                                            <small class="text-muted-custom d-block">From: <?= e(show_date($project["assigned_from"])) ?><?= $project["assigned_to"] ? " to " . e(show_date($project["assigned_to"])) : "" ?></small>
                                            <small class="text-muted-custom d-block">Location: <?= e($project["project_location"] ?: "-") ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <?php if (empty($assignedProjects)): ?>
                                    <div class="col-12">
                                        <p class="text-muted-custom small fw-semibold mb-0">No project assignments found.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>

                    <div class="col-lg-4">
                        <section class="profile-section-card mb-3">
                            <h2 class="fw-bold fs-6 mb-3">Emergency & KYC</h2>
                            <div class="d-grid gap-3">
                                <div class="detail-box">
                                    <div class="detail-label">Emergency Contact</div>
                                    <p class="detail-value"><?= e($profile["emergency_contact_name"] ?: "-") ?></p>
                                    <small class="text-muted-custom fw-semibold"><?= e($profile["emergency_contact_phone"] ?: "-") ?></small>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Aadhar Number</div>
                                    <p class="detail-value"><?= e($profile["aadhar_card_number"] ?: "-") ?></p>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">PAN Number</div>
                                    <p class="detail-value"><?= e($profile["pancard_number"] ?: "-") ?></p>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Bank / IFSC</div>
                                    <p class="detail-value"><?= e($profile["bank_account_number"] ?: "-") ?></p>
                                    <small class="text-muted-custom fw-semibold"><?= e($profile["ifsc_code"] ?: "-") ?></small>
                                </div>
                            </div>
                        </section>

                        <section class="profile-section-card">
                            <h2 class="fw-bold fs-6 mb-3">Recent Attendance</h2>
                            <div class="d-grid gap-2">
                                <?php foreach ($recentAttendance as $att): ?>
                                    <div class="detail-box">
                                        <div class="d-flex justify-content-between align-items-center gap-2">
                                            <p class="detail-value"><?= e(show_date($att["attendance_date"])) ?></p>
                                            <span class="profile-pill <?= status_class($att["approval_status"]) ?>"><?= e($att["location_type"]) ?></span>
                                        </div>
                                        <small class="text-muted-custom fw-semibold d-block">
                                            In: <?= e(time_show($att["punch_in_at"])) ?> · Out: <?= e(time_show($att["punch_out_at"])) ?>
                                        </small>
                                        <small class="text-muted-custom fw-semibold d-block">
                                            <?= e($att["office_location_name"] ?: $att["project_name"] ?: $att["address"] ?: "-") ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>

                                <?php if (empty($recentAttendance)): ?>
                                    <p class="text-muted-custom small fw-semibold mb-0">No recent attendance found.</p>
                                <?php endif; ?>
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

    <div class="modal fade" id="editProfileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form action="api/process-profile.php" method="POST" enctype="multipart/form-data" class="modal-content">
                <input type="hidden" name="action" value="update_profile">

                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Edit Profile</h5>
                        <p class="text-muted-custom small mb-0">Update your basic contact details and profile photo.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control rounded-4" value="<?= e($displayName) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Email</label>
                            <input type="email" name="email" class="form-control rounded-4" value="<?= e($displayEmail) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Mobile Number</label>
                            <input type="text" name="mobile_number" class="form-control rounded-4" value="<?= e($displayMobile) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Profile Photo</label>
                            <input type="file" name="profile_photo" class="form-control rounded-4" accept="image/*">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold small">Current Address</label>
                            <textarea name="current_address" class="form-control rounded-4" rows="3"><?= e($profile["current_address"]) ?></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Emergency Contact Name</label>
                            <input type="text" name="emergency_contact_name" class="form-control rounded-4" value="<?= e($profile["emergency_contact_name"]) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Emergency Contact Phone</label>
                            <input type="text" name="emergency_contact_phone" class="form-control rounded-4" value="<?= e($profile["emergency_contact_phone"]) ?>">
                        </div>
                    </div>
                </div>

                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Update Profile</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="passwordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form action="api/process-profile.php" method="POST" class="modal-content" id="passwordForm">
                <input type="hidden" name="action" value="update_password">

                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Update Password</h5>
                        <p class="text-muted-custom small mb-0">Use a strong password with at least 8 characters.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Current Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="current_password" id="current_password" class="form-control rounded-start-4" required>
                            <button type="button" class="btn btn-outline-secondary password-eye-btn" onclick="togglePassword('current_password', this)">
                                <i data-lucide="eye" style="width:16px;height:16px;"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="new_password" class="form-control rounded-start-4" minlength="8" required>
                            <button type="button" class="btn btn-outline-secondary password-eye-btn" onclick="togglePassword('new_password', this)">
                                <i data-lucide="eye" style="width:16px;height:16px;"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="form-label fw-bold small">Confirm New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control rounded-start-4" minlength="8" required>
                            <button type="button" class="btn btn-outline-secondary password-eye-btn" onclick="togglePassword('confirm_password', this)">
                                <i data-lucide="eye" style="width:16px;height:16px;"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Update Password</button>
                </div>
            </form>
        </div>
    </div>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=95"></script>

    <script>
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;

            input.type = input.type === "password" ? "text" : "password";

            if (window.lucide && typeof window.lucide.createIcons === "function") {
                const icon = btn.querySelector("i");
                if (icon) {
                    icon.setAttribute("data-lucide", input.type === "password" ? "eye" : "eye-off");
                    window.lucide.createIcons();
                }
            }
        }

        document.getElementById("passwordForm")?.addEventListener("submit", function (event) {
            const newPass = document.getElementById("new_password").value;
            const confirmPass = document.getElementById("confirm_password").value;

            if (newPass.length < 8) {
                event.preventDefault();
                alert("New password must be at least 8 characters.");
                return;
            }

            if (newPass !== confirmPass) {
                event.preventDefault();
                alert("New password and confirm password do not match.");
            }
        });

        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
