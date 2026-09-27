<?php
require_once __DIR__ . '/../../includes/functions.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Check if user is admin or principal
if ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Principal') {
    header('Location: ../../index.php');
    exit();
}

require_once __DIR__ . '/../../includes/header.php';

$db = getDB();

// Get all assigned tasks with user details
$tasksStmt = $db->query("
    SELECT t.*, u.full_name as user_name, u.employee_id, tc.name as category_name,
           u2.full_name as assigned_by_name,
           ts.submission_text, ts.submitted_at, ts.attachment as submission_attachment,
           te.marks_obtained, te.remarks, te.status as evaluation_status
    FROM tasks t
    JOIN users u ON t.user_id = u.id
    JOIN task_categories tc ON t.category_id = tc.id
    JOIN users u2 ON t.assigned_by = u2.id
    LEFT JOIN task_submissions ts ON ts.task_id = t.id
    LEFT JOIN task_evaluations te ON te.submission_id = ts.id
    WHERE t.is_template = 1
    ORDER BY t.created_at DESC
");
$assignedTasks = $tasksStmt->fetchAll();

// Get status counts for filters
$statusCounts = [];
$statusQuery = $db->query("
    SELECT status, COUNT(*) as count 
    FROM tasks 
    WHERE is_template = 1 
    GROUP BY status
");
while ($row = $statusQuery->fetch()) {
    $statusCounts[$row['status']] = $row['count'];
}

// Get category counts
$categoryCounts = [];
$catQuery = $db->query("
    SELECT tc.name, COUNT(*) as count 
    FROM tasks t
    JOIN task_categories tc ON t.category_id = tc.id
    WHERE t.is_template = 1
    GROUP BY tc.name
");
while ($row = $catQuery->fetch()) {
    $categoryCounts[$row['name']] = $row['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Task Status - Faculty Appraisal System</title>
    
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
        
        /* Status Cards */
        .status-card {
            border-radius: 10px;
            padding: 12px 15px;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: default;
            height: 100%;
        }
        
        .status-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
        }
        
        .status-card h5 {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-card h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        
        .status-card .status-icon {
            font-size: 1.5rem;
            opacity: 0.3;
        }
        
        /* Category Cards */
        .category-item {
            padding: 10px;
            border-radius: 8px;
            background: #f8f9fa;
            transition: all 0.3s ease;
            text-align: center;
            margin-bottom: 10px;
        }
        
        .category-item:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }
        
        .category-item h6 {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: #495057;
        }
        
        .category-item .badge {
            font-size: 1.1rem;
            padding: 8px 16px;
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
            
            .status-card h2 {
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
            
            .card-body {
                padding: 25px 30px;
            }
            
            .status-card h2 {
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
            
            .status-card {
                padding: 10px 8px;
            }
            
            .status-card h5 {
                font-size: 0.7rem;
            }
            
            .status-card h2 {
                font-size: 1.5rem;
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
            
            .category-item {
                padding: 8px;
            }
            
            .category-item h6 {
                font-size: 0.75rem;
            }
            
            .category-item .badge {
                font-size: 0.9rem;
                padding: 6px 12px;
            }
            
            .dataTables_wrapper .dataTables_filter input {
                width: 150px;
            }
        }
        
        @media (max-width: 400px) {
            .main-content {
                padding: 10px 5px;
            }
            
            .card-body {
                padding: 12px;
            }
            
            .status-card h2 {
                font-size: 1.3rem;
            }
            
            .status-card h5 {
                font-size: 0.65rem;
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
        
        /* Table container for mobile */
        .table-mobile-card {
            display: block;
        }
        
        /* Small text on mobile */
        .small-mobile {
            font-size: 0.7rem;
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
                <small style="color: rgba(255,255,255,0.7);"><?php echo $_SESSION['role'] === 'Principal' ? 'Principal Panel' : 'Admin Panel'; ?></small>
            </div>
            <ul class="nav flex-column">
                <?php if ($_SESSION['role'] === 'Principal'): ?>
                <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/principal/dashboard"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/principal/assign-task"><i class="bi bi-plus-circle"></i> Assign Task</a></li>
                <li class="nav-item"><a class="nav-link active" href="<?php echo BASE_URL; ?>/principal/task-status"><i class="bi bi-list-check"></i> Task Status</a></li>
                <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/principal/evaluations"><i class="bi bi-clipboard-check"></i> Evaluations</a></li>
                <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/principal/reports"><i class="bi bi-bar-chart"></i> Reports</a></li>
                <?php else: ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/dashboard">
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
                    <a class="nav-link active" href="<?php echo BASE_URL; ?>/admin/task-status">
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
                <?php endif; ?>
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
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <!-- Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-center pt-2 pb-3 mb-3 border-bottom">
                <h1 class="h2 mb-0">Task Status</h1>
                <span class="badge bg-primary mt-2 mt-sm-0">
                    <i class="bi bi-list-check me-1"></i> Overview
                </span>
            </div>

            <!-- Status Summary Cards -->
            <div class="row g-3 mb-4 fade-in">
                <div class="col-6 col-sm-4 col-md-2">
                    <div class="status-card bg-warning text-dark">
                        <div class="status-icon float-end"><i class="bi bi-clock"></i></div>
                        <h5>Pending</h5>
                        <h2><?php echo $statusCounts['Pending'] ?? 0; ?></h2>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-md-2">
                    <div class="status-card bg-info text-white">
                        <div class="status-icon float-end"><i class="bi bi-arrow-repeat"></i></div>
                        <h5>In Progress</h5>
                        <h2><?php echo $statusCounts['In Progress'] ?? 0; ?></h2>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-md-2">
                    <div class="status-card bg-primary text-white">
                        <div class="status-icon float-end"><i class="bi bi-send"></i></div>
                        <h5>Submitted</h5>
                        <h2><?php echo $statusCounts['Submitted'] ?? 0; ?></h2>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-md-2">
                    <div class="status-card bg-success text-white">
                        <div class="status-icon float-end"><i class="bi bi-check-circle"></i></div>
                        <h5>Approved</h5>
                        <h2><?php echo $statusCounts['Approved'] ?? 0; ?></h2>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-md-2">
                    <div class="status-card bg-danger text-white">
                        <div class="status-icon float-end"><i class="bi bi-x-circle"></i></div>
                        <h5>Rejected</h5>
                        <h2><?php echo $statusCounts['Rejected'] ?? 0; ?></h2>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-md-2">
                    <div class="status-card bg-secondary text-white">
                        <div class="status-icon float-end"><i class="bi bi-list-ul"></i></div>
                        <h5>Total</h5>
                        <h2><?php echo array_sum($statusCounts); ?></h2>
                    </div>
                </div>
            </div>

            <!-- Category Summary -->
            <div class="row mb-4 fade-in">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center">
                            <span><i class="bi bi-pie-chart me-2"></i> Category-wise Distribution</span>
                            <span class="badge bg-primary mt-1 mt-sm-0"><?php echo count($categoryCounts); ?> Categories</span>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <?php if (count($categoryCounts) > 0): ?>
                                    <?php foreach ($categoryCounts as $category => $count): ?>
                                    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                                        <div class="category-item">
                                            <h6><?php echo htmlspecialchars($category); ?></h6>
                                            <span class="badge bg-primary"><?php echo $count; ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="col-12">
                                        <p class="text-muted text-center mb-0">No categories found</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tasks Table -->
            <div class="card fade-in">
                <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center">
                    <span><i class="bi bi-table me-2"></i> All Assigned Tasks</span>
                    <span class="badge bg-primary mt-1 mt-sm-0"><?php echo count($assignedTasks); ?> Tasks</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="tasksTable" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>User</th>
                                    <th>Task</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Assigned By</th>
                                    <th>Submission</th>
                                    <th>Evaluation</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($assignedTasks as $task): ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td>
                                        <small><?php echo htmlspecialchars($task['employee_id']); ?></small><br>
                                        <strong><?php echo htmlspecialchars($task['user_name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($task['task_title']); ?></td>
                                    <td><?php echo htmlspecialchars($task['category_name']); ?></td>
                                    <td>
                                        <?php
                                        $statusColors = [
                                            'Pending' => 'warning',
                                            'In Progress' => 'info',
                                            'Submitted' => 'primary',
                                            'Approved' => 'success',
                                            'Rejected' => 'danger'
                                        ];
                                        $color = $statusColors[$task['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>" style="font-size: 12px;">
                                            <?php echo $task['status']; ?>
                                        </span>
                                    </td>
                                    <td><small><?php echo htmlspecialchars($task['assigned_by_name']); ?></small></td>
                                    <td>
                                        <?php if ($task['submitted_at']): ?>
                                            <span class="badge bg-success">Submitted</span>
                                            <small class="d-block small-mobile"><?php echo date('d M Y', strtotime($task['submitted_at'])); ?></small>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not Submitted</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($task['evaluation_status']): ?>
                                            <span class="badge bg-<?php echo $task['evaluation_status'] == 'Approved' ? 'success' : 'danger'; ?>">
                                                <?php echo $task['evaluation_status']; ?>
                                            </span>
                                            <?php if ($task['marks_obtained']): ?>
                                                <small class="d-block small-mobile">Marks: <?php echo $task['marks_obtained']; ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="small-mobile"><?php echo date('d M Y', strtotime($task['created_at'])); ?></small></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-warning" title="Edit Task" onclick='editTask(<?php echo json_encode($task); ?>)'>
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" title="Delete Task" onclick="deleteTask(<?php echo $task['id']; ?>)">
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

    
    <!-- Edit Task Modal -->
    <div class="modal fade" id="editTaskModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i> Edit Task</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editTaskForm" method="POST" action="<?php echo BASE_URL; ?>/api/principal">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_task">
                        <input type="hidden" name="task_id" id="editTaskId">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Task Title</label>
                            <input type="text" class="form-control" name="task_title" id="editTaskTitle" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Category</label>
                            <select name="category_id" id="editTaskCategory" class="form-select" required>
                                <?php 
                                $catStmt = $db->query("SELECT * FROM task_categories");
                                while($cat = $catStmt->fetch()) {
                                    echo "<option value='{$cat['id']}'>" . htmlspecialchars($cat['name']) . "</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Due Date</label>
                            <input type="date" class="form-control" name="due_date" id="editTaskDueDate">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Description</label>
                            <textarea class="form-control" name="task_description" id="editTaskDesc" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Priority</label>
                            <select name="priority" id="editTaskPriority" class="form-select">
                                <option value="Low">Low</option>
                                <option value="Medium">Medium</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning"><i class="bi bi-save me-1"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

    <script>
        
        // Edit Task
        function editTask(task) {
            $('#editTaskId').val(task.id);
            $('#editTaskTitle').val(task.task_title);
            $('#editTaskDesc').val(task.task_description);
            $('#editTaskCategory').val(task.category_id);
            $('#editTaskPriority').val(task.priority);
            
            // Format due date if exists
            if (task.due_date) {
                // Remove time part if it exists in the string (usually YYYY-MM-DD HH:MM:SS)
                const datePart = task.due_date.split(' ')[0];
                $('#editTaskDueDate').val(datePart);
            } else {
                $('#editTaskDueDate').val('');
            }
            
            var editModal = new bootstrap.Modal(document.getElementById('editTaskModal'));
            editModal.show();
        }

        // Delete Task
        function deleteTask(taskId) {
            Swal.fire({
                title: 'Are you absolutely sure?',
                html: '<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i> This will completely remove the task and <strong>permanently delete</strong> all related faculty submissions and evaluations.</div>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="bi bi-trash me-1"></i> Yes, Force Delete',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Deleting...',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });
                    
                    $.ajax({
                        url: '<?php echo BASE_URL; ?>/api/principal',
                        type: 'POST',
                        data: {
                            action: 'delete_task',
                            task_id: taskId,
                            csrf_token: '<?php echo generateCSRFToken(); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Deleted!', 'The task and its submissions have been removed.', 'success')
                                .then(() => {
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire('Error', response.message || 'Failed to delete task', 'error');
                            }
                        },
                        error: function() {
                            Swal.fire('Error', 'A network or server error occurred', 'error');
                        }
                    });
                }
            });
        }

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

            // Initialize DataTable with responsive
            $('#tasksTable').DataTable({
                order: [[8, 'desc']],
                pageLength: 25,
                responsive: {
                    details: {
                        display: $.fn.dataTable.Responsive.display.modal({
                            header: function(row) {
                                var data = row.data();
                                return 'Task Details - ' + data[2];
                            }
                        }),
                        renderer: $.fn.dataTable.Responsive.renderer.tableAll({
                            tableClass: 'table table-bordered mb-0'
                        })
                    }
                },
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "Showing 0 to 0 of 0 entries",
                    infoFiltered: "(filtered from _MAX_ total entries)",
                    zeroRecords: "No matching tasks found"
                },
                columnDefs: [
                    { responsivePriority: 1, targets: [0, 1, 2, 4] },
                    { responsivePriority: 2, targets: [3, 5, 6, 7, 8] },
                    { orderable: false, targets: [0, 1, 2, 3, 4, 5, 6, 7] }
                ]
            });

            // Add animation delay to status cards
            $('.status-card').each(function(index) {
                $(this).css('animation-delay', (index * 0.05) + 's');
            });
            
            // Add animation delay to category items
            $('.category-item').each(function(index) {
                $(this).css('animation-delay', (index * 0.03) + 's');
            });
        });
    </script>
</body>
</html>
