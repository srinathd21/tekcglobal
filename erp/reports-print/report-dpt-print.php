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
    $query = mysqli_query($conn, "SELECT employee_id FROM users WHERE id = $userId LIMIT 1");

    if ($query && ($row = mysqli_fetch_assoc($query))) {
        $employeeId = (int)($row['employee_id'] ?? 0);
        $_SESSION['employee_id'] = $employeeId;
    }
}

$modeString = isset($_GET['mode']) && $_GET['mode'] === 'string';
$forceDownload = isset($_GET['dl']) && $_GET['dl'] == '1';

function dpt_pdf_clean($value): string
{
    $value = strip_tags((string)$value);
    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $value = preg_replace('/\s+/', ' ', $value);
    $value = trim($value);

    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);
    return $converted !== false ? $converted : $value;
}

function dpt_pdf_date($date): string
{
    $date = trim((string)$date);
    if ($date === '' || $date === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('d-m-Y', $timestamp) : $date;
}

function dpt_pdf_is_super_admin(mysqli $conn): bool
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
          AND (r.role_slug = 'super-admin' OR LOWER(r.role_name) = 'super admin')
        LIMIT 1
    ");

    return $query && mysqli_num_rows($query) > 0;
}

$companyName = 'TEK-C | A UKB Group Company';
$companyLogoDb = '';

