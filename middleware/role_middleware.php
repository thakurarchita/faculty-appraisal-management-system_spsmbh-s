<?php
require_once __DIR__ . '/../includes/auth.php';

function checkRole($allowedRoles) {
    requireLogin();
    $userRole = getUserRole();
    if (!in_array($userRole, $allowedRoles)) {
        header('Location: ' . getDashboardUrl());
        exit();
    }
}

// Specific role checks
function checkAdmin() {
    checkRole(['Admin']);
}

function checkPrincipal() {
    checkRole(['Principal']);
}

function checkFaculty() {
    checkRole(['Faculty']);
}

function checkAdminOrPrincipal() {
    checkRole(['Admin', 'Principal']);
}
?>
