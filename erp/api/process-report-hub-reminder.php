<?php
session_start();
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/report-hub-api-helper.php";

$returnUrl = rh_api_return_url($_POST["return_url"] ?? "reports-hub.php");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . rh_api_return_url($_POST["return_url"] ?? "reports-hub.php"));
    exit;
}

$reportTypeId = (int)($_POST["report_type_id"] ?? 0);
$projectId = (int)($_POST["project_id"] ?? 0);
$periodStart = trim($_POST["period_start"] ?? "");
$periodEnd = trim($_POST["period_end"] ?? "");
$reportDate = trim($_POST["report_date"] ?? "");
$remarkLevel = trim($_POST["remark_level"] ?? "");
$remark = trim($_POST["remark"] ?? "");
$userId = (int)($_SESSION["user_id"] ?? 0);
$employeeId = rh_api_employee_id($conn);

if ($reportTypeId <= 0 || $projectId <= 0 || !in_array($remarkLevel, ["tl", "manager"], true) || $remark === "") {
    rh_api_redirect_with($_POST["return_url"] ?? "reports-hub.php", ["error" => "Invalid reminder details."]);
}

try {
    mysqli_begin_transaction($conn);

    $q = mysqli_query($conn, "
        SELECT
            rt.report_name,
            rt.report_code,
            p.project_name
        FROM master_report_types rt
        INNER JOIN projects p ON p.id = $projectId
        WHERE rt.id = $reportTypeId
        LIMIT 1
    ");

    $row = $q ? mysqli_fetch_assoc($q) : null;

    if (!$row) {
        throw new Exception("Report or project not found.");
    }

    $isSuperAdmin = rh_api_is_super_admin($conn, $userId);
    $hierarchy = rh_api_hierarchy($conn, $projectId);

    $accessColumn = $remarkLevel === "manager" ? "can_remark_manager" : "can_remark_tl";
    $requiredEmployee = $remarkLevel === "manager" ? (int)$hierarchy["manager"] : (int)$hierarchy["team_lead"];

    $hasAccess = $isSuperAdmin || rh_api_has_report_access($conn, $userId, $reportTypeId, $accessColumn);
    $isHierarchyPerson = (int)$requiredEmployee === (int)$employeeId;

    if (!$isSuperAdmin && (!$hasAccess || !$isHierarchyPerson)) {
        throw new Exception("You do not have access to send submit reminder for this report.");
    }

    $validTargetEmployees = [];

    $targetQ = mysqli_query($conn, "
        SELECT DISTINCT e.id
        FROM employees e
        INNER JOIN users u ON u.employee_id = e.id
        INNER JOIN user_roles ur ON ur.user_id = u.id
        INNER JOIN report_type_role_access rtra ON rtra.role_id = ur.role_id
        INNER JOIN project_assignments pa ON pa.employee_id = e.id
        WHERE rtra.report_type_id = $reportTypeId
          AND rtra.can_submit = 1
          AND pa.project_id = $projectId
          AND pa.status = 'active'
          AND e.employee_status = 'active'
    ");

    while ($targetQ && ($target = mysqli_fetch_assoc($targetQ))) {
        $validTargetEmployees[] = (int)$target["id"];
    }

    if (($remarkLevel === "manager" || $isSuperAdmin) && (int)$hierarchy["team_lead"] > 0) {
        $validTargetEmployees[] = (int)$hierarchy["team_lead"];
    }

    if ($isSuperAdmin && (int)$hierarchy["manager"] > 0) {
        $validTargetEmployees[] = (int)$hierarchy["manager"];
    }

    $validTargetEmployees = array_values(array_unique(array_filter($validTargetEmployees)));

    $selectedTargets = $_POST["target_employees"] ?? [];
    if (!is_array($selectedTargets)) {
        $selectedTargets = [];
    }

    $selectedTargets = array_values(array_unique(array_map("intval", $selectedTargets)));
    $targetEmployees = array_values(array_intersect($selectedTargets, $validTargetEmployees));

    if (empty($targetEmployees)) {
        throw new Exception("Please select at least one valid recipient.");
    }

    $periodText = ($periodStart && $periodEnd) ? "Period: $periodStart to $periodEnd" : "Date: $reportDate";
    $senderLabel = $remarkLevel === "manager" ? "Manager" : "Team Lead";
    if ($isSuperAdmin) {
        $senderLabel = $remarkLevel === "manager" ? "Super Admin as Manager" : "Super Admin as Team Lead";
    }

    $title = $senderLabel . " Submit Reminder - " . $row["report_code"];
    $message = $row["report_name"] . " is not submitted for " . $row["project_name"] . ". " . $periodText . ". " . $senderLabel . " remark: " . $remark;
    $link = "reports-hub.php?project_id=" . $projectId . "&report_date=" . urlencode($reportDate);

    $selectedTargetsJson = json_encode(array_values(array_unique($targetEmployees)));

    $stmt = mysqli_prepare($conn, "
        INSERT INTO project_report_pending_remarks
        (project_id, report_type_id, report_date, period_start, period_end, remark_by_employee_id, remark_by_user_id, remark_level, remark, target_employee_ids_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    mysqli_stmt_bind_param(
        $stmt,
        "iisssiisss",
        $projectId,
        $reportTypeId,
        $reportDate,
        $periodStart,
        $periodEnd,
        $employeeId,
        $userId,
        $remarkLevel,
        $remark,
        $selectedTargetsJson
    );

    if (!$stmt || !mysqli_stmt_execute($stmt)) {
        throw new Exception("Unable to save pending report remark: " . mysqli_error($conn));
    }

    mysqli_stmt_close($stmt);

    foreach (array_unique($targetEmployees) as $targetEmployeeId) {
        rh_api_send_notification($conn, $targetEmployeeId, $title, $message, $link, $reportTypeId, "PROJECT_REPORT_SUBMIT_REMINDER", "high", "warning");
    }

    rh_api_log($conn, "update", "Submit reminder sent for " . $row["report_code"] . " - " . $row["project_name"], $reportTypeId);

    mysqli_commit($conn);

    rh_api_redirect_with($_POST["return_url"] ?? "reports-hub.php", ["reminder" => 1]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    rh_api_redirect_with($_POST["return_url"] ?? "reports-hub.php", ["error" => $e->getMessage()]);
}
?>