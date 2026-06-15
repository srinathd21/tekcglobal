<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';
date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

function dlar_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dlar_employee_id(mysqli $conn): int
{
    if (!empty($_SESSION['employee_id'])) {
        return (int)$_SESSION['employee_id'];
    }

    if (!empty($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
        $query = mysqli_query($conn, "SELECT employee_id FROM users WHERE id = $userId LIMIT 1");
        if ($query && ($row = mysqli_fetch_assoc($query)) && !empty($row['employee_id'])) {
            $_SESSION['employee_id'] = (int)$row['employee_id'];
            return (int)$row['employee_id'];
        }
    }

    return 0;
}

function dlar_is_super_admin(mysqli $conn): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    $userId = (int)$_SESSION['user_id'];
    $query = mysqli_query($conn, "
        SELECT r.id
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = $userId
          AND (r.role_slug = 'super-admin' OR LOWER(r.role_name) = 'super admin')
        LIMIT 1
    ");

    return $query && mysqli_num_rows($query) > 0;
}

function dlar_report_access(mysqli $conn, string $permission): bool
{
    if (dlar_is_super_admin($conn)) {
        return true;
    }

    $allowed = ['can_submit', 'can_view', 'can_remark_tl', 'can_remark_manager'];
    if (!in_array($permission, $allowed, true) || empty($_SESSION['user_id'])) {
        return false;
    }

    $userId = (int)$_SESSION['user_id'];
    $query = mysqli_query($conn, "
        SELECT MAX(COALESCE(rtra.$permission, 0)) AS allowed
        FROM user_roles ur
        INNER JOIN report_type_role_access rtra ON rtra.role_id = ur.role_id
        INNER JOIN master_report_types rt ON rt.id = rtra.report_type_id
        WHERE ur.user_id = $userId
          AND rt.report_code = 'DLAR'
          AND rt.is_active = 1
    ");

    return $query && ($row = mysqli_fetch_assoc($query)) && (int)($row['allowed'] ?? 0) === 1;
}

function dlar_project_allowed(mysqli $conn, int $projectId, int $employeeId, bool $isSuperAdmin): bool
{
    if ($isSuperAdmin) {
        return true;
    }

    if ($projectId <= 0 || $employeeId <= 0) {
        return false;
    }

    $query = mysqli_query($conn, "
        SELECT p.id
        FROM projects p
        WHERE p.id = $projectId
          AND p.deleted_at IS NULL
          AND (
                p.manager_employee_id = $employeeId
             OR p.team_lead_employee_id = $employeeId
             OR EXISTS (
                    SELECT 1
                    FROM project_assignments pa
                    WHERE pa.project_id = p.id
                      AND pa.employee_id = $employeeId
                      AND pa.status = 'active'
                )
          )
        LIMIT 1
    ");

    return $query && mysqli_num_rows($query) > 0;
}

function dlar_clean_rows(array $rows): array
{
    $cleaned = [];
    foreach ($rows as $row) {
        $hasData = false;
        foreach ($row as $key => $value) {
            if ($key === 'sl_no') {
                continue;
            }
            if (trim((string)$value) !== '') {
                $hasData = true;
                break;
            }
        }
        if ($hasData) {
            $cleaned[] = $row;
        }
    }
    return $cleaned;
}

function dlar_table_columns(mysqli $conn, string $table): array
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columns = [];
    $query = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $columns[$row['Field']] = true;
    }
    return $columns;
}

function dlar_sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function dlar_report_type_id(mysqli $conn): int
{
    $query = mysqli_query($conn, "SELECT id FROM master_report_types WHERE report_code = 'DLAR' LIMIT 1");
    if ($query && ($row = mysqli_fetch_assoc($query))) {
        return (int)$row['id'];
    }
    return 0;
}

function dlar_log_activity(mysqli $conn, string $type, string $description, ?int $referenceId = null): void
{
    $employeeId = dlar_employee_id($conn);
    $employeeName = (string)($_SESSION['employee_name'] ?? $_SESSION['name'] ?? '');
    $username = (string)($_SESSION['username'] ?? '');
    $designation = (string)($_SESSION['designation'] ?? '');
    $department = (string)($_SESSION['department'] ?? '');
    $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $referenceId = $referenceId ?: null;
    $type = strtoupper($type);

    $stmt = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, 'DLAR', ?, ?, ?)
    ");
    if ($stmt) {
        mysqli_stmt_bind_param(
            $stmt,
            'issssssis',
            $employeeId,
            $employeeName,
            $username,
            $designation,
            $department,
            $type,
            $description,
            $referenceId,
            $ipAddress
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function dlar_notify_employee(mysqli $conn, int $employeeId, string $title, string $message, int $referenceId, string $link): void
{
    if ($employeeId <= 0) {
        return;
    }

    $userId = 0;
    $employeeQuery = mysqli_query($conn, "SELECT user_id FROM employees WHERE id = $employeeId LIMIT 1");
    if ($employeeQuery && ($employee = mysqli_fetch_assoc($employeeQuery))) {
        $userId = (int)($employee['user_id'] ?? 0);
    }

    $reasonId = 0;
    $icon = 'file-warning';
    $color = '#f59e0b';
    $reasonQuery = mysqli_query($conn, "
        SELECT id, default_icon, default_color
        FROM master_notification_reasons
        WHERE reason_code IN ('PROJECT_REPORT_REMARK', 'PROJECT_REPORT_SUBMIT_REMINDER')
        ORDER BY FIELD(reason_code, 'PROJECT_REPORT_REMARK', 'PROJECT_REPORT_SUBMIT_REMINDER')
        LIMIT 1
    ");
    if ($reasonQuery && ($reason = mysqli_fetch_assoc($reasonQuery))) {
        $reasonId = (int)($reason['id'] ?? 0);
        $icon = (string)($reason['default_icon'] ?: $icon);
        $color = (string)($reason['default_color'] ?: $color);
    }

    $createdBy = (int)($_SESSION['employee_id'] ?? 0);
    $createdByName = (string)($_SESSION['employee_name'] ?? $_SESSION['name'] ?? '');

    $stmt = mysqli_prepare($conn, "
        INSERT INTO notifications
        (reason_id, title, message, target_type, module, reference_id, link, priority, notification_type, icon, color_code, is_system, is_active, sent_at, created_by, created_by_name)
        VALUES (?, ?, ?, 'employees', 'dlar_reports', ?, ?, 'normal', 'info', ?, ?, 1, 1, NOW(), ?, ?)
    ");
    if (!$stmt) {
        return;
    }

    mysqli_stmt_bind_param(
        $stmt,
        'ississsis',
        $reasonId,
        $title,
        $message,
        $referenceId,
        $link,
        $icon,
        $color,
        $createdBy,
        $createdByName
    );

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return;
    }

    $notificationId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    if ($notificationId <= 0) {
        return;
    }

    mysqli_query($conn, "INSERT INTO notification_employee_targets (notification_id, employee_id) VALUES ($notificationId, $employeeId)");
    if ($userId > 0) {
        mysqli_query($conn, "INSERT INTO notification_user_targets (notification_id, user_id) VALUES ($notificationId, $userId)");
        mysqli_query($conn, "INSERT INTO notification_user_status (notification_id, user_id) VALUES ($notificationId, $userId)");
    }
}

function dlar_sync_submission(mysqli $conn, int $submissionId, int $projectId, int $employeeId, string $reportDate, int $dlarId): void
{
    $reportTypeId = dlar_report_type_id($conn);
    if ($reportTypeId <= 0 || $projectId <= 0 || $employeeId <= 0 || $dlarId <= 0) {
        return;
    }

    $columns = dlar_table_columns($conn, 'project_report_submissions');
    $userId = (int)($_SESSION['user_id'] ?? 0);

    // DLAR is monthly in Reports Hub.
    $reportTimestamp = strtotime($reportDate);
    $periodStart = $reportTimestamp ? date('Y-m-01', $reportTimestamp) : $reportDate;
    $periodEnd = $reportTimestamp ? date('Y-m-t', $reportTimestamp) : $reportDate;

    $dateEsc = mysqli_real_escape_string($conn, $reportDate);
    $periodStartEsc = mysqli_real_escape_string($conn, $periodStart);
    $periodEndEsc = mysqli_real_escape_string($conn, $periodEnd);

    if ($submissionId <= 0) {
        $query = mysqli_query($conn, "
            SELECT id
            FROM project_report_submissions
            WHERE project_id = $projectId
              AND report_type_id = $reportTypeId
              AND (
                    submission_for_date = '$dateEsc'
                 OR (period_start = '$periodStartEsc' AND period_end = '$periodEndEsc')
              )
            ORDER BY id DESC
            LIMIT 1
        ");
        if ($query && ($row = mysqli_fetch_assoc($query))) {
            $submissionId = (int)$row['id'];
        }
    }

    $reportNo = 'DLAR-' . $projectId . '-' . str_replace('-', '', $reportDate) . '-' . $dlarId;

    if ($submissionId > 0) {
        $sets = [];
        $map = [
            'report_no' => $reportNo,
            'report_number' => $reportNo,
            'submission_no' => $reportNo,
            'submitted_by_employee_id' => $employeeId,
            'submitted_by_user_id' => $userId > 0 ? $userId : null,
            'submission_for_date' => $reportDate,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'submitted',
            'submitted_at' => date('Y-m-d H:i:s'),
            'reference_id' => $dlarId,
            'source_table' => 'dlar_reports',
            'source_id' => $dlarId,
            'report_reference_table' => 'dlar_reports',
            'report_reference_id' => $dlarId,
            'updated_by' => $userId > 0 ? $userId : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        foreach ($map as $field => $value) {
            if (isset($columns[$field])) {
                $sets[] = "`$field` = " . dlar_sql_value($conn, $value);
            }
        }

        if ($sets) {
            mysqli_query($conn, "UPDATE project_report_submissions SET " . implode(', ', $sets) . " WHERE id = $submissionId");
        }
        return;
    }

    $data = [
        'project_id' => $projectId,
        'report_type_id' => $reportTypeId,
        'report_no' => $reportNo,
        'report_number' => $reportNo,
        'submission_no' => $reportNo,
        'submitted_by_employee_id' => $employeeId,
        'submitted_by_user_id' => $userId > 0 ? $userId : null,
        'submission_for_date' => $reportDate,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'status' => 'submitted',
        'submitted_at' => date('Y-m-d H:i:s'),
        'reference_id' => $dlarId,
        'source_table' => 'dlar_reports',
        'source_id' => $dlarId,
        'report_reference_table' => 'dlar_reports',
        'report_reference_id' => $dlarId,
        'created_by' => $userId > 0 ? $userId : null,
        'updated_by' => $userId > 0 ? $userId : null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $insertColumns = [];
    $insertValues = [];
    foreach ($data as $field => $value) {
        if (isset($columns[$field])) {
            $insertColumns[] = "`$field`";
            $insertValues[] = dlar_sql_value($conn, $value);
        }
    }

    if ($insertColumns) {
        mysqli_query(
            $conn,
            "INSERT INTO project_report_submissions (" . implode(',', $insertColumns) . ") VALUES (" . implode(',', $insertValues) . ")"
        );
    }
}

function dlar_find_existing_report(mysqli $conn, int $submissionId, int $projectId, int $employeeId, string $reportDate): int
{
    if ($submissionId > 0) {
        $columns = dlar_table_columns($conn, 'project_report_submissions');
        $select = ['id', 'project_id', 'submitted_by_employee_id', 'submission_for_date', 'period_start'];
        foreach (['reference_id', 'source_id', 'report_reference_id'] as $column) {
            if (isset($columns[$column])) {
                $select[] = $column;
            }
        }

        $query = mysqli_query($conn, "SELECT " . implode(',', array_unique($select)) . " FROM project_report_submissions WHERE id = $submissionId LIMIT 1");
        if ($query && ($submission = mysqli_fetch_assoc($query))) {
            foreach (['report_reference_id', 'source_id', 'reference_id'] as $column) {
                if (!empty($submission[$column])) {
                    $reportId = (int)$submission[$column];
                    $reportQuery = mysqli_query($conn, "SELECT id FROM dlar_reports WHERE id = $reportId LIMIT 1");
                    if ($reportQuery && mysqli_num_rows($reportQuery) > 0) {
                        return $reportId;
                    }
                }
            }
        }
    }

    $dateEsc = mysqli_real_escape_string($conn, $reportDate);
    $query = mysqli_query($conn, "
        SELECT id
        FROM dlar_reports
        WHERE project_id = $projectId
          AND employee_id = $employeeId
          AND report_date = '$dateEsc'
        ORDER BY id DESC
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query))) ? (int)$row['id'] : 0;
}

function dlar_redirect_hub(int $projectId, string $reportDate, string $flag): void
{
    $flag = preg_replace('/[^a-zA-Z0-9_]/', '', $flag);
    $timestamp = strtotime($reportDate);
    $periodStart = $timestamp ? date('Y-m-01', $timestamp) : $reportDate;
    $periodEnd = $timestamp ? date('Y-m-t', $timestamp) : $reportDate;

    header(
        "Location: reports-hub.php"
        . "?project_id=" . $projectId
        . "&report_date=" . urlencode($reportDate)
        . "&period_start=" . urlencode($periodStart)
        . "&period_end=" . urlencode($periodEnd)
        . "&" . $flag . "=1"
    );
    exit;
}

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS dlar_reports (
        id INT NOT NULL AUTO_INCREMENT,
        project_id INT NOT NULL,
        site_id INT DEFAULT NULL,
        employee_id INT NOT NULL,
        dlar_no VARCHAR(60) NOT NULL,
        report_date DATE NOT NULL,
        report_month TINYINT NOT NULL,
        report_year SMALLINT NOT NULL,
        project_name VARCHAR(255) DEFAULT NULL,
        client_name VARCHAR(255) DEFAULT NULL,
        architect_name VARCHAR(255) DEFAULT NULL,
        pmc_name VARCHAR(255) DEFAULT NULL,
        date_version VARCHAR(100) DEFAULT NULL,
        items_json LONGTEXT DEFAULT NULL,
        prepared_by VARCHAR(150) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_dlar_project (project_id),
        KEY idx_dlar_employee (employee_id),
        KEY idx_dlar_report_date (report_date),
        KEY idx_dlar_month_year (report_month, report_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$employeeId = dlar_employee_id($conn);
$isSuperAdmin = dlar_is_super_admin($conn);

if ($employeeId <= 0) {
    header('Location: login.php');
    exit;
}

if (!dlar_report_access($conn, 'can_submit')) {
    header('Location: reports-hub.php?error=' . urlencode('You do not have DLAR submit access.'));
    exit;
}

$employee = null;
$employeeQuery = mysqli_query($conn, "SELECT * FROM employees WHERE id = $employeeId LIMIT 1");
if ($employeeQuery) {
    $employee = mysqli_fetch_assoc($employeeQuery);
}
$preparedBy = (string)($employee['full_name'] ?? $_SESSION['employee_name'] ?? $_SESSION['name'] ?? '');

$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$reportDate = trim((string)($_GET['report_date'] ?? $_POST['report_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    $reportDate = date('Y-m-d');
}
$resubmitSubmissionId = (int)($_GET['resubmit_submission_id'] ?? $_POST['resubmit_submission_id'] ?? 0);

$projects = [];
if ($isSuperAdmin) {
    $projectsQuery = mysqli_query($conn, "
        SELECT p.id, p.project_name, p.project_code, p.project_location, c.client_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        WHERE p.deleted_at IS NULL
        ORDER BY p.project_name ASC
    ");
} else {
    $projectsQuery = mysqli_query($conn, "
        SELECT DISTINCT p.id, p.project_name, p.project_code, p.project_location, c.client_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN project_assignments pa ON pa.project_id = p.id AND pa.status = 'active'
        WHERE p.deleted_at IS NULL
          AND (
                p.manager_employee_id = $employeeId
             OR p.team_lead_employee_id = $employeeId
             OR pa.employee_id = $employeeId
          )
        ORDER BY p.project_name ASC
    ");
}
while ($projectsQuery && ($projectRow = mysqli_fetch_assoc($projectsQuery))) {
    $projects[] = $projectRow;
}

$project = null;
if ($projectId > 0 && dlar_project_allowed($conn, $projectId, $employeeId, $isSuperAdmin)) {
    $projectQuery = mysqli_query($conn, "
        SELECT p.*, c.client_name, c.company_name, mpt.project_type_name,
               manager.full_name AS manager_name, team_lead_emp.full_name AS team_lead_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN master_project_types mpt ON mpt.id = p.project_type_id
        LEFT JOIN employees manager ON manager.id = p.manager_employee_id
        LEFT JOIN employees team_lead_emp ON team_lead_emp.id = p.team_lead_employee_id
        WHERE p.id = $projectId
          AND p.deleted_at IS NULL
        LIMIT 1
    ");
    if ($projectQuery) {
        $project = mysqli_fetch_assoc($projectQuery);
    }
}


$pmsSchedule = $projectId > 0 ? pms_project_schedule($conn, $projectId) : null;
[$pmsScheduleStart, $pmsScheduleEnd] = pms_schedule_date_range($pmsSchedule, $project);

$defaultDlarNo = '';
if ($projectId > 0) {
    $countQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM dlar_reports WHERE project_id = $projectId");
    $count = $countQuery ? (int)(mysqli_fetch_assoc($countQuery)['total'] ?? 0) : 0;
    $defaultDlarNo = '#DLAR-' . str_pad((string)($count + 1), 2, '0', STR_PAD_LEFT);
}

$existingReport = null;
if ($resubmitSubmissionId > 0 && $projectId > 0) {
    $existingId = dlar_find_existing_report($conn, $resubmitSubmissionId, $projectId, $employeeId, $reportDate);
    if ($existingId > 0) {
        $existingQuery = mysqli_query($conn, "SELECT * FROM dlar_reports WHERE id = $existingId LIMIT 1");
        if ($existingQuery) {
            $existingReport = mysqli_fetch_assoc($existingQuery);
        }
    }
}

$previousTemplate = null;
if ($projectId > 0) {
    $safeDate = mysqli_real_escape_string($conn, $reportDate);

    // Prefer this employee's latest DLAR before the selected date.
    $previousQuery = mysqli_query($conn, "
        SELECT *
        FROM dlar_reports
        WHERE project_id = $projectId
          AND employee_id = $employeeId
          AND report_date < '$safeDate'
        ORDER BY report_date DESC, created_at DESC, id DESC
        LIMIT 1
    ");
    if ($previousQuery) {
        $previousTemplate = mysqli_fetch_assoc($previousQuery);
    }

    // Then this employee's latest DLAR for the project.
    if (!$previousTemplate) {
        $previousQuery = mysqli_query($conn, "
            SELECT *
            FROM dlar_reports
            WHERE project_id = $projectId
              AND employee_id = $employeeId
            ORDER BY report_date DESC, created_at DESC, id DESC
            LIMIT 1
        ");
        if ($previousQuery) {
            $previousTemplate = mysqli_fetch_assoc($previousQuery);
        }
    }

    // Final project-level fallback, matching the current DAR behaviour.
    if (!$previousTemplate) {
        $previousQuery = mysqli_query($conn, "
            SELECT *
            FROM dlar_reports
            WHERE project_id = $projectId
            ORDER BY report_date DESC, created_at DESC, id DESC
            LIMIT 1
        ");
        if ($previousQuery) {
            $previousTemplate = mysqli_fetch_assoc($previousQuery);
        }
    }
}

$pageMessageType = '';
$pageMessageText = '';
if (isset($_GET['error'])) {
    $pageMessageType = 'error';
    $pageMessageText = trim((string)$_GET['error']) ?: 'Something went wrong.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dlar'])) {
    $postProjectId = (int)($_POST['project_id'] ?? 0);
    $postReportDate = trim((string)($_POST['report_date'] ?? ''));
    $postSubmissionId = (int)($_POST['resubmit_submission_id'] ?? 0);
    $dlarNo = trim((string)($_POST['dlar_no'] ?? ''));
    $dateVersion = trim((string)($_POST['date_version'] ?? ''));
    $projectName = trim((string)($_POST['project_name'] ?? ''));
    $clientName = trim((string)($_POST['client_name'] ?? ''));
    $architectName = trim((string)($_POST['architect_name'] ?? ''));
    $pmcName = trim((string)($_POST['pmc_name'] ?? ''));

    $error = '';
    if (!dlar_project_allowed($conn, $postProjectId, $employeeId, $isSuperAdmin)) {
        $error = 'Invalid project selection.';
    } elseif ($dlarNo === '') {
        $error = 'DLAR No is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postReportDate)) {
        $error = 'Valid report date is required.';
    }

    $slNos = $_POST['sl_no'] ?? [];
    $delayedTasks = $_POST['delayed_task'] ?? [];
    $plannedDates = $_POST['planned_date'] ?? [];
    $actualDates = $_POST['actual_date'] ?? [];
    $delayDays = $_POST['delay_days'] ?? [];
    $delayResponses = $_POST['delay_response_by'] ?? [];
    $issuesOpened = $_POST['issues_opened_on'] ?? [];
    $reminders = $_POST['reminders_dated'] ?? [];
    $issuesClosed = $_POST['issues_closed_on'] ?? [];

    $items = [];
    $maxRows = max(
        count($slNos), count($delayedTasks), count($plannedDates), count($actualDates),
        count($delayDays), count($delayResponses), count($issuesOpened), count($reminders), count($issuesClosed)
    );

    for ($index = 0; $index < $maxRows; $index++) {
        $planned = trim((string)($plannedDates[$index] ?? ''));
        $actual = trim((string)($actualDates[$index] ?? ''));
        $calculatedDelay = '';
        if ($planned !== '' && $actual !== '') {
            $plannedTime = strtotime($planned);
            $actualTime = strtotime($actual);
            if ($plannedTime !== false && $actualTime !== false) {
                $calculatedDelay = (string)max(0, (int)round(($actualTime - $plannedTime) / 86400));
            }
        }

        $items[] = [
            'sl_no' => (int)($slNos[$index] ?? ($index + 1)),
            'delayed_task' => trim((string)($delayedTasks[$index] ?? '')),
            'planned_date' => $planned,
            'actual_date' => $actual,
            'delay_days' => $calculatedDelay !== '' ? $calculatedDelay : trim((string)($delayDays[$index] ?? '')),
            'delay_response_by' => trim((string)($delayResponses[$index] ?? '')),
            'issues_opened_on' => trim((string)($issuesOpened[$index] ?? '')),
            'reminders_dated' => trim((string)($reminders[$index] ?? '')),
            'issues_closed_on' => trim((string)($issuesClosed[$index] ?? '')),
        ];
    }

    $items = dlar_clean_rows($items);
    if ($error === '' && !$items) {
        $error = 'Please enter at least one delay entry.';
    }

    if ($error === '') {
        $reportMonth = (int)date('n', strtotime($postReportDate));
        $reportYear = (int)date('Y', strtotime($postReportDate));
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        if ($postSubmissionId > 0) {
            $existingId = dlar_find_existing_report($conn, $postSubmissionId, $postProjectId, $employeeId, $postReportDate);
            if ($existingId <= 0) {
                $error = 'Original DLAR not found for resubmission.';
            } else {
                $stmt = mysqli_prepare($conn, "
                    UPDATE dlar_reports
                    SET dlar_no = ?, report_date = ?, report_month = ?, report_year = ?,
                        project_name = ?, client_name = ?, architect_name = ?, pmc_name = ?,
                        date_version = ?, items_json = ?, prepared_by = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                if (!$stmt) {
                    $error = 'Database error: ' . mysqli_error($conn);
                } else {
                    mysqli_stmt_bind_param(
                        $stmt,
                        'ssiisssssssi',
                        $dlarNo,
                        $postReportDate,
                        $reportMonth,
                        $reportYear,
                        $projectName,
                        $clientName,
                        $architectName,
                        $pmcName,
                        $dateVersion,
                        $itemsJson,
                        $preparedBy,
                        $existingId
                    );

                    if (!mysqli_stmt_execute($stmt)) {
                        $error = 'Failed to resubmit DLAR: ' . mysqli_stmt_error($stmt);
                    } else {
                        dlar_sync_submission($conn, $postSubmissionId, $postProjectId, $employeeId, $postReportDate, $existingId);
                        dlar_log_activity($conn, 'UPDATE', 'Resubmitted DLAR ' . $dlarNo, $existingId);

                        $managerQuery = mysqli_query($conn, "SELECT project_name, manager_employee_id FROM projects WHERE id = $postProjectId LIMIT 1");
                        if ($managerQuery && ($manager = mysqli_fetch_assoc($managerQuery)) && !empty($manager['manager_employee_id'])) {
                            dlar_notify_employee(
                                $conn,
                                (int)$manager['manager_employee_id'],
                                'DLAR Resubmitted',
                                $preparedBy . ' resubmitted ' . $dlarNo . ' for ' . $manager['project_name'],
                                $existingId,
                                'reports-print/report-dlar-print.php?id=' . $existingId
                            );
                        }

                        mysqli_stmt_close($stmt);
                        dlar_redirect_hub($postProjectId, $postReportDate, 'resubmitted');
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        } else {
            $dateEsc = mysqli_real_escape_string($conn, $postReportDate);
            $duplicateQuery = mysqli_query($conn, "
                SELECT id
                FROM dlar_reports
                WHERE project_id = $postProjectId
                  AND employee_id = $employeeId
                  AND report_date = '$dateEsc'
                LIMIT 1
            ");

            if ($duplicateQuery && mysqli_num_rows($duplicateQuery) > 0) {
                $error = 'DLAR already submitted for this date. Use Resubmit from Reports Hub.';
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO dlar_reports
                    (project_id, site_id, employee_id, dlar_no, report_date, report_month, report_year,
                     project_name, client_name, architect_name, pmc_name, date_version, items_json, prepared_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$stmt) {
                    $error = 'Database error: ' . mysqli_error($conn);
                } else {
                    $siteId = $postProjectId;
                    mysqli_stmt_bind_param(
                        $stmt,
                        'iiissiisssssss',
                        $postProjectId,
                        $siteId,
                        $employeeId,
                        $dlarNo,
                        $postReportDate,
                        $reportMonth,
                        $reportYear,
                        $projectName,
                        $clientName,
                        $architectName,
                        $pmcName,
                        $dateVersion,
                        $itemsJson,
                        $preparedBy
                    );

                    if (!mysqli_stmt_execute($stmt)) {
                        $error = 'Failed to save DLAR: ' . mysqli_stmt_error($stmt);
                    } else {
                        $newId = (int)mysqli_insert_id($conn);
                        mysqli_stmt_close($stmt);

                        dlar_sync_submission($conn, 0, $postProjectId, $employeeId, $postReportDate, $newId);
                        dlar_log_activity($conn, 'CREATE', 'Submitted DLAR ' . $dlarNo, $newId);

                        $managerQuery = mysqli_query($conn, "SELECT project_name, manager_employee_id FROM projects WHERE id = $postProjectId LIMIT 1");
                        if ($managerQuery && ($manager = mysqli_fetch_assoc($managerQuery)) && !empty($manager['manager_employee_id'])) {
                            dlar_notify_employee(
                                $conn,
                                (int)$manager['manager_employee_id'],
                                'New DLAR Submitted',
                                $preparedBy . ' submitted ' . $dlarNo . ' for ' . $manager['project_name'],
                                $newId,
                                'reports-print/report-dlar-print.php?id=' . $newId
                            );
                        }

                        dlar_redirect_hub($postProjectId, $postReportDate, 'saved');
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }

    if ($error !== '') {
        header('Location: dlar.php?project_id=' . $postProjectId . '&report_date=' . urlencode($postReportDate) . '&error=' . urlencode($error));
        exit;
    }
}

$recentReports = [];
$recentQuery = mysqli_query($conn, "
    SELECT r.id, r.dlar_no, r.report_date, r.project_name
    FROM dlar_reports r
    WHERE r.employee_id = $employeeId
    ORDER BY r.created_at DESC
    LIMIT 10
");
while ($recentQuery && ($recentRow = mysqli_fetch_assoc($recentQuery))) {
    $recentReports[] = $recentRow;
}

$formData = [
    'dlar_no' => $existingReport['dlar_no'] ?? $defaultDlarNo,
    'report_date' => $existingReport['report_date'] ?? $reportDate,
    'date_version' => $existingReport['date_version'] ?? (date('d-m-Y', strtotime($reportDate)) . ' / V1'),
    'project_name' => $existingReport['project_name'] ?? ($project['project_name'] ?? ''),
    'client_name' => $existingReport['client_name'] ?? ($project['client_name'] ?? ''),
    'architect_name' => $existingReport['architect_name'] ?? '',
    'pmc_name' => $existingReport['pmc_name'] ?? 'TEK-C PMC',
    'items' => [],
];
if (!empty($existingReport['items_json'])) {
    $decodedItems = json_decode($existingReport['items_json'], true);
    $formData['items'] = is_array($decodedItems) ? $decodedItems : [];
}
if (!$formData['items']) {
    $formData['items'] = array_fill(0, 5, [
        'sl_no' => '', 'delayed_task' => '', 'planned_date' => '', 'actual_date' => '',
        'delay_days' => '', 'delay_response_by' => '', 'issues_opened_on' => '',
        'reminders_dated' => '', 'issues_closed_on' => ''
    ]);
}

$previousTemplatePayload = null;
if ($previousTemplate) {
    $decodedPreviousItems = json_decode((string)($previousTemplate['items_json'] ?? '[]'), true);
    $previousTemplatePayload = [
        'architect_name' => $previousTemplate['architect_name'] ?? '',
        'pmc_name' => $previousTemplate['pmc_name'] ?? '',
        'date_version' => $previousTemplate['date_version'] ?? '',
        'items' => is_array($decodedPreviousItems) ? $decodedPreviousItems : [],
        'dlar_no' => $previousTemplate['dlar_no'] ?? '',
        'report_date' => $previousTemplate['report_date'] ?? '',
    ];
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DLAR - TEK-C PMC Construction</title>
    <?php include 'includes/links.php'; ?>
    <style>
        .page-head-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
        .section-box{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:18px}
        .mini-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}.mini-icon{width:44px;height:44px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:rgba(245,158,11,.14);color:#f59e0b}
        .form-control,.form-select{background:var(--card-bg);color:var(--text-main);border-color:var(--border-soft);min-height:42px;font-size:13px;font-weight:700}
        .dlar-table{min-width:1450px}.dlar-table th{font-size:11px;text-transform:uppercase;color:var(--text-muted);font-weight:900;background:rgba(148,163,184,.10);white-space:nowrap;text-align:center;vertical-align:middle}.dlar-table td{vertical-align:top}.dlar-table textarea{min-width:210px}.dlar-table input[type="date"]{min-width:145px}
        .badge-soft{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--border-soft);background:rgba(148,163,184,.08);border-radius:999px;padding:7px 12px;font-size:12px;font-weight:900}.badge-soft input{width:15px;height:15px}
        .recent-card{border:1px solid var(--border-soft);border-radius:18px;padding:12px;background:rgba(148,163,184,.06)}
    </style>
</head>
<body>
<div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>
<?php include 'includes/page-message.php'; ?>
<div class="min-vh-100 d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main id="main">
        <?php include 'includes/nav.php'; ?>
        <section class="page-section p-3 p-lg-3">
            <div class="page-head-card mb-3">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                    <div>
                        <h1 class="h4 fw-bold mb-1"><?= $resubmitSubmissionId > 0 ? 'Resubmit Delay Analysis Report (DLAR)' : 'Delay Analysis Report (DLAR)' ?></h1>
                        <p class="text-muted-custom mb-0 small">Record delayed activities, responsible persons, follow-ups and closure dates.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <label class="badge-soft mb-0 <?= !$previousTemplatePayload ? 'opacity-75' : '' ?>"
                               title="<?= $previousTemplatePayload ? 'Load previous submitted DLAR data without changing DLAR No, Report Date or Project' : 'No previous DLAR data found for this project' ?>">
                            <input type="checkbox" class="form-check-input mt-0" id="loadPreviousDlarData" <?= !$previousTemplatePayload ? 'disabled' : '' ?>>
                            <span>
                                <strong>Load previous data</strong>
                                <small class="d-block text-muted-custom fw-semibold">
                                    <?php if ($previousTemplatePayload): ?>
                                        <?= dlar_e($previousTemplatePayload['dlar_no']) ?> · <?= dlar_e(date('d M Y', strtotime($previousTemplatePayload['report_date']))) ?>
                                    <?php else: ?>
                                        No previous data
                                    <?php endif; ?>
                                </small>
                            </span>
                        </label>
                        <span class="badge-soft"><i data-lucide="user" style="width:15px;height:15px"></i><?= dlar_e($preparedBy) ?></span>
                        <a href="reports-hub.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Back to Reports Hub</a>
                    </div>
                </div>
            </div>

            <div class="section-box mb-3">
                <div class="mini-head"><div class="mini-icon"><i data-lucide="map-pin"></i></div><div><h2 class="fw-bold fs-6 mb-1">Project Selection</h2><p class="text-muted-custom small mb-0">Choose an assigned project to prepare DLAR.</p></div></div>
                <div class="row g-3 align-items-end">
                    <div class="col-lg-9">
                        <label class="form-label fw-bold small">Assigned Project <span class="text-danger">*</span></label>
                        <select class="form-select rounded-4" id="projectPicker">
                            <option value="">-- Select Project --</option>
                            <?php foreach ($projects as $projectOption): ?>
                                <option value="<?= (int)$projectOption['id'] ?>" <?= (int)$projectOption['id'] === $projectId ? 'selected' : '' ?>>
                                    <?= dlar_e($projectOption['project_name']) ?><?= !empty($projectOption['project_code']) ? ' (' . dlar_e($projectOption['project_code']) . ')' : '' ?> - <?= dlar_e($projectOption['project_location'] ?: '-') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3"><a href="dlar.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">Reset</a></div>
                </div>
            </div>

            <div class="section-box mb-3">
                <div class="mini-head"><div class="mini-icon"><i data-lucide="building-2"></i></div><div><h2 class="fw-bold fs-6 mb-1">Project Details</h2><p class="text-muted-custom small mb-0">Auto-filled from the selected project.</p></div></div>
                <?php if (!$project): ?>
                    <p class="text-muted-custom fw-bold mb-0">Please select a project above.</p>
                <?php else: ?>
                    <div class="row g-3">
                        <div class="col-md-4"><small class="text-muted-custom fw-bold">Project</small><div class="fw-bold"><?= dlar_e($project['project_name']) ?></div></div>
                        <div class="col-md-4"><small class="text-muted-custom fw-bold">Client</small><div class="fw-bold"><?= dlar_e($project['client_name'] ?: '-') ?></div></div>
                        <div class="col-md-4"><small class="text-muted-custom fw-bold">Location</small><div class="fw-bold"><?= dlar_e($project['project_location'] ?: '-') ?></div></div>
                        <div class="col-md-4"><small class="text-muted-custom fw-bold">Project Type</small><div class="fw-bold"><?= dlar_e($project['project_type_name'] ?: '-') ?></div></div>
                        <div class="col-md-4"><small class="text-muted-custom fw-bold">Project Start</small><div class="fw-bold"><?= dlar_e($project['start_date'] ?: '-') ?></div></div>
                        <div class="col-md-4"><small class="text-muted-custom fw-bold">Expected Completion</small><div class="fw-bold"><?= dlar_e($project['expected_completion_date'] ?: '-') ?></div></div>
                        <div class="col-12"><small class="text-muted-custom fw-bold">Scope of Work</small><div class="fw-bold"><?= dlar_e($project['scope_of_work'] ?: '-') ?></div></div>
                    </div>
                <?php endif; ?>
            </div>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="submit_dlar" value="1">
                <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
                <input type="hidden" name="resubmit_submission_id" value="<?= (int)$resubmitSubmissionId ?>">

                <div class="section-box mb-3">
                    <div class="mini-head"><div class="mini-icon"><i data-lucide="file-warning"></i></div><div><h2 class="fw-bold fs-6 mb-1">DLAR Header</h2><p class="text-muted-custom small mb-0">Report number, date, project parties and version details.</p></div></div>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label fw-bold small">DLAR No <span class="text-danger">*</span></label><input class="form-control rounded-4" name="dlar_no" value="<?= dlar_e($formData['dlar_no']) ?>" required></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">Report Date <span class="text-danger">*</span></label><input type="date" class="form-control rounded-4" name="report_date" id="report_date" value="<?= dlar_e($formData['report_date']) ?>" required></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">Date / Version</label><input class="form-control rounded-4" name="date_version" id="date_version" value="<?= dlar_e($formData['date_version']) ?>"></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">Project</label><input class="form-control rounded-4" name="project_name" value="<?= dlar_e($formData['project_name']) ?>"></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">Client</label><input class="form-control rounded-4" name="client_name" value="<?= dlar_e($formData['client_name']) ?>"></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">Architect</label><input class="form-control rounded-4" name="architect_name" id="architect_name" value="<?= dlar_e($formData['architect_name']) ?>"></div>
                        <div class="col-md-6"><label class="form-label fw-bold small">PMC</label><input class="form-control rounded-4" name="pmc_name" id="pmc_name" value="<?= dlar_e($formData['pmc_name']) ?>"></div>
                        <div class="col-md-6"><label class="form-label fw-bold small">Prepared By</label><input class="form-control rounded-4" value="<?= dlar_e($preparedBy) ?>" readonly></div>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-2 mb-3">
                        <div class="mini-head mb-0"><div class="mini-icon"><i data-lucide="list-checks"></i></div><div><h2 class="fw-bold fs-6 mb-1">Delay Entries</h2><p class="text-muted-custom small mb-0">Add delayed tasks and Engineer In Charge / PMC actions.</p></div></div>
                        <button type="button" class="btn btn-outline-primary rounded-4 fw-bold" id="addDelayRow">Add Row</button>
                    </div>
                    <div class="table-responsive thin-scrollbar">
                        <table class="table table-bordered align-middle mb-0 dlar-table">
                            <thead><tr><th style="width:70px">SL No</th><th>Delayed Task</th><th>Planned Date</th><th>Actual Date</th><th>Delay Days</th><th>Delay Response By</th><th>Issues Opened On</th><th>Reminders / Follow-ups Dated</th><th>Issues Closed On</th><th>Del</th></tr></thead>
                            <tbody id="delayBody">
                            <?php foreach ($formData['items'] as $index => $item): ?>
                                <tr>
                                    <td><input class="form-control rounded-4 text-center sl-no" name="sl_no[]" value="<?= $index + 1 ?>" readonly></td>
                                    <td><textarea class="form-control rounded-4" name="delayed_task[]" rows="2"><?= dlar_e($item['delayed_task'] ?? '') ?></textarea></td>
                                    <td><input type="date" class="form-control rounded-4 planned-date" name="planned_date[]" value="<?= dlar_e($item['planned_date'] ?? '') ?>"></td>
                                    <td><input type="date" class="form-control rounded-4 actual-date" name="actual_date[]" value="<?= dlar_e($item['actual_date'] ?? '') ?>"></td>
                                    <td><input class="form-control rounded-4 text-center delay-days" name="delay_days[]" value="<?= dlar_e($item['delay_days'] ?? '') ?>" readonly></td>
                                    <td><input class="form-control rounded-4" name="delay_response_by[]" value="<?= dlar_e($item['delay_response_by'] ?? '') ?>"></td>
                                    <td><input type="date" class="form-control rounded-4" name="issues_opened_on[]" value="<?= dlar_e($item['issues_opened_on'] ?? '') ?>"></td>
                                    <td><input class="form-control rounded-4" name="reminders_dated[]" value="<?= dlar_e($item['reminders_dated'] ?? '') ?>"></td>
                                    <td><input type="date" class="form-control rounded-4" name="issues_closed_on[]" value="<?= dlar_e($item['issues_closed_on'] ?? '') ?>"></td>
                                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger rounded-4 delRow"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="d-flex justify-content-end"><button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4" <?= (!$project) ? 'disabled' : '' ?>><?= $resubmitSubmissionId > 0 ? 'Resubmit DLAR' : 'Submit DLAR' ?></button></div>
                </div>
            </form>

            <section class="card-ui overflow-hidden">
                <div class="p-3 p-lg-4"><h2 class="fw-bold fs-6 mb-1">Recent DLAR</h2><p class="text-muted-custom small mb-0">Your latest Delay Analysis Report submissions.</p></div>
                <div class="px-3 px-lg-4 pb-4">
                    <?php if (!$recentReports): ?>
                        <p class="text-muted-custom fw-bold mb-0">No DLAR submitted yet.</p>
                    <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($recentReports as $recent): ?>
                                <div class="col-md-6 col-xl-4"><div class="recent-card"><div class="d-flex justify-content-between gap-2"><div><div class="fw-bold"><?= dlar_e($recent['dlar_no']) ?></div><small class="text-muted-custom"><?= dlar_e($recent['project_name']) ?> · <?= dlar_e(date('d M Y', strtotime($recent['report_date']))) ?></small></div><a href="reports-print/report-dlar-print.php?id=<?= (int)$recent['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-4 fw-bold">Print</a></div></div></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php include 'includes/footer.php'; ?>
        </section>
    </main>
    <div id="settingsOverlay"></div>
    <?php include 'includes/rightsidbar.php'; ?>
</div>
<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js?v=35"></script>
<script>
const previousDlarData = <?= json_encode($previousTemplatePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const currentDlarSnapshot = {
    architect_name: document.getElementById('architect_name')?.value || '',
    pmc_name: document.getElementById('pmc_name')?.value || '',
    date_version: document.getElementById('date_version')?.value || '',
    items: Array.from(document.querySelectorAll('#delayBody tr')).map(function(row) {
        return {
            delayed_task: row.querySelector('[name="delayed_task[]"]')?.value || '',
            planned_date: row.querySelector('[name="planned_date[]"]')?.value || '',
            actual_date: row.querySelector('[name="actual_date[]"]')?.value || '',
            delay_days: row.querySelector('[name="delay_days[]"]')?.value || '',
            delay_response_by: row.querySelector('[name="delay_response_by[]"]')?.value || '',
            issues_opened_on: row.querySelector('[name="issues_opened_on[]"]')?.value || '',
            reminders_dated: row.querySelector('[name="reminders_dated[]"]')?.value || '',
            issues_closed_on: row.querySelector('[name="issues_closed_on[]"]')?.value || ''
        };
    })
};

document.getElementById('projectPicker')?.addEventListener('change', function () {
    const date = document.getElementById('report_date')?.value || '<?= dlar_e($reportDate) ?>';
    window.location.href = 'dlar.php?project_id=' + encodeURIComponent(this.value) + '&report_date=' + encodeURIComponent(date);
});

const delayBody = document.getElementById('delayBody');

function renumberRows() {
    delayBody.querySelectorAll('tr').forEach((row, index) => {
        const input = row.querySelector('.sl-no');
        if (input) input.value = index + 1;
    });
}

function calculateDelay(row) {
    const planned = row.querySelector('.planned-date')?.value || '';
    const actual = row.querySelector('.actual-date')?.value || '';
    const output = row.querySelector('.delay-days');
    if (!output) return;
    if (!planned || !actual) { output.value = ''; return; }
    const start = new Date(planned + 'T00:00:00');
    const end = new Date(actual + 'T00:00:00');
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) { output.value = ''; return; }
    output.value = Math.max(0, Math.round((end - start) / 86400000));
}

function bindRow(row) {
    row.querySelector('.planned-date')?.addEventListener('change', () => calculateDelay(row));
    row.querySelector('.actual-date')?.addEventListener('change', () => calculateDelay(row));
}

function rowHtml(values = {}) {
    return `
        <td><input class="form-control rounded-4 text-center sl-no" name="sl_no[]" readonly></td>
        <td><textarea class="form-control rounded-4" name="delayed_task[]" rows="2">${values.delayed_task || ''}</textarea></td>
        <td><input type="date" class="form-control rounded-4 planned-date" name="planned_date[]" value="${values.planned_date || ''}"></td>
        <td><input type="date" class="form-control rounded-4 actual-date" name="actual_date[]" value="${values.actual_date || ''}"></td>
        <td><input class="form-control rounded-4 text-center delay-days" name="delay_days[]" value="${values.delay_days || ''}" readonly></td>
        <td><input class="form-control rounded-4" name="delay_response_by[]" value="${values.delay_response_by || ''}"></td>
        <td><input type="date" class="form-control rounded-4" name="issues_opened_on[]" value="${values.issues_opened_on || ''}"></td>
        <td><input class="form-control rounded-4" name="reminders_dated[]" value="${values.reminders_dated || ''}"></td>
        <td><input type="date" class="form-control rounded-4" name="issues_closed_on[]" value="${values.issues_closed_on || ''}"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger rounded-4 delRow"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>`;
}

function addRow(values = {}) {
    const row = document.createElement('tr');
    row.innerHTML = rowHtml(values);
    delayBody.appendChild(row);
    renumberRows();
    bindRow(row);
    calculateDelay(row);
    if (window.lucide) window.lucide.createIcons();
}

document.getElementById('addDelayRow')?.addEventListener('click', () => addRow());

document.addEventListener('click', function (event) {
    const button = event.target.closest('.delRow');
    if (!button) return;
    const row = button.closest('tr');
    if (!row) return;
    if (delayBody.querySelectorAll('tr').length <= 1) {
        row.querySelectorAll('textarea, input:not(.sl-no)').forEach(input => input.value = '');
    } else {
        row.remove();
    }
    renumberRows();
});

delayBody.querySelectorAll('tr').forEach(row => { bindRow(row); calculateDelay(row); });

document.getElementById('loadPreviousDlarData')?.addEventListener('change', function () {
    if (!this.checked || !previousDlarData) return;
    document.getElementById('architect_name').value = previousDlarData.architect_name || '';
    document.getElementById('pmc_name').value = previousDlarData.pmc_name || '';
    delayBody.innerHTML = '';
    const items = Array.isArray(previousDlarData.items) && previousDlarData.items.length ? previousDlarData.items : [{}];
    items.forEach(item => addRow(item));
});

window.addEventListener('load', function () {
    if (window.lucide) window.lucide.createIcons();
});
</script>
</body>
</html>
