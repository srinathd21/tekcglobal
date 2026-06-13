<?php
session_start();
require_once __DIR__ . "/includes/db.php";

require_permission($conn, "can_view", "attendance-approvals.php");

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function date_show($date)
{
    return ($date && $date !== "0000-00-00") ? date("d M Y", strtotime($date)) : "-";
}

function time_show($dateTime)
{
    return ($dateTime && $dateTime !== "0000-00-00 00:00:00") ? date("h:i A", strtotime($dateTime)) : "-";
}

function table_exists($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $q && mysqli_num_rows($q) > 0;
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["approved"])) {
    $pageMessageType = "success";
    $pageMessageText = "Other location punch-in request approved and reflected in attendance.";
} elseif (isset($_GET["rejected"])) {
    $pageMessageType = "success";
    $pageMessageText = "Other location punch-in request rejected.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$statusFilter = trim($_GET["status"] ?? "pending");
if (!in_array($statusFilter, ["pending", "approved", "rejected", "all"], true)) {
    $statusFilter = "pending";
}

$where = "1=1";
if ($statusFilter !== "all") {
    $where = "r.status = '" . mysqli_real_escape_string($conn, $statusFilter) . "'";
}

$requests = [];
if (table_exists($conn, "attendance_other_location_requests")) {
    $q = mysqli_query($conn, "
        SELECT
            r.*,
            e.full_name,
            e.employee_code,
            e.mobile_number,
            d.department_name,
            ro.role_name,
            u.name AS approved_by_name
        FROM attendance_other_location_requests r
        INNER JOIN employees e ON e.id = r.employee_id
        LEFT JOIN master_departments d ON d.id = e.department_id
        LEFT JOIN roles ro ON ro.id = e.role_id
        LEFT JOIN users u ON u.id = r.approved_by
        WHERE $where
        ORDER BY
            CASE r.status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END,
            r.id DESC
    ");

    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $requests[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Attendance Approvals - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>

    <style>
        .page-head-card,
        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px;
        }

        .status-pill {
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            text-transform: capitalize;
        }

        .status-pill.green { background: rgba(16,185,129,.15); color:#059669; }
        .status-pill.amber { background: rgba(245,158,11,.15); color:#d97706; }
        .status-pill.red { background: rgba(239,68,68,.15); color:#dc2626; }
        .status-pill.blue { background: rgba(37,99,235,.15); color:#2563eb; }

        .approval-map {
            height: 260px;
            border-radius: 18px;
            border: 1px solid var(--border-soft);
            background: rgba(148, 163, 184, .10);
            overflow: hidden;
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
                            <h1 class="h4 fw-bold mb-1">Other Location Attendance Approvals</h1>
                            <p class="text-muted-custom mb-0 small">
                                View employee map location, approve or reject other-location punch-ins.
                            </p>
                        </div>

                        <a href="attendance.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm px-3">
                            Back to Attendance
                        </a>
                    </div>
                </div>

                <form method="GET" class="filter-card mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Status</label>
                            <select name="status" class="form-select rounded-4">
                                <option value="pending" <?= $statusFilter === "pending" ? "selected" : "" ?>>Pending</option>
                                <option value="approved" <?= $statusFilter === "approved" ? "selected" : "" ?>>Approved</option>
                                <option value="rejected" <?= $statusFilter === "rejected" ? "selected" : "" ?>>Rejected</option>
                                <option value="all" <?= $statusFilter === "all" ? "selected" : "" ?>>All</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold w-100">Filter</button>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4">
                        <h2 class="fw-bold fs-6 mb-1">Requests</h2>
                        <p class="text-muted-custom small mb-0">Pending requests become attendance only after approval.</p>
                    </div>

                    <div class="overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                        <table class="project-table w-100">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Date / Time</th>
                                    <th>Location Map</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                    <th style="width:190px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $request): ?>
                                    <?php $json = htmlspecialchars(json_encode($request, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= e($request["full_name"]) ?></div>
                                            <small class="text-muted-custom">
                                                <?= e($request["employee_code"]) ?>
                                                <?= $request["role_name"] ? " · " . e($request["role_name"]) : "" ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?= e(date_show($request["attendance_date"])) ?></div>
                                            <small class="text-muted-custom"><?= e(time_show($request["requested_punch_in_at"])) ?></small>
                                        </td>
                                        <td>
                                            <small class="text-muted-custom d-block"><?= e($request["address"] ?: "-") ?></small>
                                            <?php if (!empty($request["latitude"]) && !empty($request["longitude"])): ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-1"
                                                    data-bs-toggle="modal" data-bs-target="#mapModal"
                                                    onclick='openRequestMap(<?= $json ?>)'>
                                                    View Map
                                                </button>
                                                <a target="_blank" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold mt-1"
                                                    href="https://www.google.com/maps?q=<?= e($request["latitude"]) ?>,<?= e($request["longitude"]) ?>">
                                                    Google
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e($request["reason"]) ?></td>
                                        <td>
                                            <span class="status-pill <?= $request["status"] === "approved" ? "green" : ($request["status"] === "rejected" ? "red" : "amber") ?>">
                                                <?= e($request["status"]) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($request["status"] === "pending"): ?>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-success rounded-4 fw-bold"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#approvalModal"
                                                        onclick='openApprovalModal(<?= $json ?>, "approve")'>Approve</button>

                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#approvalModal"
                                                        onclick='openApprovalModal(<?= $json ?>, "reject")'>Reject</button>
                                                </div>
                                            <?php else: ?>
                                                <small class="text-muted-custom"><?= e($request["approval_remarks"] ?: "-") ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($requests)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted-custom py-4">No requests found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include("includes/rightsidbar.php"); ?>
    </div>

    <div class="modal fade" id="approvalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="api/process-attendance-approval.php" class="modal-content">
                <input type="hidden" name="request_id" id="request_id">
                <input type="hidden" name="approval_action" id="approval_action">

                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold" id="approval_title">Approve Request</h5>
                        <p class="text-muted-custom small mb-0" id="approval_subtitle">Review other location request.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="alert rounded-4 mb-3" id="approval_alert"></div>

                    <label class="form-label fw-bold small">Remarks</label>
                    <textarea name="approval_remarks" rows="3" class="form-control rounded-4"
                        placeholder="Optional remarks"></textarea>
                </div>

                <div class="modal-footer px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn rounded-4 fw-bold px-4" id="approval_submit">Submit</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="mapModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header px-4">
                    <div>
                        <h5 class="modal-title fw-bold">Other Location Map</h5>
                        <p class="text-muted-custom small mb-0" id="mapSubtitle">Employee current location.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="approval-map mb-3" id="requestMap"></div>
                    <div class="alert alert-info rounded-4 mb-0" id="requestMapInfo"></div>
                </div>
            </div>
        </div>
    </div>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=90"></script>

    <script>
        let requestMap;
        let requestMarker;

        function openApprovalModal(request, action) {
            document.getElementById("request_id").value = request.id || "";
            document.getElementById("approval_action").value = action;

            const title = document.getElementById("approval_title");
            const subtitle = document.getElementById("approval_subtitle");
            const alertBox = document.getElementById("approval_alert");
            const submit = document.getElementById("approval_submit");

            if (action === "approve") {
                title.textContent = "Approve Other Location Punch In";
                subtitle.textContent = "Approval will create attendance for this date.";
                alertBox.className = "alert alert-success rounded-4 mb-3";
                alertBox.textContent = "Approve request for " + (request.full_name || "employee") + "?";
                submit.className = "btn btn-success rounded-4 fw-bold px-4";
                submit.textContent = "Approve";
            } else {
                title.textContent = "Reject Other Location Punch In";
                subtitle.textContent = "Rejected request will not create attendance.";
                alertBox.className = "alert alert-danger rounded-4 mb-3";
                alertBox.textContent = "Reject request for " + (request.full_name || "employee") + "?";
                submit.className = "btn btn-danger rounded-4 fw-bold px-4";
                submit.textContent = "Reject";
            }
        }

        function openRequestMap(request) {
            const lat = parseFloat(request.latitude || "0");
            const lng = parseFloat(request.longitude || "0");
            const mapInfo = document.getElementById("requestMapInfo");
            const subtitle = document.getElementById("mapSubtitle");

            subtitle.textContent = (request.full_name || "Employee") + " - " + (request.attendance_date || "");

            setTimeout(function () {
                if (typeof google === "undefined" || !google.maps || !Number.isFinite(lat) || !Number.isFinite(lng)) {
                    mapInfo.textContent = "Unable to load map or location coordinates.";
                    return;
                }

                const pos = { lat, lng };

                if (!requestMap) {
                    requestMap = new google.maps.Map(document.getElementById("requestMap"), {
                        center: pos,
                        zoom: 16,
                        mapTypeControl: true,
                        streetViewControl: false,
                        fullscreenControl: true
                    });
                }

                if (requestMarker) requestMarker.setMap(null);

                requestMap.setCenter(pos);
                requestMap.setZoom(16);

                requestMarker = new google.maps.Marker({
                    position: pos,
                    map: requestMap,
                    title: "Other Location Punch In",
                    label: "Y"
                });

                google.maps.event.trigger(requestMap, "resize");
                requestMap.setCenter(pos);

                mapInfo.innerHTML = `
                    <b>Latitude:</b> ${lat.toFixed(8)}<br>
                    <b>Longitude:</b> ${lng.toFixed(8)}<br>
                    <b>Address:</b> ${request.address || "-"}<br>
                    <b>Reason:</b> ${request.reason || "-"}
                `;
            }, 300);
        }

        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>

    <script
        src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_API_KEY&libraries=places,geometry"
        async
        defer>
    </script>
</body>

</html>
