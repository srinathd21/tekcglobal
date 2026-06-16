<?php
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

function rfi_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rfi_employee_id(mysqli $conn): int
{
    if (!empty($_SESSION['employee_id'])) {
        return (int)$_SESSION['employee_id'];
    }

    if (!empty($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];

        $query = mysqli_query(
            $conn,
            "SELECT employee_id
             FROM users
             WHERE id = $userId
             LIMIT 1"
        );

        if (
            $query
            && ($row = mysqli_fetch_assoc($query))
            && !empty($row['employee_id'])
        ) {
            $_SESSION['employee_id'] = (int)$row['employee_id'];
            return (int)$row['employee_id'];
        }
    }

    return 0;
}

function rfi_is_super_admin(mysqli $conn): bool
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

function rfi_report_access(mysqli $conn, string $permission): bool
{
    if (rfi_is_super_admin($conn)) {
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
          AND rt.report_code = 'RFI'
          AND rt.is_active = 1
    ");

    return $query
        && ($row = mysqli_fetch_assoc($query))
        && (int)($row['allowed'] ?? 0) === 1;
}

function rfi_project_allowed(
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

function rfi_table_columns(mysqli $conn, string $table): array
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columns = [];

    $query = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $columns[$row['Field']] = true;
    }

    return $columns;
}