try {
    $query = mysqli_query($conn, "
        SELECT company_name, logo_path
        FROM company_details
        WHERE id = 1
        LIMIT 1
    ");

    if ($query && ($company = mysqli_fetch_assoc($query))) {
        $companyName = $company['company_name'] ?: $companyName;
        $companyLogoDb = $company['logo_path'] ?? '';
    }
} catch (Throwable $exception) {
    // Use safe defaults.
}

$requestedId = (int)($_GET['view'] ?? $_GET['id'] ?? 0);
if ($requestedId <= 0) {
    die('Invalid DPT ID.');
}

$viewId = $requestedId;
$direct = mysqli_query($conn, "SELECT id FROM dpt_main WHERE id = $viewId LIMIT 1");

if (!$direct || mysqli_num_rows($direct) === 0) {
    $submissionColumns = [];
    $columnsQuery = mysqli_query($conn, "SHOW COLUMNS FROM project_report_submissions");

    while ($columnsQuery && ($column = mysqli_fetch_assoc($columnsQuery))) {
        $submissionColumns[$column['Field']] = true;
    }

    $referenceColumns = [];
    foreach (['report_reference_id', 'source_id', 'reference_id'] as $columnName) {
        if (isset($submissionColumns[$columnName])) {
            $referenceColumns[] = "`$columnName`";
        }
    }

    if ($referenceColumns) {
        $stmt = mysqli_prepare($conn, "
            SELECT " . implode(', ', $referenceColumns) . "
            FROM project_report_submissions
            WHERE id = ?
            LIMIT 1
        ");

        mysqli_stmt_bind_param($stmt, 'i', $requestedId);
        mysqli_stmt_execute($stmt);
        $submission = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($submission) {
            foreach (['report_reference_id', 'source_id', 'reference_id'] as $columnName) {
                $candidateId = (int)($submission[$columnName] ?? 0);
                if ($candidateId <= 0) {
                    continue;
                }

                $check = mysqli_query($conn, "SELECT id FROM dpt_main WHERE id = $candidateId LIMIT 1");
                if ($check && mysqli_num_rows($check) > 0) {
                    $viewId = $candidateId;
                    break;
                }
            }
        }
    }
}

$stmt = mysqli_prepare($conn, "
    SELECT
        dpt.*,
        p.project_location,
        p.manager_employee_id,
        p.team_lead_employee_id,
        c.client_name AS current_client_name
    FROM dpt_main dpt
    INNER JOIN projects p
        ON p.id = COALESCE(NULLIF(dpt.project_id, 0), dpt.site_id)
    LEFT JOIN clients c ON c.id = p.client_id
    WHERE dpt.id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, 'i', $viewId);
mysqli_stmt_execute($stmt);
$dpt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$dpt) {
    die('DPT not found.');
}

$canAccess =
    dpt_pdf_is_super_admin($conn)
    || (int)($dpt['manager_employee_id'] ?? 0) === $employeeId
    || (int)($dpt['team_lead_employee_id'] ?? 0) === $employeeId
    || (int)($dpt['created_by'] ?? 0) === $employeeId;

if (!$canAccess) {
    die('DPT not found or access denied.');
}

$stmt = mysqli_prepare($conn, "
    SELECT *
    FROM dpt_details
    WHERE dpt_main_id = ?
      AND TRIM(COALESCE(list_of_work, '')) <> ''
    ORDER BY sl_no ASC, id ASC
");

mysqli_stmt_bind_param($stmt, 'i', $viewId);
mysqli_stmt_execute($stmt);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

class TekcDptPreviousReportPdf extends FPDF
{
    public $reportMeta = [];
    public $reportLogo = '';
    public $reportFont = 'Arial';
    public $columnWidths = [15, 102, 40, 45, 30, 45];

    public function initialiseFonts(): void
    {
        $fontDir = __DIR__ . '/../libs/fpdf/font/';

        if (file_exists($fontDir . 'calibri.php') && file_exists($fontDir . 'calibrib.php')) {
            $this->AddFont('Calibri', '', 'calibri.php');
            $this->AddFont('Calibri', 'B', 'calibrib.php');

            if (file_exists($fontDir . 'calibrii.php')) {
                $this->AddFont('Calibri', 'I', 'calibrii.php');
            }

            $this->reportFont = 'Calibri';
        }
    }

    public function Header(): void
    {
        $x = 10;
        $y = 10;
        $totalWidth = 277;
        $headerHeight = 28;
        $logoWidth = 28;
        $metaWidth = 90;
        $titleWidth = $totalWidth - $logoWidth - $metaWidth;

        // Same full-page border used by the previous TEK-C reports.
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.25);
        $this->Rect($x, $y, $totalWidth, 190);

        // Logo box.
        $this->Rect($x, $y, $logoWidth, $headerHeight);

        if ($this->reportLogo !== '' && file_exists($this->reportLogo)) {
            $imageInfo = @getimagesize($this->reportLogo);

            if ($imageInfo) {
                $imageWidth = (float)$imageInfo[0];
                $imageHeight = (float)$imageInfo[1];
                $ratio = min(
                    ($logoWidth - 3) / max($imageWidth, 1),
                    ($headerHeight - 3) / max($imageHeight, 1)
                );

                $drawWidth = $imageWidth * $ratio;
                $drawHeight = $imageHeight * $ratio;

                $this->Image(
                    $this->reportLogo,
                    $x + (($logoWidth - $drawWidth) / 2),
                    $y + (($headerHeight - $drawHeight) / 2),
                    $drawWidth,
                    $drawHeight
                );
            }
        }

        // Grey title area.
        $this->SetXY($x + $logoWidth, $y);
        $this->SetFillColor(220, 220, 220);
        $this->SetFont($this->reportFont, 'B', 14);
        $this->Cell(
            $titleWidth,
            $headerHeight,
            'DAILY PROGRESS TRACKER (DPT)',
            1,
            0,
            'C',
            true
        );

        // Right-side metadata grid, matching AIT/VFS/DDS/DDT print pages.
        $metaX = $x + $logoWidth + $titleWidth;
        $rowHeight = $headerHeight / 4;
        $labelWidth = 30;
        $valueWidth = $metaWidth - $labelWidth;

        $metaRows = [
            ['Project', $this->reportMeta['project_name'] ?? ''],
            ['Client', $this->reportMeta['client_name'] ?? ''],
            ['PMC', $this->reportMeta['pmc'] ?? ''],
            ['Dated', $this->reportMeta['dated'] ?? ''],
        ];

        foreach ($metaRows as $index => $row) {
            $rowY = $y + ($index * $rowHeight);
            $this->SetXY($metaX, $rowY);
            $this->SetFillColor(235, 235, 235);
            $this->SetFont($this->reportFont, 'B', 8.5);
            $this->Cell($labelWidth, $rowHeight, dpt_pdf_clean($row[0]), 1, 0, 'L', true);

            $value = dpt_pdf_clean($row[1]);
            $fontSize = 8.5;
            $this->SetFont($this->reportFont, '', $fontSize);

            while ($fontSize > 6 && $this->GetStringWidth($value) > ($valueWidth - 2)) {
                $fontSize -= 0.2;
                $this->SetFont($this->reportFont, '', $fontSize);
            }

            $this->Cell($valueWidth, $rowHeight, $value, 1, 0, 'L');
        }

        $this->SetXY(10, $y + $headerHeight + 6);
    }

    public function Footer(): void
    {
        $this->SetY(-17);
        $this->SetFont($this->reportFont, 'I', 8);

        $this->SetX(13);
        $this->Cell(0, 10, dpt_pdf_clean($this->reportMeta['company_name'] ?? ''), 0, 0, 'L');

        $this->SetX(($this->GetPageWidth() / 2) - 12);
        $this->Cell(24, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    public function drawTableHeader(): void
    {
        $headers = [
            'SL NO',
            'LIST OF PENDING WORKS',
            'SCHEDULED FINISH',
            'ACTUAL / TARGETED FINISH',
            'STATUS',
            'REMARKS',
        ];

        $this->SetFillColor(141, 180, 226);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont($this->reportFont, 'B', 8.5);

        foreach ($headers as $index => $header) {
            $this->Cell(
                $this->columnWidths[$index],
                12,
                $header,
                1,
                0,
                'C',
                true
            );
        }

        $this->Ln();
    }

    public function numberOfLines(float $width, string $text): int
    {
        $characterWidths = &$this->CurrentFont['cw'];
        $maxWidth = ($width - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $text = str_replace("\r", '', $text);
        $length = strlen($text);
        $separator = -1;
        $index = 0;
        $lineStart = 0;
        $lineWidth = 0;
        $lineCount = 1;

        while ($index < $length) {
            $character = $text[$index];

            if ($character === "\n") {
                $index++;
                $separator = -1;
                $lineStart = $index;
                $lineWidth = 0;
                $lineCount++;
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
                $lineCount++;
            } else {
                $index++;
            }
        }

        return $lineCount;
    }

    private function statusStyle(string $status): array
    {
        $status = strtoupper(trim($status));

        switch ($status) {
            case 'COMPLETED':
                return ['COMPLETED', 220, 235, 255, 30, 64, 175];

            case 'DELAY':
                return ['DELAY', 255, 220, 220, 153, 27, 27];

            case 'BLOCKED':
                return ['BLOCKED', 255, 237, 213, 154, 52, 18];

            case 'CANCELLED':
                return ['CANCELLED', 230, 230, 230, 70, 70, 70];

            case 'ONTRACK':
            case 'ON TRACK':
            default:
                return ['ON TRACK', 220, 252, 231, 22, 101, 52];
        }
    }

    public function drawDataRow(array $row, int $printedSl): void
    {
        $lineHeight = 4.3;

        $texts = [
            (string)$printedSl,
            dpt_pdf_clean($row['list_of_work'] ?? ''),
            dpt_pdf_date($row['scheduled_finish'] ?? ''),
            dpt_pdf_date($row['actual_targeted_finish'] ?? ''),
            '',
            dpt_pdf_clean($row['remark'] ?? ''),
        ];

        $maxLines = 1;
        foreach ($texts as $index => $text) {
            if ($index === 4) {
                continue;
            }

            $maxLines = max(
                $maxLines,
                $this->numberOfLines($this->columnWidths[$index] - 2, $text)
            );
        }

        $rowHeight = max(9, $maxLines * $lineHeight + 2);

        if ($this->GetY() + $rowHeight > 184) {
            $this->AddPage();
            $this->drawTableHeader();
        }

        $x = 10;
        $y = $this->GetY();
        $alignments = ['C', 'L', 'C', 'C', 'C', 'L'];

        foreach ($texts as $index => $text) {
            $width = $this->columnWidths[$index];

            if ($index === 4) {
                $style = $this->statusStyle((string)($row['status'] ?? ''));
                $this->SetFillColor($style[1], $style[2], $style[3]);
                $this->Rect($x, $y, $width, $rowHeight, 'DF');
                $this->SetTextColor($style[4], $style[5], $style[6]);
                $this->SetFont($this->reportFont, 'B', 7.5);
                $this->SetXY($x, $y + (($rowHeight - 5) / 2));
                $this->Cell($width, 5, $style[0], 0, 0, 'C');
                $this->SetTextColor(0, 0, 0);
                $x += $width;
                continue;
            }

            $this->Rect($x, $y, $width, $rowHeight);
            $lines = max(1, $this->numberOfLines($width - 2, $text));
            $textHeight = $lines * $lineHeight;

            $this->SetFont($this->reportFont, '', 8);
            $this->SetXY(
                $x + 1,
                $y + max(1, ($rowHeight - $textHeight) / 2)
            );

            $this->MultiCell(
                $width - 2,
                $lineHeight,
                $text,
                0,
                $alignments[$index]
            );

            $x += $width;
        }

        $this->SetXY(10, $y + $rowHeight);
    }
}

$pdf = new TekcDptPreviousReportPdf('L', 'mm', 'A4');
$pdf->initialiseFonts();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(false);
$pdf->AliasNbPages('{nb}');

$pdf->reportMeta = [
    'company_name' => $companyName,
    'project_name' => $dpt['project_name'] ?? '',
    'client_name' => ($dpt['client_name'] ?? '') ?: ($dpt['current_client_name'] ?? ''),
    'pmc' => $dpt['pmc'] ?? '',
    'dated' => dpt_pdf_date($dpt['dated'] ?? ''),
];

$logoCandidates = [
    __DIR__ . '/../assets/ukb.png',
    __DIR__ . '/../assets/img/ukb.png',
    __DIR__ . '/../public/ukb.png',
    __DIR__ . '/../images/ukb.png',
    __DIR__ . '/../ukb.png',
];

if ($companyLogoDb !== '') {
    $logoCandidates[] = __DIR__ . '/../' . ltrim($companyLogoDb, '/');
    $logoCandidates[] = __DIR__ . '/../../' . ltrim($companyLogoDb, '/');
}

foreach ($logoCandidates as $logoPath) {
    if ($logoPath !== '' && file_exists($logoPath)) {
        $pdf->reportLogo = $logoPath;
        break;
    }
}

$pdf->AddPage();
$pdf->drawTableHeader();
$pdf->SetFont($pdf->reportFont, '', 8);

$printedSl = 1;
foreach ($rows as $row) {
    $pdf->drawDataRow($row, $printedSl++);
}

$filename = 'DPT_'
    . preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        dpt_pdf_clean($dpt['dpt_no'] ?? ('ID_' . $viewId))
    )
    . '.pdf';

if ($modeString) {
    $bytes = $pdf->Output('S');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $GLOBALS['__DPT_PDF_RESULT__'] = [
        'filename' => $filename,
        'bytes' => $bytes,
    ];

    return;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$pdf->Output($forceDownload ? 'D' : 'I', $filename);
exit;