<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pms-helper.php';
require_once __DIR__ . '/../libs/fpdf.php';

if (empty($_SESSION['employee_id']) && empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection failed.');
}

$employeeId = (int)($_SESSION['employee_id'] ?? 0);

if ($employeeId <= 0 && !empty($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];

    $query = mysqli_query(
        $conn,
        "SELECT employee_id
         FROM users
         WHERE id = $userId
         LIMIT 1"
    );

    if ($query && ($row = mysqli_fetch_assoc($query))) {
        $employeeId = (int)($row['employee_id'] ?? 0);
        $_SESSION['employee_id'] = $employeeId;
    }
}

$modeString = isset($_GET['mode']) && $_GET['mode'] === 'string';
$forceDownload = isset($_GET['dl']) && $_GET['dl'] == '1';

function wpt_pdf_clean($value): string
{
    $value = strip_tags((string)$value);
    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $value = preg_replace('/\s+/', ' ', $value);
    $value = trim($value);

    $converted = @iconv(
        'UTF-8',
        'windows-1252//TRANSLIT//IGNORE',
        $value
    );

    return $converted !== false ? $converted : $value;
}

function wpt_pdf_date($date): string
{
    $date = trim((string)$date);

    if ($date === '' || $date === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('d-m-Y', $timestamp) : $date;
}

function wpt_pdf_is_super_admin(mysqli $conn): bool
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

$companyName = 'TEK-C | A UKB Group Company';
$companyLogoDb = '';

try {
    $companyQuery = mysqli_query($conn, "
        SELECT company_name, logo_path
        FROM company_details
        WHERE id = 1
        LIMIT 1
    ");

    if ($companyQuery && ($company = mysqli_fetch_assoc($companyQuery))) {
        if (!empty($company['company_name'])) {
            $companyName = $company['company_name'];
        }

        if (!empty($company['logo_path'])) {
            $companyLogoDb = $company['logo_path'];
        }
    }
} catch (Throwable $exception) {
    // Keep defaults.
}

$requestedId = (int)($_GET['view'] ?? $_GET['id'] ?? 0);

if ($requestedId <= 0) {
    die('Invalid WPT ID.');
}

$viewId = $requestedId;

$directQuery = mysqli_query(
    $conn,
    "SELECT id
     FROM wpt_main
     WHERE id = $viewId
     LIMIT 1"
);

if (!$directQuery || mysqli_num_rows($directQuery) === 0) {
    $submissionColumns = [];

    $columnQuery = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM project_report_submissions"
    );

    while ($columnQuery && ($column = mysqli_fetch_assoc($columnQuery))) {
        $submissionColumns[$column['Field']] = true;
    }

    $referenceColumns = [];

    foreach (
        ['report_reference_id', 'source_id', 'reference_id']
        as $columnName
    ) {
        if (isset($submissionColumns[$columnName])) {
            $referenceColumns[] = "`$columnName`";
        }
    }

    if ($referenceColumns) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT " . implode(', ', $referenceColumns) . "
             FROM project_report_submissions
             WHERE id = ?
             LIMIT 1"
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $requestedId);
            mysqli_stmt_execute($stmt);

            $submission = mysqli_fetch_assoc(
                mysqli_stmt_get_result($stmt)
            );

            mysqli_stmt_close($stmt);

            if ($submission) {
                foreach (
                    ['report_reference_id', 'source_id', 'reference_id']
                    as $columnName
                ) {
                    $candidateId = (int)($submission[$columnName] ?? 0);

                    if ($candidateId <= 0) {
                        continue;
                    }

                    $candidateQuery = mysqli_query(
                        $conn,
                        "SELECT id
                         FROM wpt_main
                         WHERE id = $candidateId
                         LIMIT 1"
                    );

                    if (
                        $candidateQuery
                        && mysqli_num_rows($candidateQuery) > 0
                    ) {
                        $viewId = $candidateId;
                        break;
                    }
                }
            }
        }
    }
}

