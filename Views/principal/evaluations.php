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

// ============== GET ALL DATA FIRST ==============

// Get all pending submissions
$pendingSubmissions = $taskModel->getPendingSubmissions();

// Get all submissions with their evaluation status
$allSubmissions = $db->query("
    SELECT 
        ts.*,
        t.task_title,
        t.task_description,
        t.due_date,
        t.priority,
        t.category_id,
        u.full_name as employee_name,
        u.employee_id,
        u.email as employee_email,
        u.mobile as employee_mobile,
        tc.name as category_name,
        tc.id as category_id,
        ps.max_marks as category_max_marks,
        te.id as evaluation_id,
        te.marks_obtained,
        te.remarks as evaluation_remarks,
        te.status as evaluation_status,
        te.evaluated_at,
        eval_by.full_name as evaluated_by_name
    FROM task_submissions ts
    JOIN tasks t ON ts.task_id = t.id
    JOIN users u ON t.user_id = u.id
    JOIN task_categories tc ON t.category_id = tc.id
    LEFT JOIN performance_settings ps ON ps.category_id = tc.id
    LEFT JOIN task_evaluations te ON te.submission_id = ts.id
    LEFT JOIN users eval_by ON eval_by.id = te.evaluated_by
    ORDER BY ts.submitted_at DESC
");
$allSubmissions = $allSubmissions->fetchAll();

// Get category max marks for reference
$categoryMaxMarks = [];
$catQuery = $db->query("
    SELECT tc.id, tc.name, ps.max_marks 
    FROM task_categories tc
    LEFT JOIN performance_settings ps ON ps.category_id = tc.id
");
while ($row = $catQuery->fetch()) {
    $categoryMaxMarks[$row['id']] = [
        'name' => $row['name'],
        'max_marks' => $row['max_marks'] ?? 0
    ];
}

// Function to get file icon based on extension
function getFileIcon($filename) {
    if (empty($filename)) return 'bi-file-earmark';
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'jpg' => 'bi-file-image', 'jpeg' => 'bi-file-image', 'png' => 'bi-file-image',
        'gif' => 'bi-file-image', 'webp' => 'bi-file-image', 'svg' => 'bi-file-image',
        'pdf' => 'bi-file-pdf', 'doc' => 'bi-file-word', 'docx' => 'bi-file-word',
        'xls' => 'bi-file-excel', 'xlsx' => 'bi-file-excel', 'ppt' => 'bi-file-slides',
        'pptx' => 'bi-file-slides', 'txt' => 'bi-file-text', 'csv' => 'bi-file-text',
        'zip' => 'bi-file-zip', 'rar' => 'bi-file-zip',
    ];
    return $icons[$ext] ?? 'bi-file-earmark';
}

function getFileType($filename) {
    if (empty($filename)) return '';
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $types = [
        'jpg' => 'Image', 'jpeg' => 'Image', 'png' => 'Image', 'gif' => 'Image',
        'webp' => 'Image', 'svg' => 'Image', 'pdf' => 'PDF', 'doc' => 'Word',
        'docx' => 'Word', 'xls' => 'Excel', 'xlsx' => 'Excel', 'ppt' => 'PowerPoint',
        'pptx' => 'PowerPoint', 'txt' => 'Text', 'csv' => 'CSV', 'zip' => 'Archive',
        'rar' => 'Archive',
    ];
    return $types[$ext] ?? strtoupper($ext);
}

function getFileSize($filename) {
    if (empty($filename)) return 'Unknown';
    $file_path = __DIR__ . '/../../assets/uploads/submissions/' . $filename;
    if (file_exists($file_path)) {
        $size = filesize($file_path);
        if ($size < 1024) return $size . ' B';
        if ($size < 1048576) return round($size / 1024, 1) . ' KB';
        return round($size / 1048576, 1) . ' MB';
    }
    return 'Unknown';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Task Evaluations - Principal Panel</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-dark: #1a2332;
            --primary-gold: #f1c40f;
            --card-shadow: 0 2px 12px rgba(0,0,0,0.08);
            --border-radius: 12px;
        }
        
        * { box-sizing: border-box; }
        
        body {
            background: #f5f6fa;
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
        
        .sidebar .brand {
            padding: 1.5rem 1rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .sidebar .brand i {
            font-size: 1.8rem;
            color: #f1c40f;
        }
        .sidebar .brand h6 {
            color: #ecf0f1;
            margin: 0.5rem 0 0;
            font-weight: 600;
        }
        .sidebar .brand small {
            color: rgba(255,255,255,0.5);
            font-size: 0.7rem;
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
        
        /* Page Header */
        .page-header {
            background: white;
            padding: 1rem 1.2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .page-header h1 {
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0;
            color: #1a2332;
        }
        .page-header h1 small {
            font-weight: 400;
            font-size: 0.8rem;
            color: #6c757d;
            display: block;
        }
        .page-header .badge-pending {
            background: #f1c40f;
            color: #1a2332;
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.8rem;
            white-space: nowrap;
        }
        .page-header .badge-pending i {
            margin-right: 6px;
        }
        
        /* Filter Bar */
        .filter-bar {
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }
        .filter-bar .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            color: #6c757d;
            margin-bottom: 0.2rem;
        }
        .filter-bar .form-control,
        .filter-bar .form-select {
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-size: 0.85rem;
            padding: 0.4rem 0.7rem;
        }
        .filter-bar .form-control:focus,
        .filter-bar .form-select:focus {
            border-color: #f1c40f;
            box-shadow: 0 0 0 0.2rem rgba(241, 196, 15, 0.2);
        }
        
        /* Nav Tabs - Mobile Responsive */
        .nav-tabs-wrapper {
            background: white;
            padding: 0.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .nav-tabs {
            border: none;
            gap: 4px;
            flex-wrap: nowrap;
            min-width: max-content;
        }
        
        .nav-tabs .nav-link {
            border: none;
            padding: 0.5rem 0.8rem;
            border-radius: 8px;
            color: #6c757d;
            font-weight: 500;
            font-size: 0.8rem;
            transition: all 0.3s;
            white-space: nowrap;
        }
        .nav-tabs .nav-link:hover {
            background: #f8f9fa;
            color: #1a2332;
        }
        .nav-tabs .nav-link.active {
            background: #1a2332;
            color: #f1c40f;
        }
        .nav-tabs .nav-link .badge {
            margin-left: 4px;
            font-size: 0.65rem;
            padding: 0.15rem 0.5rem;
        }
        
        /* Submission Card */
        .submission-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 1rem;
            overflow: hidden;
            transition: all 0.3s;
            border-left: 4px solid #f1c40f;
        }
        .submission-card:hover {
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        .submission-card .card-header {
            padding: 0.7rem 1rem;
            background: #f8f9fc;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.3rem;
            border-bottom: 1px solid #e9ecef;
        }
        .submission-card .card-header .task-title {
            font-weight: 600;
            color: #1a2332;
            font-size: 0.9rem;
        }
        .submission-card .card-header .task-title i {
            color: #f1c40f;
            margin-right: 8px;
        }
        .submission-card .card-header .meta-info {
            font-size: 0.75rem;
            color: #6c757d;
        }
        .submission-card .card-header .meta-info i {
            margin-right: 4px;
        }
        .submission-card .card-header .badge-category {
            background: #e9ecef;
            color: #1a2332;
            padding: 0.15rem 0.6rem;
            border-radius: 50px;
            font-size: 0.65rem;
            font-weight: 600;
        }
        .submission-card .card-body {
            padding: 1rem;
        }
        
        /* Detail Grid */
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.5rem 1rem;
            margin-bottom: 0.8rem;
        }
        .detail-grid .detail-item {
            display: flex;
            flex-direction: column;
        }
        .detail-grid .detail-item .label {
            font-size: 0.65rem;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .detail-grid .detail-item .value {
            font-size: 0.85rem;
            color: #1a2332;
            word-break: break-word;
        }
        .detail-grid .detail-item .value.text-muted {
            color: #6c757d;
            font-style: italic;
        }
        
        /* Attachment Box */
        .attachment-box {
            background: #f8f9fc;
            border-radius: 8px;
            padding: 0.7rem;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 0.5rem;
            border: 1px solid #e9ecef;
        }
        .attachment-box .file-info-row {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            flex-wrap: wrap;
        }
        .attachment-box .file-icon {
            font-size: 1.8rem;
            color: #f1c40f;
        }
        .attachment-box .file-info {
            flex: 1;
            min-width: 100px;
        }
        .attachment-box .file-info .file-name {
            font-weight: 600;
            font-size: 0.8rem;
            color: #1a2332;
            word-break: break-all;
        }
        .attachment-box .file-info .file-meta {
            font-size: 0.7rem;
            color: #6c757d;
        }
        .attachment-box .file-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .attachment-box .file-actions .btn {
            padding: 0.15rem 0.5rem;
            font-size: 0.7rem;
            border-radius: 6px;
        }
        
        /* Evaluation Form */
        .evaluation-form {
            background: #f8f9fc;
            border-radius: 8px;
            padding: 0.8rem;
            margin-top: 0.8rem;
            border: 1px solid #e9ecef;
        }
        .evaluation-form .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            color: #1a2332;
            margin-bottom: 0.15rem;
        }
        .evaluation-form .form-control,
        .evaluation-form .form-select {
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-size: 0.85rem;
            padding: 0.35rem 0.6rem;
        }
        .evaluation-form .form-control:focus,
        .evaluation-form .form-select:focus {
            border-color: #f1c40f;
            box-shadow: 0 0 0 0.15rem rgba(241, 196, 15, 0.2);
        }
        .evaluation-form .marks-input {
            font-size: 1rem;
            font-weight: 600;
            width: 70px;
            text-align: center;
        }
        .evaluation-form .max-marks-hint {
            font-size: 0.65rem;
            color: #6c757d;
        }
        
        /* Status Badge */
        .status-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 50px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }
        .status-Pending { background: #fff3cd; color: #856404; }
        .status-Approved { background: #d4edda; color: #155724; }
        .status-Rejected { background: #f8d7da; color: #721c24; }
        .status-Submitted { background: #cce5ff; color: #004085; }
        
        /* Table Responsive */
        .table-responsive {
            border-radius: 8px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table {
            font-size: 0.85rem;
            margin-bottom: 0;
        }
        .table th {
            white-space: nowrap;
            font-weight: 600;
        }
        .table td {
            vertical-align: middle;
            padding: 0.5rem 0.4rem;
        }
        
        /* Alerts */
        .alert {
            border-radius: 10px;
            border: none;
            padding: 0.8rem 1rem;
            margin-bottom: 1rem;
        }
        
        /* Media Queries */
        @media (min-width: 576px) {
            .detail-grid {
                grid-template-columns: 1fr 1fr;
            }
            .attachment-box {
                flex-direction: row;
                align-items: center;
            }
            .attachment-box .file-info-row {
                flex-wrap: nowrap;
            }
        }
        
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
            
            .detail-grid {
                grid-template-columns: 1fr 1fr;
            }
            .page-header h1 {
                font-size: 1.4rem;
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
            
            .detail-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 12px 8px;
            }
            
            .page-header {
                padding: 0.8rem 1rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            .page-header h1 {
                font-size: 1rem;
            }
            .page-header .badge-pending {
                font-size: 0.7rem;
                padding: 0.3rem 0.6rem;
            }
            
            .filter-bar {
                padding: 0.8rem;
            }
            .filter-bar .row > div {
                margin-bottom: 0.5rem;
            }
            
            .nav-tabs-wrapper {
                padding: 0.3rem;
            }
            .nav-tabs .nav-link {
                font-size: 0.7rem;
                padding: 0.3rem 0.6rem;
            }
            
            .submission-card .card-header {
                padding: 0.5rem 0.7rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.2rem;
            }
            .submission-card .card-header .task-title {
                font-size: 0.8rem;
            }
            .submission-card .card-body {
                padding: 0.7rem;
            }
            
            .evaluation-form .row > div {
                margin-bottom: 0.5rem;
            }
            
            .table {
                font-size: 0.75rem;
            }
            .table td, .table th {
                padding: 0.3rem 0.2rem;
            }
            
            .navbar-toggle {
                padding: 8px 12px;
                font-size: 1.2rem;
                top: 8px;
                left: 8px;
            }
        }
        
        @media (max-width: 400px) {
            .main-content {
                padding: 8px 4px;
            }
            
            .page-header h1 {
                font-size: 0.9rem;
            }
            .page-header h1 small {
                font-size: 0.7rem;
            }
            
            .nav-tabs .nav-link {
                font-size: 0.65rem;
                padding: 0.25rem 0.5rem;
            }
            
            .table {
                font-size: 0.7rem;
            }
            .table td, .table th {
                padding: 0.2rem 0.15rem;
            }
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
        
        /* Modal Responsive */
        .modal-dialog {
            margin: 10px;
        }
        @media (min-width: 576px) {
            .modal-dialog {
                margin: 1.75rem auto;
            }
        }
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }
        .modal-header {
            background: #1a2332;
            color: white;
            padding: 0.8rem 1.2rem;
            border-radius: 12px 12px 0 0;
        }
        .modal-body {
            padding: 1.2rem;
            max-height: 70vh;
            overflow-y: auto;
        }
        .modal-footer {
            padding: 0.8rem 1.2rem;
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
        <div class="brand">
            <i class="bi bi-mortarboard-fill"></i>
            <h6>Appraisal System</h6>
            <small>Principal Panel</small>
        </div>
        <ul class="nav flex-column mt-3">
            <li class="nav-item">
                <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/dashboard">
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
                <a class="nav-link active" href="<?php echo BASE_URL; ?>/principal/evaluations">
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
    </nav>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="container-fluid px-0">
            <!-- Page Header -->
            <div class="page-header fade-in">
                <div>
                    <h1>
                        Task Evaluations
                        <small>Review and evaluate faculty task submissions</small>
                    </h1>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge-pending">
                        <i class="bi bi-clock-history"></i> 
                        Pending: <?php echo count($pendingSubmissions); ?>
                    </span>
                    <button class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> <span class="d-none d-sm-inline">Refresh</span>
                    </button>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-circle-fill me-2"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

          

            <!-- KPI Summary Dashboard -->
            <div class="row g-3 mb-2 fade-in">
                <div class="col-6 col-md-3">
                    <div class="card h-100 border-0" style="background: linear-gradient(135deg, #fff3cd 0%, #fff8e1 100%);">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-1" style="font-size: 0.8rem; font-weight:600; text-transform:uppercase;">Pending</h6>
                                    <h3 class="mb-0" style="color: #856404; font-weight:700;"><?php echo count($pendingSubmissions); ?></h3>
                                </div>
                                <div class="bg-warning bg-opacity-25 p-2 rounded-circle">
                                    <i class="bi bi-hourglass-split fs-4 text-warning"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100 border-0" style="background: linear-gradient(135deg, #d1e7dd 0%, #e8f5e9 100%);">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-1" style="font-size: 0.8rem; font-weight:600; text-transform:uppercase;">Approved</h6>
                                    <?php 
                                    $approvedCount = count(array_filter($allSubmissions, function($s) { return ($s['evaluation_status'] ?? '') == 'Approved'; }));
                                    ?>
                                    <h3 class="mb-0" style="color: #0f5132; font-weight:700;"><?php echo $approvedCount; ?></h3>
                                </div>
                                <div class="bg-success bg-opacity-25 p-2 rounded-circle">
                                    <i class="bi bi-check-circle fs-4 text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100 border-0" style="background: linear-gradient(135deg, #f8d7da 0%, #ffebee 100%);">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-1" style="font-size: 0.8rem; font-weight:600; text-transform:uppercase;">Rejected</h6>
                                    <?php 
                                    $rejectedCount = count(array_filter($allSubmissions, function($s) { return ($s['evaluation_status'] ?? '') == 'Rejected'; }));
                                    ?>
                                    <h3 class="mb-0" style="color: #842029; font-weight:700;"><?php echo $rejectedCount; ?></h3>
                                </div>
                                <div class="bg-danger bg-opacity-25 p-2 rounded-circle">
                                    <i class="bi bi-x-circle fs-4 text-danger"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100 border-0" style="background: linear-gradient(135deg, #e2e3e5 0%, #f8f9fa 100%);">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted mb-1" style="font-size: 0.8rem; font-weight:600; text-transform:uppercase;">Total</h6>
                                    <h3 class="mb-0" style="color: #41464b; font-weight:700;"><?php echo count($allSubmissions); ?></h3>
                                </div>
                                <div class="bg-secondary bg-opacity-25 p-2 rounded-circle">
                                    <i class="bi bi-collection fs-4 text-secondary"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar fade-in">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label">Filter by Status</label>
                        <select id="statusFilter" class="form-select">
                            <option value="all">All Submissions</option>
                            <option value="Pending">Pending Review</option>
                            <option value="Approved">Approved</option>
                            <option value="Rejected">Rejected</option>
                            <option value="Submitted">Submitted</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Filter by Category</label>
                        <select id="categoryFilter" class="form-select">
                            <option value="all">All Categories</option>
                            <?php foreach ($categoryMaxMarks as $catId => $cat): ?>
                            <option value="<?php echo $catId; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Search Employee</label>
                        <input type="text" id="employeeSearch" class="form-control" placeholder="Search by name or ID...">
                    </div>
                    <div class="col-6 col-md-3">
                        <button class="btn btn-warning w-100" onclick="resetFilters()">
                            <i class="bi bi-arrow-counterclockwise"></i> <span class="d-none d-sm-inline">Reset Filters</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tabs - Premium Pills -->
            <div class="nav-tabs-wrapper fade-in mb-2">
                <ul class="nav nav-pills custom-pills justify-content-start flex-nowrap overflow-auto pb-2" id="evaluationTabs" role="tablist" style="scrollbar-width: none;">
                    <li class="nav-item me-2" role="presentation">
                        <button class="nav-link active rounded-pill px-3 py-1 d-flex align-items-center fw-semibold shadow-sm transition-all" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab" style="gap: 5px; font-size: 0.9rem;">
                            <i class="bi bi-clock-history "></i> 
                            <span>Pending</span>
                            <span class="badge bg-white text-warning rounded-pill shadow-sm ms-1"><?php echo count($pendingSubmissions); ?></span>
                        </button>
                    </li>
                    <li class="nav-item me-2" role="presentation">
                        <button class="nav-link rounded-pill px-3 py-1 d-flex align-items-center fw-semibold shadow-sm transition-all text-secondary bg-white border border-light" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab" style="gap: 5px; font-size: 0.9rem;">
                            <i class="bi bi-list-ul "></i> All
                        </button>
                    </li>
                    <li class="nav-item me-2" role="presentation">
                        <button class="nav-link rounded-pill px-3 py-1 d-flex align-items-center fw-semibold shadow-sm transition-all text-success bg-white border border-light" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved" type="button" role="tab" style="gap: 5px; font-size: 0.9rem;">
                            <i class="bi bi-check-circle-fill "></i> Approved
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill px-3 py-1 d-flex align-items-center fw-semibold shadow-sm transition-all text-danger bg-white border border-light" id="rejected-tab" data-bs-toggle="tab" data-bs-target="#rejected" type="button" role="tab" style="gap: 5px; font-size: 0.9rem;">
                            <i class="bi bi-x-circle-fill "></i> Rejected
                        </button>
                    </li>
                </ul>
            </div>
            
            <style>
                .custom-pills .nav-link {
                    border: 1px solid transparent;
                }
                .custom-pills .nav-link:hover:not(.active) {
                    background-color: #f8f9fa !important;
                    border-color: #dee2e6 !important;
                    transform: translateY(-1px);
                }
                .custom-pills .nav-link.active {
                    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                    color: white !important;
                    border: none;
                }
                .custom-pills::-webkit-scrollbar {
                    display: none;
                }
                .transition-all {
                    transition: all 0.2s ease;
                }
            </style>

            <div class="tab-content" id="evaluationTabsContent">
                <!-- Pending Tab - CORRECTED VERSION -->
                <div class="tab-pane fade show active" id="pending" role="tabpanel">
                    <?php if (count($pendingSubmissions) > 0): ?>
                        <?php foreach ($pendingSubmissions as $submission): 
                            $catId = isset($submission['category_id']) ? $submission['category_id'] : 0;
                            $catMaxMarks = isset($categoryMaxMarks[$catId]) ? 
                                           $categoryMaxMarks[$catId]['max_marks'] : 0;
                            $catName = isset($categoryMaxMarks[$catId]) ? 
                                       $categoryMaxMarks[$catId]['name'] : ($submission['category_name'] ?? 'Unknown');
                            $fileExt = pathinfo($submission['attachment'] ?? '', PATHINFO_EXTENSION);
                            $isImage = in_array(strtolower($fileExt), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                        ?>
                        <div class="submission-card fade-in mb-2" data-submission-id="<?php echo $submission['id']; ?>" style="border-left: 5px solid #f1c40f; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-radius: 12px; overflow: hidden;">
                            
                            <!-- Premium Header -->
                            <div class="card-header bg-white p-3 border-bottom d-flex flex-wrap justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                        <i class="bi bi-person-circle fs-4"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-1" style="font-weight: 700; color: #1a2332;">
                                            <?php echo htmlspecialchars($submission['employee_name'] ?? 'Unknown'); ?>
                                            <span class="badge bg-light text-dark border ms-2" style="font-weight: 500; font-size: 0.75rem;"><?php echo htmlspecialchars($submission['employee_id'] ?? 'N/A'); ?></span>
                                        </h5>
                                        <div class="text-muted" style="font-size: 0.85rem;">
                                            <i class="bi bi-folder2-open me-1"></i> <?php echo htmlspecialchars($submission['task_title'] ?? 'Untitled Task'); ?> 
                                            &bull; <i class="bi bi-tag ms-1 me-1"></i> <?php echo htmlspecialchars($catName); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-end mt-2 mt-sm-0">
                                    <span class="badge bg-warning text-dark px-3 py-2 rounded-pill shadow-sm" style="font-weight: 600;">
                                        <i class="bi bi-hourglass-split me-1"></i> Pending Review
                                    </span>
                                </div>
                            </div>

                            <div class="card-body p-0">
                                <div class="row g-0">
                                    <!-- Left Side: Task & Submission Details -->
                                    <div class="col-12 col-lg-7 p-3 border-end">
                                        <h6 class="text-uppercase text-muted fw-bold mb-2" style="font-size: 0.75rem; letter-spacing: 1px;">Submission Details</h6>
                                        
                                        <div class="mb-2">
                                            <p class="mb-1 fw-bold text-dark">Description</p>
                                            <p class="text-muted small bg-light p-3 rounded border mb-0" style="max-height: 150px; overflow-y: auto;">
                                                <?php echo nl2br(htmlspecialchars($submission['task_description'] ?? 'No description provided.')); ?>
                                            </p>
                                        </div>

                                        <div class="row mb-2">
                                            <div class="col-6">
                                                <p class="mb-0 text-muted small fw-bold">Submitted On</p>
                                                <p class="mb-0 fw-medium text-dark"><i class="bi bi-calendar-check text-primary me-1"></i> <?php echo htmlspecialchars(formatDatabaseDate($submission['submitted_at'])); ?></p>
                                            </div>
                                            <?php if (!empty($submission['due_date'])): ?>
                                            <div class="col-6">
                                                <p class="mb-0 text-muted small fw-bold">Due Date</p>
                                                <p class="mb-0 fw-medium text-dark"><i class="bi bi-calendar-event text-danger me-1"></i> <?php echo date('d M Y', strtotime($submission['due_date'])); ?></p>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($submission['submission_text'])): ?>
                                        <div class="mb-2">
                                            <p class="mb-1 fw-bold text-dark">Employee Remarks</p>
                                            <div class="bg-light p-3 rounded border text-dark small" style="border-left: 3px solid #3b82f6 !important;">
                                                <?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Attachment Area -->
                                        <div>
                                            <p class="mb-2 fw-bold text-dark">Attachment</p>
                                            <?php if (!empty($submission['attachment'])): ?>
                                            <div class="attachment-box d-flex align-items-center p-3 border rounded" style="background: #f8f9fc;">
                                                <div class="file-icon me-3">
                                                    <i class="bi <?php echo getFileIcon($submission['attachment']); ?> text-primary" style="font-size: 2rem;"></i>
                                                </div>
                                                <div class="file-info flex-grow-1">
                                                    <div class="fw-bold text-dark text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($submission['attachment']); ?>">
                                                        <?php echo htmlspecialchars($submission['attachment']); ?>
                                                    </div>
                                                    <div class="text-muted small">
                                                        <?php echo getFileType($submission['attachment']); ?> &bull; <?php echo getFileSize($submission['attachment']); ?>
                                                    </div>
                                                </div>
                                                <div class="d-flex gap-2">
                                                    <a href="<?php echo url('assets/download.php'); ?>?file=<?php echo rawurlencode($submission['attachment']); ?>&action=view" target="_blank" class="btn btn-sm btn-outline-primary shadow-sm rounded-pill px-3">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                    <a href="<?php echo url('assets/download.php'); ?>?file=<?php echo rawurlencode($submission['attachment']); ?>&action=download" class="btn btn-sm btn-primary shadow-sm rounded-pill px-3">
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-center py-4 bg-light rounded border border-dashed">
                                                <i class="bi bi-file-earmark-x text-muted fs-4"></i>
                                                <p class="text-muted small mb-0 mt-2">No attachment provided</p>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                    </div>

                                    <!-- Right Side: Evaluation Form -->
                                    <div class="col-12 col-lg-5 p-3 bg-light" style="background: linear-gradient(180deg, #f8f9fa 0%, #e9ecef 100%);">
                                        <h6 class="text-uppercase text-muted fw-bold mb-2" style="font-size: 0.75rem; letter-spacing: 1px;"><i class="bi bi-clipboard-check text-warning me-1"></i> Evaluation Actions</h6>
                                        
                                        <div class="bg-white p-3 rounded shadow-sm border">
                                            <div class="d-flex justify-content-between align-items-center mb-2 pb-3 border-bottom">
                                                <div>
                                                    <p class="text-muted small mb-0 fw-bold">Max Marks</p>
                                                    <h3 class="mb-0 text-dark" style="font-weight: 800;"><?php echo $catMaxMarks > 0 ? $catMaxMarks : '--'; ?></h3>
                                                </div>
                                                <div class="text-end">
                                                    <p class="text-muted small mb-0 fw-bold">Category</p>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($catName); ?></span>
                                                </div>
                                            </div>

                                            <form method="POST" action="<?php echo BASE_URL; ?>/api/principal" onsubmit="return validateEvaluation(this)">
                                                <input type="hidden" name="action" value="evaluate_task">
                                                <input type="hidden" name="submission_id" value="<?php echo $submission['id']; ?>">
                                                <input type="hidden" name="task_id" value="<?php echo $submission['task_id']; ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $submission['user_id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                                                <div class="mb-2">
                                                    <label class="form-label fw-bold text-dark">Assign Marks</label>
                                                    <div class="input-group input-group-lg shadow-sm">
                                                        <span class="input-group-text bg-light"><i class="bi bi-star-fill text-warning"></i></span>
                                                        <input type="number" name="marks_obtained" class="form-control fw-bold text-center" style="font-size: 1.5rem;" min="0" max="<?php echo $catMaxMarks > 0 ? $catMaxMarks : 100; ?>" placeholder="0" required>
                                                    </div>
                                                </div>

                                                <div class="mb-2">
                                                    <label class="form-label fw-bold text-dark">Status</label>
                                                    <select name="status" id="eval_status_<?php echo $submission['id']; ?>" class="form-select form-select-lg shadow-sm" style="cursor:pointer;" required onchange="toggleDueDate(<?php echo $submission['id']; ?>)">
                                                        <option value="">Select decision...</option>
                                                        <option value="Approved">✅ Approve Submission</option>
                                                        <option value="Rejected">❌ Reject & Request Revision</option>
                                                    </select>
                                                </div>

                                                <div class="mb-2" id="due_date_container_<?php echo $submission['id']; ?>" style="display: none;">
                                                    <label class="form-label fw-bold text-danger"><i class="bi bi-calendar-x me-1"></i> New Due Date</label>
                                                    <input type="date" name="new_due_date" class="form-control shadow-sm" id="new_due_date_<?php echo $submission['id']; ?>">
                                                    <div class="form-text text-muted small">Required when rejecting to set a new deadline.</div>
                                                </div>

                                                <div class="mb-2">
                                                    <label class="form-label fw-bold text-dark">Feedback / Remarks</label>
                                                    <textarea name="remarks" class="form-control shadow-sm" rows="3" placeholder="Provide constructive feedback to the faculty member..." required></textarea>
                                                </div>

                                                <button type="submit" class="btn btn-warning w-100 btn-lg fw-bold shadow-sm rounded-pill">
                                                    <i class="bi bi-check2-circle me-1"></i> Submit Final Evaluation
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 bg-white rounded shadow-sm border" style="margin-top: 20px;">
                            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80' fill='%2328a745' class='bi bi-check-circle-fill' viewBox='0 0 16 16'%3E%3Cpath d='M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z'/%3E%3C/svg%3E" alt="All Done" class="mb-2" style="opacity: 0.8;">
                            <h4 class="fw-bold" style="color: #1a2332;">You're all caught up!</h4>
                            <p class="text-muted">There are no pending submissions waiting for your review.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- All Submissions Tab -->
                <div class="tab-pane fade" id="all" role="tabpanel">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-list-ul text-primary me-2"></i>All Submissions</h5>
                            <span class="badge bg-primary rounded-pill"><?php echo count($allSubmissions); ?> Total</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="allSubmissionsTable">
                                    <thead class="bg-light text-secondary" style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                        <tr>
                                            <th class="ps-3 border-0 py-2">Employee</th>
                                            <th class="border-0 py-2">Task Details</th>
                                            <th class="border-0 py-2">Submitted On</th>
                                            <th class="border-0 py-2">Status</th>
                                            <th class="border-0 py-2">Score</th>
                                            <th class="pe-3 border-0 py-2 text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="border-top-0">
                                        <?php foreach ($allSubmissions as $sub): ?>
                                        <tr class="border-bottom" style="transition: all 0.2s;" data-status="<?php echo $sub['evaluation_status'] ?? 'Pending'; ?>" data-category="<?php echo $sub['category_id']; ?>" data-submission-id="<?php echo $sub['id']; ?>">
                                            <td class="ps-3 py-2">
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-circle bg-primary bg-opacity-10 text-primary fw-bold me-3 rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.85rem;">
                                                        <?php echo strtoupper(substr($sub['employee_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($sub['employee_name']); ?></div>
                                                        <div class="small text-muted font-monospace"><?php echo htmlspecialchars($sub['employee_id']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-2">
                                                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($sub['task_title']); ?></div>
                                                <div class="small text-muted"><i class="bi bi-folder2 me-1"></i><?php echo htmlspecialchars($sub['category_name']); ?></div>
                                            </td>
                                            <td class="py-2">
                                                <div class="text-dark"><i class="bi bi-calendar3 me-1 text-muted"></i> <?php echo htmlspecialchars(formatDatabaseDate($sub['submitted_at'], 'd M Y')); ?></div>
                                            </td>
                                            <td class="py-2">
                                                <?php 
                                                $status = $sub['evaluation_status'] ?? 'Pending';
                                                $badgeClass = 'bg-warning text-dark';
                                                $icon = 'bi-clock-history';
                                                if($status == 'Approved') { $badgeClass = 'bg-success'; $icon = 'bi-check-circle-fill'; }
                                                if($status == 'Rejected') { $badgeClass = 'bg-danger'; $icon = 'bi-x-circle-fill'; }
                                                ?>
                                                <span class="badge <?php echo $badgeClass; ?> px-2 py-1 rounded-pill"><i class="bi <?php echo $icon; ?> me-1"></i><?php echo $status; ?></span>
                                            </td>
                                            <td class="py-2">
                                                <?php if ($sub['marks_obtained'] !== null): ?>
                                                <div class="d-flex align-items-baseline">
                                                    <span class="fw-bold  <?php echo $status == 'Approved' ? 'text-success' : 'text-dark'; ?>"><?php echo $sub['marks_obtained']; ?></span>
                                                    <span class="text-muted ms-1 small">/ <?php echo $sub['category_max_marks'] ?? 0; ?></span>
                                                </div>
                                                <?php else: ?>
                                                <span class="badge bg-light text-secondary rounded-pill border">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="pe-3 py-2 text-end">
                                                <div class="btn-group shadow-sm rounded-pill overflow-hidden">
                                                    <button class="btn btn-light btn-sm border" onclick="viewSubmission(<?php echo $sub['id']; ?>)" title="View Details">
                                                        <i class="bi bi-eye text-primary"></i>
                                                    </button>
                                                    <?php if (($sub['evaluation_status'] == 'Pending' || !$sub['evaluation_id']) && $sub['status'] == 'Submitted'): ?>
                                                    <button class="btn btn-warning btn-sm border-warning text-dark" onclick="evaluateNow(<?php echo $sub['id']; ?>)" title="Evaluate Now">
                                                        <i class="bi bi-pencil-square"></i> Grade
                                                    </button>
                                                    <?php endif; ?>
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

                <!-- Approved Tab -->
                <div class="tab-pane fade" id="approved" role="tabpanel">
                    <?php 
                    $approvedSubs = array_filter($allSubmissions, function($s) {
                        return ($s['evaluation_status'] ?? '') == 'Approved';
                    });
                    if (count($approvedSubs) > 0): ?>
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden border-top border-success border-2">
                        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-check-circle-fill text-success me-2"></i>Approved Submissions</h5>
                            <span class="badge bg-success rounded-pill"><?php echo count($approvedSubs); ?> Approved</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light text-secondary" style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                        <tr>
                                            <th class="ps-3 border-0 py-2">Employee</th>
                                            <th class="border-0 py-2">Task & Category</th>
                                            <th class="border-0 py-2">Score</th>
                                            <th class="border-0 py-2">Evaluated On</th>
                                            <th class="pe-3 border-0 py-2">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody class="border-top-0">
                                        <?php foreach ($approvedSubs as $sub): ?>
                                        <tr class="border-bottom" style="transition: all 0.2s;">
                                            <td class="ps-3 py-2">
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-circle bg-success bg-opacity-10 text-success fw-bold me-3 rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.85rem;">
                                                        <?php echo strtoupper(substr($sub['employee_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($sub['employee_name']); ?></div>
                                                        <div class="small text-muted font-monospace"><?php echo htmlspecialchars($sub['employee_id']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-2">
                                                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($sub['task_title']); ?></div>
                                                <span class="badge bg-light text-secondary border border-secondary border-opacity-25 rounded-pill fw-normal mt-1"><i class="bi bi-folder2 me-1"></i><?php echo htmlspecialchars($sub['category_name']); ?></span>
                                            </td>
                                            <td class="py-2">
                                                <div class="d-flex align-items-baseline bg-success bg-opacity-10 d-inline-block px-3 py-1 rounded-pill">
                                                    <span class="fw-bold  text-success"><?php echo $sub['marks_obtained']; ?></span>
                                                    <span class="text-success text-opacity-75 ms-1 small">/ <?php echo $sub['category_max_marks'] ?? 0; ?></span>
                                                </div>
                                            </td>
                                            <td class="py-2 text-muted">
                                                <i class="bi bi-calendar-check me-1"></i> <?php echo htmlspecialchars(formatDatabaseDate($sub['evaluated_at'] ?? $sub['submitted_at'], 'd M Y')); ?>
                                            </td>
                                            <td class="pe-3 py-2">
                                                <div class="text-truncate" style="max-width: 250px; font-size: 0.9rem;" title="<?php echo htmlspecialchars($sub['evaluation_remarks'] ?? ''); ?>">
                                                    <?php echo htmlspecialchars($sub['evaluation_remarks'] ?? 'No remarks provided'); ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4 bg-white rounded-4 shadow-sm border border-light">
                        <div class="bg-success bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px;">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 1.8rem;"></i>
                        </div>
                        <h4 class="fw-bold text-dark">No Approved Submissions</h4>
                        <p class="text-muted">You haven't approved any submissions yet.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Rejected Tab -->
                <div class="tab-pane fade" id="rejected" role="tabpanel">
                    <?php 
                    $rejectedSubs = array_filter($allSubmissions, function($s) {
                        return ($s['evaluation_status'] ?? '') == 'Rejected';
                    });
                    if (count($rejectedSubs) > 0): ?>
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden border-top border-danger border-2">
                        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-x-circle-fill text-danger me-2"></i>Rejected Submissions</h5>
                            <span class="badge bg-danger rounded-pill"><?php echo count($rejectedSubs); ?> Rejected</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light text-secondary" style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                        <tr>
                                            <th class="ps-3 border-0 py-2">Employee</th>
                                            <th class="border-0 py-2">Task & Category</th>
                                            <th class="border-0 py-2">Rejected On</th>
                                            <th class="pe-3 border-0 py-2">Feedback Provided</th>
                                        </tr>
                                    </thead>
                                    <tbody class="border-top-0">
                                        <?php foreach ($rejectedSubs as $sub): ?>
                                        <tr class="border-bottom" style="transition: all 0.2s;">
                                            <td class="ps-3 py-2">
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-circle bg-danger bg-opacity-10 text-danger fw-bold me-3 rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.85rem;">
                                                        <?php echo strtoupper(substr($sub['employee_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($sub['employee_name']); ?></div>
                                                        <div class="small text-muted font-monospace"><?php echo htmlspecialchars($sub['employee_id']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-2">
                                                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($sub['task_title']); ?></div>
                                                <span class="badge bg-light text-secondary border border-secondary border-opacity-25 rounded-pill fw-normal mt-1"><i class="bi bi-folder2 me-1"></i><?php echo htmlspecialchars($sub['category_name']); ?></span>
                                            </td>
                                            <td class="py-2 text-muted">
                                                <i class="bi bi-calendar-x me-1 text-danger"></i> <?php echo htmlspecialchars(formatDatabaseDate($sub['evaluated_at'] ?? $sub['submitted_at'], 'd M Y')); ?>
                                            </td>
                                            <td class="pe-3 py-2">
                                                <div class="bg-danger bg-opacity-10 border border-danger border-opacity-25 rounded p-2 text-danger small">
                                                    <i class="bi bi-chat-left-dots-fill me-1"></i> 
                                                    <?php echo htmlspecialchars($sub['evaluation_remarks'] ?? 'No feedback provided'); ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4 bg-white rounded-4 shadow-sm border border-light">
                        <div class="bg-danger bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px;">
                            <i class="bi bi-shield-check text-danger" style="font-size: 1.8rem;"></i>
                        </div>
                        <h4 class="fw-bold text-dark">No Rejected Submissions</h4>
                        <p class="text-muted">You haven't rejected any submissions yet.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- View Submission Modal -->
    <div class="modal fade" id="viewSubmissionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-file-text me-2"></i> Submission Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="submissionDetails">
                    <div class="text-center py-4">
                        <div class="spinner-border text-warning" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading submission details...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        const downloadBaseUrl = <?php echo json_encode(url('assets/download.php')); ?>;
        function toggleDueDate(submissionId) {
            const statusSelect = document.getElementById('eval_status_' + submissionId);
            const dueDateContainer = document.getElementById('due_date_container_' + submissionId);
            
            if (statusSelect && dueDateContainer) {
                if (statusSelect.value === 'Rejected') {
                    dueDateContainer.style.display = 'block';
                } else {
                    dueDateContainer.style.display = 'none';
                    // Clear the date when not needed
                    const dueDateInput = document.getElementById('new_due_date_' + submissionId);
                    if (dueDateInput) {
                        dueDateInput.value = '';
                    }
                }
            }
        }

        // Update validateEvaluation function
        function validateEvaluation(form) {
            const marks = form.querySelector('input[name="marks_obtained"]');
            const status = form.querySelector('select[name="status"]');
            const remarks = form.querySelector('textarea[name="remarks"]');
            const newDueDate = form.querySelector('input[name="new_due_date"]');
            
            if (!marks.value || marks.value < 0) {
                Swal.fire('Error', 'Please enter valid marks', 'error');
                marks.focus();
                return false;
            }
            
            if (!status.value) {
                Swal.fire('Error', 'Please select a status', 'error');
                status.focus();
                return false;
            }
            
            if (!remarks.value.trim()) {
                Swal.fire('Error', 'Please provide feedback/remarks', 'error');
                remarks.focus();
                return false;
            }
            
            // Build confirmation message
            let dueDateMessage = '';
            if (status.value === 'Rejected' && newDueDate && newDueDate.value) {
                const dateObj = new Date(newDueDate.value);
                const formattedDate = dateObj.toLocaleDateString('en-GB', { 
                    day: '2-digit', 
                    month: 'short', 
                    year: 'numeric' 
                });
                dueDateMessage = `<p><strong>New Due Date:</strong> ${formattedDate}</p>`;
            }
            
            Swal.fire({
                title: 'Confirm Evaluation',
                html: `
                    <div class="text-start" style="font-size: 14px;">
                        <p><strong>Status:</strong> ${status.value}</p>
                        <p><strong>Marks:</strong> ${marks.value}</p>
                        <p><strong>Remarks:</strong> ${remarks.value}</p>
                        ${dueDateMessage}
                        ${status.value === 'Rejected' ? '<p class="text-danger mt-2"><i class="bi bi-exclamation-triangle"></i> This task will be reopened for revision</p>' : ''}
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#f1c40f',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Submit Evaluation'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
            
            return false;
        }
        
        function viewSubmission(id) {
            const modal = document.getElementById('viewSubmissionModal');
            const content = document.getElementById('submissionDetails');
            
            content.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-warning" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading submission details...</p>
                </div>
            `;
            
            $.ajax({
                url: '<?php echo BASE_URL; ?>/api/principal',
                type: 'GET',
                data: { action: 'get_submission_details', id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        const maxMarks = data.category_max_marks || 0;
                        const marksObtained = data.marks_obtained || 'Not evaluated';
                        const status = data.evaluation_status || 'Pending';
                        const statusColor = status === 'Approved' ? 'success' : 
                                           status === 'Rejected' ? 'danger' : 'warning';
                        
                        let attachmentHtml = '';
                        if (data.attachment) {
                            const fileExt = data.attachment.split('.').pop().toLowerCase();
                            const isImage = ['jpg','jpeg','png','gif','webp'].includes(fileExt);
                            
                            attachmentHtml = `
                                <div class="attachment-box">
                                    <div class="file-info-row">
                                        <i class="bi ${isImage ? 'bi-file-image' : 'bi-file-pdf'}" style="font-size:2rem; color:#f1c40f;"></i>
                                        <div>
                                            <div style="font-weight:600; font-size:0.85rem;">${data.attachment}</div>
                                            <div style="font-size:0.75rem; color:#6c757d;">${isImage ? 'Image' : 'PDF'} • ${data.file_size || '0'} KB</div>
                                        </div>
                                    </div>
                                    <div class="file-actions">
                                        <a href="${downloadBaseUrl}?file=${encodeURIComponent(data.attachment)}&action=view" target="_blank" class="btn btn-primary btn-sm">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                        <a href="${downloadBaseUrl}?file=${encodeURIComponent(data.attachment)}&action=download" class="btn btn-success btn-sm">
                                            <i class="bi bi-download"></i> Download
                                        </a>
                                    </div>
                                </div>
                            `;
                        } else {
                            attachmentHtml = `<p class="text-muted">No attachment</p>`;
                        }
                        
                        content.innerHTML = `
                            <div class="row g-3">
                                <div class="col-12 col-md-8">
                                    <div class="detail-grid">
                                        <div class="detail-item">
                                            <span class="label">Task Title</span>
                                            <span class="value">${data.task_title}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="label">Category</span>
                                            <span class="value">${data.category_name}</span>
                                        </div>
                                        <div class="detail-item" style="grid-column:1/-1;">
                                            <span class="label">Description</span>
                                            <span class="value">${data.task_description || 'No description'}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="label">Due Date</span>
                                            <span class="value">${data.due_date || 'No due date'}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="label">Submitted</span>
                                            <span class="value">${data.submitted_at}</span>
                                        </div>
                                        <div class="detail-item" style="grid-column:1/-1;">
                                            <span class="label">Submission Text</span>
                                            <span class="value">${data.submission_text || 'No text'}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <h6 style="font-weight:600; color:#1a2332; font-size:0.9rem;">Attachment</h6>
                                    ${attachmentHtml}
                                    
                                    <h6 class="mt-3" style="font-weight:600; color:#1a2332; font-size:0.9rem;">Evaluation</h6>
                                    <div class="detail-grid" style="grid-template-columns:1fr;">
                                        <div class="detail-item">
                                            <span class="label">Status</span>
                                            <span class="badge bg-${statusColor}">${status}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="label">Marks</span>
                                            <span class="value">${marksObtained} / ${maxMarks}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="label">Remarks</span>
                                            <span class="value">${data.evaluation_remarks || '—'}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="label">Evaluated By</span>
                                            <span class="value">${data.evaluated_by_name || '—'}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        content.innerHTML = `<div class="alert alert-danger">${response.message || 'Failed to load'}</div>`;
                    }
                },
                error: function() {
                    content.innerHTML = `<div class="alert alert-danger">Error loading submission details</div>`;
                }
            });
            
            $(modal).modal('show');
        }

        function evaluateNow(id) {
            const row = document.querySelector(`#allSubmissionsTable tbody tr[data-submission-id="${id}"]`);
            if (row) {
                const status = row.dataset.status;
                if (status === 'Pending' || status === 'Submitted') {
                    document.getElementById('pending-tab').click();
                    setTimeout(() => {
                        const card = document.querySelector(`.submission-card[data-submission-id="${id}"]`);
                        if (card) {
                            const header = card.querySelector('.card-header');
                            if (header) header.click();
                            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }, 500);
                } else {
                    Swal.fire('Info', 'This submission has already been evaluated.', 'info');
                }
            }
        }

        $('#statusFilter').on('change', function() {
            const status = $(this).val();
            $('#allSubmissionsTable tbody tr').each(function() {
                const rowStatus = $(this).data('status') || 'Pending';
                $(this).toggle(status === 'all' || rowStatus === status);
            });
        });

        $('#categoryFilter').on('change', function() {
            const category = $(this).val();
            $('#allSubmissionsTable tbody tr').each(function() {
                const rowCategory = $(this).data('category');
                $(this).toggle(category === 'all' || rowCategory == category);
            });
        });

        $('#employeeSearch').on('keyup', function() {
            const search = $(this).val().toLowerCase();
            $('#allSubmissionsTable tbody tr').each(function() {
                $(this).toggle($(this).text().toLowerCase().includes(search));
            });
        });

        function resetFilters() {
            $('#statusFilter').val('all').trigger('change');
            $('#categoryFilter').val('all').trigger('change');
            $('#employeeSearch').val('').trigger('keyup');
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
            $('#allSubmissionsTable').DataTable({
                pageLength: 25,
                order: [[3, 'desc']],
                responsive: {
                    details: {
                        display: $.fn.dataTable.Responsive.display.modal({
                            header: function(row) {
                                var data = row.data();
                                return 'Submission Details';
                            }
                        }),
                        renderer: $.fn.dataTable.Responsive.renderer.tableAll({
                            tableClass: 'table table-bordered mb-0'
                        })
                    }
                },
                columnDefs: [
                    { responsivePriority: 1, targets: [0, 1, 3, 5] },
                    { responsivePriority: 2, targets: [2, 4] },
                    { orderable: false, targets: [5] }
                ],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "Showing 0 to 0 of 0 entries",
                    infoFiltered: "(filtered from _MAX_ total entries)",
                    zeroRecords: "No matching submissions found"
                }
            });
        });
    </script>
</body>
</html>
