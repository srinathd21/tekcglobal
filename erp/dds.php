<?php
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

function dds_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dds_employee_id(mysqli $conn): int
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

function dds_is_super_admin(mysqli $conn): bool
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

function dds_report_access(mysqli $conn, string $permission): bool
{
    if (dds_is_super_admin($conn)) {
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
          AND rt.report_code = 'DDS'
          AND rt.is_active = 1
    ");

    return $query
        && ($row = mysqli_fetch_assoc($query))
        && (int)($row['allowed'] ?? 0) === 1;
}

function dds_project_allowed(
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

function dds_columns(mysqli $conn, string $table): array
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $columns = [];

    $query = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $columns[$row['Field']] = true;
    }

    return $columns;
}

function dds_sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function dds_report_type_id(mysqli $conn): int
{
    $query = mysqli_query($conn, "
        SELECT id
        FROM master_report_types
        WHERE report_code = 'DDS'
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function dds_find_existing(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate
): int {
    if ($submissionId > 0) {
        $columns = dds_columns($conn, 'project_report_submissions');
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
                     FROM dds_main
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
        FROM dds_main
        WHERE project_id = $projectId
          AND created_by = $employeeId
          AND dds_date = '$dateEsc'
        ORDER BY id DESC
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function dds_sync_submission(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate,
    int $ddsId,
    string $ddsNo
): void {
    $reportTypeId = dds_report_type_id($conn);

    if ($reportTypeId <= 0 || $ddsId <= 0) {
        return;
    }

    $columns = dds_columns($conn, 'project_report_submissions');
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
                    report_reference_id = $ddsId
                 OR source_id = $ddsId
                 OR reference_id = $ddsId
                 OR report_no = '" . mysqli_real_escape_string($conn, $ddsNo) . "'
              )
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($query && ($row = mysqli_fetch_assoc($query))) {
            $submissionId = (int)$row['id'];
        }
    }

    $map = [
        'report_no' => $ddsNo,
        'report_number' => $ddsNo,
        'submission_no' => $ddsNo,
        'title' => 'Design Deliverable Schedule',
        'submitted_by_employee_id' => $employeeId,
        'submitted_by_user_id' => $userId > 0 ? $userId : null,
        'submission_for_date' => $reportDate,
        'period_start' => $reportDate,
        'period_end' => $reportDate,
        'status' => 'submitted',
        'submitted_at' => date('Y-m-d H:i:s'),
        'source_table' => 'dds_main',
        'source_id' => $ddsId,
        'report_reference_table' => 'dds_main',
        'report_reference_id' => $ddsId,
        'reference_id' => $ddsId,
        'updated_by' => $userId > 0 ? $userId : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($submissionId > 0) {
        $sets = [];

        foreach ($map as $field => $value) {
            if (isset($columns[$field])) {
                $sets[] = "`$field` = " . dds_sql_value($conn, $value);
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
            $insertValues[] = dds_sql_value($conn, $value);
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

function dds_redirect_hub(
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

$employeeId = dds_employee_id($conn);
$isSuperAdmin = dds_is_super_admin($conn);

if ($employeeId <= 0) {
    header('Location: login.php');
    exit;
}

if (!dds_report_access($conn, 'can_submit')) {
    header(
        'Location: reports-hub.php?error='
        . urlencode('You do not have DDS submit access.')
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
    ?? $_POST['dds_date']
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
    && dds_project_allowed(
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
    $prefix = 'DDS/' . $projectId . '/' . date('Ym', strtotime($reportDate)) . '/';
    $prefixEsc = mysqli_real_escape_string($conn, $prefix);

    $query = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM dds_main
         WHERE dds_no LIKE '$prefixEsc%'"
    );

    $count = $query
        ? (int)(mysqli_fetch_assoc($query)['total'] ?? 0)
        : 0;

    $defaultNo = $prefix
        . str_pad((string)($count + 1), 3, '0', STR_PAD_LEFT);
}

$existingReport = null;

if ($resubmitSubmissionId > 0 && $projectId > 0) {
    $existingId = dds_find_existing(
        $conn,
        $resubmitSubmissionId,
        $projectId,
        $employeeId,
        $reportDate
    );

    if ($existingId > 0) {
        $query = mysqli_query(
            $conn,
            "SELECT * FROM dds_main WHERE id = $existingId LIMIT 1"
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
        "project_id = $projectId AND created_by = $employeeId AND dds_date < '$dateEsc'",
        "project_id = $projectId AND created_by = $employeeId",
        "project_id = $projectId"
    ] as $where) {
        $query = mysqli_query($conn, "
            SELECT *
            FROM dds_main
            WHERE $where
            ORDER BY dds_date DESC, created_at DESC, id DESC
            LIMIT 1
        ");

        if ($query && ($previousReport = mysqli_fetch_assoc($query))) {
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dds'])) {
    $postProjectId = (int)($_POST['project_id'] ?? 0);
    $postSubmissionId = (int)($_POST['resubmit_submission_id'] ?? 0);

    $ddsNo = trim((string)($_POST['dds_no'] ?? ''));
    $ddsDate = trim((string)($_POST['dds_date'] ?? ''));
    $architect = trim((string)($_POST['architect'] ?? ''));
    $structuralConsultant = trim((string)($_POST['structural_consultant'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));
    $version = trim((string)($_POST['version'] ?? 'R0'));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $items = json_decode(
        (string)($_POST['items_json'] ?? '[]'),
        true
    );

    $error = '';

    if (
        !dds_project_allowed(
            $conn,
            $postProjectId,
            $employeeId,
            $isSuperAdmin
        )
    ) {
        $error = 'Invalid project selection.';
    } elseif ($ddsNo === '') {
        $error = 'DDS No is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ddsDate)) {
        $error = 'Valid DDS date is required.';
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
                $ddsId = dds_find_existing(
                    $conn,
                    $postSubmissionId,
                    $postProjectId,
                    $employeeId,
                    $ddsDate
                );

                if ($ddsId <= 0) {
                    throw new RuntimeException(
                        'Original DDS not found for resubmission.'
                    );
                }

                $stmt = mysqli_prepare($conn, "
                    UPDATE dds_main
                    SET
                        dds_no = ?,
                        project_id = ?,
                        site_id = ?,
                        client_id = ?,
                        project_name = ?,
                        client_name = ?,
                        architect = ?,
                        structural_consultant = ?,
                        pmc = ?,
                        version = ?,
                        dds_date = ?,
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
                    'siiisssssssssi',
                    $ddsNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $architect,
                    $structuralConsultant,
                    $pmc,
                    $version,
                    $ddsDate,
                    $employeeName,
                    $remarks,
                    $ddsId
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                mysqli_stmt_close($stmt);

                mysqli_query(
                    $conn,
                    "DELETE FROM dds_details WHERE dds_main_id = $ddsId"
                );
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO dds_main
                    (
                        dds_no,
                        project_id,
                        site_id,
                        client_id,
                        project_name,
                        client_name,
                        architect,
                        structural_consultant,
                        pmc,
                        version,
                        dds_date,
                        created_by,
                        created_by_name,
                        remarks
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt) {
                    throw new RuntimeException(mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'siiisssssssiss',
                    $ddsNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $architect,
                    $structuralConsultant,
                    $pmc,
                    $version,
                    $ddsDate,
                    $employeeId,
                    $employeeName,
                    $remarks
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                $ddsId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }

            $detailStmt = mysqli_prepare($conn, "
                INSERT INTO dds_details
                (
                    dds_main_id,
                    sl_no,
                    section,
                    list_of_drawings,
                    duration_days,
                    start_date,
                    end_date,
                    remarks
                )
                VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?)
            ");

            if (!$detailStmt) {
                throw new RuntimeException(mysqli_error($conn));
            }

            $sectionCounters = [];

            foreach ($items as $item) {
                $section = strtoupper(trim((string)($item['section'] ?? 'A')));

                if (!in_array($section, ['A', 'B', 'C', 'D', 'E'], true)) {
                    $section = 'A';
                }

                $sectionCounters[$section] =
                    ($sectionCounters[$section] ?? 0) + 1;

                $slNo = $sectionCounters[$section];
                $drawing = trim((string)($item['list_of_drawings'] ?? ''));
                $duration = max(0, (int)($item['duration_days'] ?? 0));
                $startDate = trim((string)($item['start_date'] ?? ''));
                $endDate = trim((string)($item['end_date'] ?? ''));
                $itemRemarks = trim((string)($item['remarks'] ?? ''));

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
                    'iississs',
                    $ddsId,
                    $slNo,
                    $section,
                    $drawing,
                    $duration,
                    $startDate,
                    $endDate,
                    $itemRemarks
                );

                if (!mysqli_stmt_execute($detailStmt)) {
                    throw new RuntimeException(
                        mysqli_stmt_error($detailStmt)
                    );
                }
            }

            mysqli_stmt_close($detailStmt);

            dds_sync_submission(
                $conn,
                $postSubmissionId,
                $postProjectId,
                $employeeId,
                $ddsDate,
                $ddsId,
                $ddsNo
            );

            mysqli_commit($conn);

            dds_redirect_hub(
                $postProjectId,
                $ddsDate,
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
            'Location: dds.php'
            . '?project_id=' . $postProjectId
            . '&report_date=' . urlencode($ddsDate)
            . '&error=' . urlencode($error)
        );
        exit;
    }
}

$formData = [
    'dds_no' => $existingReport['dds_no'] ?? $defaultNo,
    'dds_date' => $existingReport['dds_date'] ?? $reportDate,
    'architect' => $existingReport['architect'] ?? '',
    'structural_consultant' => $existingReport['structural_consultant'] ?? '',
    'pmc' => $existingReport['pmc'] ?? '',
    'version' => $existingReport['version'] ?? 'R0',
    'remarks' => $existingReport['remarks'] ?? '',
    'items' => [],
];

if ($existingReport) {
    $query = mysqli_query(
        $conn,
        "SELECT *
         FROM dds_details
         WHERE dds_main_id = " . (int)$existingReport['id'] . "
         ORDER BY FIELD(section, 'A', 'B', 'C', 'D', 'E'), sl_no, id"
    );

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $formData['items'][] = $row;
    }
}

if (!$formData['items']) {
    foreach (['A', 'B', 'C', 'D', 'E'] as $section) {
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
         FROM dds_details
         WHERE dds_main_id = " . (int)$previousReport['id'] . "
         ORDER BY FIELD(section, 'A', 'B', 'C', 'D', 'E'), sl_no, id"
    );

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $items[] = $row;
    }

    $previousPayload = [
        'dds_no' => $previousReport['dds_no'] ?? '',
        'dds_date' => $previousReport['dds_date'] ?? '',
        'architect' => $previousReport['architect'] ?? '',
        'structural_consultant' => $previousReport['structural_consultant'] ?? '',
        'pmc' => $previousReport['pmc'] ?? '',
        'version' => $previousReport['version'] ?? 'R0',
        'remarks' => $previousReport['remarks'] ?? '',
        'items' => $items,
    ];
}

$recent = [];

$query = mysqli_query($conn, "
    SELECT
        d.id,
        d.dds_no,
        d.dds_date,
        d.project_name,
        COUNT(dt.id) AS drawing_count
    FROM dds_main d
    LEFT JOIN dds_details dt ON dt.dds_main_id = d.id
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
    <title>DDS - TEK-C PMC Construction</title>

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
                                ? 'Resubmit Design Deliverable Schedule (DDS)'
                                : 'Design Deliverable Schedule (DDS)' ?>
                        </h1>

                        <p class="text-muted-custom mb-0 small">
                            Track design drawings, durations and delivery dates.
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
                                        ? dds_e($previousPayload['dds_no'])
                                            . ' · '
                                            . dds_e(date(
                                                'd M Y',
                                                strtotime($previousPayload['dds_date'])
                                            ))
                                        : 'No previous data' ?>
                                </small>
                            </span>
                        </label>

                        <span class="badge-soft">
                            <i data-lucide="user" style="width:15px"></i>
                            <?= dds_e($employeeName) ?>
                        </span>

                        <span class="badge-soft">
                            <?= dds_e($designationName) ?>
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
                                    <?= dds_e($projectOption['project_name']) ?>
                                    <?= !empty($projectOption['project_code'])
                                        ? ' (' . dds_e($projectOption['project_code']) . ')'
                                        : '' ?>
                                    - <?= dds_e($projectOption['project_location'] ?: '-') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3">
                        <a href="dds.php"
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
                            <div class="fw-bold"><?= dds_e($project['project_name']) ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Client</small>
                            <div class="fw-bold"><?= dds_e($project['client_name'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Location</small>
                            <div class="fw-bold"><?= dds_e($project['project_location'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Schedule</small>
                            <div class="fw-bold">
                                <?= dds_e($pmsSchedule['schedule_name'] ?? 'PMS Schedule') ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Start</small>
                            <div class="fw-bold"><?= dds_e($pmsStart ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS End</small>
                            <div class="fw-bold"><?= dds_e($pmsEnd ?: '-') ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <form method="POST" id="ddsForm">
                <input type="hidden" name="submit_dds" value="1">
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
                            <h2 class="fw-bold fs-6 mb-1">DDS Header</h2>
                            <p class="text-muted-custom small mb-0">
                                Report number, version and consultant details.
                            </p>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">DDS No</label>
                            <input class="form-control rounded-4"
                                   name="dds_no"
                                   value="<?= dds_e($formData['dds_no']) ?>"
                                   required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">DDS Date</label>
                            <input type="date"
                                   class="form-control rounded-4"
                                   id="dds_date"
                                   name="dds_date"
                                   value="<?= dds_e($formData['dds_date']) ?>"
                                   required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Version</label>
                            <input class="form-control rounded-4"
                                   id="version"
                                   name="version"
                                   placeholder="e.g. R0, R1, Final"
                                   value="<?= dds_e($formData['version']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Architect</label>
                            <input class="form-control rounded-4"
                                   id="architect"
                                   name="architect"
                                   placeholder="Enter architect name"
                                   value="<?= dds_e($formData['architect']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">
                                Structural Consultant
                            </label>
                            <input class="form-control rounded-4"
                                   id="structural_consultant"
                                   name="structural_consultant"
                                   placeholder="Enter structural consultant"
                                   value="<?= dds_e($formData['structural_consultant']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">PMC</label>
                            <input class="form-control rounded-4"
                                   id="pmc"
                                   name="pmc"
                                   placeholder="Enter PMC name"
                                   value="<?= dds_e($formData['pmc']) ?>">
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
                                <th>Duration Days</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Remark</th>
                                <th>Del</th>
                            </tr>
                            </thead>

                            <tbody id="ddsBody"></tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                        <button type="button"
                                class="btn btn-outline-primary rounded-4 fw-bold add-section-row"
                                data-section="A">
                            Add Architectural
                        </button>

                        <button type="button"
                                class="btn btn-outline-primary rounded-4 fw-bold add-section-row"
                                data-section="B">
                            Add Structural
                        </button>

                        <button type="button"
                                class="btn btn-outline-primary rounded-4 fw-bold add-section-row"
                                data-section="C">
                            Add Electrical
                        </button>

                        <button type="button"
                                class="btn btn-outline-primary rounded-4 fw-bold add-section-row"
                                data-section="D">
                            Add Plumbing
                        </button>

                        <button type="button"
                                class="btn btn-outline-primary rounded-4 fw-bold add-section-row"
                                data-section="E">
                            Add HVAC
                        </button>
                    </div>

                    <div class="mt-3">
                        <label class="form-label fw-bold small">
                            General Remarks
                        </label>

                        <textarea class="form-control rounded-4"
                                  id="remarks"
                                  name="remarks"
                                  rows="3"
                                  placeholder="Enter overall DDS remarks"><?= dds_e($formData['remarks']) ?></textarea>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="d-flex justify-content-end">
                        <button type="submit"
                                class="btn brand-gradient text-white rounded-4 fw-bold px-4"
                                <?= !$project ? 'disabled' : '' ?>>
                            <?= $resubmitSubmissionId > 0
                                ? 'Resubmit DDS'
                                : 'Submit DDS' ?>
                        </button>
                    </div>
                </div>
            </form>

            <section class="card-ui overflow-hidden">
                <div class="p-3 p-lg-4">
                    <h2 class="fw-bold fs-6 mb-1">Recent DDS</h2>
                    <p class="text-muted-custom small mb-0">
                        Your latest Design Deliverable Schedule submissions.
                    </p>
                </div>

                <div class="px-3 px-lg-4 pb-4">
                    <div class="row g-2">
                        <?php foreach ($recent as $recentRow): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="recent-card">
                                    <div class="fw-bold">
                                        <?= dds_e($recentRow['dds_no']) ?>
                                    </div>

                                    <small class="text-muted-custom">
                                        <?= dds_e($recentRow['project_name']) ?>
                                        ·
                                        <?= dds_e(date(
                                            'd M Y',
                                            strtotime($recentRow['dds_date'])
                                        )) ?>
                                        ·
                                        <?= (int)$recentRow['drawing_count'] ?> drawing(s)
                                    </small>

                                    <br>

                                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2"
                                       target="_blank"
                                       href="reports-print/report-dds-print.php?view=<?= (int)$recentRow['id'] ?>">
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
    A: 'ARCHITECTURAL DRAWINGS',
    B: 'STRUCTURAL DRAWINGS',
    C: 'ELECTRICAL DRAWINGS',
    D: 'PLUMBING DRAWINGS',
    E: 'HVAC DRAWINGS'
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

const ddsBody = document.getElementById('ddsBody');

const snapshot = {
    architect:
        document.getElementById('architect')?.value || '',
    structural_consultant:
        document.getElementById('structural_consultant')?.value || '',
    pmc:
        document.getElementById('pmc')?.value || '',
    version:
        document.getElementById('version')?.value || 'R0',
    remarks:
        document.getElementById('remarks')?.value || '',
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
    const start = row.querySelector('.start-date').value;
    const duration = parseInt(
        row.querySelector('.duration').value || '0',
        10
    );

    const end = row.querySelector('.end-date');

    if (!start || duration <= 0) {
        end.value = '';
        return;
    }

    const date = new Date(start + 'T00:00:00');
    date.setDate(date.getDate() + duration - 1);
    end.value = date.toISOString().slice(0, 10);
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
    row.className = 'dds-data-row';
    row.dataset.section = section;

    row.innerHTML = `
        <td class="sl text-center fw-bold"></td>

        <td>
            <input class="form-control rounded-4 drawing"
                   placeholder="Drawing name / description"
                   value="${escapeHtml(values.list_of_drawings || '')}">
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
                   class="form-control rounded-4 start-date"
                   value="${escapeHtml(values.start_date || '')}">
        </td>

        <td>
            <input type="date"
                   class="form-control rounded-4 end-date"
                   value="${escapeHtml(values.end_date || '')}"
                   readonly>
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

    row.querySelector('.start-date')
        .addEventListener('change', () => calculateEndDate(row));

    row.querySelector('.duration')
        .addEventListener('input', () => calculateEndDate(row));

    return row;
}

function renderItems(items) {
    ddsBody.innerHTML = '';

    const grouped = {A: [], B: [], C: [], D: [], E: []};

    (items || []).forEach(item => {
        const section = grouped[item.section]
            ? item.section
            : 'A';

        grouped[section].push(item);
    });

    Object.keys(sectionTitles).forEach(section => {
        ddsBody.appendChild(createSectionHeader(section));

        const rows = grouped[section].length
            ? grouped[section]
            : [{section}];

        rows.forEach(item => {
            ddsBody.appendChild(
                createDataRow({...item, section})
            );
        });
    });

    renumberRows();
}

function renumberRows() {
    Object.keys(sectionTitles).forEach(section => {
        let counter = 1;

        ddsBody.querySelectorAll(
            `.dds-data-row[data-section="${section}"]`
        ).forEach(row => {
            row.querySelector('.sl').textContent = counter++;
        });
    });

    if (window.lucide) {
        window.lucide.createIcons();
    }
}

function addSectionRow(section) {
    const header = ddsBody.querySelector(
        `[data-section-header="${section}"]`
    );

    if (!header) {
        return;
    }

    const row = createDataRow({section});
    let insertBefore = null;
    let cursor = header.nextElementSibling;

    while (
        cursor
        && !cursor.dataset.sectionHeader
    ) {
        cursor = cursor.nextElementSibling;
    }

    insertBefore = cursor;

    if (insertBefore) {
        ddsBody.insertBefore(row, insertBefore);
    } else {
        ddsBody.appendChild(row);
    }

    renumberRows();
}

function loadSource(source) {
    document.getElementById('architect').value =
        source?.architect || '';

    document.getElementById('structural_consultant').value =
        source?.structural_consultant || '';

    document.getElementById('pmc').value =
        source?.pmc || '';

    document.getElementById('version').value =
        source?.version || 'R0';

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

    const row = button.closest('.dds-data-row');
    const section = row.dataset.section;

    const rows = ddsBody.querySelectorAll(
        `.dds-data-row[data-section="${section}"]`
    );

    if (rows.length <= 1) {
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
            document.getElementById('dds_date')?.value
            || '<?= dds_e($reportDate) ?>';

        window.location.href = this.value
            ? 'dds.php?project_id='
                + encodeURIComponent(this.value)
                + '&report_date='
                + encodeURIComponent(date)
            : 'dds.php';
    });

document.getElementById('ddsForm')
    ?.addEventListener('submit', function () {
        const items = [...ddsBody.querySelectorAll('.dds-data-row')]
            .map(row => ({
                section: row.dataset.section,
                list_of_drawings:
                    row.querySelector('.drawing').value,
                duration_days:
                    Number(row.querySelector('.duration').value || 0),
                start_date:
                    row.querySelector('.start-date').value,
                end_date:
                    row.querySelector('.end-date').value,
                remarks:
                    row.querySelector('.row-remarks').value
            }));

        document.getElementById('items_json').value =
            JSON.stringify(items);
    });
</script>
</body>
</html>
