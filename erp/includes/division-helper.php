<?php
/**
 * Division helper.
 * Use this in Employees / Projects / Sites pages to load active divisions.
 */

function get_active_divisions(mysqli $conn): array
{
    $divisions = [];
    $q = mysqli_query($conn, "
        SELECT id, division_name, division_code
        FROM company_divisions
        WHERE is_active = 1
        ORDER BY sort_order ASC, division_name ASC
    ");

    while ($q && ($row = mysqli_fetch_assoc($q))) {
        $divisions[] = $row;
    }

    return $divisions;
}

function get_default_division_id(mysqli $conn): int
{
    $q = mysqli_query($conn, "
        SELECT id
        FROM company_divisions
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
        LIMIT 1
    ");

    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return (int)$row["id"];
    }

    return 0;
}
?>