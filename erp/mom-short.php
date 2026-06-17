<?php
session_start();

require_once __DIR__ . '/includes/db.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

function mom_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function mom_employee_id(mysqli $conn): int
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

function mom_is_super_admin(mysqli $conn): bool
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

function mom_report_access(mysqli $conn, string $permission): bool
{
    if (mom_is_super_admin($conn)) {
        return true;
    }

    $allowed = ['can_submit', 'can_view', 'can_remark_tl', 'can_remark_manager'];

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
          AND rt.report_code = 'MOMST'
          AND rt.is_active = 1
    ");

    return $query
        && ($row = mysqli_fetch_assoc($query))
        && (int)($row['allowed'] ?? 0) === 1;
}

function mom_project_allowed(
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

function mom_columns(mysqli $conn, string $table): array
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $columns = [];

    $query = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $columns[$row['Field']] = true;
    }

    return $columns;
}

function mom_sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function mom_report_type_id(mysqli $conn): int
{
    $query = mysqli_query($conn, "
        SELECT id
        FROM master_report_types
        WHERE report_code = 'MOMST'
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function mom_find_existing(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate
): int {
    if ($submissionId > 0) {
        $columns = mom_columns($conn, 'project_report_submissions');
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
                    "SELECT id FROM mom_short_reports WHERE id = $candidate LIMIT 1"
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
        FROM mom_short_reports
        WHERE project_id = $projectId
          AND employee_id = $employeeId
          AND mom_date = '$dateEsc'
        ORDER BY id DESC
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function mom_sync_submission(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate,
    int $momId,
    string $momNo
): void {
    $reportTypeId = mom_report_type_id($conn);

    if ($reportTypeId <= 0 || $momId <= 0) {
        return;
    }

    $columns = mom_columns($conn, 'project_report_submissions');
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $map = [
        'project_id' => $projectId,
        'report_type_id' => $reportTypeId,
        'report_no' => $momNo,
        'report_number' => $momNo,
        'submission_no' => $momNo,
        'title' => 'MOM(Short Term)',
        'submitted_by_employee_id' => $employeeId,
        'submitted_by_user_id' => $userId > 0 ? $userId : null,
        'submission_for_date' => $reportDate,
        'period_start' => $reportDate,
        'period_end' => $reportDate,
        'status' => 'submitted',
        'submitted_at' => date('Y-m-d H:i:s'),
        'source_table' => 'mom_short_reports',
        'source_id' => $momId,
        'report_reference_table' => 'mom_short_reports',
        'report_reference_id' => $momId,
        'reference_id' => $momId,
        'updated_by' => $userId > 0 ? $userId : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($submissionId > 0) {
        $sets = [];

        foreach ($map as $field => $value) {
            if (isset($columns[$field])) {
                $sets[] = "`$field` = " . mom_sql_value($conn, $value);
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
            $insertValues[] = mom_sql_value($conn, $value);
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

function mom_clean_rows(array $rows): array
{
    return array_values(array_filter($rows, static function ($row) {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }));
}

$employeeId = mom_employee_id($conn);
$isSuperAdmin = mom_is_super_admin($conn);

if ($employeeId <= 0) {
    header('Location: login.php');
    exit;
}

if (!mom_report_access($conn, 'can_submit')) {
    header(
        'Location: reports-hub.php?error='
        . urlencode('You do not have MOM submit access.')
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
    ?? $_POST['mom_date']
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
    && mom_project_allowed($conn, $projectId, $employeeId, $isSuperAdmin)
) {
    $query = mysqli_query($conn, "
        SELECT
            p.*,
            c.client_name,
            c.company_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        WHERE p.id = $projectId
          AND p.deleted_at IS NULL
        LIMIT 1
    ");

    if ($query) {
        $project = mysqli_fetch_assoc($query);
    }
}

$defaultMomNo = '';

if ($projectId > 0) {
    $prefix = 'MOMS/' . $projectId . '/' . date('Ym', strtotime($reportDate)) . '/';
    $prefixEsc = mysqli_real_escape_string($conn, $prefix);

    $query = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM mom_short_reports
         WHERE mom_no LIKE '$prefixEsc%'"
    );

    $count = $query
        ? (int)(mysqli_fetch_assoc($query)['total'] ?? 0)
        : 0;

    $defaultMomNo = $prefix
        . str_pad((string)($count + 1), 3, '0', STR_PAD_LEFT);
}

$existingReport = null;

if ($resubmitSubmissionId > 0 && $projectId > 0) {
    $existingId = mom_find_existing(
        $conn,
        $resubmitSubmissionId,
        $projectId,
        $employeeId,
        $reportDate
    );

    if ($existingId > 0) {
        $query = mysqli_query(
            $conn,
            "SELECT * FROM mom_short_reports WHERE id = $existingId LIMIT 1"
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
        "project_id = $projectId AND employee_id = $employeeId AND mom_date < '$dateEsc'",
        "project_id = $projectId AND employee_id = $employeeId",
        "project_id = $projectId"
    ] as $where) {
        $query = mysqli_query($conn, "
            SELECT *
            FROM mom_short_reports
            WHERE $where
            ORDER BY mom_date DESC, created_at DESC, id DESC
            LIMIT 1
        ");

        if ($query && ($previousReport = mysqli_fetch_assoc($query))) {
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_mom'])) {
    $postProjectId = (int)($_POST['project_id'] ?? 0);
    $postSubmissionId = (int)($_POST['resubmit_submission_id'] ?? 0);

    $momNo = trim((string)($_POST['mom_no'] ?? ''));
    $momDate = trim((string)($_POST['mom_date'] ?? ''));
    $architects = trim((string)($_POST['architects'] ?? ''));
    $pmcName = trim((string)($_POST['pmc_name'] ?? ''));
    $meetingConductedBy = trim((string)($_POST['meeting_conducted_by'] ?? ''));
    $meetingHeldAt = trim((string)($_POST['meeting_held_at'] ?? ''));
    $meetingTime = trim((string)($_POST['meeting_time'] ?? ''));
    $momSharedTo = trim((string)($_POST['mom_shared_to'] ?? ''));
    $momCopyTo = trim((string)($_POST['mom_copy_to'] ?? ''));
    $momSharedBy = trim((string)($_POST['mom_shared_by'] ?? ''));
    $momSharedOn = trim((string)($_POST['mom_shared_on'] ?? ''));
    $nextMeetingDate = trim((string)($_POST['next_meeting_date'] ?? ''));
    $nextMeetingPlace = trim((string)($_POST['next_meeting_place'] ?? ''));

    $agendaItems = $_POST['agenda_item'] ?? [];
    $attStakeholder = $_POST['att_stakeholder'] ?? [];
    $attName = $_POST['att_name'] ?? [];
    $attDesignation = $_POST['att_designation'] ?? [];
    $attFirm = $_POST['att_firm'] ?? [];
    $minDiscussion = $_POST['min_discussion'] ?? [];
    $minResponsible = $_POST['min_responsible'] ?? [];
    $minDeadline = $_POST['min_deadline'] ?? [];
    $amdDiscussion = $_POST['amd_discussion'] ?? [];
    $amdResponsible = $_POST['amd_responsible'] ?? [];
    $amdDeadline = $_POST['amd_deadline'] ?? [];

    $agendaRows = [];

    foreach ($agendaItems as $item) {
        $agendaRows[] = ['item' => trim((string)$item)];
    }

    $agendaRows = mom_clean_rows($agendaRows);

    $attendeeRows = [];
    $attendeeCount = max(
        count($attStakeholder),
        count($attName),
        count($attDesignation),
        count($attFirm)
    );

    for ($index = 0; $index < $attendeeCount; $index++) {
        $attendeeRows[] = [
            'stakeholder' => trim((string)($attStakeholder[$index] ?? '')),
            'name' => trim((string)($attName[$index] ?? '')),
            'designation' => trim((string)($attDesignation[$index] ?? '')),
            'firm' => trim((string)($attFirm[$index] ?? '')),
        ];
    }

    $attendeeRows = mom_clean_rows($attendeeRows);

    $minuteRows = [];
    $minuteCount = max(
        count($minDiscussion),
        count($minResponsible),
        count($minDeadline)
    );

    for ($index = 0; $index < $minuteCount; $index++) {
        $minuteRows[] = [
            'discussion' => trim((string)($minDiscussion[$index] ?? '')),
            'responsible_by' => trim((string)($minResponsible[$index] ?? '')),
            'deadline' => trim((string)($minDeadline[$index] ?? '')),
        ];
    }

    $minuteRows = mom_clean_rows($minuteRows);

    $amendedRows = [];
    $amendedCount = max(
        count($amdDiscussion),
        count($amdResponsible),
        count($amdDeadline)
    );

    for ($index = 0; $index < $amendedCount; $index++) {
        $amendedRows[] = [
            'discussion' => trim((string)($amdDiscussion[$index] ?? '')),
            'responsible_by' => trim((string)($amdResponsible[$index] ?? '')),
            'deadline' => trim((string)($amdDeadline[$index] ?? '')),
        ];
    }

    $amendedRows = mom_clean_rows($amendedRows);

    $error = '';

    if (
        !mom_project_allowed(
            $conn,
            $postProjectId,
            $employeeId,
            $isSuperAdmin
        )
    ) {
        $error = 'Invalid project selection.';
    } elseif ($momNo === '') {
        $error = 'MOM(Short Term) No is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $momDate)) {
        $error = 'Valid MOM date is required.';
    } elseif ($meetingConductedBy === '') {
        $error = 'Meeting conducted by is required.';
    } elseif ($meetingHeldAt === '') {
        $error = 'Meeting held at is required.';
    } elseif ($meetingTime === '') {
        $error = 'Meeting time is required.';
    } elseif (!$minuteRows) {
        $error = 'Please enter at least one minutes-of-discussion row.';
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

        $agendaJson = $agendaRows
            ? json_encode($agendaRows, JSON_UNESCAPED_UNICODE)
            : null;

        $attendeesJson = $attendeeRows
            ? json_encode($attendeeRows, JSON_UNESCAPED_UNICODE)
            : null;

        $minutesJson = json_encode(
            $minuteRows,
            JSON_UNESCAPED_UNICODE
        );

        $amendedJson = $amendedRows
            ? json_encode($amendedRows, JSON_UNESCAPED_UNICODE)
            : null;

        mysqli_begin_transaction($conn);

        try {
            if ($postSubmissionId > 0) {
                $momId = mom_find_existing(
                    $conn,
                    $postSubmissionId,
                    $postProjectId,
                    $employeeId,
                    $momDate
                );

                if ($momId <= 0) {
                    throw new RuntimeException(
                        'Original MOM not found for resubmission.'
                    );
                }

                $stmt = mysqli_prepare($conn, "
                    UPDATE mom_short_reports
                    SET
                        mom_no = ?,
                        project_id = ?,
                        client_id = ?,
                        project_name = ?,
                        client_name = ?,
                        pmc_name = ?,
                        architects = ?,
                        mom_date = ?,
                        meeting_conducted_by = ?,
                        meeting_held_at = ?,
                        meeting_time = ?,
                        agenda_json = ?,
                        attendees_json = ?,
                        minutes_json = ?,
                        amended_json = ?,
                        mom_shared_to = ?,
                        mom_copy_to = ?,
                        mom_shared_by = ?,
                        mom_shared_on = NULLIF(?, ''),
                        next_meeting_date = NULLIF(?, ''),
                        next_meeting_place = ?,
                        prepared_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                if (!$stmt) {
                    throw new RuntimeException(mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'siisssssssssssssssssssi',
                    $momNo,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $pmcName,
                    $architects,
                    $momDate,
                    $meetingConductedBy,
                    $meetingHeldAt,
                    $meetingTime,
                    $agendaJson,
                    $attendeesJson,
                    $minutesJson,
                    $amendedJson,
                    $momSharedTo,
                    $momCopyTo,
                    $momSharedBy,
                    $momSharedOn,
                    $nextMeetingDate,
                    $nextMeetingPlace,
                    $employeeName,
                    $momId
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                mysqli_stmt_close($stmt);
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO mom_short_reports
                    (
                        project_id,
                        client_id,
                        employee_id,
                        mom_no,
                        mom_date,
                        project_name,
                        client_name,
                        pmc_name,
                        architects,
                        meeting_conducted_by,
                        meeting_held_at,
                        meeting_time,
                        agenda_json,
                        attendees_json,
                        minutes_json,
                        amended_json,
                        mom_shared_to,
                        mom_copy_to,
                        mom_shared_by,
                        mom_shared_on,
                        next_meeting_date,
                        next_meeting_place,
                        prepared_by
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''),
                        NULLIF(?, ''), ?, ?
                    )
                ");

                if (!$stmt) {
                    throw new RuntimeException(mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    'iiissssssssssssssssssss',
                    $postProjectId,
                    $clientId,
                    $employeeId,
                    $momNo,
                    $momDate,
                    $projectName,
                    $clientName,
                    $pmcName,
                    $architects,
                    $meetingConductedBy,
                    $meetingHeldAt,
                    $meetingTime,
                    $agendaJson,
                    $attendeesJson,
                    $minutesJson,
                    $amendedJson,
                    $momSharedTo,
                    $momCopyTo,
                    $momSharedBy,
                    $momSharedOn,
                    $nextMeetingDate,
                    $nextMeetingPlace,
                    $employeeName
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                $momId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }

            mom_sync_submission(
                $conn,
                $postSubmissionId,
                $postProjectId,
                $employeeId,
                $momDate,
                $momId,
                $momNo
            );

            mysqli_commit($conn);

            header(
                'Location: reports-hub.php'
                . '?project_id=' . $postProjectId
                . '&report_date=' . urlencode($momDate)
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
            'Location: mom-short.php'
            . '?project_id=' . $postProjectId
            . '&report_date=' . urlencode($momDate)
            . '&error=' . urlencode($error)
        );
        exit;
    }
}

function mom_decode_rows($json): array
{
    if (is_array($json)) {
        return $json;
    }

    $rows = json_decode((string)$json, true);

    return is_array($rows) ? $rows : [];
}

$formData = [
    'mom_no' => $existingReport['mom_no'] ?? $defaultMomNo,
    'mom_date' => $existingReport['mom_date'] ?? $reportDate,
    'pmc_name' => $existingReport['pmc_name'] ?? 'M/s. UKB Construction Management Pvt Ltd',
    'architects' => $existingReport['architects'] ?? '',
    'meeting_conducted_by' => $existingReport['meeting_conducted_by'] ?? $employeeName,
    'meeting_held_at' => $existingReport['meeting_held_at'] ?? ($project['project_location'] ?? ''),
    'meeting_time' => $existingReport['meeting_time'] ?? '',
    'agenda' => mom_decode_rows($existingReport['agenda_json'] ?? ''),
    'attendees' => mom_decode_rows($existingReport['attendees_json'] ?? ''),
    'minutes' => mom_decode_rows($existingReport['minutes_json'] ?? ''),
    'amended' => mom_decode_rows($existingReport['amended_json'] ?? ''),
    'mom_shared_to' => $existingReport['mom_shared_to'] ?? 'All Attendees',
    'mom_copy_to' => $existingReport['mom_copy_to'] ?? '',
    'mom_shared_by' => $existingReport['mom_shared_by'] ?? $employeeName,
    'mom_shared_on' => $existingReport['mom_shared_on'] ?? date('Y-m-d'),
    'next_meeting_date' => $existingReport['next_meeting_date'] ?? '',
    'next_meeting_place' => $existingReport['next_meeting_place'] ?? '',
];

if (!$formData['agenda']) {
    $formData['agenda'] = [['item' => ''], ['item' => '']];
}

if (!$formData['attendees']) {
    $formData['attendees'] = [[
        'stakeholder' => '',
        'name' => '',
        'designation' => '',
        'firm' => '',
    ]];
}

if (!$formData['minutes']) {
    $formData['minutes'] = array_fill(0, 3, [
        'discussion' => '',
        'responsible_by' => '',
        'deadline' => '',
    ]);
}

if (!$formData['amended']) {
    $formData['amended'] = [[
        'discussion' => '',
        'responsible_by' => '',
        'deadline' => '',
    ]];
}

$previousPayload = null;

if ($previousReport) {
    $previousPayload = [
        'pmc_name' => $previousReport['pmc_name'] ?? '',
        'architects' => $previousReport['architects'] ?? '',
        'meeting_conducted_by' => $previousReport['meeting_conducted_by'] ?? '',
        'meeting_held_at' => $previousReport['meeting_held_at'] ?? '',
        'meeting_time' => $previousReport['meeting_time'] ?? '',
        'agenda' => mom_decode_rows($previousReport['agenda_json'] ?? ''),
        'attendees' => mom_decode_rows($previousReport['attendees_json'] ?? ''),
        'minutes' => mom_decode_rows($previousReport['minutes_json'] ?? ''),
        'amended' => mom_decode_rows($previousReport['amended_json'] ?? ''),
        'mom_shared_to' => $previousReport['mom_shared_to'] ?? '',
        'mom_copy_to' => $previousReport['mom_copy_to'] ?? '',
        'mom_shared_by' => $previousReport['mom_shared_by'] ?? '',
        'next_meeting_place' => $previousReport['next_meeting_place'] ?? '',
    ];
}

$recent = [];

$query = mysqli_query($conn, "
    SELECT
        m.id,
        m.mom_no,
        m.mom_date,
        m.project_name
    FROM mom_short_reports m
    WHERE m.employee_id = $employeeId
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
    <title>MOM(Short Term) - TEK-C PMC Construction</title>

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

    .mom-table {
        min-width: 1000px;
    }

    .mom-table th {
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
                                ? 'Resubmit MOM(Short Term)'
                                : 'MOM(Short Term)' ?>
                            </h1>

                            <p class="text-muted-custom mb-0 small">
                                Record meeting agenda, attendees, decisions and follow-up actions.
                            </p>
                        </div>

                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <label class="badge-soft mb-0 <?= !$previousPayload ? 'opacity-75' : '' ?>">
                                <input type="checkbox" id="loadPrevious" <?= !$previousPayload ? 'disabled' : '' ?>>

                                <span>
                                    <strong>Load previous data</strong>
                                    <small class="d-block text-muted-custom">
                                        <?= $previousReport
                                        ? mom_e($previousReport['mom_no'])
                                            . ' · '
                                            . mom_e(date(
                                                'd M Y',
                                                strtotime($previousReport['mom_date'])
                                            ))
                                        : 'No previous data' ?>
                                    </small>
                                </span>
                            </label>

                            <span class="badge-soft">
                                <i data-lucide="user" style="width:15px"></i>
                                <?= mom_e($employeeName) ?>
                            </span>

                            <span class="badge-soft">
                                <?= mom_e($designationName) ?>
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
                                    <?= mom_e($projectOption['project_name']) ?>
                                    <?= !empty($projectOption['project_code'])
                                        ? ' (' . mom_e($projectOption['project_code']) . ')'
                                        : '' ?>
                                    - <?= mom_e($projectOption['project_location'] ?: '-') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-3">
                            <a href="mom-short.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">
                                Reset
                            </a>
                        </div>
                    </div>
                </div>

                <form method="POST" id="momForm">
                    <input type="hidden" name="submit_mom" value="1">
                    <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
                    <input type="hidden" name="resubmit_submission_id" value="<?= (int)$resubmitSubmissionId ?>">

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="building-2"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Project Information</h2>
                                <p class="text-muted-custom small mb-0">
                                    Current project, client, PMC and architect.
                                </p>
                            </div>
                        </div>

                        <?php if (!$project): ?>
                        <p class="text-muted-custom fw-bold mb-0">
                            Please select a project.
                        </p>
                        <?php else: ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <small class="text-muted-custom fw-bold">Project</small>
                                <div class="fw-bold"><?= mom_e($project['project_name']) ?></div>
                            </div>

                            <div class="col-md-6">
                                <small class="text-muted-custom fw-bold">Client</small>
                                <div class="fw-bold"><?= mom_e($project['client_name'] ?: '-') ?></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">PMC</label>
                                <input class="form-control rounded-4" id="pmc_name" name="pmc_name"
                                    value="<?= mom_e($formData['pmc_name']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Architects</label>
                                <input class="form-control rounded-4" id="architects" name="architects"
                                    value="<?= mom_e($formData['architects']) ?>">
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="file-text"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">MOM Header</h2>
                                <p class="text-muted-custom small mb-0">
                                    MOM number, meeting date and prepared-by information.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">MOM(Short Term) No</label>
                                <input class="form-control rounded-4" name="mom_no"
                                    value="<?= mom_e($formData['mom_no']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Meeting Date</label>
                                <input type="date" class="form-control rounded-4" id="mom_date" name="mom_date"
                                    value="<?= mom_e($formData['mom_date']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Prepared By</label>
                                <input class="form-control rounded-4" value="<?= mom_e($employeeName) ?>" readonly>
                            </div>
                        </div>
                    </div>

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="users"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Meeting Information</h2>
                                <p class="text-muted-custom small mb-0">
                                    Meeting organizer, venue and time.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Conducted By</label>
                                <input class="form-control rounded-4" id="meeting_conducted_by"
                                    name="meeting_conducted_by" value="<?= mom_e($formData['meeting_conducted_by']) ?>"
                                    required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Held At</label>
                                <input class="form-control rounded-4" id="meeting_held_at" name="meeting_held_at"
                                    value="<?= mom_e($formData['meeting_held_at']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Time</label>
                                <input class="form-control rounded-4" id="meeting_time" name="meeting_time"
                                    placeholder="10:30 AM" value="<?= mom_e($formData['meeting_time']) ?>" required>
                            </div>
                        </div>
                    </div>

                    <?php
                $tables = [
                    'agenda' => [
                        'title' => 'Meeting Agenda',
                        'subtitle' => 'Agenda points discussed in the meeting.',
                        'icon' => 'list-ordered'
                    ],
                    'attendees' => [
                        'title' => 'Meeting Attendees',
                        'subtitle' => 'Stakeholders and participants.',
                        'icon' => 'contact'
                    ],
                    'minutes' => [
                        'title' => 'Minutes of Discussions',
                        'subtitle' => 'Decisions, responsible person and deadline.',
                        'icon' => 'notebook-tabs'
                    ],
                    'amended' => [
                        'title' => 'Amended Points',
                        'subtitle' => 'Optional missed or amended points.',
                        'icon' => 'pencil-line'
                    ]
                ];
                ?>

                    <?php foreach ($tables as $key => $tableInfo): ?>
                    <div class="section-box mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
                            <div class="mini-head mb-0">
                                <div class="mini-icon">
                                    <i data-lucide="<?= mom_e($tableInfo['icon']) ?>"></i>
                                </div>

                                <div>
                                    <h2 class="fw-bold fs-6 mb-1"><?= mom_e($tableInfo['title']) ?></h2>
                                    <p class="text-muted-custom small mb-0"><?= mom_e($tableInfo['subtitle']) ?></p>
                                </div>
                            </div>

                            <button type="button" class="btn btn-outline-primary rounded-4 fw-bold add-row"
                                data-target="<?= mom_e($key) ?>">
                                Add Row
                            </button>
                        </div>

                        <div class="table-responsive thin-scrollbar">
                            <table class="table table-bordered mom-table">
                                <thead>
                                    <?php if ($key === 'agenda'): ?>
                                    <tr>
                                        <th style="width:70px">SL</th>
                                        <th>Agenda Item</th>
                                        <th style="width:70px">Del</th>
                                    </tr>
                                    <?php elseif ($key === 'attendees'): ?>
                                    <tr>
                                        <th>Stakeholder</th>
                                        <th>Name</th>
                                        <th>Designation</th>
                                        <th>Firm</th>
                                        <th style="width:70px">Del</th>
                                    </tr>
                                    <?php else: ?>
                                    <tr>
                                        <th style="width:70px">SL</th>
                                        <th>Discussion / Decision</th>
                                        <th>Responsible By</th>
                                        <th>Deadline</th>
                                        <th style="width:70px">Del</th>
                                    </tr>
                                    <?php endif; ?>
                                </thead>

                                <tbody id="<?= mom_e($key) ?>Body"></tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="send"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">Sharing Details</h2>
                                <p class="text-muted-custom small mb-0">
                                    MOM distribution and next-meeting information.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Shared To</label>
                                <input class="form-control rounded-4" id="mom_shared_to" name="mom_shared_to"
                                    value="<?= mom_e($formData['mom_shared_to']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Copy To</label>
                                <input class="form-control rounded-4" id="mom_copy_to" name="mom_copy_to"
                                    value="<?= mom_e($formData['mom_copy_to']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Shared By</label>
                                <input class="form-control rounded-4" id="mom_shared_by" name="mom_shared_by"
                                    value="<?= mom_e($formData['mom_shared_by']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Shared On</label>
                                <input type="date" class="form-control rounded-4" id="mom_shared_on"
                                    name="mom_shared_on" value="<?= mom_e($formData['mom_shared_on']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Next Meeting Date</label>
                                <input type="date" class="form-control rounded-4" id="next_meeting_date"
                                    name="next_meeting_date" value="<?= mom_e($formData['next_meeting_date']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Next Meeting Place</label>
                                <input class="form-control rounded-4" id="next_meeting_place" name="next_meeting_place"
                                    value="<?= mom_e($formData['next_meeting_place']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="section-box mb-3">
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4"
                                <?= !$project ? 'disabled' : '' ?>>
                                <?= $resubmitSubmissionId > 0
                                ? 'Resubmit MOM(Short Term)'
                                : 'Submit MOM(Short Term)' ?>
                            </button>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4">
                        <h2 class="fw-bold fs-6 mb-1">Recent MOM(Short Term)</h2>
                        <p class="text-muted-custom small mb-0">
                            Your latest MOM(Short Term) submissions.
                        </p>
                    </div>

                    <div class="px-3 px-lg-4 pb-4">
                        <div class="row g-2">
                            <?php foreach ($recent as $recentRow): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="recent-card">
                                    <div class="fw-bold">
                                        <?= mom_e($recentRow['mom_no']) ?>
                                    </div>

                                    <small class="text-muted-custom">
                                        <?= mom_e($recentRow['project_name']) ?>
                                        ·
                                        <?= mom_e(date(
                                            'd M Y',
                                            strtotime($recentRow['mom_date'])
                                        )) ?>
                                    </small>

                                    <br>

                                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2" target="_blank"
                                        href="reports-print/report-mom-short-print.php?view=<?= (int)$recentRow['id'] ?>">
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
    <script src="assets/js/script.js?v=52"></script>

    <script>
    const initialData = <?= json_encode(
    $formData,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

    const previousData = <?= json_encode(
    $previousPayload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

    const snapshot = JSON.parse(JSON.stringify(initialData));

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function attendeeOptions(selected = '') {
        const options = ['', 'Client', 'PMC', 'Architect', 'Contractor', 'Vendor', 'Other'];

        return options.map(option => `
        <option value="${escapeHtml(option)}"
            ${option === selected ? 'selected' : ''}>
            ${option || '-- Select --'}
        </option>
    `).join('');
    }

    function addAgendaRow(value = {}) {
        const body = document.getElementById('agendaBody');
        const row = document.createElement('tr');

        row.innerHTML = `
        <td class="sl text-center fw-bold"></td>
        <td>
            <input class="form-control rounded-4"
                   name="agenda_item[]"
                   value="${escapeHtml(value.item || '')}">
        </td>
        <td class="text-center">
            <button type="button"
                    class="btn btn-sm btn-outline-danger rounded-4 delete-row">
                <i data-lucide="trash-2"></i>
            </button>
        </td>
    `;

        body.appendChild(row);
        renumber(body);
    }

    function addAttendeeRow(value = {}) {
        const body = document.getElementById('attendeesBody');
        const row = document.createElement('tr');

        row.innerHTML = `
        <td>
            <select class="form-select rounded-4" name="att_stakeholder[]">
                ${attendeeOptions(value.stakeholder || '')}
            </select>
        </td>
        <td><input class="form-control rounded-4" name="att_name[]" value="${escapeHtml(value.name || '')}"></td>
        <td><input class="form-control rounded-4" name="att_designation[]" value="${escapeHtml(value.designation || '')}"></td>
        <td><input class="form-control rounded-4" name="att_firm[]" value="${escapeHtml(value.firm || '')}"></td>
        <td class="text-center">
            <button type="button"
                    class="btn btn-sm btn-outline-danger rounded-4 delete-row">
                <i data-lucide="trash-2"></i>
            </button>
        </td>
    `;

        body.appendChild(row);

        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    function addDiscussionRow(type, value = {}) {
        const body = document.getElementById(type + 'Body');
        const prefix = type === 'minutes' ? 'min' : 'amd';
        const row = document.createElement('tr');

        row.innerHTML = `
        <td class="sl text-center fw-bold"></td>
        <td><input class="form-control rounded-4" name="${prefix}_discussion[]" value="${escapeHtml(value.discussion || '')}"></td>
        <td><input class="form-control rounded-4" name="${prefix}_responsible[]" value="${escapeHtml(value.responsible_by || '')}"></td>
        <td><input class="form-control rounded-4" name="${prefix}_deadline[]" value="${escapeHtml(value.deadline || '')}" placeholder="ASAP or date"></td>
        <td class="text-center">
            <button type="button"
                    class="btn btn-sm btn-outline-danger rounded-4 delete-row">
                <i data-lucide="trash-2"></i>
            </button>
        </td>
    `;

        body.appendChild(row);
        renumber(body);
    }

    function renumber(body) {
        [...body.rows].forEach((row, index) => {
            const serial = row.querySelector('.sl');

            if (serial) {
                serial.textContent = index + 1;
            }
        });

        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    function renderData(data) {
        document.getElementById('pmc_name').value = data?.pmc_name || '';
        document.getElementById('architects').value = data?.architects || '';
        document.getElementById('meeting_conducted_by').value = data?.meeting_conducted_by || '';
        document.getElementById('meeting_held_at').value = data?.meeting_held_at || '';
        document.getElementById('meeting_time').value = data?.meeting_time || '';
        document.getElementById('mom_shared_to').value = data?.mom_shared_to || '';
        document.getElementById('mom_copy_to').value = data?.mom_copy_to || '';
        document.getElementById('mom_shared_by').value = data?.mom_shared_by || '';
        document.getElementById('next_meeting_place').value = data?.next_meeting_place || '';

        document.getElementById('agendaBody').innerHTML = '';
        document.getElementById('attendeesBody').innerHTML = '';
        document.getElementById('minutesBody').innerHTML = '';
        document.getElementById('amendedBody').innerHTML = '';

        (data?.agenda?.length ? data.agenda : [{}]).forEach(addAgendaRow);
        (data?.attendees?.length ? data.attendees : [{}]).forEach(addAttendeeRow);
        (data?.minutes?.length ? data.minutes : [{}]).forEach(item => addDiscussionRow('minutes', item));
        (data?.amended?.length ? data.amended : [{}]).forEach(item => addDiscussionRow('amended', item));
    }

    renderData(initialData);

    document.querySelectorAll('.add-row').forEach(button => {
        button.addEventListener('click', () => {
            const target = button.dataset.target;

            if (target === 'agenda') {
                addAgendaRow({});
            } else if (target === 'attendees') {
                addAttendeeRow({});
            } else {
                addDiscussionRow(target, {});
            }
        });
    });

    document.addEventListener('click', event => {
        const button = event.target.closest('.delete-row');

        if (!button) {
            return;
        }

        const row = button.closest('tr');
        const body = row.parentElement;

        if (body.rows.length <= 1) {
            row.querySelectorAll('input').forEach(input => {
                input.value = '';
            });

            row.querySelectorAll('select').forEach(select => {
                select.value = '';
            });
        } else {
            row.remove();
        }

        renumber(body);
    });

    document.getElementById('loadPrevious')
        ?.addEventListener('change', function() {
            renderData(this.checked ? previousData : snapshot);
        });

    document.getElementById('projectPicker')
        ?.addEventListener('change', function() {
            const date =
                document.getElementById('mom_date')?.value ||
                '<?= mom_e($reportDate) ?>';

            window.location.href = this.value ?
                'mom-short.php?project_id=' +
                encodeURIComponent(this.value) +
                '&report_date=' +
                encodeURIComponent(date) :
                'mom-short.php';
        });
    </script>
</body>

</html>