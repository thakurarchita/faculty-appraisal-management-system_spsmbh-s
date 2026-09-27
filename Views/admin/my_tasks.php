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
require_once __DIR__ . '/../../models/Task.php';

$db = getDB();
$taskModel = new Task();
$adminId = $_SESSION['user_id'];

// Get task categories for self-task modal
$categories = $db->query("SELECT * FROM task_categories ORDER BY name")->fetchAll();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add_self_task') {
        $taskTitle = sanitizeInput($_POST['task_title']);
        $taskDesc = sanitizeInput($_POST['task_description']);
        $categoryId = $_POST['category_id'];
        $dueDate = $_POST['due_date'];
        
        $data = [
            'user_id' => $adminId,
            'assigned_by' => $adminId,
            'task_title' => $taskTitle,
            'task_description' => $taskDesc,
            'category_id' => $categoryId,
            'due_date' => $dueDate,
            'priority' => 'Medium',
            'attachment' => null
        ];
        
        if ($taskModel->createTask($data)) {
            $lastInsertId = $db->lastInsertId();
            $stmt = $db->prepare("UPDATE tasks SET status = 'In Progress' WHERE id = ?");
            $stmt->execute([$lastInsertId]);
            $_SESSION['success'] = 'Self task added successfully!';
        } else {
            $_SESSION['error'] = 'Failed to add self task.';
        }
        header('Location: ' . BASE_URL . '/admin/my-tasks');
        exit();
    }
}
$adminId = $_SESSION['user_id'];

