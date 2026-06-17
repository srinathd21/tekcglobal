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
        $_SESSION['employee_id'] = $employeeId;
    }
}

$modeString = isset($_GET['mode']) && $_GET['mode'] === 'string';
$forceDownload = isset($_GET['dl']) && $_GET['dl'] == '1';

function mom_pdf_clean($value): string
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

function mom_pdf_date($date): string
{
    $date = trim((string)$date);

    if ($date === '' || $date === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('d-m-Y', $timestamp) : $date;
}

function mom_pdf_rows($json): array
{
    if (is_array($json)) {
        return $json;
    }

    $rows = json_decode((string)$json, true);

    return is_array($rows) ? $rows : [];
}

function mom_pdf_is_super_admin(mysqli $conn): bool
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

$requestedId = (int)($_GET['view'] ?? $_GET['id'] ?? 0);

if ($requestedId <= 0) {
    die('Invalid MOM ID.');
}

$viewId = $requestedId;

$direct = mysqli_query(
    $conn,
    "SELECT id FROM mom_short_reports WHERE id = $viewId LIMIT 1"
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
                    "SELECT id FROM mom_short_reports WHERE id = $candidate LIMIT 1"
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
        m.*,
        p.project_location,
        p.manager_employee_id,
        p.team_lead_employee_id,
        c.client_name AS current_client_name
    FROM mom_short_reports m
    INNER JOIN projects p ON p.id = m.project_id
    LEFT JOIN clients c ON c.id = p.client_id
    WHERE m.id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, 'i', $viewId);
mysqli_stmt_execute($stmt);

$mom = mysqli_fetch_assoc(
    mysqli_stmt_get_result($stmt)
);

mysqli_stmt_close($stmt);

if (!$mom) {
    die('MOM not found.');
}

$canAccess =
    mom_pdf_is_super_admin($conn)
    || (int)($mom['manager_employee_id'] ?? 0) === $employeeId
    || (int)($mom['team_lead_employee_id'] ?? 0) === $employeeId
    || (int)($mom['employee_id'] ?? 0) === $employeeId;

if (!$canAccess) {
    die('MOM not found or access denied.');
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

$agenda = mom_pdf_rows($mom['agenda_json'] ?? '');
$attendees = mom_pdf_rows($mom['attendees_json'] ?? '');
$minutes = mom_pdf_rows($mom['minutes_json'] ?? '');
$amended = mom_pdf_rows($mom['amended_json'] ?? '');

class TekcMomShortSheetPdf extends FPDF
{
    public $momMeta = [];
    public $momLogo = '';
    public $momFont = 'Arial';
    public $outerX = 8;
    public $outerY = 8;
    public $leftLabelWidth = 13;
    public $leftTitleWidth = 54;
    public $lineHeight = 7;
    public $rowGap = 1.5;
    public $sectionGap = 5;

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

            $this->momFont = 'Calibri';
        }
    }

    public function Header(): void
    {
        $width = $this->GetPageWidth() - ($this->outerX * 2);

        $this->SetLineWidth(0.35);
        $this->Rect(
            $this->outerX,
            $this->outerY,
            $width,
            $this->GetPageHeight() - ($this->outerY * 2)
        );

        if ($this->PageNo() !== 1) {
            $this->SetY($this->outerY + 4);
            return;
        }

        $logoWidth = 25;
        $metaWidth = 107;
        $titleWidth = $width - $logoWidth - $metaWidth;
        $headerHeight = 24;

        $this->SetXY($this->outerX, $this->outerY);
        $this->Cell($logoWidth, $headerHeight, '', 1, 0, 'C');

        if ($this->momLogo && file_exists($this->momLogo)) {
            $info = @getimagesize($this->momLogo);

            if ($info) {
                [$imageWidth, $imageHeight] = $info;

                $ratio = min(
                    ($logoWidth - 4) / max($imageWidth, 1),
                    ($headerHeight - 4) / max($imageHeight, 1)
                );

                $drawWidth = $imageWidth * $ratio;
                $drawHeight = $imageHeight * $ratio;

                $this->Image(
                    $this->momLogo,
                    $this->outerX + (($logoWidth - $drawWidth) / 2),
                    $this->outerY + (($headerHeight - $drawHeight) / 2),
                    $drawWidth,
                    $drawHeight
                );
            }
        }

        $this->SetFillColor(220, 220, 220);
        $this->SetFont($this->momFont, 'B', 14);
        $this->Cell(
            $titleWidth,
            $headerHeight,
            'MINUTES OF MEETING - SHORT',
            1,
            0,
            'C',
            true
        );

        $metaX = $this->outerX + $logoWidth + $titleWidth;
        $rowHeight = $headerHeight / 2;
        $labelWidth = 45;
        $valueWidth = $metaWidth - $labelWidth;

        $this->SetXY($metaX, $this->outerY);
        $this->SetFont($this->momFont, 'B', 10);
        $this->Cell($labelWidth, $rowHeight, 'MOM Short No', 1, 0, 'L');
        $this->SetFont($this->momFont, '', 10);
        $this->Cell(
            $valueWidth,
            $rowHeight,
            mom_pdf_clean($this->momMeta['mom_no'] ?? ''),
            1,
            1,
            'L'
        );

        $this->SetX($metaX);
        $this->SetFont($this->momFont, 'B', 10);
        $this->Cell($labelWidth, $rowHeight, 'Date', 1, 0, 'L');
        $this->SetFont($this->momFont, '', 10);
        $this->Cell(
            $valueWidth,
            $rowHeight,
            mom_pdf_clean($this->momMeta['mom_date'] ?? ''),
            1,
            1,
            'L'
        );

        $this->SetY($this->outerY + $headerHeight + 5);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont($this->momFont, '', 9);
        $this->Cell(
            0,
            8,
            $this->PageNo() . '/{nb}',
            0,
            0,
            'C'
        );
    }

    public function lineCount(float $width, string $text): int
    {
        $characterWidths = &$this->CurrentFont['cw'];
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

    public function calculateRowHeight(array $widths, array $cells): float
    {
        $maximumLines = 1;

        foreach ($cells as $index => $cell) {
            $maximumLines = max(
                $maximumLines,
                $this->lineCount(
                    $widths[$index] - 2,
                    mom_pdf_clean($cell)
                )
            );
        }

        return max(
            $this->lineHeight + $this->rowGap,
            ($maximumLines * $this->lineHeight) + $this->rowGap
        );
    }

    public function ensureSpace(float $requiredHeight): void
    {
        if (
            $this->GetY() + $requiredHeight
            > $this->GetPageHeight() - $this->outerY - 10
        ) {
            $this->AddPage();
        }
    }

    public function drawWrappedRow(
        float $startX,
        array $widths,
        array $cells,
        array $alignments = []
    ): void {
        $height = $this->calculateRowHeight($widths, $cells);
        $this->ensureSpace($height);

        $rowY = $this->GetY();
        $x = $startX;

        foreach ($cells as $index => $cell) {
            $width = $widths[$index];
            $text = mom_pdf_clean($cell);
            $alignment = $alignments[$index] ?? 'L';

            $this->Rect($x, $rowY, $width, $height);

            $lines = max(
                1,
                $this->lineCount($width - 2, $text)
            );

            $this->SetXY(
                $x + 1,
                $rowY + max(
                    1,
                    ($height - ($lines * $this->lineHeight)) / 2
                )
            );

            $this->MultiCell(
                $width - 2,
                $this->lineHeight,
                $text,
                0,
                $alignment
            );

            $x += $width;
        }

        $this->SetXY($startX, $rowY + $height);
    }

    public function drawSection(
        string $sectionCode,
        string $sectionTitle,
        array $rows
    ): void {
        $leftWidth = $this->leftLabelWidth + $this->leftTitleWidth;
        $pageWidth = $this->GetPageWidth() - ($this->outerX * 2);
        $rightWidth = $pageWidth - $leftWidth;
        $rightX = $this->outerX + $leftWidth;

        $rowsHeight = 0;

        foreach ($rows as $row) {
            $rowsHeight += $this->calculateRowHeight($row[0], $row[1]);
        }

        $sectionHeight = max(22, $rowsHeight);
        $this->ensureSpace($sectionHeight + $this->sectionGap);

        $sectionY = $this->GetY();

        $this->SetFillColor(220, 220, 220);
        $this->SetFont($this->momFont, 'B', 10);

        $this->SetXY($this->outerX, $sectionY);
        $this->Cell(
            $this->leftLabelWidth,
            $sectionHeight,
            $sectionCode,
            1,
            0,
            'C',
            true
        );

        $this->Rect(
            $this->outerX + $this->leftLabelWidth,
            $sectionY,
            $this->leftTitleWidth,
            $sectionHeight,
            'DF'
        );

        $title = mom_pdf_clean($sectionTitle);
        $titleLines = max(
            1,
            $this->lineCount($this->leftTitleWidth - 4, $title)
        );

        $titleHeight = $titleLines * 6;

        $this->SetXY(
            $this->outerX + $this->leftLabelWidth + 2,
            $sectionY + max(2, ($sectionHeight - $titleHeight) / 2)
        );

        $this->MultiCell(
            $this->leftTitleWidth - 4,
            6,
            $title,
            0,
            'C'
        );

        $this->SetXY($rightX, $sectionY);

        foreach ($rows as $row) {
            $this->drawWrappedRow(
                $rightX,
                $row[0],
                $row[1],
                $row[2] ?? []
            );
        }

        $this->SetY($sectionY + $sectionHeight + $this->sectionGap);
    }
}

$pdf = new TekcMomShortSheetPdf('P', 'mm', 'A3');
$pdf->initialiseFonts();
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(false);
$pdf->AliasNbPages('{nb}');

$pdf->momMeta = [
    'mom_no' => $mom['mom_no'] ?? '',
    'mom_date' => mom_pdf_date($mom['mom_date'] ?? ''),
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
        $pdf->momLogo = $path;
        break;
    }
}