function rfi_sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function rfi_report_type_id(mysqli $conn): int
{
    $query = mysqli_query($conn, "
        SELECT id
        FROM master_report_types
        WHERE report_code = 'RFI'
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function rfi_existing_for_resubmit(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $issueDate
): int {
    if ($submissionId > 0) {
        $columns = rfi_table_columns($conn, 'project_report_submissions');
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

                $recordQuery = mysqli_query(
                    $conn,
                    "SELECT id
                     FROM rfi_reports
                     WHERE id = $candidateId
                     LIMIT 1"
                );

                if ($recordQuery && mysqli_num_rows($recordQuery) > 0) {
                    return $candidateId;
                }
            }
        }
    }

    $dateEsc = mysqli_real_escape_string($conn, $issueDate);

    $query = mysqli_query($conn, "
        SELECT id
        FROM rfi_reports
        WHERE project_id = $projectId
          AND employee_id = $employeeId
          AND issue_date = '$dateEsc'
        ORDER BY id DESC
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function rfi_sync_submission(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $issueDate,
    int $rfiId,
    string $rfiNo,
    string $subject
): void {
    $reportTypeId = rfi_report_type_id($conn);

    if ($reportTypeId <= 0 || $projectId <= 0 || $employeeId <= 0 || $rfiId <= 0) {
        return;
    }

    $columns = rfi_table_columns($conn, 'project_report_submissions');
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $dateEsc = mysqli_real_escape_string($conn, $issueDate);

    if ($submissionId <= 0) {
        $query = mysqli_query($conn, "
            SELECT id
            FROM project_report_submissions
            WHERE project_id = $projectId
              AND report_type_id = $reportTypeId
              AND submission_for_date = '$dateEsc'
              AND (
                    report_reference_id = $rfiId
                 OR source_id = $rfiId
                 OR reference_id = $rfiId
                 OR report_no = '" . mysqli_real_escape_string($conn, $rfiNo) . "'
              )
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($query && ($row = mysqli_fetch_assoc($query))) {
            $submissionId = (int)$row['id'];
        }
    }

    $map = [
        'report_no' => $rfiNo,
        'report_number' => $rfiNo,
        'submission_no' => $rfiNo,
        'title' => $subject !== '' ? $subject : 'Request For Information',
        'submitted_by_employee_id' => $employeeId,
        'submitted_by_user_id' => $userId > 0 ? $userId : null,
        'submission_for_date' => $issueDate,
        'period_start' => $issueDate,
        'period_end' => $issueDate,
        'status' => 'submitted',
        'submitted_at' => date('Y-m-d H:i:s'),
        'source_table' => 'rfi_reports',
        'source_id' => $rfiId,
        'report_reference_table' => 'rfi_reports',
        'report_reference_id' => $rfiId,
        'reference_id' => $rfiId,
        'updated_by' => $userId > 0 ? $userId : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($submissionId > 0) {
        $sets = [];

        foreach ($map as $field => $value) {
            if (isset($columns[$field])) {
                $sets[] = "`$field` = " . rfi_sql_value($conn, $value);
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
            $insertValues[] = rfi_sql_value($conn, $value);
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

function rfi_redirect_hub(
    int $projectId,
    string $issueDate,
    string $flag
): void {
    $flag = preg_replace('/[^a-zA-Z0-9_]/', '', $flag);

    header(
        'Location: reports-hub.php'
        . '?project_id=' . $projectId
        . '&report_date=' . urlencode($issueDate)
        . '&period_start=' . urlencode($issueDate)
        . '&period_end=' . urlencode($issueDate)
        . '&' . $flag . '=1'
    );

    exit;
}

$employeeId = rfi_employee_id($conn);
$isSuperAdmin = rfi_is_super_admin($conn);

if ($employeeId <= 0) {
    header('Location: login.php');
    exit;
}

if (!rfi_report_access($conn, 'can_submit')) {
    header(
        'Location: reports-hub.php?error='
        . urlencode('You do not have RFI submit access.')
    );
    exit;
}

$employeeQuery = mysqli_query($conn, "
    SELECT
        e.*,
        designation_role.role_name AS designation_name
    FROM employees e
    LEFT JOIN roles designation_role
        ON designation_role.id = e.role_id
    WHERE e.id = $employeeId
    LIMIT 1
");

$employee = $employeeQuery
    ? mysqli_fetch_assoc($employeeQuery)
    : null;

$preparedBy = (string)(
    $employee['full_name']
    ?? $_SESSION['employee_name']
    ?? $_SESSION['name']
    ?? ''
);

$preparedDesignation = (string)(
    $employee['designation_name']
    ?? $_SESSION['designation']
    ?? ''
);

$projectId = (int)(
    $_GET['project_id']
    ?? $_POST['project_id']
    ?? 0
);

$issueDate = trim((string)(
    $_GET['report_date']
    ?? $_POST['issue_date']
    ?? date('Y-m-d')
));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
    $issueDate = date('Y-m-d');
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

while ($projectsQuery && ($row = mysqli_fetch_assoc($projectsQuery))) {
    $projects[] = $row;
}

$project = null;

if (
    $projectId > 0
    && rfi_project_allowed(
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
            c.state,
            mpt.project_type_name,
            manager.full_name AS manager_name,
            manager.email AS manager_email,
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

$defaultDistribute = '';

if ($project) {
    $parts = [];

    if (!empty($project['client_name'])) {
        $parts[] = 'Client - ' . $project['client_name'];
    }

    if (!empty($project['manager_email'])) {
        $parts[] = $project['manager_email'];
    } elseif (!empty($project['manager_name'])) {
        $parts[] = $project['manager_name'];
    }

    /*
     * Current schema: employees.role_id stores the designation role.
     */
    $directorQuery = mysqli_query($conn, "
        SELECT
            e.full_name,
            e.email,
            designation_role.role_name AS designation_name
        FROM employees e
        INNER JOIN roles designation_role
            ON designation_role.id = e.role_id
        WHERE e.employee_status = 'active'
          AND LOWER(TRIM(designation_role.role_name)) IN (
              'director',
              'vice president',
              'general manager'
          )
        ORDER BY
            FIELD(
                LOWER(TRIM(designation_role.role_name)),
                'director',
                'vice president',
                'general manager'
            ),
            e.full_name ASC
    ");

    while ($directorQuery && ($director = mysqli_fetch_assoc($directorQuery))) {
        $label = trim((string)($director['email'] ?? ''));

        if ($label === '') {
            $label = trim((string)($director['full_name'] ?? ''));
        }

        if ($label !== '') {
            $parts[] = $label;
        }
    }

    $defaultDistribute = implode(
        ', ',
        array_values(array_unique(array_filter($parts)))
    );
}

$defaultRfiNo = '';

if ($projectId > 0) {
    $prefix = 'RFI/' . $projectId . '/' . date('Ym', strtotime($issueDate)) . '/';
    $prefixEsc = mysqli_real_escape_string($conn, $prefix);

    $countQuery = mysqli_query($conn, "
        SELECT COUNT(*) AS total
        FROM rfi_reports
        WHERE rfi_no LIKE '$prefixEsc%'
    ");

    $count = $countQuery
        ? (int)(mysqli_fetch_assoc($countQuery)['total'] ?? 0)
        : 0;

    $defaultRfiNo = $prefix
        . str_pad((string)($count + 1), 3, '0', STR_PAD_LEFT);
}

$existingReport = null;

if ($resubmitSubmissionId > 0 && $projectId > 0) {
    $existingId = rfi_existing_for_resubmit(
        $conn,
        $resubmitSubmissionId,
        $projectId,
        $employeeId,
        $issueDate
    );

    if ($existingId > 0) {
        $existingQuery = mysqli_query(
            $conn,
            "SELECT *
             FROM rfi_reports
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
    $safeDate = mysqli_real_escape_string($conn, $issueDate);

    $queries = [
        "
        SELECT *
        FROM rfi_reports
        WHERE project_id = $projectId
          AND employee_id = $employeeId
          AND issue_date < '$safeDate'
        ORDER BY issue_date DESC, created_at DESC, id DESC
        LIMIT 1
        ",
        "
        SELECT *
        FROM rfi_reports
        WHERE project_id = $projectId
          AND employee_id = $employeeId
        ORDER BY issue_date DESC, created_at DESC, id DESC
        LIMIT 1
        ",
        "
        SELECT *
        FROM rfi_reports
        WHERE project_id = $projectId
        ORDER BY issue_date DESC, created_at DESC, id DESC
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rfi'])) {
    $postProjectId = (int)($_POST['project_id'] ?? 0);
    $postSubmissionId = (int)($_POST['resubmit_submission_id'] ?? 0);

    $rfiNo = trim((string)($_POST['rfi_no'] ?? ''));
    $postIssueDate = trim((string)($_POST['issue_date'] ?? ''));
    $requiredResponseDate = trim((string)($_POST['required_response_date'] ?? ''));

    $architectConsultant = trim((string)($_POST['architect_consultant'] ?? ''));
    $contractor = trim((string)($_POST['contractor'] ?? ''));
    $raisedByName = trim((string)($_POST['raised_by_name'] ?? ''));
    $raisedByDesignation = trim((string)($_POST['raised_by_designation'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));

    $drawingNo = trim((string)($_POST['drawing_no'] ?? ''));
    $drawingDate = trim((string)($_POST['drawing_date'] ?? ''));
    $specificationClause = trim((string)($_POST['specification_clause'] ?? ''));
    $boqItemRef = trim((string)($_POST['boq_item_ref'] ?? ''));
    $siteInstructionRef = trim((string)($_POST['site_instruction_ref'] ?? ''));

    $queryDescription = trim((string)($_POST['query_description'] ?? ''));

    $attachments = [
        'site_photograph' => !empty($_POST['att_site_photograph']) ? 1 : 0,
        'markedup_drawing' => !empty($_POST['att_markedup_drawing']) ? 1 : 0,
        'sketch' => !empty($_POST['att_sketch']) ? 1 : 0,
        'calculation_sheet' => !empty($_POST['att_calculation_sheet']) ? 1 : 0,
    ];

    $impacts = [
        'work_stoppage' => !empty($_POST['impact_work_stoppage']) ? 1 : 0,
        'delay_in_schedule' => !empty($_POST['impact_delay_schedule']) ? 1 : 0,
        'cost_impact' => !empty($_POST['impact_cost']) ? 1 : 0,
        'quality_risk' => !empty($_POST['impact_quality']) ? 1 : 0,
        'safety_risk' => !empty($_POST['impact_safety']) ? 1 : 0,
    ];

    $impactBrief = trim((string)($_POST['impact_brief'] ?? ''));
    $proposedSolution = trim((string)($_POST['proposed_solution'] ?? ''));

    $responseDate = trim((string)($_POST['response_date'] ?? ''));
    $responseBy = trim((string)($_POST['response_by'] ?? ''));
    $responseDecision = trim((string)($_POST['response_decision'] ?? ''));
    $responseRemarks = trim((string)($_POST['response_remarks'] ?? ''));

    $reportDistributeTo = trim((string)($_POST['report_distribute_to'] ?? ''));
    $preparedByPost = trim((string)($_POST['prepared_by'] ?? $preparedBy));

    $error = '';

    if (
        !rfi_project_allowed(
            $conn,
            $postProjectId,
            $employeeId,
            $isSuperAdmin
        )
    ) {
        $error = 'Invalid project selection.';
    } elseif ($rfiNo === '') {
        $error = 'RFI No is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postIssueDate)) {
        $error = 'Valid issue date is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requiredResponseDate)) {
        $error = 'Valid required response date is required.';
    } elseif (strtotime($requiredResponseDate) < strtotime($postIssueDate)) {
        $error = 'Required response date cannot be earlier than issue date.';
    } elseif ($raisedByName === '') {
        $error = 'Raised By name is required.';
    } elseif ($raisedByDesignation === '') {
        $error = 'Raised By designation is required.';
    } elseif ($subject === '') {
        $error = 'Subject is required.';
    } elseif ($queryDescription === '') {
        $error = 'Description of query is required.';
    } elseif ($reportDistributeTo === '') {
        $error = 'Report distribution is required.';
    }

    if ($error === '') {
        $projectQuery = mysqli_query($conn, "
            SELECT
                p.project_name,
                p.project_location,
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
        $projectLocation = (string)($projectData['project_location'] ?? '');
        $clientId = (int)($projectData['client_id'] ?? 0);
        $clientName = (string)($projectData['client_name'] ?? '');

        $attachmentsJson = json_encode(
            $attachments,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $impactsJson = json_encode(
            $impacts,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $status = $responseDecision !== ''
            ? 'Responded'
            : 'Open';

        mysqli_begin_transaction($conn);

        try {
            if ($postSubmissionId > 0) {
                $rfiId = rfi_existing_for_resubmit(
                    $conn,
                    $postSubmissionId,
                    $postProjectId,
                    $employeeId,
                    $postIssueDate
                );

                if ($rfiId <= 0) {
                    throw new RuntimeException(
                        'Original RFI not found for resubmission.'
                    );
                }

                $stmt = mysqli_prepare($conn, "
                    UPDATE rfi_reports
                    SET rfi_no = ?,
                        project_id = ?,
                        site_id = ?,
                        client_id = ?,
                        project_name = ?,
                        project_location = ?,
                        client_name = ?,
                        issue_date = ?,
                        required_response_date = ?,
                        subject = ?,
                        architect_consultant = ?,
                        contractor = ?,
                        raised_by_name = ?,
                        raised_by_designation = ?,
                        drawing_no = ?,
                        drawing_date = NULLIF(?, ''),
                        specification_clause = ?,
                        boq_item_ref = ?,
                        site_instruction_ref = ?,
                        query_description = ?,
                        attachments_json = ?,
                        impacts_json = ?,
                        impact_brief = ?,
                        proposed_solution = ?,
                        response_date = NULLIF(?, ''),
                        response_by = ?,
                        response_decision = ?,
                        response_remarks = ?,
                        report_distribute_to = ?,
                        prepared_by = ?,
                        status = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                if (!$stmt) {
                    throw new RuntimeException(
                        'RFI update SQL error: ' . mysqli_error($conn)
                    );
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'siiisssssssssssssssssssssssssssi',
                    $rfiNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $projectLocation,
                    $clientName,
                    $postIssueDate,
                    $requiredResponseDate,
                    $subject,
                    $architectConsultant,
                    $contractor,
                    $raisedByName,
                    $raisedByDesignation,
                    $drawingNo,
                    $drawingDate,
                    $specificationClause,
                    $boqItemRef,
                    $siteInstructionRef,
                    $queryDescription,
                    $attachmentsJson,
                    $impactsJson,
                    $impactBrief,
                    $proposedSolution,
                    $responseDate,
                    $responseBy,
                    $responseDecision,
                    $responseRemarks,
                    $reportDistributeTo,
                    $preparedByPost,
                    $status,
                    $rfiId
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(
                        'Failed to update RFI: '
                        . mysqli_stmt_error($stmt)
                    );
                }

                mysqli_stmt_close($stmt);
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO rfi_reports
                    (
                        project_id,
                        site_id,
                        client_id,
                        employee_id,
                        project_name,
                        project_location,
                        client_name,
                        rfi_no,
                        issue_date,
                        required_response_date,
                        subject,
                        architect_consultant,
                        contractor,
                        raised_by_name,
                        raised_by_designation,
                        drawing_no,
                        drawing_date,
                        specification_clause,
                        boq_item_ref,
                        site_instruction_ref,
                        query_description,
                        attachments_json,
                        impacts_json,
                        impact_brief,
                        proposed_solution,
                        response_date,
                        response_by,
                        response_decision,
                        response_remarks,
                        report_distribute_to,
                        prepared_by,
                        status
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?,
                        NULLIF(?, ''), ?, ?, ?, ?, ?, ?
                    )
                ");

                if (!$stmt) {
                    throw new RuntimeException(
                        'RFI insert SQL error: ' . mysqli_error($conn)
                    );
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'iiiissssssssssssssssssssssssssss',
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $employeeId,
                    $projectName,
                    $projectLocation,
                    $clientName,
                    $rfiNo,
                    $postIssueDate,
                    $requiredResponseDate,
                    $subject,
                    $architectConsultant,
                    $contractor,
                    $raisedByName,
                    $raisedByDesignation,
                    $drawingNo,
                    $drawingDate,
                    $specificationClause,
                    $boqItemRef,
                    $siteInstructionRef,
                    $queryDescription,
                    $attachmentsJson,
                    $impactsJson,
                    $impactBrief,
                    $proposedSolution,
                    $responseDate,
                    $responseBy,
                    $responseDecision,
                    $responseRemarks,
                    $reportDistributeTo,
                    $preparedByPost,
                    $status
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(
                        'Failed to save RFI: '
                        . mysqli_stmt_error($stmt)
                    );
                }

                $rfiId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }

            rfi_sync_submission(
                $conn,
                $postSubmissionId,
                $postProjectId,
                $employeeId,
                $postIssueDate,
                $rfiId,
                $rfiNo,
                $subject
            );

            mysqli_commit($conn);

            rfi_redirect_hub(
                $postProjectId,
                $postIssueDate,
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
            'Location: rfi.php'
            . '?project_id=' . $postProjectId
            . '&report_date=' . urlencode($postIssueDate)
            . '&error=' . urlencode($error)
        );

        exit;
    }
}

$formData = [
    'rfi_no' => $existingReport['rfi_no'] ?? $defaultRfiNo,
    'issue_date' => $existingReport['issue_date'] ?? $issueDate,
    'required_response_date' => $existingReport['required_response_date']
        ?? date('Y-m-d', strtotime($issueDate . ' +3 days')),
    'architect_consultant' => $existingReport['architect_consultant'] ?? '',
    'contractor' => $existingReport['contractor'] ?? '',
    'raised_by_name' => $existingReport['raised_by_name'] ?? $preparedBy,
    'raised_by_designation' => $existingReport['raised_by_designation'] ?? $preparedDesignation,
    'subject' => $existingReport['subject'] ?? '',
    'drawing_no' => $existingReport['drawing_no'] ?? '',
    'drawing_date' => $existingReport['drawing_date'] ?? '',
    'specification_clause' => $existingReport['specification_clause'] ?? '',
    'boq_item_ref' => $existingReport['boq_item_ref'] ?? '',
    'site_instruction_ref' => $existingReport['site_instruction_ref'] ?? '',
    'query_description' => $existingReport['query_description'] ?? '',
    'attachments' => json_decode(
        (string)($existingReport['attachments_json'] ?? '{}'),
        true
    ) ?: [],
    'impacts' => json_decode(
        (string)($existingReport['impacts_json'] ?? '{}'),
        true
    ) ?: [],
    'impact_brief' => $existingReport['impact_brief'] ?? '',
    'proposed_solution' => $existingReport['proposed_solution'] ?? '',
    'response_date' => $existingReport['response_date'] ?? '',
    'response_by' => $existingReport['response_by'] ?? '',
    'response_decision' => $existingReport['response_decision'] ?? '',
    'response_remarks' => $existingReport['response_remarks'] ?? '',
    'report_distribute_to' => $existingReport['report_distribute_to']
        ?? $defaultDistribute,
    'prepared_by' => $existingReport['prepared_by'] ?? $preparedBy,
];

$previousPayload = null;

if ($previousTemplate) {
    $previousPayload = [
        'architect_consultant' => $previousTemplate['architect_consultant'] ?? '',
        'contractor' => $previousTemplate['contractor'] ?? '',
        'drawing_no' => $previousTemplate['drawing_no'] ?? '',
        'specification_clause' => $previousTemplate['specification_clause'] ?? '',
        'boq_item_ref' => $previousTemplate['boq_item_ref'] ?? '',
        'site_instruction_ref' => $previousTemplate['site_instruction_ref'] ?? '',
        'report_distribute_to' => $previousTemplate['report_distribute_to'] ?? '',
        'rfi_no' => $previousTemplate['rfi_no'] ?? '',
        'issue_date' => $previousTemplate['issue_date'] ?? '',
    ];
}

$recentReports = [];

$recentQuery = mysqli_query($conn, "
    SELECT
        id,
        rfi_no,
        issue_date,
        subject,
        status,
        project_name
    FROM rfi_reports
    WHERE employee_id = $employeeId
    ORDER BY created_at DESC
    LIMIT 10
");

while ($recentQuery && ($row = mysqli_fetch_assoc($recentQuery))) {
    $recentReports[] = $row;
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

    <title>RFI - TEK-C PMC Construction</title>

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

    .check-card {
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 12px;
        background: rgba(148, 163, 184, .06);
        display: flex;
        gap: 10px;
        align-items: center;
        font-weight: 800;
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
                                ? 'Resubmit Request For Information (RFI)'
                                : 'Request For Information (RFI)' ?>
                            </h1>

                            <p class="text-muted-custom mb-0 small">
                                Create and submit project clarification requests.
                            </p>
                        </div>

                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <label class="badge-soft mb-0 <?= !$previousPayload ? 'opacity-75' : '' ?>">
                                <input type="checkbox" id="loadPreviousRfiData"
                                    <?= !$previousPayload ? 'disabled' : '' ?>>

                                <span>
                                    <strong>Load previous data</strong>

                                    <small class="d-block text-muted-custom">
                                        <?= $previousPayload
                                        ? rfi_e($previousPayload['rfi_no'])
                                            . ' · '
                                            . rfi_e(date(
                                                'd M Y',
                                                strtotime($previousPayload['issue_date'])
                                            ))
                                        : 'No previous data' ?>
                                    </small>
                                </span>
                            </label>

                            <span class="badge-soft">
                                <i data-lucide="user" style="width:15px"></i>
                                <?= rfi_e($preparedBy) ?>
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
                                    <?= rfi_e($projectOption['project_name']) ?>

                                    <?= !empty($projectOption['project_code'])
                                        ? ' (' . rfi_e($projectOption['project_code']) . ')'
                                        : '' ?>

                                    - <?= rfi_e($projectOption['project_location'] ?: '-') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-3">
                            <a href="rfi.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">
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
                            <div class="fw-bold"><?= rfi_e($project['project_name']) ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Client</small>
                            <div class="fw-bold"><?= rfi_e($project['client_name'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Location</small>
                            <div class="fw-bold"><?= rfi_e($project['project_location'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Schedule</small>
                            <div class="fw-bold">
                                <?= rfi_e($pmsSchedule['schedule_name'] ?? 'PMS Schedule') ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Start</small>
                            <div class="fw-bold">
                                <?= rfi_e($pmsScheduleStart ?: ($project['start_date'] ?? '-')) ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS End</small>
                            <div class="fw-bold">
                                <?= rfi_e($pmsScheduleEnd ?: ($project['expected_completion_date'] ?? '-')) ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="submit_rfi" value="1">
                    <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
                    <input type="hidden" name="resubmit_submission_id" value="<?= (int)$resubmitSubmissionId ?>">

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="clipboard-check"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">RFI Header</h2>
                                <p class="text-muted-custom small mb-0">
                                    Report number, dates and project-party details.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">RFI No</label>
                                <input class="form-control rounded-4" name="rfi_no"
                                    value="<?= rfi_e($formData['rfi_no']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Date of Issue</label>
                                <input type="date" class="form-control rounded-4" id="issue_date" name="issue_date"
                                    value="<?= rfi_e($formData['issue_date']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Required Response Date</label>
                                <input type="date" class="form-control rounded-4" name="required_response_date"
                                    value="<?= rfi_e($formData['required_response_date']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Architect / Consultant</label>
                                <input class="form-control rounded-4" id="architect_consultant"
                                    name="architect_consultant" placeholder="Enter architect or consultant"
                                    value="<?= rfi_e($formData['architect_consultant']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Contractor</label>
                                <input class="form-control rounded-4" id="contractor" name="contractor"
                                    placeholder="Enter contractor name" value="<?= rfi_e($formData['contractor']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Raised By</label>
                                <input class="form-control rounded-4" name="raised_by_name"
                                    value="<?= rfi_e($formData['raised_by_name']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Designation</label>
                                <input class="form-control rounded-4" name="raised_by_designation"
                                    value="<?= rfi_e($formData['raised_by_designation']) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="pin"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Subject & References</h2>
                                <p class="text-muted-custom small mb-0">
                                    Define the clarification and related documents.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-bold small">Subject</label>
                                <input class="form-control rounded-4" name="subject"
                                    placeholder="Enter short and clear RFI subject"
                                    value="<?= rfi_e($formData['subject']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Drawing No</label>
                                <input class="form-control rounded-4" id="drawing_no" name="drawing_no"
                                    placeholder="e.g. S-104" value="<?= rfi_e($formData['drawing_no']) ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Drawing Date</label>
                                <input type="date" class="form-control rounded-4" name="drawing_date"
                                    value="<?= rfi_e($formData['drawing_date']) ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Specification Clause</label>
                                <input class="form-control rounded-4" id="specification_clause"
                                    name="specification_clause" placeholder="e.g. Clause 4.2.1"
                                    value="<?= rfi_e($formData['specification_clause']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">BOQ Item Ref</label>
                                <input class="form-control rounded-4" id="boq_item_ref" name="boq_item_ref"
                                    placeholder="Enter BOQ reference" value="<?= rfi_e($formData['boq_item_ref']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Site Instruction Ref</label>
                                <input class="form-control rounded-4" id="site_instruction_ref"
                                    name="site_instruction_ref" placeholder="Enter site instruction reference"
                                    value="<?= rfi_e($formData['site_instruction_ref']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="circle-help"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Description of Query</h2>
                                <p class="text-muted-custom small mb-0">
                                    Explain the issue and exact clarification needed.
                                </p>
                            </div>
                        </div>

                        <textarea class="form-control rounded-4" name="query_description" rows="7"
                            placeholder="Explain the issue, discrepancy and clarification required"
                            required><?= rfi_e($formData['query_description']) ?></textarea>
                    </div>

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="paperclip"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Attachments</h2>
                                <p class="text-muted-custom small mb-0">
                                    Select attachment types included with the RFI.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <?php
                        $attachmentOptions = [
                            'att_site_photograph' => ['site_photograph', 'Site Photograph'],
                            'att_markedup_drawing' => ['markedup_drawing', 'Marked-up Drawing'],
                            'att_sketch' => ['sketch', 'Sketch'],
                            'att_calculation_sheet' => ['calculation_sheet', 'Calculation Sheet'],
                        ];
                        ?>

                            <?php foreach ($attachmentOptions as $name => [$key, $label]): ?>
                            <div class="col-md-6">
                                <label class="check-card">
                                    <input type="checkbox" name="<?= rfi_e($name) ?>" value="1"
                                        <?= !empty($formData['attachments'][$key]) ? 'checked' : '' ?>>
                                    <?= rfi_e($label) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="triangle-alert"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Impact If Not Clarified</h2>
                                <p class="text-muted-custom small mb-0">
                                    Select applicable impact categories.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <?php
                        $impactOptions = [
                            'impact_work_stoppage' => ['work_stoppage', 'Work Stoppage'],
                            'impact_delay_schedule' => ['delay_in_schedule', 'Delay in Schedule'],
                            'impact_cost' => ['cost_impact', 'Cost Impact'],
                            'impact_quality' => ['quality_risk', 'Quality Risk'],
                            'impact_safety' => ['safety_risk', 'Safety Risk'],
                        ];
                        ?>

                            <?php foreach ($impactOptions as $name => [$key, $label]): ?>
                            <div class="col-md-6 col-xl-4">
                                <label class="check-card">
                                    <input type="checkbox" name="<?= rfi_e($name) ?>" value="1"
                                        <?= !empty($formData['impacts'][$key]) ? 'checked' : '' ?>>
                                    <?= rfi_e($label) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <textarea class="form-control rounded-4" name="impact_brief" rows="3"
                            placeholder="Explain likely schedule, cost, quality or safety impact"><?= rfi_e($formData['impact_brief']) ?></textarea>
                    </div>

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="wrench"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Proposed Solution</h2>
                                <p class="text-muted-custom small mb-0">
                                    Contractor or PMC recommendation, when available.
                                </p>
                            </div>
                        </div>

                        <textarea class="form-control rounded-4" name="proposed_solution" rows="4"
                            placeholder="Enter proposed solution or recommendation"><?= rfi_e($formData['proposed_solution']) ?></textarea>
                    </div>

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="reply"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Response Section</h2>
                                <p class="text-muted-custom small mb-0">
                                    Optional consultant or architect response.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Response Date</label>
                                <input type="date" class="form-control rounded-4" name="response_date"
                                    value="<?= rfi_e($formData['response_date']) ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Response By</label>
                                <input class="form-control rounded-4" name="response_by" placeholder="Name or company"
                                    value="<?= rfi_e($formData['response_by']) ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Decision</label>
                                <select class="form-select rounded-4" name="response_decision">
                                    <option value="">-- Select Decision --</option>

                                    <?php foreach ([
                                    'Approved',
                                    'Approved with Comments',
                                    'Not Approved',
                                    'Revised Drawing to be Issued'
                                ] as $decision): ?>
                                    <option value="<?= rfi_e($decision) ?>" <?= $formData['response_decision'] === $decision
                                            ? 'selected'
                                            : '' ?>>
                                        <?= rfi_e($decision) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold small">Response Remarks</label>
                                <textarea class="form-control rounded-4" name="response_remarks" rows="4"
                                    placeholder="Consultant or architect remarks"><?= rfi_e($formData['response_remarks']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="send"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Distribution & Submission</h2>
                                <p class="text-muted-custom small mb-0">
                                    Confirm recipients and submit the RFI.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold small">Report Distribute To</label>
                                <textarea class="form-control rounded-4" id="report_distribute_to"
                                    name="report_distribute_to" rows="3"
                                    required><?= rfi_e($formData['report_distribute_to']) ?></textarea>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Prepared By</label>
                                <input class="form-control rounded-4" name="prepared_by"
                                    value="<?= rfi_e($formData['prepared_by']) ?>" readonly>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4"
                                <?= !$project ? 'disabled' : '' ?>>
                                <?= $resubmitSubmissionId > 0
                                ? 'Resubmit RFI'
                                : 'Submit RFI' ?>
                            </button>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4">
                        <h2 class="fw-bold fs-6 mb-1">Recent RFI</h2>
                        <p class="text-muted-custom small mb-0">
                            Your latest Request For Information submissions.
                        </p>
                    </div>

                    <div class="px-3 px-lg-4 pb-4">
                        <div class="row g-2">
                            <?php foreach ($recentReports as $recent): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="recent-card">
                                    <div class="fw-bold">
                                        <?= rfi_e($recent['rfi_no']) ?>
                                    </div>

                                    <small class="text-muted-custom">
                                        <?= rfi_e($recent['project_name']) ?>
                                        ·
                                        <?= rfi_e(date(
                                            'd M Y',
                                            strtotime($recent['issue_date'])
                                        )) ?>
                                        ·
                                        <?= rfi_e($recent['status']) ?>
                                    </small>

                                    <div class="small mt-1">
                                        <?= rfi_e($recent['subject']) ?>
                                    </div>
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
    <script src="assets/js/script.js?v=45"></script>

    <script>
    const previousRfiData =
        <?= json_encode(
        $previousPayload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    const currentRfiSnapshot = {
        architect_consultant: document.getElementById('architect_consultant')?.value || '',
        contractor: document.getElementById('contractor')?.value || '',
        drawing_no: document.getElementById('drawing_no')?.value || '',
        specification_clause: document.getElementById('specification_clause')?.value || '',
        boq_item_ref: document.getElementById('boq_item_ref')?.value || '',
        site_instruction_ref: document.getElementById('site_instruction_ref')?.value || '',
        report_distribute_to: document.getElementById('report_distribute_to')?.value || ''
    };

    function loadRfiSource(source) {
        if (!source) {
            return;
        }

        [
            'architect_consultant',
            'contractor',
            'drawing_no',
            'specification_clause',
            'boq_item_ref',
            'site_instruction_ref',
            'report_distribute_to'
        ].forEach(id => {
            const field = document.getElementById(id);

            if (field) {
                field.value = source[id] || '';
            }
        });

        /*
         * Unique values intentionally remain unchanged:
         * RFI No, Issue Date, Required Response Date,
         * Project, Subject, Query and Prepared By.
         */
    }

    document.getElementById('loadPreviousRfiData')
        ?.addEventListener('change', function() {
            loadRfiSource(
                this.checked ?
                previousRfiData :
                currentRfiSnapshot
            );
        });

    document.getElementById('projectPicker')
        ?.addEventListener('change', function() {
            const date =
                document.getElementById('issue_date')?.value ||
                '<?= rfi_e($issueDate) ?>';

            window.location.href = this.value ?
                'rfi.php?project_id=' +
                encodeURIComponent(this.value) +
                '&report_date=' +
                encodeURIComponent(date) :
                'rfi.php';
        });
    </script>
</body>

</html>