$mainStmt = mysqli_prepare($conn, "
    SELECT
        w.*,
        p.project_location,
        p.manager_employee_id,
        p.team_lead_employee_id,
        c.client_name AS current_client_name
    FROM wpt_main w
    INNER JOIN projects p
        ON p.id = COALESCE(NULLIF(w.project_id, 0), w.site_id)
    LEFT JOIN clients c
        ON c.id = p.client_id
    WHERE w.id = ?
    LIMIT 1
");

if (!$mainStmt) {
    die('WPT SQL error: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($mainStmt, 'i', $viewId);
mysqli_stmt_execute($mainStmt);

$wpt = mysqli_fetch_assoc(
    mysqli_stmt_get_result($mainStmt)
);

mysqli_stmt_close($mainStmt);

if (!$wpt) {
    die('WPT not found.');
}

$canAccess = false;

if (wpt_pdf_is_super_admin($conn)) {
    $canAccess = true;
} elseif ((int)($wpt['manager_employee_id'] ?? 0) === $employeeId) {
    $canAccess = true;
} elseif ((int)($wpt['team_lead_employee_id'] ?? 0) === $employeeId) {
    $canAccess = true;
} elseif ((int)($wpt['created_by'] ?? 0) === $employeeId) {
    $canAccess = true;
}

if (!$canAccess) {
    die('WPT not found or access denied.');
}

$detailStmt = mysqli_prepare($conn, "
    SELECT *
    FROM wpt_details
    WHERE wpt_main_id = ?
    ORDER BY sl_no ASC, id ASC
");

if (!$detailStmt) {
    die('WPT detail SQL error: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($detailStmt, 'i', $viewId);
mysqli_stmt_execute($detailStmt);

$rows = mysqli_fetch_all(
    mysqli_stmt_get_result($detailStmt),
    MYSQLI_ASSOC
);

mysqli_stmt_close($detailStmt);

class WPTCurrentPDF extends FPDF
{
    public array $meta = [];
    public string $logoPath = '';
    public string $fontFamily = 'Arial';

    public float $outerX = 10;
    public float $outerY = 10;
    public float $outerWidth = 277;
    public float $outerHeight = 190;

    public array $tableWidths = [];

    public function initialiseFonts(): void
    {
        $fontDirectory = __DIR__ . '/../libs/fpdf/font/';

        if (
            file_exists($fontDirectory . 'calibri.php')
            && file_exists($fontDirectory . 'calibrib.php')
        ) {
            $this->AddFont('Calibri', '', 'calibri.php');
            $this->AddFont('Calibri', 'B', 'calibrib.php');

            if (file_exists($fontDirectory . 'calibrii.php')) {
                $this->AddFont('Calibri', 'I', 'calibrii.php');
            }

            $this->fontFamily = 'Calibri';
        }
    }

    public function setMetaData(array $meta): void
    {
        $this->meta = $meta;
    }

    public function setTableWidths(array $widths): void
    {
        $this->tableWidths = $widths;
    }

    public function Header(): void
    {
        $this->SetLineWidth(0.3);

        // Full outer border.
        $this->Rect(
            $this->outerX,
            $this->outerY,
            $this->outerWidth,
            $this->outerHeight
        );

        $x = $this->outerX;
        $y = $this->outerY;

        $logoWidth = 30;
        $titleWidth = 110;
        $leftInfoWidth = 68;
        $rightInfoWidth = 69;
        $headerHeight = 28;

        // Logo box.
        $this->SetXY($x, $y);
        $this->Cell($logoWidth, $headerHeight, '', 1);

        if (
            $this->logoPath !== ''
            && file_exists($this->logoPath)
        ) {
            $imageInfo = @getimagesize($this->logoPath);

            if ($imageInfo) {
                [$imageWidth, $imageHeight] = $imageInfo;

                $gap = 2;
                $boxWidth = $logoWidth - ($gap * 2);
                $boxHeight = $headerHeight - ($gap * 2);
                $ratio = min(
                    $boxWidth / $imageWidth,
                    $boxHeight / $imageHeight
                );

                $drawWidth = $imageWidth * $ratio;
                $drawHeight = $imageHeight * $ratio;

                $drawX = $x + (($logoWidth - $drawWidth) / 2);
                $drawY = $y + (($headerHeight - $drawHeight) / 2);

                $this->Image(
                    $this->logoPath,
                    $drawX,
                    $drawY,
                    $drawWidth,
                    $drawHeight
                );
            }
        }

        // Title.
        $this->SetFillColor(220, 220, 220);
        $this->SetXY($x + $logoWidth, $y);
        $this->SetFont($this->fontFamily, 'B', 13);

        $this->Cell(
            $titleWidth,
            $headerHeight,
            'WORK PROGRESS TRACKER (WPT)',
            1,
            0,
            'C',
            true
        );

        $leftX = $x + $logoWidth + $titleWidth;
        $rightX = $leftX + $leftInfoWidth;
        $rowHeight = 7;
        $labelWidth = 27;

        $leftRows = [
            ['Project', $this->meta['project_name'] ?? ''],
            ['Client', $this->meta['client_name'] ?? ''],
            ['Architect', $this->meta['architect'] ?? ''],
            ['Contractor', $this->meta['contractor'] ?? ''],
        ];

        $rightRows = [
            ['PMC', $this->meta['pmc'] ?? ''],
            ['Scope of Work', $this->meta['scope_of_work'] ?? ''],
            ['Week Ends On', $this->meta['week_ends_on'] ?? ''],
            ['WPT No./Dated', $this->meta['wpt_no_dated'] ?? ''],
        ];

        foreach ($leftRows as $index => $row) {
            $rowY = $y + ($index * $rowHeight);

            $this->SetXY($leftX, $rowY);
            $this->SetFillColor(235, 235, 235);
            $this->SetFont($this->fontFamily, 'B', 8);

            $this->Cell(
                $labelWidth,
                $rowHeight,
                wpt_pdf_clean($row[0]),
                1,
                0,
                'L',
                true
            );

            $this->SetFont($this->fontFamily, '', 8);

            $this->Cell(
                $leftInfoWidth - $labelWidth,
                $rowHeight,
                $this->fitText(
                    wpt_pdf_clean($row[1]),
                    $leftInfoWidth - $labelWidth
                ),
                1,
                0,
                'L'
            );
        }

        foreach ($rightRows as $index => $row) {
            $rowY = $y + ($index * $rowHeight);

            $this->SetXY($rightX, $rowY);
            $this->SetFillColor(235, 235, 235);
            $this->SetFont($this->fontFamily, 'B', 8);

            $this->Cell(
                $labelWidth,
                $rowHeight,
                wpt_pdf_clean($row[0]),
                1,
                0,
                'L',
                true
            );

            $this->SetFont($this->fontFamily, '', 8);

            $this->Cell(
                $rightInfoWidth - $labelWidth,
                $rowHeight,
                $this->fitText(
                    wpt_pdf_clean($row[1]),
                    $rightInfoWidth - $labelWidth
                ),
                1,
                0,
                'L'
            );
        }

        $this->SetY($y + $headerHeight + 8);
    }

    public function fitText(string $text, float $width): string
    {
        if ($text === '') {
            return '';
        }

        if ($this->GetStringWidth($text) <= ($width - 2)) {
            return $text;
        }

        while (
            strlen($text) > 3
            && $this->GetStringWidth($text . '...') > ($width - 2)
        ) {
            $text = substr($text, 0, -1);
        }

        return $text . '...';
    }

    public function Footer(): void
    {
        $this->SetY(-17);
        $this->SetFont($this->fontFamily, 'I', 9);

        $company = wpt_pdf_clean(
            $this->meta['company_name'] ?? ''
        );

        $pageText = $this->PageNo() . ' / {nb}';
        $pageWidth = $this->GetStringWidth($pageText);

        $this->SetX($this->outerX + 3);
        $this->Cell(0, 10, $company, 0, 0, 'L');

        $this->SetX(
            ($this->GetPageWidth() - $pageWidth) / 2
        );

        $this->Cell(
            $pageWidth,
            10,
            $pageText,
            0,
            0,
            'C'
        );
    }

    public function numberOfLines(
        float $width,
        string $text
    ): int {
        $characterWidths = &$this->CurrentFont['cw'];

        $maxWidth = (
            $width - (2 * $this->cMargin)
        ) * 1000 / $this->FontSize;

        $text = str_replace("\r", '', $text);
        $length = strlen($text);

        if (
            $length > 0
            && $text[$length - 1] === "\n"
        ) {
            $length--;
        }

        $separator = -1;
        $index = 0;
        $lineStart = 0;
        $lineWidth = 0;
        $lines = 1;

        while ($index < $length) {
            $character = $text[$index];

            if ($character === "\n") {
                $index++;
                $separator = -1;
                $lineStart = $index;
                $lineWidth = 0;
                $lines++;
                continue;
            }

            if ($character === ' ') {
                $separator = $index;
            }

            $lineWidth += $characterWidths[$character] ?? 0;

            if ($lineWidth > $maxWidth) {
                if ($separator === -1) {
                    if ($index === $lineStart) {
                        $index++;
                    }
                } else {
                    $index = $separator + 1;
                }

                $separator = -1;
                $lineStart = $index;
                $lineWidth = 0;
                $lines++;
            } else {
                $index++;
            }
        }

        return $lines;
    }

    public function ensureSpace(float $height): void
    {
        if (
            $this->GetY() + $height
            > $this->GetPageHeight() - 23
        ) {
            $this->AddPage();
            $this->drawTableHeader();
        }
    }

    public function drawTableHeader(): void
    {
        $w = $this->tableWidths;
        $x = 10;
        $y = $this->GetY();

        $topHeight = 7;
        $subHeight = 7;
        $totalHeight = 14;

        $this->SetFillColor(141, 180, 226);
        $this->SetFont($this->fontFamily, 'B', 8);

        // Fixed columns.
        $fixed = [
            [0, 'SL NO'],
            [1, 'TASK AS PER SCHEDULE'],
            [2, 'DURATION'],
        ];

        foreach ($fixed as [$index, $label]) {
            $this->Rect($x, $y, $w[$index], $totalHeight, 'F');
            $this->Rect($x, $y, $w[$index], $totalHeight);
            $this->SetXY($x, $y + 3.5);
            $this->Cell($w[$index], 7, $label, 0, 0, 'C');
            $x += $w[$index];
        }

        // Schedule group.
        $scheduleWidth = $w[3] + $w[4] + $w[5];

        $this->Rect($x, $y, $scheduleWidth, $topHeight, 'F');
        $this->Rect($x, $y, $scheduleWidth, $topHeight);
        $this->SetXY($x, $y + 1);
        $this->Cell($scheduleWidth, 5, 'AS PER SCHEDULE', 0, 0, 'C');

        $scheduleHeaders = [
            ['START', $w[3]],
            ['FINISH', $w[4]],
            ['% WORK DONE', $w[5]],
        ];

        $subX = $x;

        foreach ($scheduleHeaders as [$label, $width]) {
            $this->Rect(
                $subX,
                $y + $topHeight,
                $width,
                $subHeight,
                'F'
            );

            $this->Rect(
                $subX,
                $y + $topHeight,
                $width,
                $subHeight
            );

            $this->SetXY(
                $subX,
                $y + $topHeight + 1
            );

            $this->Cell(
                $width,
                5,
                $label,
                0,
                0,
                'C'
            );

            $subX += $width;
        }

        $x += $scheduleWidth;

        // Actual group.
        $actualWidth = $w[6] + $w[7] + $w[8];

        $this->Rect($x, $y, $actualWidth, $topHeight, 'F');
        $this->Rect($x, $y, $actualWidth, $topHeight);
        $this->SetXY($x, $y + 1);
        $this->Cell($actualWidth, 5, 'ACTUAL', 0, 0, 'C');

        $actualHeaders = [
            ['START', $w[6]],
            ['FINISH', $w[7]],
            ['% WORK DONE', $w[8]],
        ];

        $subX = $x;

        foreach ($actualHeaders as [$label, $width]) {
            $this->Rect(
                $subX,
                $y + $topHeight,
                $width,
                $subHeight,
                'F'
            );

            $this->Rect(
                $subX,
                $y + $topHeight,
                $width,
                $subHeight
            );

            $this->SetXY(
                $subX,
                $y + $topHeight + 1
            );

            $this->Cell(
                $width,
                5,
                $label,
                0,
                0,
                'C'
            );

            $subX += $width;
        }

        $x += $actualWidth;

        // Delay group.
        $delayWidth = $w[9] + $w[10];

        $this->Rect($x, $y, $delayWidth, $topHeight, 'F');
        $this->Rect($x, $y, $delayWidth, $topHeight);
        $this->SetXY($x, $y + 1);
        $this->Cell($delayWidth, 5, 'DELAY (DAYS)', 0, 0, 'C');

        $this->Rect(
            $x,
            $y + $topHeight,
            $w[9],
            $subHeight,
            'F'
        );

        $this->Rect(
            $x,
            $y + $topHeight,
            $w[9],
            $subHeight
        );

        $this->SetXY(
            $x,
            $y + $topHeight + 1
        );

        $this->Cell(
            $w[9],
            5,
            'PREVIOUS',
            0,
            0,
            'C'
        );

        $this->Rect(
            $x + $w[9],
            $y + $topHeight,
            $w[10],
            $subHeight,
            'F'
        );

        $this->Rect(
            $x + $w[9],
            $y + $topHeight,
            $w[10],
            $subHeight
        );

        $this->SetXY(
            $x + $w[9],
            $y + $topHeight + 1
        );

        $this->Cell(
            $w[10],
            5,
            'PRESENT',
            0,
            0,
            'C'
        );

        $x += $delayWidth;

        // Remarks.
        $this->Rect($x, $y, $w[11], $totalHeight, 'F');
        $this->Rect($x, $y, $w[11], $totalHeight);
        $this->SetXY($x, $y + 3.5);
        $this->Cell($w[11], 7, 'REMARKS', 0, 0, 'C');

        $this->SetXY(10, $y + $totalHeight);
    }

    public function drawDataRow(
        array $cells,
        array $alignments
    ): void {
        $lineHeight = 4.5;
        $maxLines = 1;

        foreach ($cells as $index => $cell) {
            $maxLines = max(
                $maxLines,
                $this->numberOfLines(
                    $this->tableWidths[$index] - 2,
                    wpt_pdf_clean($cell)
                )
            );
        }

        $rowHeight = max(
            8,
            $maxLines * $lineHeight
        );

        $this->ensureSpace($rowHeight);

        $x = 10;
        $y = $this->GetY();

        foreach ($cells as $index => $cell) {
            $width = $this->tableWidths[$index];
            $alignment = $alignments[$index] ?? 'L';
            $text = wpt_pdf_clean($cell);

            $this->Rect(
                $x,
                $y,
                $width,
                $rowHeight
            );

            if ($text !== '') {
                $lines = $this->numberOfLines(
                    $width - 2,
                    $text
                );

                $textHeight = $lines * $lineHeight;
                $startY = $y + max(
                    0,
                    ($rowHeight - $textHeight) / 2
                );

                $this->SetXY(
                    $x + 1,
                    $startY
                );

                $this->MultiCell(
                    $width - 2,
                    $lineHeight,
                    $text,
                    0,
                    $alignment
                );
            }

            $x += $width;
        }

        $this->SetXY(
            10,
            $y + $rowHeight
        );
    }
}

$pdf = new WPTCurrentPDF('L', 'mm', 'A4');
$pdf->initialiseFonts();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(false);
$pdf->AliasNbPages('{nb}');

$pdf->setMetaData([
    'company_name' => $companyName,
    'project_name' => $wpt['project_name'] ?? '',
    'client_name' => $wpt['client_name']
        ?: ($wpt['current_client_name'] ?? ''),
    'architect' => $wpt['architect'] ?? '',
    'contractor' => $wpt['contractor'] ?? '',
    'pmc' => $wpt['pmc'] ?? '',
    'scope_of_work' => $wpt['scope_of_work'] ?? '',
    'week_ends_on' => wpt_pdf_date(
        $wpt['week_ends_on'] ?? ''
    ),
    'wpt_no_dated' => ($wpt['wpt_no'] ?? '')
        . ' / '
        . wpt_pdf_date($wpt['created_at'] ?? ''),
]);

$logoCandidates = [
    __DIR__ . '/../assets/ukb.png',
    __DIR__ . '/../assets/img/ukb.png',
    __DIR__ . '/../public/ukb.png',
    __DIR__ . '/../images/ukb.png',
    __DIR__ . '/../ukb.png',
];

if ($companyLogoDb !== '') {
    $logoCandidates[] =
        __DIR__ . '/../' . ltrim($companyLogoDb, '/');

    $logoCandidates[] =
        __DIR__ . '/../../' . ltrim($companyLogoDb, '/');
}

foreach ($logoCandidates as $logoPath) {
    if (
        $logoPath !== ''
        && file_exists($logoPath)
    ) {
        $pdf->logoPath = $logoPath;
        break;
    }
}

$widths = [
    10,
    55,
    18,
    18,
    18,
    25,
    18,
    18,
    25,
    18,
    17,
    37
];

$pdf->setTableWidths($widths);
$pdf->AddPage();
$pdf->drawTableHeader();
$pdf->SetFont($pdf->fontFamily, '', 8);

$alignments = [
    'C',
    'L',
    'C',
    'C',
    'C',
    'C',
    'C',
    'C',
    'C',
    'C',
    'C',
    'L'
];

if (!$rows) {
    $pdf->drawDataRow(
        [
            '1',
            'No WPT task details found',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        ],
        $alignments
    );
} else {
    foreach ($rows as $row) {
        $pdf->drawDataRow(
            [
                (string)($row['sl_no'] ?? ''),
                $row['task_name'] ?? '',
                $row['duration'] ?? '',
                wpt_pdf_date($row['start_date'] ?? ''),
                wpt_pdf_date($row['finish_date'] ?? ''),
                $row['schedule_work_done'] ?? '',
                wpt_pdf_date($row['actual_start'] ?? ''),
                wpt_pdf_date($row['actual_finish'] ?? ''),
                $row['actual_work_done'] ?? '',
                $row['prev_delay'] ?? '',
                $row['present_delay'] ?? '',
                $row['remarks'] ?? '',
            ],
            $alignments
        );
    }
}

$filename = 'WPT_'
    . preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        wpt_pdf_clean($wpt['wpt_no'] ?? '')
    )
    . '.pdf';

if ($modeString) {
    $pdfBytes = $pdf->Output('S');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $GLOBALS['__WPT_PDF_RESULT__'] = [
        'filename' => $filename,
        'bytes' => $pdfBytes,
    ];

    return;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$pdf->Output(
    $forceDownload ? 'D' : 'I',
    $filename
);

exit;
