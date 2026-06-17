<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
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
        "SELECT employee_id FROM users WHERE id = $userId LIMIT 1"
    );

    if ($query && ($row = mysqli_fetch_assoc($query))) {
        $employeeId = (int)($row['employee_id'] ?? 0);
    }
}

$modeString = isset($_GET['mode']) && $_GET['mode'] === 'string';
$forceDownload = isset($_GET['dl']) && $_GET['dl'] == '1';

function chk_pdf_clean($value): string
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

function chk_pdf_date($date): string
{
    $date = trim((string)$date);

    if ($date === '' || $date === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('d-m-Y', $timestamp) : $date;
}

function chk_pdf_rows($json): array
{
    $rows = json_decode((string)$json, true);
    return is_array($rows) ? $rows : [];
}

function chk_pdf_is_super_admin(mysqli $conn): bool
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
          AND r.role_slug = 'super-admin'
        LIMIT 1
    ");

    return $query && mysqli_num_rows($query) > 0;
}

$requestedId = (int)($_GET['view'] ?? $_GET['id'] ?? 0);

if ($requestedId <= 0) {
    die('Invalid Checklist ID.');
}

$viewId = $requestedId;

$direct = mysqli_query(
    $conn,
    "SELECT id FROM checklist_reports WHERE id = $viewId LIMIT 1"
);

if (!$direct || mysqli_num_rows($direct) === 0) {
    $columns = [];
    $query = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM project_report_submissions"
    );

    while ($query && ($column = mysqli_fetch_assoc($query))) {
        $columns[$column['Field']] = true;
    }

    $select = [];

    foreach (['report_reference_id', 'source_id', 'reference_id'] as $column) {
        if (isset($columns[$column])) {
            $select[] = "`$column`";
        }
    }

    if ($select) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT " . implode(',', $select) . "
             FROM project_report_submissions
             WHERE id = ?
             LIMIT 1"
        );

        mysqli_stmt_bind_param($stmt, 'i', $requestedId);
        mysqli_stmt_execute($stmt);

        $submission = mysqli_fetch_assoc(
            mysqli_stmt_get_result($stmt)
        );

        mysqli_stmt_close($stmt);

        if ($submission) {
            foreach (['report_reference_id', 'source_id', 'reference_id'] as $column) {
                $candidate = (int)($submission[$column] ?? 0);

                if ($candidate <= 0) {
                    continue;
                }

                $check = mysqli_query(
                    $conn,
                    "SELECT id FROM checklist_reports WHERE id = $candidate LIMIT 1"
                );

                if ($check && mysqli_num_rows($check) > 0) {
                    $viewId = $candidate;
                    break;
                }
            }
        }
    }
}

$stmt = mysqli_prepare($conn, "
    SELECT
        r.*,
        p.project_location,
        p.manager_employee_id,
        p.team_lead_employee_id,
        c.client_name AS current_client_name
    FROM checklist_reports r
    INNER JOIN projects p ON p.id = r.project_id
    LEFT JOIN clients c ON c.id = p.client_id
    WHERE r.id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, 'i', $viewId);
mysqli_stmt_execute($stmt);

$report = mysqli_fetch_assoc(
    mysqli_stmt_get_result($stmt)
);

mysqli_stmt_close($stmt);

if (!$report) {
    die('Checklist not found.');
}

$canAccess =
    chk_pdf_is_super_admin($conn)
    || (int)($report['manager_employee_id'] ?? 0) === $employeeId
    || (int)($report['team_lead_employee_id'] ?? 0) === $employeeId
    || (int)($report['employee_id'] ?? 0) === $employeeId;

if (!$canAccess) {
    die('Checklist not found or access denied.');
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
}

$items = chk_pdf_rows($report['checklist_json'] ?? '');
$grouped = [];

foreach ($items as $item) {
    $section = trim((string)($item['section'] ?? 'Others'));

    if ($section === '') {
        $section = 'Others';
    }

    $grouped[$section][] = $item;
}

class TekcChecklistPdf extends FPDF
{
    public $checklistMeta = [];
    public $checklistLogo = '';
    public $checklistFont = 'Arial';
    public $outerX = 10;
    public $outerY = 10;

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

