<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Check if user is principal
if ($_SESSION['role'] !== 'Principal') {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../models/Task.php';
require_once __DIR__ . '/../../models/User.php';

$taskModel = new Task();
$userModel = new User();
$db = getDB();

// Get statistics
$pendingSubmissions = $taskModel->getPendingSubmissions();
$faculty = array_filter($userModel->getAllUsers(), function($u) { 
    return $u['role_name'] == 'Faculty'; 
});
$totalFaculty = count($faculty);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Principal Dashboard - Faculty Appraisal System</title>
    
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
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
            color: #ecf0f1 !important;
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
        
        /* Sidebar Brand */
        .sidebar-brand {
            color: #f1c40f;
            text-align: center;
            padding: 20px 0 15px 0;
        }
        
        .sidebar-brand i {
            font-size: 2.5rem;
        }
        
        .sidebar-brand h6 {
            color: #ecf0f1;
            margin-top: 5px;
            font-weight: 600;
        }
        
        .sidebar-brand small {
            color: #f1c40f;
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
            box-shadow: 0 0 0 3px rgba(241, 196, 15, 0.3);
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
        
        /* Stats Cards */
        .stats-card {
            border-radius: 12px;
            padding: 18px 20px;
            color: white;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: default;
            height: 100%;
            border: none;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stats-card .card-title {
            font-size: 0.85rem;
            font-weight: 500;
            opacity: 0.9;
            margin-bottom: 6px;
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
            opacity: 0.2;
        }
        
        .bg-dark-blue {
            background: #2c3e50;
        }
        
        .bg-midnight {
            background: #34495e;
        }
        
        .bg-gold {
            background: #f1c40f;
            color: #1a2332;
        }
        
        .bg-orange {
            background: #e67e22;
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
        
        /* Notification Badge */
        .notification-badge {
            background: #f1c40f;
            color: #1a2332;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.9rem;
            white-space: nowrap;
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
            
            .stats-card h2 {
                font-size: 2.2rem;
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
            
            .stats-card h2 {
                font-size: 2.5rem;
            }
            
            .stats-card {
                padding: 22px 25px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px 10px;
            }
            
            .navbar-toggle {
                padding: 8px 12px;
                font-size: 1.2rem;
                top: 8px;
                left: 8px;
            }
            
            .stats-card {
                padding: 14px 15px;
            }
            
            .stats-card h2 {
                font-size: 1.5rem;
            }
            
            .stats-card .card-title {
                font-size: 0.75rem;
            }
            
            .stats-card .stats-icon {
                font-size: 2rem;
                top: 10px;
                right: 10px;
            }
            
            .notification-badge {
                font-size: 0.75rem;
                padding: 5px 12px;
            }
            
            .sidebar-brand i {
                font-size: 2rem;
            }
            
            .sidebar-brand h6 {
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 400px) {
            .main-content {
                padding: 10px 5px;
            }
            
            .stats-card h2 {
                font-size: 1.3rem;
            }
            
            .stats-card .card-title {
                font-size: 0.7rem;
            }
            
            .stats-card .stats-icon {
                font-size: 1.5rem;
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
        
        /* Utilities */
        .text-muted {
            color: #6c757d !important;
        }
        
        .fw-bold {
            font-weight: 600;
        }
        
        /* Stats Card Position Relative for Icon */
        .stats-card {
            position: relative;
            overflow: hidden;
        }
        
        /* Grid Responsive */
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
            <div class="sidebar-brand">
                <i class="bi bi-mortarboard-fill"></i>
                <h6>Appraisal System</h6>
                <small>Principal Panel</small>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link active" href="<?php echo BASE_URL; ?>/principal/dashboard">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/assign-task">
                        <i class="bi bi-plus-circle"></i> Assign Task
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/task-status">
                        <i class="bi bi-list-task"></i> Task Status
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/evaluations">
                        <i class="bi bi-check2-square"></i> Evaluations
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/reports">
                        <i class="bi bi-file-earmark-pdf"></i> Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/settings">
                        <i class="bi bi-sliders"></i> Performance Settings
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
                <h1 class="h2 mb-0">Principal Dashboard</h1>
                <div class="btn-toolbar mt-2 mt-sm-0">
                    <span class="notification-badge">
                        <i class="bi bi-bell me-1"></i> 
                        <?php echo getUnreadNotificationsCount($_SESSION['user_id']); ?>
                    </span>
                </div>
            </div>

            <!-- Guided Principal Workflow Banner -->
            <div class="guided-banner fade-in" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="mb-1 fw-bold text-white"><i class="bi bi-person-badge-fill me-2 text-warning"></i>Principal Appraisal Workflow Guide</h5>
                        <p class="mb-0 small text-light opacity-75">3 essential actions to manage institution-wide faculty performance appraisals.</p>
                    </div>
                    <?php 
                    $activeYear = getActiveAcademicYear();
                    ?>
                    <span class="badge bg-warning text-dark px-3 py-2 fw-bold">
                        <i class="bi bi-calendar3 me-1"></i> Active Year: <?php echo htmlspecialchars($activeYear['year_name'] ?? '2025-2026'); ?>
                    </span>
                </div>
                <div class="guided-steps">
                    <a href="<?php echo BASE_URL; ?>/principal/assign-task" class="guided-step-item">
                        <div class="guided-step-num" style="background: #3b82f6;">1</div>
                        <div class="guided-step-text">
                            <strong>Assign Direct Tasks</strong>
                            <small>Assign responsibilities</small>
                        </div>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/principal/evaluations" class="guided-step-item">
                        <div class="guided-step-num" style="background: #ec4899;">2</div>
                        <div class="guided-step-text">
                            <strong>Grade Submissions</strong>
                            <small><?php echo count($pendingSubmissions); ?> Pending review</small>
                        </div>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/principal/reports" class="guided-step-item">
                        <div class="guided-step-num" style="background: #10b981;">3</div>
                        <div class="guided-step-text">
                            <strong>Generate Reports</strong>
                            <small>Monthly & Yearly A4 print</small>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Quick Action Hub -->
            <div class="row g-3 mb-4 fade-in">
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="<?php echo BASE_URL; ?>/principal/evaluations" class="quick-action-card">
                        <div class="quick-action-icon purple">
                            <i class="bi bi-check2-square"></i>
                        </div>
                        <div class="quick-action-info">
                            <strong>Evaluate Tasks</strong>
                            <p><?php echo count($pendingSubmissions); ?> Submissions awaiting score</p>
                        </div>
                    </a>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="<?php echo BASE_URL; ?>/principal/reports" class="quick-action-card">
                        <div class="quick-action-icon emerald">
                            <i class="bi bi-file-earmark-pdf-fill"></i>
                        </div>
                        <div class="quick-action-info">
                            <strong>Print A4 Marksheets</strong>
                            <p>Individual & Summary PDF reports</p>
                        </div>
                    </a>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="<?php echo BASE_URL; ?>/principal/assign-task" class="quick-action-card">
                        <div class="quick-action-icon blue">
                            <i class="bi bi-plus-circle-fill"></i>
                        </div>
                        <div class="quick-action-info">
                            <strong>Assign Direct Tasks</strong>
                            <p>Delegate institutional duties</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="row g-3 fade-in">
                <div class="col-6 col-md-3">
                    <div class="stats-card bg-dark-blue">
                        <div class="stats-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="card-title">Total Faculty</div>
                        <h2><?php echo $totalFaculty; ?></h2>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stats-card bg-midnight">
                        <div class="stats-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="card-title">Pending Submissions</div>
                        <h2><?php echo count($pendingSubmissions); ?></h2>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stats-card bg-gold">
                        <div class="stats-icon">
                            <i class="bi bi-list-check"></i>
                        </div>
                        <div class="card-title">Total Tasks</div>
                        <h2><?php 
                            $stmt = $db->query("SELECT COUNT(*) as count FROM tasks");
                            echo $stmt->fetch()['count'];
                        ?></h2>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stats-card bg-orange">
                        <div class="stats-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="card-title">Completed</div>
                        <h2><?php 
                            $stmt = $db->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'Approved'");
                            echo $stmt->fetch()['count'];
                        ?></h2>
                    </div>
                </div>
            </div>
            
            <!-- Welcome Alert -->
            <div class="alert alert-info mt-3 fade-in" role="alert">
                <div class="d-flex align-items-start">
                    <i class="bi bi-info-circle me-2" style="font-size: 1.2rem;"></i>
                    <div>
                        Welcome to Principal Dashboard. You can assign tasks, evaluate submissions, and generate reports.
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    
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
