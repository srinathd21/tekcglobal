<?php
require_once __DIR__ . "/../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../projects.php");
    exit;
}

function redirect_project_error($message, $projectId = 0)
{
    $target = $projectId > 0 ? "../project-edit.php?id=" . (int)$projectId : "../project-add.php";
    $separator = strpos($target, "?") !== false ? "&" : "?";
    header("Location: " . $target . $separator . "error=" . urlencode($message));
    exit;
}

function clean_project($value) { return trim($value ?? ""); }
function nullable_int_project($value) { return ($value === "" || $value === null) ? null : (int)$value; }
function nullable_decimal_project($value) { return ($value === "" || $value === null) ? 0.00 : (float)$value; }

function upload_project_document($field, $oldPath = "")
{
    if (empty($_FILES[$field]["name"])) {
        return $oldPath;
    }

    $allowed = ["pdf", "doc", "docx", "jpg", "jpeg", "png", "webp"];
    $ext = strtolower(pathinfo($_FILES[$field]["name"], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        throw new Exception("Allowed file types: PDF, DOC, DOCX, JPG, PNG, WebP.");
    }

    if ($_FILES[$field]["size"] > 10 * 1024 * 1024) {
        throw new Exception("Contract document size must be below 10MB.");
    }

    $uploadDir = __DIR__ . "/../uploads/projects/contracts/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileName = "contract_" . time() . "_" . rand(1000, 9999) . "." . $ext;
    $target = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES[$field]["tmp_name"], $target)) {
        throw new Exception("Unable to upload contract document.");
    }

    return "uploads/projects/contracts/" . $fileName;
}

