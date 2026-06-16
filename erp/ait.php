<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

function ait_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ait_employee_id(mysqli $conn): int
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

function ait_is_super_admin(mysqli $conn): bool
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
          AND r.is_active = 1
          AND (r.role_slug = 'super-admin' OR LOWER(r.role_name) = 'super admin')
        LIMIT 1
    ");

    return $query && mysqli_num_rows($query) > 0;
}

function ait_report_access(mysqli $conn, string $permission): bool
{
    if (ait_is_super_admin($conn)) {
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
          AND rt.report_code = 'AIT'
          AND rt.is_active = 1
    ");

    return $query
        && ($row = mysqli_fetch_assoc($query))
        && (int)($row['allowed'] ?? 0) === 1;
}

function ait_project_allowed(mysqli $conn, int $projectId, int $employeeId, bool $isSuperAdmin): bool
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

function ait_table_columns(mysqli $conn, string $table): array
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columns = [];
    $query = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $columns[$row['Field']] = true;
    }

    return $columns;
}

function ait_sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function ait_report_type_id(mysqli $conn): int
{
    $query = mysqli_query($conn, "
        SELECT id
        FROM master_report_types
        WHERE report_code = 'AIT'
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query))) ? (int)$row['id'] : 0;
}

function ait_log_activity(mysqli $conn, string $type, string $description, ?int $referenceId = null): void
{
    $employeeId = ait_employee_id($conn);
    $employeeName = (string)($_SESSION['employee_name'] ?? $_SESSION['name'] ?? '');
    $username = (string)($_SESSION['username'] ?? '');
    $designation = (string)($_SESSION['designation'] ?? '');
    $department = (string)($_SESSION['department'] ?? '');
    $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $type = strtoupper($type);

    $stmt = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (
            employee_id, employee_name, username, designation, department,
            activity_type, module, description, reference_id, ip_address
        )
        VALUES (?, ?, ?, ?, ?, ?, 'AIT', ?, ?, ?)
    ");

    if (!$stmt) {
        return;
    }

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

function ait_notify_employee(
    mysqli $conn,
    int $employeeId,
    string $title,
    string $message,
    int $referenceId,
    string $link
): void {
    if ($employeeId <= 0) {
        return;
    }

    $userId = 0;
    $employeeQuery = mysqli_query($conn, "SELECT user_id FROM employees WHERE id = $employeeId LIMIT 1");

    if ($employeeQuery && ($employee = mysqli_fetch_assoc($employeeQuery))) {
        $userId = (int)($employee['user_id'] ?? 0);
    }

    $reasonId = 0;
    $icon = 'list-checks';
    $color = '#2563eb';

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
        (
            reason_id, title, message, target_type, module, reference_id, link,
            priority, notification_type, icon, color_code, is_system, is_active,
            sent_at, created_by, created_by_name
        )
        VALUES (?, ?, ?, 'employees', 'ait_main', ?, ?, 'normal', 'info', ?, ?, 1, 1, NOW(), ?, ?)
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

    mysqli_query(
        $conn,
        "INSERT INTO notification_employee_targets (notification_id, employee_id)
         VALUES ($notificationId, $employeeId)"
    );

    if ($userId > 0) {
        mysqli_query(
            $conn,
            "INSERT INTO notification_user_targets (notification_id, user_id)
             VALUES ($notificationId, $userId)"
        );

        mysqli_query(
            $conn,
            "INSERT INTO notification_user_status (notification_id, user_id)
             VALUES ($notificationId, $userId)"
        );
    }
}

function ait_existing_for_resubmit(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate
): int {
    if ($submissionId > 0) {
        $columns = ait_table_columns($conn, 'project_report_submissions');
        $select = ['id', 'project_id', 'submitted_by_employee_id', 'submission_for_date'];

        foreach (['reference_id', 'source_id', 'report_reference_id'] as $column) {
            if (isset($columns[$column])) {
                $select[] = $column;
            }
        }

        $query = mysqli_query(
            $conn,
            "SELECT " . implode(',', array_unique($select)) . "
             FROM project_report_submissions
             WHERE id = $submissionId
             LIMIT 1"
        );

        if ($query && ($submission = mysqli_fetch_assoc($query))) {
            foreach (['report_reference_id', 'source_id', 'reference_id'] as $column) {
                $candidateId = (int)($submission[$column] ?? 0);

                if ($candidateId <= 0) {
                    continue;
                }

                $recordQuery = mysqli_query(
                    $conn,
                    "SELECT id FROM ait_main WHERE id = $candidateId LIMIT 1"
                );

                if ($recordQuery && mysqli_num_rows($recordQuery) > 0) {
                    return $candidateId;
                }
            }
        }
    }

    $dateEsc = mysqli_real_escape_string($conn, $reportDate);
    $query = mysqli_query($conn, "
        SELECT id
        FROM ait_main
        WHERE project_id = $projectId
          AND created_by = $employeeId
          AND ait_date = '$dateEsc'
        ORDER BY id DESC
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query))) ? (int)$row['id'] : 0;
}

