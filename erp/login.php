<?php
// login.php - TEK-C ERP login using users table, following old split-screen UI.

session_start();
require_once __DIR__ . "/includes/db.php";

$error = "";
$success = "";

function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
}

function norm($v)
{
    $v = strtolower(trim((string)$v));
    return preg_replace("/\s+/", " ", $v);
}

function roleRedirectPath($rolesText)
{
    $rolesText = norm($rolesText);

    if (str_contains($rolesText, "super admin") || str_contains($rolesText, "admin")) {
        return "index.php";
    }

    if (str_contains($rolesText, "hr")) {
        return "index.php";
    }

    if (str_contains($rolesText, "account")) {
        return "index.php";
    }

    if (str_contains($rolesText, "manager")) {
        return "index.php";
    }

    if (str_contains($rolesText, "team lead") || str_contains($rolesText, "tl")) {
        return "index.php";
    }

    if (str_contains($rolesText, "engineer")) {
        return "index.php";
    }

    return "index.php";
}

if (!empty($_SESSION["user_id"])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = (string)($_POST["password"] ?? "");

    if ($username === "" || $password === "") {
        $error = "Please enter username and password.";
    } else {
        $stmt = mysqli_prepare($conn, "
            SELECT
                u.id,
                u.employee_id,
                u.name,
                u.username,
                u.email,
                u.mobile_number,
                u.password,
                u.status,
                e.full_name AS employee_name,
                e.employee_code,
                e.employee_status,
                e.department_id,
                e.role_id,
                d.department_name,
                er.role_name AS designation_name,
                GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR ', ') AS roles_text
            FROM users u
            LEFT JOIN employees e ON e.id = u.employee_id
            LEFT JOIN master_departments d ON d.id = e.department_id
            LEFT JOIN roles er ON er.id = e.role_id
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            WHERE u.username = ?
            GROUP BY u.id
            LIMIT 1
        ");

        if (!$stmt) {
            $error = "Database error. Please contact Admin.";
        } else {
            mysqli_stmt_bind_param($stmt, "s", $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);

            if (!$user) {
                $error = "Invalid username or password.";
            } elseif (($user["status"] ?? "") !== "active") {
                $error = "Your user account is not active. Please contact Admin.";
            } elseif (!empty($user["employee_status"]) && $user["employee_status"] !== "active") {
                $error = "Your employee account is not active. Please contact Admin.";
            } elseif (!password_verify($password, (string)$user["password"])) {
                $error = "Invalid username or password.";
            } else {
                $_SESSION["user_id"] = (int)$user["id"];
                $_SESSION["employee_id"] = !empty($user["employee_id"]) ? (int)$user["employee_id"] : null;
                $_SESSION["employee_name"] = $user["employee_name"] ?: $user["name"];
                $_SESSION["name"] = $user["name"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["email"] = $user["email"] ?? "";
                $_SESSION["mobile_number"] = $user["mobile_number"] ?? "";
                $_SESSION["employee_code"] = $user["employee_code"] ?? "";
                $_SESSION["department"] = $user["department_name"] ?? "";
                $_SESSION["designation"] = $user["designation_name"] ?? "";
                $_SESSION["roles_text"] = $user["roles_text"] ?? "";

                $updateLogin = mysqli_prepare($conn, "UPDATE users SET last_login_at = NOW() WHERE id = ?");
                if ($updateLogin) {
                    mysqli_stmt_bind_param($updateLogin, "i", $user["id"]);
                    mysqli_stmt_execute($updateLogin);
                    mysqli_stmt_close($updateLogin);
                }

                $ip = $_SERVER["REMOTE_ADDR"] ?? null;
                $log = mysqli_prepare($conn, "
                    INSERT INTO activity_logs
                    (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
                    VALUES (?, ?, ?, ?, ?, 'LOGIN', 'auth', 'User logged in', ?, ?)
                ");

                if ($log) {
                    $sessionEmployeeId = $_SESSION["employee_id"];
                    $employeeName = $_SESSION["employee_name"];
                    $sessionUsername = $_SESSION["username"];
                    $designation = $_SESSION["designation"];
                    $department = $_SESSION["department"];
                    $referenceId = (int)$user["id"];

                    mysqli_stmt_bind_param($log, "issssis", $sessionEmployeeId, $employeeName, $sessionUsername, $designation, $department, $referenceId, $ip);
                    mysqli_stmt_execute($log);
                    mysqli_stmt_close($log);
                }

                $redirect = roleRedirectPath($user["roles_text"] ?? "");
                header("Location: " . $redirect);
                exit;
            }
        }
    }
}

$tekcYellow = "#F9C52A";
$tekcDark = "#111827";
$tekcMuted = "#6b7280";
$logoPath = "assets/img/logo.png";
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Login - TEK-C ERP</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="assets/favicon/site.webmanifest">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>

    <style>
        :root {
            --tekc-yellow: <?= $tekcYellow ?>;
            --tekc-dark: <?= $tekcDark ?>;
            --tekc-muted: <?= $tekcMuted ?>;
            --border: #e5e7eb;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
        }

        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--tekc-dark);
            background: #fff;
        }

        .split {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1.1fr .9fr;
        }

        .left {
            background: var(--tekc-yellow);
            padding: 54px 56px;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .blob1,
        .blob2 {
            position: absolute;
            border-radius: 999px;
            z-index: 0;
            background: #fff;
        }

        .blob1 {
            width: 560px;
            height: 560px;
            left: -240px;
            top: -200px;
            opacity: .22;
        }

        .blob2 {
            width: 700px;
            height: 700px;
            right: -300px;
            bottom: -300px;
            opacity: .18;
        }

        .left-inner {
            position: relative;
            z-index: 1;
            max-width: 760px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fff;
            border-radius: 18px;
            padding: 14px;
            width: 100%;
            max-width: 760px;
            box-shadow: 0 10px 25px rgba(17, 24, 39, .10);
            border: 1px solid rgba(17, 24, 39, .08);
        }

        .brand .logo {
            width: 46px;
            height: 46px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex: 0 0 auto;
        }

        .brand .logo img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            display: block;
        }

        .brand .t1 {
            margin: 0;
            font-weight: 900;
            font-size: 18px;
            line-height: 1.1;
            color: var(--tekc-dark);
        }

        .brand .t2 {
            margin: 2px 0 0;
            font-weight: 800;
            font-size: 12px;
            color: rgba(17, 24, 39, .78);
        }

        .headline {
            margin: 34px 0 12px;
            font-size: 42px;
            font-weight: 900;
            letter-spacing: -.6px;
            line-height: 1.05;
        }

        .subline {
            margin: 0 0 22px;
            font-weight: 800;
            font-size: 14px;
            line-height: 1.7;
            color: rgba(17, 24, 39, .78);
            max-width: 560px;
        }

        .feature {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px;
            border-radius: 18px;
            background: #fff;
            border: 1px solid rgba(17, 24, 39, .10);
            margin-bottom: 12px;
            box-shadow: 0 10px 25px rgba(17, 24, 39, .08);
        }

        .feature .ic {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            background: var(--tekc-yellow);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #111827;
            flex: 0 0 auto;
            box-shadow: 0 8px 18px rgba(17, 24, 39, .12);
        }

        .feature .ft {
            margin: 0;
            font-weight: 900;
            font-size: 14px;
            color: #111827;
        }

        .feature .fd {
            margin: 6px 0 0;
            font-weight: 700;
            font-size: 12px;
            color: rgba(17, 24, 39, .78);
            line-height: 1.45;
        }

        .left-footer {
            position: relative;
            z-index: 1;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid rgba(17, 24, 39, .08);
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-weight: 800;
            font-size: 12px;
            color: rgba(17, 24, 39, .80);
            flex-wrap: wrap;
        }

        .right {
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 54px 56px;
        }

        .login-wrap {
            width: 100%;
            max-width: 520px;
            margin-left: auto;
            margin-right: auto;
        }

        .login-title {
            margin: 0 0 6px;
            font-weight: 900;
            font-size: 28px;
            letter-spacing: -.3px;
        }

        .login-sub {
            margin: 0 0 18px;
            font-weight: 700;
            font-size: 13px;
            color: var(--tekc-muted);
            line-height: 1.45;
        }

        .alert {
            border-radius: 14px;
            border: none;
            box-shadow: 0 10px 25px rgba(17, 24, 39, .08);
        }

        .form-label {
            font-weight: 900;
            font-size: 13px;
            color: #374151;
        }

        .form-control {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px 14px;
            font-weight: 700;
            font-size: 14px;
        }

        .form-control:focus {
            border-color: var(--tekc-yellow);
            box-shadow: 0 0 0 3px rgba(249, 197, 42, .25);
        }

        .input-group-text {
            border-radius: 12px 0 0 12px;
            border: 2px solid #e5e7eb;
            border-right: none;
            background: #fff;
            color: #111827;
        }

        .input-with-icon {
            border-left: none !important;
            border-radius: 0 12px 12px 0 !important;
        }

        .password-field {
            border-left: none !important;
            border-radius: 0 !important;
        }

        .password-toggle {
            border-radius: 0 12px 12px 0;
            border: 2px solid #e5e7eb;
            border-left: none;
            background: #fff;
            color: #111827;
            width: 46px;
        }

        .btn-login {
            width: 100%;
            border: none;
            border-radius: 14px;
            padding: 12px 14px;
            font-weight: 900;
            background: var(--tekc-yellow);
            color: #111827;
            box-shadow: 0 12px 26px rgba(249, 197, 42, .35);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: .15s ease;
        }

        .btn-login:hover {
            filter: brightness(.98);
            transform: translateY(-1px);
        }

        .tiny {
            margin-top: 14px;
            font-weight: 700;
            font-size: 12px;
            color: var(--tekc-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        @media (max-width: 992px) {
            .split {
                grid-template-columns: 1fr;
            }

            .left {
                padding: 28px 22px;
                min-height: 44vh;
            }

            .right {
                padding: 28px 22px;
            }

            .headline {
                font-size: 32px;
            }

            .login-wrap {
                max-width: 520px;
                margin: 0;
            }

            .left-inner {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="split">
        <section class="left" aria-label="TEK-C Information">
            <div class="blob1"></div>
            <div class="blob2"></div>

            <div class="left-inner">
                <div class="brand">
                    <div class="logo">
                        <img src="<?= e($logoPath) ?>" onerror="this.style.display='none';" alt="TEK-C Logo">
                    </div>
                    <div>
                        <p class="t1">TEK-C | A UKB Group Company</p>
                        <p class="t2">Construction ERP Platform</p>
                    </div>
                </div>

                <h1 class="headline">Manage Projects. Empower Teams. Deliver Faster.</h1>
                <p class="subline">
                    TEK-C helps you streamline project execution with organized tracking, clear accountability,
                    and secure document control across every stage of construction.
                </p>

                <div class="feature">
                    <div class="ic"><i data-lucide="kanban-square" style="width:21px;height:21px;"></i></div>
                    <div>
                        <p class="ft">Sites & Contracts</p>
                        <p class="fd">Track project scope, site details, agreements, work orders, and related documents in one place.</p>
                    </div>
                </div>

                <div class="feature">
                    <div class="ic"><i data-lucide="shield-check" style="width:21px;height:21px;"></i></div>
                    <div>
                        <p class="ft">Role-Based Access Control</p>
                        <p class="fd">Access control for Admin, HR, QS, Accounts, Project Managers, Team Leads, and Engineers.</p>
                    </div>
                </div>

                <div class="feature" style="margin-bottom:0;">
                    <div class="ic"><i data-lucide="folder-open" style="width:21px;height:21px;"></i></div>
                    <div>
                        <p class="ft">Centralized Document Management</p>
                        <p class="fd">Securely store staff records, site photos, drawings, approvals, and critical project documents.</p>
                    </div>
                </div>
            </div>

            <div class="left-footer">
                <span>© 2026 TEK-C – A UKB Group Company</span>
                <span>Secure Construction ERP Platform</span>
            </div>
        </section>

        <section class="right" aria-label="Login">
            <div class="login-wrap">
                <h2 class="login-title">Sign in</h2>
                <p class="login-sub">Enter your username and password to continue.</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert">
                        <i data-lucide="triangle-alert" class="mt-1" style="width:18px;height:18px;"></i>
                        <div>
                            <strong>Login Failed</strong>
                            <div><?= e($error) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success d-flex align-items-start gap-2 mb-3" role="alert">
                        <i data-lucide="check-circle-2" class="mt-1" style="width:18px;height:18px;"></i>
                        <div>
                            <strong>Success</strong>
                            <div><?= e($success) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="off" novalidate>
                    <div class="mb-3">
                        <label class="form-label" for="username">Username</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i data-lucide="user" style="width:18px;height:18px;"></i>
                            </span>
                            <input type="text"
                                class="form-control input-with-icon"
                                id="username"
                                name="username"
                                value="<?= e($_POST["username"] ?? "") ?>"
                                placeholder="Enter username"
                                required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i data-lucide="lock" style="width:18px;height:18px;"></i>
                            </span>
                            <input type="password"
                                class="form-control password-field"
                                id="password"
                                name="password"
                                placeholder="Enter password"
                                required>
                            <button class="btn password-toggle" type="button" id="togglePass">
                                <i data-lucide="eye" style="width:18px;height:18px;"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-login">
                        <i data-lucide="log-in" style="width:18px;height:18px;"></i> Login
                    </button>

                    <div class="tiny">
                        <i data-lucide="shield-lock" style="width:16px;height:16px;"></i>
                        Your access is based on your assigned role permissions.
                    </div>
                </form>
            </div>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const btn = document.getElementById("togglePass");
            const input = document.getElementById("password");

            if (btn && input) {
                btn.addEventListener("click", function () {
                    const isPwd = input.type === "password";
                    input.type = isPwd ? "text" : "password";
                    btn.innerHTML = isPwd
                        ? '<i data-lucide="eye-off" style="width:18px;height:18px;"></i>'
                        : '<i data-lucide="eye" style="width:18px;height:18px;"></i>';

                    if (window.lucide && typeof window.lucide.createIcons === "function") {
                        window.lucide.createIcons();
                    }
                });
            }

            if (window.lucide && typeof window.lucide.createIcons === "function") {
                window.lucide.createIcons();
            }
        });
    </script>
</body>

</html>