function get_assignment_role_id($conn, $roleKey)
{
    $stmt = mysqli_prepare($conn, "SELECT id FROM project_assignment_roles WHERE role_key = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $roleKey);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $row ? (int)$row["id"] : 0;
}

function sync_assignment($conn, $projectId, $employeeId, $roleId, $isPrimary, $assignedBy)
{
    if (!$employeeId || !$roleId) {
        return;
    }

    $close = mysqli_prepare($conn, "
        UPDATE project_assignments
        SET status = 'inactive', assigned_to = CURDATE(), removed_by = ?
        WHERE project_id = ? AND assignment_role_id = ? AND employee_id <> ? AND status = 'active'
    ");
    mysqli_stmt_bind_param($close, "iiii", $assignedBy, $projectId, $roleId, $employeeId);
    mysqli_stmt_execute($close);
    mysqli_stmt_close($close);

    $check = mysqli_prepare($conn, "
        SELECT id FROM project_assignments
        WHERE project_id = ? AND employee_id = ? AND assignment_role_id = ? AND status = 'active'
        LIMIT 1
    ");
    mysqli_stmt_bind_param($check, "iii", $projectId, $employeeId, $roleId);
    mysqli_stmt_execute($check);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
    mysqli_stmt_close($check);

    if ($existing) {
        return;
    }

    $insert = mysqli_prepare($conn, "
        INSERT INTO project_assignments
        (project_id, employee_id, assignment_role_id, is_primary, assigned_from, status, assigned_by)
        VALUES (?, ?, ?, ?, CURDATE(), 'active', ?)
    ");
    mysqli_stmt_bind_param($insert, "iiiii", $projectId, $employeeId, $roleId, $isPrimary, $assignedBy);
    mysqli_stmt_execute($insert);
    mysqli_stmt_close($insert);
}

$projectId = (int)($_POST["project_id"] ?? 0);

if ($projectId > 0) {
    require_permission($conn, "can_edit", "projects.php");
} else {
    require_permission($conn, "can_create", "projects.php");
}

$clientId = (int)($_POST["client_id"] ?? 0);
$projectTypeId = nullable_int_project($_POST["project_type_id"] ?? null);
$projectStatusId = nullable_int_project($_POST["project_status_id"] ?? null);
$managerEmployeeId = nullable_int_project($_POST["manager_employee_id"] ?? null);
$teamLeadEmployeeId = nullable_int_project($_POST["team_lead_employee_id"] ?? null);

$projectName = clean_project($_POST["project_name"] ?? "");
$projectCode = clean_project($_POST["project_code"] ?? "");
$projectLocation = clean_project($_POST["project_location"] ?? "");
$scopeOfWorkArray = $_POST["scope_of_work"] ?? [];
$scopeOfWork = is_array($scopeOfWorkArray) ? implode(", ", array_map("clean_project", $scopeOfWorkArray)) : "";
$contractValue = nullable_decimal_project($_POST["contract_value"] ?? 0);
$startDate = clean_project($_POST["start_date"] ?? "");
$expectedCompletionDate = clean_project($_POST["expected_completion_date"] ?? "");
$boqDetails = clean_project($_POST["boq_details"] ?? "");
$pmcCharges = nullable_decimal_project($_POST["pmc_charges"] ?? 0);
$agreementNumber = clean_project($_POST["agreement_number"] ?? "");
$agreementDate = clean_project($_POST["agreement_date"] ?? "");
$workOrderDate = clean_project($_POST["work_order_date"] ?? "");
$oldContractDocument = clean_project($_POST["old_contract_document"] ?? "");
$authorizedSignatoryName = clean_project($_POST["authorized_signatory_name"] ?? "");
$authorizedSignatoryContact = clean_project($_POST["authorized_signatory_contact"] ?? "");
$contactPersonDesignation = clean_project($_POST["contact_person_designation"] ?? "");
$contactPersonEmail = clean_project($_POST["contact_person_email"] ?? "");
$approvalAuthority = clean_project($_POST["approval_authority"] ?? "");
$siteInChargeClientSide = clean_project($_POST["site_in_charge_client_side"] ?? "");
$latitude = clean_project($_POST["latitude"] ?? "");
$longitude = clean_project($_POST["longitude"] ?? "");
$locationAddress = clean_project($_POST["location_address"] ?? "");
$placeId = clean_project($_POST["place_id"] ?? "");
$locationRadius = (int)($_POST["location_radius"] ?? 100);
$engineerIds = $_POST["project_engineer_ids"] ?? [];
$userId = $_SESSION["user_id"] ?? null;

if ($clientId <= 0 || $projectName === "" || !$projectTypeId || !$projectStatusId || $projectLocation === "" || $scopeOfWork === "" || $startDate === "" || $expectedCompletionDate === "") {
    redirect_project_error("Please fill all required project fields.", $projectId);
}

if ($expectedCompletionDate < $startDate) {
    redirect_project_error("Expected completion date cannot be earlier than start date.", $projectId);
}

if ($locationRadius < 10 || $locationRadius > 5000) {
    redirect_project_error("Employee punch-in radius must be between 10 and 5000 meters.", $projectId);
}

if ($latitude === "" || $longitude === "") {
    redirect_project_error("Please select site location on Google Map. Latitude and longitude are required for employee punch-in radius.", $projectId);
}

$latitudeValue = (float)$latitude;
$longitudeValue = (float)$longitude;

try {
    $contractDocument = upload_project_document("contract_document", $oldContractDocument);

    if ($projectId <= 0 && $contractDocument === "") {
        redirect_project_error("Contract document is required.", 0);
    }

    if ($projectCode === "") {
        $projectCode = "PRJ" . date("YmdHis");
    }

    mysqli_begin_transaction($conn);

    if ($projectId > 0) {
        $stmt = mysqli_prepare($conn, "
            UPDATE projects SET
                client_id = ?, project_type_id = ?, project_status_id = ?, manager_employee_id = ?, team_lead_employee_id = ?,
                project_name = ?, project_code = ?, project_location = ?, scope_of_work = ?, contract_value = ?,
                start_date = ?, expected_completion_date = ?, boq_details = ?, pmc_charges = ?,
                agreement_number = ?, agreement_date = ?, work_order_date = ?, contract_document = ?,
                authorized_signatory_name = ?, authorized_signatory_contact = ?, contact_person_designation = ?, contact_person_email = ?,
                approval_authority = ?, site_in_charge_client_side = ?, latitude = ?, longitude = ?, location_address = ?, place_id = ?,
                location_radius = ?, updated_by = ?
            WHERE id = ?
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "iiiiissssdsssdssssssssssddssiii",
            $clientId, $projectTypeId, $projectStatusId, $managerEmployeeId, $teamLeadEmployeeId,
            $projectName, $projectCode, $projectLocation, $scopeOfWork, $contractValue,
            $startDate, $expectedCompletionDate, $boqDetails, $pmcCharges,
            $agreementNumber, $agreementDate, $workOrderDate, $contractDocument,
            $authorizedSignatoryName, $authorizedSignatoryContact, $contactPersonDesignation, $contactPersonEmail,
            $approvalAuthority, $siteInChargeClientSide, $latitudeValue, $longitudeValue, $locationAddress, $placeId,
            $locationRadius, $userId, $projectId
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $activityType = "UPDATE";
        $description = "Updated project";
    } else {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO projects
            (client_id, project_type_id, project_status_id, manager_employee_id, team_lead_employee_id,
             project_name, project_code, project_location, scope_of_work, contract_value,
             start_date, expected_completion_date, boq_details, pmc_charges,
             agreement_number, agreement_date, work_order_date, contract_document,
             authorized_signatory_name, authorized_signatory_contact, contact_person_designation, contact_person_email,
             approval_authority, site_in_charge_client_side, latitude, longitude, location_address, place_id,
             location_radius, created_by)
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "iiiiissssdsssdssssssssssddssii",
            $clientId, $projectTypeId, $projectStatusId, $managerEmployeeId, $teamLeadEmployeeId,
            $projectName, $projectCode, $projectLocation, $scopeOfWork, $contractValue,
            $startDate, $expectedCompletionDate, $boqDetails, $pmcCharges,
            $agreementNumber, $agreementDate, $workOrderDate, $contractDocument,
            $authorizedSignatoryName, $authorizedSignatoryContact, $contactPersonDesignation, $contactPersonEmail,
            $approvalAuthority, $siteInChargeClientSide, $latitudeValue, $longitudeValue, $locationAddress, $placeId,
            $locationRadius, $userId
        );

        mysqli_stmt_execute($stmt);
        $projectId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $activityType = "CREATE";
        $description = "Created project";
    }

    $managerRoleId = get_assignment_role_id($conn, "MANAGER");
    $teamLeadRoleId = get_assignment_role_id($conn, "TEAM_LEAD");
    $engineerRoleId = get_assignment_role_id($conn, "PROJECT_ENGINEER");

    sync_assignment($conn, $projectId, $managerEmployeeId, $managerRoleId, 1, $userId);
    sync_assignment($conn, $projectId, $teamLeadEmployeeId, $teamLeadRoleId, 1, $userId);

    if ($engineerRoleId) {
        $engineerIds = is_array($engineerIds) ? array_values(array_unique(array_map("intval", $engineerIds))) : [];

        if (!empty($engineerIds)) {
            $placeholders = implode(",", array_fill(0, count($engineerIds), "?"));
            $types = str_repeat("i", count($engineerIds));
            $params = $engineerIds;

            $sql = "
                UPDATE project_assignments
                SET status = 'inactive', assigned_to = CURDATE(), removed_by = ?
                WHERE project_id = ? AND assignment_role_id = ? AND status = 'active'
                  AND employee_id NOT IN ($placeholders)
            ";

            $close = mysqli_prepare($conn, $sql);
            $bindTypes = "iii" . $types;
            $bindParams = array_merge([$userId, $projectId, $engineerRoleId], $params);
            mysqli_stmt_bind_param($close, $bindTypes, ...$bindParams);
            mysqli_stmt_execute($close);
            mysqli_stmt_close($close);

            foreach ($engineerIds as $engineerId) {
                sync_assignment($conn, $projectId, $engineerId, $engineerRoleId, 0, $userId);
            }
        } else {
            $closeAll = mysqli_prepare($conn, "
                UPDATE project_assignments
                SET status = 'inactive', assigned_to = CURDATE(), removed_by = ?
                WHERE project_id = ? AND assignment_role_id = ? AND status = 'active'
            ");
            mysqli_stmt_bind_param($closeAll, "iii", $userId, $projectId, $engineerRoleId);
            mysqli_stmt_execute($closeAll);
            mysqli_stmt_close($closeAll);
        }
    }

    $employeeId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, 'projects', ?, ?, ?)
    ");
    mysqli_stmt_bind_param($log, "issssssis", $employeeId, $employeeName, $username, $designation, $department, $activityType, $description, $projectId, $ip);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    mysqli_commit($conn);

    header("Location: ../projects.php?success=1");
    exit;
} catch (Throwable $e) {
    if (mysqli_errno($conn)) {
        mysqli_rollback($conn);
    }
    redirect_project_error("Unable to save project: " . $e->getMessage(), $projectId);
}
?>