function ait_sync_submission(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate,
    int $aitId,
    string $aitNo
): void {
    $reportTypeId = ait_report_type_id($conn);

    if ($reportTypeId <= 0 || $projectId <= 0 || $employeeId <= 0 || $aitId <= 0) {
        return;
    }

    $columns = ait_table_columns($conn, 'project_report_submissions');
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $dateEsc = mysqli_real_escape_string($conn, $reportDate);

    if ($submissionId <= 0) {
        $query = mysqli_query($conn, "
            SELECT id
            FROM project_report_submissions
            WHERE project_id = $projectId
              AND report_type_id = $reportTypeId
              AND submission_for_date = '$dateEsc'
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($query && ($row = mysqli_fetch_assoc($query))) {
            $submissionId = (int)$row['id'];
        }
    }

    $map = [
        'report_no' => $aitNo,
        'report_number' => $aitNo,
        'submission_no' => $aitNo,
        'submitted_by_employee_id' => $employeeId,
        'submitted_by_user_id' => $userId > 0 ? $userId : null,
        'submission_for_date' => $reportDate,
        'period_start' => $reportDate,
        'period_end' => $reportDate,
        'status' => 'submitted',
        'submitted_at' => date('Y-m-d H:i:s'),
        'reference_id' => $aitId,
        'source_table' => 'ait_main',
        'source_id' => $aitId,
        'report_reference_table' => 'ait_main',
        'report_reference_id' => $aitId,
        'updated_by' => $userId > 0 ? $userId : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($submissionId > 0) {
        $sets = [];

        foreach ($map as $field => $value) {
            if (isset($columns[$field])) {
                $sets[] = "`$field` = " . ait_sql_value($conn, $value);
            }
        }

        if ($sets) {
            mysqli_query(
                $conn,
                "UPDATE project_report_submissions
                 SET " . implode(', ', $sets) . "
                 WHERE id = $submissionId"
            );
        }

        return;
    }

    $insertData = array_merge([
        'project_id' => $projectId,
        'report_type_id' => $reportTypeId,
        'created_by' => $userId > 0 ? $userId : null,
        'created_at' => date('Y-m-d H:i:s'),
    ], $map);

    $insertColumns = [];
    $insertValues = [];

    foreach ($insertData as $field => $value) {
        if (isset($columns[$field])) {
            $insertColumns[] = "`$field`";
            $insertValues[] = ait_sql_value($conn, $value);
        }
    }

    if ($insertColumns) {
        mysqli_query(
            $conn,
            "INSERT INTO project_report_submissions (" . implode(',', $insertColumns) . ")
             VALUES (" . implode(',', $insertValues) . ")"
        );
    }
}

function ait_redirect_hub(int $projectId, string $reportDate, string $flag): void
{
    $flag = preg_replace('/[^a-zA-Z0-9_]/', '', $flag);
    $date = urlencode($reportDate);

    header(
        "Location: reports-hub.php"
        . "?project_id=" . $projectId
        . "&report_date=" . $date
        . "&period_start=" . $date
        . "&period_end=" . $date
        . "&" . $flag . "=1"
    );
    exit;
}

$employeeId = ait_employee_id($conn);
$isSuperAdmin = ait_is_super_admin($conn);

if ($employeeId <= 0) {
    header('Location: login.php');
    exit;
}

if (!ait_report_access($conn, 'can_submit')) {
    header('Location: reports-hub.php?error=' . urlencode('You do not have AIT submit access.'));
    exit;
}

/*
|--------------------------------------------------------------------------
| Ensure current AIT tables exist
|--------------------------------------------------------------------------
*/

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS ait_main (
        id INT NOT NULL AUTO_INCREMENT,
        ait_no VARCHAR(80) NOT NULL,
        project_id INT NOT NULL,
        site_id INT DEFAULT NULL,
        client_id INT DEFAULT NULL,
        project_name VARCHAR(255) DEFAULT NULL,
        client_name VARCHAR(255) DEFAULT NULL,
        architects VARCHAR(255) DEFAULT NULL,
        pmc VARCHAR(255) DEFAULT NULL,
        revisions VARCHAR(120) DEFAULT NULL,
        ait_date DATE NOT NULL,
        created_by INT NOT NULL,
        created_by_name VARCHAR(150) DEFAULT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_ait_project (project_id),
        KEY idx_ait_employee (created_by),
        KEY idx_ait_date (ait_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS ait_details (
        id INT NOT NULL AUTO_INCREMENT,
        ait_main_id INT NOT NULL,
        sl_no INT NOT NULL,
        dated DATE DEFAULT NULL,
        description TEXT DEFAULT NULL,
        priority VARCHAR(30) DEFAULT NULL,
        responsible_by VARCHAR(255) DEFAULT NULL,
        due_date DATE DEFAULT NULL,
        completion_date DATE DEFAULT NULL,
        progress_notes TEXT DEFAULT NULL,
        status VARCHAR(40) DEFAULT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_ait_detail_main (ait_main_id),
        CONSTRAINT fk_ait_detail_main
            FOREIGN KEY (ait_main_id)
            REFERENCES ait_main(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$employee = null;
$employeeQuery = mysqli_query($conn, "SELECT * FROM employees WHERE id = $employeeId LIMIT 1");

if ($employeeQuery) {
    $employee = mysqli_fetch_assoc($employeeQuery);
}

$preparedBy = (string)($employee['full_name'] ?? $_SESSION['employee_name'] ?? $_SESSION['name'] ?? '');

$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$reportDate = trim((string)($_GET['report_date'] ?? $_POST['ait_date'] ?? date('Y-m-d')));

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
        LEFT JOIN project_assignments pa
            ON pa.project_id = p.id
           AND pa.status = 'active'
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

if ($projectId > 0 && ait_project_allowed($conn, $projectId, $employeeId, $isSuperAdmin)) {
    $projectQuery = mysqli_query($conn, "
        SELECT
            p.*,
            c.client_name,
            c.company_name,
            mpt.project_type_name,
            manager.full_name AS manager_name,
            team_lead_emp.full_name AS team_lead_name
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

$defaultAitNo = '';

if ($projectId > 0) {
    $yearMonth = date('Ym', strtotime($reportDate));
    $prefix = 'AIT/' . $projectId . '/' . $yearMonth . '/';
    $prefixEsc = mysqli_real_escape_string($conn, $prefix);

    $countQuery = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM ait_main
         WHERE ait_no LIKE '$prefixEsc%'"
    );

    $count = $countQuery ? (int)(mysqli_fetch_assoc($countQuery)['total'] ?? 0) : 0;
    $defaultAitNo = $prefix . str_pad((string)($count + 1), 3, '0', STR_PAD_LEFT);
}

$existingReport = null;

if ($resubmitSubmissionId > 0 && $projectId > 0) {
    $existingId = ait_existing_for_resubmit(
        $conn,
        $resubmitSubmissionId,
        $projectId,
        $employeeId,
        $reportDate
    );

    if ($existingId > 0) {
        $existingQuery = mysqli_query($conn, "SELECT * FROM ait_main WHERE id = $existingId LIMIT 1");

        if ($existingQuery) {
            $existingReport = mysqli_fetch_assoc($existingQuery);
        }
    }
}

$previousTemplate = null;

if ($projectId > 0) {
    $safeDate = mysqli_real_escape_string($conn, $reportDate);

    $previousQuery = mysqli_query($conn, "
        SELECT *
        FROM ait_main
        WHERE project_id = $projectId
          AND created_by = $employeeId
          AND ait_date < '$safeDate'
        ORDER BY ait_date DESC, created_at DESC, id DESC
        LIMIT 1
    ");

    if ($previousQuery) {
        $previousTemplate = mysqli_fetch_assoc($previousQuery);
    }

    if (!$previousTemplate) {
        $previousQuery = mysqli_query($conn, "
            SELECT *
            FROM ait_main
            WHERE project_id = $projectId
              AND created_by = $employeeId
            ORDER BY ait_date DESC, created_at DESC, id DESC
            LIMIT 1
        ");

        if ($previousQuery) {
            $previousTemplate = mysqli_fetch_assoc($previousQuery);
        }
    }

    if (!$previousTemplate) {
        $previousQuery = mysqli_query($conn, "
            SELECT *
            FROM ait_main
            WHERE project_id = $projectId
            ORDER BY ait_date DESC, created_at DESC, id DESC
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ait'])) {
    $postProjectId = (int)($_POST['project_id'] ?? 0);
    $postReportDate = trim((string)($_POST['ait_date'] ?? ''));
    $postSubmissionId = (int)($_POST['resubmit_submission_id'] ?? 0);
    $aitNo = trim((string)($_POST['ait_no'] ?? ''));
    $architects = trim((string)($_POST['architects'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));
    $revisions = trim((string)($_POST['revisions'] ?? ''));

    $error = '';

    if (!ait_project_allowed($conn, $postProjectId, $employeeId, $isSuperAdmin)) {
        $error = 'Invalid project selection.';
    } elseif ($aitNo === '') {
        $error = 'AIT No is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postReportDate)) {
        $error = 'Valid AIT date is required.';
    }

    $slNos = $_POST['sl_no'] ?? [];
    $dateds = $_POST['dated'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $priorities = $_POST['priority'] ?? [];
    $responsibleBys = $_POST['responsible_by'] ?? [];
    $dueDates = $_POST['due_date'] ?? [];
    $completionDates = $_POST['completion_date'] ?? [];
    $progressNotes = $_POST['progress_notes'] ?? [];
    $statuses = $_POST['status'] ?? [];

    $rows = [];
    $maxRows = max(
        count($slNos),
        count($dateds),
        count($descriptions),
        count($priorities),
        count($responsibleBys),
        count($dueDates),
        count($completionDates),
        count($progressNotes),
        count($statuses)
    );

    for ($index = 0; $index < $maxRows; $index++) {
        $description = trim((string)($descriptions[$index] ?? ''));

        if ($description === '') {
            continue;
        }

        $rows[] = [
            'sl_no' => (int)($slNos[$index] ?? ($index + 1)),
            'dated' => trim((string)($dateds[$index] ?? '')),
            'description' => $description,
            'priority' => trim((string)($priorities[$index] ?? 'MEDIUM')),
            'responsible_by' => trim((string)($responsibleBys[$index] ?? '')),
            'due_date' => trim((string)($dueDates[$index] ?? '')),
            'completion_date' => trim((string)($completionDates[$index] ?? '')),
            'progress_notes' => trim((string)($progressNotes[$index] ?? '')),
            'status' => trim((string)($statuses[$index] ?? 'OPEN')),
        ];
    }

    if ($error === '' && !$rows) {
        $error = 'Please enter at least one action item.';
    }

    if ($error === '') {
        $projectQuery = mysqli_query($conn, "
            SELECT p.project_name, p.client_id, c.client_name, p.manager_employee_id
            FROM projects p
            LEFT JOIN clients c ON c.id = p.client_id
            WHERE p.id = $postProjectId
            LIMIT 1
        ");

        $projectData = $projectQuery ? mysqli_fetch_assoc($projectQuery) : null;
        $projectName = (string)($projectData['project_name'] ?? '');
        $clientName = (string)($projectData['client_name'] ?? '');
        $clientId = (int)($projectData['client_id'] ?? 0);

        mysqli_begin_transaction($conn);

        try {
            if ($postSubmissionId > 0) {
                $existingId = ait_existing_for_resubmit(
                    $conn,
                    $postSubmissionId,
                    $postProjectId,
                    $employeeId,
                    $postReportDate
                );

                if ($existingId <= 0) {
                    throw new RuntimeException('Original AIT not found for resubmission.');
                }

                $stmt = mysqli_prepare($conn, "
                    UPDATE ait_main
                    SET ait_no = ?,
                        project_id = ?,
                        site_id = ?,
                        client_id = ?,
                        project_name = ?,
                        client_name = ?,
                        architects = ?,
                        pmc = ?,
                        revisions = ?,
                        ait_date = ?,
                        created_by_name = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                if (!$stmt) {
                    throw new RuntimeException('Database error: ' . mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'siiisssssssi',
                    $aitNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $architects,
                    $pmc,
                    $revisions,
                    $postReportDate,
                    $preparedBy,
                    $existingId
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException('Failed to update AIT: ' . mysqli_stmt_error($stmt));
                }

                mysqli_stmt_close($stmt);
                mysqli_query($conn, "DELETE FROM ait_details WHERE ait_main_id = $existingId");

                $aitId = $existingId;
                $activityType = 'UPDATE';
                $activityText = 'Resubmitted AIT ' . $aitNo;
                $notificationTitle = 'AIT Resubmitted';
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO ait_main
                    (
                        ait_no, project_id, site_id, client_id, project_name, client_name,
                        architects, pmc, revisions, ait_date, created_by, created_by_name
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt) {
                    throw new RuntimeException('Database error: ' . mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'siiissssssis',
                    $aitNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $architects,
                    $pmc,
                    $revisions,
                    $postReportDate,
                    $employeeId,
                    $preparedBy
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException('Failed to save AIT: ' . mysqli_stmt_error($stmt));
                }

                $aitId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                $activityType = 'CREATE';
                $activityText = 'Submitted AIT ' . $aitNo;
                $notificationTitle = 'New AIT Submitted';
            }

            $detailStmt = mysqli_prepare($conn, "
                INSERT INTO ait_details
                (
                    ait_main_id, sl_no, dated, description, priority,
                    responsible_by, due_date, completion_date, progress_notes, status
                )
                VALUES (?, ?, NULLIF(?, ''), ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?)
            ");

            if (!$detailStmt) {
                throw new RuntimeException('Detail query error: ' . mysqli_error($conn));
            }

            foreach ($rows as $row) {
                mysqli_stmt_bind_param(
                    $detailStmt,
                    'iissssssss',
                    $aitId,
                    $row['sl_no'],
                    $row['dated'],
                    $row['description'],
                    $row['priority'],
                    $row['responsible_by'],
                    $row['due_date'],
                    $row['completion_date'],
                    $row['progress_notes'],
                    $row['status']
                );

                if (!mysqli_stmt_execute($detailStmt)) {
                    throw new RuntimeException('Failed to save AIT detail: ' . mysqli_stmt_error($detailStmt));
                }
            }

            mysqli_stmt_close($detailStmt);

            ait_sync_submission(
                $conn,
                $postSubmissionId,
                $postProjectId,
                $employeeId,
                $postReportDate,
                $aitId,
                $aitNo
            );

            ait_log_activity($conn, $activityType, $activityText, $aitId);

            if (!empty($projectData['manager_employee_id'])) {
                ait_notify_employee(
                    $conn,
                    (int)$projectData['manager_employee_id'],
                    $notificationTitle,
                    $preparedBy . ' submitted ' . $aitNo . ' for ' . $projectName,
                    $aitId,
                    'reports-print/report-ait-print.php?view=' . $aitId
                );
            }

            mysqli_commit($conn);

            ait_redirect_hub(
                $postProjectId,
                $postReportDate,
                $postSubmissionId > 0 ? 'resubmitted' : 'saved'
            );
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            $error = $exception->getMessage();
        }
    }

    if ($error !== '') {
        header(
            'Location: ait.php'
            . '?project_id=' . $postProjectId
            . '&report_date=' . urlencode($postReportDate)
            . '&error=' . urlencode($error)
        );
        exit;
    }
}

$recentReports = [];
$recentQuery = mysqli_query($conn, "
    SELECT m.id, m.ait_no, m.ait_date, m.project_name, COUNT(d.id) AS item_count
    FROM ait_main m
    LEFT JOIN ait_details d ON d.ait_main_id = m.id
    WHERE m.created_by = $employeeId
    GROUP BY m.id
    ORDER BY m.created_at DESC
    LIMIT 10
");

while ($recentQuery && ($recentRow = mysqli_fetch_assoc($recentQuery))) {
    $recentReports[] = $recentRow;
}

$formData = [
    'ait_no' => $existingReport['ait_no'] ?? $defaultAitNo,
    'ait_date' => $existingReport['ait_date'] ?? $reportDate,
    'architects' => $existingReport['architects'] ?? '',
    'pmc' => $existingReport['pmc'] ?? '',
    'revisions' => $existingReport['revisions'] ?? '',
    'items' => [],
];

if ($existingReport) {
    $detailQuery = mysqli_query(
        $conn,
        "SELECT * FROM ait_details
         WHERE ait_main_id = " . (int)$existingReport['id'] . "
         ORDER BY sl_no ASC, id ASC"
    );

    while ($detailQuery && ($detailRow = mysqli_fetch_assoc($detailQuery))) {
        $formData['items'][] = $detailRow;
    }
}

if (!$formData['items']) {
    $formData['items'] = array_fill(0, 5, [
        'sl_no' => '',
        'dated' => $reportDate,
        'description' => '',
        'priority' => 'MEDIUM',
        'responsible_by' => '',
        'due_date' => date('Y-m-d', strtotime($reportDate . ' +7 days')),
        'completion_date' => '',
        'progress_notes' => '',
        'status' => 'OPEN',
    ]);
}

$previousTemplatePayload = null;

if ($previousTemplate) {
    $items = [];
    $detailQuery = mysqli_query(
        $conn,
        "SELECT * FROM ait_details
         WHERE ait_main_id = " . (int)$previousTemplate['id'] . "
         ORDER BY sl_no ASC, id ASC"
    );

    while ($detailQuery && ($detailRow = mysqli_fetch_assoc($detailQuery))) {
        $items[] = $detailRow;
    }

    $previousTemplatePayload = [
        'architects' => $previousTemplate['architects'] ?? '',
        'pmc' => $previousTemplate['pmc'] ?? '',
        'revisions' => $previousTemplate['revisions'] ?? '',
        'ait_no' => $previousTemplate['ait_no'] ?? '',
        'ait_date' => $previousTemplate['ait_date'] ?? '',
        'items' => $items,
    ];
}
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AIT - TEK-C PMC Construction</title>
    <?php include 'includes/links.php'; ?>

    <style>
        .page-head-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px;
        }

        .section-box {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 18px;
        }

        .mini-head {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .mini-icon {
            width: 44px;
            height: 44px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(37, 99, 235, .12);
            color: #2563eb;
        }

        .form-control,
        .form-select {
            background: var(--card-bg);
            color: var(--text-main);
            border-color: var(--border-soft);
            min-height: 42px;
            font-size: 13px;
            font-weight: 700;
        }

        .ait-table {
            min-width: 1500px;
        }

        .ait-table th {
            font-size: 11px;
            text-transform: uppercase;
            color: var(--text-muted);
            font-weight: 900;
            background: rgba(148, 163, 184, .10);
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
        }

        .ait-table td {
            vertical-align: top;
        }

        .ait-table textarea {
            min-width: 210px;
        }

        .badge-soft {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border: 1px solid var(--border-soft);
            background: rgba(148, 163, 184, .08);
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 900;
        }

        .badge-soft input {
            width: 15px;
            height: 15px;
        }

        .recent-card {
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 12px;
            background: rgba(148, 163, 184, .06);
        }
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
                        <h1 class="h4 fw-bold mb-1">
                            <?= $resubmitSubmissionId > 0
                                ? 'Resubmit Action Item Tracker (AIT)'
                                : 'Action Item Tracker (AIT)' ?>
                        </h1>
                        <p class="text-muted-custom mb-0 small">
                            Record, assign and track project action items.
                        </p>
                    </div>

                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <label class="badge-soft mb-0 <?= !$previousTemplatePayload ? 'opacity-75' : '' ?>"
                               title="<?= $previousTemplatePayload
                                   ? 'Load previous AIT data without changing AIT No, Date or Project'
                                   : 'No previous AIT data found for this project' ?>">
                            <input type="checkbox"
                                   class="form-check-input mt-0"
                                   id="loadPreviousAitData"
                                   <?= !$previousTemplatePayload ? 'disabled' : '' ?>>
                            <span>
                                <strong>Load previous data</strong>
                                <small class="d-block text-muted-custom fw-semibold">
                                    <?php if ($previousTemplatePayload): ?>
                                        <?= ait_e($previousTemplatePayload['ait_no']) ?> ·
                                        <?= ait_e(date('d M Y', strtotime($previousTemplatePayload['ait_date']))) ?>
                                    <?php else: ?>
                                        No previous data
                                    <?php endif; ?>
                                </small>
                            </span>
                        </label>

                        <span class="badge-soft">
                            <i data-lucide="user" style="width:15px;height:15px"></i>
                            <?= ait_e($preparedBy) ?>
                        </span>

                        <a href="reports-hub.php"
                           class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">
                            Back to Reports Hub
                        </a>
                    </div>
                </div>
            </div>

            <div class="section-box mb-3">
                <div class="mini-head">
                    <div class="mini-icon"><i data-lucide="map-pin"></i></div>
                    <div>
                        <h2 class="fw-bold fs-6 mb-1">Project Selection</h2>
                        <p class="text-muted-custom small mb-0">
                            Choose an assigned project to prepare AIT.
                        </p>
                    </div>
                </div>

                <div class="row g-3 align-items-end">
                    <div class="col-lg-9">
                        <label class="form-label fw-bold small">
                            Assigned Project <span class="text-danger">*</span>
                        </label>

                        <select class="form-select rounded-4" id="projectPicker">
                            <option value="">-- Select Project --</option>

                            <?php foreach ($projects as $projectOption): ?>
                                <option value="<?= (int)$projectOption['id'] ?>"
                                    <?= (int)$projectOption['id'] === $projectId ? 'selected' : '' ?>>
                                    <?= ait_e($projectOption['project_name']) ?>
                                    <?= !empty($projectOption['project_code'])
                                        ? ' (' . ait_e($projectOption['project_code']) . ')'
                                        : '' ?>
                                    - <?= ait_e($projectOption['project_location'] ?: '-') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3">
                        <a href="ait.php"
                           class="btn btn-outline-secondary rounded-4 fw-bold w-100">
                            Reset
                        </a>
                    </div>
                </div>
            </div>

            <div class="section-box mb-3">
                <div class="mini-head">
                    <div class="mini-icon"><i data-lucide="building-2"></i></div>
                    <div>
                        <h2 class="fw-bold fs-6 mb-1">Project Details</h2>
                        <p class="text-muted-custom small mb-0">
                            Auto-filled from the selected project.
                        </p>
                    </div>
                </div>

                <?php if (!$project): ?>
                    <p class="text-muted-custom fw-bold mb-0">
                        Please select a project above.
                    </p>
                <?php else: ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Project</small>
                            <div class="fw-bold"><?= ait_e($project['project_name']) ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Client</small>
                            <div class="fw-bold"><?= ait_e($project['client_name'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Location</small>
                            <div class="fw-bold"><?= ait_e($project['project_location'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Project Type</small>
                            <div class="fw-bold"><?= ait_e($project['project_type_name'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Project Start</small>
                            <div class="fw-bold"><?= ait_e($project['start_date'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Expected Completion</small>
                            <div class="fw-bold"><?= ait_e($project['expected_completion_date'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Schedule</small>
                            <div class="fw-bold">
                                <?= ait_e($pmsSchedule['schedule_name'] ?? 'PMS Schedule') ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Schedule Start</small>
                            <div class="fw-bold">
                                <?= ait_e($pmsScheduleStart ?: ($project['start_date'] ?? '-')) ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Schedule End</small>
                            <div class="fw-bold">
                                <?= ait_e($pmsScheduleEnd ?: ($project['expected_completion_date'] ?? '-')) ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Status</small>
                            <div class="fw-bold">
                                <?= ait_e(ucwords(str_replace('_', ' ', $pmsSchedule['schedule_status'] ?? '-'))) ?>
                            </div>
                        </div>

                        <div class="col-12">
                            <small class="text-muted-custom fw-bold">Scope of Work</small>
                            <div class="fw-bold"><?= ait_e($project['scope_of_work'] ?: '-') ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="submit_ait" value="1">
                <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
                <input type="hidden" name="resubmit_submission_id" value="<?= (int)$resubmitSubmissionId ?>">

                <div class="section-box mb-3">
                    <div class="mini-head">
                        <div class="mini-icon"><i data-lucide="clipboard-check"></i></div>
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">AIT Header</h2>
                            <p class="text-muted-custom small mb-0">
                                Report number, date, project parties and revision details.
                            </p>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">
                                AIT No <span class="text-danger">*</span>
                            </label>
                            <input class="form-control rounded-4"
                                   name="ait_no"
                                   value="<?= ait_e($formData['ait_no']) ?>"
                                   required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">
                                AIT Date <span class="text-danger">*</span>
                            </label>
                            <input type="date"
                                   class="form-control rounded-4"
                                   name="ait_date"
                                   id="ait_date"
                                   value="<?= ait_e($formData['ait_date']) ?>"
                                   required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Revisions / Dated</label>
                            <input class="form-control rounded-4"
                                   name="revisions"
                                   id="revisions"
                                   value="<?= ait_e($formData['revisions']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Project</label>
                            <input class="form-control rounded-4"
                                   value="<?= ait_e($project['project_name'] ?? '') ?>"
                                   readonly>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Client</label>
                            <input class="form-control rounded-4"
                                   value="<?= ait_e($project['client_name'] ?? '') ?>"
                                   readonly>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Architects</label>
                            <input class="form-control rounded-4"
                                   name="architects"
                                   id="architects"
                                   value="<?= ait_e($formData['architects']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">PMC</label>
                            <input class="form-control rounded-4"
                                   name="pmc"
                                   id="pmc"
                                   value="<?= ait_e($formData['pmc']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Prepared By</label>
                            <input class="form-control rounded-4"
                                   value="<?= ait_e($preparedBy) ?>"
                                   readonly>
                        </div>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-2 mb-3">
                        <div class="mini-head mb-0">
                            <div class="mini-icon"><i data-lucide="list-checks"></i></div>
                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Action Items</h2>
                                <p class="text-muted-custom small mb-0">
                                    Add responsibilities, dates, progress and status.
                                </p>
                            </div>
                        </div>

                        <button type="button"
                                class="btn btn-outline-primary rounded-4 fw-bold"
                                id="addAitRow">
                            Add Row
                        </button>
                    </div>

                    <div class="table-responsive thin-scrollbar">
                        <table class="table table-bordered align-middle mb-0 ait-table">
                            <thead>
                            <tr>
                                <th style="width:70px">SL No</th>
                                <th>Dated</th>
                                <th>Description</th>
                                <th>Priority</th>
                                <th>Responsible By</th>
                                <th>Due Date</th>
                                <th>Completion Date</th>
                                <th>Progress Notes</th>
                                <th>Status</th>
                                <th>Del</th>
                            </tr>
                            </thead>

                            <tbody id="aitBody">
                            <?php foreach ($formData['items'] as $index => $item): ?>
                                <tr>
                                    <td>
                                        <input class="form-control rounded-4 text-center sl-no"
                                               name="sl_no[]"
                                               value="<?= $index + 1 ?>"
                                               readonly>
                                    </td>

                                    <td>
                                        <input type="date"
                                               class="form-control rounded-4"
                                               name="dated[]"
                                               value="<?= ait_e($item['dated'] ?? '') ?>">
                                    </td>

                                    <td>
                                        <textarea class="form-control rounded-4"
                                                  name="description[]"
                                                  rows="2"><?= ait_e($item['description'] ?? '') ?></textarea>
                                    </td>

                                    <td>
                                        <select class="form-select rounded-4" name="priority[]">
                                            <?php foreach (['LOW', 'MEDIUM', 'HIGH', 'URGENT'] as $priority): ?>
                                                <option value="<?= $priority ?>"
                                                    <?= ($item['priority'] ?? 'MEDIUM') === $priority ? 'selected' : '' ?>>
                                                    <?= $priority ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <td>
                                        <input class="form-control rounded-4"
                                               name="responsible_by[]"
                                               value="<?= ait_e($item['responsible_by'] ?? '') ?>">
                                    </td>

                                    <td>
                                        <input type="date"
                                               class="form-control rounded-4"
                                               name="due_date[]"
                                               value="<?= ait_e($item['due_date'] ?? '') ?>">
                                    </td>

                                    <td>
                                        <input type="date"
                                               class="form-control rounded-4"
                                               name="completion_date[]"
                                               value="<?= ait_e($item['completion_date'] ?? '') ?>">
                                    </td>

                                    <td>
                                        <textarea class="form-control rounded-4"
                                                  name="progress_notes[]"
                                                  rows="2"><?= ait_e($item['progress_notes'] ?? '') ?></textarea>
                                    </td>

                                    <td>
                                        <select class="form-select rounded-4" name="status[]">
                                            <?php foreach (['OPEN', 'IN PROGRESS', 'COMPLETED', 'BLOCKED', 'CANCELLED'] as $status): ?>
                                                <option value="<?= $status ?>"
                                                    <?= ($item['status'] ?? 'OPEN') === $status ? 'selected' : '' ?>>
                                                    <?= $status ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <td class="text-center">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger rounded-4 delRow">
                                            <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="d-flex justify-content-end">
                        <button type="submit"
                                class="btn brand-gradient text-white rounded-4 fw-bold px-4"
                                <?= !$project ? 'disabled' : '' ?>>
                            <?= $resubmitSubmissionId > 0 ? 'Resubmit AIT' : 'Submit AIT' ?>
                        </button>
                    </div>
                </div>
            </form>

            <section class="card-ui overflow-hidden">
                <div class="p-3 p-lg-4">
                    <h2 class="fw-bold fs-6 mb-1">Recent AIT</h2>
                    <p class="text-muted-custom small mb-0">
                        Your latest Action Item Tracker submissions.
                    </p>
                </div>

                <div class="px-3 px-lg-4 pb-4">
                    <?php if (!$recentReports): ?>
                        <p class="text-muted-custom fw-bold mb-0">
                            No AIT submitted yet.
                        </p>
                    <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($recentReports as $recent): ?>
                                <div class="col-md-6 col-xl-4">
                                    <div class="recent-card">
                                        <div class="d-flex justify-content-between gap-2">
                                            <div>
                                                <div class="fw-bold"><?= ait_e($recent['ait_no']) ?></div>
                                                <small class="text-muted-custom">
                                                    <?= ait_e($recent['project_name']) ?> ·
                                                    <?= ait_e(date('d M Y', strtotime($recent['ait_date']))) ?> ·
                                                    <?= (int)$recent['item_count'] ?> item(s)
                                                </small>
                                            </div>

                                            <a href="reports-print/report-ait-print.php?view=<?= (int)$recent['id'] ?>"
                                               target="_blank"
                                               class="btn btn-sm btn-outline-primary rounded-4 fw-bold">
                                                Print
                                            </a>
                                        </div>
                                    </div>
                                </div>
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
<script src="assets/js/script.js?v=42"></script>

<script>
const previousAitData =
    <?= json_encode($previousTemplatePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const aitBody = document.getElementById('aitBody');

const currentAitSnapshot = {
    architects: document.getElementById('architects')?.value || '',
    pmc: document.getElementById('pmc')?.value || '',
    revisions: document.getElementById('revisions')?.value || '',
    items: Array.from(document.querySelectorAll('#aitBody tr')).map(function(row) {
        return {
            dated: row.querySelector('[name="dated[]"]')?.value || '',
            description: row.querySelector('[name="description[]"]')?.value || '',
            priority: row.querySelector('[name="priority[]"]')?.value || 'MEDIUM',
            responsible_by: row.querySelector('[name="responsible_by[]"]')?.value || '',
            due_date: row.querySelector('[name="due_date[]"]')?.value || '',
            completion_date: row.querySelector('[name="completion_date[]"]')?.value || '',
            progress_notes: row.querySelector('[name="progress_notes[]"]')?.value || '',
            status: row.querySelector('[name="status[]"]')?.value || 'OPEN'
        };
    })
};

document.getElementById('projectPicker')?.addEventListener('change', function() {
    const date = document.getElementById('ait_date')?.value || '<?= ait_e($reportDate) ?>';

    window.location.href = this.value
        ? 'ait.php?project_id=' + encodeURIComponent(this.value)
            + '&report_date=' + encodeURIComponent(date)
        : 'ait.php';
});

function renumberRows() {
    aitBody.querySelectorAll('tr').forEach(function(row, index) {
        const input = row.querySelector('.sl-no');
        if (input) input.value = index + 1;
    });

    if (window.lucide) {
        window.lucide.createIcons();
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function rowHtml(values = {}) {
    const priority = values.priority || 'MEDIUM';
    const status = values.status || 'OPEN';

    return `
        <td><input class="form-control rounded-4 text-center sl-no" name="sl_no[]" readonly></td>
        <td><input type="date" class="form-control rounded-4" name="dated[]" value="${escapeHtml(values.dated || '')}"></td>
        <td><textarea class="form-control rounded-4" name="description[]" rows="2">${escapeHtml(values.description || '')}</textarea></td>
        <td>
            <select class="form-select rounded-4" name="priority[]">
                ${['LOW','MEDIUM','HIGH','URGENT'].map(item =>
                    `<option value="${item}" ${item === priority ? 'selected' : ''}>${item}</option>`
                ).join('')}
            </select>
        </td>
        <td><input class="form-control rounded-4" name="responsible_by[]" value="${escapeHtml(values.responsible_by || '')}"></td>
        <td><input type="date" class="form-control rounded-4" name="due_date[]" value="${escapeHtml(values.due_date || '')}"></td>
        <td><input type="date" class="form-control rounded-4" name="completion_date[]" value="${escapeHtml(values.completion_date || '')}"></td>
        <td><textarea class="form-control rounded-4" name="progress_notes[]" rows="2">${escapeHtml(values.progress_notes || '')}</textarea></td>
        <td>
            <select class="form-select rounded-4" name="status[]">
                ${['OPEN','IN PROGRESS','COMPLETED','BLOCKED','CANCELLED'].map(item =>
                    `<option value="${item}" ${item === status ? 'selected' : ''}>${item}</option>`
                ).join('')}
            </select>
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger rounded-4 delRow">
                <i data-lucide="trash-2" style="width:14px;height:14px"></i>
            </button>
        </td>
    `;
}

function addRow(values = {}) {
    const row = document.createElement('tr');
    row.innerHTML = rowHtml(values);
    aitBody.appendChild(row);
    renumberRows();
}

document.getElementById('addAitRow')?.addEventListener('click', function() {
    addRow({
        dated: document.getElementById('ait_date')?.value || '',
        priority: 'MEDIUM',
        status: 'OPEN'
    });
});

document.addEventListener('click', function(event) {
    const button = event.target.closest('.delRow');
    if (!button) return;

    const row = button.closest('tr');
    if (!row) return;

    if (aitBody.querySelectorAll('tr').length <= 1) {
        row.querySelectorAll('input:not(.sl-no), textarea').forEach(input => input.value = '');
        row.querySelector('[name="priority[]"]').value = 'MEDIUM';
        row.querySelector('[name="status[]"]').value = 'OPEN';
    } else {
        row.remove();
    }

    renumberRows();
});

document.getElementById('loadPreviousAitData')?.addEventListener('change', function() {
    const source = this.checked ? previousAitData : currentAitSnapshot;
    if (!source) return;

    const architects = document.getElementById('architects');
    const pmc = document.getElementById('pmc');
    const revisions = document.getElementById('revisions');

    if (architects) architects.value = source.architects || '';
    if (pmc) pmc.value = source.pmc || '';
    if (revisions) revisions.value = source.revisions || '';

    aitBody.innerHTML = '';

    const items = Array.isArray(source.items) && source.items.length
        ? source.items
        : [{}];

    items.forEach(item => addRow(item));

    /*
     * Unique fields are intentionally not overwritten:
     * - AIT No
     * - AIT Date
     * - Project
     * - Resubmit submission ID
     * - Prepared By
     */
});

window.addEventListener('load', function() {
    renumberRows();

    if (window.lucide) {
        window.lucide.createIcons();
    }
});
</script>
</body>
</html>