$pdf->AddPage();
$pdf->SetFont($pdf->momFont, '', 10);

$pageWidth = $pdf->GetPageWidth() - ($pdf->outerX * 2);
$rightWidth = $pageWidth - (
    $pdf->leftLabelWidth + $pdf->leftTitleWidth
);

$projectRows = [
    [
        [$rightWidth * .25, $rightWidth * .25, $rightWidth * .21, $rightWidth * .29],
        ['Project', $mom['project_name'] ?? '', 'PMC', $mom['pmc_name'] ?? ''],
        ['L', 'L', 'L', 'L']
    ],
    [
        [$rightWidth * .25, $rightWidth * .25, $rightWidth * .21, $rightWidth * .29],
        ['Client', $mom['client_name'] ?: ($mom['current_client_name'] ?? ''), 'Architects', $mom['architects'] ?? ''],
        ['L', 'L', 'L', 'L']
    ]
];

$pdf->drawSection('A.', 'PROJECT INFORMATION', $projectRows);

$meetingRows = [
    [
        [$rightWidth / 2, $rightWidth / 2],
        ['Meeting Conducted By', 'Date'],
        ['L', 'L']
    ],
    [
        [$rightWidth / 2, $rightWidth / 2],
        [$mom['meeting_conducted_by'] ?? '', mom_pdf_date($mom['mom_date'] ?? '')],
        ['L', 'L']
    ],
    [
        [$rightWidth / 2, $rightWidth / 2],
        ['Meeting Held At', 'Time'],
        ['L', 'L']
    ],
    [
        [$rightWidth / 2, $rightWidth / 2],
        [$mom['meeting_held_at'] ?? '', $mom['meeting_time'] ?? ''],
        ['L', 'L']
    ],
];

