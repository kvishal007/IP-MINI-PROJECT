<?php
/**
 * File    : login.php
 * Purpose : Login page — authenticates admin and staff users.
 * Module  : Login Module (Supporting)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in → redirect to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
    ) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            // Fetch user from DB using a prepared statement
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'SELECT login_id, username, password, role, full_name
                 FROM login WHERE username = :username LIMIT 1'
            );
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Regenerate session ID to prevent session fixation
                session_regenerate_id(true);
                $_SESSION['user_id']   = $user['login_id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                header('Location: index.php');
                exit;
            } else {
                $error = 'Invalid username or password. Please try again.';
            }
        }
    }
}

// Generate CSRF token for the form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — FitTrack Gym Analyzer</title>
    <meta name="description" content="FitTrack login page for gym staff and admin.">
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">

<div class="login-wrapper">
    <!-- Left Panel: Branding -->
    <div class="login-brand-panel">
        <div class="login-brand-content">
            <div class="login-logo">
                <i class="bi bi-lightning-charge-fill"></i>
            </div>
            <h1 class="login-brand-name">FitTrack</h1>
            <p class="login-brand-tagline">Gym Membership &amp; Attendance Analyzer</p>
            <div class="login-features mt-4">
                <div class="login-feature-item">
                    <i class="bi bi-check-circle-fill text-accent"></i>
                    <span>Member Registration &amp; Management</span>
                </div>
                <div class="login-feature-item">
                    <i class="bi bi-check-circle-fill text-accent"></i>
                    <span>Daily Attendance Tracking</span>
                </div>
                <div class="login-feature-item">
                    <i class="bi bi-check-circle-fill text-accent"></i>
                    <span>Dropout Risk Analyzer</span>
                </div>
                <div class="login-feature-item">
                    <i class="bi bi-check-circle-fill text-accent"></i>
                    <span>Revenue &amp; Plan Reports</span>
                </div>
                <div class="login-feature-item">
                    <i class="bi bi-check-circle-fill text-accent"></i>
                    <span>Peak Hour &amp; Trend Charts</span>
                </div>
            </div>
        </div>
        <div class="login-brand-footer">
            <p>Department of AI &amp; Data Science<br>
            Kamaraj College of Engineering and Technology</p>
        </div>
    </div>

    <!-- Right Panel: Login Form -->
    <div class="login-form-panel">
        <div class="login-form-card">
            <div class="login-form-header">
                <h2>Welcome Back</h2>
                <p class="text-muted">Sign in to your FitTrack account</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?= h($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" id="loginForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

                <div class="mb-3">
                    <label for="username" class="form-label fw-semibold">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                        <input
                            type="text"
                            class="form-control"
                            id="username"
                            name="username"
                            placeholder="Enter username"
                            value="<?= h($_POST['username'] ?? '') ?>"
                            required
                            autocomplete="username">
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label fw-semibold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            placeholder="Enter password"
                            required
                            autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword" title="Show password">
                            <i class="bi bi-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-accent btn-lg w-100" id="loginBtn">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                </button>
            </form>

            <div class="login-demo-creds mt-4">
                <p class="small text-muted text-center mb-2">Demo Credentials</p>
                <div class="demo-cred-row">
                    <span class="badge bg-danger-soft text-danger">Admin</span>
                    <code>admin</code> / <code>admin123</code>
                </div>
                <div class="demo-cred-row mt-1">
                    <span class="badge bg-secondary-soft text-secondary">Staff</span>
                    <code>staff</code> / <code>staff123</code>
                </div>
            </div>
        </div>

        <div class="login-footer-note text-center text-muted small mt-3">
            FitTrack &copy; 2026 | Developed by Pavithran | III AI&amp;DS | KCET
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Toggle password visibility
document.getElementById('togglePassword').addEventListener('click', function () {
    const pwd  = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        pwd.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
});
</script>
</body>
</html>
