<?php
// reports-print/report-ait-print.php
// Current TEK-C AIT PDF
// Supports:
//   ?view=123
//   ?id=123
//   ?view=123&dl=1
//   ?view=123&mode=string

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

/*
|--------------------------------------------------------------------------
| Current Employee
|--------------------------------------------------------------------------
*/

$employeeId = (int)($_SESSION['employee_id'] ?? 0);

if ($employeeId <= 0 && !empty($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];

    $employeeQuery = mysqli_query(
        $conn,
        "SELECT employee_id
         FROM users
         WHERE id = $userId
         LIMIT 1"
    );

    if (
        $employeeQuery
        && ($employeeRow = mysqli_fetch_assoc($employeeQuery))
        && !empty($employeeRow['employee_id'])
    ) {
        $employeeId = (int)$employeeRow['employee_id'];
        $_SESSION['employee_id'] = $employeeId;
    }
}

if ($employeeId <= 0) {
    die('Employee session not found.');
}

$modeString = isset($_GET['mode']) && $_GET['mode'] === 'string';
$forceDownload = isset($_GET['dl']) && $_GET['dl'] == '1';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function ait_pdf_clean($value): string
{
    if (is_array($value)) {
        $value = implode(' ', array_map(
            static fn($item) => is_scalar($item) ? (string)$item : '',
            $value
        ));
    }

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

function ait_pdf_date($date): string
{
    $date = trim((string)$date);

    if ($date === '' || $date === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('d-m-Y', $timestamp) : $date;
}

function ait_pdf_safe_filename($value): string
{
    $value = ait_pdf_clean($value);

    $value = str_replace(
        ['/', '\\', ':', '*', '?', '"', '<', '>', '|'],
        '_',
        $value
    );

    $value = preg_replace('/[^A-Za-z0-9 \-_.]/', '_', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = preg_replace('/_+/', '_', $value);

    return trim($value, " ._-");
}

function ait_pdf_rfc5987(string $value): string
{
    return "UTF-8''" . rawurlencode($value);
}

function ait_pdf_is_super_admin(mysqli $conn): bool
{
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        return false;
    }

    $query = mysqli_query($conn, "
        SELECT r.id
        FROM user_roles ur
        INNER JOIN roles r
            ON r.id = ur.role_id
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

/*
|--------------------------------------------------------------------------
| Company Details
|--------------------------------------------------------------------------
*/

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
    // Keep safe defaults.
}

/*
|--------------------------------------------------------------------------
| Resolve AIT ID
|--------------------------------------------------------------------------
|
| Reports Hub can send either:
| 1. ait_main.id
| 2. project_report_submissions.id
|
*/

$requestedId = (int)($_GET['view'] ?? $_GET['id'] ?? 0);

if ($requestedId <= 0) {
    die('Invalid AIT ID.');
}

$viewId = $requestedId;

$directQuery = mysqli_query(
    $conn,
    "SELECT id
     FROM ait_main
     WHERE id = $viewId
     LIMIT 1"
);

if (!$directQuery || mysqli_num_rows($directQuery) === 0) {
    $submissionColumns = [];

    $columnsQuery = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM project_report_submissions"
    );

    while ($columnsQuery && ($column = mysqli_fetch_assoc($columnsQuery))) {
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
        $submissionStmt = mysqli_prepare(
            $conn,
            "SELECT " . implode(', ', $referenceColumns) . "
             FROM project_report_submissions
             WHERE id = ?
             LIMIT 1"
        );

        if ($submissionStmt) {
            mysqli_stmt_bind_param(
                $submissionStmt,
                'i',
                $requestedId
            );

            mysqli_stmt_execute($submissionStmt);

            $submissionResult = mysqli_stmt_get_result($submissionStmt);
            $submission = mysqli_fetch_assoc($submissionResult);

            mysqli_stmt_close($submissionStmt);

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
                         FROM ait_main
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

/*
|--------------------------------------------------------------------------
| Load Main AIT Record
|--------------------------------------------------------------------------
*/

$mainStmt = mysqli_prepare($conn, "
    SELECT
        m.*,
        p.project_name AS current_project_name,
        p.project_location,
        p.manager_employee_id,
        p.team_lead_employee_id,
        c.client_name AS current_client_name
    FROM ait_main m
    INNER JOIN projects p
        ON p.id = COALESCE(NULLIF(m.project_id, 0), m.site_id)
    LEFT JOIN clients c
        ON c.id = p.client_id
    WHERE m.id = ?
    LIMIT 1
");

if (!$mainStmt) {
    die('AIT SQL error: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($mainStmt, 'i', $viewId);
mysqli_stmt_execute($mainStmt);

$mainResult = mysqli_stmt_get_result($mainStmt);
$main = mysqli_fetch_assoc($mainResult);

mysqli_stmt_close($mainStmt);

if (!$main) {
    die('AIT not found.');
}

/*
|--------------------------------------------------------------------------
| Access Check
|--------------------------------------------------------------------------
*/

$canAccess = false;

if (ait_pdf_is_super_admin($conn)) {
    $canAccess = true;
} elseif (
    (int)($main['manager_employee_id'] ?? 0) === $employeeId
) {
    $canAccess = true;
} elseif (
    (int)($main['team_lead_employee_id'] ?? 0) === $employeeId
) {
    $canAccess = true;
} elseif (
    (int)($main['created_by'] ?? 0) === $employeeId
) {
    $canAccess = true;
}

if (!$canAccess) {
    die('AIT not found or access denied.');
}

/*
|--------------------------------------------------------------------------
| Load AIT Details
|--------------------------------------------------------------------------
*/

$detailStmt = mysqli_prepare($conn, "
    SELECT *
    FROM ait_details
    WHERE ait_main_id = ?
    ORDER BY sl_no ASC, id ASC
");

if (!$detailStmt) {
    die('AIT detail SQL error: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($detailStmt, 'i', $viewId);
mysqli_stmt_execute($detailStmt);

$detailResult = mysqli_stmt_get_result($detailStmt);
$details = mysqli_fetch_all($detailResult, MYSQLI_ASSOC);

mysqli_stmt_close($detailStmt);

/*
|--------------------------------------------------------------------------
| PDF Class
|--------------------------------------------------------------------------
*/

class AITCurrentPDF extends FPDF
{
    public array $meta = [];
    public string $logoPath = '';
    public string $fontFamily = 'Arial';

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

    public function Header(): void
    {
        $margin = 10;
        $pageWidth = $this->GetPageWidth();
        $pageHeight = $this->GetPageHeight();
        $totalWidth = $pageWidth - ($margin * 2);

        $this->SetLineWidth(0.3);
        $this->Rect(
            $margin,
            10,
            $totalWidth,
            $pageHeight - 20
        );

        $logoWidth = 30;
        $headerHeight = 28;
        $metaWidth = 95;
        $titleWidth = $totalWidth - $logoWidth - $metaWidth;

        /*
        |------------------------------------------------------------------
        | Logo
        |------------------------------------------------------------------
        */

        $this->SetXY($margin, 10);
        $this->Cell($logoWidth, $headerHeight, '', 1);

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

                $drawX = $margin + (($logoWidth - $drawWidth) / 2);
                $drawY = 10 + (($headerHeight - $drawHeight) / 2);

                $this->Image(
                    $this->logoPath,
                    $drawX,
                    $drawY,
                    $drawWidth,
                    $drawHeight
                );
            }
        }

        /*
        |------------------------------------------------------------------
        | Title
        |------------------------------------------------------------------
        */

        $this->SetXY($margin + $logoWidth, 10);
        $this->SetFillColor(220, 220, 220);
        $this->SetFont($this->fontFamily, 'B', 14);

        $this->Cell(
            $titleWidth,
            $headerHeight,
            'ACTION ITEM TRACKER (AIT)',
            1,
            0,
            'C',
            true
        );

        /*
        |------------------------------------------------------------------
        | Metadata
        |------------------------------------------------------------------
        */

        $metaX = $margin + $logoWidth + $titleWidth;
        $rowHeight = $headerHeight / 5;
        $labelWidth = 30;
        $valueWidth = $metaWidth - $labelWidth;

        $rows = [
            ['Project', $this->meta['project_name'] ?? ''],
            ['Client', $this->meta['client_name'] ?? ''],
            ['Architect', $this->meta['architects'] ?? ''],
            ['PMC', $this->meta['pmc'] ?? ''],
            ['AIT No', $this->meta['ait_no'] ?? ''],
        ];

        foreach ($rows as $index => $row) {
            $rowY = 10 + ($index * $rowHeight);

            $this->SetXY($metaX, $rowY);
            $this->SetFont($this->fontFamily, 'B', 8.5);

            $this->Cell(
                $labelWidth,
                $rowHeight,
                ait_pdf_clean($row[0]),
                1,
                0,
                'L'
            );

            $text = ait_pdf_clean($row[1]);
            $fontSize = 8.5;

            $this->SetFont(
                $this->fontFamily,
                '',
                $fontSize
            );

            while (
                $fontSize > 6
                && $this->GetStringWidth($text) > ($valueWidth - 2)
            ) {
                $fontSize -= 0.25;

                $this->SetFont(
                    $this->fontFamily,
                    '',
                    $fontSize
                );
            }

            $this->Cell(
                $valueWidth,
                $rowHeight,
                $text,
                1,
                0,
                'L'
            );
        }

        $this->SetY(10 + $headerHeight + 7);
    }

    public function Footer(): void
    {
        $this->SetY(-17);
        $this->SetFont($this->fontFamily, 'I', 9);

        $company = ait_pdf_clean(
            $this->meta['company_name'] ?? ''
        );

        $pageText = $this->PageNo() . ' / {nb}';
        $pageTextWidth = $this->GetStringWidth($pageText);

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

    public function numberOfLines(
        float $width,
        string $text
    ): int {
        $characterWidths = &$this->CurrentFont['cw'];

        if ($width == 0) {
            $width = $this->w
                - $this->rMargin
                - $this->x;
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
        $lines = 1;

        while ($index < $length) {
            $character = $text[$index];

            if ($character === "\n") {
                $index++;
                $separator = -1;
                $lineStart = $index;
                $lineLength = 0;
                $lines++;
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
            > $this->GetPageHeight() - 22
        ) {
            $this->AddPage();
            $this->drawTableHeader();
        }
    }

    public function drawTableHeader(): void
    {
        $widths = [
            10,
            24,
            57,
            24,
            34,
            25,
            27,
            45,
            27
        ];

        $headers = [
            'SL',
            'DATE',
            'DESCRIPTION',
            'PRIORITY',
            'RESPONSIBLE',
            'DUE',
            'COMPLETE',
            'PROGRESS',
            'STATUS'
        ];

        $this->SetFillColor(141, 180, 226);
        $this->SetFont(
            $this->fontFamily,
            'B',
            8
        );

        foreach ($headers as $index => $header) {
            $this->Cell(
                $widths[$index],
                10,
                $header,
                1,
                0,
                'C',
                true
            );
        }

        $this->Ln();
    }

    public function drawDynamicRow(
        array $widths,
        array $cells,
        array $alignments
    ): void {
        $lineHeight = 4.5;
        $maximumLines = 1;

        foreach ($cells as $index => $cell) {
            $maximumLines = max(
                $maximumLines,
                $this->numberOfLines(
                    $widths[$index] - 2,
                    ait_pdf_clean($cell)
                )
            );
        }

        $height = max(
            8,
            $maximumLines * $lineHeight
        );

        $this->ensureSpace($height);

        $x = 10;
        $y = $this->GetY();

        foreach ($cells as $index => $cell) {
            $width = $widths[$index];
            $alignment = $alignments[$index] ?? 'L';
            $text = ait_pdf_clean($cell);

            $this->Rect(
                $x,
                $y,
                $width,
                $height
            );

            if ($text !== '') {
                $lines = $this->numberOfLines(
                    $width - 2,
                    $text
                );

                $textHeight = $lines * $lineHeight;
                $startY = $y + max(
                    0,
                    ($height - $textHeight) / 2
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
            $y + $height
        );
    }
}

/*
|--------------------------------------------------------------------------
| PDF Data
|--------------------------------------------------------------------------
*/

$projectName = ait_pdf_clean(
    $main['project_name']
    ?? $main['current_project_name']
    ?? ''
);

$clientName = ait_pdf_clean(
    $main['client_name']
    ?? $main['current_client_name']
    ?? ''
);

$architects = ait_pdf_clean(
    $main['architects'] ?? ''
);

$pmc = ait_pdf_clean(
    $main['pmc'] ?? ''
);

$aitNo = ait_pdf_clean(
    $main['ait_no'] ?? ''
);

$aitDate = ait_pdf_date(
    $main['ait_date'] ?? ''
);

/*
|--------------------------------------------------------------------------
| Initialise PDF
|--------------------------------------------------------------------------
*/

$pdf = new AITCurrentPDF(
    'L',
    'mm',
    'A4'
);

$pdf->initialiseFonts();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.3);
$pdf->AliasNbPages('{nb}');

$pdf->setMetaData([
    'company_name' => $companyName,
    'project_name' => $projectName,
    'client_name' => $clientName,
    'architects' => $architects,
    'pmc' => $pmc,
    'ait_no' => $aitNo,
    'ait_date' => $aitDate,
]);

/*
|--------------------------------------------------------------------------
| Logo Path
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Logo Path
|--------------------------------------------------------------------------
| UKB logo is the first priority.
| Expected preferred location:
|   /assets/ukb.png
*/

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
        __DIR__ . '/../assets/logo.jpg',
        __DIR__ . '/../public/logo.png',
        __DIR__ . '/../public/logo.jpg',
        __DIR__ . '/../images/logo.png',
        __DIR__ . '/../images/logo.jpg',
        __DIR__ . '/../logo.png',
        __DIR__ . '/../logo.jpg',
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

/*
|--------------------------------------------------------------------------
| Render PDF
|--------------------------------------------------------------------------
*/

$pdf->AddPage();
$pdf->drawTableHeader();
$pdf->SetFont(
    $pdf->fontFamily,
    '',
    8
);

$widths = [
    10,
    24,
    57,
    24,
    34,
    25,
    27,
    45,
    27
];

$alignments = [
    'C',
    'C',
    'L',
    'C',
    'L',
    'C',
    'C',
    'L',
    'C'
];

if (!$details) {
    $pdf->drawDynamicRow(
        $widths,
        [
            '1',
            '',
            'No action items found',
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
    foreach ($details as $detail) {
        $pdf->drawDynamicRow(
            $widths,
            [
                (string)($detail['sl_no'] ?? ''),
                ait_pdf_date($detail['dated'] ?? ''),
                $detail['description'] ?? '',
                $detail['priority'] ?? '',
                $detail['responsible_by'] ?? '',
                ait_pdf_date($detail['due_date'] ?? ''),
                ait_pdf_date(
                    $detail['completion_date'] ?? ''
                ),
                $detail['progress_notes'] ?? '',
                $detail['status'] ?? '',
            ],
            $alignments
        );
    }
}

/*
|--------------------------------------------------------------------------
| Output
|--------------------------------------------------------------------------
*/

$projectPart = ait_pdf_safe_filename(
    $projectName
);

$numberPart = ait_pdf_safe_filename(
    $aitNo
);

$datePart = ait_pdf_safe_filename(
    $aitDate
);

if ($projectPart === '') {
    $projectPart = 'PROJECT';
}

if ($numberPart === '') {
    $numberPart = 'ID_' . $viewId;
}

if ($datePart === '') {
    $datePart = date('d-m-Y');
}

$filename = $projectPart
    . '_AIT_'
    . $numberPart
    . '_Dated_'
    . $datePart
    . '.pdf';

if ($modeString) {
    $pdfBytes = $pdf->Output('S');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $GLOBALS['__AIT_PDF_RESULT__'] = [
        'filename' => $filename,
        'bytes' => $pdfBytes,
    ];

    return;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('Content-Transfer-Encoding: binary');
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');

    $disposition = $forceDownload
        ? 'attachment'
        : 'inline';

    header(
        "Content-Disposition: $disposition; filename=\""
        . $filename
        . "\"; filename*="
        . ait_pdf_rfc5987($filename)
    );

    header(
        'Cache-Control: private, max-age=0, must-revalidate'
    );

    header('Pragma: public');
}

$pdf->Output(
    $forceDownload ? 'D' : 'I',
    $filename
);

exit;