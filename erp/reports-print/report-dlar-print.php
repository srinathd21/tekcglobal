<?php
ob_start();
session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pms-helper.php';
require_once __DIR__ . '/../libs/fpdf.php';

if (empty($_SESSION['user_id']) && empty($_SESSION['employee_id'])) {
    header('Location: ../login.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection failed');
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
$forceDownload = isset($_GET['dl']) && $_GET['dl'] === '1';

function dlarp_clean($value): string
{
    if (is_array($value)) {
        $value = implode(' ', array_map('strval', $value));
    }
    $value = strip_tags((string)$value);
    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $value = preg_replace('/\s+/', ' ', trim($value));
    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);
    return $converted !== false ? $converted : $value;
}

function dlarp_date($date): string
{
    $date = trim((string)$date);
    if ($date === '' || $date === '0000-00-00') {
        return '';
    }
    $time = strtotime($date);
    return $time ? date('d-m-Y', $time) : $date;
}

function dlarp_rows($json): array
{
    $rows = json_decode((string)$json, true);
    return is_array($rows) ? $rows : [];
}

function dlarp_safe_filename($value): string
{
    $value = dlarp_clean($value);
    $value = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $value);
    $value = preg_replace('/[^A-Za-z0-9 ._\-]/', '_', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value, " ._-");
}

function dlarp_is_super_admin(mysqli $conn): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    $userId = (int)$_SESSION['user_id'];
    $query = mysqli_query($conn, "
        SELECT r.id
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = $userId
          AND (r.role_slug = 'super-admin' OR LOWER(r.role_name) = 'super admin')
        LIMIT 1
    ");
    return $query && mysqli_num_rows($query) > 0;
}

function dlarp_has_view_access(mysqli $conn): bool
{
    if (dlarp_is_super_admin($conn)) {
        return true;
    }
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    $userId = (int)$_SESSION['user_id'];
    $query = mysqli_query($conn, "
        SELECT MAX(COALESCE(rtra.can_view, 0)) AS allowed
        FROM user_roles ur
        INNER JOIN report_type_role_access rtra ON rtra.role_id = ur.role_id
        INNER JOIN master_report_types rt ON rt.id = rtra.report_type_id
        WHERE ur.user_id = $userId
          AND rt.report_code = 'DLAR'
          AND rt.is_active = 1
    ");
    return $query && ($row = mysqli_fetch_assoc($query)) && (int)($row['allowed'] ?? 0) === 1;
}

$requestedId = (int)($_GET['view'] ?? $_GET['id'] ?? 0);
if ($requestedId <= 0) {
    die('Invalid DLAR id');
}

$viewId = $requestedId;

// Reports Hub normally passes dlar_reports.id. Resolve a submission id as a safe fallback.
$directQ = mysqli_query($conn, "SELECT id FROM dlar_reports WHERE id = $viewId LIMIT 1");
if (!$directQ || mysqli_num_rows($directQ) === 0) {
    $submissionColumns = [];
    $columnQ = mysqli_query($conn, "SHOW COLUMNS FROM project_report_submissions");
    while ($columnQ && ($columnRow = mysqli_fetch_assoc($columnQ))) {
        $submissionColumns[$columnRow['Field']] = true;
    }

    $referenceColumns = [];
    foreach (['report_reference_id', 'source_id', 'reference_id'] as $columnName) {
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
            mysqli_stmt_bind_param($submissionStmt, 'i', $requestedId);
            mysqli_stmt_execute($submissionStmt);
            $submissionResult = mysqli_stmt_get_result($submissionStmt);
            $submissionRow = mysqli_fetch_assoc($submissionResult);
            mysqli_stmt_close($submissionStmt);

            if ($submissionRow) {
                foreach (['report_reference_id', 'source_id', 'reference_id'] as $columnName) {
                    $candidateId = (int)($submissionRow[$columnName] ?? 0);
                    if ($candidateId <= 0) continue;

                    $candidateQ = mysqli_query($conn, "SELECT id FROM dlar_reports WHERE id = $candidateId LIMIT 1");
                    if ($candidateQ && mysqli_num_rows($candidateQ) > 0) {
                        $viewId = $candidateId;
                        break;
                    }
                }
            }
        }
    }
}

