<?php
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

function wpt_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function wpt_employee_id(mysqli $conn): int
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

function wpt_is_super_admin(mysqli $conn): bool
{
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        return false;
    }

    $query = mysqli_query($conn, "
        SELECT r.id
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = $userId
          AND r.is_active = 1
          AND (
                r.role_slug = 'super-admin'
             OR LOWER(r.role_name) = 'super admin'
          )
        LIMIT 1
    ");

    return $query && mysqli_num_rows($query) > 0;
}

function wpt_report_access(mysqli $conn, string $permission): bool
{
    if (wpt_is_super_admin($conn)) {
        return true;
    }

    $allowed = [
        'can_submit',
        'can_view',
        'can_remark_tl',
        'can_remark_manager'
    ];

    if (!in_array($permission, $allowed, true)) {
        return false;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        return false;
    }

    $query = mysqli_query($conn, "
        SELECT MAX(COALESCE(rtra.$permission, 0)) AS allowed
        FROM user_roles ur
        INNER JOIN report_type_role_access rtra
            ON rtra.role_id = ur.role_id
        INNER JOIN master_report_types rt
            ON rt.id = rtra.report_type_id
        WHERE ur.user_id = $userId
          AND rt.report_code = 'WPT'
          AND rt.is_active = 1
    ");

    return $query
        && ($row = mysqli_fetch_assoc($query))
        && (int)($row['allowed'] ?? 0) === 1;
}

function wpt_project_allowed(
    mysqli $conn,
    int $projectId,
    int $employeeId,
    bool $isSuperAdmin
): bool {
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

function wpt_table_columns(mysqli $conn, string $table): array
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columns = [];

    $query = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $columns[$row['Field']] = true;
    }

    return $columns;
}