            $this->checklistFont = 'Calibri';
        }
    }

    public function Header(): void
    {
        $pageWidth = $this->GetPageWidth();
        $usableWidth = $pageWidth - ($this->outerX * 2);

        $this->SetLineWidth(0.3);
        $this->Rect(
            $this->outerX,
            $this->outerY,
            $usableWidth,
            $this->GetPageHeight() - ($this->outerY * 2)
        );

        if ($this->PageNo() !== 1) {
            $this->SetY($this->outerY + 5);
            return;
        }

        $logoWidth = 28;
        $metaWidth = 98;
        $headerHeight = 30;
        $titleWidth = $usableWidth - $logoWidth - $metaWidth;

        $this->SetXY($this->outerX, $this->outerY);
        $this->Cell($logoWidth, $headerHeight, '', 1, 0);

        if ($this->checklistLogo && file_exists($this->checklistLogo)) {
            $info = @getimagesize($this->checklistLogo);

            if ($info) {
                [$imageWidth, $imageHeight] = $info;

                $ratio = min(
                    ($logoWidth - 4) / max($imageWidth, 1),
                    ($headerHeight - 4) / max($imageHeight, 1)
                );

                $drawWidth = $imageWidth * $ratio;
                $drawHeight = $imageHeight * $ratio;

                $this->Image(
                    $this->checklistLogo,
                    $this->outerX + (($logoWidth - $drawWidth) / 2),
                    $this->outerY + (($headerHeight - $drawHeight) / 2),
                    $drawWidth,
                    $drawHeight
                );
            }
        }

        $this->SetFillColor(220, 220, 220);
        $this->SetFont($this->checklistFont, 'B', 13);

        $this->Cell(
            $titleWidth,
            $headerHeight,
            'PROJECT ENGINEER DAILY ACTIVITY CHECKLIST',
            1,
            0,
            'C',
            true
        );

        $metaX = $this->outerX + $logoWidth + $titleWidth;
        $rowHeight = $headerHeight / 2;
        $labelWidth = 35;
        $valueWidth = $metaWidth - $labelWidth;

        $rows = [
            ['Document No.', $this->checklistMeta['doc_no'] ?? ''],
            ['Date', $this->checklistMeta['checklist_date'] ?? ''],
        ];

        foreach ($rows as $index => $row) {
            $this->SetXY(
                $metaX,
                $this->outerY + ($index * $rowHeight)
            );

            $this->SetFont($this->checklistFont, 'B', 9);
            $this->Cell(
                $labelWidth,
                $rowHeight,
                chk_pdf_clean($row[0]),
                1,
                0,
                'L'
            );

            $this->SetFont($this->checklistFont, '', 9);
            $this->Cell(
                $valueWidth,
                $rowHeight,
                chk_pdf_clean($row[1]),
                1,
                0,
                'L'
            );
        }

        $this->SetY($this->outerY + $headerHeight + 5);
    }

    public function Footer(): void
    {
        $this->SetY(-16);
        $this->SetFont($this->checklistFont, 'I', 8.5);

        $this->SetX(13);
        $this->Cell(
            0,
            10,
            chk_pdf_clean($this->checklistMeta['company_name'] ?? ''),
            0,
            0,
            'L'
        );

        $this->SetX(($this->GetPageWidth() / 2) - 10);
        $this->Cell(
            20,
            10,
            $this->PageNo() . ' / {nb}',
            0,
            0,
            'C'
        );
    }

    public function ensureSpace(float $height): void
    {
        if ($this->GetY() + $height > $this->GetPageHeight() - 22) {
            $this->AddPage();
        }
    }

    public function sectionTitle(string $title): void
    {
        $this->ensureSpace(13);

        $this->SetFont($this->checklistFont, 'B', 10);
        $this->SetFillColor(141, 180, 226);
        $this->SetTextColor(0, 0, 0);

        $this->Cell(
            0,
            9,
            chk_pdf_clean($title),
            1,
            1,
            'L',
            true
        );

        $this->Ln(2);
    }

    public function checklistItem(string $label, bool $checked): void
    {
        $this->ensureSpace(10);

        $x = $this->GetX();
        $y = $this->GetY();
        $boxSize = 5;

        $this->Rect($x + 2, $y + 1.5, $boxSize, $boxSize);

        if ($checked) {
            $this->SetLineWidth(0.7);
            $this->Line($x + 3, $y + 4, $x + 4.5, $y + 5.5);
            $this->Line($x + 4.5, $y + 5.5, $x + 7, $y + 2.5);
            $this->SetLineWidth(0.2);
        }

        $this->SetXY($x + 10, $y);
        $this->SetFont($this->checklistFont, '', 9.5);
        $this->MultiCell(
            $this->GetPageWidth() - $this->rMargin - ($x + 10),
            7,
            chk_pdf_clean($label),
            0,
            'L'
        );

        $this->SetY(max($this->GetY(), $y + 8));
    }

    public function infoRow(string $label, string $value): void
    {
        $labelWidth = 42;
        $valueWidth = $this->GetPageWidth()
            - $this->lMargin
            - $this->rMargin
            - $labelWidth;

        $this->SetFont($this->checklistFont, 'B', 9.5);
        $this->Cell($labelWidth, 8, chk_pdf_clean($label), 1, 0, 'L');

        $this->SetFont($this->checklistFont, '', 9.5);
        $this->Cell($valueWidth, 8, chk_pdf_clean($value), 1, 1, 'L');
    }
}