// Get all tasks assigned to admin
$tasksStmt = $db->prepare("
    SELECT t.*, tc.name as category_name, u.full_name as assigned_by_name,
           ts.submission_text, ts.submitted_at, ts.attachment as submission_attachment,
           te.marks_obtained, te.remarks, te.status as evaluation_status
    FROM tasks t
    JOIN task_categories tc ON t.category_id = tc.id
    JOIN users u ON t.assigned_by = u.id
    LEFT JOIN task_submissions ts ON ts.task_id = t.id
    LEFT JOIN task_evaluations te ON te.submission_id = ts.id
    WHERE t.user_id = ? AND t.is_template = 0
    ORDER BY t.created_at DESC
");
$tasksStmt->execute([$adminId]);
$myTasks = $tasksStmt->fetchAll();

// Get task counts by status
$countStmt = $db->prepare("
    SELECT status, COUNT(*) as count 
    FROM tasks 
    WHERE user_id = ? AND is_template = 0
    GROUP BY status
");
$countStmt->execute([$adminId]);
$statusCounts = [];
while ($row = $countStmt->fetch()) {
    $statusCounts[$row['status']] = $row['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Tasks - Faculty Appraisal System</title>
    
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
        
        .status-card h6 {
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-card h3 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        
        .status-card .status-icon {
            font-size: 1.5rem;
            opacity: 0.3;
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
        
        /* Button Group */
        .btn-group-sm .btn {
            padding: 4px 8px;
            font-size: 0.8rem;
            border-radius: 6px;
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
            
            .status-card h3 {
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
            
            .card-body {
                padding: 25px 30px;
            }
            
            .status-card h3 {
                font-size: 2.2rem;
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
            
            .status-card h6 {
                font-size: 0.7rem;
            }
            
            .status-card h3 {
                font-size: 1.4rem;
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
            
            .btn-group-sm .btn {
                padding: 3px 6px;
                font-size: 0.7rem;
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
            
            .status-card h3 {
                font-size: 1.2rem;
            }
            
            .status-card h6 {
                font-size: 0.6rem;
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
        
        .btn-primary {
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.3);
        }
        
        /* Responsive Grid */
        .row.g-3 {
            --bs-gutter-y: 1rem;
        }
        
        /* Table container for mobile */
        .table-mobile-card {
            display: block;
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
                    <a class="nav-link active" href="<?php echo BASE_URL; ?>/admin/my-tasks">
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
                <div>
                    <h1 class="h2 mb-0" style="color: #1a2332;">My Tasks</h1>
                    <p class="text-muted mb-0" style="font-size: 13px;">View and manage your tasks</p>
                </div>
                <div class="d-flex gap-2 align-items-center mt-2 mt-sm-0">
                    <button class="btn btn-sm" data-bs-toggle="modal" data-bs-target="#addSelfTaskModal" style="background: #1a2332; color: #f1c40f; border-radius: 8px; font-size: 13px; font-weight: 600;">
                        <i class="bi bi-plus-lg me-1"></i> Add Self Task
                    </button>
                    <span class="badge" style="background: #1a2332; padding: 8px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; color: #f1c40f;">Assigned by Principal</span>
                </div>
            </div>

            <!-- Status Summary -->
            <div class="row g-3 mb-4 fade-in">
                <div class="col-6 col-sm-4 col-md-2">
                    <div class="status-card bg-warning text-dark">
                        <div class="status-icon float-end"><i class="bi bi-clock"></i></div>
                        <h6>Pending</h6>
                        <h3><?php echo $statusCounts['Pending'] ?? 0; ?></h3>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-md-2">
                    <div class="status-card bg-info text-white">
                        <div class="status-icon float-end"><i class="bi bi-arrow-repeat"></i></div>
                        <h6>In Progress</h6>
                        <h3><?php echo $statusCounts['In Progress'] ?? 0; ?></h3>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-md-2">
                    <div class="status-card bg-primary text-white">
                        <div class="status-icon float-end"><i class="bi bi-send"></i></div>
                        <h6>Submitted</h6>
                        <h3><?php echo $statusCounts['Submitted'] ?? 0; ?></h3>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-md-2">
                    <div class="status-card bg-success text-white">
                        <div class="status-icon float-end"><i class="bi bi-check-circle"></i></div>
                        <h6>Approved</h6>
                        <h3><?php echo $statusCounts['Approved'] ?? 0; ?></h3>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-md-2">
                    <div class="status-card bg-danger text-white">
                        <div class="status-icon float-end"><i class="bi bi-x-circle"></i></div>
                        <h6>Rejected</h6>
                        <h3><?php echo $statusCounts['Rejected'] ?? 0; ?></h3>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-md-2">
                    <div class="status-card bg-secondary text-white">
                        <div class="status-icon float-end"><i class="bi bi-list-ul"></i></div>
                        <h6>Total</h6>
                        <h3><?php echo array_sum($statusCounts); ?></h3>
                    </div>
                </div>
            </div>

            <!-- Tasks Table -->
            <div class="card fade-in">
                <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center">
                    <span><i class="bi bi-table me-2"></i> All Tasks Assigned to Me</span>
                    <span class="badge bg-primary mt-1 mt-sm-0"><?php echo count($myTasks); ?> Tasks</span>
                </div>
                <div class="card-body">
                    <?php if (count($myTasks) > 0): ?>
                    <div class="table-responsive">
                        <table id="tasksTable" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Task</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Assigned By</th>
                                    <th>Due Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($myTasks as $task): ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($task['task_title']); ?></strong>
                                        <?php if ($task['task_description']): ?>
                                        <br><small class="text-muted d-block"><?php echo substr(htmlspecialchars($task['task_description']), 0, 50); ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $task['category_name']; ?></td>
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
                                        <span class="badge bg-<?php echo $color; ?>">
                                            <?php echo $task['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $task['priority'] == 'High' ? 'danger' : ($task['priority'] == 'Medium' ? 'warning' : 'secondary'); ?>">
                                            <?php echo $task['priority'] ?? 'Medium'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($task['assigned_by_name']); ?></td>
                                    <td><?php echo $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : 'N/A'; ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-outline-primary" onclick="updateStatus(<?php echo $task['id']; ?>, '<?php echo $task['status']; ?>')" title="Update Status">
                                                <i class="bi bi-arrow-repeat"></i>
                                            </button>
                                            <button class="btn btn-outline-info" onclick="viewTask(<?php echo $task['id']; ?>)" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="bi bi-inbox" style="font-size: 3rem; color: #dee2e6;"></i>
                        <p class="text-muted mt-2 mb-0">No tasks assigned to you yet.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>Update Task Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="<?php echo BASE_URL; ?>/api/admin-tasks">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_task_status">
                        <input type="hidden" name="task_id" id="status_task_id">
                        <div class="mb-3">
                            <label class="fw-bold">Current Status</label>
                            <p id="current_status_display" class="fw-bold mt-1"></p>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold">New Status <span class="text-danger">*</span></label>
                            <select name="status" id="new_status" class="form-control" required>
                                <option value="Pending">Pending</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold">Remarks (Optional)</label>
                            <textarea name="remarks" class="form-control" rows="2" placeholder="Add any remarks..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> Update Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Task Modal -->
    <div class="modal fade" id="viewTaskModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Task Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewTaskContent">
                    <!-- Loaded via AJAX -->
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Close
                    </button>
                </div>
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
            $('#tasksTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                responsive: {
                    details: {
                        display: $.fn.dataTable.Responsive.display.modal({
                            header: function(row) {
                                var data = row.data();
                                return 'Task Details';
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
                    { responsivePriority: 1, targets: [0, 1, 3, 7] },
                    { responsivePriority: 2, targets: [2, 4, 5, 6] }
                ]
            });

            // Add animation delay to status cards
            $('.status-card').each(function(index) {
                $(this).css('animation-delay', (index * 0.05) + 's');
            });
        });

        function updateStatus(taskId, currentStatus) {
            $('#status_task_id').val(taskId);
            
            // Set current status display with color
            const statusColors = {
                'Pending': '#ffc107',
                'In Progress': '#0dcaf0',
                'Submitted': '#0d6efd',
                'Approved': '#198754',
                'Rejected': '#dc3545'
            };
            $('#current_status_display').text(currentStatus).css('color', statusColors[currentStatus] || '#6c757d');
            
            // Set selected value in dropdown
            $('#new_status').val(currentStatus);
            
            $('#updateStatusModal').modal('show');
        }

        function viewTask(taskId) {
            // Show loading
            $('#viewTaskContent').html(`
                <div class="text-center py-3">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `);
            $('#viewTaskModal').modal('show');
            
            $.ajax({
                url: '<?php echo BASE_URL; ?>/api/admin-tasks',
                type: 'POST',
                data: {
                    action: 'get_task_details',
                    task_id: taskId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var task = response.data;
                        var html = `
                            <div class="row">
                                <div class="col-12">
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover">
                                            <tbody>
                                                <tr>
                                                    <th style="width:30%; background:#f8f9fa;">Task Title</th>
                                                    <td><strong>${task.task_title}</strong></td>
                                                </tr>
                                                <tr>
                                                    <th style="background:#f8f9fa;">Description</th>
                                                    <td>${task.task_description || 'N/A'}</td>
                                                </tr>
                                                <tr>
                                                    <th style="background:#f8f9fa;">Category</th>
                                                    <td>${task.category_name}</td>
                                                </tr>
                                                <tr>
                                                    <th style="background:#f8f9fa;">Status</th>
                                                    <td><span class="badge bg-${task.status == 'Pending' ? 'warning' : task.status == 'In Progress' ? 'info' : task.status == 'Submitted' ? 'primary' : task.status == 'Approved' ? 'success' : 'danger'}">${task.status}</span></td>
                                                </tr>
                                                <tr>
                                                    <th style="background:#f8f9fa;">Priority</th>
                                                    <td><span class="badge bg-${task.priority == 'High' ? 'danger' : task.priority == 'Medium' ? 'warning' : 'secondary'}">${task.priority || 'Medium'}</span></td>
                                                </tr>
                                                <tr>
                                                    <th style="background:#f8f9fa;">Assigned By</th>
                                                    <td>${task.assigned_by_name}</td>
                                                </tr>
                                                <tr>
                                                    <th style="background:#f8f9fa;">Due Date</th>
                                                    <td>${task.due_date || 'N/A'}</td>
                                                </tr>
                                                <tr>
                                                    <th style="background:#f8f9fa;">Created At</th>
                                                    <td>${task.created_at}</td>
                                                </tr>
                                                ${task.submission_text ? `
                                                <tr>
                                                    <th style="background:#f8f9fa;">Submission</th>
                                                    <td>${task.submission_text}</td>
                                                </tr>
                                                ` : ''}
                                                ${task.submitted_at ? `
                                                <tr>
                                                    <th style="background:#f8f9fa;">Submitted At</th>
                                                    <td>${task.submitted_at}</td>
                                                </tr>
                                                ` : ''}
                                                ${task.evaluation_status ? `
                                                <tr>
                                                    <th style="background:#f8f9fa;">Evaluation Status</th>
                                                    <td><span class="badge bg-${task.evaluation_status == 'Approved' ? 'success' : task.evaluation_status == 'Rejected' ? 'danger' : 'warning'}">${task.evaluation_status}</span></td>
                                                </tr>
                                                ` : ''}
                                                ${task.marks_obtained ? `
                                                <tr>
                                                    <th style="background:#f8f9fa;">Marks Obtained</th>
                                                    <td>${task.marks_obtained}</td>
                                                </tr>
                                                ` : ''}
                                                ${task.remarks ? `
                                                <tr>
                                                    <th style="background:#f8f9fa;">Remarks</th>
                                                    <td>${task.remarks}</td>
                                                </tr>
                                                ` : ''}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        `;
                        $('#viewTaskContent').html(html);
                    } else {
                        $('#viewTaskContent').html(`
                            <div class="alert alert-danger" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                Failed to load task details. Please try again.
                            </div>
                        `);
                        Swal.fire('Error', 'Failed to load task details', 'error');
                    }
                },
                error: function() {
                    $('#viewTaskContent').html(`
                        <div class="alert alert-danger" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            An error occurred while loading task details.
                        </div>
                    `);
                    Swal.fire('Error', 'Failed to load task details', 'error');
                }
            });
        }
    </script>

    <!-- Add Self Task Modal -->
    <div class="modal fade" id="addSelfTaskModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: #1a2332; color: #f1c40f;">
                    <h5 class="modal-title" style="font-size: 16px;">
                        <i class="bi bi-plus-circle me-2"></i> Add Self Task
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="<?php echo BASE_URL; ?>/admin/my-tasks" method="POST">
                    <input type="hidden" name="action" value="add_self_task">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="modal-body p-3">
                        <div class="mb-3">
                            <label class="form-label" style="font-size: 13px;">Task Title <span class="text-danger">*</span></label>
                            <input type="text" name="task_title" class="form-control" required placeholder="What are you working on?" style="font-size: 13px; border-radius: 8px;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size: 13px;">Description <span class="text-danger">*</span></label>
                            <textarea name="task_description" class="form-control" rows="3" required placeholder="Task details..." style="font-size: 13px; border-radius: 8px;"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size: 13px;">Category <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-select" required style="font-size: 13px; border-radius: 8px;">
                                <option value="">Select Category...</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" style="font-size: 13px;">Target Due Date <span class="text-danger">*</span></label>
                            <input type="date" name="due_date" class="form-control" required style="font-size: 13px; border-radius: 8px;" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d', strtotime('+1 week')); ?>">
                        </div>
                    </div>
                    
                    <div class="modal-footer p-2">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                        <button type="submit" class="btn btn-sm" style="background: #1a2332; color: #f1c40f; border-radius: 8px; font-size: 13px; font-weight: 600;">Add Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
