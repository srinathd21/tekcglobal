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

$employee = [
    "employee_status" => "active",
    "office_location_id" => "",
    "work_location" => ""
];
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Add Employee - TEK-C PMC Construction</title>
    <?php include("includes/links.php"); ?>
    <style>
        .page-head-card {
            background: var(--card-bg);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-card);
            padding: 16px;
        }

        .work-location-help {
            border: 1px solid var(--border-soft);
            background: rgba(37, 99, 235, .06);
            border-radius: 16px;
            padding: 10px 12px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
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
                            <h1 class="h4 fw-bold mb-1">Add Employee</h1>
                            <p class="text-muted-custom mb-0 small">Create a new employee record with work location.</p>
                        </div>
                        <a href="employees.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm px-3">Back</a>
                    </div>
                </div>

                <form action="api/process-employee.php" method="POST" enctype="multipart/form-data" class="card-ui p-3 p-lg-4">
                    <input type="hidden" name="employee_id" value="">
                    <?php include("includes/employee-form-fields.php"); ?>

                    <div class="mt-4 d-flex flex-column flex-sm-row gap-2 justify-content-end">
                        <a href="employees.php" class="btn btn-outline-secondary rounded-4 fw-bold px-4">Cancel</a>
                        <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Save Employee</button>
                    </div>
                </form>

                <?php include("includes/footer.php"); ?>
            </section>
        </main>

        <div id="settingsOverlay"></div>
        <?php include("includes/rightsidbar.php"); ?>
    </div>

    <?php include("includes/script.php") ?>
    <script src="assets/js/script.js?v=12"></script>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const officeSelect = document.getElementById("office_location_id");
            const workLocationInput = document.getElementById("work_location");

            function syncWorkLocationText() {
                if (!officeSelect || !workLocationInput) return;

                const option = officeSelect.options[officeSelect.selectedIndex];
                const locationName = option ? (option.getAttribute("data-location-name") || "") : "";
                workLocationInput.value = locationName;
            }

            if (officeSelect) {
                officeSelect.addEventListener("change", syncWorkLocationText);
                syncWorkLocationText();
            }

            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
