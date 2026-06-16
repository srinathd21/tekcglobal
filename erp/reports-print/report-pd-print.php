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
        "SELECT employee_id FROM users WHERE id = $userId LIMIT 1"
    );

    if ($query && ($row = mysqli_fetch_assoc($query))) {
        $employeeId = (int)($row['employee_id'] ?? 0);
        $_SESSION['employee_id'] = $employeeId;
    }
}

$modeString = isset($_GET['mode']) && $_GET['mode'] === 'string';
$forceDownload = isset($_GET['dl']) && $_GET['dl'] == '1';

function pd_pdf_clean($value): string
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

function pd_pdf_date($date): string
{
    $date = trim((string)$date);

    if ($date === '' || $date === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('d-m-Y', $timestamp) : $date;
}

function pd_pdf_is_super_admin(mysqli $conn): bool
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

$requestedId = (int)($_GET['view'] ?? $_GET['id'] ?? 0);

if ($requestedId <= 0) {
    die('Invalid PD ID.');
}

$viewId = $requestedId;

$direct = mysqli_query(
    $conn,
    "SELECT id FROM pd_main WHERE id = $viewId LIMIT 1"
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
                    "SELECT id FROM pd_main WHERE id = $candidate LIMIT 1"
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
        pdr.*,
        p.project_location,
        p.manager_employee_id,
        p.team_lead_employee_id,
        c.client_name AS current_client_name
    FROM pd_main pdr
    INNER JOIN projects p
        ON p.id = COALESCE(NULLIF(pdr.project_id, 0), pdr.site_id)
    LEFT JOIN clients c ON c.id = p.client_id
    WHERE pdr.id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, 'i', $viewId);
mysqli_stmt_execute($stmt);

$pd = mysqli_fetch_assoc(
    mysqli_stmt_get_result($stmt)
);

mysqli_stmt_close($stmt);

if (!$pd) {
    die('PD not found.');
}

$canAccess =
    pd_pdf_is_super_admin($conn)
    || (int)($pd['manager_employee_id'] ?? 0) === $employeeId
    || (int)($pd['team_lead_employee_id'] ?? 0) === $employeeId
    || (int)($pd['prepared_by'] ?? 0) === $employeeId;

if (!$canAccess) {
    die('PD not found or access denied.');
}

$stmt = mysqli_prepare($conn, "
    SELECT *
    FROM pd_details
    WHERE pd_main_id = ?
      AND TRIM(COALESCE(stakeholder_type, '')) <> ''
      AND LOWER(TRIM(stakeholder_type)) NOT IN (
          '-- select stakeholder --',
          'select stakeholder',
          '- select stakeholder -'
      )
    ORDER BY sl_no, id
");

mysqli_stmt_bind_param($stmt, 'i', $viewId);
mysqli_stmt_execute($stmt);

$details = mysqli_fetch_all(
    mysqli_stmt_get_result($stmt),
    MYSQLI_ASSOC
);

mysqli_stmt_close($stmt);

class PDCURRENT_PDF extends FPDF
{
    public array $meta = [];
    public string $logoPath = '';
    public string $fontFamily = 'Arial';
    public array $widths = [12, 50, 45, 40, 40, 45, 45];

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

    public function Header(): void
    {
        $x = 10;
        $y = 10;
        $totalWidth = 277;
        $headerHeight = 28;
        $logoWidth = 28;
        $metaWidth = 90;
        $titleWidth = $totalWidth - $logoWidth - $metaWidth;

        $this->SetLineWidth(0.3);
        $this->Rect($x, $y, $totalWidth, 190);

        $this->Rect($x, $y, $logoWidth, $headerHeight);

        if ($this->logoPath && file_exists($this->logoPath)) {
            $info = @getimagesize($this->logoPath);

            if ($info) {
                [$imageWidth, $imageHeight] = $info;

                $ratio = min(
                    ($logoWidth - 4) / max($imageWidth, 1),
                    ($headerHeight - 4) / max($imageHeight, 1)
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

        $this->SetXY($x + $logoWidth, $y);
        $this->SetFillColor(220, 220, 220);
        $this->SetFont($this->fontFamily, 'B', 13);

        $this->Cell(
            $titleWidth,
            $headerHeight,
            'PROJECT DIRECTORY (PD)',
            1,
            0,
            'C',
            true
        );

        $metaX = $x + $logoWidth + $titleWidth;
        $rowHeight = $headerHeight / 5;
        $labelWidth = 30;
        $valueWidth = $metaWidth - $labelWidth;

        $rows = [
            ['Project', $this->meta['project_name'] ?? ''],
            ['Client', $this->meta['client_name'] ?? ''],
            ['Architect', $this->meta['architect'] ?? ''],
            ['PMC', $this->meta['pmc'] ?? ''],
            ['Date/Version', ($this->meta['pd_date'] ?? '') . ' / ' . ($this->meta['version'] ?? '')],
        ];

        foreach ($rows as $index => $row) {
            $rowY = $y + ($index * $rowHeight);

            $this->SetXY($metaX, $rowY);
            $this->SetFillColor(235, 235, 235);
            $this->SetFont($this->fontFamily, 'B', 8.5);

            $this->Cell(
                $labelWidth,
                $rowHeight,
                pd_pdf_clean($row[0]),
                1,
                0,
                'L',
                true
            );

            $value = pd_pdf_clean($row[1]);
            $fontSize = 8.5;
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

        $this->SetXY(10, $y + $headerHeight + 6);
    }

    public function Footer(): void
    {
        $this->SetY(-17);
        $this->SetFont($this->fontFamily, 'I', 8.5);

        $this->SetX(13);
        $this->Cell(
            0,
            10,
            pd_pdf_clean($this->meta['company_name'] ?? ''),
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

    public function drawHeaderRow(): void
    {
        $headers = [
            'SL NO',
            'STAKEHOLDERS',
            'COMPANY',
            'CONTACT PERSON',
            'DESIGNATION',
            'MOBILE NO',
            'EMAIL ID'
        ];

        $this->SetFillColor(141, 169, 196);
        $this->SetFont($this->fontFamily, 'B', 8.5);

        foreach ($headers as $index => $header) {
            $this->Cell(
                $this->widths[$index],
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

    public function lineCount(float $width, string $text): int
    {
        $cw = &$this->CurrentFont['cw'];
        $maxWidth = ($width - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $text = str_replace("\r", '', $text);
        $length = strlen($text);
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

            $lineWidth += $cw[$character] ?? 0;

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

    public function drawDataRow(array $cells, array $alignments): void
    {
        $lineHeight = 4.5;
        $maxLines = 1;

        foreach ($cells as $index => $cell) {
            $maxLines = max(
                $maxLines,
                $this->lineCount(
                    $this->widths[$index] - 2,
                    pd_pdf_clean($cell)
                )
            );
        }

        $rowHeight = max(8, $maxLines * $lineHeight);

        if ($this->GetY() + $rowHeight > 184) {
            $this->AddPage();
            $this->drawHeaderRow();
        }

        $x = 10;
        $y = $this->GetY();

        foreach ($cells as $index => $cell) {
            $width = $this->widths[$index];
            $text = pd_pdf_clean($cell);
            $alignment = $alignments[$index] ?? 'L';

            $this->Rect($x, $y, $width, $rowHeight);

            $lines = max(
                1,
                $this->lineCount($width - 2, $text)
            );

            $this->SetXY(
                $x + 1,
                $y + max(1, ($rowHeight - ($lines * $lineHeight)) / 2)
            );

            $this->MultiCell(
                $width - 2,
                $lineHeight,
                $text,
                0,
                $alignment
            );

            $x += $width;
        }

        $this->SetXY(10, $y + $rowHeight);
    }
}

$pdf = new PDCURRENT_PDF('L', 'mm', 'A4');
$pdf->initialiseFonts();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(false);
$pdf->AliasNbPages('{nb}');

$pdf->meta = [
    'company_name' => $companyName,
    'project_name' => $pd['project_name'] ?? '',
    'client_name' => $pd['client_name']
        ?: ($pd['current_client_name'] ?? ''),
    'architect' => $pd['architect'] ?? '',
    'pmc' => $pd['pmc'] ?? '',
    'pd_date' => pd_pdf_date($pd['pd_date'] ?? ''),
    'version' => $pd['version'] ?? 'R0',
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

    $logoCandidates[] =
        __DIR__ . '/../../' . ltrim($companyLogoDb, '/');
}

foreach ($logoCandidates as $path) {
    if ($path && file_exists($path)) {
        $pdf->logoPath = $path;
        break;
    }
}

$pdf->AddPage();
$pdf->drawHeaderRow();
$pdf->SetFont($pdf->fontFamily, '', 8);

$alignments = ['C', 'L', 'L', 'L', 'L', 'L', 'L'];

if ($details) {
    $printSlNo = 1;

    foreach ($details as $detail) {
        $pdf->drawDataRow(
            [
                (string)$printSlNo++,
                $detail['stakeholder_type'] ?? '',
                $detail['company_name'] ?? '',
                $detail['contact_person'] ?? '',
                $detail['designation'] ?? '',
                $detail['mobile_number'] ?? '',
                $detail['email_id'] ?? '',
            ],
            $alignments
        );
    }
}

$filename = 'PD_'
    . preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        pd_pdf_clean($pd['pd_no'] ?? ('ID_' . $viewId))
    )
    . '.pdf';

if ($modeString) {
    $bytes = $pdf->Output('S');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $GLOBALS['__PD_PDF_RESULT__'] = [
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
