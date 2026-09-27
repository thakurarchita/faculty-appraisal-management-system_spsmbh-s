<?php
require_once __DIR__ . '/../../includes/functions.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Check if user is admin
if ($_SESSION['role'] !== 'Admin') {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../models/User.php';

$userModel = new User();
$users = $userModel->getAllUsers();
$db = getDB();

// Get roles
$stmt = $db->query("SELECT * FROM roles ORDER BY name");
$roles = $stmt->fetchAll();

// Check if we have a created user to show
$showPassword = isset($_SESSION['created_user']);
$createdUser = $showPassword ? $_SESSION['created_user'] : null;

// Generate next Employee ID
function generateEmployeeId($db) {
    // Get the latest employee ID starting with EMP
    $stmt = $db->query("SELECT employee_id FROM users WHERE employee_id LIKE 'EMP%' ORDER BY CAST(SUBSTR(employee_id, 4) AS INTEGER) DESC LIMIT 1");
    $lastId = $stmt->fetch();
    
    if ($lastId) {
        // Extract number from ID (e.g., EMP001 -> 1)
        $num = intval(substr($lastId['employee_id'], 3));
        $next = $num + 1;
    } else {
        $next = 1;
    }
    
    // Format with leading zeros (EMP001, EMP002, etc.)
    return 'EMP' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

$nextEmployeeId = generateEmployeeId($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>User Management - Faculty Appraisal System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        /* Global Styles */
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            overflow-x: hidden;
        }
        
        /* Sidebar Styles - Mobile First */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 280px;
            z-index: 1050;
            background: linear-gradient(180deg, #1a2332 0%, #2c3e50 100%) !important;
            transition: transform 0.3s ease-in-out;
            transform: translateX(-100%);
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar.show {
            transform: translateX(0);
        }
        
        .sidebar .nav-link {
            padding: 12px 20px;
            color: rgba(255,255,255,0.7) !important;
            border-radius: 8px;
            margin: 2px 10px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }
        
        .sidebar .nav-link:hover {
            background: rgba(255,255,255,0.05);
            color: #f1c40f !important;
        }
        
        .sidebar .nav-link.active {
            color: #f1c40f !important;
            background: rgba(241, 196, 15, 0.1);
        }
        
        .sidebar .nav-link i {
            margin-right: 12px;
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 0;
            padding: 20px 15px;
            transition: margin-left 0.3s ease;
            width: 100%;
            min-height: 100vh;
        }
        
        /* Mobile Toggle Button */
        .navbar-toggle {
            display: block;
            position: fixed;
            top: 10px;
            left: 10px;
            z-index: 1060;
            background: #1a2332;
            border: none;
            color: #f1c40f;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        
        .navbar-toggle:hover {
            background: #2c3e50;
            transform: scale(1.05);
        }
        
        .navbar-toggle:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.3);
        }
        
        /* Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1040;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        /* Card Styles */
        .card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            border-radius: 12px 12px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Table Responsive */
        .table-responsive {
            border-radius: 8px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .table {
            margin-bottom: 0;
            font-size: 0.9rem;
        }
        
        .table th {
            font-weight: 600;
            border-top: none;
            white-space: nowrap;
        }
        
        .table td {
            vertical-align: middle;
            padding: 10px 8px;
        }
        
        /* Badge Styles */
        .badge {
            padding: 5px 10px;
            font-weight: 500;
            font-size: 0.75rem;
            border-radius: 6px;
        }
        
        /* Button Styles */
        .btn {
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.3);
        }
        
        .btn-group .btn {
            padding: 0.25rem 0.6rem;
            font-size: 0.8rem;
            border-color: #dee2e6;
            border-radius: 6px;
        }
        
        .btn-group .btn:hover {
            background-color: #f8f9fa;
            border-color: #ced4da;
        }
        
        .btn-group .btn i {
            font-size: 0.9rem;
        }
        
        .table .btn-group {
            display: flex;
            gap: 2px;
            flex-wrap: nowrap;
        }
        
        /* Modal */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }
        
        .modal-header {
            border-bottom: 1px solid #e9ecef;
            padding: 15px 20px;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 15px 20px;
        }
        
        .modal-body .form-label {
            font-weight: 500;
            font-size: 14px;
        }
        
        .modal-body .form-control, .modal-body .form-select {
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
        }
        
        /* Alert */
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        /* Responsive Typography */
        h1.h2 {
            font-size: calc(1.5rem + 0.5vw);
        }
        
        /* Scrollbar Styling */
        .sidebar::-webkit-scrollbar {
            width: 5px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.2);
            border-radius: 3px;
        }
        
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* DataTables Overrides */
        .dataTables_wrapper .dataTables_filter input {
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 6px 12px;
            margin-left: 6px;
        }
        
        .dataTables_wrapper .dataTables_length select {
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 4px 8px;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border-radius: 6px !important;
            padding: 4px 10px !important;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #0d6efd !important;
            color: white !important;
            border-color: #0d6efd !important;
        }
        
        /* Media Queries */
        @media (min-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: 240px;
                transform: translateX(0);
                z-index: 1000;
            }
            
            .navbar-toggle {
                display: none;
            }
            
            .sidebar-overlay {
                display: none !important;
            }
            
            .main-content {
                margin-left: 240px;
                padding: 25px 30px;
                width: calc(100% - 240px);
            }
        }
        
        @media (min-width: 992px) {
            .sidebar {
                width: 260px;
            }
            
            .main-content {
                margin-left: 260px;
                width: calc(100% - 260px);
                padding: 30px 40px;
            }
            
            .card-body {
                padding: 25px 30px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px 10px;
            }
            
            .card-body {
                padding: 15px;
            }
            
            .card-header {
                padding: 12px 15px;
                font-size: 0.95rem;
            }
            
            .navbar-toggle {
                padding: 8px 12px;
                font-size: 1.2rem;
                top: 8px;
                left: 8px;
            }
            
            .table {
                font-size: 0.8rem;
            }
            
            .table td, .table th {
                padding: 6px 4px;
            }
            
            .badge {
                padding: 3px 7px;
                font-size: 0.65rem;
            }
            
            .btn-group .btn {
                padding: 3px 6px;
                font-size: 0.7rem;
            }
            
            .btn-group .btn i {
                font-size: 0.7rem;
            }
            
            .modal-body {
                padding: 15px;
            }
            
            .modal-body .form-control, .modal-body .form-select {
                padding: 8px 12px;
                font-size: 13px;
            }
            
            .dataTables_wrapper .dataTables_filter input {
                width: 130px;
            }
            
            .dataTables_wrapper .dataTables_length select {
                padding: 2px 6px;
                font-size: 12px;
            }
            
            .table .btn-group {
                flex-wrap: wrap;
                gap: 1px;
            }
        }
        
        @media (max-width: 400px) {
            .main-content {
                padding: 10px 5px;
            }
            
            .card-body {
                padding: 12px;
            }
            
            .dataTables_wrapper .dataTables_filter input {
                width: 100px;
            }
            
            .table .btn-group .btn {
                padding: 2px 4px;
                font-size: 0.6rem;
            }
            
            .modal-dialog {
                margin: 5px;
            }
        }
        
        /* Animation */
        .fade-in {
            animation: fadeIn 0.4s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Utilities */
        .text-muted {
            color: #6c757d !important;
        }
        
        .fw-bold {
            font-weight: 600;
        }
        
        /* Responsive Grid */
        .row.g-3 {
            --bs-gutter-y: 1rem;
        }
        
        /* Modal responsive */
        .modal-dialog {
            margin: 10px;
        }
        
        @media (min-width: 576px) {
            .modal-dialog {
                margin: 1.75rem auto;
            }
        }
        
        /* Credentials table responsive */
        .credentials-table {
            font-size: 0.9rem;
        }
        
        @media (max-width: 576px) {
            .credentials-table {
                font-size: 0.8rem;
            }
            .credentials-table td, .credentials-table th {
                padding: 6px 8px;
            }
        }
        
        /* Alert info responsive */
        .alert-info .row {
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <!-- Mobile Toggle Button -->
    <button class="navbar-toggle" id="sidebarToggle" aria-label="Toggle navigation">
        <i class="bi bi-list"></i>
    </button>
    
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="position-sticky pt-3">
            <div class="text-center mb-4 px-3" style="color: #f1c40f;">
                <i class="bi bi-mortarboard-fill" style="font-size: 2.5rem;"></i>
                <h6 class="mt-2" style="color: white;">Appraisal System</h6>
                <small style="color: rgba(255,255,255,0.7);">Admin Panel</small>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/dashboard">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="<?php echo BASE_URL; ?>/admin/users">
                        <i class="bi bi-people"></i> Users
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/assign-task">
                        <i class="bi bi-plus-circle"></i> Assign Task
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/my-tasks">
                        <i class="bi bi-list-task"></i> My Tasks
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/task-status">
                        <i class="bi bi-list-check"></i> Task Status
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/templates">
                        <i class="bi bi-file-earmark-text"></i> Task Templates
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/settings">
                        <i class="bi bi-gear"></i> Settings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/settings">
                        <i class="bi bi-sliders"></i> Perf. Settings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/logout">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main content -->
    <main class="main-content" id="mainContent">
        <div class="container-fluid px-0">
            <!-- Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-center pt-2 pb-3 mb-3 border-bottom">
                <h1 class="h2 mb-0">User Management</h1>
                <button class="btn btn-primary mt-2 mt-sm-0" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-person-plus me-1"></i> Add User
                </button>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Show Created User Credentials with Email Status -->
            <?php if ($showPassword && $createdUser): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <h5><i class="bi bi-person-check me-2"></i> User Created Successfully!</h5>
                
                <!-- Email Status -->
                <div class="mt-2">
                    <?php if (isset($createdUser['email_sent']) && $createdUser['email_sent']): ?>
                        <div class="alert alert-success mb-2" style="padding: 8px 12px;">
                            <i class="bi bi-envelope-check me-1"></i> 
                            <strong>✅ Welcome email sent successfully to <?php echo $createdUser['email']; ?></strong>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning mb-2" style="padding: 8px 12px;">
                            <i class="bi bi-envelope-exclamation me-1"></i> 
                            <strong>⚠️ Email could not be sent.</strong> Please provide credentials manually.
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12 col-md-6">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm credentials-table">
                                <tr>
                                    <th style="width:40%;">Name</th>
                                    <td><?php echo htmlspecialchars($createdUser['name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Username</th>
                                    <td><strong><?php echo htmlspecialchars($createdUser['username']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th>Temporary Password</th>
                                    <td>
                                        <span class="badge bg-warning text-dark" style="font-size: 14px; padding: 6px 12px;">
                                            <?php echo $createdUser['password']; ?>
                                        </span>
                                        <button class="btn btn-sm btn-outline-primary ms-1" onclick="copyPassword('<?php echo $createdUser['password']; ?>')">
                                            <i class="bi bi-copy"></i> <span class="d-none d-sm-inline">Copy</span>
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Email</th>
                                    <td><?php echo htmlspecialchars($createdUser['email']); ?></td>
                                </tr>
                            </table>
                        </div>
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i> 
                            <?php if (isset($createdUser['email_sent']) && $createdUser['email_sent']): ?>
                                User will receive login credentials via email. They must change password on first login.
                            <?php else: ?>
                                Please share these credentials with the user manually.
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" onclick="clearSession()"></button>
            </div>
            <?php 
            unset($_SESSION['created_user']);
            unset($_SESSION['email_sent']);
            endif; 
            ?>

            <!-- Fast Add User Inline Form -->
            <div class="card mb-3 fade-in">
                <div class="card-header bg-light">
                    <span><i class="bi bi-lightning-charge me-2"></i> Fast Add User</span>
                </div>
                <div class="card-body p-2">
                    <form id="fastAddUserForm" class="row g-2 align-items-end">
                        <input type="hidden" name="action" value="ajax_add_user">
                        <input type="hidden" name="employee_id" id="fast_employee_id" value="<?php echo $nextEmployeeId; ?>">
                        
                        <div class="col-md-2">
                            <label class="form-label mb-1" style="font-size:0.8rem;">Full Name *</label>
                            <input type="text" name="full_name" class="form-control form-control-sm" required placeholder="Full Name">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1" style="font-size:0.8rem;">Email *</label>
                            <input type="email" name="email" class="form-control form-control-sm" required placeholder="Email">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label mb-1" style="font-size:0.8rem;">Mobile</label>
                            <input type="text" name="mobile" class="form-control form-control-sm" placeholder="Mobile">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1" style="font-size:0.8rem;">Designation</label>
                            <input type="text" name="designation" class="form-control form-control-sm" placeholder="Designation">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label mb-1" style="font-size:0.8rem;">Type *</label>
                            <select name="employee_type" class="form-select form-select-sm" required>
                                <option value="Teaching">Teaching</option>
                                <option value="Non-Teaching">Non-Teaching</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1" style="font-size:0.8rem;">Role *</label>
                            <select name="role_id" id="fast_role_id" class="form-select form-select-sm" required>
                                <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>" <?php echo $role['name'] == 'Faculty' ? 'selected' : ''; ?>><?php echo htmlspecialchars($role['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label mb-1" style="font-size:0.8rem;">Password *</label>
                            <input type="password" name="password" class="form-control form-control-sm" required placeholder="Password" value="123456">
                        </div>
                        <div class="col-md-1 text-end">
                            <button type="submit" class="btn btn-primary btn-sm w-100" id="fastAddBtn">
                                <i class="bi bi-plus-circle"></i> Add
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Users Table -->
            <div class="card fade-in">
                <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center">
                    <span><i class="bi bi-people me-2"></i> All Users (Drag to Reorder)</span>
                    <span class="badge bg-primary mt-1 mt-sm-0" id="userCountBadge"><?php echo count($users); ?> Users</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="usersTable" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th style="width:30px"></th> <!-- Drag Handle -->
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Designation</th>
                                    <th>Role</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="usersTableBody">
                                <?php foreach ($users as $user): ?>
                                <tr data-id="<?php echo $user['id']; ?>">
                                    <td class="text-center align-middle">
                                        <i class="bi bi-grip-vertical text-muted drag-handle" style="cursor: grab; font-size: 1.2rem;"></i>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['designation'] ?? 'N/A'); ?></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($user['role_name']); ?></span></td>
                                    <td><?php echo htmlspecialchars($user['employee_type'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'danger'; ?>">
                                            <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php if(!empty($user['profile_slug'])): ?>
                                            <a href="<?php echo BASE_URL; ?>/faculty/<?php echo htmlspecialchars($user['profile_slug']); ?>" target="_blank" class="btn btn-outline-primary" title="View Public Profile">
                                                <i class="bi bi-globe"></i>
                                            </a>
                                            <?php endif; ?>
                                            <button class="btn btn-outline-secondary" onclick="viewUser(<?php echo $user['id']; ?>)" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary" onclick="editUser(<?php echo $user['id']; ?>)" title="Edit User">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary" onclick="toggleUser(<?php echo $user['id']; ?>, <?php echo $user['is_active'] ? 0 : 1; ?>)" title="<?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                <i class="bi bi-<?php echo $user['is_active'] ? 'person-x' : 'person-check'; ?>"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo addslashes($user['full_name']); ?>')" title="Delete User">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="<?php echo BASE_URL; ?>/api/users" id="addUserForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_user">
                        <input type="hidden" name="employee_id" id="autoEmployeeId" value="<?php echo $nextEmployeeId; ?>">
                        
                        <!-- Auto-generated Employee ID Display -->
                        <div class="row">
                            <div class="col-12 mb-3">
                                <div class="alert alert-info d-flex flex-wrap align-items-center">
                                    <i class="bi bi-info-circle me-2"></i> 
                                    <span>Employee ID will be auto-generated: <strong id="displayEmployeeId"><?php echo $nextEmployeeId; ?></strong></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" class="form-control" required placeholder="Enter full name">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" required placeholder="user@college.edu">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Mobile</label>
                                <input type="text" name="mobile" class="form-control" placeholder="9876543210">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Designation</label>
                                <input type="text" name="designation" class="form-control" placeholder="e.g., Assistant Professor">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Employee Type <span class="text-danger">*</span></label>
                                <select name="employee_type" class="form-control" required>
                                    <option value="">-- Select Type --</option>
                                    <option value="Teaching">Teaching</option>
                                    <option value="Non-Teaching">Non-Teaching</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select name="role_id" class="form-control" required>
                                    <option value="">-- Select Role --</option>
                                    <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> Create User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="<?php echo BASE_URL; ?>/api/users">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_user">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Employee ID</label>
                                <input type="text" name="employee_id" id="edit_employee_id" class="form-control" required readonly>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" id="edit_email" class="form-control" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Mobile</label>
                                <input type="text" name="mobile" id="edit_mobile" class="form-control">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Designation</label>
                                <input type="text" name="designation" id="edit_designation" class="form-control">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Employee Type <span class="text-danger">*</span></label>
                                <select name="employee_type" id="edit_employee_type" class="form-control" required>
                                    <option value="Teaching">Teaching</option>
                                    <option value="Non-Teaching">Non-Teaching</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select name="role_id" id="edit_role_id" class="form-control" required>
                                    <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Reset Password</label>
                                <input type="password" name="password" id="edit_password" class="form-control" placeholder="Enter new password (optional)">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> Update User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View User Modal -->
    <div class="modal fade" id="viewUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-lines-fill me-2"></i>User Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <table class="table table-bordered mb-0">
                        <tbody>
                            <tr>
                                <th class="bg-light" style="width: 35%;">Employee ID</th>
                                <td id="view_employee_id"></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Full Name</th>
                                <td id="view_full_name"></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Email</th>
                                <td id="view_email"></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Mobile</th>
                                <td id="view_mobile"></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Designation</th>
                                <td id="view_designation"></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Employee Type</th>
                                <td id="view_employee_type"></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Role</th>
                                <td><span class="badge bg-info" id="view_role"></span></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Status</th>
                                <td id="view_status"></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Created At</th>
                                <td id="view_created_at"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery (Required for DataTables) -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <!-- SortableJS -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

    <script>
        $(document).ready(function() {
            // Mobile Sidebar Toggle
            $('#sidebarToggle').click(function() {
                $('#sidebar').addClass('show');
                $('#sidebarOverlay').show();
            });

            $('#sidebarOverlay').click(function() {
                $('#sidebar').removeClass('show');
                $(this).hide();
            });
            
            // Initialize DataTable (disable sorting on the first column for the drag handle)
            var usersTable = $('#usersTable').DataTable({
                pageLength: 25,
                ordering: false, // Turn off Datatables ordering so manual sequence is respected
                responsive: true,
                language: {
                    search: "<i class='bi bi-search'></i>",
                    searchPlaceholder: "Search users..."
                }
            });
            
            // Initialize SortableJS
            var el = document.getElementById('usersTableBody');
            var sortable = Sortable.create(el, {
                handle: '.drag-handle',
                animation: 150,
                onEnd: function (evt) {
                    var sequences = [];
                    $('#usersTableBody tr').each(function(index) {
                        sequences.push({
                            id: $(this).data('id'),
                            sequence: index
                        });
                    });
                    
                    // Save sequence via AJAX
                    $.post('<?php echo BASE_URL; ?>/api/users', {
                        action: 'update_sequence',
                        sequences: sequences
                    }, function(response) {
                        if(response.success) {
                            // Show a small toast or silently succeed
                        } else {
                            alert('Failed to update sequence: ' + response.message);
                        }
                    });
                }
            });
            
            // Fast Add AJAX Form
            $('#fastAddUserForm').submit(function(e) {
                e.preventDefault();
                var btn = $('#fastAddBtn');
                btn.prop('disabled', true).html('<i class="spinner-border spinner-border-sm"></i>');
                
                $.post('<?php echo BASE_URL; ?>/api/users', $(this).serialize(), function(res) {
                    btn.prop('disabled', false).html('<i class="bi bi-plus-circle"></i> Add');
                    if(res.success) {
                        var u = res.user;
                        var row = '<tr data-id="'+u.id+'">' +
                            '<td class="text-center align-middle"><i class="bi bi-grip-vertical text-muted drag-handle" style="cursor: grab; font-size: 1.2rem;"></i></td>' +
                            '<td>' + u.full_name + '</td>' +
                            '<td>' + u.email + '</td>' +
                            '<td>' + (u.designation || 'N/A') + '</td>' +
                            '<td><span class="badge bg-info">' + u.role_name + '</span></td>' +
                            '<td>' + (u.employee_type || 'N/A') + '</td>' +
                            '<td><span class="badge bg-success">Active</span></td>' +
                            '<td>' +
                                '<div class="btn-group btn-group-sm" role="group">' +
                                    (u.profile_slug ? '<a href="<?php echo BASE_URL; ?>/faculty/'+u.profile_slug+'" target="_blank" class="btn btn-outline-primary"><i class="bi bi-globe"></i></a>' : '') +
                                    '<button class="btn btn-outline-secondary" onclick="viewUser('+u.id+')"><i class="bi bi-eye"></i></button>' +
                                    '<button class="btn btn-outline-secondary" onclick="editUser('+u.id+')"><i class="bi bi-pencil"></i></button>' +
                                    '<button class="btn btn-outline-secondary" onclick="toggleUser('+u.id+', 0)"><i class="bi bi-person-x"></i></button>' +
                                    '<button class="btn btn-outline-secondary" onclick="deleteUser('+u.id+', \''+u.full_name+'\')"><i class="bi bi-trash"></i></button>' +
                                '</div>' +
                            '</td>' +
                        '</tr>';
                        
                        $('#usersTableBody').append(row);
                        
                        // Update next employee ID
                        $('#fast_employee_id').val(res.next_employee_id);
                        $('#displayEmployeeId').text(res.next_employee_id);
                        $('#autoEmployeeId').val(res.next_employee_id);
                        
                        // Reset form but keep employee_id
                        $('#fastAddUserForm')[0].reset();
                        $('#fast_employee_id').val(res.next_employee_id);
                        $('#fast_role_id').val('3'); // Reset role to Faculty typically
                        $('#fastAddUserForm input[name="password"]').val('123456');

                        alert(res.message);
                        
                        // Update counter
                        var countStr = $('#userCountBadge').text();
                        var count = parseInt(countStr) || 0;
                        $('#userCountBadge').text((count + 1) + ' Users');
                        
                    } else {
                        alert(res.message);
                    }
                }).fail(function() {
                    btn.prop('disabled', false).html('<i class="bi bi-plus-circle"></i> Add');
                    alert('An error occurred.');
                });
            });
        });

        function copyPassword(password) {
            navigator.clipboard.writeText(password).then(function() {
                alert('Password copied to clipboard!');
            });
        }
        
        function clearSession() {
            // Handled server-side usually, but visual clear done by dismiss
        }

        function viewUser(id) {
            $.post('<?php echo BASE_URL; ?>/api/users', { action: 'get_user', user_id: id }, function(response) {
                var res = typeof response === 'string' ? JSON.parse(response) : response;
                if (res.success) {
                    var user = res.data;
                    $('#view_employee_id').text(user.employee_id);
                    $('#view_full_name').text(user.full_name);
                    $('#view_email').text(user.email);
                    $('#view_mobile').text(user.mobile || 'N/A');
                    $('#view_designation').text(user.designation || 'N/A');
                    $('#view_employee_type').text(user.employee_type || 'N/A');
                    $('#view_role').text(user.role_name);
                    
                    var statusBadge = user.is_active 
                        ? '<span class="badge bg-success">Active</span>' 
                        : '<span class="badge bg-danger">Inactive</span>';
                    $('#view_status').html(statusBadge);
                    $('#view_created_at').text(user.created_at);
                    
                    var modal = new bootstrap.Modal(document.getElementById('viewUserModal'));
                    modal.show();
                } else {
                    alert('Error loading user details');
                }
            });
        }

        function editUser(id) {
            $.post('<?php echo BASE_URL; ?>/api/users', { action: 'get_user', user_id: id }, function(response) {
                var res = typeof response === 'string' ? JSON.parse(response) : response;
                if (res.success) {
                    var user = res.data;
                    $('#edit_user_id').val(user.id);
                    $('#edit_employee_id').val(user.employee_id);
                    $('#edit_full_name').val(user.full_name);
                    $('#edit_email').val(user.email);
                    $('#edit_mobile').val(user.mobile);
                    $('#edit_designation').val(user.designation);
                    $('#edit_employee_type').val(user.employee_type);
                    $('#edit_role_id').val(user.role_id);
                    
                    var modal = new bootstrap.Modal(document.getElementById('editUserModal'));
                    modal.show();
                } else {
                    alert('Error loading user details');
                }
            });
        }

        function toggleUser(id, status) {
            var actionStr = status ? 'activate' : 'deactivate';
            if (confirm(`Are you sure you want to ${actionStr} this user?`)) {
                $.ajax({
                    url: '<?php echo BASE_URL; ?>/api/users',
                    type: 'POST',
                    data: {
                        action: 'toggle_user',
                        user_id: id,
                        status: status
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            window.location.reload();
                        } else {
                            alert(res.message || 'Error toggling user status');
                        }
                    },
                    error: function() {
                        alert('Server error occurred while toggling status');
                    }
                });
            }
        }

        function deleteUser(id, name) {
            if (confirm(`Are you sure you want to permanently delete user "${name}"?\n\nWARNING: This will also delete all their task assignments and evaluations! This action cannot be undone.`)) {
                $.ajax({
                    url: '<?php echo BASE_URL; ?>/api/users',
                    type: 'POST',
                    data: {
                        action: 'delete_user',
                        user_id: id
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            window.location.reload();
                        } else {
                            alert(res.message || 'Error deleting user');
                        }
                    },
                    error: function() {
                        alert('Server error occurred while deleting user');
                    }
                });
            }
        }
    </script>
</body>
</html>
