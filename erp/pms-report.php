<?php
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

function pmsr_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function pmsr_employee_id(mysqli $conn): int
{
    if (!empty($_SESSION['employee_id'])) {
        return (int)$_SESSION['employee_id'];
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($userId > 0) {
        $query = mysqli_query(
            $conn,
            "SELECT employee_id FROM users WHERE id = $userId LIMIT 1"
        );

        if ($query && ($row = mysqli_fetch_assoc($query))) {
            $employeeId = (int)($row['employee_id'] ?? 0);

            if ($employeeId > 0) {
                $_SESSION['employee_id'] = $employeeId;
                return $employeeId;
            }
        }
    }

    return 0;
}

function pmsr_is_super_admin(mysqli $conn): bool
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

function pmsr_report_access(mysqli $conn, string $permission): bool
{
    if (pmsr_is_super_admin($conn)) {
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
          AND rt.report_code = 'PMS'
          AND rt.is_active = 1
    ");

    return $query
        && ($row = mysqli_fetch_assoc($query))
        && (int)($row['allowed'] ?? 0) === 1;
}

function pmsr_project_allowed(
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

function pmsr_columns(mysqli $conn, string $table): array
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $columns = [];
    $query = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $columns[$row['Field']] = true;
    }

    return $columns;
}

function pmsr_sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function pmsr_report_type_id(mysqli $conn): int
{
    $query = mysqli_query($conn, "
        SELECT id
        FROM master_report_types
        WHERE report_code = 'PMS'
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function pmsr_find_existing(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate
): int {
    if ($submissionId > 0) {
        $columns = pmsr_columns($conn, 'project_report_submissions');
        $select = ['id'];

        foreach (['report_reference_id', 'source_id', 'reference_id'] as $column) {
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

                $check = mysqli_query(
                    $conn,
                    "SELECT id FROM pms_main WHERE id = $candidateId LIMIT 1"
                );

                if ($check && mysqli_num_rows($check) > 0) {
                    return $candidateId;
                }
            }
        }
    }

    $dateEsc = mysqli_real_escape_string($conn, $reportDate);

    $query = mysqli_query($conn, "
        SELECT id
        FROM pms_main
        WHERE project_id = $projectId
          AND prepared_by = $employeeId
          AND pms_date = '$dateEsc'
        ORDER BY id DESC
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function pmsr_sync_submission(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate,
    int $pmsId,
    string $pmsNo
): void {
    $reportTypeId = pmsr_report_type_id($conn);

    if ($reportTypeId <= 0 || $pmsId <= 0) {
        return;
    }

    $columns = pmsr_columns($conn, 'project_report_submissions');
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $dateEsc = mysqli_real_escape_string($conn, $reportDate);

    if ($submissionId <= 0) {
        $query = mysqli_query($conn, "
            SELECT id
            FROM project_report_submissions
            WHERE project_id = $projectId
              AND report_type_id = $reportTypeId
              AND submission_for_date = '$dateEsc'
              AND (
                    report_reference_id = $pmsId
                 OR source_id = $pmsId
                 OR reference_id = $pmsId
                 OR report_no = '" . mysqli_real_escape_string($conn, $pmsNo) . "'
              )
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($query && ($row = mysqli_fetch_assoc($query))) {
            $submissionId = (int)$row['id'];
        }
    }

    $map = [
        'report_no' => $pmsNo,
        'report_number' => $pmsNo,
        'submission_no' => $pmsNo,
        'title' => 'Project Master Schedule',
        'submitted_by_employee_id' => $employeeId,
        'submitted_by_user_id' => $userId > 0 ? $userId : null,
        'submission_for_date' => $reportDate,
        'period_start' => $reportDate,
        'period_end' => $reportDate,
        'status' => 'submitted',
        'submitted_at' => date('Y-m-d H:i:s'),
        'source_table' => 'pms_main',
        'source_id' => $pmsId,
        'report_reference_table' => 'pms_main',
        'report_reference_id' => $pmsId,
        'reference_id' => $pmsId,
        'updated_by' => $userId > 0 ? $userId : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($submissionId > 0) {
        $sets = [];

        foreach ($map as $field => $value) {
            if (isset($columns[$field])) {
                $sets[] = "`$field` = " . pmsr_sql_value($conn, $value);
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

    $data = array_merge([
        'project_id' => $projectId,
        'report_type_id' => $reportTypeId,
        'created_by' => $userId > 0 ? $userId : null,
        'created_at' => date('Y-m-d H:i:s'),
    ], $map);

    $insertColumns = [];
    $insertValues = [];

    foreach ($data as $field => $value) {
        if (isset($columns[$field])) {
            $insertColumns[] = "`$field`";
            $insertValues[] = pmsr_sql_value($conn, $value);
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

function pmsr_redirect_hub(
    int $projectId,
    string $reportDate,
    string $flag
): void {
    $flag = preg_replace('/[^A-Za-z0-9_]/', '', $flag);

    header(
        'Location: reports-hub.php'
        . '?project_id=' . $projectId
        . '&report_date=' . urlencode($reportDate)
        . '&period_start=' . urlencode($reportDate)
        . '&period_end=' . urlencode($reportDate)
        . '&' . $flag . '=1'
    );

    exit;
}

$employeeId = pmsr_employee_id($conn);
$isSuperAdmin = pmsr_is_super_admin($conn);

if ($employeeId <= 0) {
    header('Location: login.php');
    exit;
}

if (!pmsr_report_access($conn, 'can_submit')) {
    header(
        'Location: reports-hub.php?error='
        . urlencode('You do not have PMS submit access.')
    );
    exit;
}

$employeeQuery = mysqli_query($conn, "
    SELECT
        e.*,
        r.role_name AS designation_name
    FROM employees e
    LEFT JOIN roles r ON r.id = e.role_id
    WHERE e.id = $employeeId
    LIMIT 1
");

$employee = $employeeQuery
    ? mysqli_fetch_assoc($employeeQuery)
    : null;

$employeeName = (string)(
    $employee['full_name']
    ?? $_SESSION['employee_name']
    ?? $_SESSION['name']
    ?? ''
);

$designationName = (string)(
    $employee['designation_name']
    ?? $_SESSION['designation']
    ?? ''
);

$projectId = (int)(
    $_GET['project_id']
    ?? $_POST['project_id']
    ?? 0
);

$reportDate = trim((string)(
    $_GET['report_date']
    ?? $_POST['pms_date']
    ?? date('Y-m-d')
));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    $reportDate = date('Y-m-d');
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
            p.client_id,
            c.client_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        WHERE p.deleted_at IS NULL
        ORDER BY p.project_name
    ");
} else {
    $projectsQuery = mysqli_query($conn, "
        SELECT DISTINCT
            p.id,
            p.project_name,
            p.project_code,
            p.project_location,
            p.client_id,
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
        ORDER BY p.project_name
    ");
}

while ($projectsQuery && ($row = mysqli_fetch_assoc($projectsQuery))) {
    $projects[] = $row;
}

$project = null;

if (
    $projectId > 0
    && pmsr_project_allowed(
        $conn,
        $projectId,
        $employeeId,
        $isSuperAdmin
    )
) {
    $query = mysqli_query($conn, "
        SELECT
            p.*,
            c.client_name,
            c.company_name,
            mpt.project_type_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN master_project_types mpt
            ON mpt.id = p.project_type_id
        WHERE p.id = $projectId
          AND p.deleted_at IS NULL
        LIMIT 1
    ");

    if ($query) {
        $project = mysqli_fetch_assoc($query);
    }
}

$currentSchedule = $projectId > 0
    ? pms_project_schedule($conn, $projectId)
    : null;

[$scheduleStart, $scheduleEnd] =
    pms_schedule_date_range($currentSchedule, $project);

$defaultNo = '';

if ($projectId > 0) {
    $prefix = 'PMS/' . $projectId . '/' . date('Ym', strtotime($reportDate)) . '/';
    $prefixEsc = mysqli_real_escape_string($conn, $prefix);

    $query = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM pms_main
         WHERE pms_no LIKE '$prefixEsc%'"
    );

    $count = $query
        ? (int)(mysqli_fetch_assoc($query)['total'] ?? 0)
        : 0;

    $defaultNo = $prefix
        . str_pad((string)($count + 1), 3, '0', STR_PAD_LEFT);
}

$existingReport = null;

if ($resubmitSubmissionId > 0 && $projectId > 0) {
    $existingId = pmsr_find_existing(
        $conn,
        $resubmitSubmissionId,
        $projectId,
        $employeeId,
        $reportDate
    );

    if ($existingId > 0) {
        $query = mysqli_query(
            $conn,
            "SELECT * FROM pms_main WHERE id = $existingId LIMIT 1"
        );

        if ($query) {
            $existingReport = mysqli_fetch_assoc($query);
        }
    }
}

$previousReport = null;

if ($projectId > 0) {
    $dateEsc = mysqli_real_escape_string($conn, $reportDate);

    foreach ([
        "project_id = $projectId AND prepared_by = $employeeId AND pms_date < '$dateEsc'",
        "project_id = $projectId AND prepared_by = $employeeId",
        "project_id = $projectId"
    ] as $where) {
        $query = mysqli_query($conn, "
            SELECT *
            FROM pms_main
            WHERE $where
            ORDER BY pms_date DESC, created_at DESC, id DESC
            LIMIT 1
        ");

        if ($query && ($previousReport = mysqli_fetch_assoc($query))) {
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_pms'])) {
    $postProjectId = (int)($_POST['project_id'] ?? 0);
    $postSubmissionId = (int)($_POST['resubmit_submission_id'] ?? 0);

    $pmsNo = trim((string)($_POST['pms_no'] ?? ''));
    $pmsDate = trim((string)($_POST['pms_date'] ?? ''));
    $architect = trim((string)($_POST['architect'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));
    $version = trim((string)($_POST['version'] ?? 'R0'));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $items = json_decode(
        (string)($_POST['items_json'] ?? '[]'),
        true
    );

    $error = '';

    if (
        !pmsr_project_allowed(
            $conn,
            $postProjectId,
            $employeeId,
            $isSuperAdmin
        )
    ) {
        $error = 'Invalid project selection.';
    } elseif ($pmsNo === '') {
        $error = 'PMS No is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pmsDate)) {
        $error = 'Valid PMS date is required.';
    } elseif (!is_array($items)) {
        $error = 'Invalid schedule rows.';
    }

    $items = array_values(array_filter(
        is_array($items) ? $items : [],
        static fn($row) =>
            trim((string)($row['task_activity'] ?? '')) !== ''
    ));

    if ($error === '' && !$items) {
        $error = 'Please enter at least one task or milestone.';
    }

    if ($error === '') {
        $query = mysqli_query($conn, "
            SELECT
                p.project_name,
                p.client_id,
                c.client_name
            FROM projects p
            LEFT JOIN clients c ON c.id = p.client_id
            WHERE p.id = $postProjectId
            LIMIT 1
        ");

        $projectData = $query
            ? mysqli_fetch_assoc($query)
            : null;

        $projectName = (string)($projectData['project_name'] ?? '');
        $clientId = (int)($projectData['client_id'] ?? 0);
        $clientName = (string)($projectData['client_name'] ?? '');

        mysqli_begin_transaction($conn);

        try {
            if ($postSubmissionId > 0) {
                $pmsId = pmsr_find_existing(
                    $conn,
                    $postSubmissionId,
                    $postProjectId,
                    $employeeId,
                    $pmsDate
                );

                if ($pmsId <= 0) {
                    throw new RuntimeException(
                        'Original PMS not found for resubmission.'
                    );
                }

                $stmt = mysqli_prepare($conn, "
                    UPDATE pms_main
                    SET
                        pms_no = ?,
                        project_id = ?,
                        site_id = ?,
                        client_id = ?,
                        project_name = ?,
                        client_name = ?,
                        architect = ?,
                        pmc = ?,
                        version = ?,
                        pms_date = ?,
                        prepared_by_name = ?,
                        remarks = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                if (!$stmt) {
                    throw new RuntimeException(mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'siiissssssssi',
                    $pmsNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $architect,
                    $pmc,
                    $version,
                    $pmsDate,
                    $employeeName,
                    $remarks,
                    $pmsId
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                mysqli_stmt_close($stmt);

                mysqli_query(
                    $conn,
                    "DELETE FROM pms_details WHERE pms_main_id = $pmsId"
                );
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO pms_main
                    (
                        pms_no,
                        project_id,
                        site_id,
                        client_id,
                        project_name,
                        client_name,
                        architect,
                        pmc,
                        version,
                        pms_date,
                        prepared_by,
                        prepared_by_name,
                        remarks
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt) {
                    throw new RuntimeException(mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'siiissssssiss',
                    $pmsNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $architect,
                    $pmc,
                    $version,
                    $pmsDate,
                    $employeeId,
                    $employeeName,
                    $remarks
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                $pmsId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }

            $detailStmt = mysqli_prepare($conn, "
                INSERT INTO pms_details
                (
                    pms_main_id,
                    sl_no,
                    task_activity,
                    duration_days,
                    date_start,
                    date_end,
                    remark
                )
                VALUES (?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?)
            ");

            if (!$detailStmt) {
                throw new RuntimeException(mysqli_error($conn));
            }

            foreach ($items as $index => $item) {
                $slNo = $index + 1;
                $task = trim((string)($item['task_activity'] ?? ''));
                $duration = max(0, (int)($item['duration_days'] ?? 0));
                $startDate = trim((string)($item['date_start'] ?? ''));
                $endDate = trim((string)($item['date_end'] ?? ''));
                $remark = trim((string)($item['remark'] ?? ''));

                if (
                    $endDate === ''
                    && $startDate !== ''
                    && $duration > 0
                ) {
                    $endDate = date(
                        'Y-m-d',
                        strtotime(
                            $startDate . ' +' . max(0, $duration - 1) . ' days'
                        )
                    );
                }

                mysqli_stmt_bind_param(
                    $detailStmt,
                    'iisisss',
                    $pmsId,
                    $slNo,
                    $task,
                    $duration,
                    $startDate,
                    $endDate,
                    $remark
                );

                if (!mysqli_stmt_execute($detailStmt)) {
                    throw new RuntimeException(
                        mysqli_stmt_error($detailStmt)
                    );
                }
            }

            mysqli_stmt_close($detailStmt);

            pmsr_sync_submission(
                $conn,
                $postSubmissionId,
                $postProjectId,
                $employeeId,
                $pmsDate,
                $pmsId,
                $pmsNo
            );

            mysqli_commit($conn);

            pmsr_redirect_hub(
                $postProjectId,
                $pmsDate,
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
            'Location: pms.php'
            . '?project_id=' . $postProjectId
            . '&report_date=' . urlencode($pmsDate)
            . '&error=' . urlencode($error)
        );
        exit;
    }
}

$formData = [
    'pms_no' => $existingReport['pms_no'] ?? $defaultNo,
    'pms_date' => $existingReport['pms_date'] ?? $reportDate,
    'architect' => $existingReport['architect'] ?? '',
    'pmc' => $existingReport['pmc'] ?? '',
    'version' => $existingReport['version'] ?? 'R0',
    'remarks' => $existingReport['remarks'] ?? '',
    'items' => [],
];

if ($existingReport) {
    $query = mysqli_query(
        $conn,
        "SELECT *
         FROM pms_details
         WHERE pms_main_id = " . (int)$existingReport['id'] . "
         ORDER BY sl_no, id"
    );

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $formData['items'][] = $row;
    }
}

if (!$formData['items']) {
    $formData['items'] = array_fill(0, 3, [
        'task_activity' => '',
        'duration_days' => 0,
        'date_start' => '',
        'date_end' => '',
        'remark' => '',
    ]);
}

$previousPayload = null;

if ($previousReport) {
    $items = [];

    $query = mysqli_query(
        $conn,
        "SELECT *
         FROM pms_details
         WHERE pms_main_id = " . (int)$previousReport['id'] . "
         ORDER BY sl_no, id"
    );

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $items[] = $row;
    }

    $previousPayload = [
        'pms_no' => $previousReport['pms_no'] ?? '',
        'pms_date' => $previousReport['pms_date'] ?? '',
        'architect' => $previousReport['architect'] ?? '',
        'pmc' => $previousReport['pmc'] ?? '',
        'version' => $previousReport['version'] ?? 'R0',
        'remarks' => $previousReport['remarks'] ?? '',
        'items' => $items,
    ];
}

$recent = [];

$query = mysqli_query($conn, "
    SELECT
        m.id,
        m.pms_no,
        m.pms_date,
        m.project_name,
        COUNT(d.id) AS task_count
    FROM pms_main m
    LEFT JOIN pms_details d ON d.pms_main_id = m.id
    WHERE m.prepared_by = $employeeId
    GROUP BY m.id
    ORDER BY m.created_at DESC
    LIMIT 10
");

while ($query && ($row = mysqli_fetch_assoc($query))) {
    $recent[] = $row;
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
    <title>PMS - TEK-C PMC Construction</title>

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

        .pms-table {
            min-width: 1250px;
        }

        .pms-table th {
            font-size: 11px;
            text-transform: uppercase;
            color: var(--text-muted);
            font-weight: 900;
            background: rgba(148, 163, 184, .10);
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
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
<div id="mobileOverlay"
     class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>

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
                                ? 'Resubmit Project Master Schedule (PMS)'
                                : 'Project Master Schedule (PMS)' ?>
                        </h1>

                        <p class="text-muted-custom mb-0 small">
                            Plan project activities, milestones and timelines.
                        </p>
                    </div>

                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <label class="badge-soft mb-0 <?= !$previousPayload ? 'opacity-75' : '' ?>">
                            <input type="checkbox"
                                   id="loadPrevious"
                                   <?= !$previousPayload ? 'disabled' : '' ?>>

                            <span>
                                <strong>Load previous data</strong>
                                <small class="d-block text-muted-custom">
                                    <?= $previousPayload
                                        ? pmsr_e($previousPayload['pms_no'])
                                            . ' · '
                                            . pmsr_e(date(
                                                'd M Y',
                                                strtotime($previousPayload['pms_date'])
                                            ))
                                        : 'No previous data' ?>
                                </small>
                            </span>
                        </label>

                        <span class="badge-soft">
                            <i data-lucide="user" style="width:15px"></i>
                            <?= pmsr_e($employeeName) ?>
                        </span>

                        <span class="badge-soft">
                            <?= pmsr_e($designationName) ?>
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
                    <div class="mini-icon">
                        <i data-lucide="map-pin"></i>
                    </div>

                    <div>
                        <h2 class="fw-bold fs-6 mb-1">Project Selection</h2>
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

                        <select id="projectPicker"
                                class="form-select rounded-4">
                            <option value="">-- Select Assigned Project --</option>

                            <?php foreach ($projects as $projectOption): ?>
                                <option value="<?= (int)$projectOption['id'] ?>"
                                    <?= (int)$projectOption['id'] === $projectId
                                        ? 'selected'
                                        : '' ?>>
                                    <?= pmsr_e($projectOption['project_name']) ?>
                                    <?= !empty($projectOption['project_code'])
                                        ? ' (' . pmsr_e($projectOption['project_code']) . ')'
                                        : '' ?>
                                    - <?= pmsr_e($projectOption['project_location'] ?: '-') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3">
                        <a href="pms.php"
                           class="btn btn-outline-secondary rounded-4 fw-bold w-100">
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
                        <h2 class="fw-bold fs-6 mb-1">Project Details</h2>
                        <p class="text-muted-custom small mb-0">
                            Current project and preferred PMS schedule.
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
                            <div class="fw-bold"><?= pmsr_e($project['project_name']) ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Client</small>
                            <div class="fw-bold"><?= pmsr_e($project['client_name'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Location</small>
                            <div class="fw-bold"><?= pmsr_e($project['project_location'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Schedule</small>
                            <div class="fw-bold">
                                <?= pmsr_e($currentSchedule['schedule_name'] ?? 'PMS Schedule') ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Start</small>
                            <div class="fw-bold"><?= pmsr_e($scheduleStart ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS End</small>
                            <div class="fw-bold"><?= pmsr_e($scheduleEnd ?: '-') ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <form method="POST" id="pmsForm">
                <input type="hidden" name="submit_pms" value="1">
                <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
                <input type="hidden"
                       name="resubmit_submission_id"
                       value="<?= (int)$resubmitSubmissionId ?>">
                <input type="hidden" name="items_json" id="items_json">

                <div class="section-box mb-3">
                    <div class="mini-head">
                        <div class="mini-icon">
                            <i data-lucide="clipboard-check"></i>
                        </div>

                        <div>
                            <h2 class="fw-bold fs-6 mb-1">PMS Header</h2>
                            <p class="text-muted-custom small mb-0">
                                Report number, version and project-party details.
                            </p>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">PMS No</label>
                            <input class="form-control rounded-4"
                                   name="pms_no"
                                   value="<?= pmsr_e($formData['pms_no']) ?>"
                                   required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">PMS Date</label>
                            <input type="date"
                                   class="form-control rounded-4"
                                   id="pms_date"
                                   name="pms_date"
                                   value="<?= pmsr_e($formData['pms_date']) ?>"
                                   required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Version</label>
                            <input class="form-control rounded-4"
                                   id="version"
                                   name="version"
                                   placeholder="e.g. R0, R1, Final"
                                   value="<?= pmsr_e($formData['version']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Architect</label>
                            <input class="form-control rounded-4"
                                   id="architect"
                                   name="architect"
                                   placeholder="Enter architect name"
                                   value="<?= pmsr_e($formData['architect']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">PMC</label>
                            <input class="form-control rounded-4"
                                   id="pmc"
                                   name="pmc"
                                   placeholder="Enter PMC name"
                                   value="<?= pmsr_e($formData['pmc']) ?>">
                        </div>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="mini-head mb-0">
                            <div class="mini-icon">
                                <i data-lucide="calendar-range"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">
                                    Schedule Activities
                                </h2>

                                <p class="text-muted-custom small mb-0">
                                    Add project tasks, durations, dates and remarks.
                                </p>
                            </div>
                        </div>

                        <button type="button"
                                id="addRow"
                                class="btn btn-outline-primary rounded-4 fw-bold">
                            Add Task
                        </button>
                    </div>

                    <div class="table-responsive thin-scrollbar">
                        <table class="table table-bordered pms-table">
                            <thead>
                            <tr>
                                <th>SL</th>
                                <th>Task / Activity / Milestone</th>
                                <th>Duration Days</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Remark</th>
                                <th>Del</th>
                            </tr>
                            </thead>

                            <tbody id="pmsBody"></tbody>
                        </table>
                    </div>

                    <div class="mt-3">
                        <label class="form-label fw-bold small">
                            General Remarks
                        </label>

                        <textarea class="form-control rounded-4"
                                  id="remarks"
                                  name="remarks"
                                  rows="3"
                                  placeholder="Enter overall schedule remarks"><?= pmsr_e($formData['remarks']) ?></textarea>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="d-flex justify-content-end">
                        <button type="submit"
                                class="btn brand-gradient text-white rounded-4 fw-bold px-4"
                                <?= !$project ? 'disabled' : '' ?>>
                            <?= $resubmitSubmissionId > 0
                                ? 'Resubmit PMS'
                                : 'Submit PMS' ?>
                        </button>
                    </div>
                </div>
            </form>

            <section class="card-ui overflow-hidden">
                <div class="p-3 p-lg-4">
                    <h2 class="fw-bold fs-6 mb-1">Recent PMS</h2>
                    <p class="text-muted-custom small mb-0">
                        Your latest Project Master Schedule submissions.
                    </p>
                </div>

                <div class="px-3 px-lg-4 pb-4">
                    <div class="row g-2">
                        <?php foreach ($recent as $recentRow): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="recent-card">
                                    <div class="fw-bold">
                                        <?= pmsr_e($recentRow['pms_no']) ?>
                                    </div>

                                    <small class="text-muted-custom">
                                        <?= pmsr_e($recentRow['project_name']) ?>
                                        ·
                                        <?= pmsr_e(date(
                                            'd M Y',
                                            strtotime($recentRow['pms_date'])
                                        )) ?>
                                        ·
                                        <?= (int)$recentRow['task_count'] ?> task(s)
                                    </small>

                                    <br>

                                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2"
                                       target="_blank"
                                       href="reports-print/report-pms-print.php?view=<?= (int)$recentRow['id'] ?>">
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
<script src="assets/js/script.js?v=49"></script>

<script>
const initialItems =
    <?= json_encode(
        $formData['items'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

const previousData =
    <?= json_encode(
        $previousPayload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

const pmsBody = document.getElementById('pmsBody');

const snapshot = {
    architect: document.getElementById('architect')?.value || '',
    pmc: document.getElementById('pmc')?.value || '',
    version: document.getElementById('version')?.value || 'R0',
    remarks: document.getElementById('remarks')?.value || '',
    items: initialItems
};

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function calculateEndDate(row) {
    const start = row.querySelector('.date-start').value;
    const duration = parseInt(
        row.querySelector('.duration').value || '0',
        10
    );

    const end = row.querySelector('.date-end');

    if (!start || duration <= 0) {
        end.value = '';
        return;
    }

    const date = new Date(start + 'T00:00:00');
    date.setDate(date.getDate() + duration - 1);
    end.value = date.toISOString().slice(0, 10);
}

function addRow(values = {}) {
    const row = document.createElement('tr');

    row.innerHTML = `
        <td class="sl text-center fw-bold"></td>

        <td>
            <input class="form-control rounded-4 task"
                   placeholder="Enter task, activity or milestone"
                   value="${escapeHtml(values.task_activity || '')}">
        </td>

        <td>
            <input type="number"
                   class="form-control rounded-4 duration"
                   min="0"
                   placeholder="Days"
                   value="${escapeHtml(values.duration_days || 0)}">
        </td>

        <td>
            <input type="date"
                   class="form-control rounded-4 date-start"
                   value="${escapeHtml(values.date_start || '')}">
        </td>

        <td>
            <input type="date"
                   class="form-control rounded-4 date-end"
                   value="${escapeHtml(values.date_end || '')}"
                   readonly>
        </td>

        <td>
            <input class="form-control rounded-4 remark"
                   placeholder="Enter remark"
                   value="${escapeHtml(values.remark || '')}">
        </td>

        <td class="text-center">
            <button type="button"
                    class="btn btn-sm btn-outline-danger rounded-4 delete-row">
                <i data-lucide="trash-2"></i>
            </button>
        </td>
    `;

    pmsBody.appendChild(row);

    row.querySelector('.date-start')
        .addEventListener('change', () => calculateEndDate(row));

    row.querySelector('.duration')
        .addEventListener('input', () => calculateEndDate(row));

    renumberRows();
}

function renumberRows() {
    [...pmsBody.rows].forEach((row, index) => {
        row.querySelector('.sl').textContent = index + 1;
    });

    if (window.lucide) {
        window.lucide.createIcons();
    }
}

function loadSource(source) {
    document.getElementById('architect').value =
        source?.architect || '';

    document.getElementById('pmc').value =
        source?.pmc || '';

    document.getElementById('version').value =
        source?.version || 'R0';

    document.getElementById('remarks').value =
        source?.remarks || '';

    pmsBody.innerHTML = '';

    const items = source?.items?.length
        ? source.items
        : [{}];

    items.forEach(addRow);
}

initialItems.forEach(addRow);

document.getElementById('addRow')
    ?.addEventListener('click', () => addRow({}));

document.addEventListener('click', event => {
    const button = event.target.closest('.delete-row');

    if (!button) {
        return;
    }

    const row = button.closest('tr');

    if (pmsBody.rows.length <= 1) {
        row.querySelectorAll('input').forEach(input => {
            input.value = input.classList.contains('duration')
                ? '0'
                : '';
        });
    } else {
        row.remove();
    }

    renumberRows();
});

document.getElementById('loadPrevious')
    ?.addEventListener('change', function () {
        loadSource(
            this.checked
                ? previousData
                : snapshot
        );
    });

document.getElementById('projectPicker')
    ?.addEventListener('change', function () {
        const date =
            document.getElementById('pms_date')?.value
            || '<?= pmsr_e($reportDate) ?>';

        window.location.href = this.value
            ? 'pms.php?project_id='
                + encodeURIComponent(this.value)
                + '&report_date='
                + encodeURIComponent(date)
            : 'pms.php';
    });

document.getElementById('pmsForm')
    ?.addEventListener('submit', function () {
        const items = [...pmsBody.rows].map((row, index) => ({
            sl_no: index + 1,
            task_activity:
                row.querySelector('.task').value,
            duration_days:
                Number(row.querySelector('.duration').value || 0),
            date_start:
                row.querySelector('.date-start').value,
            date_end:
                row.querySelector('.date-end').value,
            remark:
                row.querySelector('.remark').value
        }));

        document.getElementById('items_json').value =
            JSON.stringify(items);
    });
</script>
</body>
</html>
