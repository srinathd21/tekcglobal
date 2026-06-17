<?php
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

function chk_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function chk_employee_id(mysqli $conn): int
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

function chk_is_super_admin(mysqli $conn): bool
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
          AND r.role_slug = 'super-admin'
        LIMIT 1
    ");

    return $query && mysqli_num_rows($query) > 0;
}

function chk_report_access(mysqli $conn, string $permission): bool
{
    if (chk_is_super_admin($conn)) {
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
          AND rt.report_code = 'CHK'
          AND rt.is_active = 1
    ");

    return $query
        && ($row = mysqli_fetch_assoc($query))
        && (int)($row['allowed'] ?? 0) === 1;
}

function chk_project_allowed(
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

function chk_columns(mysqli $conn, string $table): array
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $columns = [];

    $query = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $columns[$row['Field']] = true;
    }

    return $columns;
}

function chk_sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function chk_report_type_id(mysqli $conn): int
{
    $query = mysqli_query($conn, "
        SELECT id
        FROM master_report_types
        WHERE report_code = 'CHK'
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function chk_find_existing(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate
): int {
    if ($submissionId > 0) {
        $columns = chk_columns($conn, 'project_report_submissions');
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
                $candidate = (int)($submission[$column] ?? 0);

                if ($candidate <= 0) {
                    continue;
                }

                $check = mysqli_query(
                    $conn,
                    "SELECT id FROM checklist_reports WHERE id = $candidate LIMIT 1"
                );

                if ($check && mysqli_num_rows($check) > 0) {
                    return $candidate;
                }
            }
        }
    }

    $dateEsc = mysqli_real_escape_string($conn, $reportDate);

    $query = mysqli_query($conn, "
        SELECT id
        FROM checklist_reports
        WHERE project_id = $projectId
          AND employee_id = $employeeId
          AND checklist_date = '$dateEsc'
        ORDER BY id DESC
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function chk_sync_submission(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate,
    int $checklistId,
    string $docNo
): void {
    $reportTypeId = chk_report_type_id($conn);

    if ($reportTypeId <= 0 || $checklistId <= 0) {
        return;
    }

    $columns = chk_columns($conn, 'project_report_submissions');
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $map = [
        'project_id' => $projectId,
        'report_type_id' => $reportTypeId,
        'report_no' => $docNo,
        'title' => 'Project Engineer Daily Activity Checklist',
        'submitted_by_employee_id' => $employeeId,
        'submitted_by_user_id' => $userId > 0 ? $userId : null,
        'submission_for_date' => $reportDate,
        'period_start' => $reportDate,
        'period_end' => $reportDate,
        'status' => 'submitted',
        'current_review_level' => 'tl',
        'submitted_at' => date('Y-m-d H:i:s'),
        'source_table' => 'checklist_reports',
        'source_id' => $checklistId,
        'report_reference_table' => 'checklist_reports',
        'report_reference_id' => $checklistId,
        'reference_id' => $checklistId,
        'updated_by' => $userId > 0 ? $userId : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($submissionId > 0) {
        $sets = [];

        foreach ($map as $field => $value) {
            if (isset($columns[$field])) {
                $sets[] = "`$field` = " . chk_sql_value($conn, $value);
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
        'created_by' => $userId > 0 ? $userId : null,
        'created_at' => date('Y-m-d H:i:s'),
    ], $map);

    $insertColumns = [];
    $insertValues = [];

    foreach ($data as $field => $value) {
        if (isset($columns[$field])) {
            $insertColumns[] = "`$field`";
            $insertValues[] = chk_sql_value($conn, $value);
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

$checklistSections = [
    'Daily Responsibilities' => [
        'report_time' => 'Report on time and mark attendance.',
        'review_program' => 'Review daily work program against baseline schedule.',
        'check_manpower' => 'Check manpower, plant and machinery availability.',
        'fill_dpr' => 'Complete the Daily Progress Report.',
        'site_photos' => 'Take and submit current site photographs.',
    ],
    'Quality Control' => [
        'gfc_check' => 'Check execution against GFC drawings and specifications.',
        'verify_levels' => 'Verify line, level, plumb and dimensions.',
        'material_approval' => 'Confirm material approvals before use.',
        'work_checklists' => 'Complete activity checklists before execution.',
        'raise_ncr' => 'Raise NCR for observed deviations.',
    ],
    'Coordination' => [
        'attend_huddles' => 'Attend daily huddles and scheduled review meetings.',
        'coordinate_contractors' => 'Coordinate contractors and vendors for work sequence.',
        'clarifications_pmc' => 'Route technical clarifications through the PMC lead.',
    ],
    'Safety & Compliance' => [
        'ensure_ppe' => 'Ensure proper PPE usage.',
        'check_scaffolding' => 'Check scaffolding, barricades and safety signage.',
        'stop_unsafe' => 'Stop unsafe work practices immediately.',
    ],
    'Documentation' => [
        'maintain_registers' => 'Maintain DPR, material and inspection registers.',
        'update_drawing_tracker' => 'Update the drawing tracker with latest revisions.',
        'weekly_client_report' => 'Support weekly client report preparation.',
    ],
    'Escalation & Communication' => [
        'report_delays' => 'Report delays, design issues and vendor shortfalls.',
        'raise_procurement' => 'Raise procurement needs 7–10 days in advance.',
    ],
    'Weekly/Monthly Duties' => [
        'progress_reviews' => 'Assist in weekly progress reviews.',
        'quantity_tracker' => 'Update quantity tracker and verify contractor bills.',
        'delay_audits' => 'Support delay analysis and project audits.',
    ],
    'Professional Conduct' => [
        'no_commitments' => 'Avoid direct commercial commitments to clients or contractors.',
        'professionalism' => 'Maintain professionalism, integrity and confidentiality.',
    ],
];

$employeeId = chk_employee_id($conn);
$isSuperAdmin = chk_is_super_admin($conn);

if ($employeeId <= 0) {
    header('Location: login.php');
    exit;
}

if (!chk_report_access($conn, 'can_submit')) {
    header(
        'Location: reports-hub.php?error='
        . urlencode('You do not have Checklist submit access.')
    );
    exit;
}

$employeeQuery = mysqli_query($conn, "
    SELECT e.*, r.role_name AS designation_name
    FROM employees e
    LEFT JOIN roles r ON r.id = e.role_id
    WHERE e.id = $employeeId
    LIMIT 1
");

$employee = $employeeQuery ? mysqli_fetch_assoc($employeeQuery) : null;

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
    ?? $_POST['checklist_date']
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
    && chk_project_allowed(
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
            tl.full_name AS team_lead_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN employees tl ON tl.id = p.team_lead_employee_id
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

$defaultDocNo = '';

if ($projectId > 0) {
    $prefix = 'CHK/' . $projectId . '/' . date('Ym', strtotime($reportDate)) . '/';
    $prefixEsc = mysqli_real_escape_string($conn, $prefix);

    $query = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM checklist_reports
         WHERE doc_no LIKE '$prefixEsc%'"
    );

    $count = $query
        ? (int)(mysqli_fetch_assoc($query)['total'] ?? 0)
        : 0;

    $defaultDocNo = $prefix
        . str_pad((string)($count + 1), 3, '0', STR_PAD_LEFT);
}

$existingReport = null;

if ($resubmitSubmissionId > 0 && $projectId > 0) {
    $existingId = chk_find_existing(
        $conn,
        $resubmitSubmissionId,
        $projectId,
        $employeeId,
        $reportDate
    );

    if ($existingId > 0) {
        $query = mysqli_query(
            $conn,
            "SELECT * FROM checklist_reports WHERE id = $existingId LIMIT 1"
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
        "project_id = $projectId AND employee_id = $employeeId AND checklist_date < '$dateEsc'",
        "project_id = $projectId AND employee_id = $employeeId",
        "project_id = $projectId"
    ] as $where) {
        $query = mysqli_query($conn, "
            SELECT *
            FROM checklist_reports
            WHERE $where
            ORDER BY checklist_date DESC, created_at DESC, id DESC
            LIMIT 1
        ");

        if ($query && ($previousReport = mysqli_fetch_assoc($query))) {
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_checklist'])) {
    $postProjectId = (int)($_POST['project_id'] ?? 0);
    $postSubmissionId = (int)($_POST['resubmit_submission_id'] ?? 0);

    $docNo = trim((string)($_POST['doc_no'] ?? ''));
    $checklistDate = trim((string)($_POST['checklist_date'] ?? ''));
    $projectEngineer = trim((string)($_POST['project_engineer'] ?? ''));
    $pmcLead = trim((string)($_POST['pmc_lead'] ?? ''));
    $checked = $_POST['chk'] ?? [];

    $error = '';

    if (
        !chk_project_allowed(
            $conn,
            $postProjectId,
            $employeeId,
            $isSuperAdmin
        )
    ) {
        $error = 'Invalid project selection.';
    } elseif ($docNo === '') {
        $error = 'Document number is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checklistDate)) {
        $error = 'Valid checklist date is required.';
    } elseif ($projectEngineer === '') {
        $error = 'Project Engineer name is required.';
    } elseif ($pmcLead === '') {
        $error = 'PMC Lead name is required.';
    }

    $checklistData = [];
    $checkedCount = 0;

    foreach ($checklistSections as $sectionName => $items) {
        foreach ($items as $key => $label) {
            $isChecked = in_array($key, $checked, true) ? 1 : 0;

            if ($isChecked === 1) {
                $checkedCount++;
            }

            $checklistData[] = [
                'section' => $sectionName,
                'key' => $key,
                'label' => $label,
                'checked' => $isChecked,
            ];
        }
    }

    if ($error === '' && $checkedCount <= 0) {
        $error = 'Please select at least one checklist item.';
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

        $projectData = $query ? mysqli_fetch_assoc($query) : null;

        $projectName = (string)($projectData['project_name'] ?? '');
        $clientId = (int)($projectData['client_id'] ?? 0);
        $clientName = (string)($projectData['client_name'] ?? '');
        $checklistJson = json_encode(
            $checklistData,
            JSON_UNESCAPED_UNICODE
        );

        mysqli_begin_transaction($conn);

        try {
            if ($postSubmissionId > 0) {
                $checklistId = chk_find_existing(
                    $conn,
                    $postSubmissionId,
                    $postProjectId,
                    $employeeId,
                    $checklistDate
                );

                if ($checklistId <= 0) {
                    throw new RuntimeException(
                        'Original checklist not found for resubmission.'
                    );
                }

                $stmt = mysqli_prepare($conn, "
                    UPDATE checklist_reports
                    SET
                        doc_no = ?,
                        project_id = ?,
                        client_id = ?,
                        project_name = ?,
                        client_name = ?,
                        checklist_date = ?,
                        checklist_json = ?,
                        project_engineer = ?,
                        pmc_lead = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                if (!$stmt) {
                    throw new RuntimeException(mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'siissssssi',
                    $docNo,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $checklistDate,
                    $checklistJson,
                    $projectEngineer,
                    $pmcLead,
                    $checklistId
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                mysqli_stmt_close($stmt);
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO checklist_reports
                    (
                        project_id,
                        client_id,
                        employee_id,
                        doc_no,
                        checklist_date,
                        project_name,
                        client_name,
                        checklist_json,
                        project_engineer,
                        pmc_lead
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt) {
                    throw new RuntimeException(mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'iiisssssss',
                    $postProjectId,
                    $clientId,
                    $employeeId,
                    $docNo,
                    $checklistDate,
                    $projectName,
                    $clientName,
                    $checklistJson,
                    $projectEngineer,
                    $pmcLead
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                $checklistId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }

            chk_sync_submission(
                $conn,
                $postSubmissionId,
                $postProjectId,
                $employeeId,
                $checklistDate,
                $checklistId,
                $docNo
            );

            mysqli_commit($conn);

            header(
                'Location: reports-hub.php'
                . '?project_id=' . $postProjectId
                . '&report_date=' . urlencode($checklistDate)
                . '&' . ($postSubmissionId > 0 ? 'resubmitted' : 'saved')
                . '=1'
            );

            exit;
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            $error = $exception->getMessage();
        }
    }

    if ($error !== '') {
        header(
            'Location: checklist.php'
            . '?project_id=' . $postProjectId
            . '&report_date=' . urlencode($checklistDate)
            . '&error=' . urlencode($error)
        );
        exit;
    }
}

function chk_decode_items($json): array
{
    $items = json_decode((string)$json, true);
    return is_array($items) ? $items : [];
}

$formData = [
    'doc_no' => $existingReport['doc_no'] ?? $defaultDocNo,
    'checklist_date' => $existingReport['checklist_date'] ?? $reportDate,
    'project_engineer' => $existingReport['project_engineer'] ?? $employeeName,
    'pmc_lead' => $existingReport['pmc_lead'] ?? ($project['team_lead_name'] ?? ''),
    'checked' => [],
];

foreach (chk_decode_items($existingReport['checklist_json'] ?? '') as $item) {
    if ((int)($item['checked'] ?? 0) === 1) {
        $formData['checked'][] = (string)($item['key'] ?? '');
    }
}

$previousPayload = null;

if ($previousReport) {
    $previousChecked = [];

    foreach (chk_decode_items($previousReport['checklist_json'] ?? '') as $item) {
        if ((int)($item['checked'] ?? 0) === 1) {
            $previousChecked[] = (string)($item['key'] ?? '');
        }
    }

    $previousPayload = [
        'project_engineer' => $previousReport['project_engineer'] ?? '',
        'pmc_lead' => $previousReport['pmc_lead'] ?? '',
        'checked' => $previousChecked,
    ];
}

$recent = [];

$query = mysqli_query($conn, "
    SELECT
        id,
        doc_no,
        checklist_date,
        project_name
    FROM checklist_reports
    WHERE employee_id = $employeeId
    ORDER BY created_at DESC
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
    <title>Project Engineer Checklist - TEK-C</title>

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
        flex: 0 0 auto;
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

    .check-item {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 13px 14px;
        background: rgba(148, 163, 184, .04);
        cursor: pointer;
    }

    .check-item:hover {
        background: rgba(37, 99, 235, .06);
    }

    .check-item input {
        width: 21px;
        height: 21px;
        margin-top: 1px;
        accent-color: #2563eb;
        flex: 0 0 auto;
    }

    .check-item-title {
        font-weight: 800;
        line-height: 1.45;
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
                                ? 'Resubmit Project Engineer Checklist'
                                : 'Project Engineer Daily Activity Checklist' ?>
                            </h1>

                            <p class="text-muted-custom mb-0 small">
                                Complete the applicable daily responsibilities and submit for review.
                            </p>
                        </div>

                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <label class="badge-soft mb-0 <?= !$previousPayload ? 'opacity-75' : '' ?>">
                                <input type="checkbox" id="loadPrevious" <?= !$previousPayload ? 'disabled' : '' ?>>

                                <span>
                                    <strong>Load previous data</strong>
                                    <small class="d-block text-muted-custom">
                                        <?= $previousReport
                                        ? chk_e($previousReport['doc_no'])
                                            . ' · '
                                            . chk_e(date(
                                                'd M Y',
                                                strtotime($previousReport['checklist_date'])
                                            ))
                                        : 'No previous data' ?>
                                    </small>
                                </span>
                            </label>

                            <span class="badge-soft">
                                <i data-lucide="user" style="width:15px"></i>
                                <?= chk_e($employeeName) ?>
                            </span>

                            <span class="badge-soft">
                                <?= chk_e($designationName) ?>
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
                                Choose one of your assigned projects.
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
                                    <?= chk_e($projectOption['project_name']) ?>
                                    <?= !empty($projectOption['project_code'])
                                        ? ' (' . chk_e($projectOption['project_code']) . ')'
                                        : '' ?>
                                    - <?= chk_e($projectOption['project_location'] ?: '-') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-3">
                            <a href="checklist.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">
                                Reset
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($project): ?>
                <div class="section-box mb-3">
                    <div class="mini-head">
                        <div class="mini-icon">
                            <i data-lucide="building-2"></i>
                        </div>

                        <div>
                            <h2 class="fw-bold fs-6 mb-1">Project Details</h2>
                            <p class="text-muted-custom small mb-0">
                                Current project, client and PMS schedule.
                            </p>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Project</small>
                            <div class="fw-bold"><?= chk_e($project['project_name']) ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Client</small>
                            <div class="fw-bold"><?= chk_e($project['client_name'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Location</small>
                            <div class="fw-bold"><?= chk_e($project['project_location'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Schedule</small>
                            <div class="fw-bold">
                                <?= chk_e($pmsSchedule['schedule_name'] ?? 'PMS Schedule') ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Start</small>
                            <div class="fw-bold"><?= chk_e($pmsStart ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS End</small>
                            <div class="fw-bold"><?= chk_e($pmsEnd ?: '-') ?></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" id="checklistForm">
                    <input type="hidden" name="submit_checklist" value="1">
                    <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
                    <input type="hidden" name="resubmit_submission_id" value="<?= (int)$resubmitSubmissionId ?>">

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="clipboard-check"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Checklist Header</h2>
                                <p class="text-muted-custom small mb-0">
                                    Document number and checklist date.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Document No.</label>
                                <input class="form-control rounded-4" name="doc_no"
                                    value="<?= chk_e($formData['doc_no']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Checklist Date</label>
                                <input type="date" class="form-control rounded-4" id="checklist_date"
                                    name="checklist_date" value="<?= chk_e($formData['checklist_date']) ?>" required>
                            </div>
                        </div>
                    </div>

                    <?php foreach ($checklistSections as $sectionName => $items): ?>
                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="check-square-2"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">
                                    <?= chk_e($sectionName) ?>
                                </h2>

                                <p class="text-muted-custom small mb-0">
                                    Select the items completed or verified today.
                                </p>
                            </div>
                        </div>

                        <div class="row g-2">
                            <?php foreach ($items as $key => $label): ?>
                            <div class="col-12 col-xl-6">
                                <label class="check-item">
                                    <input type="checkbox" name="chk[]" value="<?= chk_e($key) ?>" <?= in_array(
                                                   $key,
                                                   $formData['checked'],
                                                   true
                                               ) ? 'checked' : '' ?>>

                                    <span class="check-item-title">
                                        <?= chk_e($label) ?>
                                    </span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="pen-line"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Sign-off</h2>
                                <p class="text-muted-custom small mb-0">
                                    Project Engineer and PMC Lead details.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">
                                    Project Engineer
                                </label>

                                <input class="form-control rounded-4" id="project_engineer" name="project_engineer"
                                    value="<?= chk_e($formData['project_engineer']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">
                                    PMC Lead
                                </label>

                                <input class="form-control rounded-4" id="pmc_lead" name="pmc_lead"
                                    value="<?= chk_e($formData['pmc_lead']) ?>" required>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4"
                                <?= !$project ? 'disabled' : '' ?>>
                                <?= $resubmitSubmissionId > 0
                                ? 'Resubmit Checklist'
                                : 'Submit Checklist' ?>
                            </button>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4">
                        <h2 class="fw-bold fs-6 mb-1">Recent Checklists</h2>
                        <p class="text-muted-custom small mb-0">
                            Your latest checklist submissions.
                        </p>
                    </div>

                    <div class="px-3 px-lg-4 pb-4">
                        <div class="row g-2">
                            <?php foreach ($recent as $recentRow): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="recent-card">
                                    <div class="fw-bold">
                                        <?= chk_e($recentRow['doc_no']) ?>
                                    </div>

                                    <small class="text-muted-custom">
                                        <?= chk_e($recentRow['project_name']) ?>
                                        ·
                                        <?= chk_e(date(
                                            'd M Y',
                                            strtotime($recentRow['checklist_date'])
                                        )) ?>
                                    </small>

                                    <br>

                                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2" target="_blank"
                                        href="reports-print/report-checklist-print.php?view=<?= (int)$recentRow['id'] ?>">
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
    <script src="assets/js/script.js?v=53"></script>

    <script>
    const previousData = <?= json_encode(
    $previousPayload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

    const currentSnapshot = {
        project_engineer: document.getElementById('project_engineer')?.value || '',
        pmc_lead: document.getElementById('pmc_lead')?.value || '',
        checked: [...document.querySelectorAll('input[name="chk[]"]:checked')]
            .map(input => input.value)
    };

    function applyChecklistSource(source) {
        document.getElementById('project_engineer').value =
            source?.project_engineer || '';

        document.getElementById('pmc_lead').value =
            source?.pmc_lead || '';

        const checked = new Set(source?.checked || []);

        document.querySelectorAll('input[name="chk[]"]').forEach(input => {
            input.checked = checked.has(input.value);
        });
    }

    document.getElementById('loadPrevious')
        ?.addEventListener('change', function() {
            applyChecklistSource(
                this.checked ?
                previousData :
                currentSnapshot
            );
        });

    document.getElementById('projectPicker')
        ?.addEventListener('change', function() {
            const date =
                document.getElementById('checklist_date')?.value ||
                '<?= chk_e($reportDate) ?>';

            window.location.href = this.value ?
                'checklist.php?project_id=' +
                encodeURIComponent(this.value) +
                '&report_date=' +
                encodeURIComponent(date) :
                'checklist.php';
        });
    </script>
</body>

</html>