$pdf->drawSection('B.', 'MEETING INFORMATION', $meetingRows);

$agendaRows = [
    [
        [$rightWidth * .08, $rightWidth * .92],
        ['No.', 'Agenda Item'],
        ['C', 'L']
    ]
];

foreach ($agenda as $index => $row) {
    $agendaRows[] = [
        [$rightWidth * .08, $rightWidth * .92],
        [(string)($index + 1), $row['item'] ?? ''],
        ['C', 'L']
    ];
}

$pdf->drawSection('C.', 'MEETING AGENDA', $agendaRows);

$attendeeRows = [
    [
        [$rightWidth * .22, $rightWidth * .26, $rightWidth * .22, $rightWidth * .30],
        ['Stakeholder', 'Name', 'Designation', 'Firm'],
        ['L', 'L', 'L', 'L']
    ]
];

foreach ($attendees as $row) {
    $attendeeRows[] = [
        [$rightWidth * .22, $rightWidth * .26, $rightWidth * .22, $rightWidth * .30],
        [
            $row['stakeholder'] ?? '',
            $row['name'] ?? '',
            $row['designation'] ?? '',
            $row['firm'] ?? ''
        ],
        ['L', 'L', 'L', 'L']
    ];
}

$pdf->drawSection('D.', 'MEETING ATTENDEES', $attendeeRows);

