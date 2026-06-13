<?php
session_start();
require_once __DIR__ . "/includes/db.php";

function e($v)
{
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

$pageMessageType = "";
$pageMessageText = "";

if (isset($_GET["error"])) {
    $pageMessageType = "error";
    $pageMessageText = trim($_GET["error"]) !== "" ? trim($_GET["error"]) : "Something went wrong. Please try again.";
}

$client = [];
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Add Client - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>
    <style>
        .page-head-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px;
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
                            <h1 class="h4 fw-bold mb-1">Add Client</h1>
                            <p class="text-muted-custom mb-0 small">Create a new client record.</p>
                        </div>
                        <a href="clients.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm px-3">Back</a>
                    </div>
                </div>

                <form action="api/process-client.php" method="POST" class="card-ui p-3 p-lg-4">
                    <input type="hidden" name="client_id" value="">
                    <?php include("includes/client-form-fields.php"); ?>

                    <div class="mt-4 d-flex flex-column flex-sm-row gap-2 justify-content-end">
                        <a href="clients.php" class="btn btn-outline-secondary rounded-4 fw-bold px-4">Cancel</a>
                        <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Save Client</button>
                    </div>
                </form>

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
