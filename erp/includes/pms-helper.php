<?php
/*
 * PMS helper
 * ==========
 * IMPORTANT:
 * Database table names are still project_pmc_*.
 * User-facing/report wording must be PMS, not PMC.
 */

if (!function_exists('pms_project_schedule')) {
    function pms_project_schedule($conn, $projectId)
    {
        $projectId = (int)$projectId;
        if ($projectId <= 0) {
            return null;
        }

        $q = mysqli_query($conn, "
            SELECT *
            FROM project_pmc_schedules
            WHERE project_id = $projectId
            ORDER BY
                CASE schedule_status
                    WHEN 'ongoing' THEN 1
                    WHEN 'pending' THEN 2
                    WHEN 'draft' THEN 3
                    WHEN 'completed' THEN 4
                    ELSE 5
                END,
                id DESC
            LIMIT 1
        ");

        return $q ? mysqli_fetch_assoc($q) : null;
    }
}

if (!function_exists('pms_project_schedule_items')) {
    function pms_project_schedule_items($conn, $projectId, $scheduleId = 0)
    {
        $projectId = (int)$projectId;
        $scheduleId = (int)$scheduleId;

        if ($projectId <= 0) {
            return [];
        }

        if ($scheduleId <= 0) {
            $schedule = pms_project_schedule($conn, $projectId);
            $scheduleId = (int)($schedule['id'] ?? 0);
        }

        if ($scheduleId <= 0) {
            return [];
        }

        $items = [];
        $q = mysqli_query($conn, "
            SELECT *
            FROM project_pmc_schedule_items
            WHERE project_id = $projectId
              AND schedule_id = $scheduleId
              AND is_active = 1
            ORDER BY hierarchy_level ASC, parent_id ASC, sort_order ASC, id ASC
        ");

        while ($q && ($row = mysqli_fetch_assoc($q))) {
            $items[] = $row;
        }

        return $items;
    }
}

if (!function_exists('pms_schedule_date_range')) {
    function pms_schedule_date_range($schedule, $project = null)
    {
        $start = '';
        $end = '';

        if (is_array($schedule)) {
            $start = $schedule['overall_start_date'] ?? '';
            $end = $schedule['overall_end_date'] ?? '';
        }

        if (($start === '' || $start === '0000-00-00') && is_array($project)) {
            $start = $project['start_date'] ?? '';
        }

        if (($end === '' || $end === '0000-00-00') && is_array($project)) {
            $end = $project['expected_completion_date'] ?? '';
        }

        return [$start, $end];
    }
}

if (!function_exists('pms_label')) {
    function pms_label($text)
    {
        return str_replace(['PMC', 'Pmc', 'pmc'], ['PMS', 'PMS', 'pms'], (string)$text);
    }
}
