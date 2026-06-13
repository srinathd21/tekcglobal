<?php
if (!function_exists("project_e")) {
    function project_e($v)
    {
        return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
    }
}

if (!function_exists("project_selected")) {
    function project_selected($a, $b)
    {
        return (string)$a === (string)$b ? "selected" : "";
    }
}

if (!function_exists("project_checked")) {
    function project_checked($value, $array)
    {
        return in_array((int)$value, array_map("intval", $array), true) ? "checked" : "";
    }
}

$project = $project ?? [];
$selectedEngineerIds = $selectedEngineerIds ?? [];

$clientsQ = mysqli_query($conn, "
    SELECT c.id, c.client_name, c.company_name, c.mobile_number, mct.client_type_name
    FROM clients c
    LEFT JOIN master_client_types mct ON mct.id = c.client_type_id
    WHERE c.is_active = 1
    ORDER BY c.client_name ASC
");

$projectTypesQ = mysqli_query($conn, "
    SELECT id, project_type_name
    FROM master_project_types
    WHERE is_active = 1
    ORDER BY project_type_name ASC
");

$projectStatusesQ = mysqli_query($conn, "
    SELECT id, status_name, is_default, sort_order
    FROM master_project_statuses
    WHERE is_active = 1
    ORDER BY sort_order ASC, status_name ASC
");

$divisionsQ = mysqli_query($conn, "
    SELECT id, division_name, division_code
    FROM company_divisions
    WHERE is_active = 1
    ORDER BY sort_order ASC, division_name ASC
");

$managersQ = mysqli_query($conn, "
    SELECT e.id, e.full_name, e.employee_code, e.division_id, cd.division_name, r.role_name
    FROM employees e
    LEFT JOIN roles r ON r.id = e.role_id
    LEFT JOIN company_divisions cd ON cd.id = e.division_id
    WHERE e.employee_status = 'active'
      AND (LOWER(r.role_slug) LIKE '%manager%' OR LOWER(r.role_name) LIKE '%manager%')
    ORDER BY e.full_name ASC
");

$teamLeadsQ = mysqli_query($conn, "
    SELECT e.id, e.full_name, e.employee_code, e.division_id, cd.division_name, r.role_name
    FROM employees e
    LEFT JOIN roles r ON r.id = e.role_id
    LEFT JOIN company_divisions cd ON cd.id = e.division_id
    WHERE e.employee_status = 'active'
      AND (LOWER(r.role_slug) LIKE '%team-lead%' OR LOWER(r.role_name) LIKE '%team lead%' OR LOWER(r.role_name) LIKE '%tl%')
    ORDER BY e.full_name ASC
");

$engineersQ = mysqli_query($conn, "
    SELECT e.id, e.full_name, e.employee_code, e.division_id, cd.division_name, r.role_name
    FROM employees e
    LEFT JOIN roles r ON r.id = e.role_id
    LEFT JOIN company_divisions cd ON cd.id = e.division_id
    WHERE e.employee_status = 'active'
      AND (LOWER(r.role_slug) LIKE '%engineer%' OR LOWER(r.role_name) LIKE '%engineer%')
    ORDER BY e.full_name ASC
");

$scopeOptions = ["Civil", "Interior", "MEP", "Turnkey", "BOQ", "PMC", "Steel", "Finishing", "Electrical", "Plumbing"];
$selectedScopes = [];

if (!empty($project["scope_of_work"])) {
    $selectedScopes = array_map("trim", explode(",", $project["scope_of_work"]));
}

$defaultStatusId = $project["project_status_id"] ?? "";
if ($defaultStatusId === "" || $defaultStatusId === null) {
    $defaultQ = mysqli_query($conn, "SELECT id FROM master_project_statuses WHERE is_active = 1 AND is_default = 1 LIMIT 1");
    if ($defaultQ && mysqli_num_rows($defaultQ) > 0) {
        $defaultStatusId = (int)mysqli_fetch_assoc($defaultQ)["id"];
    }
}
?>

<div class="row g-3">
    <div class="col-12">
        <div class="form-section-title">
            <span class="form-section-icon"><i data-lucide="briefcase-business"></i></span>
            <div>
                <h2>Project Details</h2>
                <p>Client, project type, status, scope, value and timeline.</p>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Client <span class="text-danger">*</span></label>
        <select name="client_id" class="form-select rounded-4" required>
            <option value="">Select Client</option>
            <?php while ($client = mysqli_fetch_assoc($clientsQ)): ?>
                <?php
                $label = $client["client_name"];
                if (!empty($client["company_name"])) {
                    $label .= " - " . $client["company_name"];
                }
                if (!empty($client["mobile_number"])) {
                    $label .= " (" . $client["mobile_number"] . ")";
                }
                ?>
                <option value="<?= (int)$client["id"] ?>" <?= project_selected($project["client_id"] ?? "", $client["id"]) ?>>
                    <?= project_e($label) ?>
                </option>
            <?php endwhile; ?>
        </select>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Division <span class="text-danger">*</span></label>
        <select name="division_id" id="division_id" class="form-select rounded-4" required onchange="filterProjectTeamByDivision()">
            <option value="">Select Division</option>
            <?php if ($divisionsQ): ?>
                <?php while ($division = mysqli_fetch_assoc($divisionsQ)): ?>
                    <option value="<?= (int)$division["id"] ?>" <?= project_selected($project["division_id"] ?? "", $division["id"]) ?>>
                        <?= project_e($division["division_name"]) ?><?= $division["division_code"] ? " (" . project_e($division["division_code"]) . ")" : "" ?>
                    </option>
                <?php endwhile; ?>
            <?php endif; ?>
        </select>
        <small class="text-muted-custom fw-semibold">Manager, TL and Project Engineers will be shown under this division.</small>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Project Name <span class="text-danger">*</span></label>
        <input type="text" name="project_name" class="form-control rounded-4" required value="<?= project_e($project["project_name"] ?? "") ?>" placeholder="Enter project name">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Project Code</label>
        <input type="text" name="project_code" class="form-control rounded-4" value="<?= project_e($project["project_code"] ?? "") ?>" placeholder="Auto if empty">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Project Type <span class="text-danger">*</span></label>
        <select name="project_type_id" class="form-select rounded-4" required>
            <option value="">Select Type</option>
            <?php while ($type = mysqli_fetch_assoc($projectTypesQ)): ?>
                <option value="<?= (int)$type["id"] ?>" <?= project_selected($project["project_type_id"] ?? "", $type["id"]) ?>>
                    <?= project_e($type["project_type_name"]) ?>
                </option>
            <?php endwhile; ?>
        </select>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Project Status <span class="text-danger">*</span></label>
        <select name="project_status_id" class="form-select rounded-4" required>
            <option value="">Select Status</option>
            <?php while ($status = mysqli_fetch_assoc($projectStatusesQ)): ?>
                <option value="<?= (int)$status["id"] ?>" <?= project_selected($defaultStatusId, $status["id"]) ?>>
                    <?= project_e($status["status_name"]) ?>
                </option>
            <?php endwhile; ?>
        </select>
    </div>

    <div class="col-12">
        <label class="form-label fw-bold small">Project Location / City <span class="text-danger">*</span></label>
        <input type="text" name="project_location" id="project_location" class="form-control rounded-4" required value="<?= project_e($project["project_location"] ?? "") ?>" placeholder="City / Area / Landmark">
    </div>

    <div class="col-12">
        <label class="form-label fw-bold small">Scope of Work <span class="text-danger">*</span></label>
        <div class="scope-grid">
            <?php foreach ($scopeOptions as $scope): ?>
                <label class="scope-pill">
                    <input type="checkbox" name="scope_of_work[]" value="<?= project_e($scope) ?>" class="form-check-input" <?= in_array($scope, $selectedScopes, true) ? "checked" : "" ?>>
                    <span><?= project_e($scope) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Contract Value <span class="text-danger">*</span></label>
        <input type="number" name="contract_value" class="form-control rounded-4" required min="0" step="0.01" value="<?= project_e($project["contract_value"] ?? "") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">PMC Charges <span class="text-danger">*</span></label>
        <input type="number" name="pmc_charges" class="form-control rounded-4" required min="0" step="0.01" value="<?= project_e($project["pmc_charges"] ?? "") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Employee Punch-in Radius (meters)</label>
        <input type="number"
            name="location_radius"
            id="location_radius"
            class="form-control rounded-4"
            min="10"
            max="5000"
            step="10"
            value="<?= project_e($project["location_radius"] ?? "100") ?>">
        <small class="text-muted-custom fw-semibold">
            Employees assigned to this site can punch in only within this radius from the selected site location.
        </small>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Start Date <span class="text-danger">*</span></label>
        <input type="date" name="start_date" class="form-control rounded-4" required value="<?= project_e($project["start_date"] ?? "") ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Expected Completion Date <span class="text-danger">*</span></label>
        <input type="date" name="expected_completion_date" class="form-control rounded-4" required value="<?= project_e($project["expected_completion_date"] ?? "") ?>">
    </div>

    <div class="col-12">
        <label class="form-label fw-bold small">BOQ Details</label>
        <textarea name="boq_details" rows="3" class="form-control rounded-4" placeholder="Enter BOQ details / notes"><?= project_e($project["boq_details"] ?? "") ?></textarea>
    </div>

    <div class="col-12 pt-3">
        <div class="form-section-title">
            <span class="form-section-icon green"><i data-lucide="map-pin"></i></span>
            <div>
                <h2>Site Location & Geo-fence</h2>
                <p>Use current location, search Google Maps, or click on the map to set site coordinates and employee punch-in radius.</p>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold w-100" onclick="getCurrentLocation()">
            <i data-lucide="crosshair" style="width:16px;height:16px;"></i> Use My Current Location
        </button>
    </div>

    <div class="col-md-6">
        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold w-100" onclick="focusLocationSearch()">
            <i data-lucide="search" style="width:16px;height:16px;"></i> Search on Google Maps
        </button>
    </div>

    <div class="col-12">
        <div class="location-search-box">
            <input type="text" id="locationSearch" class="form-control rounded-4" placeholder="Search for a place or address...">
            <div id="suggestions" class="location-suggestions"></div>
        </div>
    </div>

    <div class="col-12">
        <div id="locationMap" class="project-map"></div>
    </div>

    <div class="col-md-3">
        <label class="form-label fw-bold small">Latitude</label>
        <input type="text" name="latitude" id="latitude" class="form-control rounded-4" readonly value="<?= project_e($project["latitude"] ?? "") ?>">
    </div>

    <div class="col-md-3">
        <label class="form-label fw-bold small">Longitude</label>
        <input type="text" name="longitude" id="longitude" class="form-control rounded-4" readonly value="<?= project_e($project["longitude"] ?? "") ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Google Place ID</label>
        <input type="text" name="place_id" id="place_id" class="form-control rounded-4" readonly value="<?= project_e($project["place_id"] ?? "") ?>">
    </div>

    <div class="col-12">
        <label class="form-label fw-bold small">Full Location Address</label>
        <textarea name="location_address" id="location_address" rows="2" class="form-control rounded-4"><?= project_e($project["location_address"] ?? "") ?></textarea>
    </div>

    <div class="col-12 pt-3">
        <div class="form-section-title">
            <span class="form-section-icon pink"><i data-lucide="file-text"></i></span>
            <div>
                <h2>Contract & Communication</h2>
                <p>Agreement, work order, document and client communication details.</p>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Agreement / Contract Number</label>
        <input type="text" name="agreement_number" class="form-control rounded-4" value="<?= project_e($project["agreement_number"] ?? "") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Agreement Date</label>
        <input type="date" name="agreement_date" class="form-control rounded-4" value="<?= project_e($project["agreement_date"] ?? "") ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-bold small">Work Order Date</label>
        <input type="date" name="work_order_date" class="form-control rounded-4" value="<?= project_e($project["work_order_date"] ?? "") ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Contract Document <?= empty($project["id"]) ? '<span class="text-danger">*</span>' : '' ?></label>
        <input type="file" name="contract_document" class="form-control rounded-4" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" <?= empty($project["id"]) ? "required" : "" ?>>
        <?php if (!empty($project["contract_document"])): ?>
            <small class="text-muted-custom fw-semibold">Current: <a href="<?= project_e($project["contract_document"]) ?>" target="_blank">Open document</a></small>
        <?php endif; ?>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Approval Authority</label>
        <input type="text" name="approval_authority" class="form-control rounded-4" value="<?= project_e($project["approval_authority"] ?? "") ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Authorized Signatory Name</label>
        <input type="text" name="authorized_signatory_name" class="form-control rounded-4" value="<?= project_e($project["authorized_signatory_name"] ?? "") ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Authorized Signatory Contact</label>
        <input type="text" name="authorized_signatory_contact" class="form-control rounded-4" value="<?= project_e($project["authorized_signatory_contact"] ?? "") ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Contact Person Designation</label>
        <input type="text" name="contact_person_designation" class="form-control rounded-4" value="<?= project_e($project["contact_person_designation"] ?? "") ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Contact Person Email</label>
        <input type="email" name="contact_person_email" class="form-control rounded-4" value="<?= project_e($project["contact_person_email"] ?? "") ?>">
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Site In-charge Client Side</label>
        <input type="text" name="site_in_charge_client_side" class="form-control rounded-4" value="<?= project_e($project["site_in_charge_client_side"] ?? "") ?>">
    </div>

    <div class="col-12 pt-3">
        <div class="form-section-title">
            <span class="form-section-icon purple"><i data-lucide="users"></i></span>
            <div>
                <h2>Team Assignment</h2>
                <p>Assign manager, team lead and project engineers.</p>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Project Manager</label>
        <select name="manager_employee_id" id="manager_employee_id" class="form-select rounded-4">
            <option value="">Select Manager</option>
            <?php while ($manager = mysqli_fetch_assoc($managersQ)): ?>
                <option value="<?= (int)$manager["id"] ?>"
                    data-division-id="<?= (int)($manager["division_id"] ?? 0) ?>"
                    <?= project_selected($project["manager_employee_id"] ?? "", $manager["id"]) ?>>
                    <?= project_e($manager["full_name"] . " (" . $manager["employee_code"] . ")") ?><?= $manager["division_name"] ? " - " . project_e($manager["division_name"]) : "" ?>
                </option>
            <?php endwhile; ?>
        </select>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-bold small">Team Lead</label>
        <select name="team_lead_employee_id" id="team_lead_employee_id" class="form-select rounded-4">
            <option value="">Select Team Lead</option>
            <?php while ($lead = mysqli_fetch_assoc($teamLeadsQ)): ?>
                <option value="<?= (int)$lead["id"] ?>"
                    data-division-id="<?= (int)($lead["division_id"] ?? 0) ?>"
                    <?= project_selected($project["team_lead_employee_id"] ?? "", $lead["id"]) ?>>
                    <?= project_e($lead["full_name"] . " (" . $lead["employee_code"] . ")") ?><?= $lead["division_name"] ? " - " . project_e($lead["division_name"]) : "" ?>
                </option>
            <?php endwhile; ?>
        </select>
    </div>

    <div class="col-12">
        <label class="form-label fw-bold small">Project Engineers</label>
        <div class="engineer-grid thin-scrollbar">
            <?php while ($engineer = mysqli_fetch_assoc($engineersQ)): ?>
                <label class="engineer-pill project-engineer-option" data-division-id="<?= (int)($engineer["division_id"] ?? 0) ?>">
                    <input type="checkbox" name="project_engineer_ids[]" value="<?= (int)$engineer["id"] ?>" class="form-check-input" <?= project_checked($engineer["id"], $selectedEngineerIds) ?>>
                    <span>
                        <strong><?= project_e($engineer["full_name"]) ?></strong>
                        <small><?= project_e($engineer["employee_code"]) ?><?= $engineer["division_name"] ? " - " . project_e($engineer["division_name"]) : "" ?></small>
                    </span>
                </label>
            <?php endwhile; ?>
        </div>
        <small id="engineerEmptyNote" class="text-danger fw-bold d-none mt-2">No project engineers found under selected division.</small>
    </div>
</div>

<script>
    function filterSelectByDivision(selectId, selectedDivisionId) {
        const select = document.getElementById(selectId);
        if (!select) return;

        let selectedStillVisible = false;

        Array.from(select.options).forEach(function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }

            const optionDivisionId = option.dataset.divisionId || "";
            const isVisible = selectedDivisionId !== "" && optionDivisionId === selectedDivisionId;
            option.hidden = !isVisible;

            if (isVisible && option.selected) {
                selectedStillVisible = true;
            }
        });

        if (!selectedStillVisible) {
            select.value = "";
        }
    }

    function filterProjectTeamByDivision() {
        const divisionSelect = document.getElementById("division_id");
        const selectedDivisionId = divisionSelect ? divisionSelect.value : "";

        filterSelectByDivision("manager_employee_id", selectedDivisionId);
        filterSelectByDivision("team_lead_employee_id", selectedDivisionId);

        let visibleEngineerCount = 0;

        document.querySelectorAll(".project-engineer-option").forEach(function (label) {
            const optionDivisionId = label.dataset.divisionId || "";
            const isVisible = selectedDivisionId !== "" && optionDivisionId === selectedDivisionId;

            label.classList.toggle("d-none", !isVisible);

            const checkbox = label.querySelector('input[type="checkbox"]');
            if (!isVisible && checkbox) {
                checkbox.checked = false;
            }

            if (isVisible) {
                visibleEngineerCount++;
            }
        });

        const emptyNote = document.getElementById("engineerEmptyNote");
        if (emptyNote) {
            emptyNote.classList.toggle("d-none", selectedDivisionId === "" || visibleEngineerCount > 0);
        }
    }

    document.addEventListener("DOMContentLoaded", filterProjectTeamByDivision);
</script>
