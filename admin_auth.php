<?php
// admin_auth.php - session-based username/password admin auth (DB-backed).
// Include this immediately after require 'config.php' in admin.php.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If the admin users table is not present yet, allow access to the helper that creates the first admin.
// Remove create_admin_user.php after finishing initial setup.
$publicFiles = ['admin_login.php', 'admin_logout.php', 'create_admin_user.php'];
$current = basename($_SERVER['SCRIPT_NAME']);
if (in_array($current, $publicFiles, true)) {
    return;
}

// If logged in, allow
if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true && !empty($_SESSION['admin_user'])) {
    return;
}

// Not logged in: redirect to login, preserve requested URL
$redirect = $_SERVER['REQUEST_URI'] ?? 'admin.php';
$loginUrl = 'admin_login.php?redirect=' . urlencode($redirect);
header('Location: ' . $loginUrl);
exit;