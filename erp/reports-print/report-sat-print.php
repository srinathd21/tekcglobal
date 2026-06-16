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

function sat_pdf_clean($value): string
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

function sat_pdf_date($date): string
{
    $date = trim((string)$date);

    if ($date === '' || $date === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('d-m-Y', $timestamp) : $date;
}

function sat_pdf_is_super_admin(mysqli $conn): bool
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
    die('Invalid SAT ID.');
}

$viewId = $requestedId;

$directQuery = mysqli_query(
    $conn,
    "SELECT id
     FROM sat_reports
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
                         FROM sat_reports
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
        r.*,
        p.project_location,
        p.manager_employee_id,
        p.team_lead_employee_id,
        c.client_name AS current_client_name
    FROM sat_reports r
    INNER JOIN projects p
        ON p.id = COALESCE(NULLIF(r.project_id, 0), r.site_id)
    LEFT JOIN clients c
        ON c.id = p.client_id
    WHERE r.id = ?
    LIMIT 1
");

if (!$mainStmt) {
    die('SAT SQL error: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($mainStmt, 'i', $viewId);
mysqli_stmt_execute($mainStmt);

$main = mysqli_fetch_assoc(
    mysqli_stmt_get_result($mainStmt)
);

mysqli_stmt_close($mainStmt);

if (!$main) {
    die('SAT not found.');
}

$canAccess = false;

if (sat_pdf_is_super_admin($conn)) {
    $canAccess = true;
} elseif ((int)($main['manager_employee_id'] ?? 0) === $employeeId) {
    $canAccess = true;
} elseif ((int)($main['team_lead_employee_id'] ?? 0) === $employeeId) {
    $canAccess = true;
} elseif ((int)($main['employee_id'] ?? 0) === $employeeId) {
    $canAccess = true;
}

if (!$canAccess) {
    die('SAT not found or access denied.');
}

$itemStmt = mysqli_prepare($conn, "
    SELECT *
    FROM sat_report_items
    WHERE sat_report_id = ?
    ORDER BY sl_no ASC, id ASC
");

if (!$itemStmt) {
    die('SAT item SQL error: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($itemStmt, 'i', $viewId);
mysqli_stmt_execute($itemStmt);

$items = mysqli_fetch_all(
    mysqli_stmt_get_result($itemStmt),
    MYSQLI_ASSOC
);

mysqli_stmt_close($itemStmt);

class SATBorderedPDF extends FPDF
{
    public array $meta = [];
    public string $logoPath = '';
    public string $fontFamily = 'Arial';

    public float $pageMargin = 8;
    public float $outerX = 8;
    public float $outerY = 8;
    public float $outerWidth = 0;
    public float $outerHeight = 0;

    public array $tableWidths = [];
    public float $tableX = 8;

    public function initialiseFonts(): void
    {
        $fontDir = __DIR__ . '/../libs/fpdf/font/';

        if (
            file_exists($fontDir . 'calibri.php')
            && file_exists($fontDir . 'calibrib.php')
        ) {
            $this->AddFont('Calibri', '', 'calibri.php');
            $this->AddFont('Calibri', 'B', 'calibrib.php');

            if (file_exists($fontDir . 'calibrii.php')) {
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
        $this->SetLineWidth(0.35);

        $this->outerWidth = $this->GetPageWidth() - 16;
        $this->outerHeight = $this->GetPageHeight() - 16;

        // Full-page border like the previous reports.
        $this->Rect(
            $this->outerX,
            $this->outerY,
            $this->outerWidth,
            $this->outerHeight
        );

        $x = $this->outerX;
        $y = $this->outerY;

        $headerHeight = 32;
        $logoWidth = 32;
        $metaWidth = 118;
        $titleWidth = $this->outerWidth - $logoWidth - $metaWidth;

        // Logo cell.
        $this->SetXY($x, $y);
        $this->Cell($logoWidth, $headerHeight, '', 1, 0, 'C');

        if (
            $this->logoPath !== ''
            && file_exists($this->logoPath)
        ) {
            $imageInfo = @getimagesize($this->logoPath);

            if ($imageInfo) {
                [$imageWidth, $imageHeight] = $imageInfo;

                $padding = 2;
                $boxWidth = $logoWidth - ($padding * 2);
                $boxHeight = $headerHeight - ($padding * 2);

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

        // Title cell.
        $this->SetFillColor(220, 220, 220);
        $this->SetFont($this->fontFamily, 'B', 14);

        $this->Cell(
            $titleWidth,
            $headerHeight,
            'SAMPLES APPROVAL TRACKER (SAT)',
            1,
            0,
            'C',
            true
        );

        // Meta table.
        $metaX = $x + $logoWidth + $titleWidth;
        $metaRowHeight = $headerHeight / 5;
        $labelWidth = 36;
        $valueWidth = $metaWidth - $labelWidth;

        $metaRows = [
            ['Project', $this->meta['project'] ?? ''],
            ['Client', $this->meta['client'] ?? ''],
            ['Architects', $this->meta['architects'] ?? ''],
            ['PMC', $this->meta['pmc'] ?? ''],
            ['Revisions/Dated', $this->meta['revisions'] ?? ''],
        ];

        foreach ($metaRows as $index => $metaRow) {
            $rowY = $y + ($index * $metaRowHeight);

            $this->SetXY($metaX, $rowY);
            $this->SetFillColor(235, 235, 235);
            $this->SetFont($this->fontFamily, 'B', 8.5);

            $this->Cell(
                $labelWidth,
                $metaRowHeight,
                sat_pdf_clean($metaRow[0]),
                1,
                0,
                'L',
                true
            );

            $text = sat_pdf_clean($metaRow[1]);
            $fontSize = 8.5;

            $this->SetFont($this->fontFamily, '', $fontSize);

            while (
                $fontSize > 6
                && $this->GetStringWidth($text) > ($valueWidth - 2)
            ) {
                $fontSize -= 0.25;
                $this->SetFont($this->fontFamily, '', $fontSize);
            }

            $this->Cell(
                $valueWidth,
                $metaRowHeight,
                $text,
                1,
                0,
                'L'
            );
        }

        $this->SetY($y + $headerHeight + 7);
    }

    public function Footer(): void
    {
        $this->SetY(-19);
        $this->SetFont($this->fontFamily, 'I', 9);

        $company = sat_pdf_clean(
            $this->meta['company'] ?? ''
        );

        $pageText = $this->PageNo() . ' / {nb}';
        $pageTextWidth = $this->GetStringWidth($pageText);

        $this->SetX($this->outerX + 3);
        $this->Cell(0, 10, $company, 0, 0, 'L');

        $this->SetX(
            ($this->GetPageWidth() - $pageTextWidth) / 2
        );

        $this->Cell(
            $pageTextWidth,
            10,
            $pageText,
            0,
            0,
            'C'
        );
    }

    public function numberOfLines(float $width, string $text): int
    {
        $characterWidths = &$this->CurrentFont['cw'];

        if ($width == 0) {
            $width = $this->w - $this->rMargin - $this->x;
        }

        $maximumWidth = (
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
        $lineLength = 0;
        $lineCount = 1;

        while ($index < $length) {
            $character = $text[$index];

            if ($character === "\n") {
                $index++;
                $separator = -1;
                $lineStart = $index;
                $lineLength = 0;
                $lineCount++;
                continue;
            }

            if ($character === ' ') {
                $separator = $index;
            }

            $lineLength += $characterWidths[$character] ?? 0;

            if ($lineLength > $maximumWidth) {
                if ($separator === -1) {
                    if ($index === $lineStart) {
                        $index++;
                    }
                } else {
                    $index = $separator + 1;
                }

                $separator = -1;
                $lineStart = $index;
                $lineLength = 0;
                $lineCount++;
            } else {
                $index++;
            }
        }

        return $lineCount;
    }

    public function ensureSpace(float $height): void
    {
        if (
            $this->GetY() + $height
            > $this->GetPageHeight() - 23
        ) {
            $this->AddPage();
            $this->drawGroupedTableHeader();
        }
    }

    public function drawGroupedTableHeader(): void
    {
        $w = $this->tableWidths;
        $x = $this->tableX;
        $y = $this->GetY();

        $topHeight = 7;
        $subHeight = 7;
        $totalHeight = $topHeight + $subHeight;

        $this->SetFillColor(141, 180, 226);
        $this->SetFont($this->fontFamily, 'B', 8);

        // SL.
        $this->Rect($x, $y, $w[0], $totalHeight, 'F');
        $this->Rect($x, $y, $w[0], $totalHeight);
        $this->SetXY($x, $y + 3.5);
        $this->Cell($w[0], 7, 'SL NO', 0, 0, 'C');

        // Samples.
        $x += $w[0];
        $this->Rect($x, $y, $w[1], $totalHeight, 'F');
        $this->Rect($x, $y, $w[1], $totalHeight);
        $this->SetXY($x, $y + 3.5);
        $this->Cell($w[1], 7, 'SAMPLES', 0, 0, 'C');

        // Vendors.
        $x += $w[1];
        $this->Rect($x, $y, $w[2], $totalHeight, 'F');
        $this->Rect($x, $y, $w[2], $totalHeight);
        $this->SetXY($x, $y + 3.5);
        $this->Cell($w[2], 7, 'VENDORS', 0, 0, 'C');

        // Sample status group.
        $x += $w[2];
        $sampleGroupWidth = $w[3] + $w[4];
        $this->Rect($x, $y, $sampleGroupWidth, $topHeight, 'F');
        $this->Rect($x, $y, $sampleGroupWidth, $topHeight);
        $this->SetXY($x, $y + 1);
        $this->Cell($sampleGroupWidth, 5, 'SAMPLE STATUS', 0, 0, 'C');

        $this->Rect($x, $y + $topHeight, $w[3], $subHeight, 'F');
        $this->Rect($x, $y + $topHeight, $w[3], $subHeight);
        $this->SetXY($x, $y + $topHeight + 1);
        $this->Cell($w[3], 5, 'DELIVERED', 0, 0, 'C');

        $this->Rect($x + $w[3], $y + $topHeight, $w[4], $subHeight, 'F');
        $this->Rect($x + $w[3], $y + $topHeight, $w[4], $subHeight);
        $this->SetXY($x + $w[3], $y + $topHeight + 1);
        $this->Cell($w[4], 5, 'DATE', 0, 0, 'C');

        // Quote status group.
        $x += $sampleGroupWidth;
        $quoteGroupWidth = $w[5] + $w[6];
        $this->Rect($x, $y, $quoteGroupWidth, $topHeight, 'F');
        $this->Rect($x, $y, $quoteGroupWidth, $topHeight);
        $this->SetXY($x, $y + 1);
        $this->Cell($quoteGroupWidth, 5, 'QUOTE STATUS', 0, 0, 'C');

        $this->Rect($x, $y + $topHeight, $w[5], $subHeight, 'F');
        $this->Rect($x, $y + $topHeight, $w[5], $subHeight);
        $this->SetXY($x, $y + $topHeight + 1);
        $this->Cell($w[5], 5, 'RECEIVED', 0, 0, 'C');

        $this->Rect($x + $w[5], $y + $topHeight, $w[6], $subHeight, 'F');
        $this->Rect($x + $w[5], $y + $topHeight, $w[6], $subHeight);
        $this->SetXY($x + $w[5], $y + $topHeight + 1);
        $this->Cell($w[6], 5, 'DATE', 0, 0, 'C');

        // Approval group.
        $x += $quoteGroupWidth;
        $approvalGroupWidth = $w[7] + $w[8] + $w[9];

        $this->Rect($x, $y, $approvalGroupWidth, $topHeight, 'F');
        $this->Rect($x, $y, $approvalGroupWidth, $topHeight);
        $this->SetXY($x, $y + 1);
        $this->Cell(
            $approvalGroupWidth,
            5,
            'APPROVAL STATUS',
            0,
            0,
            'C'
        );

        $approvalHeaders = [
            ['APPROVED', $w[7]],
            ['REJECTED', $w[8]],
            ['DATE', $w[9]],
        ];

        $approvalX = $x;

        foreach ($approvalHeaders as [$label, $width]) {
            $this->Rect(
                $approvalX,
                $y + $topHeight,
                $width,
                $subHeight,
                'F'
            );

            $this->Rect(
                $approvalX,
                $y + $topHeight,
                $width,
                $subHeight
            );

            $this->SetXY(
                $approvalX,
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

            $approvalX += $width;
        }

        // Comments.
        $x += $approvalGroupWidth;

        $this->Rect($x, $y, $w[10], $totalHeight, 'F');
        $this->Rect($x, $y, $w[10], $totalHeight);

        $this->SetXY($x, $y + 2);
        $this->MultiCell(
            $w[10],
            5,
            'COMMENTS / FURTHER ACTION',
            0,
            'C'
        );

        $this->SetXY(
            $this->tableX,
            $y + $totalHeight
        );
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
                    sat_pdf_clean($cell)
                )
            );
        }

        $rowHeight = max(8, $maxLines * $lineHeight);

        $this->ensureSpace($rowHeight);

        $x = $this->tableX;
        $y = $this->GetY();

        foreach ($cells as $index => $cell) {
            $width = $this->tableWidths[$index];
            $alignment = $alignments[$index] ?? 'L';
            $text = sat_pdf_clean($cell);

            $this->Rect($x, $y, $width, $rowHeight);

            if ($text !== '') {
                $lineCount = $this->numberOfLines(
                    $width - 2,
                    $text
                );

                $textHeight = $lineCount * $lineHeight;
                $startY = $y + max(
                    0,
                    ($rowHeight - $textHeight) / 2
                );

                $this->SetXY($x + 1, $startY);

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
            $this->tableX,
            $y + $rowHeight
        );
    }
}

$pdf = new SATBorderedPDF('L', 'mm', 'A3');
$pdf->initialiseFonts();
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(false);
$pdf->AliasNbPages('{nb}');

$pdf->setMetaData([
    'project' => $main['project_name'] ?? '',
    'client' => $main['client_name']
        ?: ($main['current_client_name'] ?? ''),
    'architects' => $main['architects'] ?? '',
    'pmc' => $main['pmc'] ?? '',
    'revisions' => $main['revisions'] ?? '',
    'company' => $companyName,
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

$logoCandidates = array_merge(
    $logoCandidates,
    [
        __DIR__ . '/../assets/img/logo.png',
        __DIR__ . '/../assets/logo.png',
        __DIR__ . '/../public/logo.png',
        __DIR__ . '/../logo.png',
    ]
);

foreach ($logoCandidates as $logoPath) {
    if (
        $logoPath !== ''
        && file_exists($logoPath)
    ) {
        $pdf->logoPath = $logoPath;
        break;
    }
}

// Exact table width = A3 landscape width minus both 8 mm borders.
$totalTableWidth = $pdf->GetPageWidth() - 16;

$widths = [
    12, // SL
    58, // Samples
    46, // Vendors
    22, // Delivered
    27, // Delivered date
    22, // Quote
    27, // Quote date
    22, // Approved
    22, // Rejected
    27, // Approval date
    0   // Comments calculated below
];

$usedWidth = array_sum($widths);
$widths[10] = $totalTableWidth - $usedWidth;

$pdf->tableX = 8;
$pdf->setTableWidths($widths);

$pdf->AddPage();
$pdf->drawGroupedTableHeader();
$pdf->SetFont($pdf->fontFamily, '', 8.5);

$alignments = [
    'C',
    'L',
    'L',
    'C',
    'C',
    'C',
    'C',
    'C',
    'C',
    'C',
    'L'
];

if (!$items) {
    $pdf->drawDataRow(
        [
            '1',
            'No sample items found',
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
    foreach ($items as $item) {
        $pdf->drawDataRow(
            [
                (string)($item['sl_no'] ?? ''),
                $item['sample_name'] ?? '',
                $item['vendor_name'] ?? '',
                !empty($item['sample_delivered']) ? 'Y' : '-',
                sat_pdf_date(
                    $item['sample_delivered_date'] ?? ''
                ),
                !empty($item['quote_received']) ? 'Y' : '-',
                sat_pdf_date(
                    $item['quote_received_date'] ?? ''
                ),
                !empty($item['approved']) ? 'Y' : '-',
                !empty($item['rejected']) ? 'Y' : '-',
                sat_pdf_date(
                    $item['approval_date'] ?? ''
                ),
                $item['comments'] ?? '',
            ],
            $alignments
        );
    }
}

$filename = 'SAT_'
    . preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        sat_pdf_clean($main['sat_no'] ?? '')
    )
    . '_'
    . sat_pdf_date($main['report_date'] ?? '')
    . '.pdf';

if ($modeString) {
    $pdfBytes = $pdf->Output('S');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $GLOBALS['__SAT_PDF_RESULT__'] = [
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