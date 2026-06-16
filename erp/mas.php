<?php
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

function mas_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function mas_employee_id(mysqli $conn): int
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

function mas_is_super_admin(mysqli $conn): bool
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

function mas_report_access(mysqli $conn, string $permission): bool
{
    if (mas_is_super_admin($conn)) {
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
          AND rt.report_code = 'MAS'
          AND rt.is_active = 1
    ");

    return $query
        && ($row = mysqli_fetch_assoc($query))
        && (int)($row['allowed'] ?? 0) === 1;
}

function mas_project_allowed(
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

function mas_table_columns(mysqli $conn, string $table): array
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $columns = [];

    $query = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $columns[$row['Field']] = true;
    }

    return $columns;
}

function mas_sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function mas_report_type_id(mysqli $conn): int
{
    $query = mysqli_query($conn, "
        SELECT id
        FROM master_report_types
        WHERE report_code = 'MAS'
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function mas_find_existing(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate
): int {
    if ($submissionId > 0) {
        $columns = mas_table_columns($conn, 'project_report_submissions');
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
                    "SELECT id FROM mas_main WHERE id = $candidateId LIMIT 1"
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
        FROM mas_main
        WHERE project_id = $projectId
          AND created_by = $employeeId
          AND meeting_date = '$dateEsc'
        ORDER BY id DESC
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function mas_sync_submission(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate,
    int $masId,
    string $masNo,
    string $meetingTitle
): void {
    $reportTypeId = mas_report_type_id($conn);

    if ($reportTypeId <= 0 || $masId <= 0) {
        return;
    }

    $columns = mas_table_columns($conn, 'project_report_submissions');
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
                    report_reference_id = $masId
                 OR source_id = $masId
                 OR reference_id = $masId
                 OR report_no = '" . mysqli_real_escape_string($conn, $masNo) . "'
              )
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($query && ($row = mysqli_fetch_assoc($query))) {
            $submissionId = (int)$row['id'];
        }
    }

    $map = [
        'report_no' => $masNo,
        'report_number' => $masNo,
        'submission_no' => $masNo,
        'title' => $meetingTitle !== '' ? $meetingTitle : 'Meeting Attendance Sheet',
        'submitted_by_employee_id' => $employeeId,
        'submitted_by_user_id' => $userId > 0 ? $userId : null,
        'submission_for_date' => $reportDate,
        'period_start' => $reportDate,
        'period_end' => $reportDate,
        'status' => 'submitted',
        'submitted_at' => date('Y-m-d H:i:s'),
        'source_table' => 'mas_main',
        'source_id' => $masId,
        'report_reference_table' => 'mas_main',
        'report_reference_id' => $masId,
        'reference_id' => $masId,
        'updated_by' => $userId > 0 ? $userId : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($submissionId > 0) {
        $sets = [];

        foreach ($map as $field => $value) {
            if (isset($columns[$field])) {
                $sets[] = "`$field` = " . mas_sql_value($conn, $value);
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
            $insertValues[] = mas_sql_value($conn, $value);
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

function mas_redirect_hub(
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

$employeeId = mas_employee_id($conn);
$isSuperAdmin = mas_is_super_admin($conn);

if ($employeeId <= 0) {
    header('Location: login.php');
    exit;
}

if (!mas_report_access($conn, 'can_submit')) {
    header(
        'Location: reports-hub.php?error='
        . urlencode('You do not have MAS submit access.')
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
    ?? $_POST['meeting_date']
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
    && mas_project_allowed(
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

$defaultMasNo = '';

if ($projectId > 0) {
    $prefix = 'MAS/' . $projectId . '/' . date('Ym', strtotime($reportDate)) . '/';
    $prefixEsc = mysqli_real_escape_string($conn, $prefix);

    $countQuery = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM mas_main
         WHERE mas_no LIKE '$prefixEsc%'"
    );

    $count = $countQuery
        ? (int)(mysqli_fetch_assoc($countQuery)['total'] ?? 0)
        : 0;

    $defaultMasNo = $prefix
        . str_pad((string)($count + 1), 3, '0', STR_PAD_LEFT);
}

$existingReport = null;

if ($resubmitSubmissionId > 0 && $projectId > 0) {
    $existingId = mas_find_existing(
        $conn,
        $resubmitSubmissionId,
        $projectId,
        $employeeId,
        $reportDate
    );

    if ($existingId > 0) {
        $query = mysqli_query(
            $conn,
            "SELECT * FROM mas_main WHERE id = $existingId LIMIT 1"
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
        "project_id = $projectId AND created_by = $employeeId AND meeting_date < '$dateEsc'",
        "project_id = $projectId AND created_by = $employeeId",
        "project_id = $projectId"
    ] as $where) {
        $query = mysqli_query($conn, "
            SELECT *
            FROM mas_main
            WHERE $where
            ORDER BY meeting_date DESC, created_at DESC, id DESC
            LIMIT 1
        ");

        if ($query && ($previousReport = mysqli_fetch_assoc($query))) {
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_mas'])) {
    $postProjectId = (int)($_POST['project_id'] ?? 0);
    $postSubmissionId = (int)($_POST['resubmit_submission_id'] ?? 0);

    $masNo = trim((string)($_POST['mas_no'] ?? ''));
    $meetingTitle = trim((string)($_POST['meeting_title'] ?? ''));
    $meetingDate = trim((string)($_POST['meeting_date'] ?? ''));
    $meetingTime = trim((string)($_POST['meeting_time'] ?? ''));
    $meetingVenue = trim((string)($_POST['meeting_venue'] ?? ''));
    $conductedBy = trim((string)($_POST['conducted_by'] ?? ''));
    $architect = trim((string)($_POST['architect'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $items = json_decode(
        (string)($_POST['items_json'] ?? '[]'),
        true
    );

    $error = '';

    if (
        !mas_project_allowed(
            $conn,
            $postProjectId,
            $employeeId,
            $isSuperAdmin
        )
    ) {
        $error = 'Invalid project selection.';
    } elseif ($masNo === '') {
        $error = 'MAS No is required.';
    } elseif ($meetingTitle === '') {
        $error = 'Meeting title is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate)) {
        $error = 'Valid meeting date is required.';
    } elseif (!is_array($items)) {
        $error = 'Invalid attendee rows.';
    }

    $items = array_values(array_filter(
        is_array($items) ? $items : [],
        static fn($row) =>
            trim((string)($row['attendee_name'] ?? '')) !== ''
    ));

    if ($error === '' && !$items) {
        $error = 'Please enter at least one attendee.';
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
                $masId = mas_find_existing(
                    $conn,
                    $postSubmissionId,
                    $postProjectId,
                    $employeeId,
                    $meetingDate
                );

                if ($masId <= 0) {
                    throw new RuntimeException(
                        'Original MAS not found for resubmission.'
                    );
                }

                $stmt = mysqli_prepare($conn, "
                    UPDATE mas_main
                    SET
                        mas_no = ?,
                        project_id = ?,
                        site_id = ?,
                        client_id = ?,
                        project_name = ?,
                        client_name = ?,
                        architect = ?,
                        pmc = ?,
                        meeting_title = ?,
                        meeting_date = ?,
                        meeting_time = NULLIF(?, ''),
                        meeting_venue = ?,
                        conducted_by = ?,
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
                    'siiisssssssssssi',
                    $masNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $architect,
                    $pmc,
                    $meetingTitle,
                    $meetingDate,
                    $meetingTime,
                    $meetingVenue,
                    $conductedBy,
                    $employeeName,
                    $remarks,
                    $masId
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                mysqli_stmt_close($stmt);

                mysqli_query(
                    $conn,
                    "DELETE FROM mas_details WHERE mas_main_id = $masId"
                );
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO mas_main
                    (
                        mas_no,
                        project_id,
                        site_id,
                        client_id,
                        project_name,
                        client_name,
                        architect,
                        pmc,
                        meeting_title,
                        meeting_date,
                        meeting_time,
                        meeting_venue,
                        conducted_by,
                        created_by,
                        created_by_name,
                        remarks
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        NULLIF(?, ''), ?, ?, ?, ?, ?
                    )
                ");

                if (!$stmt) {
                    throw new RuntimeException(mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'siiisssssssssiss',
                    $masNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $architect,
                    $pmc,
                    $meetingTitle,
                    $meetingDate,
                    $meetingTime,
                    $meetingVenue,
                    $conductedBy,
                    $employeeId,
                    $employeeName,
                    $remarks
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                $masId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }

            $detailStmt = mysqli_prepare($conn, "
                INSERT INTO mas_details
                (
                    mas_main_id,
                    sl_no,
                    attendee_name,
                    organization,
                    mobile_no,
                    email_id,
                    signature
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$detailStmt) {
                throw new RuntimeException(mysqli_error($conn));
            }

            foreach ($items as $index => $item) {
                $slNo = $index + 1;
                $attendeeName = trim((string)($item['attendee_name'] ?? ''));
                $organization = trim((string)($item['organization'] ?? ''));
                $mobileNo = trim((string)($item['mobile_no'] ?? ''));
                $emailId = trim((string)($item['email_id'] ?? ''));
                $signature = trim((string)($item['signature'] ?? ''));

                mysqli_stmt_bind_param(
                    $detailStmt,
                    'iisssss',
                    $masId,
                    $slNo,
                    $attendeeName,
                    $organization,
                    $mobileNo,
                    $emailId,
                    $signature
                );

                if (!mysqli_stmt_execute($detailStmt)) {
                    throw new RuntimeException(
                        mysqli_stmt_error($detailStmt)
                    );
                }
            }

            mysqli_stmt_close($detailStmt);

            mas_sync_submission(
                $conn,
                $postSubmissionId,
                $postProjectId,
                $employeeId,
                $meetingDate,
                $masId,
                $masNo,
                $meetingTitle
            );

            mysqli_commit($conn);

            mas_redirect_hub(
                $postProjectId,
                $meetingDate,
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
            'Location: mas.php'
            . '?project_id=' . $postProjectId
            . '&report_date=' . urlencode($meetingDate)
            . '&error=' . urlencode($error)
        );
        exit;
    }
}

$formData = [
    'mas_no' => $existingReport['mas_no'] ?? $defaultMasNo,
    'meeting_title' => $existingReport['meeting_title'] ?? '',
    'meeting_date' => $existingReport['meeting_date'] ?? $reportDate,
    'meeting_time' => $existingReport['meeting_time'] ?? '',
    'meeting_venue' => $existingReport['meeting_venue'] ?? '',
    'conducted_by' => $existingReport['conducted_by'] ?? '',
    'architect' => $existingReport['architect'] ?? '',
    'pmc' => $existingReport['pmc'] ?? '',
    'remarks' => $existingReport['remarks'] ?? '',
    'items' => [],
];

if ($existingReport) {
    $query = mysqli_query(
        $conn,
        "SELECT *
         FROM mas_details
         WHERE mas_main_id = " . (int)$existingReport['id'] . "
         ORDER BY sl_no, id"
    );

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $formData['items'][] = $row;
    }
}

if (!$formData['items']) {
    $formData['items'] = array_fill(0, 3, [
        'attendee_name' => '',
        'organization' => '',
        'mobile_no' => '',
        'email_id' => '',
        'signature' => '',
    ]);
}

$previousPayload = null;

if ($previousReport) {
    $items = [];

    $query = mysqli_query(
        $conn,
        "SELECT *
         FROM mas_details
         WHERE mas_main_id = " . (int)$previousReport['id'] . "
         ORDER BY sl_no, id"
    );

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $items[] = $row;
    }

    $previousPayload = [
        'mas_no' => $previousReport['mas_no'] ?? '',
        'meeting_date' => $previousReport['meeting_date'] ?? '',
        'meeting_title' => $previousReport['meeting_title'] ?? '',
        'meeting_time' => $previousReport['meeting_time'] ?? '',
        'meeting_venue' => $previousReport['meeting_venue'] ?? '',
        'conducted_by' => $previousReport['conducted_by'] ?? '',
        'architect' => $previousReport['architect'] ?? '',
        'pmc' => $previousReport['pmc'] ?? '',
        'remarks' => $previousReport['remarks'] ?? '',
        'items' => $items,
    ];
}

$recent = [];

$query = mysqli_query($conn, "
    SELECT
        m.id,
        m.mas_no,
        m.meeting_date,
        m.meeting_title,
        m.project_name,
        COUNT(d.id) AS attendee_count
    FROM mas_main m
    LEFT JOIN mas_details d ON d.mas_main_id = m.id
    WHERE m.created_by = $employeeId
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
    <title>MAS - TEK-C PMC Construction</title>

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

        .mas-table {
            min-width: 1300px;
        }

        .mas-table th {
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
                                ? 'Resubmit Meeting Attendance Sheet (MAS)'
                                : 'Meeting Attendance Sheet (MAS)' ?>
                        </h1>

                        <p class="text-muted-custom mb-0 small">
                            Record project meeting attendees and contact information.
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
                                        ? mas_e($previousPayload['mas_no'])
                                            . ' · '
                                            . mas_e(date(
                                                'd M Y',
                                                strtotime($previousPayload['meeting_date'])
                                            ))
                                        : 'No previous data' ?>
                                </small>
                            </span>
                        </label>

                        <span class="badge-soft">
                            <i data-lucide="user" style="width:15px"></i>
                            <?= mas_e($employeeName) ?>
                        </span>

                        <span class="badge-soft">
                            <?= mas_e($designationName) ?>
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
                                    <?= mas_e($projectOption['project_name']) ?>
                                    <?= !empty($projectOption['project_code'])
                                        ? ' (' . mas_e($projectOption['project_code']) . ')'
                                        : '' ?>
                                    - <?= mas_e($projectOption['project_location'] ?: '-') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3">
                        <a href="mas.php"
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
                            <div class="fw-bold"><?= mas_e($project['project_name']) ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Client</small>
                            <div class="fw-bold"><?= mas_e($project['client_name'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Location</small>
                            <div class="fw-bold"><?= mas_e($project['project_location'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Schedule</small>
                            <div class="fw-bold">
                                <?= mas_e($pmsSchedule['schedule_name'] ?? 'PMS Schedule') ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Start</small>
                            <div class="fw-bold"><?= mas_e($pmsStart ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS End</small>
                            <div class="fw-bold"><?= mas_e($pmsEnd ?: '-') ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <form method="POST" id="masForm">
                <input type="hidden" name="submit_mas" value="1">
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
                            <h2 class="fw-bold fs-6 mb-1">MAS Header</h2>
                            <p class="text-muted-custom small mb-0">
                                Meeting and project-party details.
                            </p>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">MAS No</label>
                            <input class="form-control rounded-4"
                                   name="mas_no"
                                   value="<?= mas_e($formData['mas_no']) ?>"
                                   required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Meeting Date</label>
                            <input type="date"
                                   class="form-control rounded-4"
                                   id="meeting_date"
                                   name="meeting_date"
                                   value="<?= mas_e($formData['meeting_date']) ?>"
                                   required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Meeting Time</label>
                            <input type="time"
                                   class="form-control rounded-4"
                                   id="meeting_time"
                                   name="meeting_time"
                                   value="<?= mas_e($formData['meeting_time']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Architect</label>
                            <input class="form-control rounded-4"
                                   id="architect"
                                   name="architect"
                                   placeholder="Enter architect name"
                                   value="<?= mas_e($formData['architect']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">PMC</label>
                            <input class="form-control rounded-4"
                                   id="pmc"
                                   name="pmc"
                                   placeholder="Enter PMC name"
                                   value="<?= mas_e($formData['pmc']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Meeting Title</label>
                            <input class="form-control rounded-4"
                                   id="meeting_title"
                                   name="meeting_title"
                                   placeholder="e.g. Weekly Review Meeting"
                                   value="<?= mas_e($formData['meeting_title']) ?>"
                                   required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Venue</label>
                            <input class="form-control rounded-4"
                                   id="meeting_venue"
                                   name="meeting_venue"
                                   placeholder="Conference room / site office / online"
                                   value="<?= mas_e($formData['meeting_venue']) ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold small">Conducted By</label>
                            <input class="form-control rounded-4"
                                   id="conducted_by"
                                   name="conducted_by"
                                   placeholder="Name of person conducting the meeting"
                                   value="<?= mas_e($formData['conducted_by']) ?>">
                        </div>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="mini-head mb-0">
                            <div class="mini-icon">
                                <i data-lucide="users"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">
                                    Meeting Attendees
                                </h2>

                                <p class="text-muted-custom small mb-0">
                                    Add all attendee contact and signature details.
                                </p>
                            </div>
                        </div>

                        <button type="button"
                                id="addRow"
                                class="btn btn-outline-primary rounded-4 fw-bold">
                            Add Attendee
                        </button>
                    </div>

                    <div class="table-responsive thin-scrollbar">
                        <table class="table table-bordered mas-table">
                            <thead>
                            <tr>
                                <th>SL</th>
                                <th>Attendee Name</th>
                                <th>Organization</th>
                                <th>Mobile No</th>
                                <th>Email ID</th>
                                <th>Signature / Initials</th>
                                <th>Del</th>
                            </tr>
                            </thead>

                            <tbody id="masBody"></tbody>
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
                                  placeholder="Enter meeting attendance remarks"><?= mas_e($formData['remarks']) ?></textarea>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="d-flex justify-content-end">
                        <button type="submit"
                                class="btn brand-gradient text-white rounded-4 fw-bold px-4"
                                <?= !$project ? 'disabled' : '' ?>>
                            <?= $resubmitSubmissionId > 0
                                ? 'Resubmit MAS'
                                : 'Submit MAS' ?>
                        </button>
                    </div>
                </div>
            </form>

            <section class="card-ui overflow-hidden">
                <div class="p-3 p-lg-4">
                    <h2 class="fw-bold fs-6 mb-1">Recent MAS</h2>
                    <p class="text-muted-custom small mb-0">
                        Your latest Meeting Attendance Sheet submissions.
                    </p>
                </div>

                <div class="px-3 px-lg-4 pb-4">
                    <div class="row g-2">
                        <?php foreach ($recent as $recentRow): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="recent-card">
                                    <div class="fw-bold">
                                        <?= mas_e($recentRow['mas_no']) ?>
                                    </div>

                                    <small class="text-muted-custom">
                                        <?= mas_e($recentRow['project_name']) ?>
                                        ·
                                        <?= mas_e(date(
                                            'd M Y',
                                            strtotime($recentRow['meeting_date'])
                                        )) ?>
                                        ·
                                        <?= (int)$recentRow['attendee_count'] ?> attendee(s)
                                    </small>

                                    <div class="small mt-1">
                                        <?= mas_e($recentRow['meeting_title']) ?>
                                    </div>

                                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2"
                                       target="_blank"
                                       href="reports-print/report-mas-print.php?view=<?= (int)$recentRow['id'] ?>">
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
<script src="assets/js/script.js?v=48"></script>

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

const masBody = document.getElementById('masBody');

const snapshot = {
    meeting_title: document.getElementById('meeting_title')?.value || '',
    meeting_time: document.getElementById('meeting_time')?.value || '',
    meeting_venue: document.getElementById('meeting_venue')?.value || '',
    conducted_by: document.getElementById('conducted_by')?.value || '',
    architect: document.getElementById('architect')?.value || '',
    pmc: document.getElementById('pmc')?.value || '',
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

function addRow(values = {}) {
    const row = document.createElement('tr');

    row.innerHTML = `
        <td class="sl text-center fw-bold"></td>

        <td>
            <input class="form-control rounded-4 attendee-name"
                   placeholder="Full name"
                   value="${escapeHtml(values.attendee_name || '')}">
        </td>

        <td>
            <input class="form-control rounded-4 organization"
                   placeholder="Company / organization"
                   value="${escapeHtml(values.organization || '')}">
        </td>

        <td>
            <input class="form-control rounded-4 mobile-no"
                   placeholder="Mobile number"
                   value="${escapeHtml(values.mobile_no || '')}">
        </td>

        <td>
            <input type="email"
                   class="form-control rounded-4 email-id"
                   placeholder="Email ID"
                   value="${escapeHtml(values.email_id || '')}">
        </td>

        <td>
            <input class="form-control rounded-4 signature"
                   placeholder="Signature / initials"
                   value="${escapeHtml(values.signature || '')}">
        </td>

        <td class="text-center">
            <button type="button"
                    class="btn btn-sm btn-outline-danger rounded-4 delete-row">
                <i data-lucide="trash-2"></i>
            </button>
        </td>
    `;

    masBody.appendChild(row);
    renumberRows();
}

function renumberRows() {
    [...masBody.rows].forEach((row, index) => {
        row.querySelector('.sl').textContent = index + 1;
    });

    if (window.lucide) {
        window.lucide.createIcons();
    }
}

function loadSource(source) {
    [
        'meeting_title',
        'meeting_time',
        'meeting_venue',
        'conducted_by',
        'architect',
        'pmc',
        'remarks'
    ].forEach(id => {
        const field = document.getElementById(id);

        if (field) {
            field.value = source?.[id] || '';
        }
    });

    masBody.innerHTML = '';

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

    if (masBody.rows.length <= 1) {
        row.querySelectorAll('input').forEach(input => {
            input.value = '';
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
            document.getElementById('meeting_date')?.value
            || '<?= mas_e($reportDate) ?>';

        window.location.href = this.value
            ? 'mas.php?project_id='
                + encodeURIComponent(this.value)
                + '&report_date='
                + encodeURIComponent(date)
            : 'mas.php';
    });

document.getElementById('masForm')
    ?.addEventListener('submit', function () {
        const items = [...masBody.rows].map((row, index) => ({
            sl_no: index + 1,
            attendee_name:
                row.querySelector('.attendee-name').value,
            organization:
                row.querySelector('.organization').value,
            mobile_no:
                row.querySelector('.mobile-no').value,
            email_id:
                row.querySelector('.email-id').value,
            signature:
                row.querySelector('.signature').value
        }));

        document.getElementById('items_json').value =
            JSON.stringify(items);
    });
</script>
</body>
</html>
