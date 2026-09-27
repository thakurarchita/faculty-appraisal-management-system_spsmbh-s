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

// Check if user is admin
if ($_SESSION['role'] !== 'Admin') {
    header('Location: ../../index.php');
    exit();
}

require_once __DIR__ . '/../../includes/header.php';

$db = getDB();

// Get templates with category info
$stmt = $db->query("
    SELECT t.*, tc.name as category_name 
    FROM task_templates t 
    JOIN task_categories tc ON t.category_id = tc.id 
    ORDER BY t.created_at DESC
");
$templates = $stmt->fetchAll();

$categories = getTaskCategories();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Task Templates - Faculty Appraisal System</title>
    
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
        
        .btn-sm {
            padding: 4px 10px;
            font-size: 0.8rem;
            border-radius: 6px;
        }
        
        .btn-group-sm .btn {
            padding: 4px 8px;
            font-size: 0.75rem;
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
        
        /* Form Controls */
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 10px 14px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
        }
        
        .form-check-input {
            border-radius: 4px;
        }
        
        .form-check-input:checked {
            background-color: #0d6efd;
            border-color: #0d6efd;
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
            
            .btn-sm {
                padding: 3px 6px;
                font-size: 0.7rem;
            }
            
            .btn-group-sm .btn {
                padding: 3px 6px;
                font-size: 0.65rem;
            }
            
            .modal-body {
                padding: 15px;
            }
            
            .form-control, .form-select {
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
                max-width: 500px;
            }
        }
        
        /* Button group wrap on mobile */
        .btn-group-sm {
            flex-wrap: nowrap;
        }
        
        @media (max-width: 400px) {
            .btn-group-sm .btn {
                padding: 2px 5px;
                font-size: 0.6rem;
            }
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
                    <a class="nav-link active" href="<?php echo BASE_URL; ?>/admin/templates">
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
                <h1 class="h2 mb-0">Task Templates</h1>
                <button class="btn btn-primary mt-2 mt-sm-0" data-bs-toggle="modal" data-bs-target="#addTemplateModal">
                    <i class="bi bi-plus-circle me-1"></i> Add Template
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

            <!-- Templates Table -->
            <div class="card fade-in">
                <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center">
                    <span><i class="bi bi-table me-2"></i> All Task Templates</span>
                    <span class="badge bg-primary mt-1 mt-sm-0"><?php echo count($templates); ?> Templates</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="templatesTable" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Task Name</th>
                                    <th>Category</th>
                                    <th>Employee Type</th>
                                    <th>Default</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($templates as $template): ?>
                                <tr>
                                    <td><?php echo $template['id']; ?></td>
                                    <td><?php echo htmlspecialchars($template['task_name']); ?></td>
                                    <td><?php echo htmlspecialchars($template['category_name']); ?></td>
                                    <td><?php echo htmlspecialchars($template['employee_type']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $template['is_default'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $template['is_default'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $template['is_active'] ? 'success' : 'danger'; ?>">
                                            <?php echo $template['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-outline-primary" onclick="editTemplate(<?php echo $template['id']; ?>)" title="Edit Template">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-outline-danger" onclick="deleteTemplate(<?php echo $template['id']; ?>, '<?php echo addslashes($template['task_name']); ?>')" title="Delete Template">
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

    <!-- Add Template Modal -->
    <div class="modal fade" id="addTemplateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Task Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="<?php echo BASE_URL; ?>/api/tasks">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_template">
                        <div class="mb-3">
                            <label class="fw-bold">Task Name <span class="text-danger">*</span></label>
                            <input type="text" name="task_name" class="form-control" placeholder="Enter task name" required>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold">Category <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-control" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold">Employee Type <span class="text-danger">*</span></label>
                            <select name="employee_type" class="form-control" required>
                                <option value="Teaching">Teaching</option>
                                <option value="Non-Teaching">Non-Teaching</option>
                                <option value="Both">Both</option>
                            </select>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_default" class="form-check-input" id="isDefault">
                            <label class="form-check-label fw-normal" for="isDefault">Default Template</label>
                            <small class="d-block text-muted">Default templates are automatically available when assigning tasks</small>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_active" class="form-check-input" id="isActive" checked>
                            <label class="form-check-label fw-normal" for="isActive">Active</label>
                            <small class="d-block text-muted">Inactive templates won't appear in task assignment lists</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> Create Template
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Template Modal -->
    <div class="modal fade" id="editTemplateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Task Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="<?php echo BASE_URL; ?>/api/tasks">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_template">
                        <input type="hidden" name="template_id" id="edit_template_id">
                        <div class="mb-3">
                            <label class="fw-bold">Task Name <span class="text-danger">*</span></label>
                            <input type="text" name="task_name" id="edit_task_name" class="form-control" placeholder="Enter task name" required>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold">Category <span class="text-danger">*</span></label>
                            <select name="category_id" id="edit_category_id" class="form-control" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold">Employee Type <span class="text-danger">*</span></label>
                            <select name="employee_type" id="edit_employee_type" class="form-control" required>
                                <option value="Teaching">Teaching</option>
                                <option value="Non-Teaching">Non-Teaching</option>
                                <option value="Both">Both</option>
                            </select>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_default" id="edit_is_default" class="form-check-input">
                            <label class="form-check-label fw-normal" for="edit_is_default">Default Template</label>
                            <small class="d-block text-muted">Default templates are automatically available when assigning tasks</small>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input">
                            <label class="form-check-label fw-normal" for="edit_is_active">Active</label>
                            <small class="d-block text-muted">Inactive templates won't appear in task assignment lists</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> Update Template
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
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

            // Initialize DataTable with responsive
            $('#templatesTable').DataTable({
                order: [[0, 'desc']],
                responsive: {
                    details: {
                        display: $.fn.dataTable.Responsive.display.modal({
                            header: function(row) {
                                var data = row.data();
                                return 'Template Details - ' + data[1];
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
                    zeroRecords: "No matching templates found"
                },
                columnDefs: [
                    { responsivePriority: 1, targets: [0, 1, 2, 6] },
                    { responsivePriority: 2, targets: [3, 4, 5] }
                ]
            });
        });

        // Edit Template Function
        function editTemplate(templateId) {
            // Show loading state
            Swal.fire({
                title: 'Loading...',
                text: 'Please wait while we load template data.',
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // Fetch template data via AJAX
            $.ajax({
                url: '<?php echo BASE_URL; ?>/api/tasks',
                type: 'POST',
                data: {
                    action: 'get_template',
                    template_id: templateId
                },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        // Populate edit form
                        $('#edit_template_id').val(response.data.id);
                        $('#edit_task_name').val(response.data.task_name);
                        $('#edit_category_id').val(response.data.category_id);
                        $('#edit_employee_type').val(response.data.employee_type);
                        $('#edit_is_default').prop('checked', response.data.is_default == 1);
                        $('#edit_is_active').prop('checked', response.data.is_active == 1);
                        
                        // Show modal
                        $('#editTemplateModal').modal('show');
                    } else {
                        Swal.fire('Error', response.message || 'Failed to load template data', 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('Error', 'Server error occurred while loading template data', 'error');
                }
            });
        }

        // Delete Template Function
        function deleteTemplate(templateId, templateName) {
            Swal.fire({
                title: 'Are you sure?',
                html: `
                    <div class="text-start">
                        <p>You are about to delete <strong>"${templateName}"</strong></p>
                        <p class="text-danger">This action cannot be undone!</p>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: '<i class="bi bi-trash me-1"></i> Yes, delete it!',
                cancelButtonText: '<i class="bi bi-x-circle me-1"></i> Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Deleting...',
                        text: 'Please wait while the template is being deleted.',
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    // Send delete request
                    $.ajax({
                        url: '<?php echo BASE_URL; ?>/api/tasks',
                        type: 'POST',
                        data: {
                            action: 'delete_template',
                            template_id: templateId
                        },
                        dataType: 'json',
                        success: function(response) {
                            Swal.close();
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Deleted!',
                                    text: 'Template has been deleted successfully.',
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                                // Reload page after 2 seconds
                                setTimeout(() => {
                                    location.reload();
                                }, 2000);
                            } else {
                                Swal.fire('Error', response.message || 'Failed to delete template', 'error');
                            }
                        },
                        error: function() {
                            Swal.close();
                            Swal.fire('Error', 'Server error occurred while deleting template', 'error');
                        }
                    });
                }
            });
        }

        // Toggle Status Function (Optional - can be added)
        function toggleStatus(templateId, currentStatus) {
            const newStatus = currentStatus == 1 ? 0 : 1;
            const statusText = newStatus == 1 ? 'activate' : 'deactivate';
            
            Swal.fire({
                title: `Are you sure?`,
                text: `Do you want to ${statusText} this template?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: `Yes, ${statusText} it!`
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '<?php echo BASE_URL; ?>/api/tasks',
                        type: 'POST',
                        data: {
                            action: 'toggle_template_status',
                            template_id: templateId,
                            status: newStatus
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Success!', `Template ${statusText}d successfully.`, 'success');
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                Swal.fire('Error', 'Failed to update status', 'error');
                            }
                        }
                    });
                }
            });
        }
    </script>
</body>
</html>
