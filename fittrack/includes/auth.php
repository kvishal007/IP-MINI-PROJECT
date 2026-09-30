<?php
/**
 * File    : includes/auth.php
 * Purpose : Session guard — redirects to login if not authenticated.
 *           Also provides role-check helper for admin-only actions.
 * Module  : Login / Security (supporting all modules)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Require the user to be logged in.
 * Call at the top of every protected page.
 */
function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . base_url('login.php'));
        exit;
    }
}

/**
 * Require admin role.
 * Redirects staff users away from admin-only pages.
 */
function require_admin(): void {
    require_login();
    if ($_SESSION['role'] !== 'admin') {
        $_SESSION['flash_error'] = 'Access denied. Admin privileges required.';
        header('Location: ' . base_url('index.php'));
        exit;
    }
}

/**
 * Returns true if the current user is an admin.
 */
function is_admin(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Generate or return the existing CSRF token for this session.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate the CSRF token from a POST request.
 * Exits with 403 if invalid.
 */
function validate_csrf(): void {
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals(csrf_token(), $_POST['csrf_token'])
    ) {
        http_response_code(403);
        die('<h2>403 Forbidden — CSRF token mismatch.</h2>');
    }
}

/**
 * Return an absolute URL relative to the fittrack root.
 * Handles both localhost/fittrack/ and subdirectory installs.
 */
function base_url(string $path = ''): string {
    // Detect the base directory dynamically
    $script = $_SERVER['SCRIPT_NAME'] ?? '/fittrack/index.php';
    // Find the project root segment
    if (preg_match('#^(/fittrack/)#', $script, $m)) {
        $base = $m[1];
    } else {
        $base = '/fittrack/';
    }
    return $base . ltrim($path, '/');
}
