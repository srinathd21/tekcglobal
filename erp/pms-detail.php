<?php
require_once __DIR__ . "/includes/db.php";

$PMS_PERMISSION_PAGE = "pms-detail.php";
$PMS_DETAIL_PAGE = basename($_SERVER["PHP_SELF"]);

function pms_detail_logged_employee_id($conn)
{
    if (!empty($_SESSION["employee_id"])) {
        return (int)$_SESSION["employee_id"];
    }

    if (!empty($_SESSION["user_id"])) {
        $userId = (int)$_SESSION["user_id"];
        $q = mysqli_query($conn, "SELECT employee_id FROM users WHERE id = $userId LIMIT 1");
        if ($q && ($row = mysqli_fetch_assoc($q)) && !empty($row["employee_id"])) {
            $_SESSION["employee_id"] = (int)$row["employee_id"];
            return (int)$row["employee_id"];
        }
    }

    return 0;
}

function pms_detail_page_permission($conn, $permissionColumn)
{
    global $PMS_PERMISSION_PAGE;

    $allowedColumns = ["can_view", "can_create", "can_edit", "can_delete", "can_approve"];

    if (!in_array($permissionColumn, $allowedColumns, true) || empty($_SESSION["user_id"])) {
        return false;
    }

    $userId = (int)$_SESSION["user_id"];
    $pageUrl = mysqli_real_escape_string($conn, $PMS_PERMISSION_PAGE);

    $sql = "
        SELECT MAX(rsa.$permissionColumn) AS allowed
        FROM user_roles ur
        INNER JOIN role_sidebar_access rsa ON rsa.role_id = ur.role_id
        INNER JOIN sidebar_menus sm ON sm.id = rsa.menu_id
        WHERE ur.user_id = $userId
          AND sm.menu_url = '$pageUrl'
          AND sm.is_active = 1
    ";

    $q = mysqli_query($conn, $sql);

    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return (int)($row["allowed"] ?? 0) === 1;
    }

    return false;
}

