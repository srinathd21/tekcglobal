<?php
session_start();
require_once __DIR__ . "/../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../attendance.php");
    exit;
}

function clean($v)
{
    return trim($v ?? "");
}

function redirect_attendance($params = [])
{
    header("Location: ../attendance.php" . ($params ? "?" . http_build_query($params) : ""));
    exit;
}

function redirect_error($message)
{
    redirect_attendance(["error" => $message]);
}

function table_exists($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $q && mysqli_num_rows($q) > 0;
}

function column_exists($conn, $table, $column)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && mysqli_num_rows($q) > 0;
}

function get_login_employee_id($conn)
{
    if (!empty($_SESSION["employee_id"])) {
        return (int)$_SESSION["employee_id"];
    }

    if (!empty($_SESSION["user_id"]) && table_exists($conn, "users") && column_exists($conn, "users", "employee_id")) {
        $userId = (int)$_SESSION["user_id"];
        $res = mysqli_query($conn, "SELECT employee_id FROM users WHERE id = $userId LIMIT 1");
        if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row["employee_id"])) {
            $_SESSION["employee_id"] = (int)$row["employee_id"];
            return (int)$row["employee_id"];
        }
    }

    return 0;
}

function existing_attendance($conn, $employeeId, $date)
{
    $stmt = mysqli_prepare($conn, "SELECT id, punch_out_at FROM attendance_records WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "is", $employeeId, $date);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row;
}

function pending_request_exists($conn, $employeeId, $date)
{
    $stmt = mysqli_prepare($conn, "SELECT id FROM attendance_other_location_requests WHERE employee_id = ? AND attendance_date = ? AND status = 'pending' LIMIT 1");
    mysqli_stmt_bind_param($stmt, "is", $employeeId, $date);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? true : false;
}

function distance_in_meters($lat1, $lng1, $lat2, $lng2)
{
    $earthRadius = 6371000;

    $lat1 = deg2rad((float)$lat1);
    $lng1 = deg2rad((float)$lng1);
    $lat2 = deg2rad((float)$lat2);
    $lng2 = deg2rad((float)$lng2);

    $latDelta = $lat2 - $lat1;
    $lngDelta = $lng2 - $lng1;

    $a = sin($latDelta / 2) * sin($latDelta / 2) +
        cos($lat1) * cos($lat2) *
        sin($lngDelta / 2) * sin($lngDelta / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

function get_office_radius_data($conn, $officeLocationId)
{
    $stmt = mysqli_prepare($conn, "
        SELECT id, location_name, latitude, longitude, location_radius
        FROM master_office_locations
        WHERE id = ? AND is_active = 1
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "i", $officeLocationId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        throw new Exception("Selected office location is invalid or inactive.");
    }

    if ($row["latitude"] === null || $row["longitude"] === null || $row["latitude"] === "" || $row["longitude"] === "") {
        throw new Exception("Office location latitude/longitude is not set in master control.");
    }

    $radius = (int)($row["location_radius"] ?? 100);
    if ($radius <= 0) $radius = 100;

    return [
        "name" => $row["location_name"],
        "target_latitude" => (float)$row["latitude"],
        "target_longitude" => (float)$row["longitude"],
        "radius" => $radius
    ];
}

function get_site_radius_data($conn, $projectId, $employeeId)
{
    $stmt = mysqli_prepare($conn, "
        SELECT p.id, p.project_name, p.latitude, p.longitude, p.location_radius
        FROM projects p
        INNER JOIN project_assignments pa ON pa.project_id = p.id
        WHERE p.id = ?
          AND pa.employee_id = ?
          AND pa.status = 'active'
          AND p.deleted_at IS NULL
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "ii", $projectId, $employeeId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        throw new Exception("Selected project site is not assigned to you or inactive.");
    }

    if ($row["latitude"] === null || $row["longitude"] === null || $row["latitude"] === "" || $row["longitude"] === "") {
        throw new Exception("Project site latitude/longitude is not set.");
    }

    $radius = (int)($row["location_radius"] ?? 100);
    if ($radius <= 0) $radius = 100;

    return [
        "name" => $row["project_name"],
        "target_latitude" => (float)$row["latitude"],
        "target_longitude" => (float)$row["longitude"],
        "radius" => $radius
    ];
}

function validate_radius($target, $currentLat, $currentLng, $label)
{
    if ($currentLat === null || $currentLng === null || $currentLat === "" || $currentLng === "") {
        throw new Exception("Current GPS location is required for " . $label . " punch-in.");
    }

    $distance = distance_in_meters($target["target_latitude"], $target["target_longitude"], $currentLat, $currentLng);

    if ($distance > $target["radius"]) {
        throw new Exception(
            "You are outside the " . $label . " punch-in radius. Allowed: " .
            $target["radius"] . "m, your distance: " . round($distance) . "m from " . $target["name"] . "."
        );
    }

    return $distance;
}

function log_activity($conn, $type, $description, $referenceId = null)
{
    if (!table_exists($conn, "activity_logs")) return;

    $employeeName = $_SESSION["employee_name"] ?? "Admin";
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $sessionEmployeeId = $_SESSION["employee_id"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, 'attendance', ?, ?, ?)
    ");
    if ($log) {
        mysqli_stmt_bind_param($log, "issssssis", $sessionEmployeeId, $employeeName, $username, $designation, $department, $type, $description, $referenceId, $ip);
        mysqli_stmt_execute($log);
        mysqli_stmt_close($log);
    }
}

if (!table_exists($conn, "attendance_records") || !table_exists($conn, "attendance_other_location_requests")) {
    redirect_error("Attendance tables are missing. Run attendance_tables.sql first.");
}

$employeeId = get_login_employee_id($conn);
$userId = $_SESSION["user_id"] ?? null;

if ($employeeId <= 0) {
    redirect_error("Employee profile not linked with this user account.");
}

$action = $_POST["action"] ?? "";
$today = date("Y-m-d");
$now = date("Y-m-d H:i:s");

$latitude = clean($_POST["latitude"] ?? "");
$longitude = clean($_POST["longitude"] ?? "");
$address = clean($_POST["address"] ?? "");

$latitudeVal = $latitude === "" ? null : (float)$latitude;
$longitudeVal = $longitude === "" ? null : (float)$longitude;

try {
    if ($action === "office_punch_in" || $action === "site_punch_in") {
        if (existing_attendance($conn, $employeeId, $today)) {
            redirect_error("Attendance already exists for today.");
        }

        if (pending_request_exists($conn, $employeeId, $today)) {
            redirect_error("Other location request already pending for today.");
        }

        $locationType = $action === "office_punch_in" ? "office" : "site";
        $officeLocationId = null;
        $projectId = null;
        $target = null;

        if ($locationType === "office") {
            $officeLocationId = (int)($_POST["office_location_id"] ?? 0);
            if ($officeLocationId <= 0) {
                redirect_error("Please select office location.");
            }
            $target = get_office_radius_data($conn, $officeLocationId);
            $distance = validate_radius($target, $latitudeVal, $longitudeVal, "office");
        }

        if ($locationType === "site") {
            $projectId = (int)($_POST["project_id"] ?? 0);
            if ($projectId <= 0) {
                redirect_error("Please select project site.");
            }
            $target = get_site_radius_data($conn, $projectId, $employeeId);
            $distance = validate_radius($target, $latitudeVal, $longitudeVal, "site");
        }

        $stmt = mysqli_prepare($conn, "
            INSERT INTO attendance_records
            (employee_id, attendance_date, punch_in_at, location_type, office_location_id, project_id,
             target_latitude, target_longitude, allowed_radius_meters, distance_meters,
             latitude, longitude, address, approval_status, approved_by, approved_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?)
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "isssiiddidddsisi",
            $employeeId,
            $today,
            $now,
            $locationType,
            $officeLocationId,
            $projectId,
            $target["target_latitude"],
            $target["target_longitude"],
            $target["radius"],
            $distance,
            $latitudeVal,
            $longitudeVal,
            $address,
            $userId,
            $now,
            $userId
        );

        mysqli_stmt_execute($stmt);
        $attendanceId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        log_activity($conn, "CREATE", ucfirst($locationType) . " punch in", $attendanceId);
        redirect_attendance(["success" => 1]);
    }

    if ($action === "other_punch_request") {
        if (existing_attendance($conn, $employeeId, $today)) {
            redirect_error("Attendance already exists for today.");
        }

        if (pending_request_exists($conn, $employeeId, $today)) {
            redirect_error("Other location request already pending for today.");
        }

        $reason = clean($_POST["reason"] ?? "");
        if ($reason === "") {
            redirect_error("Reason is required for other location punch-in.");
        }

        $stmt = mysqli_prepare($conn, "
            INSERT INTO attendance_other_location_requests
            (employee_id, attendance_date, requested_punch_in_at,
             target_latitude, target_longitude, allowed_radius_meters, distance_meters,
             latitude, longitude, address, reason, status, created_by)
            VALUES (?, ?, ?, NULL, NULL, NULL, NULL, ?, ?, ?, ?, 'pending', ?)
        ");
        mysqli_stmt_bind_param($stmt, "issddssi", $employeeId, $today, $now, $latitudeVal, $longitudeVal, $address, $reason, $userId);
        mysqli_stmt_execute($stmt);
        $requestId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        log_activity($conn, "CREATE", "Submitted other location punch-in request", $requestId);
        redirect_attendance(["pending" => 1]);
    }

    if ($action === "punch_out") {
        $attendance = existing_attendance($conn, $employeeId, $today);

        if (!$attendance) {
            redirect_error("No punch-in found for today.");
        }

        if (!empty($attendance["punch_out_at"])) {
            redirect_error("Already punched out for today.");
        }

        $fullAttendanceStmt = mysqli_prepare($conn, "
            SELECT target_latitude, target_longitude, allowed_radius_meters, location_type
            FROM attendance_records
            WHERE id = ?
            LIMIT 1
        ");
        mysqli_stmt_bind_param($fullAttendanceStmt, "i", $attendance["id"]);
        mysqli_stmt_execute($fullAttendanceStmt);
        $fullAttendance = mysqli_fetch_assoc(mysqli_stmt_get_result($fullAttendanceStmt));
        mysqli_stmt_close($fullAttendanceStmt);

        $punchOutDistance = null;

        if (!empty($fullAttendance["target_latitude"]) && !empty($fullAttendance["target_longitude"]) && !empty($fullAttendance["allowed_radius_meters"])) {
            if ($latitudeVal === null || $longitudeVal === null) {
                redirect_error("Current GPS location is required for punch out.");
            }

            $punchOutDistance = distance_in_meters(
                $fullAttendance["target_latitude"],
                $fullAttendance["target_longitude"],
                $latitudeVal,
                $longitudeVal
            );

            $allowedRadius = (int)$fullAttendance["allowed_radius_meters"];

            if ($punchOutDistance > $allowedRadius) {
                redirect_error(
                    "You are outside the punch-out radius. Allowed: " .
                    $allowedRadius . "m, your distance: " . round($punchOutDistance) . "m."
                );
            }
        }

        $stmt = mysqli_prepare($conn, "
            UPDATE attendance_records
            SET punch_out_at = ?,
                punch_out_latitude = ?,
                punch_out_longitude = ?,
                punch_out_address = ?,
                punch_out_distance_meters = ?,
                updated_by = ?
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($stmt, "sddsiii", $now, $latitudeVal, $longitudeVal, $address, $punchOutDistance, $userId, $attendance["id"]);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        log_activity($conn, "UPDATE", "Punch out", $attendance["id"]);
        redirect_attendance(["success" => 1]);
    }

    redirect_error("Invalid attendance action.");
} catch (Throwable $e) {
    redirect_error($e->getMessage());
}
?>