$stmt = mysqli_prepare($conn, "
    SELECT r.*, p.project_name AS current_project_name, p.project_location,
           p.manager_employee_id, p.team_lead_employee_id,
           c.client_name AS current_client_name
    FROM dlar_reports r
    INNER JOIN projects p ON p.id = r.project_id
    LEFT JOIN clients c ON c.id = p.client_id
    WHERE r.id = ?
    LIMIT 1
");
if (!$stmt) {
    die('SQL Error: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, 'i', $viewId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$report = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$report) {
    die('DLAR not found');
}

$canAccess = dlarp_is_super_admin($conn);
if (!$canAccess && dlarp_has_view_access($conn)) {
    $projectId = (int)$report['project_id'];
    $assignmentQuery = mysqli_query($conn, "
        SELECT p.id
        FROM projects p
        WHERE p.id = $projectId
          AND (
                p.manager_employee_id = $employeeId
             OR p.team_lead_employee_id = $employeeId
             OR EXISTS (
                    SELECT 1
                    FROM project_assignments pa
                    WHERE pa.project_id = p.id
                      AND pa.employee_id = $employeeId
                      AND pa.status = 'active'
                )
             OR " . (int)$report['employee_id'] . " = $employeeId
          )
        LIMIT 1
    ");
    $canAccess = $assignmentQuery && mysqli_num_rows($assignmentQuery) > 0;
}

if (!$canAccess) {
    die('DLAR not found or not allowed');
}

$companyName = 'TEK-C | A UKB Group Company';
$companyLogo = '';
$companyQuery = @mysqli_query($conn, "SELECT company_name, logo_path FROM company_details WHERE id = 1 LIMIT 1");
if ($companyQuery && ($company = mysqli_fetch_assoc($companyQuery))) {
    $companyName = (string)($company['company_name'] ?: $companyName);
    $companyLogo = (string)($company['logo_path'] ?? '');
}

$projectName = dlarp_clean($report['project_name'] ?: $report['current_project_name']);
$clientName = dlarp_clean($report['client_name'] ?: $report['current_client_name']);
$architectName = dlarp_clean($report['architect_name'] ?? '');
$pmcName = dlarp_clean($report['pmc_name'] ?? '');
$dateVersion = dlarp_clean($report['date_version'] ?? '');
$dlarNo = dlarp_clean($report['dlar_no'] ?? '');
$reportDate = dlarp_date($report['report_date'] ?? '');
$items = dlarp_rows($report['items_json'] ?? '');

class DLARPDF extends FPDF
{
    public $meta = [];
    public $logoPath = '';
    public $ff = 'Arial';
    public $outerX = 5;
    public $outerY = 5;
    public $grey = [220, 220, 220];

    public function InitFonts(): void
    {
        $fontDir = __DIR__ . '/../libs/fpdf/font/';
        if (file_exists($fontDir . 'calibri.php') && file_exists($fontDir . 'calibrib.php')) {
            $this->AddFont('Calibri', '', 'calibri.php');
            $this->AddFont('Calibri', 'B', 'calibrib.php');
            if (file_exists($fontDir . 'calibrii.php')) $this->AddFont('Calibri', 'I', 'calibrii.php');
            if (file_exists($fontDir . 'calibriz.php')) $this->AddFont('Calibri', 'BI', 'calibriz.php');
            $this->ff = 'Calibri';
        }
    }

    public function SetMetaData(array $meta): void
    {
        $this->meta = $meta;
    }

    public function Header(): void
    {
        $this->SetLineWidth(0.25);
        $outerW = $this->GetPageWidth() - 10;
        $outerH = $this->GetPageHeight() - 10;
        $this->Rect($this->outerX, $this->outerY, $outerW, $outerH);

        $headerH = 28;
        $logoW = 28;
        $rightW = 74;
        $titleW = $outerW - $logoW - $rightW;

        $this->Rect($this->outerX, $this->outerY, $logoW, $headerH);
        if ($this->logoPath !== '' && file_exists($this->logoPath)) {
            $this->Image($this->logoPath, $this->outerX + 3, $this->outerY + 3, $logoW - 6, $headerH - 6);
        }

        $this->SetFillColor(...$this->grey);
        $this->Rect($this->outerX + $logoW, $this->outerY, $titleW, $headerH, 'FD');
        $this->SetFont($this->ff, 'B', 14);
        $this->SetXY($this->outerX + $logoW, $this->outerY + 8);
        $this->Cell($titleW, 8, 'DELAY ANALYSIS REPORT (DLAR)', 0, 0, 'C');

        $rx = $this->outerX + $logoW + $titleW;
        $rowHeight = $headerH / 5;
        $labelW = 23;
        $valueW = $rightW - $labelW;
        $rows = [
            ['Project', $this->meta['project_name'] ?? ''],
            ['Client', $this->meta['client_name'] ?? ''],
            ['Architect', $this->meta['architect_name'] ?? ''],
            ['PMC', $this->meta['pmc_name'] ?? ''],
            ['Date/Version', $this->meta['date_version'] ?? ''],
        ];

        foreach ($rows as $index => $row) {
            $y = $this->outerY + ($index * $rowHeight);
            $this->SetXY($rx, $y);
            $this->SetFont($this->ff, 'B', 8.2);
            $this->Cell($labelW, $rowHeight, dlarp_clean($row[0]), 1, 0, 'L');
            $text = dlarp_clean($row[1]);
            $fontSize = 8.2;
            $this->SetFont($this->ff, '', $fontSize);
            while ($fontSize > 5.8 && $this->GetStringWidth($text) > $valueW - 1.5) {
                $fontSize -= 0.3;
                $this->SetFont($this->ff, '', $fontSize);
            }
            $this->Cell($valueW, $rowHeight, $text, 1, 0, 'L');
        }

        $this->SetY($this->outerY + $headerH + 6);
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont($this->ff, 'I', 8);
        $company = dlarp_clean($this->meta['company'] ?? '');
        $pageText = $this->PageNo() . ' / {nb}';
        $this->Cell(0, 6, $company, 0, 0, 'L');
        $width = $this->GetStringWidth($pageText);
        $this->SetX(($this->GetPageWidth() - $width) / 2);
        $this->Cell($width, 6, $pageText, 0, 0, 'C');
    }

    public function NbLines($w, $txt): int
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c === ' ') $sep = $i;
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep === -1) { if ($i === $j) $i++; }
                else { $i = $sep + 1; }
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else { $i++; }
        }
        return $nl;
    }

    public function EnsureSpace(float $height): void
    {
        if ($this->GetY() + $height > $this->GetPageHeight() - 15) {
            $this->AddPage();
        }
    }

    public function FitCellText(float $x, float $y, float $w, float $h, string $text, bool $fill = false, float $baseSize = 7.2): void
    {
        $this->Rect($x, $y, $w, $h, $fill ? 'FD' : 'D');
        $text = dlarp_clean($text);
        if ($text === '') return;
        $size = $baseSize;
        $this->SetFont($this->ff, 'B', $size);
        while ($size > 4.8 && $this->GetStringWidth($text) > $w - 1.2) {
            $size -= 0.25;
            $this->SetFont($this->ff, 'B', $size);
        }
        $this->SetXY($x, $y + (($h - 4) / 2));
        $this->Cell($w, 4, $text, 0, 0, 'C');
    }

    public function MultiRow(float $x, array $widths, array $cells, float $lineH = 4.5, array $aligns = []): void
    {
        $maxLines = 1;
        foreach ($cells as $index => $cell) {
            $maxLines = max($maxLines, $this->NbLines($widths[$index], dlarp_clean($cell)));
        }
        $height = max(8, $maxLines * $lineH);
        $this->EnsureSpace($height);
        $y = $this->GetY();
        $currentX = $x;

        foreach ($cells as $index => $cell) {
            $width = $widths[$index];
            $align = $aligns[$index] ?? 'L';
            $text = dlarp_clean($cell);
            $this->Rect($currentX, $y, $width, $height);
            if ($text !== '') {
                $lines = $this->NbLines($width - 2, $text);
                $textHeight = $lines * $lineH;
                $startY = $y + max(0, ($height - $textHeight) / 2);
                $this->SetXY($currentX + 1, $startY);
                $this->MultiCell($width - 2, $lineH, $text, 0, $align);
            }
            $currentX += $width;
        }
        $this->SetXY($x, $y + $height);
    }

    public function EmptyRow(float $x, array $widths, float $height = 7.5): void
    {
        $this->EnsureSpace($height);
        $y = $this->GetY();
        $currentX = $x;
        foreach ($widths as $width) {
            $this->Rect($currentX, $y, $width, $height);
            $currentX += $width;
        }
        $this->SetXY($x, $y + $height);
    }
}

