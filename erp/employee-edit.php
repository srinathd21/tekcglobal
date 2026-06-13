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

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($id <= 0) {
    header("Location: employees.php?error=" . urlencode("Invalid employee."));
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$employee = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$employee) {
    header("Location: employees.php?error=" . urlencode("Employee not found."));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Edit Employee - TEK-C PMC Construction</title>
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
                            <h1 class="h4 fw-bold mb-1">Edit Employee</h1>
                            <p class="text-muted-custom mb-0 small">Update employee details.</p>
                        </div>
                        <a href="employees.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm px-3">Back</a>
                    </div>
                </div>

                <form action="api/process-employee.php" method="POST" enctype="multipart/form-data" class="card-ui p-3 p-lg-4">
                    <input type="hidden" name="employee_id" value="<?= (int)$employee["id"] ?>">
                    <input type="hidden" name="old_photo" value="<?= e($employee["photo"] ?? "") ?>">
                    <input type="hidden" name="old_passbook_photo" value="<?= e($employee["passbook_photo"] ?? "") ?>">
                    <?php include("includes/employee-form-fields.php"); ?>

                    <div class="mt-4 d-flex flex-column flex-sm-row gap-2 justify-content-end">
                        <a href="employees.php" class="btn btn-outline-secondary rounded-4 fw-bold px-4">Cancel</a>
                        <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Update Employee</button>
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
</body>

</html>
