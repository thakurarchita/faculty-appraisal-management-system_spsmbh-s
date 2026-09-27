<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Secure session configuration before starting
    ini_set('session.use_strict_mode', '1');      // reject unrecognized session IDs (prevents fixation)
    ini_set('session.cookie_httponly', '1');       // JS cannot read session cookie (XSS hardening)
    ini_set('session.cookie_samesite', 'Strict'); // no cross-site cookie send (CSRF hardening)
    ini_set('session.use_only_cookies', '1');      // never accept session ID in URL
    ini_set('session.gc_maxlifetime', '3600');     // expire idle sessions after 1 hour
    ini_set('session.cookie_lifetime', '0');       // session cookie dies when browser closes
    session_start();
}

// Check if functions already declared to prevent redeclaration
if (!function_exists('isLoggedIn')) {

    function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    function getCurrentUser() {
        if (isLoggedIn()) {
            return $_SESSION;
        }
        return null;
    }

    function getCurrentUserId() {
        return $_SESSION['user_id'] ?? null;
    }

    function getUserRole() {
        return $_SESSION['role'] ?? null;
    }

    function isAdmin() {
        return getUserRole() === 'Admin';
    }

    function isPrincipal() {
        return getUserRole() === 'Principal';
    }

    function isFaculty() {
        return getUserRole() === 'Faculty';
    }

    function requireLogin() {
        if (!isLoggedIn()) {
            header('Location: ' . BASE_URL . '/login');
            exit();
        }
    }

    function requireRole($role) {
        requireLogin();
        if (getUserRole() !== $role) {
            header('Location: ' . BASE_URL . '/login');
            exit();
        }
    }

    function getDashboardUrl() {
        $base = defined('BASE_URL') ? BASE_URL : '';
        $role = getUserRole();
        switch($role) {
            case 'Admin':     return $base . '/admin/dashboard';
            case 'Principal': return $base . '/principal/dashboard';
            case 'Faculty':   return $base . '/faculty/dashboard';
            default:          return $base . '/login';
        }
    }

    function generateRandomPassword($length = 8) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        return substr(str_shuffle($chars), 0, $length);
    }

    function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}
?>
