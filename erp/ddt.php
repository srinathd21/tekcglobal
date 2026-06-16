<?php
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

function ddt_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ddt_employee_id(mysqli $conn): int
{
    if (!empty($_SESSION['employee_id'])) {
        return (int)$_SESSION['employee_id'];
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($userId > 0) {
        $query = mysqli_query(
            $conn,
            "SELECT employee_id
             FROM users
             WHERE id = $userId
             LIMIT 1"
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

function ddt_is_super_admin(mysqli $conn): bool
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

function ddt_report_access(mysqli $conn, string $permission): bool
{
    if (ddt_is_super_admin($conn)) {
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
          AND rt.report_code = 'DDT'
          AND rt.is_active = 1
    ");

    return $query
        && ($row = mysqli_fetch_assoc($query))
        && (int)($row['allowed'] ?? 0) === 1;
}

function ddt_project_allowed(
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

function ddt_columns(mysqli $conn, string $table): array
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $columns = [];

    $query = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $columns[$row['Field']] = true;
    }

    return $columns;
}

function ddt_sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function ddt_report_type_id(mysqli $conn): int
{
    $query = mysqli_query($conn, "
        SELECT id
        FROM master_report_types
        WHERE report_code = 'DDT'
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function ddt_find_existing(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate
): int {
    if ($submissionId > 0) {
        $columns = ddt_columns($conn, 'project_report_submissions');
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
                    "SELECT id
                     FROM ddt_main
                     WHERE id = $candidateId
                     LIMIT 1"
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
        FROM ddt_main
        WHERE project_id = $projectId
          AND created_by = $employeeId
          AND ddt_date = '$dateEsc'
        ORDER BY id DESC
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function ddt_sync_submission(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate,
    int $ddtId,
    string $ddtNo
): void {
    $reportTypeId = ddt_report_type_id($conn);

    if ($reportTypeId <= 0 || $ddtId <= 0) {
        return;
    }

    $columns = ddt_columns($conn, 'project_report_submissions');
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
                    report_reference_id = $ddtId
                 OR source_id = $ddtId
                 OR reference_id = $ddtId
                 OR report_no = '" . mysqli_real_escape_string($conn, $ddtNo) . "'
              )
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($query && ($row = mysqli_fetch_assoc($query))) {
            $submissionId = (int)$row['id'];
        }
    }

    $map = [
        'report_no' => $ddtNo,
        'report_number' => $ddtNo,
        'submission_no' => $ddtNo,
        'title' => 'Design Deliverable Tracker',
        'submitted_by_employee_id' => $employeeId,
        'submitted_by_user_id' => $userId > 0 ? $userId : null,
        'submission_for_date' => $reportDate,
        'period_start' => $reportDate,
        'period_end' => $reportDate,
        'status' => 'submitted',
        'submitted_at' => date('Y-m-d H:i:s'),
        'source_table' => 'ddt_main',
        'source_id' => $ddtId,
        'report_reference_table' => 'ddt_main',
        'report_reference_id' => $ddtId,
        'reference_id' => $ddtId,
        'updated_by' => $userId > 0 ? $userId : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($submissionId > 0) {
        $sets = [];

        foreach ($map as $field => $value) {
            if (isset($columns[$field])) {
                $sets[] = "`$field` = " . ddt_sql_value($conn, $value);
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
            $insertValues[] = ddt_sql_value($conn, $value);
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

function ddt_redirect_hub(
    int $projectId,
    string $reportDate,
    string $flag
): void {
    header(
        'Location: reports-hub.php'
        . '?project_id=' . $projectId
        . '&report_date=' . urlencode($reportDate)
        . '&period_start=' . urlencode($reportDate)
        . '&period_end=' . urlencode($reportDate)
        . '&' . preg_replace('/[^A-Za-z0-9_]/', '', $flag) . '=1'
    );

    exit;
}

$employeeId = ddt_employee_id($conn);
$isSuperAdmin = ddt_is_super_admin($conn);

if ($employeeId <= 0) {
    header('Location: login.php');
    exit;
}

if (!ddt_report_access($conn, 'can_submit')) {
    header(
        'Location: reports-hub.php?error='
        . urlencode('You do not have DDT submit access.')
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
    ?? $_POST['ddt_date']
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
    && ddt_project_allowed(
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

$pmsSchedule = $projectId > 0
    ? pms_project_schedule($conn, $projectId)
    : null;

[$pmsStart, $pmsEnd] =
    pms_schedule_date_range($pmsSchedule, $project);

$defaultNo = '';

if ($projectId > 0) {
    $prefix = 'DDT/' . $projectId . '/' . date('Ym', strtotime($reportDate)) . '/';
    $prefixEsc = mysqli_real_escape_string($conn, $prefix);

    $query = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM ddt_main
         WHERE ddt_no LIKE '$prefixEsc%'"
    );

    $count = $query
        ? (int)(mysqli_fetch_assoc($query)['total'] ?? 0)
        : 0;

    $defaultNo = $prefix
        . str_pad((string)($count + 1), 3, '0', STR_PAD_LEFT);
}

$existingReport = null;

if ($resubmitSubmissionId > 0 && $projectId > 0) {
    $existingId = ddt_find_existing(
        $conn,
        $resubmitSubmissionId,
        $projectId,
        $employeeId,
        $reportDate
    );

    if ($existingId > 0) {
        $query = mysqli_query(
            $conn,
            "SELECT * FROM ddt_main WHERE id = $existingId LIMIT 1"
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
        "project_id = $projectId AND created_by = $employeeId AND ddt_date < '$dateEsc'",
        "project_id = $projectId AND created_by = $employeeId",
        "project_id = $projectId"
    ] as $where) {
        $query = mysqli_query($conn, "
            SELECT *
            FROM ddt_main
            WHERE $where
            ORDER BY ddt_date DESC, created_at DESC, id DESC
            LIMIT 1
        ");

        if ($query && ($previousReport = mysqli_fetch_assoc($query))) {
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ddt'])) {
    $postProjectId = (int)($_POST['project_id'] ?? 0);
    $postSubmissionId = (int)($_POST['resubmit_submission_id'] ?? 0);

    $ddtNo = trim((string)($_POST['ddt_no'] ?? ''));
    $ddtDate = trim((string)($_POST['ddt_date'] ?? ''));
    $architects = trim((string)($_POST['architects'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));
    $revisions = trim((string)($_POST['revisions'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $items = json_decode(
        (string)($_POST['items_json'] ?? '[]'),
        true
    );

    $error = '';

    if (
        !ddt_project_allowed(
            $conn,
            $postProjectId,
            $employeeId,
            $isSuperAdmin
        )
    ) {
        $error = 'Invalid project selection.';
    } elseif ($ddtNo === '') {
        $error = 'DDT No is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ddtDate)) {
        $error = 'Valid DDT date is required.';
    } elseif (!is_array($items)) {
        $error = 'Invalid drawing rows.';
    }

    $items = array_values(array_filter(
        is_array($items) ? $items : [],
        static fn($row) =>
            trim((string)($row['list_of_drawings'] ?? '')) !== ''
    ));

    if ($error === '' && !$items) {
        $error = 'Please enter at least one drawing.';
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
                $ddtId = ddt_find_existing(
                    $conn,
                    $postSubmissionId,
                    $postProjectId,
                    $employeeId,
                    $ddtDate
                );

                if ($ddtId <= 0) {
                    throw new RuntimeException(
                        'Original DDT not found for resubmission.'
                    );
                }

                $stmt = mysqli_prepare($conn, "
                    UPDATE ddt_main
                    SET
                        ddt_no = ?,
                        project_id = ?,
                        site_id = ?,
                        client_id = ?,
                        project_name = ?,
                        client_name = ?,
                        architects = ?,
                        pmc = ?,
                        revisions = ?,
                        ddt_date = ?,
                        created_by_name = ?,
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
                    $ddtNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $architects,
                    $pmc,
                    $revisions,
                    $ddtDate,
                    $employeeName,
                    $remarks,
                    $ddtId
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                mysqli_stmt_close($stmt);

                mysqli_query(
                    $conn,
                    "DELETE FROM ddt_details WHERE ddt_main_id = $ddtId"
                );
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO ddt_main
                    (
                        ddt_no,
                        project_id,
                        site_id,
                        client_id,
                        project_name,
                        client_name,
                        architects,
                        pmc,
                        revisions,
                        ddt_date,
                        created_by,
                        created_by_name,
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
                    $ddtNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $architects,
                    $pmc,
                    $revisions,
                    $ddtDate,
                    $employeeId,
                    $employeeName,
                    $remarks
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                $ddtId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }

            $detailStmt = mysqli_prepare($conn, "
                INSERT INTO ddt_details
                (
                    ddt_main_id,
                    sl_no,
                    section,
                    list_of_drawings,
                    site_schedule_start,
                    drawing_deliverable_date,
                    actual_expected,
                    remarks
                )
                VALUES (?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?)
            ");

            if (!$detailStmt) {
                throw new RuntimeException(mysqli_error($conn));
            }

            $sectionCounters = [];

            foreach ($items as $item) {
                $section = strtoupper(trim((string)($item['section'] ?? 'A')));

                if (!in_array($section, ['A', 'B', 'C'], true)) {
                    $section = 'A';
                }

                $sectionCounters[$section] =
                    ($sectionCounters[$section] ?? 0) + 1;

                $slNo = $sectionCounters[$section];
                $drawing = trim((string)($item['list_of_drawings'] ?? ''));
                $siteScheduleStart = trim((string)($item['site_schedule_start'] ?? ''));
                $drawingDeliverableDate = trim((string)($item['drawing_deliverable_date'] ?? ''));
                $actualExpected = trim((string)($item['actual_expected'] ?? ''));
                $itemRemarks = trim((string)($item['remarks'] ?? ''));

                mysqli_stmt_bind_param(
                    $detailStmt,
                    'iissssss',
                    $ddtId,
                    $slNo,
                    $section,
                    $drawing,
                    $siteScheduleStart,
                    $drawingDeliverableDate,
                    $actualExpected,
                    $itemRemarks
                );

                if (!mysqli_stmt_execute($detailStmt)) {
                    throw new RuntimeException(
                        mysqli_stmt_error($detailStmt)
                    );
                }
            }

            mysqli_stmt_close($detailStmt);

            ddt_sync_submission(
                $conn,
                $postSubmissionId,
                $postProjectId,
                $employeeId,
                $ddtDate,
                $ddtId,
                $ddtNo
            );

            mysqli_commit($conn);

            ddt_redirect_hub(
                $postProjectId,
                $ddtDate,
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
            'Location: ddt.php'
            . '?project_id=' . $postProjectId
            . '&report_date=' . urlencode($ddtDate)
            . '&error=' . urlencode($error)
        );
        exit;
    }
}

$formData = [
    'ddt_no' => $existingReport['ddt_no'] ?? $defaultNo,
    'ddt_date' => $existingReport['ddt_date'] ?? $reportDate,
    'architects' => $existingReport['architects'] ?? '',
    'pmc' => $existingReport['pmc'] ?? '',
    'revisions' => $existingReport['revisions'] ?? '',
    'remarks' => $existingReport['remarks'] ?? '',
    'items' => [],
];

if ($existingReport) {
    $query = mysqli_query(
        $conn,
        "SELECT *
         FROM ddt_details
         WHERE ddt_main_id = " . (int)$existingReport['id'] . "
         ORDER BY FIELD(section, 'A', 'B', 'C'), sl_no, id"
    );

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $formData['items'][] = $row;
    }
}

if (!$formData['items']) {
    foreach (['A', 'B', 'C'] as $section) {
        $formData['items'][] = [
            'section' => $section,
            'list_of_drawings' => '',
            'duration_days' => 0,
            'start_date' => '',
            'end_date' => '',
            'remarks' => '',
        ];
    }
}

$previousPayload = null;

if ($previousReport) {
    $items = [];

    $query = mysqli_query(
        $conn,
        "SELECT *
         FROM ddt_details
         WHERE ddt_main_id = " . (int)$previousReport['id'] . "
         ORDER BY FIELD(section, 'A', 'B', 'C'), sl_no, id"
    );

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $items[] = $row;
    }

    $previousPayload = [
        'ddt_no' => $previousReport['ddt_no'] ?? '',
        'ddt_date' => $previousReport['ddt_date'] ?? '',
        'architects' => $previousReport['architects'] ?? '',
        'revisions' => $previousReport['revisions'] ?? '',
        'pmc' => $previousReport['pmc'] ?? '',
        
        'remarks' => $previousReport['remarks'] ?? '',
        'items' => $items,
    ];
}

$recent = [];

$query = mysqli_query($conn, "
    SELECT
        d.id,
        d.ddt_no,
        d.ddt_date,
        d.project_name,
        COUNT(dt.id) AS drawing_count
    FROM ddt_main d
    LEFT JOIN ddt_details dt ON dt.ddt_main_id = d.id
    WHERE d.created_by = $employeeId
    GROUP BY d.id
    ORDER BY d.created_at DESC
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
    <title>DDT - TEK-C PMC Construction</title>

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

    .dds-table {
        min-width: 1250px;
    }

    .dds-table th {
        font-size: 11px;
        text-transform: uppercase;
        color: var(--text-muted);
        font-weight: 900;
        background: rgba(148, 163, 184, .10);
        white-space: nowrap;
        text-align: center;
        vertical-align: middle;
    }

    .section-row td {
        background: rgba(148, 163, 184, .10);
        font-weight: 900;
    }

    .section-code {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        height: 28px;
        border-radius: 9px;
        background: #2563eb;
        color: #fff;
        margin-right: 8px;
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
                                ? 'Resubmit Design Deliverable Tracker (DDT)'
                                : 'Design Deliverable Tracker (DDT)' ?>
                            </h1>

                            <p class="text-muted-custom mb-0 small">
                                Track planned, actual and expected drawing deliverables.
                            </p>
                        </div>

                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <label class="badge-soft mb-0 <?= !$previousPayload ? 'opacity-75' : '' ?>">
                                <input type="checkbox" id="loadPrevious" <?= !$previousPayload ? 'disabled' : '' ?>>

                                <span>
                                    <strong>Load previous data</strong>
                                    <small class="d-block text-muted-custom">
                                        <?= $previousPayload
                                        ? ddt_e($previousPayload['ddt_no'])
                                            . ' · '
                                            . ddt_e(date(
                                                'd M Y',
                                                strtotime($previousPayload['ddt_date'])
                                            ))
                                        : 'No previous data' ?>
                                    </small>
                                </span>
                            </label>

                            <span class="badge-soft">
                                <i data-lucide="user" style="width:15px"></i>
                                <?= ddt_e($employeeName) ?>
                            </span>

                            <span class="badge-soft">
                                <?= ddt_e($designationName) ?>
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

                            <select id="projectPicker" class="form-select rounded-4">
                                <option value="">-- Select Assigned Project --</option>

                                <?php foreach ($projects as $projectOption): ?>
                                <option value="<?= (int)$projectOption['id'] ?>" <?= (int)$projectOption['id'] === $projectId
                                        ? 'selected'
                                        : '' ?>>
                                    <?= ddt_e($projectOption['project_name']) ?>
                                    <?= !empty($projectOption['project_code'])
                                        ? ' (' . ddt_e($projectOption['project_code']) . ')'
                                        : '' ?>
                                    - <?= ddt_e($projectOption['project_location'] ?: '-') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-3">
                            <a href="ddt.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">
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
                            <div class="fw-bold"><?= ddt_e($project['project_name']) ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Client</small>
                            <div class="fw-bold"><?= ddt_e($project['client_name'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Location</small>
                            <div class="fw-bold"><?= ddt_e($project['project_location'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Schedule</small>
                            <div class="fw-bold">
                                <?= ddt_e($pmsSchedule['schedule_name'] ?? 'PMS Schedule') ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Start</small>
                            <div class="fw-bold"><?= ddt_e($pmsStart ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS End</small>
                            <div class="fw-bold"><?= ddt_e($pmsEnd ?: '-') ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <form method="POST" id="ddtForm">
                    <input type="hidden" name="submit_ddt" value="1">
                    <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
                    <input type="hidden" name="resubmit_submission_id" value="<?= (int)$resubmitSubmissionId ?>">
                    <input type="hidden" name="items_json" id="items_json">

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="clipboard-check"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">DDT Header</h2>
                                <p class="text-muted-custom small mb-0">
                                    Report number, revision and consultant details.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">DDT No</label>
                                <input class="form-control rounded-4" name="ddt_no"
                                    value="<?= ddt_e($formData['ddt_no']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">DDT Date</label>
                                <input type="date" class="form-control rounded-4" id="ddt_date" name="ddt_date"
                                    value="<?= ddt_e($formData['ddt_date']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Architect</label>
                                <input class="form-control rounded-4" id="architects" name="architects"
                                    placeholder="Enter architect name" value="<?= ddt_e($formData['architects']) ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">PMC</label>
                                <input class="form-control rounded-4" id="pmc" name="pmc" placeholder="Enter PMC name"
                                    value="<?= ddt_e($formData['pmc']) ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Revision / Date</label>
                                <input class="form-control rounded-4" id="revisions" name="revisions"
                                    placeholder="e.g. R1 - 15/03/2026" value="<?= ddt_e($formData['revisions']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="files"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">
                                    Drawing Deliverables
                                </h2>

                                <p class="text-muted-custom small mb-0">
                                    Add drawing schedules under the required sections.
                                </p>
                            </div>
                        </div>

                        <div class="table-responsive thin-scrollbar">
                            <table class="table table-bordered dds-table">
                                <thead>
                                    <tr>
                                        <th>SL</th>
                                        <th>List of Drawings</th>
                                        <th>Site Schedule Start</th>
                                        <th>Drawing Deliverable Date</th>
                                        <th>Actual / Expected</th>
                                        <th>Remark</th>
                                        <th>Del</th>
                                    </tr>
                                </thead>

                                <tbody id="ddtBody"></tbody>
                            </table>
                        </div>

                        <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                            <button type="button" class="btn btn-outline-primary rounded-4 fw-bold add-section-row"
                                data-section="A">
                                Add Architectural
                            </button>

                            <button type="button" class="btn btn-outline-primary rounded-4 fw-bold add-section-row"
                                data-section="B">
                                Add Structural
                            </button>

                            <button type="button" class="btn btn-outline-primary rounded-4 fw-bold add-section-row"
                                data-section="C">
                                Add MEP
                            </button>
                        </div>

                        <div class="mt-3">
                            <label class="form-label fw-bold small">
                                General Remarks
                            </label>

                            <textarea class="form-control rounded-4" id="remarks" name="remarks" rows="3"
                                placeholder="Enter overall DDT remarks"><?= ddt_e($formData['remarks']) ?></textarea>
                        </div>
                    </div>

                    <div class="section-box mb-3">
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4"
                                <?= !$project ? 'disabled' : '' ?>>
                                <?= $resubmitSubmissionId > 0
                                ? 'Resubmit DDT'
                                : 'Submit DDT' ?>
                            </button>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4">
                        <h2 class="fw-bold fs-6 mb-1">Recent DDT</h2>
                        <p class="text-muted-custom small mb-0">
                            Your latest Design Deliverable Tracker submissions.
                        </p>
                    </div>

                    <div class="px-3 px-lg-4 pb-4">
                        <div class="row g-2">
                            <?php foreach ($recent as $recentRow): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="recent-card">
                                    <div class="fw-bold">
                                        <?= ddt_e($recentRow['ddt_no']) ?>
                                    </div>

                                    <small class="text-muted-custom">
                                        <?= ddt_e($recentRow['project_name']) ?>
                                        ·
                                        <?= ddt_e(date(
                                            'd M Y',
                                            strtotime($recentRow['ddt_date'])
                                        )) ?>
                                        ·
                                        <?= (int)$recentRow['drawing_count'] ?> drawing(s)
                                    </small>

                                    <br>

                                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2" target="_blank"
                                        href="reports-print/report-ddt-print.php?view=<?= (int)$recentRow['id'] ?>">
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
    <script src="assets/js/script.js?v=50"></script>

    <script>
    const sectionTitles = {
        A: 'ARCHITECTURAL & INTERIOR DRAWINGS',
        B: 'STRUCTURAL DRAWINGS',
        C: 'MEP DRAWINGS'
    };

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

    const ddtBody = document.getElementById('ddtBody');

    const snapshot = {
        architects: document.getElementById('architects')?.value || '',
        pmc: document.getElementById('pmc')?.value || '',
        revisions: document.getElementById('revisions')?.value || '',
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

    function createSectionHeader(section) {
        const row = document.createElement('tr');
        row.className = 'section-row';
        row.dataset.sectionHeader = section;

        row.innerHTML = `
        <td colspan="7">
            <span class="section-code">${section}</span>
            ${escapeHtml(sectionTitles[section] || 'DRAWINGS')}
        </td>
    `;

        return row;
    }

    function createDataRow(values = {}) {
        const section = values.section || 'A';
        const row = document.createElement('tr');
        row.className = 'ddt-data-row';
        row.dataset.section = section;

        row.innerHTML = `
        <td class="sl text-center fw-bold"></td>

        <td>
            <input class="form-control rounded-4 drawing"
                   placeholder="Drawing name / description"
                   value="${escapeHtml(values.list_of_drawings || '')}">
        </td>

        <td>
            <input type="date"
                   class="form-control rounded-4 site-schedule-start"
                   value="${escapeHtml(values.site_schedule_start || '')}">
        </td>

        <td>
            <input type="date"
                   class="form-control rounded-4 deliverable-date"
                   value="${escapeHtml(values.drawing_deliverable_date || '')}">
        </td>

        <td>
            <select class="form-select rounded-4 actual-expected">
                <option value="">-- Select --</option>
                <option value="Actual" ${values.actual_expected === 'Actual' ? 'selected' : ''}>Actual</option>
                <option value="Expected" ${values.actual_expected === 'Expected' ? 'selected' : ''}>Expected</option>
            </select>
        </td>

        <td>
            <input class="form-control rounded-4 row-remarks"
                   placeholder="Enter remark"
                   value="${escapeHtml(values.remarks || '')}">
        </td>

        <td class="text-center">
            <button type="button"
                    class="btn btn-sm btn-outline-danger rounded-4 delete-row">
                <i data-lucide="trash-2"></i>
            </button>
        </td>
    `;

        return row;
    }

    function renderItems(items) {
        ddtBody.innerHTML = '';

        const grouped = {
            A: [],
            B: [],
            C: []
        };

        (items || []).forEach(item => {
            const section = grouped[item.section] ?
                item.section :
                'A';

            grouped[section].push(item);
        });

        Object.keys(sectionTitles).forEach(section => {
            ddtBody.appendChild(createSectionHeader(section));

            const rows = grouped[section].length ?
                grouped[section] :
                [{
                    section
                }];

            rows.forEach(item => {
                ddtBody.appendChild(
                    createDataRow({
                        ...item,
                        section
                    })
                );
            });
        });

        renumberRows();
    }

    function renumberRows() {
        Object.keys(sectionTitles).forEach(section => {
            let counter = 1;

            ddtBody.querySelectorAll(
                `.ddt-data-row[data-section="${section}"]`
            ).forEach(row => {
                row.querySelector('.sl').textContent = counter++;
            });
        });

        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    function addSectionRow(section) {
        const header = ddtBody.querySelector(
            `[data-section-header="${section}"]`
        );

        if (!header) {
            return;
        }

        const row = createDataRow({
            section
        });
        let insertBefore = null;
        let cursor = header.nextElementSibling;

        while (
            cursor &&
            !cursor.dataset.sectionHeader
        ) {
            cursor = cursor.nextElementSibling;
        }

        insertBefore = cursor;

        if (insertBefore) {
            ddtBody.insertBefore(row, insertBefore);
        } else {
            ddtBody.appendChild(row);
        }

        renumberRows();
    }

    function loadSource(source) {
        document.getElementById('architects').value =
            source?.architects || '';

        document.getElementById('pmc').value =
            source?.pmc || '';

        document.getElementById('revisions').value =
            source?.revisions || '';

        document.getElementById('remarks').value =
            source?.remarks || '';

        renderItems(source?.items || []);
    }

    renderItems(initialItems);

    document.querySelectorAll('.add-section-row')
        .forEach(button => {
            button.addEventListener('click', () => {
                addSectionRow(button.dataset.section);
            });
        });

    document.addEventListener('click', event => {
        const button = event.target.closest('.delete-row');

        if (!button) {
            return;
        }

        const row = button.closest('.ddt-data-row');
        const section = row.dataset.section;

        const rows = ddtBody.querySelectorAll(
            `.ddt-data-row[data-section="${section}"]`
        );

        if (rows.length <= 1) {
            row.querySelectorAll('input').forEach(input => {
                input.value = '';
            });
            row.querySelectorAll('select').forEach(select => {
                select.value = '';
            });
        } else {
            row.remove();
        }

        renumberRows();
    });

    document.getElementById('loadPrevious')
        ?.addEventListener('change', function() {
            loadSource(
                this.checked ?
                previousData :
                snapshot
            );
        });

    document.getElementById('projectPicker')
        ?.addEventListener('change', function() {
            const date =
                document.getElementById('ddt_date')?.value ||
                '<?= ddt_e($reportDate) ?>';

            window.location.href = this.value ?
                'ddt.php?project_id=' +
                encodeURIComponent(this.value) +
                '&report_date=' +
                encodeURIComponent(date) :
                'ddt.php';
        });

    document.getElementById('ddtForm')
        ?.addEventListener('submit', function() {
            const items = [...ddtBody.querySelectorAll('.ddt-data-row')]
                .map(row => ({
                    section: row.dataset.section,
                    list_of_drawings: row.querySelector('.drawing').value,
                    site_schedule_start: row.querySelector('.site-schedule-start').value,
                    drawing_deliverable_date: row.querySelector('.deliverable-date').value,
                    actual_expected: row.querySelector('.actual-expected').value,
                    remarks: row.querySelector('.row-remarks').value
                }));

            document.getElementById('items_json').value =
                JSON.stringify(items);
        });
    </script>
</body>

</html>