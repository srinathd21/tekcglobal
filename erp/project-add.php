    <?php
    require_once __DIR__ . "/includes/db.php";
    require_permission($conn, "can_create", "projects.php");

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

    $project = [
        "location_radius" => 100,
        "latitude" => "",
        "longitude" => "",
        "place_id" => "",
        "location_address" => ""
    ];

    $selectedEngineerIds = [];
    ?>
    <!DOCTYPE html>
    <html lang="en" class="light">

    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Add Project - TEK-C PMC Construction</title>

        <?php include("includes/links.php"); ?>

        <style>
            .page-head-card {
                background: var(--card-bg);
                border: 1px solid var(--border-soft);
                border-radius: 22px;
                box-shadow: var(--shadow-card);
                padding: 16px;
            }

            .form-section-title {
                display: flex;
                gap: 12px;
                align-items: center;
                padding-bottom: 12px;
                border-bottom: 1px solid var(--border-soft);
            }

            .form-section-title h2 {
                font-size: 15px;
                font-weight: 900;
                margin: 0;
            }

            .form-section-title p {
                font-size: 12px;
                color: var(--text-muted);
                font-weight: 600;
                margin: 2px 0 0;
            }

            .form-section-icon {
                width: 42px;
                height: 42px;
                border-radius: 16px;
                background: linear-gradient(135deg, var(--brand-1), var(--brand-2));
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .form-section-icon.green {
                background: #10b981;
            }

            .form-section-icon.pink {
                background: #ec4899;
            }

            .form-section-icon.purple {
                background: #8b5cf6;
            }

            .scope-grid,
            .engineer-grid {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }

            .scope-pill,
            .engineer-pill {
                border: 1px solid var(--border-soft);
                background: rgba(148, 163, 184, .06);
                border-radius: 16px;
                padding: 10px 12px;
                display: flex;
                gap: 9px;
                align-items: center;
                font-weight: 800;
                font-size: 13px;
            }

            .engineer-grid {
                max-height: 240px;
                overflow: auto;
                padding: 12px;
                border: 1px solid var(--border-soft);
                border-radius: 18px;
            }

            .engineer-pill span {
                display: flex;
                flex-direction: column;
            }

            .engineer-pill small {
                color: var(--text-muted);
                font-size: 11px;
            }

            .project-map {
                height: 360px;
                border-radius: 20px;
                border: 1px solid var(--border-soft);
                overflow: hidden;
                background: rgba(148, 163, 184, .10);
            }

            .location-search-box {
                position: relative;
            }

            .location-suggestions {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: var(--card-bg);
                border: 1px solid var(--border-soft);
                border-radius: 16px;
                box-shadow: var(--shadow-card);
                z-index: 99;
                max-height: 260px;
                overflow: auto;
            }

            .suggestion-item {
                padding: 10px 13px;
                font-size: 13px;
                font-weight: 700;
                cursor: pointer;
                border-bottom: 1px solid var(--border-soft);
            }

            .suggestion-item:hover {
                background: rgba(148, 163, 184, .10);
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
                                <h1 class="h4 fw-bold mb-1">Add Project</h1>
                                <p class="text-muted-custom mb-0 small">Create project with contract, geo-location and assigned team.</p>
                            </div>

                            <a href="projects.php" class="btn btn-outline-secondary rounded-4 fw-bold btn-sm px-3">Back</a>
                        </div>
                    </div>

                    <form action="api/process-project.php" method="POST" enctype="multipart/form-data" class="card-ui p-3 p-lg-4" id="projectForm">
                        <input type="hidden" name="project_id" value="">

                        <?php include("includes/project-form-fields.php"); ?>

                        <div class="mt-4 d-flex flex-column flex-sm-row gap-2 justify-content-end">
                            <a href="projects.php" class="btn btn-outline-secondary rounded-4 fw-bold px-4">Cancel</a>
                            <button type="submit" class="btn brand-gradient text-white rounded-4 fw-bold px-4">Save Project</button>
                        </div>
                    </form>

                    <?php include("includes/footer.php"); ?>
                </section>
            </main>

            <div id="settingsOverlay"></div>
            <?php include("includes/rightsidbar.php"); ?>
        </div>

        <?php include("includes/script.php") ?>
        <script src="assets/js/script.js?v=30"></script>

        <!-- IMPORTANT: project-location.js must load BEFORE Google Maps callback=initMap -->
        <script src="assets/js/project-location.js?v=2"></script>

        <!-- Replace YOUR_GOOGLE_MAPS_API_KEY with your real key -->
        <script
            src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCyBiTiehtlXq0UxU-CTy_odcLF33eekBE&libraries=places,geometry&callback=initMap"
            async
            defer>
        </script>
    </body>

    </html>