$pdf = new TekcChecklistPdf('P', 'mm', 'A3');
$pdf->initialiseFonts();
$pdf->SetMargins(18, 10, 18);
$pdf->SetAutoPageBreak(false);
$pdf->AliasNbPages('{nb}');

$pdf->checklistMeta = [
    'company_name' => $companyName,
    'doc_no' => $report['doc_no'] ?? '',
    'checklist_date' => chk_pdf_date($report['checklist_date'] ?? ''),
];

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
}

foreach ($logoCandidates as $path) {
    if ($path && file_exists($path)) {
        $pdf->checklistLogo = $path;
        break;
    }
}

$pdf->AddPage();

$pdf->infoRow('Project', $report['project_name'] ?? '');
$pdf->infoRow(
    'Client',
    $report['client_name']
        ?: ($report['current_client_name'] ?? '')
);
$pdf->infoRow('PMC', $companyName);
$pdf->Ln(5);

$sectionOrder = [
    'Daily Responsibilities',
    'Quality Control',
    'Coordination',
    'Safety & Compliance',
    'Documentation',
    'Escalation & Communication',
    'Weekly/Monthly Duties',
    'Professional Conduct',
];

foreach ($sectionOrder as $sectionName) {
    if (!isset($grouped[$sectionName])) {
        continue;
    }

    $pdf->sectionTitle($sectionName);

    foreach ($grouped[$sectionName] as $item) {
        $pdf->checklistItem(
            (string)($item['label'] ?? ''),
            (int)($item['checked'] ?? 0) === 1
        );
    }

    $pdf->Ln(2);
    unset($grouped[$sectionName]);
}

foreach ($grouped as $sectionName => $sectionItems) {
    $pdf->sectionTitle($sectionName);

    foreach ($sectionItems as $item) {
        $pdf->checklistItem(
            (string)($item['label'] ?? ''),
            (int)($item['checked'] ?? 0) === 1
        );
    }

    $pdf->Ln(2);
}

$pdf->Ln(6);
$pdf->sectionTitle('Sign-off');
$pdf->infoRow(
    'Project Engineer',
    $report['project_engineer'] ?? ''
);
$pdf->infoRow(
    'PMC Lead',
    $report['pmc_lead'] ?? ''
);

$filename = 'Checklist_'
    . preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        chk_pdf_clean($report['doc_no'] ?? ('ID_' . $viewId))
    )
    . '_'
    . chk_pdf_date($report['checklist_date'] ?? '')
    . '.pdf';

if ($modeString) {
    $bytes = $pdf->Output('S');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $GLOBALS['__CHECKLIST_PDF_RESULT__'] = [
        'filename' => $filename,
        'bytes' => $bytes,
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
