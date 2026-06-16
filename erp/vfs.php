<?php
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pms-helper.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

function vfs_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function vfs_employee_id(mysqli $conn): int
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

function vfs_is_super_admin(mysqli $conn): bool
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

function vfs_report_access(mysqli $conn, string $permission): bool
{
    if (vfs_is_super_admin($conn)) {
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
          AND rt.report_code = 'VFS'
          AND rt.is_active = 1
    ");

    return $query
        && ($row = mysqli_fetch_assoc($query))
        && (int)($row['allowed'] ?? 0) === 1;
}

function vfs_project_allowed(
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

function vfs_table_columns(mysqli $conn, string $table): array
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $columns = [];

    $query = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $columns[$row['Field']] = true;
    }

    return $columns;
}

function vfs_sql_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function vfs_report_type_id(mysqli $conn): int
{
    $query = mysqli_query($conn, "
        SELECT id
        FROM master_report_types
        WHERE report_code = 'VFS'
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function vfs_find_existing(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate
): int {
    if ($submissionId > 0) {
        $columns = vfs_table_columns($conn, 'project_report_submissions');
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
                    "SELECT id FROM vfs_main WHERE id = $candidateId LIMIT 1"
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
        FROM vfs_main
        WHERE project_id = $projectId
          AND prepared_by = $employeeId
          AND vfs_date = '$dateEsc'
        ORDER BY id DESC
        LIMIT 1
    ");

    return ($query && ($row = mysqli_fetch_assoc($query)))
        ? (int)$row['id']
        : 0;
}

function vfs_sync_submission(
    mysqli $conn,
    int $submissionId,
    int $projectId,
    int $employeeId,
    string $reportDate,
    int $vfsId,
    string $vfsNo
): void {
    $reportTypeId = vfs_report_type_id($conn);

    if ($reportTypeId <= 0 || $vfsId <= 0) {
        return;
    }

    $columns = vfs_table_columns($conn, 'project_report_submissions');
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
                    report_reference_id = $vfsId
                 OR source_id = $vfsId
                 OR reference_id = $vfsId
                 OR report_no = '" . mysqli_real_escape_string($conn, $vfsNo) . "'
              )
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($query && ($row = mysqli_fetch_assoc($query))) {
            $submissionId = (int)$row['id'];
        }
    }

    $map = [
        'report_no' => $vfsNo,
        'report_number' => $vfsNo,
        'submission_no' => $vfsNo,
        'title' => 'Vendor Finalization Schedule',
        'submitted_by_employee_id' => $employeeId,
        'submitted_by_user_id' => $userId > 0 ? $userId : null,
        'submission_for_date' => $reportDate,
        'period_start' => $reportDate,
        'period_end' => $reportDate,
        'status' => 'submitted',
        'submitted_at' => date('Y-m-d H:i:s'),
        'source_table' => 'vfs_main',
        'source_id' => $vfsId,
        'report_reference_table' => 'vfs_main',
        'report_reference_id' => $vfsId,
        'reference_id' => $vfsId,
        'updated_by' => $userId > 0 ? $userId : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($submissionId > 0) {
        $sets = [];

        foreach ($map as $field => $value) {
            if (isset($columns[$field])) {
                $sets[] = "`$field` = " . vfs_sql_value($conn, $value);
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
            $insertValues[] = vfs_sql_value($conn, $value);
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

function vfs_redirect_hub(
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

$employeeId = vfs_employee_id($conn);
$isSuperAdmin = vfs_is_super_admin($conn);

if ($employeeId <= 0) {
    header('Location: login.php');
    exit;
}

if (!vfs_report_access($conn, 'can_submit')) {
    header(
        'Location: reports-hub.php?error='
        . urlencode('You do not have VFS submit access.')
    );
    exit;
}

$employeeQuery = mysqli_query(
    $conn,
    "SELECT e.*, r.role_name AS designation_name
     FROM employees e
     LEFT JOIN roles r ON r.id = e.role_id
     WHERE e.id = $employeeId
     LIMIT 1"
);

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

if (
    isset($_POST['ajax_action'])
    && $_POST['ajax_action'] === 'add_package'
) {
    header('Content-Type: application/json');

    $packageName = trim((string)($_POST['package_name'] ?? ''));
    $category = trim((string)($_POST['category'] ?? 'Other'));

    if ($packageName === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Package name is required.'
        ]);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, package_name
         FROM vfs_packages
         WHERE LOWER(TRIM(package_name)) = LOWER(TRIM(?))
         LIMIT 1"
    );

    mysqli_stmt_bind_param($stmt, 's', $packageName);
    mysqli_stmt_execute($stmt);

    $existing = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    if ($existing) {
        echo json_encode([
            'success' => true,
            'id' => (int)$existing['id'],
            'name' => $existing['package_name'],
            'exists' => true
        ]);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO vfs_packages
        (package_name, category, is_active)
        VALUES (?, ?, 1)"
    );

    mysqli_stmt_bind_param($stmt, 'ss', $packageName, $category);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            'success' => true,
            'id' => (int)mysqli_insert_id($conn),
            'name' => $packageName,
            'exists' => false
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => mysqli_stmt_error($stmt)
        ]);
    }

    mysqli_stmt_close($stmt);
    exit;
}

$projectId = (int)(
    $_GET['project_id']
    ?? $_POST['project_id']
    ?? 0
);

$reportDate = trim((string)(
    $_GET['report_date']
    ?? $_POST['vfs_date']
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
    && vfs_project_allowed(
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
            mpt.project_type_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN master_project_types mpt
            ON mpt.id = p.project_type_id
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

[$pmsStart, $pmsEnd] =
    pms_schedule_date_range($pmsSchedule, $project);

$packages = [];

$packageQuery = mysqli_query($conn, "
    SELECT id, package_name, category
    FROM vfs_packages
    WHERE is_active = 1
    ORDER BY category, package_name
");

while ($packageQuery && ($row = mysqli_fetch_assoc($packageQuery))) {
    $packages[] = $row;
}

$defaultVfsNo = '';

if ($projectId > 0) {
    $prefix = 'VFS/' . $projectId . '/' . date('Ym', strtotime($reportDate)) . '/';
    $prefixEsc = mysqli_real_escape_string($conn, $prefix);

    $countQuery = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM vfs_main
         WHERE vfs_no LIKE '$prefixEsc%'"
    );

    $count = $countQuery
        ? (int)(mysqli_fetch_assoc($countQuery)['total'] ?? 0)
        : 0;

    $defaultVfsNo = $prefix
        . str_pad((string)($count + 1), 3, '0', STR_PAD_LEFT);
}

$existingReport = null;

if ($resubmitSubmissionId > 0 && $projectId > 0) {
    $existingId = vfs_find_existing(
        $conn,
        $resubmitSubmissionId,
        $projectId,
        $employeeId,
        $reportDate
    );

    if ($existingId > 0) {
        $query = mysqli_query(
            $conn,
            "SELECT * FROM vfs_main WHERE id = $existingId LIMIT 1"
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
        "project_id = $projectId AND prepared_by = $employeeId AND vfs_date < '$dateEsc'",
        "project_id = $projectId AND prepared_by = $employeeId",
        "project_id = $projectId"
    ] as $where) {
        $query = mysqli_query($conn, "
            SELECT *
            FROM vfs_main
            WHERE $where
            ORDER BY vfs_date DESC, created_at DESC, id DESC
            LIMIT 1
        ");

        if ($query && ($previousReport = mysqli_fetch_assoc($query))) {
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vfs'])) {
    $postProjectId = (int)($_POST['project_id'] ?? 0);
    $postSubmissionId = (int)($_POST['resubmit_submission_id'] ?? 0);

    $vfsNo = trim((string)($_POST['vfs_no'] ?? ''));
    $vfsDate = trim((string)($_POST['vfs_date'] ?? ''));
    $architects = trim((string)($_POST['architects'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));
    $version = trim((string)($_POST['version'] ?? 'R0'));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $items = json_decode(
        (string)($_POST['items_json'] ?? '[]'),
        true
    );

    $error = '';

    if (
        !vfs_project_allowed(
            $conn,
            $postProjectId,
            $employeeId,
            $isSuperAdmin
        )
    ) {
        $error = 'Invalid project selection.';
    } elseif ($vfsNo === '') {
        $error = 'VFS No is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vfsDate)) {
        $error = 'Valid VFS date is required.';
    } elseif (!is_array($items)) {
        $error = 'Invalid package rows.';
    }

    $items = array_values(array_filter(
        is_array($items) ? $items : [],
        static fn($row) =>
            (int)($row['package_id'] ?? 0) > 0
            || trim((string)($row['package_name'] ?? '')) !== ''
    ));

    if ($error === '' && !$items) {
        $error = 'Please select at least one package.';
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
                $vfsId = vfs_find_existing(
                    $conn,
                    $postSubmissionId,
                    $postProjectId,
                    $employeeId,
                    $vfsDate
                );

                if ($vfsId <= 0) {
                    throw new RuntimeException(
                        'Original VFS not found for resubmission.'
                    );
                }

                $stmt = mysqli_prepare($conn, "
                    UPDATE vfs_main
                    SET
                        vfs_no = ?,
                        project_id = ?,
                        site_id = ?,
                        client_id = ?,
                        project_name = ?,
                        client_name = ?,
                        architects = ?,
                        pmc = ?,
                        version = ?,
                        vfs_date = ?,
                        prepared_by_name = ?,
                        remarks = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                mysqli_stmt_bind_param(
                    $stmt,
                    'siiissssssssi',
                    $vfsNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $architects,
                    $pmc,
                    $version,
                    $vfsDate,
                    $employeeName,
                    $remarks,
                    $vfsId
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                mysqli_stmt_close($stmt);

                mysqli_query(
                    $conn,
                    "DELETE FROM vfs_details WHERE vfs_main_id = $vfsId"
                );
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO vfs_main
                    (
                        vfs_no,
                        project_id,
                        site_id,
                        client_id,
                        project_name,
                        client_name,
                        architects,
                        pmc,
                        version,
                        vfs_date,
                        prepared_by,
                        prepared_by_name,
                        remarks
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                mysqli_stmt_bind_param(
                    $stmt,
                    'siiissssssiss',
                    $vfsNo,
                    $postProjectId,
                    $postProjectId,
                    $clientId,
                    $projectName,
                    $clientName,
                    $architects,
                    $pmc,
                    $version,
                    $vfsDate,
                    $employeeId,
                    $employeeName,
                    $remarks
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }

                $vfsId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            }

            $detailStmt = mysqli_prepare($conn, "
                INSERT INTO vfs_details
                (
                    vfs_main_id,
                    sl_no,
                    package_id,
                    package_name,
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

            foreach ($items as $index => $item) {
                $slNo = $index + 1;
                $packageId = (int)($item['package_id'] ?? 0);
                $packageName = trim((string)($item['package_name'] ?? ''));
                $duration = max(0, (int)($item['duration_days'] ?? 0));
                $startDate = trim((string)($item['start_date'] ?? ''));
                $endDate = trim((string)($item['end_date'] ?? ''));
                $itemRemarks = trim((string)($item['remarks'] ?? ''));

                if ($packageId <= 0 && $packageName !== '') {
                    $checkStmt = mysqli_prepare(
                        $conn,
                        "SELECT id FROM vfs_packages
                         WHERE LOWER(TRIM(package_name)) = LOWER(TRIM(?))
                         LIMIT 1"
                    );

                    mysqli_stmt_bind_param($checkStmt, 's', $packageName);
                    mysqli_stmt_execute($checkStmt);

                    $found = mysqli_fetch_assoc(
                        mysqli_stmt_get_result($checkStmt)
                    );

                    mysqli_stmt_close($checkStmt);

                    if ($found) {
                        $packageId = (int)$found['id'];
                    } else {
                        $insertPackage = mysqli_prepare(
                            $conn,
                            "INSERT INTO vfs_packages
                            (package_name, category, is_active)
                            VALUES (?, 'Other', 1)"
                        );

                        mysqli_stmt_bind_param(
                            $insertPackage,
                            's',
                            $packageName
                        );

                        mysqli_stmt_execute($insertPackage);
                        $packageId = (int)mysqli_insert_id($conn);
                        mysqli_stmt_close($insertPackage);
                    }
                }

                if ($packageId <= 0) {
                    continue;
                }

                mysqli_stmt_bind_param(
                    $detailStmt,
                    'iiisisss',
                    $vfsId,
                    $slNo,
                    $packageId,
                    $packageName,
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

            vfs_sync_submission(
                $conn,
                $postSubmissionId,
                $postProjectId,
                $employeeId,
                $vfsDate,
                $vfsId,
                $vfsNo
            );

            mysqli_commit($conn);

            vfs_redirect_hub(
                $postProjectId,
                $vfsDate,
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
            'Location: vfs.php'
            . '?project_id=' . $postProjectId
            . '&report_date=' . urlencode($vfsDate)
            . '&error=' . urlencode($error)
        );
        exit;
    }
}

$formData = [
    'vfs_no' => $existingReport['vfs_no'] ?? $defaultVfsNo,
    'vfs_date' => $existingReport['vfs_date'] ?? $reportDate,
    'architects' => $existingReport['architects'] ?? '',
    'pmc' => $existingReport['pmc'] ?? '',
    'version' => $existingReport['version'] ?? 'R0',
    'remarks' => $existingReport['remarks'] ?? '',
    'items' => [],
];

if ($existingReport) {
    $query = mysqli_query(
        $conn,
        "SELECT *
         FROM vfs_details
         WHERE vfs_main_id = " . (int)$existingReport['id'] . "
         ORDER BY sl_no, id"
    );

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $formData['items'][] = $row;
    }
}

if (!$formData['items']) {
    $formData['items'] = array_fill(0, 4, [
        'package_id' => 0,
        'package_name' => '',
        'duration_days' => 0,
        'start_date' => '',
        'end_date' => '',
        'remarks' => '',
    ]);
}

$previousPayload = null;

if ($previousReport) {
    $items = [];

    $query = mysqli_query(
        $conn,
        "SELECT *
         FROM vfs_details
         WHERE vfs_main_id = " . (int)$previousReport['id'] . "
         ORDER BY sl_no, id"
    );

    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $items[] = $row;
    }

    $previousPayload = [
        'vfs_no' => $previousReport['vfs_no'] ?? '',
        'vfs_date' => $previousReport['vfs_date'] ?? '',
        'architects' => $previousReport['architects'] ?? '',
        'pmc' => $previousReport['pmc'] ?? '',
        'version' => $previousReport['version'] ?? 'R0',
        'remarks' => $previousReport['remarks'] ?? '',
        'items' => $items,
    ];
}

$recentReports = [];

$query = mysqli_query($conn, "
    SELECT
        v.id,
        v.vfs_no,
        v.vfs_date,
        v.project_name,
        COUNT(d.id) AS package_count
    FROM vfs_main v
    LEFT JOIN vfs_details d ON d.vfs_main_id = v.id
    WHERE v.prepared_by = $employeeId
    GROUP BY v.id
    ORDER BY v.created_at DESC
    LIMIT 10
");

while ($query && ($row = mysqli_fetch_assoc($query))) {
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
    <title>VFS - TEK-C PMC Construction</title>

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

    .vfs-table {
        min-width: 1120px;
    }

    .vfs-table th {
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
                                ? 'Resubmit Vendor Finalization Schedule (VFS)'
                                : 'Vendor Finalization Schedule (VFS)' ?>
                            </h1>

                            <p class="text-muted-custom mb-0 small">
                                Vendor package duration and finalization schedule.
                            </p>
                        </div>

                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <label class="badge-soft mb-0 <?= !$previousPayload ? 'opacity-75' : '' ?>">
                                <input type="checkbox" id="loadPrevious" <?= !$previousPayload ? 'disabled' : '' ?>>

                                <span>
                                    <strong>Load previous data</strong>
                                    <small class="d-block text-muted-custom">
                                        <?= $previousPayload
                                        ? vfs_e($previousPayload['vfs_no'])
                                            . ' · '
                                            . vfs_e(date(
                                                'd M Y',
                                                strtotime($previousPayload['vfs_date'])
                                            ))
                                        : 'No previous data' ?>
                                    </small>
                                </span>
                            </label>

                            <span class="badge-soft">
                                <i data-lucide="user" style="width:15px"></i>
                                <?= vfs_e($employeeName) ?>
                            </span>

                            <span class="badge-soft">
                                <?= vfs_e($designationName) ?>
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
                                    <?= vfs_e($projectOption['project_name']) ?>
                                    <?= !empty($projectOption['project_code'])
                                        ? ' (' . vfs_e($projectOption['project_code']) . ')'
                                        : '' ?>
                                    - <?= vfs_e($projectOption['project_location'] ?: '-') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-3">
                            <a href="vfs.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">
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
                            <div class="fw-bold"><?= vfs_e($project['project_name']) ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Client</small>
                            <div class="fw-bold"><?= vfs_e($project['client_name'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">Location</small>
                            <div class="fw-bold"><?= vfs_e($project['project_location'] ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Schedule</small>
                            <div class="fw-bold">
                                <?= vfs_e($pmsSchedule['schedule_name'] ?? 'PMS Schedule') ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS Start</small>
                            <div class="fw-bold"><?= vfs_e($pmsStart ?: '-') ?></div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted-custom fw-bold">PMS End</small>
                            <div class="fw-bold"><?= vfs_e($pmsEnd ?: '-') ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <form method="POST" id="vfsForm">
                    <input type="hidden" name="submit_vfs" value="1">
                    <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
                    <input type="hidden" name="resubmit_submission_id" value="<?= (int)$resubmitSubmissionId ?>">
                    <input type="hidden" name="items_json" id="items_json">

                    <div class="section-box mb-3">
                        <div class="mini-head">
                            <div class="mini-icon">
                                <i data-lucide="clipboard-check"></i>
                            </div>

                            <div>
                                <h2 class="fw-bold fs-6 mb-1">VFS Header</h2>
                                <p class="text-muted-custom small mb-0">
                                    Report number, version and project-party details.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">VFS No</label>
                                <input class="form-control rounded-4" name="vfs_no"
                                    value="<?= vfs_e($formData['vfs_no']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">VFS Date</label>
                                <input type="date" class="form-control rounded-4" id="vfs_date" name="vfs_date"
                                    value="<?= vfs_e($formData['vfs_date']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Version</label>
                                <input class="form-control rounded-4" id="version" name="version"
                                    placeholder="e.g. R0, R1, V1" value="<?= vfs_e($formData['version']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Architect</label>
                                <input class="form-control rounded-4" id="architects" name="architects"
                                    placeholder="Enter architect name" value="<?= vfs_e($formData['architects']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">PMC</label>
                                <input class="form-control rounded-4" id="pmc" name="pmc" placeholder="Enter PMC name"
                                    value="<?= vfs_e($formData['pmc']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="section-box mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="mini-head mb-0">
                                <div class="mini-icon">
                                    <i data-lucide="package"></i>
                                </div>

                                <div>
                                    <h2 class="fw-bold fs-6 mb-1">
                                        Vendor Finalization Packages
                                    </h2>

                                    <p class="text-muted-custom small mb-0">
                                        Select packages and set their duration and dates.
                                    </p>
                                </div>
                            </div>

                            <button type="button" id="addRow" class="btn btn-outline-primary rounded-4 fw-bold">
                                Add Package
                            </button>
                        </div>

                        <div class="table-responsive thin-scrollbar">
                            <table class="table table-bordered vfs-table">
                                <thead>
                                    <tr>
                                        <th>SL</th>
                                        <th>Package</th>
                                        <th>Duration Days</th>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Remarks</th>
                                        <th>Del</th>
                                    </tr>
                                </thead>

                                <tbody id="vfsBody"></tbody>
                            </table>
                        </div>

                        <div class="mt-3">
                            <label class="form-label fw-bold small">
                                General Remarks
                            </label>

                            <textarea class="form-control rounded-4" id="remarks" name="remarks" rows="3"
                                placeholder="Enter general remarks"><?= vfs_e($formData['remarks']) ?></textarea>
                        </div>
                    </div>

                    <div class="section-box mb-3">
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4"
                                <?= !$project ? 'disabled' : '' ?>>
                                <?= $resubmitSubmissionId > 0
                                ? 'Resubmit VFS'
                                : 'Submit VFS' ?>
                            </button>
                        </div>
                    </div>
                </form>

                <section class="card-ui overflow-hidden">
                    <div class="p-3 p-lg-4">
                        <h2 class="fw-bold fs-6 mb-1">Recent VFS</h2>
                        <p class="text-muted-custom small mb-0">
                            Your latest Vendor Finalization Schedule submissions.
                        </p>
                    </div>

                    <div class="px-3 px-lg-4 pb-4">
                        <div class="row g-2">
                            <?php foreach ($recentReports as $recent): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="recent-card">
                                    <div class="fw-bold">
                                        <?= vfs_e($recent['vfs_no']) ?>
                                    </div>

                                    <small class="text-muted-custom">
                                        <?= vfs_e($recent['project_name']) ?>
                                        ·
                                        <?= vfs_e(date(
                                            'd M Y',
                                            strtotime($recent['vfs_date'])
                                        )) ?>
                                        ·
                                        <?= (int)$recent['package_count'] ?> package(s)
                                    </small>

                                    <br>

                                    <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2" target="_blank"
                                        href="reports-print/report-vfs-print.php?view=<?= (int)$recent['id'] ?>">
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
    <script src="assets/js/script.js?v=46"></script>

    <script>
    const packageOptions =
        <?= json_encode(
        $packages,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

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

    const body = document.getElementById('vfsBody');

    const snapshot = {
        architects: document.getElementById('architects')?.value || '',
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

    function buildPackageOptions(selectedId = 0, selectedName = '') {
        const grouped = {};

        packageOptions.forEach(item => {
            const category = item.category || 'Other';

            if (!grouped[category]) {
                grouped[category] = [];
            }

            grouped[category].push(item);
        });

        let html = '<option value="">-- Select Package --</option>';

        Object.entries(grouped).forEach(([category, items]) => {
            html += `<optgroup label="${escapeHtml(category)}">`;

            items.forEach(item => {
                const selected =
                    Number(item.id) === Number(selectedId) ?
                    'selected' :
                    '';

                html += `
                <option value="${Number(item.id)}"
                        data-name="${escapeHtml(item.package_name)}"
                        ${selected}>
                    ${escapeHtml(item.package_name)}
                </option>
            `;
            });

            html += '</optgroup>';
        });

        if (
            selectedName &&
            !packageOptions.some(item =>
                String(item.package_name).toLowerCase() ===
                String(selectedName).toLowerCase()
            )
        ) {
            html += `
            <option value="0"
                    data-name="${escapeHtml(selectedName)}"
                    selected>
                ${escapeHtml(selectedName)}
            </option>
        `;
        }

        return html;
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
        date.setDate(date.getDate() + duration);

        end.value = date.toISOString().slice(0, 10);
    }

    function addRow(values = {}) {
        const row = document.createElement('tr');

        row.innerHTML = `
        <td class="sl text-center fw-bold"></td>

        <td>
            <select class="form-select rounded-4 package-select">
                ${buildPackageOptions(
                    values.package_id || 0,
                    values.package_name || ''
                )}
            </select>

            <input class="form-control rounded-4 mt-2 custom-package"
                   placeholder="Or type a new package"
                   value="${Number(values.package_id || 0) > 0 ? '' : escapeHtml(values.package_name || '')}">
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
            <input class="form-control rounded-4 item-remarks"
                   placeholder="Enter remarks"
                   value="${escapeHtml(values.remarks || '')}">
        </td>

        <td class="text-center">
            <button type="button"
                    class="btn btn-sm btn-outline-danger rounded-4 delete-row">
                <i data-lucide="trash-2"></i>
            </button>
        </td>
    `;

        body.appendChild(row);

        row.querySelector('.start-date')
            .addEventListener('change', () => calculateEndDate(row));

        row.querySelector('.duration')
            .addEventListener('input', () => calculateEndDate(row));

        row.querySelector('.package-select')
            .addEventListener('change', function() {
                if (this.value) {
                    row.querySelector('.custom-package').value = '';
                }
            });

        renumberRows();
    }

    function renumberRows() {
        [...body.rows].forEach((row, index) => {
            row.querySelector('.sl').textContent = index + 1;
        });

        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    function loadSource(source) {
        document.getElementById('architects').value =
            source?.architects || '';

        document.getElementById('pmc').value =
            source?.pmc || '';

        document.getElementById('version').value =
            source?.version || 'R0';

        document.getElementById('remarks').value =
            source?.remarks || '';

        body.innerHTML = '';

        const items = source?.items?.length ?
            source.items :
            [{}];

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

        if (body.rows.length <= 1) {
            row.querySelectorAll('input').forEach(input => {
                input.value = input.classList.contains('duration') ?
                    '0' :
                    '';
            });

            row.querySelector('.package-select').value = '';
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
                document.getElementById('vfs_date')?.value ||
                '<?= vfs_e($reportDate) ?>';

            window.location.href = this.value ?
                'vfs.php?project_id=' +
                encodeURIComponent(this.value) +
                '&report_date=' +
                encodeURIComponent(date) :
                'vfs.php';
        });

    document.getElementById('vfsForm')
        ?.addEventListener('submit', function() {
            const items = [...body.rows].map((row, index) => {
                const select = row.querySelector('.package-select');
                const custom = row.querySelector('.custom-package').value.trim();
                const option = select.options[select.selectedIndex];

                return {
                    sl_no: index + 1,
                    package_id: Number(select.value || 0),
                    package_name: custom ||
                        option?.dataset?.name ||
                        option?.textContent?.trim() ||
                        '',
                    duration_days: Number(row.querySelector('.duration').value || 0),
                    start_date: row.querySelector('.start-date').value,
                    end_date: row.querySelector('.end-date').value,
                    remarks: row.querySelector('.item-remarks').value
                };
            });

            document.getElementById('items_json').value =
                JSON.stringify(items);
        });
    </script>
</body>

</html>