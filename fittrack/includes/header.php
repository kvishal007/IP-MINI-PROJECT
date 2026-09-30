<?php
/**
 * File    : includes/header.php
 * Purpose : Shared HTML header, navbar, and sidebar for all pages.
 * Module  : All Modules (Layout)
 * Author  : Pavithran
 * Course  : CS2307 - Internet Programming Laboratory
 *
 * Usage: include at the top of every protected page AFTER require_login().
 * The including file should define $page_title before including this file.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$page_title = $page_title ?? 'FitTrack';

// Determine current page for active-link highlight
$current_script = basename($_SERVER['SCRIPT_NAME']);
$current_dir    = basename(dirname($_SERVER['SCRIPT_NAME']));

function nav_active(string $dir = '', string $file = ''): string {
    global $current_script, $current_dir;
    if ($dir && $file) {
        return ($current_dir === $dir && $current_script === $file) ? 'active' : '';
    }
    if ($file) {
        return ($current_script === $file) ? 'active' : '';
    }
    if ($dir) {
        return ($current_dir === $dir) ? 'active' : '';
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?> — FitTrack</title>
    <meta name="description" content="FitTrack: Gym Membership and Attendance Analyzer — manage members, track attendance, and analyze gym performance.">
    <link rel="icon" type="image/png" href="<?= base_url('assets/img/favicon.png') ?>">

    <!-- Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Custom Stylesheet -->
    <link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
</head>
<body>

<!-- ============================================================
     SIDEBAR
============================================================ -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <i class="bi bi-lightning-charge-fill"></i>
        </div>
        <div class="brand-text">
            <span class="brand-name">FitTrack</span>
            <span class="brand-sub">Gym Analyzer</span>
        </div>
    </div>

    <div class="sidebar-user">
        <div class="user-avatar">
            <i class="bi bi-person-circle"></i>
        </div>
        <div class="user-info">
            <span class="user-name"><?= h($_SESSION['full_name'] ?? 'User') ?></span>
            <span class="user-role badge bg-accent"><?= h(ucfirst($_SESSION['role'] ?? '')) ?></span>
        </div>
    </div>

    <ul class="sidebar-nav">
        <li class="nav-section-label">Main</li>

        <li class="nav-item <?= nav_active('', 'index.php') ?>">
            <a href="<?= base_url('index.php') ?>" class="nav-link">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li class="nav-section-label">Members</li>

        <li class="nav-item <?= ($current_dir === 'members' && $current_script === 'list.php') ? 'active' : '' ?>">
            <a href="<?= base_url('members/list.php') ?>" class="nav-link">
                <i class="bi bi-people-fill"></i>
                <span>All Members</span>
            </a>
        </li>
        <li class="nav-item <?= ($current_dir === 'members' && $current_script === 'register.php') ? 'active' : '' ?>">
            <a href="<?= base_url('members/register.php') ?>" class="nav-link">
                <i class="bi bi-person-plus-fill"></i>
                <span>Register Member</span>
            </a>
        </li>

        <li class="nav-section-label">Attendance</li>

        <li class="nav-item <?= ($current_dir === 'attendance' && $current_script === 'mark.php') ? 'active' : '' ?>">
            <a href="<?= base_url('attendance/mark.php') ?>" class="nav-link">
                <i class="bi bi-qr-code-scan"></i>
                <span>Mark Attendance</span>
            </a>
        </li>
        <li class="nav-item <?= ($current_dir === 'attendance' && $current_script === 'today.php') ? 'active' : '' ?>">
            <a href="<?= base_url('attendance/today.php') ?>" class="nav-link">
                <i class="bi bi-calendar-check-fill"></i>
                <span>Today's Register</span>
            </a>
        </li>
        <li class="nav-item <?= ($current_dir === 'attendance' && $current_script === 'bulk.php') ? 'active' : '' ?>">
            <a href="<?= base_url('attendance/bulk.php') ?>" class="nav-link">
                <i class="bi bi-list-check"></i>
                <span>Bulk Mark</span>
            </a>
        </li>

        <li class="nav-section-label">Configuration</li>

        <li class="nav-item <?= ($current_dir === 'plans') ? 'active' : '' ?>">
            <a href="<?= base_url('plans/manage.php') ?>" class="nav-link">
                <i class="bi bi-card-list"></i>
                <span>Manage Plans</span>
            </a>
        </li>
        <li class="nav-item <?= ($current_dir === 'trainers') ? 'active' : '' ?>">
            <a href="<?= base_url('trainers/manage.php') ?>" class="nav-link">
                <i class="bi bi-person-badge-fill"></i>
                <span>Manage Trainers</span>
            </a>
        </li>

        <li class="nav-section-label">Analytics</li>

        <li class="nav-item <?= ($current_dir === 'reports' && $current_script === 'analyzer.php') ? 'active' : '' ?>">
            <a href="<?= base_url('reports/analyzer.php') ?>" class="nav-link">
                <i class="bi bi-bar-chart-line-fill"></i>
                <span>Analyzer</span>
            </a>
        </li>
        <li class="nav-item <?= ($current_dir === 'reports' && $current_script === 'revenue.php') ? 'active' : '' ?>">
            <a href="<?= base_url('reports/revenue.php') ?>" class="nav-link">
                <i class="bi bi-currency-rupee"></i>
                <span>Revenue Report</span>
            </a>
        </li>

        <li class="nav-section-label mt-auto">Account</li>

        <li class="nav-item">
            <a href="<?= base_url('logout.php') ?>" class="nav-link nav-logout">
                <i class="bi bi-box-arrow-left"></i>
                <span>Logout</span>
            </a>
        </li>
    </ul>
</nav>

<!-- ============================================================
     MAIN CONTENT WRAPPER
============================================================ -->
<div class="main-wrapper">
    <!-- Top Bar -->
    <header class="topbar">
        <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="bi bi-list"></i>
        </button>
        <div class="topbar-title">
            <h1 class="page-heading"><?= h($page_title) ?></h1>
        </div>
        <div class="topbar-right">
            <span class="topbar-date">
                <i class="bi bi-calendar3"></i>
                <?= date('D, d M Y') ?>
            </span>
            <a href="<?= base_url('logout.php') ?>" class="btn btn-sm btn-outline-secondary ms-3">
                <i class="bi bi-box-arrow-left"></i> Logout
            </a>
        </div>
    </header>

    <!-- Flash Messages -->
    <div class="flash-container px-4 pt-3">
        <?= render_flash('success') ?>
        <?= render_flash('error') ?>
        <?= render_flash('warning') ?>
        <?= render_flash('info') ?>
    </div>

    <!-- Page Content starts after this -->
    <main class="content-area">