$meta = [
    'company' => $companyName,
    'project_name' => $projectName,
    'client_name' => $clientName,
    'architect_name' => $architectName,
    'pmc_name' => $pmcName,
    'date_version' => $dateVersion !== '' ? $dateVersion : $reportDate,
];

$pdf = new DLARPDF('L', 'mm', 'A4');
$pdf->InitFonts();
$pdf->SetMargins(5, 5, 5);
$pdf->SetAutoPageBreak(false);
$pdf->AliasNbPages('{nb}');
$pdf->SetMetaData($meta);

$logoCandidates = [];
if ($companyLogo !== '') {
    $logoCandidates[] = __DIR__ . '/../' . ltrim($companyLogo, '/');
    $logoCandidates[] = __DIR__ . '/../../' . ltrim($companyLogo, '/');
}
$logoCandidates = array_merge($logoCandidates, [
    __DIR__ . '/../assets/img/logo.png',
    __DIR__ . '/../assets/logo.png',
    __DIR__ . '/../public/logo.png',
    __DIR__ . '/../logo.png',
]);
foreach ($logoCandidates as $candidate) {
    if (file_exists($candidate)) {
        $pdf->logoPath = $candidate;
        break;
    }
}

$pdf->AddPage();
$x = 5;
$wSL = 12;
$wTask = 66;
$wPlanned = 30;
$wActual = 30;
$wDelay = 18;
$wResponse = 26;
$wOpened = 36;
$wReminders = 43;
$wClosed = 26;
$widths = [$wSL, $wTask, $wPlanned, $wActual, $wDelay, $wResponse, $wOpened, $wReminders, $wClosed];

