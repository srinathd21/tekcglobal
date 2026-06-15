<?php
// MOM page uses Reports Hub report permission (master_report_types/report_type_role_access).
// It does not require separate sidebar page permission, so users like Arul with MOM report access can open this page.
session_start();
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/pms-helper.php";
date_default_timezone_set("Asia/Kolkata");

function e($v){ return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8"); }

function current_employee_id($conn) {
    if (!empty($_SESSION["employee_id"])) return (int)$_SESSION["employee_id"];
    if (!empty($_SESSION["user_id"])) {
        $uid = (int)$_SESSION["user_id"];
        $q = mysqli_query($conn, "SELECT employee_id FROM users WHERE id=$uid LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r["employee_id"])) {
            $_SESSION["employee_id"] = (int)$r["employee_id"];
            return (int)$r["employee_id"];
        }
    }
    return 0;
}

function is_super_admin($conn) {
    $uid = (int)($_SESSION["user_id"] ?? 0);
    if ($uid <= 0) return false;
    $q = mysqli_query($conn, "
        SELECT r.id
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = $uid
          AND (r.id = 1 OR r.role_slug = 'super-admin' OR LOWER(r.role_name) = 'super admin')
        LIMIT 1
    ");
    return $q && mysqli_num_rows($q) > 0;
}


function mom_report_permission($conn, $column)
{
    $allowedColumns = ["can_submit", "can_view", "can_remark_tl", "can_remark_manager"];
    if (!in_array($column, $allowedColumns, true)) {
        return false;
    }

    if (is_super_admin($conn)) {
        return true;
    }

    $uid = (int)($_SESSION["user_id"] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    $q = mysqli_query($conn, "
        SELECT MAX(COALESCE(rtra.$column, 0)) AS allowed
        FROM user_roles ur
        INNER JOIN report_type_role_access rtra ON rtra.role_id = ur.role_id
        INNER JOIN master_report_types rt ON rt.id = rtra.report_type_id
        WHERE ur.user_id = $uid
          AND rt.report_code = 'MOM'
          AND rt.is_active = 1
    ");

    return $q && ($row = mysqli_fetch_assoc($q)) && (int)($row["allowed"] ?? 0) === 1;
}

function project_allowed($conn, $projectId, $employeeId, $super) {
    $projectId = (int)$projectId;
    $employeeId = (int)$employeeId;
    if ($projectId <= 0) return false;
    if ($super) {
        $q = mysqli_query($conn, "SELECT id FROM projects WHERE id=$projectId AND deleted_at IS NULL LIMIT 1");
        return $q && mysqli_num_rows($q) > 0;
    }
    $q = mysqli_query($conn, "
        SELECT p.id
        FROM projects p
        WHERE p.id=$projectId
          AND p.deleted_at IS NULL
          AND (
                p.manager_employee_id=$employeeId
             OR p.team_lead_employee_id=$employeeId
             OR EXISTS (
                  SELECT 1
                  FROM project_assignments pa
                  WHERE pa.project_id=p.id
                    AND pa.employee_id=$employeeId
                    AND pa.status='active'
             )
          )
        LIMIT 1
    ");
    return $q && mysqli_num_rows($q) > 0;
}

function log_mom_activity($conn, $type, $desc, $ref = null) {
    $eid = current_employee_id($conn);
    $name = mysqli_real_escape_string($conn, $_SESSION["employee_name"] ?? $_SESSION["name"] ?? "");
    $user = mysqli_real_escape_string($conn, $_SESSION["username"] ?? "");
    $des = mysqli_real_escape_string($conn, $_SESSION["designation"] ?? "");
    $dep = mysqli_real_escape_string($conn, $_SESSION["department"] ?? "");
    $type = mysqli_real_escape_string($conn, strtoupper($type));
    $desc = mysqli_real_escape_string($conn, $desc);
    $refSql = $ref ? (int)$ref : "NULL";
    $ip = mysqli_real_escape_string($conn, $_SERVER["REMOTE_ADDR"] ?? "");
    mysqli_query($conn, "
        INSERT INTO activity_logs
        (employee_id,employee_name,username,designation,department,activity_type,module,description,reference_id,ip_address)
        VALUES ($eid,'$name','$user','$des','$dep','$type','MOM','$desc',$refSql,'$ip')
    ");
}

function generate_mom_no($conn, $projectId) {
    $projectId = (int)$projectId;
    $prefix = "MOM/" . $projectId . "/" . date("Ym") . "/";
    $like = mysqli_real_escape_string($conn, $prefix . "%");
    $q = mysqli_query($conn, "SELECT COUNT(*) cnt FROM mom_main WHERE mom_no LIKE '$like'");
    $r = $q ? mysqli_fetch_assoc($q) : ["cnt" => 0];
    return $prefix . str_pad((string)(((int)$r["cnt"]) + 1), 3, "0", STR_PAD_LEFT);
}

function save_mom_detail($stmt, $mainId, $sectionType, $sectionCode, $subCode, $subTitle, $slNo, $attName, $attFirm, $attType, $desc, $resp, $deadline, $remarks) {
    mysqli_stmt_bind_param(
        $stmt,
        "issssisssssss",
        $mainId,
        $sectionType,
        $sectionCode,
        $subCode,
        $subTitle,
        $slNo,
        $attName,
        $attFirm,
        $attType,
        $desc,
        $resp,
        $deadline,
        $remarks
    );
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Failed to save MOM detail: " . mysqli_stmt_error($stmt));
    }
}


function mom_table_columns($conn, $tableName)
{
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $cols = [];
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$tableName`");
    while ($q && ($row = mysqli_fetch_assoc($q))) {
        $cols[$row["Field"]] = true;
    }
    return $cols;
}

function mom_sql_value($conn, $value)
{
    if ($value === null) return "NULL";
    if (is_int($value) || is_float($value)) return (string)$value;
    return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
}

function mom_report_type_id($conn)
{
    $q = mysqli_query($conn, "SELECT id FROM master_report_types WHERE report_code = 'MOM' LIMIT 1");
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return (int)$row["id"];
    }
    return 0;
}

function mom_mark_report_submitted($conn, $projectId, $employeeId, $momDate, $momId, $momNo)
{
    $projectId = (int)$projectId;
    $employeeId = (int)$employeeId;
    $momId = (int)$momId;
    $reportTypeId = mom_report_type_id($conn);
    $userId = (int)($_SESSION["user_id"] ?? 0);

    if ($projectId <= 0 || $employeeId <= 0 || $momId <= 0 || $reportTypeId <= 0 || $momDate === "") {
        return;
    }

    $dateEsc = mysqli_real_escape_string($conn, $momDate);
    $safeReportNo = trim((string)$momNo);
    if ($safeReportNo === "") {
        $safeReportNo = "MOM-" . $projectId . "-" . str_replace("-", "", $momDate) . "-" . $momId;
    }

    $cols = mom_table_columns($conn, "project_report_submissions");

    $existingConditions = [];
    if (isset($cols["report_reference_table"]) && isset($cols["report_reference_id"])) {
        $existingConditions[] = "(report_reference_table = 'mom_main' AND report_reference_id = $momId)";
    }
    if (isset($cols["source_table"]) && isset($cols["source_id"])) {
        $existingConditions[] = "(source_table = 'mom_main' AND source_id = $momId)";
    }
    if (isset($cols["reference_id"])) {
        $existingConditions[] = "reference_id = $momId";
    }
    if (isset($cols["submission_for_date"])) {
        $existingConditions[] = "(project_id = $projectId AND report_type_id = $reportTypeId AND submission_for_date = '$dateEsc')";
    }

    $targetId = 0;
    if ($existingConditions) {
        $where = implode(" OR ", $existingConditions);
        $q = mysqli_query($conn, "
            SELECT id
            FROM project_report_submissions
            WHERE project_id = $projectId
              AND report_type_id = $reportTypeId
              AND ($where)
            ORDER BY id DESC
            LIMIT 1
        ");
        if ($q && ($row = mysqli_fetch_assoc($q))) {
            $targetId = (int)$row["id"];
        }
    }

    if ($targetId > 0) {
        $sets = [];
        foreach (["report_no", "report_number", "submission_no"] as $col) {
            if (isset($cols[$col])) {
                $sets[] = "`$col` = '" . mysqli_real_escape_string($conn, $safeReportNo) . "'";
            }
        }
        if (isset($cols["status"])) $sets[] = "status = 'submitted'";
        if (isset($cols["submitted_at"])) $sets[] = "submitted_at = NOW()";
        if (isset($cols["submitted_by_employee_id"])) $sets[] = "submitted_by_employee_id = $employeeId";
        if (isset($cols["submitted_by_user_id"])) $sets[] = "submitted_by_user_id = $userId";
        if (isset($cols["submission_for_date"])) $sets[] = "submission_for_date = '$dateEsc'";
        if (isset($cols["period_start"])) $sets[] = "period_start = '$dateEsc'";
        if (isset($cols["period_end"])) $sets[] = "period_end = '$dateEsc'";
        if (isset($cols["reference_id"])) $sets[] = "reference_id = $momId";
        if (isset($cols["source_table"])) $sets[] = "source_table = 'mom_main'";
        if (isset($cols["source_id"])) $sets[] = "source_id = $momId";
        if (isset($cols["report_reference_table"])) $sets[] = "report_reference_table = 'mom_main'";
        if (isset($cols["report_reference_id"])) $sets[] = "report_reference_id = $momId";
        if (isset($cols["updated_by"])) $sets[] = "updated_by = $userId";
        if (isset($cols["updated_at"])) $sets[] = "updated_at = NOW()";

        if ($sets) {
            mysqli_query($conn, "UPDATE project_report_submissions SET " . implode(", ", $sets) . " WHERE id = $targetId");
        }
        return;
    }

    $data = [
        "project_id" => $projectId,
        "report_type_id" => $reportTypeId,
        "report_no" => $safeReportNo,
        "report_number" => $safeReportNo,
        "submission_no" => $safeReportNo,
        "submitted_by_employee_id" => $employeeId,
        "submitted_by_user_id" => $userId > 0 ? $userId : null,
        "submission_for_date" => $momDate,
        "period_start" => $momDate,
        "period_end" => $momDate,
        "status" => "submitted",
        "submitted_at" => date("Y-m-d H:i:s"),
        "reference_id" => $momId,
        "source_table" => "mom_main",
        "source_id" => $momId,
        "report_reference_table" => "mom_main",
        "report_reference_id" => $momId,
        "created_by" => $userId > 0 ? $userId : null,
        "updated_by" => $userId > 0 ? $userId : null,
        "created_at" => date("Y-m-d H:i:s"),
        "updated_at" => date("Y-m-d H:i:s"),
    ];

    $insertCols = [];
    $insertVals = [];
    foreach ($data as $field => $value) {
        if (isset($cols[$field])) {
            $insertCols[] = "`$field`";
            $insertVals[] = mom_sql_value($conn, $value);
        }
    }

    if ($insertCols) {
        $updates = [];
        foreach ($insertCols as $colName) {
            $clean = trim($colName, "`");
            if (!in_array($clean, ["id", "created_at", "created_by"], true)) {
                $updates[] = "`$clean` = VALUES(`$clean`)";
            }
        }

        $sql = "INSERT INTO project_report_submissions (" . implode(",", $insertCols) . ") VALUES (" . implode(",", $insertVals) . ")";
        if ($updates) {
            $sql .= " ON DUPLICATE KEY UPDATE " . implode(", ", $updates);
        }

        mysqli_query($conn, $sql);
    }
}

function mom_redirect_reports_hub($projectId, $momDate)
{
    $projectId = (int)$projectId;
    $date = urlencode((string)$momDate);
    header(
        "Location: reports-hub.php"
        . "?project_id=" . $projectId
        . "&report_date=" . $date
        . "&period_start=" . $date
        . "&period_end=" . $date
        . "&saved=1"
    );
    exit;
}


if (empty($_SESSION["user_id"]) && empty($_SESSION["employee_id"])) {
    header("Location: login.php");
    exit;
}

$employeeId = current_employee_id($conn);
$super = is_super_admin($conn);
$employeeName = $_SESSION["employee_name"] ?? $_SESSION["name"] ?? "";
$designation = $_SESSION["designation"] ?? "";

if (!mom_report_permission($conn, "can_view") && !mom_report_permission($conn, "can_submit")) {
    header("Location: reports-hub.php?error=" . urlencode("You do not have MOM report access."));
    exit;
}

$projectId = (int)($_GET["project_id"] ?? $_POST["project_id"] ?? 0);

$pageMessageType = "";
$pageMessageText = "";
if (isset($_GET["saved"])) {
    $pageMessageType = "success";
    $pageMessageText = "MOM submitted successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) ?: "Something went wrong.";
}

$projectWhere = "p.deleted_at IS NULL";
if (!$super) {
    $projectWhere .= "
        AND (
            p.manager_employee_id=$employeeId
            OR p.team_lead_employee_id=$employeeId
            OR EXISTS (
                SELECT 1 FROM project_assignments pa
                WHERE pa.project_id=p.id AND pa.employee_id=$employeeId AND pa.status='active'
            )
        )
    ";
}

$projects = [];
$pq = mysqli_query($conn, "
    SELECT p.id,p.project_name,p.project_code,p.project_location,p.client_id,c.client_name
    FROM projects p
    LEFT JOIN clients c ON c.id=p.client_id
    WHERE $projectWhere
    ORDER BY p.created_at DESC, p.project_name ASC
");
while ($pq && ($p = mysqli_fetch_assoc($pq))) $projects[] = $p;

$project = null;
$clientId = 0;
if ($projectId > 0 && project_allowed($conn, $projectId, $employeeId, $super)) {
    $q = mysqli_query($conn, "
        SELECT p.*, c.client_name, cd.division_name, mpt.project_type_name
        FROM projects p
        LEFT JOIN clients c ON c.id=p.client_id
        LEFT JOIN company_divisions cd ON cd.id=p.division_id
        LEFT JOIN master_project_types mpt ON mpt.id=p.project_type_id
        WHERE p.id=$projectId
        LIMIT 1
    ");
    $project = $q ? mysqli_fetch_assoc($q) : null;
    $clientId = (int)($project["client_id"] ?? 0);
}

$formMomNo = $projectId > 0 ? generate_mom_no($conn, $projectId) : "";
$formProjectName = $project["project_name"] ?? "";
$formClientName = $project["client_name"] ?? "";
$formPmc = $_POST["pmc"] ?? "M/s. UKB Construction Management Pvt Ltd";

$previousMomTemplate = null;
$previousMomDetails = [];

if ($projectId > 0) {
    /*
     * Load previous MOM data for user-friendly copy support.
     * This loads reusable content only. Unique values like MOM No, MOM Date,
     * Issued Date, project_id and client_id are not overwritten by JS.
     */
    $tplQ = mysqli_query($conn, "
        SELECT *
        FROM mom_main
        WHERE project_id = $projectId
          AND created_by = $employeeId
          AND deleted_at IS NULL
        ORDER BY mom_date DESC, created_at DESC, id DESC
        LIMIT 1
    ");

    if ($tplQ) {
        $previousMomTemplate = mysqli_fetch_assoc($tplQ);
    }

    /*
     * Fallback: show/load previous project report even if the logged-in employee
     * has not submitted MOM before. This keeps the checkbox visible for every user.
     */
    if (!$previousMomTemplate) {
        $tplQ = mysqli_query($conn, "
            SELECT *
            FROM mom_main
            WHERE project_id = $projectId
              AND deleted_at IS NULL
            ORDER BY mom_date DESC, created_at DESC, id DESC
            LIMIT 1
        ");

        if ($tplQ) {
            $previousMomTemplate = mysqli_fetch_assoc($tplQ);
        }
    }

    if ($previousMomTemplate) {
        $tplId = (int)$previousMomTemplate["id"];
        $detQ = mysqli_query($conn, "
            SELECT *
            FROM mom_details
            WHERE mom_main_id = $tplId
            ORDER BY FIELD(section_type,'ATTENDEE','SECTION_I','SECTION_II','SECTION_III','SECTION_IV'),
                     section_code, subsection_code, sl_no, id
        ");

        while ($detQ && ($det = mysqli_fetch_assoc($detQ))) {
            $previousMomDetails[] = $det;
        }
    }
}



$subsections = [
    "A" => "CAPITAL INTERIORS & CONSTRUCTORS (CIVIL WORK)",
    "B" => "MADURAI AIR SYSTEM (HVAC WORK)",
    "C" => "MIRACLE MARBLES (FLOORING WORK)",
    "D" => "SANKAR ELECTRICALS (ELECTRICAL WORK)",
    "E" => "PR PLUMBING WORKS (PLUMBING WORK)",
    "F" => "CRESCENT ENTERPRISES WORKS (FABRICATION WORK)",
    "G" => "JP INTERIOR (PLANNING & FALSE CEILING WORK)"
];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["submit_mom"])) {
    $postProjectId = (int)($_POST["project_id"] ?? 0);
    $postClientId = (int)($_POST["client_id"] ?? 0);
    $momNo = trim($_POST["mom_no"] ?? "");
    $projectName = trim($_POST["project_name"] ?? "");
    $clientName = trim($_POST["client_name"] ?? "");
    $architects = trim($_POST["architects"] ?? "");
    $pmc = trim($_POST["pmc"] ?? "");
    $revisions = trim($_POST["revisions"] ?? "");
    $momDate = trim($_POST["mom_date"] ?? date("Y-m-d"));
    $meetingDatePlace = trim($_POST["meeting_date_place"] ?? "");
    $meetingTime = trim($_POST["meeting_time"] ?? "");
    $agenda = trim($_POST["agenda"] ?? "");
    $issuedBy = trim($_POST["issued_by"] ?? $employeeName);
    $issuedDate = trim($_POST["issued_date"] ?? date("Y-m-d"));

    $err = "";
    if ($postProjectId <= 0 || !project_allowed($conn, $postProjectId, $employeeId, $super)) $err = "Invalid project selection.";
    if ($err === "" && $momNo === "") $momNo = generate_mom_no($conn, $postProjectId);
    if ($err === "" && $projectName === "") $err = "Project Name is required.";
    if ($err === "" && $clientName === "") $err = "Client Name is required.";
    if ($err === "" && $momDate === "") $err = "MOM Date is required.";
    if ($err === "" && $meetingDatePlace === "") $err = "Date / Place is required.";
    if ($err === "" && $meetingTime === "") $err = "Meeting Time is required.";
    if ($err === "" && $issuedBy === "") $err = "Issued By is required.";
    if ($err === "" && $issuedDate === "") $err = "Issued Date is required.";

    if ($err === "") {
        mysqli_begin_transaction($conn);
        try {
            $st = mysqli_prepare($conn, "
                INSERT INTO mom_main
                (mom_no,project_id,client_id,project_name,client_name,architects,pmc,revisions,mom_date,
                 meeting_date_place,meeting_time,agenda,issued_by,issued_date,created_by,created_by_name)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            mysqli_stmt_bind_param(
                $st,
                "siisssssssssssis",
                $momNo,
                $postProjectId,
                $postClientId,
                $projectName,
                $clientName,
                $architects,
                $pmc,
                $revisions,
                $momDate,
                $meetingDatePlace,
                $meetingTime,
                $agenda,
                $issuedBy,
                $issuedDate,
                $employeeId,
                $employeeName
            );
            if (!mysqli_stmt_execute($st)) throw new Exception(mysqli_stmt_error($st));
            $mainId = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($st);

            $dst = mysqli_prepare($conn, "
                INSERT INTO mom_details
                (mom_main_id,section_type,section_code,subsection_code,subsection_title,sl_no,
                 attendee_name,attendee_firm,attendee_type,description,responsible_party,deadline,remarks)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");

            $names = $_POST["attendee_name"] ?? [];
            $firms = $_POST["attendee_firm"] ?? [];
            $types = $_POST["attendee_type"] ?? [];
            $sl = 1;
            foreach ($names as $i => $name) {
                $name = trim((string)$name);
                if ($name === "") continue;
                save_mom_detail($dst, $mainId, "ATTENDEE", "", "", "", $sl++, $name, trim($firms[$i] ?? ""), trim($types[$i] ?? "Others"), null, null, null, null);
            }

            $sections = [
                "SECTION_I" => ["I", "secI"],
                "SECTION_II" => ["II", "secII"],
                "SECTION_IV" => ["IV", "secIV"],
            ];

            foreach ($sections as $type => [$code, $prefix]) {
                $descs = $_POST[$prefix . "_desc"] ?? [];
                $resps = $_POST[$prefix . "_responsible"] ?? [];
                $deads = $_POST[$prefix . "_deadline"] ?? [];
                $remarks = $_POST[$prefix . "_remarks"] ?? [];
                $sl = 1;
                foreach ($descs as $i => $desc) {
                    $desc = trim((string)$desc);
                    if ($desc === "") continue;
                    save_mom_detail($dst, $mainId, $type, $code, "", "", $sl++, null, null, null, $desc, trim($resps[$i] ?? ""), !empty($deads[$i]) ? $deads[$i] : null, trim($remarks[$i] ?? ""));
                }
            }

            $s3sub = $_POST["secIII_subsection"] ?? [];
            $s3title = $_POST["secIII_subsection_title"] ?? [];
            $s3desc = $_POST["secIII_desc"] ?? [];
            $s3resp = $_POST["secIII_responsible"] ?? [];
            $s3dead = $_POST["secIII_deadline"] ?? [];
            $s3rem = $_POST["secIII_remarks"] ?? [];
            $counter = [];
            foreach ($s3desc as $i => $desc) {
                $desc = trim((string)$desc);
                if ($desc === "") continue;
                $sub = trim((string)($s3sub[$i] ?? ""));
                if (!isset($counter[$sub])) $counter[$sub] = 1;
                save_mom_detail($dst, $mainId, "SECTION_III", "III", $sub, trim($s3title[$i] ?? ""), $counter[$sub]++, null, null, null, $desc, trim($s3resp[$i] ?? ""), !empty($s3dead[$i]) ? $s3dead[$i] : null, trim($s3rem[$i] ?? ""));
            }

            mysqli_stmt_close($dst);
            mysqli_commit($conn);
            log_mom_activity($conn, "CREATE", "Submitted MOM " . $momNo, $mainId);
            mom_mark_report_submitted($conn, $postProjectId, $employeeId, $momDate, $mainId, $momNo);

            mom_redirect_reports_hub($postProjectId, $momDate);
        } catch (Exception $ex) {
            mysqli_rollback($conn);
            $err = "Failed to submit MOM: " . $ex->getMessage();
        }
    }

    if ($err !== "") {
        header("Location: mom.php?project_id=" . $postProjectId . "&error=" . urlencode($err));
        exit;
    }
}

$recent = [];
$rq = mysqli_query($conn, "
    SELECT m.id,m.mom_no,m.mom_date,m.project_name,m.client_name,COALESCE(dc.detail_count,0) detail_count
    FROM mom_main m
    LEFT JOIN (SELECT mom_main_id,COUNT(*) detail_count FROM mom_details GROUP BY mom_main_id) dc ON dc.mom_main_id=m.id
    WHERE m.created_by=$employeeId
    ORDER BY m.created_at DESC
    LIMIT 10
");
while ($rq && ($r = mysqli_fetch_assoc($rq))) $recent[] = $r;
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MOM - TEK-C PMS Construction</title>
    <?php include("includes/links.php"); ?>
    <style>
        .page-head-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:16px}
        .section-box{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:22px;box-shadow:var(--shadow-card);padding:18px}
        .mini-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
        .mini-icon{width:44px;height:44px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:rgba(245,158,11,.14);color:#d97706}
        .form-control,.form-select{background:var(--card-bg);color:var(--text-main);border-color:var(--border-soft);min-height:42px;font-size:13px;font-weight:700}
        .mom-table th{font-size:12px;text-transform:uppercase;color:var(--text-muted);font-weight:900;background:rgba(148,163,184,.10);white-space:nowrap}
        .mom-table td{vertical-align:top}
        .badge-soft{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--border-soft);background:rgba(148,163,184,.08);border-radius:999px;padding:7px 12px;font-size:12px;font-weight:900}
        .recent-card,.subsection-card{border:1px solid var(--border-soft);border-radius:18px;padding:12px;background:rgba(148,163,184,.06)}
        .delete-row-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:12px;border:1px solid rgba(220,38,38,.25);background:rgba(220,38,38,.08);color:#dc2626;cursor:pointer}
        .badge-soft input[type="checkbox"]{width:15px;height:15px;flex:0 0 auto}
        .badge-soft span{line-height:1.15}
        @media(max-width:767px){.mom-table{min-width:980px}.section-box{padding:14px}}
    </style>
</head>
<body>
<div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>
<?php include("includes/page-message.php"); ?>

<div class="min-vh-100 d-flex">
    <?php include("includes/sidebar.php"); ?>
    <main id="main">
        <?php include("includes/nav.php"); ?>
        <section class="page-section p-3 p-lg-3">
            <div class="page-head-card mb-3">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                    <div>
                        <h1 class="h4 fw-bold mb-1">Minutes of Meeting (MOM)</h1>
                        <p class="text-muted-custom mb-0 small">Record meeting discussions, decisions, attendees and action items.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <label class="badge-soft mb-0 <?= !$previousMomTemplate ? 'opacity-75' : '' ?>" title="<?= $previousMomTemplate ? 'Load previous submitted MOM data without changing MOM No, dates or project' : 'No previous MOM data found for this project' ?>">
                            <input type="checkbox" class="form-check-input mt-0" id="loadPreviousMomData" <?= !$previousMomTemplate ? 'disabled' : '' ?>>
                            <span>
                                <strong>Load previous data</strong>
                                <small class="d-block text-muted-custom fw-semibold">
                                    <?php if ($previousMomTemplate): ?>
                                        <?= e($previousMomTemplate["mom_no"]) ?> · <?= e(date("d M Y", strtotime($previousMomTemplate["mom_date"]))) ?>
                                    <?php else: ?>
                                        No previous data
                                    <?php endif; ?>
                                </small>
                            </span>
                        </label>
                        <span class="badge-soft"><i data-lucide="user" style="width:15px;height:15px;"></i><?= e($employeeName) ?></span>
                        <span class="badge-soft"><i data-lucide="award" style="width:15px;height:15px;"></i><?= e($designation) ?></span>
                        <a href="mom.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm">Reset</a>
                    </div>
                </div>
            </div>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="submit_mom" value="1">
                <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
                <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
                <input type="hidden" name="project_name" value="<?= e($formProjectName) ?>">
                <input type="hidden" name="client_name" value="<?= e($formClientName) ?>">

                <div class="section-box mb-3">
                    <div class="mini-head"><div class="mini-icon"><i data-lucide="map-pin"></i></div><div><h2 class="fw-bold fs-6 mb-1">Project Selection</h2><p class="text-muted-custom small mb-0">Choose the assigned project to prepare MOM.</p></div></div>
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-9">
                            <label class="form-label fw-bold small">Assigned Project <span class="text-danger">*</span></label>
                            <select class="form-select rounded-4" id="projectPicker">
                                <option value="">-- Select Project --</option>
                                <?php foreach ($projects as $p): ?>
                                    <?php $pid=(int)$p["id"]; $label=trim(($p["project_name"] ?: ("Project #".$pid))." - ".($p["project_location"] ?: "-")." (".($p["client_name"] ?: "-").")"); ?>
                                    <option value="<?= $pid ?>" <?= $pid === $projectId ? "selected" : "" ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-3"><a href="mom.php" class="btn btn-outline-secondary rounded-4 fw-bold w-100">Reset</a></div>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="mini-head"><div class="mini-icon"><i data-lucide="building-2"></i></div><div><h2 class="fw-bold fs-6 mb-1">Project Information</h2><p class="text-muted-custom small mb-0">Auto-filled from selected project.</p></div></div>
                    <?php if (!$project): ?>
                        <p class="text-muted-custom fw-bold mb-0">Please select a project above.</p>
                    <?php else: ?>
                        <div class="row g-3">
                            <div class="col-md-4"><small class="text-muted-custom fw-bold">Project</small><div class="fw-bold"><?= e($project["project_name"]) ?></div></div>
                            <div class="col-md-4"><small class="text-muted-custom fw-bold">Client</small><div class="fw-bold"><?= e($project["client_name"]) ?></div></div>
                            <div class="col-md-4"><small class="text-muted-custom fw-bold">Location</small><div class="fw-bold"><?= e($project["project_location"] ?? "-") ?></div></div>
                            <div class="col-md-4"><small class="text-muted-custom fw-bold">Division</small><div class="fw-bold"><?= e($project["division_name"] ?? "-") ?></div></div>
                            <div class="col-md-4"><small class="text-muted-custom fw-bold">Project Type</small><div class="fw-bold"><?= e($project["project_type_name"] ?? "-") ?></div></div>
                        </div>
                        <hr class="my-3">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label fw-bold small">Architects / Consultants</label><input type="text" name="architects" class="form-control rounded-4" placeholder="Enter architects / consultants"></div>
                            <div class="col-md-6"><label class="form-label fw-bold small">PMS</label><input type="text" name="pmc" class="form-control rounded-4" value="<?= e($formPmc) ?>"></div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="section-box mb-3">
                    <div class="mini-head"><div class="mini-icon"><i data-lucide="file-text"></i></div><div><h2 class="fw-bold fs-6 mb-1">MOM Header</h2><p class="text-muted-custom small mb-0">MOM number, revision, date, place and agenda.</p></div></div>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label fw-bold small">MOM No</label><input type="text" class="form-control rounded-4" name="mom_no" value="<?= e($formMomNo) ?>" readonly></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">MOM No / Revision</label><input type="text" class="form-control rounded-4" name="revisions" placeholder="e.g., REV-01"></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">MOM Date</label><input type="date" class="form-control rounded-4" name="mom_date" value="<?= date("Y-m-d") ?>"></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">Issued Date</label><input type="date" class="form-control rounded-4" name="issued_date" value="<?= date("Y-m-d") ?>"></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">Date / Place <span class="text-danger">*</span></label><input type="text" class="form-control rounded-4" name="meeting_date_place" placeholder="e.g., 03-01-2026 / Site"></div>
                        <div class="col-md-4"><label class="form-label fw-bold small">Meeting Time <span class="text-danger">*</span></label><input type="text" class="form-control rounded-4" name="meeting_time" placeholder="e.g., 12.00 PM to 6.30 PM"></div>
                        <div class="col-md-6"><label class="form-label fw-bold small">Issued By</label><input type="text" class="form-control rounded-4" name="issued_by" value="<?= e($employeeName) ?>"></div>
                        <div class="col-md-6"><label class="form-label fw-bold small">Prepared By</label><input type="text" class="form-control rounded-4" value="<?= e($employeeName) ?>" readonly></div>
                        <div class="col-12"><label class="form-label fw-bold small">Agenda</label><textarea class="form-control rounded-4" name="agenda" rows="2"></textarea></div>
                    </div>
                </div>

                <div class="section-box mb-3">
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-2 mb-3">
                        <div class="mini-head mb-0"><div class="mini-icon"><i data-lucide="users"></i></div><div><h2 class="fw-bold fs-6 mb-1">Attendees</h2><p class="text-muted-custom small mb-0">Meeting participant list.</p></div></div>
                        <button type="button" class="btn btn-outline-primary rounded-4 fw-bold" onclick="addAttendeeRow()">Add Attendee</button>
                    </div>
                    <div class="table-responsive thin-scrollbar">
                        <table class="table table-bordered align-middle mb-0 mom-table">
                            <thead><tr><th style="width:80px;">SL No</th><th>Attendee Name</th><th>Firm</th><th style="width:180px;">Type</th><th style="width:70px;">Del</th></tr></thead>
                            <tbody id="attendeesBody">
                                <tr class="attendee-row">
                                    <td class="text-center fw-bold"><span class="sl-no">1</span></td>
                                    <td><input type="text" class="form-control rounded-4" name="attendee_name[]"></td>
                                    <td><input type="text" class="form-control rounded-4" name="attendee_firm[]" placeholder="Firm"></td>
                                    <td><select class="form-select rounded-4" name="attendee_type[]"><option>Client</option><option>Architect</option><option>Contractor</option><option>PMS</option><option>Vendor</option><option>Others</option></select></td>
                                    <td class="text-center"><button type="button" class="delete-row-btn"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php
                function render_action_section($id,$row,$title,$descName,$respName,$deadName,$remarksName){
                ?>
                <div class="section-box mb-3">
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-2 mb-3">
                        <div class="mini-head mb-0"><div class="mini-icon"><i data-lucide="list-checks"></i></div><div><h2 class="fw-bold fs-6 mb-1"><?= e($title) ?></h2><p class="text-muted-custom small mb-0">Discussion and action items.</p></div></div>
                        <button type="button" class="btn btn-outline-primary rounded-4 fw-bold" onclick="addRow('<?= e($id) ?>','<?= e($row) ?>')">Add Item</button>
                    </div>
                    <div class="table-responsive thin-scrollbar">
                        <table class="table table-bordered align-middle mb-0 mom-table">
                            <thead><tr><th style="width:80px;">SL No</th><th>Description</th><th style="width:180px;">Action By</th><th style="width:150px;">Deadline</th><th style="width:180px;">Remarks</th><th style="width:70px;">Del</th></tr></thead>
                            <tbody id="<?= e($id) ?>">
                                <tr class="<?= e($row) ?>">
                                    <td class="text-center fw-bold"><span class="sl-no">1</span></td>
                                    <td><textarea class="form-control rounded-4" name="<?= e($descName) ?>[]" rows="2"></textarea></td>
                                    <td><input type="text" class="form-control rounded-4" name="<?= e($respName) ?>[]"></td>
                                    <td><input type="date" class="form-control rounded-4" name="<?= e($deadName) ?>[]"></td>
                                    <td><input type="text" class="form-control rounded-4" name="<?= e($remarksName) ?>[]"></td>
                                    <td class="text-center"><button type="button" class="delete-row-btn"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php } ?>

                <?php render_action_section("secIBody","secI-row","I. Architects / Consultants Deliverables","secI_desc","secI_responsible","secI_deadline","secI_remarks"); ?>
                <?php render_action_section("secIIBody","secII-row","II. PMS Deliverables","secII_desc","secII_responsible","secII_deadline","secII_remarks"); ?>

                <div class="section-box mb-3">
                    <div class="mini-head"><div class="mini-icon"><i data-lucide="wrench"></i></div><div><h2 class="fw-bold fs-6 mb-1">III. Contractors / Vendors Deliverables</h2><p class="text-muted-custom small mb-0">Vendor and contractor action items.</p></div></div>
                    <div class="d-grid gap-3">
                        <?php foreach ($subsections as $code => $title): ?>
                            <div class="subsection-card">
                                <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-2 mb-3">
                                    <div><h3 class="fw-bold fs-6 mb-1"><?= e($code) ?>. <?= e($title) ?></h3><p class="text-muted-custom small mb-0">Contractor / vendor discussion items.</p></div>
                                    <button type="button" class="btn btn-outline-primary rounded-4 fw-bold btn-sm" onclick="addRow('secIII<?= e($code) ?>','secIII-row')">Add Item</button>
                                </div>
                                <div class="table-responsive thin-scrollbar">
                                    <table class="table table-bordered align-middle mb-0 mom-table">
                                        <thead><tr><th style="width:80px;">SL No</th><th>Description</th><th style="width:180px;">Action By</th><th style="width:150px;">Deadline</th><th style="width:180px;">Remarks</th><th style="width:70px;">Del</th></tr></thead>
                                        <tbody id="secIII<?= e($code) ?>">
                                            <tr class="secIII-row">
                                                <td class="text-center fw-bold"><span class="sl-no">1</span></td>
                                                <td><textarea class="form-control rounded-4" name="secIII_desc[]" rows="2"></textarea></td>
                                                <td><input type="text" class="form-control rounded-4" name="secIII_responsible[]"></td>
                                                <td><input type="date" class="form-control rounded-4" name="secIII_deadline[]"></td>
                                                <td><input type="text" class="form-control rounded-4" name="secIII_remarks[]"></td>
                                                <td class="text-center"><button type="button" class="delete-row-btn"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>
                                                <input type="hidden" name="secIII_subsection[]" value="<?= e($code) ?>">
                                                <input type="hidden" name="secIII_subsection_title[]" value="<?= e($title) ?>">
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php render_action_section("secIVBody","secIV-row","IV. Others","secIV_desc","secIV_responsible","secIV_deadline","secIV_remarks"); ?>

                <div class="section-box mb-3">
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4" <?= !$project ? "disabled" : "" ?>>Submit MOM</button>
                    </div>
                </div>
            </form>

            <section class="card-ui overflow-hidden">
                <div class="p-3 p-lg-4"><h2 class="fw-bold fs-6 mb-1">Recent MOM</h2><p class="text-muted-custom small mb-0">Your latest MOM submissions.</p></div>
                <div class="px-3 px-lg-4 pb-4">
                    <?php if (!$recent): ?>
                        <p class="text-muted-custom fw-bold mb-0">No MOM submitted yet.</p>
                    <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($recent as $r): ?>
                                <div class="col-md-6 col-xl-4">
                                    <div class="recent-card">
                                        <div class="d-flex justify-content-between gap-2"><div><div class="fw-bold"><?= e($r["mom_no"]) ?></div><small class="text-muted-custom fw-bold"><?= e($r["project_name"]) ?></small></div><span class="pill green"><?= e(date("d M Y", strtotime($r["mom_date"]))) ?></span></div>
                                        <div class="small text-muted-custom fw-bold mt-1"><?= e($r["client_name"]) ?> · <?= (int)$r["detail_count"] ?> items</div>
                                        <a class="btn btn-sm btn-outline-primary rounded-4 fw-bold mt-2" target="_blank" href="reports-print/report-mom-main-print.php?view=<?= (int)$r["id"] ?>">Print</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php include("includes/footer.php"); ?>
        </section>
    </main>
    <div id="settingsOverlay"></div>
    <?php include("includes/rightsidbar.php"); ?>
</div>

<?php include("includes/script.php"); ?>
<script src="assets/js/script.js?v=41"></script>

<script>
const previousMomTemplate = <?php
$tpl = [
    "architects" => $previousMomTemplate["architects"] ?? "",
    "pmc" => $previousMomTemplate["pmc"] ?? "",
    "revisions" => $previousMomTemplate["revisions"] ?? "",
    "meeting_date_place" => $previousMomTemplate["meeting_date_place"] ?? "",
    "meeting_time" => $previousMomTemplate["meeting_time"] ?? "",
    "agenda" => $previousMomTemplate["agenda"] ?? "",
    "issued_by" => $previousMomTemplate["issued_by"] ?? "",
    "attendees" => [],
    "section_i" => [],
    "section_ii" => [],
    "section_iii" => [],
    "section_iv" => []
];

foreach ($previousMomDetails as $d) {
    $type = $d["section_type"] ?? "";

    if ($type === "ATTENDEE") {
        $tpl["attendees"][] = [
            "name" => $d["attendee_name"] ?? "",
            "firm" => $d["attendee_firm"] ?? "",
            "type" => $d["attendee_type"] ?? "Others"
        ];
    } elseif ($type === "SECTION_I") {
        $tpl["section_i"][] = [
            "description" => $d["description"] ?? "",
            "responsible" => $d["responsible_party"] ?? "",
            "deadline" => $d["deadline"] ?? "",
            "remarks" => $d["remarks"] ?? ""
        ];
    } elseif ($type === "SECTION_II") {
        $tpl["section_ii"][] = [
            "description" => $d["description"] ?? "",
            "responsible" => $d["responsible_party"] ?? "",
            "deadline" => $d["deadline"] ?? "",
            "remarks" => $d["remarks"] ?? ""
        ];
    } elseif ($type === "SECTION_III") {
        $tpl["section_iii"][] = [
            "subsection" => $d["subsection_code"] ?? "",
            "subsection_title" => $d["subsection_title"] ?? "",
            "description" => $d["description"] ?? "",
            "responsible" => $d["responsible_party"] ?? "",
            "deadline" => $d["deadline"] ?? "",
            "remarks" => $d["remarks"] ?? ""
        ];
    } elseif ($type === "SECTION_IV") {
        $tpl["section_iv"][] = [
            "description" => $d["description"] ?? "",
            "responsible" => $d["responsible_party"] ?? "",
            "deadline" => $d["deadline"] ?? "",
            "remarks" => $d["remarks"] ?? ""
        ];
    }
}

echo json_encode($previousMomTemplate ? $tpl : null, JSON_UNESCAPED_UNICODE);
?>;
</script>

<script>
document.addEventListener("DOMContentLoaded", function(){
    const picker = document.getElementById("projectPicker");
    if (picker) {
        picker.addEventListener("change", function(){
            location.href = picker.value ? "mom.php?project_id=" + encodeURIComponent(picker.value) : "mom.php";
        });
    }

    function icons(){ if(window.lucide && window.lucide.createIcons) window.lucide.createIcons(); }

    function renumberRows(id){
        const tb = document.getElementById(id);
        if (!tb) return;
        tb.querySelectorAll("tr").forEach(function(row, i){
            const sl = row.querySelector(".sl-no");
            if (sl) sl.textContent = i + 1;
        });
        icons();
    }

    function clearRow(row){
        row.querySelectorAll("input,select,textarea").forEach(function(el){
            if (el.type === "hidden") return;
            if (el.tagName === "SELECT") el.selectedIndex = 0;
            else el.value = "";
        });
    }

    function cloneRow(id, cls){
        const tb = document.getElementById(id);
        if (!tb) return;
        const tpl = tb.querySelector("." + cls);
        if (!tpl) return;
        const row = tpl.cloneNode(true);
        clearRow(row);
        tb.appendChild(row);
        renumberRows(id);
    }

    function setValue(name, value) {
        const el = document.querySelector('[name="' + name + '"]');
        if (el && value !== undefined && value !== null && String(value).trim() !== "") {
            el.value = value;
        }
    }

    function resetTbody(tbodyId, rowClass) {
        const tb = document.getElementById(tbodyId);
        if (!tb) return null;

        const tpl = tb.querySelector("." + rowClass);
        if (!tpl) return null;

        tb.innerHTML = "";
        return { tb, tpl };
    }

    function appendFromTemplate(tbodyId, rowClass, rows, fillCallback) {
        const target = resetTbody(tbodyId, rowClass);
        if (!target) return;

        const dataRows = Array.isArray(rows) && rows.length ? rows : [{}];

        dataRows.forEach(function (item) {
            const row = target.tpl.cloneNode(true);
            clearRow(row);
            fillCallback(row, item || {});
            target.tb.appendChild(row);
        });

        renumberRows(tbodyId);
    }

    function loadPreviousMomData() {
        if (!previousMomTemplate) return;

        /*
         * Reusable values only.
         * Do NOT overwrite unique/current values:
         * - MOM No
         * - MOM Date
         * - Issued Date
         * - Project ID
         * - Client ID
         * - Project Name
         * - Client Name
         */
        setValue("architects", previousMomTemplate.architects);
        setValue("pmc", previousMomTemplate.pmc);
        setValue("revisions", previousMomTemplate.revisions);
        setValue("meeting_date_place", previousMomTemplate.meeting_date_place);
        setValue("meeting_time", previousMomTemplate.meeting_time);
        setValue("agenda", previousMomTemplate.agenda);
        setValue("issued_by", previousMomTemplate.issued_by);

        appendFromTemplate("attendeesBody", "attendee-row", previousMomTemplate.attendees, function (row, item) {
            row.querySelector('[name="attendee_name[]"]').value = item.name || "";
            row.querySelector('[name="attendee_firm[]"]').value = item.firm || "";
            row.querySelector('[name="attendee_type[]"]').value = item.type || "Others";
        });

        appendFromTemplate("secIBody", "secI-row", previousMomTemplate.section_i, function (row, item) {
            row.querySelector('[name="secI_desc[]"]').value = item.description || "";
            row.querySelector('[name="secI_responsible[]"]').value = item.responsible || "";
            row.querySelector('[name="secI_deadline[]"]').value = item.deadline || "";
            row.querySelector('[name="secI_remarks[]"]').value = item.remarks || "";
        });

        appendFromTemplate("secIIBody", "secII-row", previousMomTemplate.section_ii, function (row, item) {
            row.querySelector('[name="secII_desc[]"]').value = item.description || "";
            row.querySelector('[name="secII_responsible[]"]').value = item.responsible || "";
            row.querySelector('[name="secII_deadline[]"]').value = item.deadline || "";
            row.querySelector('[name="secII_remarks[]"]').value = item.remarks || "";
        });

        const sectionIII = Array.isArray(previousMomTemplate.section_iii) ? previousMomTemplate.section_iii : [];
        const groupedIII = {};
        sectionIII.forEach(function (item) {
            const code = item.subsection || "";
            if (!groupedIII[code]) groupedIII[code] = [];
            groupedIII[code].push(item);
        });

        Object.keys(groupedIII).forEach(function (code) {
            const tbodyId = "secIII" + code;
            appendFromTemplate(tbodyId, "secIII-row", groupedIII[code], function (row, item) {
                row.querySelector('[name="secIII_desc[]"]').value = item.description || "";
                row.querySelector('[name="secIII_responsible[]"]').value = item.responsible || "";
                row.querySelector('[name="secIII_deadline[]"]').value = item.deadline || "";
                row.querySelector('[name="secIII_remarks[]"]').value = item.remarks || "";

                const sub = row.querySelector('[name="secIII_subsection[]"]');
                const title = row.querySelector('[name="secIII_subsection_title[]"]');
                if (sub) sub.value = item.subsection || code;
                if (title) title.value = item.subsection_title || "";
            });
        });

        appendFromTemplate("secIVBody", "secIV-row", previousMomTemplate.section_iv, function (row, item) {
            row.querySelector('[name="secIV_desc[]"]').value = item.description || "";
            row.querySelector('[name="secIV_responsible[]"]').value = item.responsible || "";
            row.querySelector('[name="secIV_deadline[]"]').value = item.deadline || "";
            row.querySelector('[name="secIV_remarks[]"]').value = item.remarks || "";
        });
    }

    document.getElementById("loadPreviousMomData")?.addEventListener("change", function () {
        if (this.checked) {
            loadPreviousMomData();
        }
    });


    window.addAttendeeRow = function(){ cloneRow("attendeesBody", "attendee-row"); };
    window.addRow = function(id, cls){ cloneRow(id, cls); };

    document.addEventListener("click", function(e){
        const btn = e.target.closest(".delete-row-btn");
        if (!btn) return;
        const row = btn.closest("tr");
        const tb = row ? row.parentElement : null;
        if (!tb) return;
        if (tb.querySelectorAll("tr").length <= 1) clearRow(row);
        else row.remove();
        renumberRows(tb.id);
    });

    icons();
});
</script>
</body>
</html>