function wpt_sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function wpt_report_type_id(mysqli $conn): int
{
    $query = mysqli_query($conn, "
        SELECT id
        FROM master_report_types
        WHERE report_code = 'WPT'
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function wpt_existing_for_resubmit(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate
): int {
    if ($submissionId > 0) {
        $columns = wpt_table_columns($conn, 'project_report_submissions');
        $selectColumns = ['id'];

        foreach (['report_reference_id', 'source_id', 'reference_id'] as $column) {
            if (isset($columns[$column])) {
                $selectColumns[] = $column;
            }
        }

        $query = mysqli_query(
            $conn,
            "SELECT " . implode(',', array_unique($selectColumns)) . "
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
                    "SELECT id FROM wpt_main WHERE id = $candidateId LIMIT 1"
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
        FROM wpt_main
        WHERE project_id = $projectId
          AND created_by = $employeeId
          AND week_ends_on = '$dateEsc'
        ORDER BY id DESC
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function wpt_sync_submission(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate,
    int $wptId,
    string $wptNo
): void {
    $reportTypeId = wpt_report_type_id($conn);

    if ($reportTypeId <= 0 || $projectId <= 0 || $employeeId <= 0 || $wptId <= 0) {
        return;
    }

    $columns = wpt_table_columns($conn, 'project_report_submissions');
    $userId = (int)($_SESSION['user_id'] ?? 0);

    /*
     * WPT is a weekly report in Reports Hub.
     * Save the complete Monday-to-Sunday period so the hub can
     * detect the submission and build the print button.
     */
    $reportTimestamp = strtotime($reportDate);
    $periodStart = $reportTimestamp
        ? date('Y-m-d', strtotime('monday this week', $reportTimestamp))
        : $reportDate;
    $periodEnd = $reportTimestamp
        ? date('Y-m-d', strtotime('sunday this week', $reportTimestamp))
        : $reportDate;

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
                 OR (
                        period_start = '$periodStartEsc'
                    AND period_end = '$periodEndEsc'
                 )
              )
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($query && ($row = mysqli_fetch_assoc($query))) {
            $submissionId = (int)$row['id'];
        }
    }

    $map = [
        'report_no' => $wptNo,
        'report_number' => $wptNo,
        'submission_no' => $wptNo,
        'title' => 'Work Progress Tracker',
        'submitted_by_employee_id' => $employeeId,
        'submitted_by_user_id' => $userId > 0 ? $userId : null,
        'submission_for_date' => $reportDate,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'status' => 'submitted',
        'submitted_at' => date('Y-m-d H:i:s'),
        'source_table' => 'wpt_main',
        'source_id' => $wptId,
        'report_reference_table' => 'wpt_main',
        'report_reference_id' => $wptId,
        'reference_id' => $wptId,
        'updated_by' => $userId > 0 ? $userId : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($submissionId > 0) {
        $sets = [];

        foreach ($map as $field => $value) {
            if (isset($columns[$field])) {
                $sets[] = "`$field` = " . wpt_sql_value($conn, $value);
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
            $insertValues[] = wpt_sql_value($conn, $value);
        }
    }

    if ($insertColumns) {
        mysqli_query(
            $conn,
            "INSERT INTO project_report_submissions
            (" . implode(',', $insertColumns) . ")
            VALUES
            (" . implode(',', $insertValues) . ")"
        );
    }
}

function wpt_redirect_hub(
    int $projectId,
    string $reportDate,
    string $flag
): void {
    $flag = preg_replace('/[^a-zA-Z0-9_]/', '', $flag);

    $timestamp = strtotime($reportDate);
    $periodStart = $timestamp
        ? date('Y-m-d', strtotime('monday this week', $timestamp))
        : $reportDate;
    $periodEnd = $timestamp
        ? date('Y-m-d', strtotime('sunday this week', $timestamp))
        : $reportDate;

    header(
        'Location: reports-hub.php'
        . '?project_id=' . $projectId
        . '&report_date=' . urlencode($reportDate)
        . '&period_start=' . urlencode($periodStart)
        . '&period_end=' . urlencode($periodEnd)
        . '&' . $flag . '=1'
    );

    exit;
}

$employeeId = wpt_employee_id($conn);
$isSuperAdmin = wpt_is_super_admin($conn);

if ($employeeId <= 0) {
    header('Location: login.php');
    exit;
}

if (!wpt_report_access($conn, 'can_submit')) {
    header(
        'Location: reports-hub.php?error='
        . urlencode('You do not have WPT submit access.')
    );
    exit;
}

$employeeQuery = mysqli_query(
    $conn,
    "SELECT *
     FROM employees
     WHERE id = $employeeId
     LIMIT 1"
);

$employee = $employeeQuery
    ? mysqli_fetch_assoc($employeeQuery)
    : null;

$preparedBy = (string)(
    $employee['full_name']
    ?? $_SESSION['employee_name']
    ?? $_SESSION['name']
    ?? ''
);

$projectId = (int)(
    $_GET['project_id']
    ?? $_POST['project_id']
    ?? 0
);

$reportDate = trim((string)(
    $_GET['report_date']
    ?? $_POST['week_ends_on']
    ?? date('Y-m-d', strtotime('this saturday'))
));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    $reportDate = date('Y-m-d', strtotime('this saturday'));
}

$resubmitSubmissionId = (int)(
    $_GET['resubmit_submission_id']
    ?? $_POST['resubmit_submission_id']
    ?? 0
);

$projects = [];

if ($isSuperAdmin) {
    $projectsQuery = mysqli_query($conn, "
        SELECT
            p.id,
            p.project_name,
            p.project_code,
            p.project_location,
            c.client_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        WHERE p.deleted_at IS NULL
        ORDER BY p.project_name ASC
    ");
} else {
    $projectsQuery = mysqli_query($conn, "
        SELECT DISTINCT
            p.id,
            p.project_name,
            p.project_code,
            p.project_location,
            c.client_name
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

if (
    $projectId > 0
    && wpt_project_allowed(
        $conn,
        $projectId,
        $employeeId,
        $isSuperAdmin
    )
) {
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
        LEFT JOIN master_project_types mpt
            ON mpt.id = p.project_type_id
        LEFT JOIN employees manager
            ON manager.id = p.manager_employee_id
        LEFT JOIN employees team_lead_emp
            ON team_lead_emp.id = p.team_lead_employee_id
        WHERE p.id = $projectId
          AND p.deleted_at IS NULL
        LIMIT 1
    ");

    if ($projectQuery) {
        $project = mysqli_fetch_assoc($projectQuery);
    }
}

$pmsSchedule = $projectId > 0
    ? pms_project_schedule($conn, $projectId)
    : null;

[$pmsScheduleStart, $pmsScheduleEnd] =
    pms_schedule_date_range($pmsSchedule, $project);

$defaultWptNo = '';

if ($projectId > 0) {
    $yearMonth = date('Ym', strtotime($reportDate));
    $prefix = 'WPT/' . $projectId . '/' . $yearMonth . '/';
    $prefixEsc = mysqli_real_escape_string($conn, $prefix);

    $countQuery = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM wpt_main
         WHERE wpt_no LIKE '$prefixEsc%'"
    );

    $count = $countQuery
        ? (int)(mysqli_fetch_assoc($countQuery)['total'] ?? 0)
        : 0;

    $defaultWptNo = $prefix
        . str_pad((string)($count + 1), 3, '0', STR_PAD_LEFT);
}

$existingReport = null;

if ($resubmitSubmissionId > 0 && $projectId > 0) {
    $existingId = wpt_existing_for_resubmit(
        $conn,
        $resubmitSubmissionId,
        $projectId,
        $employeeId,
        $reportDate
    );

    if ($existingId > 0) {
        $existingQuery = mysqli_query(
            $conn,
            "SELECT *
             FROM wpt_main
             WHERE id = $existingId
             LIMIT 1"
        );

        if ($existingQuery) {
            $existingReport = mysqli_fetch_assoc($existingQuery);
        }
    }
}

$previousTemplate = null;

if ($projectId > 0) {
    $safeDate = mysqli_real_escape_string($conn, $reportDate);

    $queries = [
        "
        SELECT *
        FROM wpt_main
        WHERE project_id = $projectId
          AND created_by = $employeeId
          AND week_ends_on < '$safeDate'
        ORDER BY week_ends_on DESC, created_at DESC, id DESC
        LIMIT 1
        ",
        "
        SELECT *
        FROM wpt_main
        WHERE project_id = $projectId
          AND created_by = $employeeId
        ORDER BY week_ends_on DESC, created_at DESC, id DESC
        LIMIT 1
        ",
        "
        SELECT *
        FROM wpt_main
        WHERE project_id = $projectId
        ORDER BY week_ends_on DESC, created_at DESC, id DESC
        LIMIT 1
        ",
    ];

    foreach ($queries as $sql) {
        $query = mysqli_query($conn, $sql);

        if ($query && ($previousTemplate = mysqli_fetch_assoc($query))) {
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_wpt'])) {
    $postProjectId = (int)($_POST['project_id'] ?? 0);
    $postReportDate = trim((string)($_POST['week_ends_on'] ?? ''));
    $postSubmissionId = (int)($_POST['resubmit_submission_id'] ?? 0);

    $wptNo = trim((string)($_POST['wpt_no'] ?? ''));
    $contractor = trim((string)($_POST['contractor'] ?? ''));
    $scopeOfWork = trim((string)($_POST['scope_of_work'] ?? ''));
    $architect = trim((string)($_POST['architect'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));

    $items = json_decode(
        (string)($_POST['items_json'] ?? '[]'),
        true
    );

    $error = '';

    if (
        !wpt_project_allowed(
            $conn,
            $postProjectId,
            $employeeId,
            $isSuperAdmin
        )
    ) {
        $error = 'Invalid project selection.';
    } elseif ($wptNo === '') {
        $error = 'WPT No is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postReportDate)) {
        $error = 'Valid week ending date is required.';
    } elseif (!is_array($items)) {
        $error = 'Invalid task rows.';
    }

    $items = array_values(array_filter(
        is_array($items) ? $items : [],
        static function ($row): bool {
            return trim((string)($row['task_name'] ?? '')) !== '';
        }
    ));

    if ($error === '' && !$items) {
        $error = 'Please enter at least one task.';
    }

    if ($error === '') {
        $projectQuery = mysqli_query($conn, "
            SELECT
                p.project_name,
                p.client_id,
                c.client_name
            FROM projects p
            LEFT JOIN clients c ON c.id = p.client_id
            WHERE p.id = $postProjectId
            LIMIT 1
        ");

        $projectData = $projectQuery
            ? mysqli_fetch_assoc($projectQuery)
            : null;

        $projectName = (string)($projectData['project_name'] ?? '');
        $clientId = (int)($projectData['client_id'] ?? 0);
        $clientName = (string)($projectData['client_name'] ?? '');

        mysqli_begin_transaction($conn);

        try {
            if ($postSubmissionId > 0) {
                $wptId = wpt_existing_for_resubmit(
                    $conn,
                    $postSubmissionId,
                    $postProjectId,
                    $employeeId,
                    $postReportDate
                );

                if ($wptId <= 0) {
                    throw new RuntimeException(
                        'Original WPT not found for resubmission.'
                    );
                }

                $stmt = mysqli_prepare($conn, "
                    UPDATE wpt_main
                    SET wpt_no = ?,
                        project_id = ?,
                        site_id = ?,
                        client_id = ?,
                        project_name = ?,
                        client_name = ?,
                        contractor = ?,
                        scope_of_work = ?,
                        architect = ?,
                        pmc = ?,
                        week_ends_on = ?,
                        created_by_name = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                if (!$stmt) {
                    throw new RuntimeException(
                        'Database error: ' . mysqli_error($conn)
                    );
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'siiissssssssi',
                    $wptNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $contractor,
                    $scopeOfWork,
                    $architect,
                    $pmc,
                    $postReportDate,
                    $preparedBy,
                    $wptId
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(
                        'Failed to update WPT: '
                        . mysqli_stmt_error($stmt)
                    );
                }

                mysqli_stmt_close($stmt);

                mysqli_query(
                    $conn,
                    "DELETE FROM wpt_details
                     WHERE wpt_main_id = $wptId"
                );
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO wpt_main
                    (
                        wpt_no,
                        project_id,
                        site_id,
                        client_id,
                        project_name,
                        client_name,
                        contractor,
                        scope_of_work,
                        architect,
                        pmc,
                        week_ends_on,
                        created_by,
                        created_by_name
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt) {
                    throw new RuntimeException(
                        'Database error: ' . mysqli_error($conn)
                    );
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'siiisssssssis',
                    $wptNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $contractor,
                    $scopeOfWork,
                    $architect,
                    $pmc,
                    $postReportDate,
                    $employeeId,
                    $preparedBy
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(
                        'Failed to save WPT: '
                        . mysqli_stmt_error($stmt)
                    );
                }

                $wptId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }

            $detailStmt = mysqli_prepare($conn, "
                INSERT INTO wpt_details
                (
                    wpt_main_id,
                    sl_no,
                    task_name,
                    duration,
                    start_date,
                    finish_date,
                    schedule_work_done,
                    actual_start,
                    actual_finish,
                    actual_work_done,
                    prev_delay,
                    present_delay,
                    remarks
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    ?,
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            if (!$detailStmt) {
                throw new RuntimeException(
                    'WPT detail SQL error: '
                    . mysqli_error($conn)
                );
            }

            foreach ($items as $index => $item) {
                $slNo = $index + 1;
                $taskName = trim((string)($item['task_name'] ?? ''));
                $duration = trim((string)($item['duration'] ?? ''));
                $startDate = trim((string)($item['start_date'] ?? ''));
                $finishDate = trim((string)($item['finish_date'] ?? ''));
                $scheduleWorkDone = trim((string)($item['schedule_work_done'] ?? ''));
                $actualStart = trim((string)($item['actual_start'] ?? ''));
                $actualFinish = trim((string)($item['actual_finish'] ?? ''));
                $actualWorkDone = trim((string)($item['actual_work_done'] ?? ''));
                $previousDelay = trim((string)($item['prev_delay'] ?? ''));
                $presentDelay = trim((string)($item['present_delay'] ?? ''));
                $remarks = trim((string)($item['remarks'] ?? ''));

                mysqli_stmt_bind_param(
                    $detailStmt,
                    'iisssssssssss',
                    $wptId,
                    $slNo,
                    $taskName,
                    $duration,
                    $startDate,
                    $finishDate,
                    $scheduleWorkDone,
                    $actualStart,
                    $actualFinish,
                    $actualWorkDone,
                    $previousDelay,
                    $presentDelay,
                    $remarks
                );

                if (!mysqli_stmt_execute($detailStmt)) {
                    throw new RuntimeException(
                        'Failed to save WPT task: '
                        . mysqli_stmt_error($detailStmt)
                    );
                }
            }

            mysqli_stmt_close($detailStmt);

            wpt_sync_submission(
                $conn,
                $postSubmissionId,
                $postProjectId,
                $employeeId,
                $postReportDate,
                $wptId,
                $wptNo
            );

            mysqli_commit($conn);

            wpt_redirect_hub(
                $postProjectId,
                $postReportDate,
                $postSubmissionId > 0
                    ? 'resubmitted'
                    : 'saved'
            );
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            $error = $exception->getMessage();
        }
    }

    if ($error !== '') {
        header(
            'Location: wpt.php'
            . '?project_id=' . $postProjectId
            . '&report_date=' . urlencode($postReportDate)
            . '&error=' . urlencode($error)
        );

        exit;
    }
}

$formData = [
    'wpt_no' => $existingReport['wpt_no'] ?? $defaultWptNo,
    'week_ends_on' => $existingReport['week_ends_on'] ?? $reportDate,
    'contractor' => $existingReport['contractor'] ?? '',
    'scope_of_work' => $existingReport['scope_of_work'] ?? '',
    'architect' => $existingReport['architect'] ?? '',
    'pmc' => $existingReport['pmc'] ?? '',
    'items' => [],
];

if ($existingReport) {
    $detailQuery = mysqli_query(
        $conn,
        "SELECT *
         FROM wpt_details
         WHERE wpt_main_id = " . (int)$existingReport['id'] . "
         ORDER BY sl_no ASC, id ASC"
    );

    while ($detailQuery && ($detailRow = mysqli_fetch_assoc($detailQuery))) {
        $formData['items'][] = $detailRow;
    }
}

if (!$formData['items']) {
    $formData['items'] = array_fill(0, 5, [
        'task_name' => '',
        'duration' => '',
        'start_date' => '',
        'finish_date' => '',
        'schedule_work_done' => '',
        'actual_start' => '',
        'actual_finish' => '',
        'actual_work_done' => '',
        'prev_delay' => '',
        'present_delay' => '',
        'remarks' => '',
    ]);
}

$previousTemplatePayload = null;

if ($previousTemplate) {
    $items = [];

    $detailQuery = mysqli_query(
        $conn,
        "SELECT *
         FROM wpt_details
         WHERE wpt_main_id = " . (int)$previousTemplate['id'] . "
         ORDER BY sl_no ASC, id ASC"
    );

    while ($detailQuery && ($detailRow = mysqli_fetch_assoc($detailQuery))) {
        $items[] = $detailRow;
    }

    $previousTemplatePayload = [
        'wpt_no' => $previousTemplate['wpt_no'] ?? '',
        'week_ends_on' => $previousTemplate['week_ends_on'] ?? '',
        'contractor' => $previousTemplate['contractor'] ?? '',
        'scope_of_work' => $previousTemplate['scope_of_work'] ?? '',
        'architect' => $previousTemplate['architect'] ?? '',
        'pmc' => $previousTemplate['pmc'] ?? '',
        'items' => $items,
    ];
}

$recentReports = [];

$recentQuery = mysqli_query($conn, "
    SELECT
        w.id,
        w.wpt_no,
        w.week_ends_on,
        w.project_name,
        COUNT(d.id) AS task_count
    FROM wpt_main w
    LEFT JOIN wpt_details d
        ON d.wpt_main_id = w.id
    WHERE w.created_by = $employeeId
    GROUP BY w.id
    ORDER BY w.created_at DESC
    LIMIT 10
");

while ($recentQuery && ($recentRow = mysqli_fetch_assoc($recentQuery))) {
    $recentReports[] = $recentRow;
}

$pageMessageType = isset($_GET['error']) ? 'error' : '';
$pageMessageText = isset($_GET['error'])
    ? trim((string)$_GET['error'])
    : '';
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>WPT - TEK-C PMC Construction</title>

    <?php include 'includes/links.php'; ?>

    <style>
    .page-head-card,
    .section-box {
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 22px;
        box-shadow: var(--shadow-card);
        padding: 16px;
    }

    .section-box {
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

    .wpt-table {
        min-width: 1650px;
    }

    .wpt-table th {
        font-size: 11px;
        text-transform: uppercase;
        color: var(--text-muted);
        font-weight: 900;
        background: rgba(148, 163, 184, .10);
        white-space: nowrap;
        text-align: center;
        vertical-align: middle;
    }

    .wpt-table td {
        vertical-align: top;
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

    .recent-card {
        border: 1px solid var(--border-soft);
        border-radius: 18px;
        padding: 12px;
        background: rgba(148, 163, 184, .06);
    }
    </style>
</head>

<body>
    <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none">
    </div>

    <?php include 'includes/page-message.php'; ?>

    <div class="min-vh-100 d-flex">
        <?php include 'includes/sidebar.php'; ?>

        <main id="main">
            <?php include 'includes/nav.php'; ?>

            <section class="page-section p-3">
                <div class="page-head-card mb-3">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                        <div>
                            <h1 class="h4 fw-bold mb-1">
                                <?= $resubmitSubmissionId > 0
                                ? 'Resubmit Work Progress Tracker (WPT)'
                                : 'Work Progress Tracker (WPT)' ?>
                            </h1>

                            <p class="text-muted-custom mb-0 small">
                                Weekly task schedule, progress and delay monitoring.
                            </p>
                        </div>

                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <label class="badge-soft mb-0 <?= !$previousTemplatePayload ? 'opacity-75' : '' ?>">
                                <input type="checkbox" id="loadPreviousWptData"
                                    <?= !$previousTemplatePayload ? 'disabled' : '' ?>>

                                <span>
                                    <strong>Load previous data</strong>

                                    <small class="d-block text-muted-custom">
                                        <?= $previousTemplatePayload
                                        ? wpt_e($previousTemplatePayload['wpt_no'])
                                            . ' · '
                                            . wpt_e(date(
                                                'd M Y',
                                                strtotime($previousTemplatePayload['week_ends_on'])
                                            ))
                                        : 'No previous data' ?>
                                    </small>
                                </span>
                            </label>

                            <span class="badge-soft">
                                <i data-lucide="user" style="width:15px"></i>
                                <?= wpt_e($preparedBy) ?>
                            </span>

                            <a href="reports-hub.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">
                                Back to Reports Hub
                            </a>
                        </div>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="mini-head">
                        <div class="mini-icon">
                            <i data-lucide="map-pin"></i>
                        </div>

                        <div>
                            <h2 class="fw-bold fs-6 mb-1">
                                Project Selection
                            </h2>

                            <p class="text-muted-custom small mb-0">
                                Choose an assigned project.
                            </p>
                        </div>
                    </div>

                    <div class="row g-3 align-items-end">
                        <div class="col-lg-9">
                            <label class="form-label fw-bold small">
                                Assigned Project
                            </label>

                            <select id="projectPicker" class="form-select rounded-4">
                                <option value="">-- Select Assigned Project --</option>

                                <?php foreach ($projects as $projectOption): ?>
                                <option value="<?= (int)$projectOption['id'] ?>" <?= (int)$projectOption['id'] === $projectId
                                        ? 'selected'
                                        : '' ?>>
                                    <?= wpt_e($projectOption['project_name']) ?>

                                    <?= !empty($projectOption['project_code'])
                                        ? ' (' . wpt_e($projectOption['project_code']) . ')'
                                        : '' ?>

                                    - <?= wpt_e($projectOption['project_location'] ?: '-') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-3">
                            <a href="wpt.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">
                                Reset
                            </a>
                        </div>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="mini-head">
                        <div class="mini-icon">
                            <i data-lucide="building-2"></i>
                        </div>

                        <div>
                            <h2 class="fw-bold fs-6 mb-1">
                                Project Details
                            </h2>

                            <p class="text-muted-custom small mb-0">
                                Auto-filled from the current project.
                            </p>
                        </div>
                    </div>

                    <?php if (!$project): ?>
                    <p class="text-muted-custom fw-bold mb-0">
                        Please select a project.
                    </p>
                    <?php else: ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Project</small>
                            <div class="fw-bold"><?= wpt_e($project['project_name']) ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Client</small>
                            <div class="fw-bold"><?= wpt_e($project['client_name'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Location</small>
                            <div class="fw-bold"><?= wpt_e($project['project_location'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Schedule</small>
                            <div class="fw-bold">
                                <?= wpt_e($pmsSchedule['schedule_name'] ?? 'PMS Schedule') ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Start</small>
                            <div class="fw-bold">
                                <?= wpt_e($pmsScheduleStart ?: ($project['start_date'] ?? '-')) ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS End</small>
                            <div class="fw-bold">
                                <?= wpt_e($pmsScheduleEnd ?: ($project['expected_completion_date'] ?? '-')) ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <form method="POST" id="wptForm">
                    <input type="hidden" name="submit_wpt" value="1">
                    <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
                    <input type="hidden" name="resubmit_submission_id" value="<?= (int)$resubmitSubmissionId ?>">
                    <input type="hidden" name="items_json" id="items_json">

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="clipboard-check"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">
                                    WPT Header
                                </h2>

                                <p class="text-muted-custom small mb-0">
                                    Report, project-party and week-ending details.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">WPT No</label>
                                <input class="form-control rounded-4" name="wpt_no"
                                    value="<?= wpt_e($formData['wpt_no']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">
                                    Week Ends On
                                </label>

                                <input type="date" class="form-control rounded-4" name="week_ends_on" id="week_ends_on"
                                    value="<?= wpt_e($formData['week_ends_on']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Contractor</label>
                                <input class="form-control rounded-4" name="contractor" id="contractor"
                                    placeholder="Enter contractor name" value="<?= wpt_e($formData['contractor']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Scope of Work</label>
                                <input class="form-control rounded-4" name="scope_of_work" id="scope_of_work"
                                    placeholder="Enter scope of work" value="<?= wpt_e($formData['scope_of_work']) ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Architect</label>
                                <input class="form-control rounded-4" name="architect" id="architect"
                                    placeholder="Enter architect name" value="<?= wpt_e($formData['architect']) ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">PMC</label>
                                <input class="form-control rounded-4" name="pmc" id="pmc" placeholder="Enter PMC name"
                                    value="<?= wpt_e($formData['pmc']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="section-box mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="mini-head mb-0">
                                <div class="mini-icon">
                                    <i data-lucide="table"></i>
                                </div>

                                <div>
                                    <h2 class="fw-bold fs-6 mb-1">
                                        Task Progress Details
                                    </h2>

                                    <p class="text-muted-custom small mb-0">
                                        Schedule, actual progress and delay monitoring.
                                    </p>
                                </div>
                            </div>

                            <button type="button" id="addWptRow" class="btn btn-outline-primary rounded-4 fw-bold">
                                Add Row
                            </button>
                        </div>

                        <div class="table-responsive thin-scrollbar">
                            <table class="table table-bordered wpt-table">
                                <thead>
                                    <tr>
                                        <th>SL</th>
                                        <th>Task As Per Schedule</th>
                                        <th>Duration</th>
                                        <th>Schedule Start</th>
                                        <th>Schedule Finish</th>
                                        <th>Schedule %</th>
                                        <th>Actual Start</th>
                                        <th>Actual Finish</th>
                                        <th>Actual %</th>
                                        <th>Previous Delay</th>
                                        <th>Present Delay</th>
                                        <th>Remarks</th>
                                        <th>Del</th>
                                    </tr>
                                </thead>

                                <tbody id="wptBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="section-box mb-3">
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4"
                                <?= !$project ? 'disabled' : '' ?>>
                                <?= $resubmitSubmissionId > 0
                                ? 'Resubmit WPT'
                                : 'Submit WPT' ?>
                            </button>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4">
                        <h2 class="fw-bold fs-6 mb-1">
                            Recent WPT
                        </h2>

                        <p class="text-muted-custom small mb-0">
                            Your latest Work Progress Tracker submissions.
                        </p>
                    </div>

                    <div class="px-3 px-lg-4 pb-4">
                        <div class="row g-2">
                            <?php foreach ($recentReports as $recent): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="recent-card">
                                    <div class="fw-bold">
                                        <?= wpt_e($recent['wpt_no']) ?>
                                    </div>

                                    <small class="text-muted-custom">
                                        <?= wpt_e($recent['project_name']) ?>
                                        ·
                                        <?= wpt_e(date(
                                            'd M Y',
                                            strtotime($recent['week_ends_on'])
                                        )) ?>
                                        ·
                                        <?= (int)$recent['task_count'] ?> task(s)
                                    </small>

                                    <br>

                                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2" target="_blank"
                                        href="reports-print/report-wpt-print.php?view=<?= (int)$recent['id'] ?>">
                                        Print
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <?php include 'includes/footer.php'; ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include 'includes/rightsidbar.php'; ?>
    </div>

    <?php include 'includes/script.php'; ?>
    <script src="assets/js/script.js?v=44"></script>

    <script>
    const initialWptItems =
        <?= json_encode(
        $formData['items'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    const previousWptData =
        <?= json_encode(
        $previousTemplatePayload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    const wptBody = document.getElementById('wptBody');

    const currentWptSnapshot = {
        contractor: document.getElementById('contractor')?.value || '',
        scope_of_work: document.getElementById('scope_of_work')?.value || '',
        architect: document.getElementById('architect')?.value || '',
        pmc: document.getElementById('pmc')?.value || '',
        items: initialWptItems
    };

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function renumberWptRows() {
        [...wptBody.rows].forEach((row, index) => {
            row.querySelector('.sl').textContent = index + 1;
        });

        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    function addWptRow(values = {}) {
        const row = document.createElement('tr');

        row.innerHTML = `
        <td class="sl text-center fw-bold"></td>

        <td>
            <textarea class="form-control rounded-4 task-name"
                      rows="2"
                      placeholder="Enter task description">${escapeHtml(values.task_name || '')}</textarea>
        </td>

        <td>
            <input class="form-control rounded-4 duration"
                   placeholder="e.g. 7 Days"
                   value="${escapeHtml(values.duration || '')}">
        </td>

        <td>
            <input type="date"
                   class="form-control rounded-4 start-date"
                   value="${escapeHtml(values.start_date || '')}">
        </td>

        <td>
            <input type="date"
                   class="form-control rounded-4 finish-date"
                   value="${escapeHtml(values.finish_date || '')}">
        </td>

        <td>
            <input class="form-control rounded-4 schedule-work"
                   placeholder="e.g. 50%"
                   value="${escapeHtml(values.schedule_work_done || '')}">
        </td>

        <td>
            <input type="date"
                   class="form-control rounded-4 actual-start"
                   value="${escapeHtml(values.actual_start || '')}">
        </td>

        <td>
            <input type="date"
                   class="form-control rounded-4 actual-finish"
                   value="${escapeHtml(values.actual_finish || '')}">
        </td>

        <td>
            <input class="form-control rounded-4 actual-work"
                   placeholder="e.g. 35%"
                   value="${escapeHtml(values.actual_work_done || '')}">
        </td>

        <td>
            <input class="form-control rounded-4 previous-delay"
                   placeholder="Days"
                   value="${escapeHtml(values.prev_delay || '')}">
        </td>

        <td>
            <input class="form-control rounded-4 present-delay"
                   placeholder="Days"
                   value="${escapeHtml(values.present_delay || '')}">
        </td>

        <td>
            <textarea class="form-control rounded-4 remarks"
                      rows="2"
                      placeholder="Enter remarks or corrective action">${escapeHtml(values.remarks || '')}</textarea>
        </td>

        <td class="text-center">
            <button type="button"
                    class="btn btn-sm btn-outline-danger rounded-4 delete-wpt-row">
                <i data-lucide="trash-2"></i>
            </button>
        </td>
    `;

        wptBody.appendChild(row);
        renumberWptRows();
    }

    function loadWptSource(source) {
        document.getElementById('contractor').value =
            source?.contractor || '';

        document.getElementById('scope_of_work').value =
            source?.scope_of_work || '';

        document.getElementById('architect').value =
            source?.architect || '';

        document.getElementById('pmc').value =
            source?.pmc || '';

        wptBody.innerHTML = '';

        const items = source?.items?.length ?
            source.items : [{}];

        items.forEach(addWptRow);
    }

    initialWptItems.forEach(addWptRow);

    document.getElementById('addWptRow')?.addEventListener(
        'click',
        () => addWptRow({})
    );

    document.addEventListener('click', event => {
        const button = event.target.closest('.delete-wpt-row');

        if (!button) {
            return;
        }

        const row = button.closest('tr');

        if (wptBody.rows.length <= 1) {
            row.querySelectorAll('input, textarea').forEach(field => {
                field.value = '';
            });
        } else {
            row.remove();
        }

        renumberWptRows();
    });

    document.getElementById('loadPreviousWptData')
        ?.addEventListener('change', function() {
            loadWptSource(
                this.checked ?
                previousWptData :
                currentWptSnapshot
            );
        });

    document.getElementById('projectPicker')
        ?.addEventListener('change', function() {
            const date =
                document.getElementById('week_ends_on')?.value ||
                '<?= wpt_e($reportDate) ?>';

            window.location.href = this.value ?
                'wpt.php?project_id=' +
                encodeURIComponent(this.value) +
                '&report_date=' +
                encodeURIComponent(date) :
                'wpt.php';
        });

    document.getElementById('wptForm')
        ?.addEventListener('submit', function() {
            const items = [...wptBody.rows].map(
                (row, index) => ({
                    sl_no: index + 1,
                    task_name: row.querySelector('.task-name').value,
                    duration: row.querySelector('.duration').value,
                    start_date: row.querySelector('.start-date').value,
                    finish_date: row.querySelector('.finish-date').value,
                    schedule_work_done: row.querySelector('.schedule-work').value,
                    actual_start: row.querySelector('.actual-start').value,
                    actual_finish: row.querySelector('.actual-finish').value,
                    actual_work_done: row.querySelector('.actual-work').value,
                    prev_delay: row.querySelector('.previous-delay').value,
                    present_delay: row.querySelector('.present-delay').value,
                    remarks: row.querySelector('.remarks').value
                })
            );

            document.getElementById('items_json').value =
                JSON.stringify(items);
        });
    </script>
</body>

</html>