$pdf->SetFillColor(141, 180, 226);
$topH = 9;
$subH = 10;
$totalH = $topH + $subH;
$headerY = $pdf->GetY();

$pdf->FitCellText($x, $headerY, $wSL, $totalH, 'SL NO', true);
$pdf->FitCellText($x + $wSL, $headerY, $wTask, $totalH, 'DELAYED TASK', true);

$xCompletion = $x + $wSL + $wTask;
$pdf->Rect($xCompletion, $headerY, $wPlanned + $wActual, $topH, 'FD');
$pdf->SetFont($pdf->ff, 'B', 7);
$pdf->SetXY($xCompletion, $headerY + 2.2);
$pdf->Cell($wPlanned + $wActual, 4, 'COMPLETION DATE', 0, 0, 'C');

$xDelay = $xCompletion + $wPlanned + $wActual;
$pdf->FitCellText($xDelay, $headerY, $wDelay, $totalH, 'DELAY (DAYS)', true, 6.0);

$xResponse = $xDelay + $wDelay;
$pdf->FitCellText($xResponse, $headerY, $wResponse, $totalH, 'DELAY RESPONSE BY', true, 5.8);

$xAction = $xResponse + $wResponse;
$pdf->Rect($xAction, $headerY, $wOpened + $wReminders + $wClosed, $topH, 'FD');
$pdf->SetFont($pdf->ff, 'B', 6.2);
$pdf->SetXY($xAction, $headerY + 2.2);
$pdf->Cell($wOpened + $wReminders + $wClosed, 4, 'ENGINEER IN CHARGE / PMC ACTION', 0, 0, 'C');

$subY = $headerY + $topH;
$pdf->FitCellText($xCompletion, $subY, $wPlanned, $subH, 'PLANNED', true, 6.5);
$pdf->FitCellText($xCompletion + $wPlanned, $subY, $wActual, $subH, 'ACTUAL', true, 6.5);
$pdf->FitCellText($xAction, $subY, $wOpened, $subH, 'ISSUES OPENED ON', true, 5.2);
$pdf->FitCellText($xAction + $wOpened, $subY, $wReminders, $subH, 'REMINDERS / FOLLOW UPS DATED', true, 5.0);
$pdf->FitCellText($xAction + $wOpened + $wReminders, $subY, $wClosed, $subH, 'ISSUES CLOSED ON', true, 5.2);

$pdf->SetY($headerY + $totalH);
$pdf->SetFont($pdf->ff, '', 8);

$filledRows = 0;
foreach ($items as $index => $item) {
    if (!is_array($item)) continue;
    $pdf->MultiRow(
        $x,
        $widths,
        [
            (string)($item['sl_no'] ?? ($index + 1)),
            $item['delayed_task'] ?? '',
            dlarp_date($item['planned_date'] ?? ''),
            dlarp_date($item['actual_date'] ?? ''),
            $item['delay_days'] ?? '',
            $item['delay_response_by'] ?? '',
            dlarp_date($item['issues_opened_on'] ?? ''),
            $item['reminders_dated'] ?? '',
            dlarp_date($item['issues_closed_on'] ?? ''),
        ],
        4.5,
        ['C', 'L', 'C', 'C', 'C', 'C', 'C', 'L', 'C']
    );
    $filledRows++;
}

for ($index = $filledRows; $index < 10; $index++) {
    $pdf->EmptyRow($x, $widths, 7.5);
}

$sitePart = dlarp_safe_filename($projectName) ?: 'PROJECT';
$numberPart = dlarp_safe_filename($dlarNo) ?: ('ID_' . $viewId);
$datePart = dlarp_safe_filename($reportDate) ?: date('d-m-Y');
$filename = $sitePart . '_DLAR_' . $numberPart . '_Dated_' . $datePart . '.pdf';

if ($modeString) {
    $bytes = $pdf->Output('S');
    while (ob_get_level() > 0) ob_end_clean();
    $GLOBALS['__DLAR_PDF_RESULT__'] = ['filename' => $filename, 'bytes' => $bytes];
    return;
}

while (ob_get_level() > 0) ob_end_clean();
if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
}
$pdf->Output($forceDownload ? 'D' : 'I', $filename);
exit;