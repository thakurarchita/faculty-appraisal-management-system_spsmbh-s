<?php
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
require_once __DIR__ . '/../../includes/functions.php';

$userModel = new User();
$users = $userModel->getAllUsers();
$db = getDB();

// Get statistics
$totalUsers = count($users);
$totalFaculty = array_filter($users, function($u) { return $u['role_name'] == 'Faculty'; });
$activeUsers = array_filter($users, function($u) { return $u['is_active'] == 1; });

// Get admin's tasks
$adminId = $_SESSION['user_id'];
$adminTasks = $db->prepare("
    SELECT t.*, tc.name as category_name, u.full_name as assigned_by_name
    FROM tasks t
    JOIN task_categories tc ON t.category_id = tc.id
    JOIN users u ON t.assigned_by = u.id
    WHERE t.user_id = ? AND t.is_template = 0
    ORDER BY t.created_at DESC
    LIMIT 5
");
$adminTasks->execute([$adminId]);
$adminTasksList = $adminTasks->fetchAll();

// Get total tasks count
$stmt = $db->query("SELECT COUNT(*) as count FROM tasks");
$totalTasks = $stmt->fetch()['count'];

// Get pending tasks count
$stmt = $db->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'Pending'");
$pendingTasks = $stmt->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Admin Dashboard - Faculty Appraisal System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    
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
            transition: transform 0.2s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        
        .card-header {
            border-radius: 12px 12px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Stats Cards */
        .stats-card {
            border-radius: 12px;
            padding: 20px;
            color: white;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: default;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stats-card .card-title {
            font-size: 0.9rem;
            font-weight: 500;
            opacity: 0.9;
            margin-bottom: 8px;
        }
        
        .stats-card h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        
        .stats-card .stats-icon {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 2.5rem;
            opacity: 0.3;
        }
        
        .bg-primary-gradient {
            background: linear-gradient(135deg, #0d6efd, #0a58ca);
        }
        
        .bg-success-gradient {
            background: linear-gradient(135deg, #198754, #157347);
        }
        
        .bg-info-gradient {
            background: linear-gradient(135deg, #0dcaf0, #0aa2c0);
        }
        
        .bg-warning-gradient {
            background: linear-gradient(135deg, #ffc107, #d39e00);
        }
        
        /* Table Responsive */
        .table-responsive {
            border-radius: 8px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .table {
            margin-bottom: 0;
            font-size: 0.95rem;
        }
        
        .table th {
            font-weight: 600;
            border-top: none;
            white-space: nowrap;
        }
        
        .table td {
            vertical-align: middle;
            padding: 12px 10px;
        }
        
        /* Badge Styles */
        .badge {
            padding: 6px 12px;
            font-weight: 500;
            font-size: 0.8rem;
            border-radius: 6px;
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
            
            .stats-card h2 {
                font-size: 2.5rem;
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
            
            .stats-card {
                padding: 15px;
            }
            
            .stats-card h2 {
                font-size: 1.5rem;
            }
            
            .stats-card .stats-icon {
                font-size: 2rem;
                top: 10px;
                right: 10px;
            }
            
            .navbar-toggle {
                padding: 8px 12px;
                font-size: 1.2rem;
                top: 8px;
                left: 8px;
            }
            
            .table {
                font-size: 0.85rem;
            }
            
            .table td, .table th {
                padding: 8px 6px;
            }
            
            .badge {
                padding: 4px 8px;
                font-size: 0.7rem;
            }
        }
        
        @media (max-width: 400px) {
            .main-content {
                padding: 10px 5px;
            }
            
            .card-body {
                padding: 12px;
            }
            
            .stats-card h2 {
                font-size: 1.3rem;
            }
            
            .stats-card .card-title {
                font-size: 0.8rem;
            }
        }
        
        /* Animation */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Notification Badge */
        .notification-badge {
            position: relative;
        }
        
        .notification-badge .badge-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 0.7rem;
            min-width: 18px;
            text-align: center;
        }
        
        /* Button Styles */
        .btn-sm {
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 0.85rem;
        }
        
        .btn-primary {
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.3);
        }
        
        /* Text Utilities */
        .text-muted {
            color: #6c757d !important;
        }
        
        .fw-bold {
            font-weight: 600;
        }
        
        /* Stats Container */
        .stats-container {
            position: relative;
        }
        
        /* Responsive Grid Fixes */
        .row.g-3 {
            --bs-gutter-y: 1rem;
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
                    <a class="nav-link active" href="<?php echo BASE_URL; ?>/admin/dashboard">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/users">
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
                <h1 class="h2 mb-0">Admin Dashboard</h1>
                <div class="btn-toolbar mt-2 mt-sm-0">
                    <span class="badge bg-primary notification-badge">
                        <i class="bi bi-bell"></i> 
                        <?php echo getUnreadNotificationsCount($_SESSION['user_id']); ?>
                    </span>
                </div>
            </div>

            <!-- Guided Admin Workflow Banner -->
            <div class="guided-banner fade-in">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="mb-1 fw-bold"><i class="bi bi-compass me-2 text-warning"></i>Admin System Guide & Workflow</h5>
                        <p class="mb-0 small text-light opacity-75">Follow this simple 4-step workflow to manage appraisals and faculty accounts smoothly.</p>
                    </div>
                    <span class="badge bg-light text-dark px-3 py-2 fw-semibold">
                        <i class="bi bi-shield-check me-1 text-primary"></i> Administrator Mode
                    </span>
                </div>
                <div class="guided-steps">
                    <a href="<?php echo BASE_URL; ?>/admin/users" class="guided-step-item">
                        <div class="guided-step-num">1</div>
                        <div class="guided-step-text">
                            <strong>Manage Users</strong>
                            <small>Create & activate faculty</small>
                        </div>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/templates" class="guided-step-item">
                        <div class="guided-step-num">2</div>
                        <div class="guided-step-text">
                            <strong>Task Templates</strong>
                            <small>Set default task catalog</small>
                        </div>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/principal/assign-task" class="guided-step-item">
                        <div class="guided-step-num">3</div>
                        <div class="guided-step-text">
                            <strong>Assign Tasks</strong>
                            <small>Push tasks to faculty</small>
                        </div>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/task-status" class="guided-step-item">
                        <div class="guided-step-num">4</div>
                        <div class="guided-step-text">
                            <strong>Track Progress</strong>
                            <small>Monitor submissions</small>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Quick Action Hub -->
            <div class="row g-3 mb-4 fade-in">
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="<?php echo BASE_URL; ?>/admin/users" class="quick-action-card">
                        <div class="quick-action-icon blue">
                            <i class="bi bi-person-plus-fill"></i>
                        </div>
                        <div class="quick-action-info">
                            <strong>Add / Edit Users</strong>
                            <p>Manage faculty & staff profiles</p>
                        </div>
                    </a>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="<?php echo BASE_URL; ?>/principal/assign-task" class="quick-action-card">
                        <div class="quick-action-icon purple">
                            <i class="bi bi-send-plus-fill"></i>
                        </div>
                        <div class="quick-action-info">
                            <strong>Assign Tasks</strong>
                            <p>Direct or template task dispatch</p>
                        </div>
                    </a>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="<?php echo BASE_URL; ?>/admin/templates" class="quick-action-card">
                        <div class="quick-action-icon amber">
                            <i class="bi bi-file-earmark-ruled-fill"></i>
                        </div>
                        <div class="quick-action-info">
                            <strong>Task Templates</strong>
                            <p>Configure default task list</p>
                        </div>
                    </a>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="<?php echo BASE_URL; ?>/admin/task-status" class="quick-action-card">
                        <div class="quick-action-icon emerald">
                            <i class="bi bi-pie-chart-fill"></i>
                        </div>
                        <div class="quick-action-info">
                            <strong>Task Statuses</strong>
                            <p>Overview of all faculty progress</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="row g-3 fade-in">
                <div class="col-6 col-md-3">
                    <div class="stats-card bg-primary-gradient position-relative">
                        <div class="stats-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="card-title">Total Users</div>
                        <h2><?php echo $totalUsers; ?></h2>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stats-card bg-success-gradient position-relative">
                        <div class="stats-icon">
                            <i class="bi bi-person-video3"></i>
                        </div>
                        <div class="card-title">Faculty</div>
                        <h2><?php echo count($totalFaculty); ?></h2>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stats-card bg-info-gradient position-relative">
                        <div class="stats-icon">
                            <i class="bi bi-person-check"></i>
                        </div>
                        <div class="card-title">Active Users</div>
                        <h2><?php echo count($activeUsers); ?></h2>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stats-card bg-warning-gradient position-relative">
                        <div class="stats-icon">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <div class="card-title">Total Tasks</div>
                        <h2><?php echo $totalTasks; ?></h2>
                    </div>
                </div>
            </div>

            <!-- My Recent Tasks -->
            <div class="row mt-4 fade-in">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-info text-white d-flex flex-wrap justify-content-between align-items-center">
                            <span><i class="bi bi-list-task me-2"></i> My Recent Tasks (Assigned by Principal)</span>
                            <span class="badge bg-light text-info mt-1 mt-sm-0">Last 5 Tasks</span>
                        </div>
                        <div class="card-body">
                            <?php if (count($adminTasksList) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Task</th>
                                            <th>Category</th>
                                            <th>Status</th>
                                            <th>Assigned By</th>
                                            <th>Due Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($adminTasksList as $task): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($task['task_title']); ?></td>
                                            <td><?php echo $task['category_name']; ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $task['status'] == 'Pending' ? 'warning' : ($task['status'] == 'In Progress' ? 'info' : ($task['status'] == 'Submitted' ? 'primary' : ($task['status'] == 'Approved' ? 'success' : 'secondary'))); ?>">
                                                    <?php echo $task['status']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($task['assigned_by_name']); ?></td>
                                            <td><?php echo $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : 'N/A'; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex justify-content-end mt-3">
                                <a href="<?php echo BASE_URL; ?>/admin/my-tasks" class="btn btn-primary btn-sm">
                                    <i class="bi bi-arrow-right me-1"></i> View All My Tasks
                                </a>
                            </div>
                            <?php else: ?>
                            <p class="text-muted text-center py-3 mb-0">
                                <i class="bi bi-inbox me-2" style="font-size: 1.5rem;"></i><br>
                                No tasks assigned to you yet.
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Pending Tasks Summary -->
            <div class="row mt-3 fade-in">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-warning text-dark d-flex flex-wrap justify-content-between align-items-center">
                            <span><i class="bi bi-clock me-2"></i> Pending Tasks Overview</span>
                            <span class="badge bg-dark mt-1 mt-sm-0">Summary</span>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 text-center">
                                <div class="col-4 col-md-4">
                                    <div class="py-2">
                                        <h3 class="mb-1 text-warning"><?php echo $pendingTasks; ?></h3>
                                        <p class="text-muted small mb-0">Pending Tasks</p>
                                    </div>
                                </div>
                                <div class="col-4 col-md-4">
                                    <div class="py-2">
                                        <h3 class="mb-1 text-success"><?php echo $totalTasks - $pendingTasks; ?></h3>
                                        <p class="text-muted small mb-0">Completed/Approved</p>
                                    </div>
                                </div>
                                <div class="col-4 col-md-4">
                                    <div class="py-2">
                                        <h3 class="mb-1 text-primary"><?php echo round(($totalTasks > 0 ? ($totalTasks - $pendingTasks) / $totalTasks * 100 : 0), 1); ?>%</h3>
                                        <p class="text-muted small mb-0">Completion Rate</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Progress Bar for visual representation -->
                            <?php 
                            $completionRate = $totalTasks > 0 ? round(($totalTasks - $pendingTasks) / $totalTasks * 100, 1) : 0;
                            $barColor = $completionRate >= 75 ? 'success' : ($completionRate >= 50 ? 'warning' : 'danger');
                            ?>
                            <div class="mt-3">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span>Task Completion</span>
                                    <span><?php echo $completionRate; ?>%</span>
                                </div>
                                <div class="progress" style="height: 8px; border-radius: 4px;">
                                    <div class="progress-bar bg-<?php echo $barColor; ?>" 
                                         role="progressbar" 
                                         style="width: <?php echo $completionRate; ?>%; border-radius: 4px;"
                                         aria-valuenow="<?php echo $completionRate; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Welcome Alert -->
            <div class="alert alert-info mt-3 fade-in" role="alert">
                <div class="d-flex align-items-start">
                    <i class="bi bi-info-circle me-2" style="font-size: 1.2rem;"></i>
                    <div>
                        Welcome to Admin Dashboard. You can manage users, assign default tasks, and view task status from here.
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="../../assets/js/dashboard.js"></script>
    
    <script>
        $(document).ready(function() {
            // Sidebar Toggle
            const sidebar = $('#sidebar');
            const overlay = $('#sidebarOverlay');
            const toggleBtn = $('#sidebarToggle');
            
            function toggleSidebar() {
                sidebar.toggleClass('show');
                overlay.toggleClass('active');
                document.body.style.overflow = sidebar.hasClass('show') ? 'hidden' : '';
            }
            
            toggleBtn.on('click', toggleSidebar);
            overlay.on('click', toggleSidebar);
            
            // Close sidebar on escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.hasClass('show')) {
                    toggleSidebar();
                }
            });
            
            // Handle window resize - close sidebar on large screens
            $(window).on('resize', function() {
                if ($(window).width() >= 768 && sidebar.hasClass('show')) {
                    sidebar.removeClass('show');
                    overlay.removeClass('active');
                    document.body.style.overflow = '';
                }
            });
            
            // Add animation delay to stats cards
            $('.stats-card').each(function(index) {
                $(this).css('animation-delay', (index * 0.1) + 's');
            });
        });
    </script>
</body>
</html>