$minuteRows = [
    [
        [$rightWidth * .08, $rightWidth * .60, $rightWidth * .16, $rightWidth * .16],
        ['Sl.No.', 'Discussions / Decisions', 'Responsible By', 'Deadline'],
        ['C', 'L', 'L', 'C']
    ]
];

foreach ($minutes as $index => $row) {
    $minuteRows[] = [
        [$rightWidth * .08, $rightWidth * .60, $rightWidth * .16, $rightWidth * .16],
        [
            (string)($index + 1),
            $row['discussion'] ?? '',
            $row['responsible_by'] ?? '',
            $row['deadline'] ?? ''
        ],
        ['C', 'L', 'L', 'C']
    ];
}

$pdf->drawSection('E.', 'MINUTES OF DISCUSSIONS', $minuteRows);

$pdf->drawSection('F.', 'MOM SHARED TO', [
    [
        [$rightWidth / 2, $rightWidth / 2],
        ['Attendees', 'Copy To'],
        ['L', 'L']
    ],
    [
        [$rightWidth / 2, $rightWidth / 2],
        [$mom['mom_shared_to'] ?? '', $mom['mom_copy_to'] ?? ''],
        ['L', 'L']
    ],
]);

$pdf->drawSection('G.', 'MOM SHARED BY', [
    [
        [$rightWidth / 2, $rightWidth / 2],
        ['Shared By', 'Shared On'],
        ['L', 'L']
    ],
    [
        [$rightWidth / 2, $rightWidth / 2],
        [$mom['mom_shared_by'] ?? '', mom_pdf_date($mom['mom_shared_on'] ?? '')],
        ['L', 'L']
    ],
]);

$pdf->drawSection('H.', 'MOM (SHORT TERM)-FORMS', [
    [
        [$rightWidth * .25, $rightWidth * .75],
        ['INFO', 'Information'],
        ['L', 'L']
    ],
    [
        [$rightWidth * .25, $rightWidth * .75],
        ['IMM', 'Immediately'],
        ['L', 'L']
    ],
    [
        [$rightWidth * .25, $rightWidth * .75],
        ['ASAP', 'As Soon As Possible'],
        ['L', 'L']
    ],
    [
        [$rightWidth * .25, $rightWidth * .75],
        ['TBF', 'To Be Followed'],
        ['L', 'L']
    ],
]);

if ($amended) {
    $amendedRows = [
        [
            [$rightWidth * .08, $rightWidth * .60, $rightWidth * .16, $rightWidth * .16],
            ['Sl.No.', 'Discussions / Decisions', 'Responsible By', 'Deadline'],
            ['C', 'L', 'L', 'C']
        ]
    ];

    foreach ($amended as $index => $row) {
        $amendedRows[] = [
            [$rightWidth * .08, $rightWidth * .60, $rightWidth * .16, $rightWidth * .16],
            [
                (string)($index + 1),
                $row['discussion'] ?? '',
                $row['responsible_by'] ?? '',
                $row['deadline'] ?? ''
            ],
            ['C', 'L', 'L', 'C']
        ];
    }

    $pdf->drawSection('I.', 'AMENDED POINTS', $amendedRows);
}

$pdf->drawSection('J.', 'NEXT MEETING', [
    [
        [$rightWidth / 2, $rightWidth / 2],
        ['Next Meeting Date', 'Next Meeting Place'],
        ['L', 'L']
    ],
    [
        [$rightWidth / 2, $rightWidth / 2],
        [
            mom_pdf_date($mom['next_meeting_date'] ?? ''),
            $mom['next_meeting_place'] ?? ''
        ],
        ['L', 'L']
    ],
]);

$filename = 'MOM_SHORT_'
    . preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        mom_pdf_clean($mom['mom_no'] ?? ('ID_' . $viewId))
    )
    . '.pdf';

if ($modeString) {
    $bytes = $pdf->Output('S');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $GLOBALS['__MOM_PDF_RESULT__'] = [
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