function pms_detail_has_assigned_schedule_access($conn, $scheduleId)
{
    $employeeId = pms_detail_logged_employee_id($conn);

    if ($employeeId <= 0 || $scheduleId <= 0) {
        return false;
    }

    $q = mysqli_query($conn, "
        SELECT pa.id
        FROM project_pmc_schedules s
        INNER JOIN project_assignments pa ON pa.project_id = s.project_id
        INNER JOIN projects p ON p.id = s.project_id
        WHERE s.id = " . (int)$scheduleId . "
          AND pa.employee_id = " . (int)$employeeId . "
          AND pa.status = 'active'
          AND s.schedule_status <> 'cancelled'
          AND p.deleted_at IS NULL
        LIMIT 1
    ");

    return $q && mysqli_num_rows($q) > 0;
}

function pms_detail_project_assignment_role_key($conn, $scheduleId)
{
    $employeeId = pms_detail_logged_employee_id($conn);

    if ($employeeId <= 0 || $scheduleId <= 0) {
        return "";
    }

    $q = mysqli_query($conn, "
        SELECT UPPER(COALESCE(par.role_key, '')) AS role_key
        FROM project_pmc_schedules s
        INNER JOIN project_assignments pa ON pa.project_id = s.project_id
        INNER JOIN project_assignment_roles par ON par.id = pa.assignment_role_id
        WHERE s.id = " . (int)$scheduleId . "
          AND pa.employee_id = " . (int)$employeeId . "
          AND pa.status = 'active'
        ORDER BY pa.is_primary DESC, pa.id ASC
        LIMIT 1
    ");

    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return strtoupper(trim($row["role_key"] ?? ""));
    }

    return "";
}

function pms_detail_is_project_engineer_view_only($conn, $scheduleId)
{
    return pms_detail_project_assignment_role_key($conn, $scheduleId) === "PROJECT_ENGINEER";
}

function pmc_e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function pmc_redirect($params = [])
{
    $query = http_build_query($params);
    header("Location: pmsdetail.php" . ($query ? "?" . $query : ""));
    exit;
}

function pmc_date_show($date)
{
    return ($date && $date !== "0000-00-00") ? $date : "-";
}

function pmc_days_label($days)
{
    $days = (int)$days;
    return $days . " day" . ($days === 1 ? "" : "s");
}

function pmc_status_class($status)
{
    $status = strtolower(trim($status ?? ""));
    if ($status === "completed") return "green";
    if ($status === "ongoing" || $status === "active") return "blue";
    if ($status === "cancelled") return "red";
    return "amber";
}

function pmc_log_activity($conn, $type, $description, $referenceId = null)
{
    $employeeId = $_SESSION["employee_id"] ?? null;
    $employeeName = $_SESSION["employee_name"] ?? ($_SESSION["name"] ?? "Admin");
    $username = $_SESSION["username"] ?? null;
    $designation = $_SESSION["designation"] ?? null;
    $department = $_SESSION["department"] ?? null;
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;

    $stmt = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, 'pmc', ?, ?, ?)
    ");

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "issssssis", $employeeId, $employeeName, $username, $designation, $department, $type, $description, $referenceId, $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function pmc_parent_level($conn, $parentId)
{
    if (!$parentId) return 0;

    $parentId = (int)$parentId;
    $q = mysqli_query($conn, "
        SELECT hierarchy_level
        FROM project_pmc_schedule_items
        WHERE id = $parentId
          AND item_type = 'topic'
          AND is_active = 1
        LIMIT 1
    ");

    if (!$q || mysqli_num_rows($q) === 0) return 0;

    $row = mysqli_fetch_assoc($q);
    return (int)$row["hierarchy_level"];
}

function pmc_next_sort_order($conn, $scheduleId, $parentId)
{
    $scheduleId = (int)$scheduleId;

    if ($parentId === null || $parentId === "") {
        $where = "parent_id IS NULL";
    } else {
        $where = "parent_id = " . (int)$parentId;
    }

    $q = mysqli_query($conn, "
        SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order
        FROM project_pmc_schedule_items
        WHERE schedule_id = $scheduleId
          AND $where
          AND is_active = 1
    ");

    if (!$q) return 1;

    $row = mysqli_fetch_assoc($q);
    return (int)($row["next_order"] ?? 1);
}

function pmc_recalculate_rollups($conn, $scheduleId)
{
    $scheduleId = (int)$scheduleId;

    if ($scheduleId <= 0) return;

    for ($i = 0; $i < 8; $i++) {
        mysqli_query($conn, "
            UPDATE project_pmc_schedule_items parent
            INNER JOIN (
                SELECT
                    child.parent_id,
                    MIN(child.planned_start_date) AS child_start,
                    MAX(child.planned_end_date) AS child_end,
                    ROUND(AVG(child.progress_percent), 2) AS child_progress,
                    CASE
                        WHEN SUM(child.item_status = 'ongoing') > 0 THEN 'ongoing'
                        WHEN SUM(child.item_status = 'pending') > 0 THEN 'pending'
                        ELSE 'completed'
                    END AS child_status
                FROM project_pmc_schedule_items child
                WHERE child.schedule_id = $scheduleId
                  AND child.is_active = 1
                  AND child.parent_id IS NOT NULL
                GROUP BY child.parent_id
            ) rollup ON rollup.parent_id = parent.id
            SET
                parent.planned_start_date = rollup.child_start,
                parent.planned_end_date = rollup.child_end,
                parent.progress_percent = COALESCE(rollup.child_progress, parent.progress_percent),
                parent.item_status = rollup.child_status
            WHERE parent.schedule_id = $scheduleId
              AND parent.item_type = 'topic'
              AND parent.is_active = 1
        ");
    }

    mysqli_query($conn, "
        UPDATE project_pmc_schedules s
        SET
            s.overall_start_date = (
                SELECT MIN(i.planned_start_date)
                FROM project_pmc_schedule_items i
                WHERE i.schedule_id = s.id AND i.is_active = 1
            ),
            s.overall_end_date = (
                SELECT MAX(i.planned_end_date)
                FROM project_pmc_schedule_items i
                WHERE i.schedule_id = s.id AND i.is_active = 1
            ),
            s.overall_duration_days = (
                SELECT CASE
                    WHEN MIN(i.planned_start_date) IS NOT NULL
                     AND MAX(i.planned_end_date) IS NOT NULL
                    THEN DATEDIFF(MAX(i.planned_end_date), MIN(i.planned_start_date)) + 1
                    ELSE 0
                END
                FROM project_pmc_schedule_items i
                WHERE i.schedule_id = s.id AND i.is_active = 1
            ),
            s.schedule_status = (
                SELECT CASE
                    WHEN COUNT(i.id) = 0 THEN s.schedule_status
                    WHEN SUM(i.item_status = 'ongoing') > 0 THEN 'ongoing'
                    WHEN SUM(i.item_status = 'pending') > 0 THEN 'pending'
                    ELSE 'completed'
                END
                FROM project_pmc_schedule_items i
                WHERE i.schedule_id = s.id AND i.is_active = 1
            )
        WHERE s.id = $scheduleId
    ");
}

function pmc_collect_child_ids($itemsByParent, $parentId, &$ids)
{
    if (empty($itemsByParent[$parentId])) return;

    foreach ($itemsByParent[$parentId] as $child) {
        $ids[] = (int)$child["id"];
        pmc_collect_child_ids($itemsByParent, (int)$child["id"], $ids);
    }
}

function pmc_render_items($itemsByParent, $parentId, $depth, $canEdit, $canDelete)
{
    if (empty($itemsByParent[$parentId])) return;

    foreach ($itemsByParent[$parentId] as $item) {
        $isTopic = $item["item_type"] === "topic";
        $rowJson = htmlspecialchars(json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8");
        $indent = min($depth * 24, 120);
        $rowClass = $isTopic ? "pmc-topic-row level-" . (int)$item["hierarchy_level"] : "pmc-task-row";
        ?>
        <tr class="<?= pmc_e($rowClass) ?>">
            <td>
                <div class="d-flex align-items-start gap-2" style="padding-left: <?= (int)$indent ?>px;">
                    <span class="pmc-tree-dot <?= $isTopic ? 'topic' : 'task' ?>"></span>
                    <div class="min-w-0">
                        <div class="<?= $isTopic ? 'fw-black' : 'fw-semibold' ?> text-break">
                            <?= pmc_e($item["title"]) ?>
                            <?php if ((int)$item["is_milestone"] === 1): ?>
                                <span class="badge rounded-pill bg-warning-subtle text-warning ms-1">Milestone</span>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted-custom"><?= $isTopic ? "Topic Level " . (int)$item["hierarchy_level"] : "Content / Task" ?></small>
                    </div>
                </div>
            </td>
            <td class="fw-bold"><?= pmc_days_label($item["duration_days"]) ?></td>
            <td><?= pmc_e(pmc_date_show($item["planned_start_date"])) ?></td>
            <td><?= pmc_e(pmc_date_show($item["planned_end_date"])) ?></td>
            <td><span class="pill <?= pmc_status_class($item["item_status"]) ?>"><?= pmc_e(ucfirst($item["item_status"])) ?></span></td>
            <td>
                <div class="progress pmc-progress">
                    <div class="progress-bar" style="width: <?= (float)$item["progress_percent"] ?>%;"></div>
                </div>
                <small class="text-muted-custom fw-bold"><?= (float)$item["progress_percent"] ?>%</small>
            </td>
            <td class="fw-bold"><?= (int)$item["sort_order"] ?></td>
            <td>
                <div class="d-flex flex-wrap gap-1">
                    <?php if ($canEdit): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                            data-bs-toggle="modal" data-bs-target="#itemModal"
                            onclick='openEditItemModal(<?= $rowJson ?>)'>Edit</button>

                        <?php if (!$isTopic): ?>
                            <button type="button" class="btn btn-sm btn-outline-warning rounded-4 fw-bold"
                                data-bs-toggle="modal" data-bs-target="#dateChangeModal"
                                onclick='openDateChangeModal(<?= $rowJson ?>)'>End Date</button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($canDelete): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                            data-bs-toggle="modal" data-bs-target="#deleteItemModal"
                            onclick='openDeleteItemModal(<?= $rowJson ?>)'>Delete</button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
        pmc_render_items($itemsByParent, (int)$item["id"], $depth + 1, $canEdit, $canDelete);
    }
}

$scheduleId = (int)($_GET["schedule_id"] ?? $_POST["schedule_id"] ?? 0);

if ($scheduleId <= 0) {
    header("Location: pms.php?error=" . urlencode("Select a Project Master Schedule."));
    exit;
}

if (!pms_detail_page_permission($conn, "can_view") && !pms_detail_has_assigned_schedule_access($conn, $scheduleId)) {
    http_response_code(403);
    include __DIR__ . "/403.php";
    exit;
}

/* DB BASED PERMISSION NOTE: Add/Edit/Delete buttons are controlled only by role_sidebar_access for sidebar_menus.menu_url = pms-detail.php. */
$canPmcCreate = pms_detail_page_permission($conn, "can_create");
$canPmcEdit = pms_detail_page_permission($conn, "can_edit");
$canPmcDelete = pms_detail_page_permission($conn, "can_delete");

$currentUserId = $_SESSION["user_id"] ?? null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    try {
        if ($action === "save_item") {
            $itemId = (int)($_POST["item_id"] ?? 0);

            if ($itemId > 0) {
                if (!pms_detail_page_permission($conn, "can_edit")) { throw new Exception("You do not have permission to edit PMS items."); }
            } else {
                if (!pms_detail_page_permission($conn, "can_create")) { throw new Exception("You do not have permission to create PMS items."); }
            }

            $projectId = (int)($_POST["project_id"] ?? 0);
            $parentIdRaw = trim($_POST["parent_id"] ?? "");
            $parentId = $parentIdRaw === "" ? null : (int)$parentIdRaw;
            $itemType = trim($_POST["item_type"] ?? "topic");
            $title = trim($_POST["title"] ?? "");
            $description = trim($_POST["item_description"] ?? "");
            $plannedStart = trim($_POST["planned_start_date"] ?? "");
            $plannedEnd = trim($_POST["planned_end_date"] ?? "");
            $actualStart = trim($_POST["actual_start_date"] ?? "");
            $actualEnd = trim($_POST["actual_end_date"] ?? "");
            $progress = (float)($_POST["progress_percent"] ?? 0);
            $status = trim($_POST["item_status"] ?? "pending");
            $sortOrder = (int)($_POST["sort_order"] ?? 0);
            $isMilestone = isset($_POST["is_milestone"]) ? 1 : 0;

            if ($projectId <= 0 || $title === "") {
                throw new Exception("Project and title are required.");
            }

            if (!in_array($itemType, ["topic", "task"], true)) $itemType = "topic";
            if (!in_array($status, ["pending", "ongoing", "completed"], true)) $status = "pending";

            if ($progress < 0 || $progress > 100) {
                throw new Exception("Progress must be between 0 and 100.");
            }

            $plannedStart = $plannedStart === "" ? null : $plannedStart;
            $plannedEnd = $plannedEnd === "" ? null : $plannedEnd;
            $actualStart = $actualStart === "" ? null : $actualStart;
            $actualEnd = $actualEnd === "" ? null : $actualEnd;

            if ($plannedStart && $plannedEnd && $plannedEnd < $plannedStart) {
                throw new Exception("Planned finish cannot be earlier than planned start.");
            }

            if ($actualStart && $actualEnd && $actualEnd < $actualStart) {
                throw new Exception("Actual finish cannot be earlier than actual start.");
            }

            if ($itemType === "task" && (!$plannedStart || !$plannedEnd)) {
                throw new Exception("Content / Task must have planned start and finish dates.");
            }

            if ($parentId) {
                $parentLevel = pmc_parent_level($conn, $parentId);

                if ($parentLevel <= 0) {
                    throw new Exception("Parent must be a valid topic.");
                }

                $hierarchyLevel = $parentLevel + 1;
            } else {
                $hierarchyLevel = 1;
            }

            if ($itemId > 0 && $parentId === $itemId) {
                throw new Exception("Item cannot be its own parent.");
            }

            if ($sortOrder <= 0) {
                $sortOrder = pmc_next_sort_order($conn, $scheduleId, $parentId);
            }

            if ($itemId > 0) {
                $stmt = mysqli_prepare($conn, "
                    UPDATE project_pmc_schedule_items
                    SET parent_id = ?, item_type = ?, hierarchy_level = ?, title = ?, description = ?,
                        planned_start_date = ?, planned_end_date = ?, actual_start_date = ?, actual_end_date = ?,
                        progress_percent = ?, item_status = ?, sort_order = ?, is_milestone = ?, updated_by = ?
                    WHERE id = ? AND schedule_id = ?
                ");
                mysqli_stmt_bind_param(
                    $stmt,
                    "isissssssdsiiiii",
                    $parentId,
                    $itemType,
                    $hierarchyLevel,
                    $title,
                    $description,
                    $plannedStart,
                    $plannedEnd,
                    $actualStart,
                    $actualEnd,
                    $progress,
                    $status,
                    $sortOrder,
                    $isMilestone,
                    $currentUserId,
                    $itemId,
                    $scheduleId
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                pmc_log_activity($conn, "UPDATE", "Updated Project Master Scheduleschedule item", $itemId);
            } else {
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO project_pmc_schedule_items
                    (schedule_id, project_id, parent_id, item_type, hierarchy_level, title, description,
                     planned_start_date, planned_end_date, actual_start_date, actual_end_date,
                     progress_percent, item_status, sort_order, is_milestone, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                mysqli_stmt_bind_param(
                    $stmt,
                    "iiisissssssdsiis",
                    $scheduleId,
                    $projectId,
                    $parentId,
                    $itemType,
                    $hierarchyLevel,
                    $title,
                    $description,
                    $plannedStart,
                    $plannedEnd,
                    $actualStart,
                    $actualEnd,
                    $progress,
                    $status,
                    $sortOrder,
                    $isMilestone,
                    $currentUserId
                );
                mysqli_stmt_execute($stmt);
                $itemId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                pmc_log_activity($conn, "CREATE", "Created Project Master Scheduleschedule item", $itemId);
            }

            pmc_recalculate_rollups($conn, $scheduleId);
            pmc_redirect(["schedule_id" => $scheduleId, "success" => 1]);
        }

        if ($action === "bulk_save_items") {
            if (!pms_detail_page_permission($conn, "can_create")) { throw new Exception("You do not have permission to create PMS items."); }

            $projectId = (int)($_POST["project_id"] ?? 0);
            $rows = $_POST["bulk"] ?? [];

            if ($projectId <= 0 || !is_array($rows) || empty($rows)) {
                throw new Exception("Add at least one bulk row.");
            }

            $createdCount = 0;

            foreach ($rows as $row) {
                $title = trim($row["title"] ?? "");

                if ($title === "") {
                    continue;
                }

                $itemType = trim($row["item_type"] ?? "topic");
                $parentIdRaw = trim($row["parent_id"] ?? "");
                $parentId = $parentIdRaw === "" ? null : (int)$parentIdRaw;
                $plannedStart = trim($row["planned_start_date"] ?? "");
                $plannedEnd = trim($row["planned_end_date"] ?? "");
                $progress = (float)($row["progress_percent"] ?? 0);
                $status = trim($row["item_status"] ?? "pending");

                if (!in_array($itemType, ["topic", "task"], true)) $itemType = "topic";
                if (!in_array($status, ["pending", "ongoing", "completed"], true)) $status = "pending";

                if ($progress < 0) $progress = 0;
                if ($progress > 100) $progress = 100;

                $plannedStart = $plannedStart === "" ? null : $plannedStart;
                $plannedEnd = $plannedEnd === "" ? null : $plannedEnd;

                if ($plannedStart && $plannedEnd && $plannedEnd < $plannedStart) {
                    throw new Exception("Bulk row finish date cannot be earlier than start date.");
                }

                if ($itemType === "task" && (!$plannedStart || !$plannedEnd)) {
                    throw new Exception("Bulk Content / Task rows require start and finish dates.");
                }

                if ($parentId) {
                    $parentLevel = pmc_parent_level($conn, $parentId);
                    if ($parentLevel <= 0) {
                        throw new Exception("Bulk parent must be a valid topic.");
                    }
                    $hierarchyLevel = $parentLevel + 1;
                } else {
                    $hierarchyLevel = 1;
                }

                $sortOrder = pmc_next_sort_order($conn, $scheduleId, $parentId);

                $stmt = mysqli_prepare($conn, "
                    INSERT INTO project_pmc_schedule_items
                    (schedule_id, project_id, parent_id, item_type, hierarchy_level, title,
                     planned_start_date, planned_end_date, progress_percent, item_status, sort_order, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                mysqli_stmt_bind_param(
                    $stmt,
                    "iiisisssdsii",
                    $scheduleId,
                    $projectId,
                    $parentId,
                    $itemType,
                    $hierarchyLevel,
                    $title,
                    $plannedStart,
                    $plannedEnd,
                    $progress,
                    $status,
                    $sortOrder,
                    $currentUserId
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $createdCount++;
            }

            if ($createdCount <= 0) {
                throw new Exception("No valid bulk rows to save.");
            }

            pmc_log_activity($conn, "CREATE", "Bulk created $createdCount Project Master Scheduleschedule items", $scheduleId);
            pmc_recalculate_rollups($conn, $scheduleId);
            pmc_redirect(["schedule_id" => $scheduleId, "success" => 1]);
        }

        if ($action === "change_end_date") {
            if (!pms_detail_page_permission($conn, "can_edit")) { throw new Exception("You do not have permission to edit PMS items."); }

            $itemId = (int)($_POST["item_id"] ?? 0);
            $projectId = (int)($_POST["project_id"] ?? 0);
            $newEndDate = trim($_POST["new_planned_end_date"] ?? "");
            $remark = trim($_POST["remark"] ?? "");

            if ($itemId <= 0 || $projectId <= 0 || $newEndDate === "" || $remark === "") {
                throw new Exception("New end date and remark are required.");
            }

            $itemQ = mysqli_query($conn, "
                SELECT planned_start_date, planned_end_date, item_type
                FROM project_pmc_schedule_items
                WHERE id = $itemId
                  AND schedule_id = $scheduleId
                  AND is_active = 1
                LIMIT 1
            ");
            $item = $itemQ ? mysqli_fetch_assoc($itemQ) : null;

            if (!$item) throw new Exception("Project Master Scheduleitem not found.");
            if ($item["item_type"] !== "task") throw new Exception("End date change is allowed only for Content / Task rows.");
            if ($item["planned_start_date"] && $newEndDate < $item["planned_start_date"]) throw new Exception("New finish date cannot be earlier than start date.");

            $oldEndDate = $item["planned_end_date"];

            mysqli_begin_transaction($conn);

            $stmt = mysqli_prepare($conn, "
                INSERT INTO project_pmc_schedule_date_changes
                (schedule_id, project_id, item_id, old_planned_end_date, new_planned_end_date, remark, changed_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            mysqli_stmt_bind_param($stmt, "iiisssi", $scheduleId, $projectId, $itemId, $oldEndDate, $newEndDate, $remark, $currentUserId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "
                UPDATE project_pmc_schedule_items
                SET planned_end_date = ?, updated_by = ?
                WHERE id = ? AND schedule_id = ?
            ");
            mysqli_stmt_bind_param($stmt, "siii", $newEndDate, $currentUserId, $itemId, $scheduleId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            pmc_log_activity($conn, "UPDATE", "Changed Project Master Scheduletask end date with remark", $itemId);

            pmc_recalculate_rollups($conn, $scheduleId);
            mysqli_commit($conn);

            pmc_redirect(["schedule_id" => $scheduleId, "success" => 1]);
        }

        if ($action === "delete_item") {
            if (!pms_detail_page_permission($conn, "can_delete")) { throw new Exception("You do not have permission to delete PMS items."); }

            $itemId = (int)($_POST["item_id"] ?? 0);
            if ($itemId <= 0) throw new Exception("Invalid item.");

            $byParent = [];
            $q = mysqli_query($conn, "
                SELECT id, parent_id
                FROM project_pmc_schedule_items
                WHERE schedule_id = $scheduleId
                  AND is_active = 1
            ");

            if ($q) {
                while ($row = mysqli_fetch_assoc($q)) {
                    $pid = $row["parent_id"] ? (int)$row["parent_id"] : 0;
                    $byParent[$pid][] = $row;
                }
            }

            $ids = [$itemId];
            pmc_collect_child_ids($byParent, $itemId, $ids);
            $ids = array_values(array_unique(array_map("intval", $ids)));
            $idsSql = implode(",", $ids);

            mysqli_query($conn, "
                UPDATE project_pmc_schedule_items
                SET is_active = 0, updated_by = " . (int)$currentUserId . "
                WHERE schedule_id = $scheduleId
                  AND id IN ($idsSql)
            ");

            pmc_log_activity($conn, "DELETE", "Deleted Project Master Scheduleitem and child items", $itemId);
            pmc_recalculate_rollups($conn, $scheduleId);

            pmc_redirect(["schedule_id" => $scheduleId, "deleted" => 1]);
        }

        throw new Exception("Invalid action.");
    } catch (Throwable $e) {
        if (mysqli_errno($conn)) {
            @mysqli_rollback($conn);
        }

        pmc_redirect(["schedule_id" => $scheduleId, "error" => $e->getMessage()]);
    }
}

pmc_recalculate_rollups($conn, $scheduleId);

$scheduleAccessSql = "1=1";

if (!pms_detail_page_permission($conn, "can_view")) {
    $loggedEmployeeId = pms_detail_logged_employee_id($conn);
    $scheduleAccessSql = "EXISTS (
        SELECT 1
        FROM project_assignments pa_view
        WHERE pa_view.project_id = s.project_id
          AND pa_view.employee_id = " . (int)$loggedEmployeeId . "
          AND pa_view.status = 'active'
    )";
}

$scheduleQ = mysqli_query($conn, "
    SELECT s.*, p.project_name, p.project_code, p.project_location
    FROM project_pmc_schedules s
    INNER JOIN projects p ON p.id = s.project_id
    WHERE s.id = $scheduleId
      AND s.schedule_status <> 'cancelled'
      AND p.deleted_at IS NULL
      AND $scheduleAccessSql
    LIMIT 1
");

$schedule = $scheduleQ ? mysqli_fetch_assoc($scheduleQ) : null;

if (!$schedule) {
    header("Location: pms.php?error=" . urlencode("Project Master Scheduleschedule not found."));
    exit;
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["success"])) {
    $pageMessageType = "success";
    $pageMessageText = "Project Master Scheduledetail saved successfully.";
} elseif (isset($_GET["deleted"])) {
    $pageMessageType = "success";
    $pageMessageText = "Project Master Scheduleitem deleted successfully.";
} elseif (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$items = [];
$itemsByParent = [];
$topicOptions = [];

$itemsQ = mysqli_query($conn, "
    SELECT *
    FROM project_pmc_schedule_items
    WHERE schedule_id = $scheduleId
      AND is_active = 1
    ORDER BY COALESCE(parent_id, 0) ASC, sort_order ASC, id ASC
");

if ($itemsQ) {
    while ($item = mysqli_fetch_assoc($itemsQ)) {
        $items[] = $item;
        $parentKey = $item["parent_id"] ? (int)$item["parent_id"] : 0;
        $itemsByParent[$parentKey][] = $item;

        if ($item["item_type"] === "topic") {
            $topicOptions[] = $item;
        }
    }
}

$dateChanges = [];
$changesQ = mysqli_query($conn, "
    SELECT dc.*, i.title AS item_title, u.name AS changed_by_name
    FROM project_pmc_schedule_date_changes dc
    INNER JOIN project_pmc_schedule_items i ON i.id = dc.item_id
    LEFT JOIN users u ON u.id = dc.changed_by
    WHERE dc.schedule_id = $scheduleId
    ORDER BY dc.id DESC
    LIMIT 10
");

if ($changesQ) {
    while ($row = mysqli_fetch_assoc($changesQ)) {
        $dateChanges[] = $row;
    }
}

$stats = [
    "topics" => (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM project_pmc_schedule_items WHERE schedule_id = $scheduleId AND is_active = 1 AND item_type = 'topic'"))["total"] ?? 0),
    "tasks" => (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM project_pmc_schedule_items WHERE schedule_id = $scheduleId AND is_active = 1 AND item_type = 'task'"))["total"] ?? 0),
    "pending" => (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM project_pmc_schedule_items WHERE schedule_id = $scheduleId AND is_active = 1 AND item_status = 'pending'"))["total"] ?? 0),
    "completed" => (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM project_pmc_schedule_items WHERE schedule_id = $scheduleId AND is_active = 1 AND item_status = 'completed'"))["total"] ?? 0),
];
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Project Master Schedule Detail - TEK-C Project Master Schedule Construction</title>
    <?php include("includes/links.php"); ?>

    <style>
        .page-head-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px;
        }

        .kpi-row>[class*="col"] { display: flex; }

        .kpi-card {
            width: 100%;
            min-height: 108px;
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 24px;
            box-shadow: var(--shadow-card);
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .kpi-icon {
            width: 54px;
            height: 54px;
            min-width: 54px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .kpi-label {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 800;
            white-space: nowrap;
        }

        .kpi-value {
            color: var(--text-main);
            font-size: 23px;
            font-weight: 900;
            margin: 4px 0 2px;
            line-height: 1.15;
        }

        .status-pill {
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            text-transform: capitalize;
        }

        .status-pill.green { background: rgba(16, 185, 129, .15); color: #059669; }
        .status-pill.blue { background: rgba(37, 99, 235, .15); color: #2563eb; }
        .status-pill.amber { background: rgba(245, 158, 11, .15); color: #d97706; }
        .status-pill.red { background: rgba(239, 68, 68, .15); color: #dc2626; }

        .pmc-table th, .pmc-table td { vertical-align: middle; }

        .pmc-topic-row.level-1 { background: rgba(255, 198, 26, .26); }
        .pmc-topic-row.level-2 { background: rgba(37, 99, 235, .08); }
        .pmc-topic-row.level-3 { background: rgba(139, 92, 246, .08); }
        .pmc-topic-row.level-4, .pmc-topic-row.level-5, .pmc-topic-row.level-6 { background: rgba(20, 184, 166, .07); }
        .pmc-task-row { background: var(--card-bg); }

        .fw-black { font-weight: 900; }

        .pmc-tree-dot {
            width: 13px;
            height: 13px;
            min-width: 13px;
            border-radius: 999px;
            display: inline-block;
            margin-top: 5px;
        }

        .pmc-tree-dot.topic {
            background: #ffc61a;
            border: 2px solid rgba(15, 23, 42, .18);
        }

        .pmc-tree-dot.task { background: #64748b; }

        .pmc-progress {
            height: 7px;
            min-width: 92px;
            background: rgba(148, 163, 184, .22);
        }

        .pmc-progress .progress-bar {
            background: linear-gradient(135deg, var(--brand-1), var(--brand-2));
        }

        .modal-content {
            background: var(--card-bg);
            color: var(--text-main);
            border: 1px solid var(--border-soft);
            border-radius: 24px;
            box-shadow: var(--shadow-card);
        }

        .modal-header, .modal-footer { border-color: var(--border-soft); }

        .bulk-row {
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 14px;
            background: rgba(148, 163, 184, .05);
        }

        .change-card {
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 12px;
            background: rgba(148, 163, 184, .06);
        }

        .delete-warning-box {
            border: 1px solid rgba(239, 68, 68, .30);
            background: rgba(239, 68, 68, .08);
            border-radius: 18px;
            padding: 14px;
        }

        .item-mobile-card {
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 13px;
            background: var(--card-bg);
        }
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
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <a href="pms.php" class="btn btn-sm btn-outline-secondary rounded-4 fw-bold">
                                    <i data-lucide="arrow-left" style="width:14px;height:14px;"></i> Back
                                </a>
                                <span class="status-pill <?= pmc_status_class($schedule["schedule_status"]) ?>">
                                    <?= pmc_e($schedule["schedule_status"]) ?>
                                </span>
                            </div>
                            <h1 class="h4 fw-bold mb-1"><?= pmc_e($schedule["schedule_name"]) ?></h1>
                            <p class="text-muted-custom mb-0 small">
                                <?= pmc_e($schedule["project_name"]) ?> <?= $schedule["project_code"] ? "· " . pmc_e($schedule["project_code"]) : "" ?> ·
                                <?= pmc_e($schedule["project_location"] ?: "-") ?>
                            </p>
                        </div>
<div class="d-flex flex-wrap gap-2">
                            <a href="pms-detail-export-excel.php?schedule_id=<?= (int)$scheduleId ?>"
                                class="btn btn-outline-success rounded-4 fw-bold btn-sm px-3">
                                <i data-lucide="file-spreadsheet" style="width:15px;height:15px;"></i> Export Excel
                            </a>

                            <a href="pms-detail-pdf.php?schedule_id=<?= (int)$scheduleId ?>&print=1"
                                target="_blank"
                                class="btn btn-outline-danger rounded-4 fw-bold btn-sm px-3">
                                <i data-lucide="file-text" style="width:15px;height:15px;"></i> Download PDF
                            </a>

                            <?php if ($canPmcCreate): ?>
                                <button type="button" class="btn btn-outline-primary rounded-4 fw-bold btn-sm px-3"
                                    data-bs-toggle="modal" data-bs-target="#bulkItemModal" onclick="resetBulkRows()">
                                    <i data-lucide="list-plus" style="width:15px;height:15px;"></i> Bulk Add
                                </button>

                                <button type="button" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3"
                                    data-bs-toggle="modal" data-bs-target="#itemModal" onclick="openAddItemModal()">
                                    <i data-lucide="plus" style="width:15px;height:15px;"></i> Add Topic / Task
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3 kpi-row">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#818cf8,#2563eb);">
                                <i data-lucide="calendar-range"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Overall Days</div>
                                <p class="kpi-value"><?= (int)$schedule["overall_duration_days"] ?></p>
                                <p class="text-muted-custom small mb-0"><?= pmc_e(pmc_date_show($schedule["overall_start_date"])) ?> to <?= pmc_e(pmc_date_show($schedule["overall_end_date"])) ?></p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-warning-subtle text-warning">
                                <i data-lucide="layers"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Topics</div>
                                <p class="kpi-value"><?= (int)$stats["topics"] ?></p>
                                <p class="text-muted-custom small mb-0">Main and sub topics</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                                <i data-lucide="list-checks"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Tasks</div>
                                <p class="kpi-value"><?= (int)$stats["tasks"] ?></p>
                                <p class="text-muted-custom small mb-0">Content/work rows</p>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="kpi-card">
                            <div class="kpi-icon bg-success-subtle text-success">
                                <i data-lucide="badge-check"></i>
                            </div>
                            <div>
                                <div class="kpi-label">Completed</div>
                                <p class="kpi-value"><?= (int)$stats["completed"] ?></p>
                                <p class="text-muted-custom small mb-0"><?= (int)$stats["pending"] ?> pending</p>
                            </div>
                        </article>
                    </div>
                </div>

                <section class="card-ui overflow-hidden mb-3">
                    <div class="p-3 p-lg-4 d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                        <div>
                            <h2 class="fw-bold fs-6 mb-1">Project Master Schedule Detail Items</h2>
                            <p class="text-muted-custom small mb-0">
                                Topics auto-calculate dates/days/status/progress from child topics and tasks.
                            </p>
                        </div>
                    </div>

                    <div class="d-none d-md-block overflow-auto thin-scrollbar px-3 px-lg-4 pb-3">
                        <table class="project-table pmc-table w-100">
                            <thead>
                                <tr>
                                    <th>Task Name / Topic</th>
                                    <th>Duration</th>
                                    <th>Start</th>
                                    <th>Finish</th>
                                    <th>Status</th>
                                    <th>Progress</th>
                                    <th>Sort</th>
                                    <th style="width:230px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php pmc_render_items($itemsByParent, 0, 0, $canPmcEdit, $canPmcDelete); ?>

                                <?php if (empty($items)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted-custom py-4">No Project Master Schedule items added yet. Use Add Topic / Task or Bulk Add.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-md-none px-3 px-lg-4 pb-3 d-grid gap-3">
                        <?php foreach ($items as $item): ?>
                            <?php $itemJson = htmlspecialchars(json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>
                            <article class="item-mobile-card">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <p class="fw-bold small mb-1"><?= pmc_e($item["title"]) ?></p>
                                        <small class="text-muted-custom"><?= $item["item_type"] === "topic" ? "Topic Level " . (int)$item["hierarchy_level"] : "Content / Task" ?></small>
                                    </div>
                                    <span class="status-pill <?= pmc_status_class($item["item_status"]) ?>"><?= pmc_e($item["item_status"]) ?></span>
                                </div>

                                <div class="mobile-info"><span>Duration</span><span><?= pmc_days_label($item["duration_days"]) ?></span></div>
                                <div class="mobile-info"><span>Start</span><span><?= pmc_e(pmc_date_show($item["planned_start_date"])) ?></span></div>
                                <div class="mobile-info"><span>Finish</span><span><?= pmc_e(pmc_date_show($item["planned_end_date"])) ?></span></div>

                                <div class="mt-3 d-flex flex-wrap gap-2">
                                    <?php if ($canPmcEdit): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-4 fw-bold"
                                            data-bs-toggle="modal" data-bs-target="#itemModal"
                                            onclick='openEditItemModal(<?= $itemJson ?>)'>Edit</button>

                                        <?php if ($item["item_type"] === "task"): ?>
                                            <button type="button" class="btn btn-sm btn-outline-warning rounded-4 fw-bold"
                                                data-bs-toggle="modal" data-bs-target="#dateChangeModal"
                                                onclick='openDateChangeModal(<?= $itemJson ?>)'>End Date</button>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($canPmcDelete): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-4 fw-bold"
                                            data-bs-toggle="modal" data-bs-target="#deleteItemModal"
                                            onclick='openDeleteItemModal(<?= $itemJson ?>)'>Delete</button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="card-ui p-3 p-lg-4">
                    <h2 class="fw-bold fs-6 mb-3">Recent End Date Changes</h2>

                    <div class="row g-3">
                        <?php foreach ($dateChanges as $change): ?>
                            <div class="col-12 col-md-6 col-xl-4">
                                <div class="change-card h-100">
                                    <p class="fw-bold small mb-1"><?= pmc_e($change["item_title"]) ?></p>
                                    <p class="text-muted-custom small mb-1">
                                        <?= pmc_e(pmc_date_show($change["old_planned_end_date"])) ?>
                                        <i data-lucide="arrow-right" style="width:13px;height:13px;"></i>
                                        <?= pmc_e(pmc_date_show($change["new_planned_end_date"])) ?>
                                    </p>
                                    <p class="small mb-1"><?= pmc_e($change["remark"]) ?></p>
                                    <small class="text-muted-custom">
                                        By <?= pmc_e($change["changed_by_name"] ?: "User") ?> · <?= pmc_e($change["changed_at"]) ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($dateChanges)): ?>
                            <div class="col-12 text-center text-muted-custom py-3">No end date changes yet.</div>
                        <?php endif; ?>
                    </div>
                </section>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include("includes/rightsidbar.php"); ?>
    </div>

    <?php if ($canPmcCreate || $canPmcEdit): ?>
        <div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl">
                <form method="POST" class="modal-content">
                    <input type="hidden" name="action" value="save_item">
                    <input type="hidden" name="item_id" id="item_id">
                    <input type="hidden" name="schedule_id" id="item_schedule_id" value="<?= (int)$scheduleId ?>">
                    <input type="hidden" name="project_id" id="item_project_id" value="<?= (int)$schedule["project_id"] ?>">

                    <div class="modal-header px-4">
                        <div>
                            <h5 class="modal-title fw-bold" id="itemModalTitle">Add Topic / Task</h5>
                            <p class="text-muted-custom small mb-0">Topic can be a main topic or sub topic. Task can be placed under any selected parent topic.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body px-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Item Type <span class="text-danger">*</span></label>
                                <select name="item_type" id="item_type" class="form-select rounded-4" required onchange="handleItemTypeChange()">
                                    <option value="topic">Topic / Sub Topic</option>
                                    <option value="task">Content / Task</option>
                                </select>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label fw-bold small">Parent Topic</label>
                                <select name="parent_id" id="parent_id" class="form-select rounded-4" onchange="setNextSortOrder()">
                                    <option value="">No Parent - Main Topic</option>
                                    <?php foreach ($topicOptions as $topic): ?>
                                        <option value="<?= (int)$topic["id"] ?>" data-level="<?= (int)$topic["hierarchy_level"] ?>">
                                            <?= str_repeat("— ", max(0, (int)$topic["hierarchy_level"] - 1)) ?><?= pmc_e($topic["title"]) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted-custom fw-semibold">Leave empty for Main Topic. Select a parent for sub topic or task.</small>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold small">Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="item_title" class="form-control rounded-4" required placeholder="Example: DESIGN DELIVERABLES or Complete Set of Architectural Floor Plans">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Planned Start</label>
                                <input type="date" name="planned_start_date" id="item_planned_start_date" class="form-control rounded-4">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Planned Finish</label>
                                <input type="date" name="planned_end_date" id="item_planned_end_date" class="form-control rounded-4">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Actual Start</label>
                                <input type="date" name="actual_start_date" id="item_actual_start_date" class="form-control rounded-4">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Actual Finish</label>
                                <input type="date" name="actual_end_date" id="item_actual_end_date" class="form-control rounded-4">
                            </div>

                            <div class="col-12">
                                <div class="alert alert-warning rounded-4 py-2 px-3 mb-0 small fw-semibold">
                                    Topic dates/days/status/progress auto-calculate from child topics/tasks. Planned dates are required for Content / Task rows.
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Progress %</label>
                                <input type="number" name="progress_percent" id="progress_percent" class="form-control rounded-4" min="0" max="100" step="0.01" value="0">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Work Status</label>
                                <select name="item_status" id="item_status" class="form-select rounded-4">
                                    <option value="pending">Pending</option>
                                    <option value="ongoing">Ongoing</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Sort Order</label>
                                <input type="number" name="sort_order" id="sort_order" class="form-control rounded-4" min="1" value="1">
                            </div>

                            <div class="col-md-3 d-flex align-items-end">
                                <label class="scope-pill">
                                    <input type="checkbox" name="is_milestone" id="is_milestone" value="1" class="form-check-input">
                                    <span>Milestone</span>
                                </label>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold small">Description</label>
                                <textarea name="item_description" id="item_description" rows="3" class="form-control rounded-4"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4" id="itemSubmitBtn">Save Item</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="bulkItemModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl">
                <form method="POST" class="modal-content">
                    <input type="hidden" name="action" value="bulk_save_items">
                    <input type="hidden" name="schedule_id" value="<?= (int)$scheduleId ?>">
                    <input type="hidden" name="project_id" value="<?= (int)$schedule["project_id"] ?>">

                    <div class="modal-header px-4">
                        <div>
                            <h5 class="modal-title fw-bold">Bulk Add Project Master Schedule Items</h5>
                            <p class="text-muted-custom small mb-0">Use Add More to create many topics/tasks at once. Parent topic is selectable for every row.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body px-4">
                        <div class="alert alert-info rounded-4 py-2 px-3 small fw-semibold">
                            Select <b>Topic</b> for main/sub topic rows. Select <b>Task</b> for actual work rows and enter planned dates.
                        </div>

                        <div id="bulkRows" class="d-grid gap-3"></div>

                        <button type="button" class="btn btn-outline-primary rounded-4 fw-bold mt-3" onclick="addBulkRow()">
                            <i data-lucide="plus" style="width:15px;height:15px;"></i> Add More
                        </button>
                    </div>

                    <div class="modal-footer px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Save Bulk Items</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="dateChangeModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" class="modal-content">
                    <input type="hidden" name="action" value="change_end_date">
                    <input type="hidden" name="schedule_id" value="<?= (int)$scheduleId ?>">
                    <input type="hidden" name="project_id" id="date_project_id" value="<?= (int)$schedule["project_id"] ?>">
                    <input type="hidden" name="item_id" id="date_item_id">

                    <div class="modal-header px-4">
                        <div>
                            <h5 class="modal-title fw-bold">Change End Date</h5>
                            <p class="text-muted-custom small mb-0">End date change requires a remark.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body px-4">
                        <div class="delete-warning-box mb-3">
                            <p class="fw-bold small mb-1" id="date_change_title">Task</p>
                            <p class="text-muted-custom small mb-0" id="date_change_current">Current finish date</p>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">New Planned Finish <span class="text-danger">*</span></label>
                            <input type="date" name="new_planned_end_date" id="new_planned_end_date" class="form-control rounded-4" required>
                        </div>

                        <div>
                            <label class="form-label fw-bold small">Remark <span class="text-danger">*</span></label>
                            <textarea name="remark" rows="3" class="form-control rounded-4" required placeholder="Reason for changing end date"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Save Change</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canPmcDelete): ?>
        <div class="modal fade" id="deleteItemModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" class="modal-content">
                    <input type="hidden" name="action" value="delete_item">
                    <input type="hidden" name="schedule_id" value="<?= (int)$scheduleId ?>">
                    <input type="hidden" name="item_id" id="delete_item_id">

                    <div class="modal-header px-4">
                        <div>
                            <h5 class="modal-title fw-bold">Delete Project Master Schedule Item</h5>
                            <p class="text-muted-custom small mb-0">This hides the selected item and child items.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body px-4">
                        <div class="delete-warning-box">
                            <p class="fw-bold mb-1" id="delete_item_title">Delete this item?</p>
                            <p class="text-muted-custom small mb-0">Child topics and tasks under this item will also be hidden.</p>
                        </div>
                    </div>

                    <div class="modal-footer px-4">
                        <button type="button" class="btn btn-outline-secondary rounded-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-4 fw-bold px-4">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=61"></script>

    <script>
        const pmcItems = <?= json_encode($items, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const topicOptionsHtml = `<?php ob_start(); ?>
            <option value="">No Parent - Main Topic</option>
            <?php foreach ($topicOptions as $topic): ?>
                <option value="<?= (int)$topic["id"] ?>" data-level="<?= (int)$topic["hierarchy_level"] ?>">
                    <?= str_repeat("— ", max(0, (int)$topic["hierarchy_level"] - 1)) ?><?= pmc_e($topic["title"]) ?>
                </option>
            <?php endforeach; ?>
        <?php echo str_replace(["\n", "\r", "`"], ["", "", "\\`"], ob_get_clean()); ?>`;

        let bulkIndex = 0;

        function setNextSortOrder() {
            const parentSelect = document.getElementById("parent_id");
            const sortInput = document.getElementById("sort_order");
            const itemId = parseInt(document.getElementById("item_id")?.value || 0, 10);
            const parentValue = parentSelect.value || "";
            const parentId = parentValue === "" ? null : parseInt(parentValue, 10);

            let maxSort = 0;

            pmcItems.forEach(function(item) {
                const currentId = parseInt(item.id || 0, 10);
                if (itemId && currentId === itemId) return;

                const itemParentId = item.parent_id === null || item.parent_id === "" ? null : parseInt(item.parent_id, 10);

                if (itemParentId === parentId) {
                    maxSort = Math.max(maxSort, parseInt(item.sort_order || 0, 10));
                }
            });

            sortInput.value = maxSort + 1;
        }

        function handleItemTypeChange() {
            const itemType = document.getElementById("item_type").value;
            const start = document.getElementById("item_planned_start_date");
            const end = document.getElementById("item_planned_end_date");

            start.required = itemType === "task";
            end.required = itemType === "task";
            setNextSortOrder();
        }

        function openAddItemModal() {
            document.getElementById("itemModalTitle").textContent = "Add Topic / Task";
            document.getElementById("itemSubmitBtn").textContent = "Save Item";
            document.getElementById("item_id").value = "";
            document.getElementById("item_type").value = "topic";
            document.getElementById("parent_id").value = "";
            document.getElementById("item_title").value = "";
            document.getElementById("item_planned_start_date").value = "";
            document.getElementById("item_planned_end_date").value = "";
            document.getElementById("item_actual_start_date").value = "";
            document.getElementById("item_actual_end_date").value = "";
            document.getElementById("progress_percent").value = "0";
            document.getElementById("item_status").value = "pending";
            document.getElementById("sort_order").value = "1";
            document.getElementById("is_milestone").checked = false;
            document.getElementById("item_description").value = "";
            handleItemTypeChange();
        }

        function openEditItemModal(item) {
            document.getElementById("itemModalTitle").textContent = "Edit Topic / Task";
            document.getElementById("itemSubmitBtn").textContent = "Update Item";
            document.getElementById("item_id").value = item.id || "";
            document.getElementById("item_type").value = item.item_type || "topic";
            document.getElementById("parent_id").value = item.parent_id || "";
            document.getElementById("item_title").value = item.title || "";
            document.getElementById("item_planned_start_date").value = item.planned_start_date || "";
            document.getElementById("item_planned_end_date").value = item.planned_end_date || "";
            document.getElementById("item_actual_start_date").value = item.actual_start_date || "";
            document.getElementById("item_actual_end_date").value = item.actual_end_date || "";
            document.getElementById("progress_percent").value = item.progress_percent || "0";
            document.getElementById("item_status").value = item.item_status || "pending";
            document.getElementById("sort_order").value = item.sort_order || "1";
            document.getElementById("is_milestone").checked = parseInt(item.is_milestone || 0, 10) === 1;
            document.getElementById("item_description").value = item.description || "";
            handleItemTypeChange();
            document.getElementById("sort_order").value = item.sort_order || "1";
        }

        function openDateChangeModal(item) {
            document.getElementById("date_item_id").value = item.id || "";
            document.getElementById("date_change_title").textContent = item.title || "Task";
            document.getElementById("date_change_current").textContent = "Current finish: " + (item.planned_end_date || "-");
            document.getElementById("new_planned_end_date").value = item.planned_end_date || "";
        }

        function openDeleteItemModal(item) {
            document.getElementById("delete_item_id").value = item.id || "";
            document.getElementById("delete_item_title").textContent = "Delete " + (item.title || "this item") + "?";
        }

        function bulkRowTemplate(index) {
            return `
                <div class="bulk-row" data-row="${index}">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                        <p class="fw-bold small mb-0">Bulk Row #${index + 1}</p>
                        <button type="button" class="btn btn-sm btn-outline-danger rounded-4 fw-bold" onclick="removeBulkRow(this)">Remove</button>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-2">
                            <label class="form-label fw-bold small">Type</label>
                            <select name="bulk[${index}][item_type]" class="form-select rounded-4 bulk-type" onchange="handleBulkTypeChange(this)">
                                <option value="topic">Topic</option>
                                <option value="task">Task</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Parent</label>
                            <select name="bulk[${index}][parent_id]" class="form-select rounded-4">
                                ${topicOptionsHtml}
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Title</label>
                            <input type="text" name="bulk[${index}][title]" class="form-control rounded-4" placeholder="Topic or task title">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Start</label>
                            <input type="date" name="bulk[${index}][planned_start_date]" class="form-control rounded-4 bulk-start">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Finish</label>
                            <input type="date" name="bulk[${index}][planned_end_date]" class="form-control rounded-4 bulk-end">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Progress %</label>
                            <input type="number" name="bulk[${index}][progress_percent]" class="form-control rounded-4" min="0" max="100" step="0.01" value="0">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Status</label>
                            <select name="bulk[${index}][item_status]" class="form-select rounded-4">
                                <option value="pending">Pending</option>
                                <option value="ongoing">Ongoing</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>
                </div>
            `;
        }

        function addBulkRow() {
            const container = document.getElementById("bulkRows");
            container.insertAdjacentHTML("beforeend", bulkRowTemplate(bulkIndex));
            bulkIndex++;
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        }

        function removeBulkRow(btn) {
            const row = btn.closest(".bulk-row");
            if (row) row.remove();

            if (document.querySelectorAll("#bulkRows .bulk-row").length === 0) {
                addBulkRow();
            }
        }

        function resetBulkRows() {
            const container = document.getElementById("bulkRows");
            container.innerHTML = "";
            bulkIndex = 0;
            addBulkRow();
            addBulkRow();
            addBulkRow();
        }

        function handleBulkTypeChange(select) {
            const row = select.closest(".bulk-row");
            if (!row) return;

            const start = row.querySelector(".bulk-start");
            const end = row.querySelector(".bulk-end");
            const isTask = select.value === "task";

            if (start) start.required = isTask;
            if (end) end.required = isTask;
        }

        window.addEventListener("load", function () {
            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
