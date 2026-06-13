<?php
session_start();
require_once __DIR__ . "/includes/db.php";

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function detail_value($value)
{
    return $value !== null && $value !== "" ? e($value) : "-";
}

function table_has_column($conn, $table, $column)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && mysqli_num_rows($res) > 0;
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($id <= 0) {
    header("Location: clients.php?error=" . urlencode("Invalid client."));
    exit;
}

$hasClientTypeId = table_has_column($conn, "clients", "client_type_id");
$typeSelect = $hasClientTypeId ? "mct.client_type_name" : "c.client_type";
$typeJoin = $hasClientTypeId ? "LEFT JOIN master_client_types mct ON mct.id = c.client_type_id" : "";

$stmt = mysqli_prepare($conn, "
    SELECT c.*, $typeSelect AS client_type_name
    FROM clients c
    $typeJoin
    WHERE c.id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$client = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$client) {
    header("Location: clients.php?error=" . urlencode("Client not found."));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>View Client - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>

    <style>
        .page-head-card,
        .client-profile-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px;
        }

        .client-view-avatar {
            width: 86px;
            height: 86px;
            border-radius: 28px;
            background: rgba(148, 163, 184, .14);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            font-size: 32px;
            font-weight: 900;
        }

        .detail-card {
            background: rgba(148, 163, 184, .06);
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            padding: 14px;
            height: 100%;
        }

        .detail-label {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 5px;
        }

        .detail-value {
            color: var(--text-main);
            font-size: 13px;
            font-weight: 800;
            margin: 0;
            word-break: break-word;
        }
    </style>
</head>

<body>
    <div id="mobileOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 z-3 d-xl-none"></div>

    <?php include("includes/page-message.php"); ?>

    <div class="min-vh-100 d-flex">
        <?php include("includes/sidebar.php"); ?>

        <main id="main">
            <?php include("includes/nav.php"); ?>

            <section class="page-section p-3 p-lg-3">
                <div class="page-head-card mb-3">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                        <div>
                            <h1 class="h4 fw-bold mb-1">View Client</h1>
                            <p class="text-muted-custom mb-0 small">Client full profile, KYC and address details.</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="clients.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm px-3">Back</a>
                            <a href="client-edit.php?id=<?= (int)$client["id"] ?>" class="btn brand-gradient text-white rounded-4 fw-bold btn-sm px-3">Edit Client</a>
                        </div>
                    </div>
                </div>

                <section class="client-profile-card mb-3">
                    <div class="d-flex flex-column flex-md-row align-items-md-center gap-3">
                        <div class="client-view-avatar"><?= e(strtoupper(substr($client["client_name"], 0, 1))) ?></div>
                        <div class="flex-grow-1">
                            <h2 class="fw-bold h5 mb-1"><?= e($client["client_name"]) ?></h2>
                            <p class="text-muted-custom small fw-semibold mb-2">
                                <?= detail_value($client["company_name"]) ?> · <?= detail_value($client["client_type_name"] ?? "") ?>
                            </p>
                            <span class="pill green"><?= detail_value($client["client_type_name"] ?? "") ?></span>
                        </div>
                    </div>
                </section>

                <section class="card-ui p-3 p-lg-4 mb-3">
                    <h2 class="fw-bold fs-6 mb-3">Contact & Company</h2>
                    <div class="row g-3">
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Mobile</div><p class="detail-value"><?= detail_value($client["mobile_number"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Email</div><p class="detail-value"><?= detail_value($client["email"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">State</div><p class="detail-value"><?= detail_value($client["state"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Company</div><p class="detail-value"><?= detail_value($client["company_name"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">PAN</div><p class="detail-value"><?= detail_value($client["pan_number"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">GST</div><p class="detail-value"><?= detail_value($client["gst_number"]) ?></p></div></div>
                        <div class="col-md-4"><div class="detail-card"><div class="detail-label">Aadhaar</div><p class="detail-value"><?= detail_value($client["aadhaar_number"]) ?></p></div></div>
                    </div>
                </section>

                <section class="card-ui p-3 p-lg-4">
                    <h2 class="fw-bold fs-6 mb-3">Address Details</h2>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="detail-card"><div class="detail-label">Office Address</div><p class="detail-value"><?= detail_value($client["office_address"]) ?></p></div></div>
                        <div class="col-md-6"><div class="detail-card"><div class="detail-label">Site Address</div><p class="detail-value"><?= detail_value($client["site_address"]) ?></p></div></div>
                        <div class="col-md-6"><div class="detail-card"><div class="detail-label">Billing Address</div><p class="detail-value"><?= detail_value($client["billing_address"]) ?></p></div></div>
                        <div class="col-md-6"><div class="detail-card"><div class="detail-label">Shipping Address</div><p class="detail-value"><?= detail_value($client["shipping_address"]) ?></p></div></div>
                    </div>
                </section>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include("includes/rightsidbar.php"); ?>
    </div>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=14"></script>
</body>

</html>
