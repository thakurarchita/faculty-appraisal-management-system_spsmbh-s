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

// Check if user is principal
if ($_SESSION['role'] !== 'Principal') {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Fix the path - use __DIR__ to get correct path
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/functions.php';

$db = getDB();

// Get all categories with their current settings
$stmt = $db->query("
    SELECT 
        tc.id as category_id,
        tc.name as category_name,
        tc.max_marks as default_max_marks,
        ps.id as setting_id,
        ps.max_marks as current_max_marks,
        ps.duration,
        ps.updated_at,
        u.full_name as updated_by_name
    FROM task_categories tc
    LEFT JOIN performance_settings ps ON ps.category_id = tc.id
    LEFT JOIN users u ON u.id = ps.updated_by
    ORDER BY tc.id
");
$categories = $stmt->fetchAll();

// Get current duration setting
$durationStmt = $db->query("SELECT DISTINCT duration FROM performance_settings ORDER BY updated_at DESC LIMIT 1");
$currentDuration = $durationStmt->fetch();
$currentDurationValue = $currentDuration ? $currentDuration['duration'] : 'Semester';

// Get audit log for settings changes
$auditStmt = $db->query("
    SELECT 
        ps.*,
        tc.name as category_name,
        u.full_name as changed_by_name
    FROM performance_settings ps
    JOIN task_categories tc ON tc.id = ps.category_id
    JOIN users u ON u.id = ps.updated_by
    ORDER BY ps.updated_at DESC
    LIMIT 20
");
$auditLogs = $auditStmt->fetchAll();

// Get statistics
$statsStmt = $db->query("
    SELECT 
        COUNT(DISTINCT t.user_id) as total_faculty,
        COUNT(DISTINCT t.id) as total_tasks,
        COUNT(DISTINCT CASE WHEN t.status = 'Approved' THEN t.id END) as completed_tasks,
        COUNT(DISTINCT CASE WHEN t.status = 'Pending' THEN t.id END) as pending_tasks
    FROM tasks t
");
$stats = $statsStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Performance Settings - Principal Panel</title>
    
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
            background-color: #f8f9fc;
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
            color: #fff !important;
            background: rgba(255,255,255,0.1);
        }
        
        .sidebar .nav-link.active {
            color: #f1c40f !important;
            background: rgba(255,255,255,0.1);
        }
        
        .sidebar .nav-link i {
            margin-right: 12px;
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
        }
        
        .sidebar .nav-header {
            color: rgba(255,255,255,0.4);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.75rem 1rem;
            margin-top: 0.5rem;
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
        
        /* Cards */
        .settings-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            transition: transform 0.2s;
            background: white;
        }
        .settings-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .settings-card .card-header {
            background: white;
            border-bottom: 2px solid #f1c40f;
            padding: 0.8rem 1rem;
            font-weight: 600;
        }
        .settings-card .card-header.bg-primary {
            background: linear-gradient(135deg, #1a2332 0%, #2c3e50 100%) !important;
            color: white;
            border-bottom: none;
            border-radius: 12px 12px 0 0;
        }
        .settings-card .card-body {
            padding: 1rem;
        }
        
        /* Category Items */
        .category-item {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            background: #f8f9fc;
            border-left: 4px solid #f1c40f;
            transition: all 0.3s;
        }
        .category-item:hover {
            background: #e9ecef;
        }
        .category-item .category-name {
            font-weight: 600;
            color: #1a2332;
        }
        .category-item .category-marks {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        /* Duration Options */
        .duration-option {
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        .duration-option:hover {
            border-color: #f1c40f;
            background: #fff8e7;
        }
        .duration-option.active {
            border-color: #f1c40f;
            background: #f1c40f;
            color: #1a2332;
        }
        .duration-option.active i {
            color: #1a2332;
        }
        .duration-option i {
            font-size: 1.3rem;
            display: block;
            margin-bottom: 3px;
        }
        
        /* Stat Boxes */
        .stat-box {
            text-align: center;
            padding: 12px 10px;
            border-radius: 8px;
            background: #f8f9fc;
            height: 100%;
        }
        .stat-box .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1a2332;
        }
        .stat-box .stat-label {
            font-size: 0.75rem;
            color: #6c757d;
        }
        .stat-box .stat-icon {
            font-size: 1.5rem;
            color: #f1c40f;
        }
        
        /* Audit Log */
        .audit-log-item {
            padding: 8px 12px;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.85rem;
        }
        .audit-log-item:last-child {
            border-bottom: none;
        }
        .audit-log-item .log-time {
            color: #6c757d;
            font-size: 0.75rem;
        }
        
        /* Form Controls */
        .form-control:focus {
            border-color: #f1c40f;
            box-shadow: 0 0 0 0.2rem rgba(241, 196, 15, 0.25);
        }
        
        /* Buttons */
        .btn-warning {
            background: #f1c40f;
            border-color: #f1c40f;
            color: #1a2332;
        }
        .btn-warning:hover {
            background: #d4ac0d;
            border-color: #d4ac0d;
            color: #1a2332;
        }
        .btn-primary {
            background: #1a2332;
            border-color: #1a2332;
        }
        .btn-primary:hover {
            background: #2c3e50;
            border-color: #2c3e50;
        }
        
        /* Toast */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 90%;
        }
        .toast {
            background: white;
            border-radius: 8px;
            padding: 12px 16px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            margin-bottom: 10px;
            border-left: 4px solid #28a745;
            min-width: 200px;
            animation: slideIn 0.3s ease;
        }
        .toast.error {
            border-left-color: #dc3545;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* Responsive */
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
            
            .stat-box .stat-number {
                font-size: 2rem;
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
            
            .settings-card .card-body {
                padding: 1.5rem;
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
            
            .settings-card .card-header {
                padding: 0.6rem 0.8rem;
                font-size: 0.9rem;
            }
            .settings-card .card-body {
                padding: 0.8rem;
            }
            
            .category-item {
                padding: 10px 12px;
            }
            .category-item .category-name {
                font-size: 0.9rem;
            }
            .category-item .category-marks {
                font-size: 0.7rem;
            }
            
            .duration-option {
                padding: 8px 10px;
            }
            .duration-option i {
                font-size: 1.1rem;
            }
            .duration-option strong {
                font-size: 0.85rem;
            }
            
            .stat-box {
                padding: 8px 6px;
            }
            .stat-box .stat-number {
                font-size: 1.2rem;
            }
            .stat-box .stat-label {
                font-size: 0.65rem;
            }
            .stat-box .stat-icon {
                font-size: 1.2rem;
            }
            
            .audit-log-item {
                padding: 6px 8px;
                font-size: 0.75rem;
            }
            .audit-log-item .log-time {
                font-size: 0.65rem;
            }
            
            .toast {
                min-width: 150px;
                padding: 10px 14px;
                font-size: 0.85rem;
            }
            
            .btn-lg {
                padding: 8px 16px;
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 400px) {
            .main-content {
                padding: 10px 5px;
            }
            
            .settings-card .card-body {
                padding: 0.6rem;
            }
            
            .category-item {
                padding: 8px 10px;
            }
            
            .stat-box .stat-number {
                font-size: 1rem;
            }
            
            .btn-lg {
                padding: 6px 12px;
                font-size: 0.8rem;
                width: 100%;
            }
            
            .duration-option i {
                font-size: 1rem;
            }
            .duration-option strong {
                font-size: 0.75rem;
            }
        }
        
        /* Scrollbar */
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
        
        /* Animation */
        .fade-in {
            animation: fadeIn 0.4s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Principal Badge */
        .principal-badge {
            background: #f1c40f;
            color: #1a2332;
            padding: 6px 18px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
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
            <div class="text-center mb-4" style="color: #f1c40f; padding: 10px 0;">
                <i class="bi bi-mortarboard-fill" style="font-size: 2.5rem;"></i>
                <h6 style="color: #ecf0f1; margin-top: 5px;">Appraisal System</h6>
                <small style="color: #f1c40f;">Principal Panel</small>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/dashboard">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="<?php echo BASE_URL; ?>/principal/settings">
                        <i class="bi bi-gear"></i> Settings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/assign-task">
                        <i class="bi bi-plus-circle"></i> Assign Task
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
                <div>
                    <h1 class="h2 mb-0">Performance Settings</h1>
                    <small class="text-muted">Configure category-wise maximum marks and evaluation duration</small>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-2 mt-sm-0">
                    <button class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> <span class="d-none d-sm-inline">Refresh</span>
                    </button>
                    <span class="principal-badge">
                        <i class="bi bi-person-badge me-1"></i> Principal
                    </span>
                </div>
            </div>

            <!-- Toast Container -->
            <div class="toast-container" id="toastContainer"></div>

            <!-- Stats Row -->
            <div class="row g-2 g-md-3 mb-4 fade-in">
                <div class="col-6 col-md-3">
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-people"></i></div>
                        <div class="stat-number"><?php echo $stats['total_faculty'] ?? 0; ?></div>
                        <div class="stat-label">Total Faculty</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-list-task"></i></div>
                        <div class="stat-number"><?php echo $stats['total_tasks'] ?? 0; ?></div>
                        <div class="stat-label">Total Tasks</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
                        <div class="stat-number"><?php echo $stats['completed_tasks'] ?? 0; ?></div>
                        <div class="stat-label">Completed Tasks</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-box">
                        <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
                        <div class="stat-number"><?php echo $stats['pending_tasks'] ?? 0; ?></div>
                        <div class="stat-label">Pending Tasks</div>
                    </div>
                </div>
            </div>

            <!-- Category Settings -->
            <div class="settings-card fade-in">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-sliders2 me-2"></i> Category Maximum Marks
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3 small">
                        <i class="bi bi-info-circle me-1"></i> 
                        Configure the maximum marks for each category. These values will be used for performance calculation.
                    </p>
                    <form id="settingsForm" method="POST" action="<?php echo BASE_URL; ?>/api/principal">
                        <input type="hidden" name="action" value="update_settings">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <?php foreach ($categories as $category): ?>
                        <div class="category-item">
                            <div class="row g-2 align-items-center">
                                <div class="col-12 col-sm-6 col-md-3">
                                    <div class="category-name">
                                        <i class="bi bi-tag"></i> <?php echo htmlspecialchars($category['category_name']); ?>
                                    </div>
                                    <div class="category-marks">
                                        <i class="bi bi-info-circle"></i> 
                                        Default: <?php echo $category['default_max_marks']; ?> marks
                                    </div>
                                </div>
                                <div class="col-6 col-sm-4 col-md-3">
                                    <label class="form-label mb-0 small">Current Max Marks</label>
                                    <input type="number" 
                                           name="max_marks[<?php echo $category['category_id']; ?>]" 
                                           class="form-control form-control-sm" 
                                           value="<?php echo $category['current_max_marks'] ?? $category['default_max_marks']; ?>"
                                           min="0" 
                                           max="100"
                                           required>
                                </div>
                                <div class="col-6 col-sm-5 col-md-4">
                                    <div class="text-muted small">
                                        <?php if ($category['updated_at']): ?>
                                        <i class="bi bi-clock"></i> 
                                        <span class="d-none d-sm-inline">Last updated: </span>
                                        <?php echo date('d M Y', strtotime($category['updated_at'])); ?>
                                        <br class="d-sm-none">
                                        <span class="d-none d-sm-inline"><br></span>
                                        <i class="bi bi-person"></i> 
                                        <?php echo htmlspecialchars($category['updated_by_name'] ?? 'System'); ?>
                                        <?php else: ?>
                                        <i class="bi bi-info-circle"></i> Not configured yet
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-12 col-sm-3 col-md-2 text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary w-100 w-sm-auto" 
                                            onclick="resetCategory(<?php echo $category['category_id']; ?>, <?php echo $category['default_max_marks']; ?>)">
                                        <i class="bi bi-arrow-counterclockwise"></i> <span class="d-none d-sm-inline">Reset</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="mt-3">
                            <button type="submit" class="btn btn-warning btn-lg w-100 w-sm-auto">
                                <i class="bi bi-save me-2"></i> Save Category Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Duration Settings -->
            <div class="settings-card fade-in">
                <div class="card-header" style="border-bottom-color: #f1c40f;">
                    <i class="bi bi-calendar-event me-2"></i> Evaluation Duration
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3 small">
                        <i class="bi bi-info-circle me-1"></i> 
                        Select the evaluation duration for performance assessment.
                    </p>
                    <form id="durationForm" method="POST" action="<?php echo BASE_URL; ?>/api/principal">
                        <input type="hidden" name="action" value="update_duration">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <div class="row g-2 g-sm-3">
                            <?php 
                            $durations = ['Monthly' => 'calendar-month', 'Semester' => 'calendar2-week', 'Yearly' => 'calendar-year'];
                            foreach ($durations as $duration => $icon): 
                            ?>
                            <div class="col-4 col-md-4">
                                <div class="duration-option <?php echo $currentDurationValue == $duration ? 'active' : ''; ?>" 
                                     onclick="selectDuration('<?php echo $duration; ?>')">
                                    <i class="bi bi-<?php echo $icon; ?>"></i>
                                    <div><strong><?php echo $duration; ?></strong></div>
                                    <div class="text-muted small d-none d-sm-block">
                                        <?php 
                                        switch($duration) {
                                            case 'Monthly': echo 'Monthly cycle'; break;
                                            case 'Semester': echo 'Semester-wise'; break;
                                            case 'Yearly': echo 'Annual cycle'; break;
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <input type="hidden" name="duration" id="selectedDuration" value="<?php echo $currentDurationValue; ?>">
                        
                        <div class="mt-3 d-flex flex-wrap align-items-center gap-2 gap-sm-3">
                            <button type="submit" class="btn btn-warning btn-lg">
                                <i class="bi bi-save me-2"></i> Save Duration Setting
                            </button>
                            <span class="text-muted small">
                                <i class="bi bi-clock me-1"></i> 
                                Current: <strong><?php echo $currentDurationValue; ?></strong>
                            </span>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Audit Log -->
            <div class="settings-card fade-in">
                <div class="card-header" style="border-bottom-color: #f1c40f;">
                    <i class="bi bi-clock-history me-2"></i> Recent Changes Log
                </div>
                <div class="card-body p-0">
                    <?php if (count($auditLogs) > 0): ?>
                    <div class="audit-log">
                        <?php foreach ($auditLogs as $log): ?>
                        <div class="audit-log-item">
                            <div class="row g-1 align-items-center">
                                <div class="col-6 col-sm-4">
                                    <strong><?php echo htmlspecialchars($log['category_name']); ?></strong>
                                </div>
                                <div class="col-6 col-sm-3">
                                    <span class="badge bg-primary">Max: <?php echo $log['max_marks']; ?></span>
                                    <span class="badge bg-info d-none d-sm-inline"><?php echo $log['duration']; ?></span>
                                </div>
                                <div class="col-6 col-sm-3">
                                    <i class="bi bi-person"></i> 
                                    <span class="d-none d-sm-inline"><?php echo htmlspecialchars($log['changed_by_name']); ?></span>
                                </div>
                                <div class="col-6 col-sm-2 text-end log-time">
                                    <i class="bi bi-clock"></i> 
                                    <span class="d-none d-sm-inline"><?php echo date('d M Y', strtotime($log['updated_at'])); ?></span>
                                    <span class="d-sm-none"><?php echo date('d/m/y', strtotime($log['updated_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="bi bi-info-circle" style="font-size: 2rem; color: #6c757d;"></i>
                        <p class="text-muted">No settings changes recorded yet.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="settings-card fade-in">
                <div class="card-header" style="border-bottom-color: #f1c40f;">
                    <i class="bi bi-lightning me-2"></i> Quick Actions
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-3">
                            <button class="btn btn-outline-primary w-100 btn-sm" onclick="resetAllSettings()">
                                <i class="bi bi-arrow-counterclockwise"></i> <span class="d-none d-sm-inline">Reset All</span>
                            </button>
                        </div>
                        <div class="col-6 col-md-3">
                            <button class="btn btn-outline-success w-100 btn-sm" onclick="exportSettings()">
                                <i class="bi bi-download"></i> <span class="d-none d-sm-inline">Export</span>
                            </button>
                        </div>
                        <div class="col-6 col-md-3">
                            <button class="btn btn-outline-info w-100 btn-sm" onclick="window.location.href = '<?php echo BASE_URL; ?>/principal/reports'">
                                <i class="bi bi-file-earmark-pdf"></i> <span class="d-none d-sm-inline">Reports</span>
                            </button>
                        </div>
                        <div class="col-6 col-md-3">
                            <button class="btn btn-outline-warning w-100 btn-sm" onclick="window.location.href = '<?php echo BASE_URL; ?>/principal/assign-task'">
                                <i class="bi bi-plus-circle"></i> <span class="d-none d-sm-inline">Assign Task</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
        });

        // Duration selection
        function selectDuration(duration) {
            $('.duration-option').removeClass('active');
            $('.duration-option').each(function() {
                if ($(this).find('strong').text() === duration) {
                    $(this).addClass('active');
                }
            });
            $('#selectedDuration').val(duration);
        }

        // Reset category to default
        function resetCategory(categoryId, defaultMarks) {
            Swal.fire({
                title: 'Reset Category?',
                text: 'This will reset the maximum marks to the default value.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#f1c40f',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, reset it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const input = document.querySelector(`input[name="max_marks[${categoryId}]"]`);
                    if (input) {
                        input.value = defaultMarks;
                        showToast(`Category reset to ${defaultMarks} marks`, 'success');
                    }
                }
            });
        }

        // Reset all settings
        function resetAllSettings() {
            Swal.fire({
                title: 'Reset All Settings?',
                text: 'This will reset all categories to their default maximum marks.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, reset all!'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Reset all category inputs
                    <?php foreach ($categories as $category): ?>
                    document.querySelector(`input[name="max_marks[<?php echo $category['category_id']; ?>]"]`).value = 
                        <?php echo $category['default_max_marks']; ?>;
                    <?php endforeach; ?>
                    showToast('All categories reset to default values', 'success');
                }
            });
        }

        // Export settings
        function exportSettings() {
            const data = {
                categories: <?php echo json_encode($categories); ?>,
                duration: '<?php echo $currentDurationValue; ?>',
                exported_at: new Date().toISOString(),
                exported_by: '<?php echo $_SESSION['full_name'] ?? 'Principal'; ?>'
            };
            
            const blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `performance_settings_${new Date().toISOString().split('T')[0]}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            
            showToast('Settings exported successfully', 'success');
        }

        // Toast notification
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type === 'error' ? 'error' : ''}`;
            toast.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill'} text-${type === 'success' ? 'success' : 'danger'}"></i>
                        <span class="ms-2">${message}</span>
                    </div>
                    <button type="button" class="btn-close" onclick="this.parentElement.parentElement.remove()"></button>
                </div>
            `;
            container.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 5000);
        }

        // Form submission with validation
        $('#settingsForm').on('submit', function(e) {
            e.preventDefault();
            
            let isValid = true;
            let errorMessages = [];
            
            $(this).find('input[type="number"]').each(function() {
                const value = parseInt($(this).val());
                if (isNaN(value) || value < 0 || value > 100) {
                    isValid = false;
                    errorMessages.push(`${$(this).closest('.category-item').find('.category-name').text().trim()} must be between 0 and 100`);
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });
            
            if (!isValid) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    html: errorMessages.join('<br>'),
                    confirmButtonColor: '#dc3545'
                });
                return;
            }
            
            Swal.fire({
                title: 'Update Settings?',
                text: 'This will update the maximum marks for all categories.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#f1c40f',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, update settings!'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Updating...',
                        text: 'Please wait while settings are being updated.',
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    this.submit();
                }
            });
        });

        // Duration form submission
        $('#durationForm').on('submit', function(e) {
            e.preventDefault();
            
            Swal.fire({
                title: 'Update Duration?',
                text: `This will set the evaluation duration to ${$('#selectedDuration').val()}.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#f1c40f',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, update duration!'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Updating...',
                        text: 'Please wait while duration is being updated.',
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    this.submit();
                }
            });
        });

        // Check for success/error messages from session
        <?php if (isset($_SESSION['success'])): ?>
        $(document).ready(function() {
            showToast('<?php echo $_SESSION['success']; ?>', 'success');
            <?php unset($_SESSION['success']); ?>
        });
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            
        $(document).ready(function() {
            showToast('<?php echo $_SESSION['error']; ?>', 'error');
            <?php unset($_SESSION['error']); ?>
        });
        <?php endif; ?>
    </script>
</body>
</html>
