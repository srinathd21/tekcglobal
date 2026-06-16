<?php
// reports-print/report-rfi-print.php

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

function rfi_pdf_clean($value): string
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

function rfi_pdf_date($date): string
{
    $date = trim((string)$date);

    if ($date === '' || $date === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('d-m-Y', $timestamp) : $date;
}

function rfi_pdf_is_super_admin(mysqli $conn): bool
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

function rfi_pdf_safe_filename($value): string
{
    $value = rfi_pdf_clean($value);

    $value = str_replace(
        ['/', '\\', ':', '*', '?', '"', '<', '>', '|'],
        '_',
        $value
    );

    $value = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $value);
    $value = preg_replace('/\s+/', ' ', $value);

    return trim($value, " ._-");
}

function rfi_pdf_rfc5987(string $value): string
{
    return "UTF-8''" . rawurlencode($value);
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
    // Keep safe defaults.
}

$requestedId = (int)($_GET['view'] ?? $_GET['id'] ?? 0);

if ($requestedId <= 0) {
    die('Invalid RFI ID.');
}

$viewId = $requestedId;

$directQuery = mysqli_query(
    $conn,
    "SELECT id
     FROM rfi_reports
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
                         FROM rfi_reports
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
        p.project_location AS current_project_location,
        p.manager_employee_id,
        p.team_lead_employee_id,
        c.client_name AS current_client_name
    FROM rfi_reports r
    INNER JOIN projects p
        ON p.id = COALESCE(NULLIF(r.project_id, 0), r.site_id)
    LEFT JOIN clients c
        ON c.id = p.client_id
    WHERE r.id = ?
    LIMIT 1
");

if (!$mainStmt) {
    die('RFI SQL error: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($mainStmt, 'i', $viewId);
mysqli_stmt_execute($mainStmt);

$rfi = mysqli_fetch_assoc(
    mysqli_stmt_get_result($mainStmt)
);

mysqli_stmt_close($mainStmt);

if (!$rfi) {
    die('RFI not found.');
}

$canAccess = false;

if (rfi_pdf_is_super_admin($conn)) {
    $canAccess = true;
} elseif ((int)($rfi['manager_employee_id'] ?? 0) === $employeeId) {
    $canAccess = true;
} elseif ((int)($rfi['team_lead_employee_id'] ?? 0) === $employeeId) {
    $canAccess = true;
} elseif ((int)($rfi['employee_id'] ?? 0) === $employeeId) {
    $canAccess = true;
}

if (!$canAccess) {
    die('RFI not found or access denied.');
}

$attachments = json_decode(
    (string)($rfi['attachments_json'] ?? '{}'),
    true
);

if (!is_array($attachments)) {
    $attachments = [];
}

$impacts = json_decode(
    (string)($rfi['impacts_json'] ?? '{}'),
    true
);

if (!is_array($impacts)) {
    $impacts = [];
}

class RFIPDF extends FPDF
{
    public array $meta = [];
    public string $logoPath = '';
    public string $fontFamily = 'Arial';

    public float $outerX = 10;
    public float $outerY = 10;
    public float $contentWidth = 190;
    public float $outerHeight = 277;
    public float $contentBottom = 270;

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
        $this->SetLineWidth(0.30);

        // Full-page border.
        $this->Rect(
            $this->outerX,
            $this->outerY,
            $this->contentWidth,
            $this->outerHeight
        );

        $x = $this->outerX;
        $y = $this->outerY;

        $logoWidth = 30;
        $titleWidth = 80;
        $metaWidth = 80;
        $headerHeight = 28;

        // Logo.
        $this->SetXY($x, $y);
        $this->Cell($logoWidth, $headerHeight, '', 1, 0, 'C');

        if ($this->logoPath !== '' && file_exists($this->logoPath)) {
            $imageInfo = @getimagesize($this->logoPath);

            if ($imageInfo) {
                [$imageWidth, $imageHeight] = $imageInfo;

                $padding = 2;
                $boxWidth = $logoWidth - ($padding * 2);
                $boxHeight = $headerHeight - ($padding * 2);
                $ratio = min(
                    $boxWidth / max($imageWidth, 1),
                    $boxHeight / max($imageHeight, 1)
                );

                $drawWidth = $imageWidth * $ratio;
                $drawHeight = $imageHeight * $ratio;

                $this->Image(
                    $this->logoPath,
                    $x + (($logoWidth - $drawWidth) / 2),
                    $y + (($headerHeight - $drawHeight) / 2),
                    $drawWidth,
                    $drawHeight
                );
            }
        }

        // Title.
        $this->SetXY($x + $logoWidth, $y);
        $this->SetFillColor(220, 220, 220);
        $this->SetFont($this->fontFamily, 'B', 13);

        $this->Cell(
            $titleWidth,
            $headerHeight,
            'REQUEST FOR INFORMATION (RFI)',
            1,
            0,
            'C',
            true
        );

        // Header metadata.
        $metaX = $x + $logoWidth + $titleWidth;
        $rowHeight = $headerHeight / 4;
        $labelWidth = 28;
        $valueWidth = $metaWidth - $labelWidth;

        $rows = [
            ['RFI No', $this->meta['rfi_no'] ?? ''],
            ['Issue Date', $this->meta['issue_date'] ?? ''],
            ['Response Due', $this->meta['required_response_date'] ?? ''],
            ['Status', $this->meta['status'] ?? ''],
        ];

        foreach ($rows as $index => $row) {
            $rowY = $y + ($index * $rowHeight);

            $this->SetXY($metaX, $rowY);
            $this->SetFillColor(235, 235, 235);
            $this->SetFont($this->fontFamily, 'B', 8.2);
            $this->Cell(
                $labelWidth,
                $rowHeight,
                rfi_pdf_clean($row[0]),
                1,
                0,
                'L',
                true
            );

            $value = rfi_pdf_clean($row[1]);
            $fontSize = 8.2;
            $this->SetFont($this->fontFamily, '', $fontSize);

            while (
                $fontSize > 6
                && $this->GetStringWidth($value) > ($valueWidth - 2)
            ) {
                $fontSize -= 0.2;
                $this->SetFont($this->fontFamily, '', $fontSize);
            }

            $this->Cell(
                $valueWidth,
                $rowHeight,
                $value,
                1,
                0,
                'L'
            );
        }

        $this->SetXY($this->outerX, $y + $headerHeight + 6);
    }

    public function Footer(): void
    {
        $this->SetY(-18);
        $this->SetFont($this->fontFamily, 'I', 8.5);

        $company = rfi_pdf_clean(
            $this->meta['company_name'] ?? ''
        );

        $pageText = $this->PageNo() . ' / {nb}';
        $pageWidth = $this->GetStringWidth($pageText);

        $this->SetX($this->outerX + 3);
        $this->Cell(0, 10, $company, 0, 0, 'L');

        $this->SetX(
            ($this->GetPageWidth() - $pageWidth) / 2
        );
        $this->Cell($pageWidth, 10, $pageText, 0, 0, 'C');
    }

    public function ensureSpace(float $height): void
    {
        if ($this->GetY() + $height > $this->contentBottom) {
            $this->AddPage();
        }

        $this->SetX($this->outerX);
    }

    public function sectionTitle(
        string $title,
        float $minimumFollowingHeight = 0
    ): void {
        $height = 8;

        // Keep the section heading with its first content block.
        $this->ensureSpace($height + max(0, $minimumFollowingHeight));

        $this->SetX($this->outerX);
        $this->SetFillColor(141, 180, 226);
        $this->SetFont($this->fontFamily, 'B', 10);
        $this->Cell(
            $this->contentWidth,
            $height,
            rfi_pdf_clean($title),
            1,
            1,
            'L',
            true
        );
    }

    public function labelValue(
        string $label,
        string $value,
        float $labelWidth = 42
    ): void {
        $valueWidth = $this->contentWidth - $labelWidth;

        $this->SetFont($this->fontFamily, '', 9);
        $valueText = rfi_pdf_clean($value);
        $valueLines = max(
            $this->numberOfLines($valueWidth - 2, $valueText),
            1
        );

        $labelLines = max(
            $this->numberOfLines($labelWidth - 2, rfi_pdf_clean($label)),
            1
        );

        $height = max(8, max($valueLines, $labelLines) * 5 + 2);
        $this->ensureSpace($height);

        $x = $this->outerX;
        $y = $this->GetY();

        $this->SetFillColor(235, 235, 235);
        $this->Rect($x, $y, $labelWidth, $height, 'F');
        $this->Rect($x, $y, $labelWidth, $height);
        $this->Rect($x + $labelWidth, $y, $valueWidth, $height);

        $this->SetFont($this->fontFamily, 'B', 9);
        $labelText = rfi_pdf_clean($label);
        $labelTextHeight = $labelLines * 5;
        $this->SetXY(
            $x + 1,
            $y + max(1, ($height - $labelTextHeight) / 2)
        );
        $this->MultiCell($labelWidth - 2, 5, $labelText, 0, 'L');

        $this->SetFont($this->fontFamily, '', 9);
        $valueTextHeight = $valueLines * 5;
        $this->SetXY(
            $x + $labelWidth + 1,
            $y + max(1, ($height - $valueTextHeight) / 2)
        );
        $this->MultiCell($valueWidth - 2, 5, $valueText, 0, 'L');

        $this->SetXY($this->outerX, $y + $height);
    }

    public function twoColumnRow(
        string $label1,
        string $value1,
        string $label2,
        string $value2
    ): void {
        $columnWidth = $this->contentWidth / 2;
        $labelWidth = 29;
        $valueWidth = $columnWidth - $labelWidth;

        $this->SetFont($this->fontFamily, '', 8.5);

        $leftValue = rfi_pdf_clean($value1);
        $rightValue = rfi_pdf_clean($value2);
        $leftLabel = rfi_pdf_clean($label1);
        $rightLabel = rfi_pdf_clean($label2);

        $lineCounts = [
            $this->numberOfLines($valueWidth - 2, $leftValue),
            $this->numberOfLines($valueWidth - 2, $rightValue),
            $this->numberOfLines($labelWidth - 2, $leftLabel),
            $this->numberOfLines($labelWidth - 2, $rightLabel),
        ];

        $height = max(8, max($lineCounts) * 5 + 2);
        $this->ensureSpace($height);

        $x = $this->outerX;
        $y = $this->GetY();

        $pairs = [
            [$x, $leftLabel, $leftValue],
            [$x + $columnWidth, $rightLabel, $rightValue],
        ];

        foreach ($pairs as [$pairX, $labelText, $valueText]) {
            $this->SetFillColor(235, 235, 235);
            $this->Rect($pairX, $y, $labelWidth, $height, 'F');
            $this->Rect($pairX, $y, $labelWidth, $height);
            $this->Rect(
                $pairX + $labelWidth,
                $y,
                $valueWidth,
                $height
            );

            $this->SetFont($this->fontFamily, 'B', 8.5);
            $labelLines = max(
                1,
                $this->numberOfLines($labelWidth - 2, $labelText)
            );
            $this->SetXY(
                $pairX + 1,
                $y + max(1, ($height - ($labelLines * 5)) / 2)
            );
            $this->MultiCell(
                $labelWidth - 2,
                5,
                $labelText,
                0,
                'L'
            );

            $this->SetFont($this->fontFamily, '', 8.5);
            $valueLines = max(
                1,
                $this->numberOfLines($valueWidth - 2, $valueText)
            );
            $this->SetXY(
                $pairX + $labelWidth + 1,
                $y + max(1, ($height - ($valueLines * 5)) / 2)
            );
            $this->MultiCell(
                $valueWidth - 2,
                5,
                $valueText,
                0,
                'L'
            );
        }

        $this->SetXY($this->outerX, $y + $height);
    }

    public function paragraphBox(
        string $text,
        float $minimumHeight = 18
    ): void {
        $text = rfi_pdf_clean($text);

        if ($text === '') {
            $text = '-';
        }

        $this->SetFont($this->fontFamily, '', 9);

        $lines = max(
            $this->numberOfLines($this->contentWidth - 4, $text),
            1
        );

        $height = max($minimumHeight, ($lines * 5) + 4);
        $this->ensureSpace($height);

        $x = $this->outerX;
        $y = $this->GetY();

        $this->Rect($x, $y, $this->contentWidth, $height);

        $textHeight = $lines * 5;
        $this->SetXY(
            $x + 2,
            $y + max(2, ($height - $textHeight) / 2)
        );

        $this->MultiCell(
            $this->contentWidth - 4,
            5,
            $text,
            0,
            'L'
        );

        $this->SetXY($this->outerX, $y + $height);
    }

    public function numberOfLines(
        float $width,
        string $text
    ): int {
        $characterWidths = &$this->CurrentFont['cw'];

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

            if ($lineWidth > $maximumWidth) {
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
}

$attachmentLabels = [];

$attachmentMap = [
    'site_photograph' => 'Site Photograph',
    'markedup_drawing' => 'Marked-up Drawing',
    'sketch' => 'Sketch',
    'calculation_sheet' => 'Calculation Sheet',
];

foreach ($attachmentMap as $key => $label) {
    if (!empty($attachments[$key])) {
        $attachmentLabels[] = $label;
    }
}

$impactLabels = [];

$impactMap = [
    'work_stoppage' => 'Work Stoppage',
    'delay_in_schedule' => 'Delay in Schedule',
    'cost_impact' => 'Cost Impact',
    'quality_risk' => 'Quality Risk',
    'safety_risk' => 'Safety Risk',
];

foreach ($impactMap as $key => $label) {
    if (!empty($impacts[$key])) {
        $impactLabels[] = $label;
    }
}

$pdf = new RFIPDF('P', 'mm', 'A4');
$pdf->initialiseFonts();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(false);
$pdf->AliasNbPages('{nb}');

$pdf->setMetaData([
    'company_name' => $companyName,
    'rfi_no' => $rfi['rfi_no'] ?? '',
    'issue_date' => rfi_pdf_date($rfi['issue_date'] ?? ''),
    'required_response_date' => rfi_pdf_date(
        $rfi['required_response_date'] ?? ''
    ),
    'status' => $rfi['status'] ?? '',
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

$pdf->AddPage();

$pdf->sectionTitle('Project Details', 30);

$pdf->twoColumnRow(
    'Project',
    $rfi['project_name'] ?? '',
    'Client',
    $rfi['client_name']
        ?: ($rfi['current_client_name'] ?? '')
);

$pdf->twoColumnRow(
    'Location',
    $rfi['project_location']
        ?: ($rfi['current_project_location'] ?? ''),
    'Architect',
    $rfi['architect_consultant'] ?? ''
);

$pdf->twoColumnRow(
    'Contractor',
    $rfi['contractor'] ?? '',
    'Raised By',
    trim(
        (string)($rfi['raised_by_name'] ?? '')
        . ' - '
        . (string)($rfi['raised_by_designation'] ?? '')
    )
);

$pdf->sectionTitle('Subject', 12);
$pdf->paragraphBox($rfi['subject'] ?? '', 12);

$pdf->sectionTitle('Reference Documents', 24);

$pdf->twoColumnRow(
    'Drawing No',
    $rfi['drawing_no'] ?? '',
    'Drawing Date',
    rfi_pdf_date($rfi['drawing_date'] ?? '')
);

$pdf->twoColumnRow(
    'Specification',
    $rfi['specification_clause'] ?? '',
    'BOQ Ref',
    $rfi['boq_item_ref'] ?? ''
);

$pdf->labelValue(
    'Site Instruction Ref',
    $rfi['site_instruction_ref'] ?? ''
);

$pdf->sectionTitle('Description of Query', 30);
$pdf->paragraphBox($rfi['query_description'] ?? '', 30);

$pdf->sectionTitle('Attachments', 12);
$pdf->paragraphBox(
    $attachmentLabels
        ? implode(', ', $attachmentLabels)
        : 'No attachment type selected',
    12
);

$pdf->sectionTitle('Impact If Not Clarified', 28);

$pdf->labelValue(
    'Impact Categories',
    $impactLabels
        ? implode(', ', $impactLabels)
        : 'No impact category selected'
);

$pdf->paragraphBox($rfi['impact_brief'] ?? '', 18);

$pdf->sectionTitle('Proposed Solution', 18);
$pdf->paragraphBox($rfi['proposed_solution'] ?? '', 18);

$pdf->sectionTitle('Consultant / Architect Response', 36);

$pdf->twoColumnRow(
    'Response Date',
    rfi_pdf_date($rfi['response_date'] ?? ''),
    'Response By',
    $rfi['response_by'] ?? ''
);

$pdf->labelValue(
    'Decision',
    $rfi['response_decision'] ?? ''
);

$pdf->paragraphBox($rfi['response_remarks'] ?? '', 14);

$pdf->sectionTitle('Distribution & Preparation', 24);

$pdf->labelValue(
    'Distribute To',
    $rfi['report_distribute_to'] ?? ''
);

$pdf->twoColumnRow(
    'Prepared By',
    $rfi['prepared_by'] ?? '',
    'Status',
    $rfi['status'] ?? ''
);

$filename = 'RFI_'
    . rfi_pdf_safe_filename($rfi['rfi_no'] ?? ('ID_' . $viewId))
    . '.pdf';

if ($modeString) {
    $pdfBytes = $pdf->Output('S');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $GLOBALS['__RFI_PDF_RESULT__'] = [
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
        . rfi_pdf_rfc5987($filename)
    );

    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
}

$pdf->Output(
    $forceDownload ? 'D' : 'I',
    $filename
);

exit;