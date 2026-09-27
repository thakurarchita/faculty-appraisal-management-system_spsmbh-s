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

// Check if user is principal or admin
if ($_SESSION['role'] !== 'Principal' && $_SESSION['role'] !== 'Admin') {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../config/mail.php';

$userModel = new User();
$db = getDB();

// Get all faculty users
$allUsers = $userModel->getAllUsers();
$facultyUsers = array_filter($allUsers, function($u) {
    return $u['role_name'] == 'Faculty';
});

// Get categories
$categories = getTaskCategories();

// Get task templates grouped by category
$stmt = $db->query("
    SELECT t.*, tc.name as category_name 
    FROM task_templates t 
    JOIN task_categories tc ON t.category_id = tc.id 
    WHERE t.is_active = 1
    ORDER BY tc.name, t.task_name
");
$templates = $stmt->fetchAll();

// Group templates by category
$templatesByCategory = [];
foreach ($templates as $template) {
    $templatesByCategory[$template['category_id']][] = $template;
}

// Handle task assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'assign_task') {
        $userIds = isset($_POST['user_id']) && is_array($_POST['user_id']) ? $_POST['user_id'] : [];
        if (empty($userIds)) {
            $_SESSION['error'] = 'Please select at least one faculty member.';
            header('Location: ' . BASE_URL . '/principal/assign-task');
            exit();
        }
        
        $assignedBy = (int)$_SESSION['user_id'];
        $categoryId = (int)$_POST['category_id'];
        $taskTitle = sanitizeInput($_POST['task_title']);
        $taskDescription = sanitizeInput($_POST['task_description'] ?? '');
        $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $priority = $_POST['priority'] ?? 'Medium';
        $duration = $_POST['duration'] ?? 'Semester';
        $isTemplate = isset($_POST['is_template']) ? 1 : 0;
        $attachment = null;
        
        // CRITICAL FIX: Set template_id based on task type
        $templateId = 0; // Default to 0 for custom tasks (since NOT NULL constraint)
        
        // If template is selected, use template name and ID
        if ($isTemplate && isset($_POST['template_id']) && !empty($_POST['template_id'])) {
            $templateId = (int)$_POST['template_id'];
            
            $stmt = $db->prepare("SELECT task_name FROM task_templates WHERE id = ? AND is_active = 1");
            $stmt->execute([$templateId]);
            $template = $stmt->fetch();
            if ($template) {
                $taskTitle = $template['task_name'];
            } else {
                $_SESSION['error'] = 'Selected template not found or inactive.';
                header('Location: ' . BASE_URL . '/principal/assign-task');
                exit();
            }
        }
        
        // Validate custom task title
        if (!$isTemplate && empty($taskTitle)) {
            $_SESSION['error'] = 'Please enter a task title.';
            header('Location: ' . BASE_URL . '/principal/assign-task');
            exit();
        }
        
        // Handle file upload or link
        $attachment = null;
        if (!empty($_POST['external_link'])) {
            $attachment = sanitizeInput($_POST['external_link']);
        } else if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
            $filename = $_FILES['attachment']['name'];
            $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $filesize = $_FILES['attachment']['size'];
            
            if ($filesize > 1 * 1024 * 1024) {
                $_SESSION['error'] = 'File size must be less than 1MB. Please use the external link field for larger files.';
                header('Location: ' . BASE_URL . '/principal/assign-task');
                exit();
            }
            
            if (!in_array($filetype, $allowed)) {
                $_SESSION['error'] = 'Only JPG, PNG, PDF, DOC, XLS, TXT files are allowed.';
                header('Location: ' . BASE_URL . '/principal/assign-task');
                exit();
            }
            
            $upload_dir = __DIR__ . '/../../assets/uploads/tasks/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $new_filename = 'task_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
            $target_path = $upload_dir . $new_filename;
            
            $image_types = ['jpg', 'jpeg', 'png', 'gif'];
            if (in_array($filetype, $image_types) && function_exists('imagecreatefromjpeg')) {
                // Image compression logic to target ~200KB
                $source_image = null;
                if ($filetype == 'jpg' || $filetype == 'jpeg') {
                    $source_image = @imagecreatefromjpeg($_FILES['attachment']['tmp_name']);
                } elseif ($filetype == 'png') {
                    $source_image = @imagecreatefrompng($_FILES['attachment']['tmp_name']);
                } elseif ($filetype == 'gif') {
                    $source_image = @imagecreatefromgif($_FILES['attachment']['tmp_name']);
                }
                
                if ($source_image) {
                    $new_filename_jpg = 'task_' . time() . '_' . uniqid() . '.jpg';
                    $target_path_jpg = $upload_dir . $new_filename_jpg;
                    
                    $width = imagesx($source_image);
                    $height = imagesy($source_image);
                    $max_width = 1200; // Resize large images
                    
                    if ($width > $max_width) {
                        $new_width = $max_width;
                        $new_height = floor($height * ($max_width / $width));
                        $tmp_image = imagecreatetruecolor($new_width, $new_height);
                        
                        if ($filetype == 'png' || $filetype == 'gif') {
                            imagecolortransparent($tmp_image, imagecolorallocatealpha($tmp_image, 0, 0, 0, 127));
                            imagealphablending($tmp_image, false);
                            imagesavealpha($tmp_image, true);
                        }
                        
                        imagecopyresampled($tmp_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                        imagejpeg($tmp_image, $target_path_jpg, 60); // Quality 60 to target ~200KB
                        imagedestroy($tmp_image);
                    } else {
                        imagejpeg($source_image, $target_path_jpg, 60);
                    }
                    imagedestroy($source_image);
                    $attachment = $new_filename_jpg;
                } else {
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
                        $attachment = $new_filename;
                    }
                }
            } else {
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
                    $attachment = $new_filename;
                }
            }
        }
        
        // Disable foreign key check temporarily
        $db->exec("PRAGMA foreign_keys = OFF");
        $db->beginTransaction();
        
        $successCount = 0;
        $failedCount = 0;
        
        try {
            $stmt = $db->prepare("
                INSERT INTO tasks (user_id, assigned_by, task_title, task_description, 
                                 category_id, due_date, priority, duration, 
                                 is_template, template_id, status, attachment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)
            ");
            
            // Prepare emails/notifications
            $userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $assignerStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
            $assignerStmt->execute([$assignedBy]);
            $assigner = $assignerStmt->fetch();
            $mailer = new Mailer();
            
            foreach ($userIds as $uid) {
                $userId = (int)$uid;
                if (!$userId) continue;
                
                $result = $stmt->execute([
                    $userId,
                    $assignedBy,
                    $taskTitle,
                    $taskDescription,
                    $categoryId,
                    $dueDate,
                    $priority,
                    $duration,
                    $isTemplate,
                    $templateId,
                    $attachment
                ]);
                
                if ($result) {
                    $successCount++;
                    
                    // Fetch user details for notification
                    $userStmt->execute([$userId]);
                    $user = $userStmt->fetch();
                    
                    if ($user) {
                        // Send email
                        if ($user['email']) {
                            $mailer->sendSingleTaskAssignedEmail(
                                $user['email'],
                                $taskTitle,
                                $dueDate ?? 'Not specified',
                                $assigner['full_name'] ?? 'Principal'
                            );
                        }
                        
                        // Add notification
                        addNotification(
                            $userId,
                            'New Task Assigned',
                            "Task '{$taskTitle}' has been assigned to you by Principal.",
                            '../views/faculty/tasks.php'
                        );
                    }
                } else {
                    $failedCount++;
                }
            }
            
            $db->commit();
            
            // Re-enable foreign key check
            $db->exec("PRAGMA foreign_keys = ON");
            
            if ($successCount > 0) {
                logActivity($assignedBy, 'Task Assigned', "Task '{$taskTitle}' assigned to {$successCount} users in bulk");
                $_SESSION['success'] = "Task '{$taskTitle}' successfully assigned to {$successCount} faculty member(s)!" . ($failedCount > 0 ? " ({$failedCount} failed)" : "");
            } else {
                $_SESSION['error'] = 'Failed to assign tasks. Please try again.';
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            $db->exec("PRAGMA foreign_keys = ON");
            $_SESSION['error'] = 'Database error: ' . $e->getMessage();
        }
        
        header('Location: ' . BASE_URL . '/principal/assign-task');
        exit();
    }
}
?>
<!-- THE REST OF YOUR HTML CODE CONTINUES HERE... -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Assign Task - Faculty Appraisal System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    
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
            background: #1a2634 !important;
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
            color: #a0afbe !important;
            border-radius: 8px;
            margin: 2px 10px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }
        
        .sidebar .nav-link:hover {
            color: #ffffff !important;
            background: rgba(255,255,255,0.05);
        }
        
        .sidebar .nav-link.active {
            color: #ffffff !important;
            background: rgba(255,255,255,0.1);
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
            background: #1a2634;
            border: none;
            color: white;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        
        .navbar-toggle:hover {
            background: #2d3a4a;
            transform: scale(1.05);
        }
        
        .navbar-toggle:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 38, 52, 0.3);
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
        .card-custom {
            border-radius: 14px;
            border: none;
            box-shadow: 0 2px 15px rgba(0,0,0,0.06);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .card-custom .card-header {
            background: #1a2634;
            color: #ffffff;
            padding: 16px 22px;
            font-weight: 600;
            border-bottom: none;
        }
        
        .card-custom .card-body {
            padding: 20px;
            background: white;
        }
        
        /* Form Controls */
        .form-label {
            font-weight: 500;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #dce1e8;
            padding: 10px 14px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #1a2634;
            box-shadow: 0 0 0 3px rgba(26,38,52,0.15);
        }
        
        .required-star {
            color: #dc3545;
            margin-left: 3px;
        }
        
        /* Buttons */
        .btn-primary-custom {
            background: #1a2332;
            border: none;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(26, 35, 50, 0.3);
            color: #ffffff;
        }
        
        .btn-warning-custom {
            background: #f1c40f;
            border: none;
            color: #1a2332;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-warning-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(241, 196, 15, 0.4);
            color: #1a2332;
        }
        
        /* Template Items */
        .template-item {
            padding: 10px 14px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 6px;
            cursor: pointer;
            transition: all 0.2s;
            background: #fafbfc;
        }
        
        .template-item:hover {
            background: #f0f2f5;
            border-color: #1a2634;
        }
        
        .template-item.selected {
            background: #e9edf2;
            border-color: #1a2634;
            border-width: 2px;
        }
        
        .template-item .template-name {
            font-weight: 500;
            color: #1a2332;
        }
        
        .template-item .template-meta {
            font-size: 12px;
            color: #6c757d;
        }
        
        /* Manual Task Section */
        .manual-task-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            border: 2px dashed #dce1e8;
            margin-top: 15px;
        }
        
        /* Guided Steps */
        .step-header {
            background: #f8f9fa;
            padding: 12px 15px;
            border-left: 4px solid #1a2332;
            border-radius: 4px;
            margin-bottom: 20px;
            font-weight: 600;
            color: #1a2332;
            display: flex;
            align-items: center;
            font-size: 1.1rem;
        }
        
        .step-number {
            background: #1a2332;
            color: #f1c40f;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            margin-right: 12px;
            font-weight: bold;
        }
        
        /* Select2 Overrides */
        .select2-container--bootstrap-5 .select2-selection {
            border-radius: 8px;
            border-color: #dce1e8;
            min-height: 44px;
        }
        
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            padding: 8px 14px;
            color: #1a2332;
        }
        
        .select2-container--bootstrap-5 .select2-dropdown {
            border-radius: 8px;
            border-color: #dce1e8;
        }
        
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__arrow {
            height: 42px;
        }
        
        /* Task Preview */
        .task-preview {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid #1a2634;
            margin-top: 15px;
            display: none;
        }
        
        .task-preview.show {
            display: block;
        }
        
        /* File Upload */
        .file-upload-wrapper {
            position: relative;
            border: 2px dashed #dce1e8;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #fafbfc;
        }
        
        .file-upload-wrapper:hover {
            border-color: #1a2634;
            background: #f8f9fa;
        }
        
        .file-upload-wrapper .file-info {
            font-size: 13px;
            color: #6c757d;
        }
        
        .file-upload-wrapper .file-name {
            font-weight: 500;
            color: #1a2332;
        }
        
        .file-upload-wrapper input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        
        .file-upload-wrapper.has-file {
            border-color: #28a745;
            background: #f0fff4;
        }
        
        /* Alerts */
        .alert {
            border-radius: 10px;
            border-left: 4px solid;
        }
        
        .alert-success {
            border-left-color: #198754;
        }
        
        .alert-danger {
            border-left-color: #dc3545;
        }
        
        /* Page Header */
        .page-title {
            color: #1a2332;
            font-weight: 700;
        }
        
        .page-subtitle {
            color: #6c757d;
            font-size: 14px;
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
            
            .card-custom .card-body {
                padding: 30px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px 10px;
            }
            
            .card-custom .card-header {
                padding: 12px 15px;
                font-size: 0.95rem;
            }
            
            .card-custom .card-body {
                padding: 15px;
            }
            
            .navbar-toggle {
                padding: 8px 12px;
                font-size: 1.2rem;
                top: 8px;
                left: 8px;
            }
            
            .form-control, .form-select {
                padding: 8px 12px;
                font-size: 13px;
            }
            
            .template-item {
                padding: 8px 12px;
            }
            
            .template-item .template-name {
                font-size: 13px;
            }
            
            .manual-task-section {
                padding: 12px;
            }
            
            .file-upload-wrapper {
                padding: 15px;
            }
            
            .principal-badge {
                font-size: 11px;
                padding: 4px 12px;
            }
            
            .page-title {
                font-size: 1.3rem;
            }
            
            .btn-primary-custom, .btn-warning-custom {
                padding: 10px 16px;
                font-size: 14px;
            }
            
            .select2-container--bootstrap-5 .select2-selection {
                min-height: 40px;
            }
        }
        
        @media (max-width: 400px) {
            .main-content {
                padding: 10px 5px;
            }
            
            .card-custom .card-body {
                padding: 12px;
            }
            
            .template-item {
                padding: 6px 10px;
            }
            
            .template-item .template-name {
                font-size: 12px;
            }
            
            .template-item .template-meta {
                font-size: 10px;
            }
            
            .file-upload-wrapper {
                padding: 12px;
            }
            
            .file-upload-wrapper .file-info {
                font-size: 11px;
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
        
        /* Category filter message */
        .category-filter-msg {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .category-filter-msg i {
            margin-right: 5px;
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
            <?php if ($_SESSION['role'] === 'Admin'): ?>
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
                    <a class="nav-link active" href="<?php echo BASE_URL; ?>/principal/assign-task">
                        <i class="bi bi-plus-circle"></i> Assign Task
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/task-status">
                        <i class="bi bi-list-task"></i> Task Status
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
            <?php else: ?>
            <div class="text-center mb-4 px-3" style="color: #ffffff; padding: 10px 0;">
                <i class="bi bi-mortarboard-fill" style="font-size: 2.5rem;"></i>
                <h6 style="color: #ffffff; margin-top: 5px; font-weight: 600;">Appraisal</h6>
                <small style="color: #a0afbe;">Principal Panel</small>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/dashboard">
                        <i class="bi bi-grid-1x2-fill"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="<?php echo BASE_URL; ?>/principal/assign-task">
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
                        <i class="bi bi-box-arrow-right"></i> Sign Out
                    </a>
                </li>
            </ul>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="container-fluid px-0">
            <!-- Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-4 border-bottom">
                <div>
                    <h2 class="page-title">Assign Task</h2>
                    <p class="page-subtitle">Assign tasks to faculty members</p>
                </div>
                <span class="principal-badge mt-2 mt-sm-0">
                    <i class="bi bi-person-badge me-1"></i> Principal
                </span>
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
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Assign Task Form -->
            <div class="card-custom fade-in">
                <div class="card-header">
                    <i class="bi bi-plus-circle me-2"></i> Assign New Task
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="assignForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="assign_task">
                        
                        <div class="step-header">
                            <span class="step-number">1</span> Faculty & Category Selection
                        </div>
                        <div class="row g-3 g-md-4">
                            <!-- Select User -->
<div class="col-12 col-md-6">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <label class="form-label mb-0">
            <i class="bi bi-person me-1"></i> Select Users <span class="required-star">*</span>
        </label>
        <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="selectAllUsersBtn" style="font-size: 0.75rem;">Select All</button>
    </div>
    <select name="user_id[]" id="userSelect" class="form-control" multiple="multiple" required>
        <?php foreach ($allUsers as $user): 
            $isSelf = $user['id'] == $_SESSION['user_id'];
            
            // Skip only the current Principal (self)
            if ($isSelf) {
                continue; // Don't show self in the list
            }
        ?>
        <option value="<?php echo $user['id']; ?>" 
                data-employee-type="<?php echo $user['employee_type']; ?>"
                data-role="<?php echo $user['role_name']; ?>"
                data-user-id="<?php echo $user['id']; ?>">
            <?php echo $user['employee_id']; ?> - <?php echo $user['full_name']; ?> 
            (<?php echo $user['role_name']; ?>)
            <?php echo $user['is_active'] ? '' : ' [Inactive]'; ?>
        </option>
        <?php endforeach; ?>
    </select>
    
</div>

                            <!-- Select Category -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">
                                    <i class="bi bi-tags me-1"></i> Category <span class="required-star">*</span>
                                </label>
                                <select name="category_id" id="categorySelect" class="form-control" required>
                                    <option value="">-- Select Category --</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" data-category-name="<?php echo $cat['name']; ?>">
                                        <?php echo $cat['name']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Task Templates -->
                        <div class="step-header mt-4">
                            <span class="step-number">2</span> Task Definition (Template or Custom)
                        </div>
                        <div class="row mt-3">
                            <div class="col-12">
                                <label class="form-label">
                                    <i class="bi bi-list-check me-1"></i> Task Templates (Click to select)
                                </label>
                                <div id="templatesContainer" class="border rounded p-3" style="background: #fafbfc; min-height: 80px;">
                                    <div id="templatePlaceholder" class="text-center text-muted py-3">
                                        <i class="bi bi-folder-open" style="font-size: 1.5rem;"></i>
                                        <p class="mt-1 mb-0">Select a faculty and category to see available templates</p>
                                    </div>
                                    <div id="templateList" style="display: none;"></div>
                                </div>
                                <input type="hidden" name="template_id" id="selectedTemplateId" value="">
                                <input type="hidden" name="is_template" id="isTemplate" value="0">
                            </div>
                        </div>

                        <!-- Or Create Manual Task -->
                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="manual-task-section">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center">
                                        <label class="form-label mb-2 mb-sm-0">
                                            <i class="bi bi-pencil-square me-1"></i> Or Create Custom Task
                                        </label>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="manualToggleBtn">
                                            <i class="bi bi-chevron-down" id="manualToggleIcon"></i> <span class="d-none d-sm-inline" id="manualToggleText">Show</span>
                                        </button>
                                    </div>
                                    <div id="manualTaskFields" style="display: none; margin-top: 15px;">
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="form-label">Task Title <span class="required-star">*</span></label>
                                                <input type="text" name="task_title" id="manualTaskTitle" class="form-control" placeholder="Enter task title...">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Description / Instructions</label>
                                                <textarea name="task_description" id="manualTaskDesc" class="form-control" rows="2" placeholder="Add detailed description..."></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Attachment Upload and Settings -->
                        <div class="step-header mt-4">
                            <span class="step-number">3</span> Settings & Attachments
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <label class="form-label">
                                    <i class="bi bi-paperclip me-1"></i> Attachment (Optional)
                                </label>
                                <div class="file-upload-wrapper" id="fileUploadWrapper">
                                    <input type="file" name="attachment" id="fileInput" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                                    <div id="fileUploadContent">
                                        <i class="bi bi-cloud-upload" style="font-size: 2rem; color: #6c757d;"></i>
                                        <p class="mb-1"><strong>Click to upload</strong> or drag and drop</p>
                                        <p class="file-info">Supported: JPG, PNG, PDF, DOC, XLS, TXT | Max: 1MB (Images are auto-compressed to ~200KB)</p>
                                    </div>
                                    <div id="fileSelected" style="display: none;">
                                        <i class="bi bi-file-earmark-check" style="font-size: 2rem; color: #28a745;"></i>
                                        <p class="mb-1"><strong class="file-name" id="fileNameDisplay">file.pdf</strong></p>
                                        <p class="file-info">Click to change file</p>
                                    </div>
                                </div>
                                <div class="mt-2 text-center text-muted fw-bold small">- OR -</div>
                                <div class="mt-2">
                                    <input type="url" name="external_link" class="form-control" placeholder="Paste Google Drive or Web Link for large files (> 1MB)">
                                </div>
                            </div>
                        </div>

                        <!-- Additional Settings -->
                        <div class="row g-3 mt-3">
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="form-label">
                                    <i class="bi bi-clock me-1"></i> Duration
                                </label>
                                <select name="duration" class="form-control">
                                    <option value="Monthly">Monthly</option>
                                    <option value="Semester" selected>Semester</option>
                                    <option value="Yearly">Yearly</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="form-label">
                                    <i class="bi bi-flag me-1"></i> Priority
                                </label>
                                <select name="priority" class="form-control">
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="form-label">
                                    <i class="bi bi-calendar-event me-1"></i> Due Date
                                </label>
                                <input type="date" name="due_date" class="form-control" id="dueDate">
                            </div>
                        </div>

                        <!-- Task Preview -->
                        <div class="task-preview" id="taskPreview">
                            <div class="d-flex flex-wrap justify-content-between align-items-center">
                                <div>
                                    <strong>Task Preview</strong>
                                    <div id="previewContent" style="font-size: 14px; color: #2c3e50;"></div>
                                </div>
                                <span class="badge mt-2 mt-sm-0" style="background: #f1c40f; color: #1a2332;">Ready</span>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="row mt-4">
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary-custom w-100" id="assignBtn">
                                    <i class="bi bi-check-circle me-2"></i> Assign Task
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Global toggle function - defined outside document ready so it's accessible from HTML
        function toggleManualTask() {
            const fields = $('#manualTaskFields');
            const icon = $('#manualToggleIcon');
            const text = $('#manualToggleText');
            
            if (fields.is(':visible')) {
                fields.slideUp();
                icon.removeClass('bi-chevron-up').addClass('bi-chevron-down');
                text.text('Show');
                // Deselect any template
                $('.template-item').removeClass('selected');
                $('#selectedTemplateId').val('');
                $('#isTemplate').val('0');
            } else {
                fields.slideDown();
                icon.removeClass('bi-chevron-down').addClass('bi-chevron-up');
                text.text('Hide');
                // Deselect any template
                $('.template-item').removeClass('selected');
                $('#selectedTemplateId').val('');
                $('#isTemplate').val('0');
                $('#manualTaskTitle').focus();
            }
            hidePreview();
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

            // Bind manual toggle button click
            $('#manualToggleBtn').on('click', toggleManualTask);

            // Initialize Select2 for User
            $('#userSelect').select2({
                theme: 'bootstrap-5',
                placeholder: 'Search and select faculty...',
                allowClear: true,
                width: '100%',
                dropdownParent: $('#userSelect').parent(),
                closeOnSelect: false
            });

            // Select All Users Button
            $('#selectAllUsersBtn').on('click', function() {
                $('#userSelect > option').prop('selected', true);
                $('#userSelect').trigger('change');
            });

            // Set default due date (30 days from now)
            const today = new Date();
            const futureDate = new Date(today);
            futureDate.setDate(today.getDate() + 30);
            const dd = String(futureDate.getDate()).padStart(2, '0');
            const mm = String(futureDate.getMonth() + 1).padStart(2, '0');
            const yyyy = futureDate.getFullYear();
            $('#dueDate').val(`${yyyy}-${mm}-${dd}`);

            // Store all categories with their names for filtering
            const allCategoryOptions = $('#categorySelect option').map(function() {
                return {
                    value: $(this).val(),
                    text: $(this).text(),
                    name: $(this).data('category-name'),
                    element: $(this)[0]
                };
            }).get();

            // Filter categories based on employee type
            function filterCategories(employeeType) {
                const categorySelect = $('#categorySelect');
                const filterMsg = $('#categoryFilterMsg');
                
                // Clear current options
                categorySelect.find('option').remove();
                
                // Add placeholder
                categorySelect.append('<option value="">-- Select Category --</option>');
                
                // Define allowed categories based on employee type
                let allowedCategories = [];
                if (employeeType === 'Teaching') {
                    // Teaching faculty can access all 5 categories
                    allowedCategories = ['Administrative', 'Teaching', 'FDP', 'Research', 'Self Initiative'];
                    filterMsg.html('<i class="bi bi-check-circle text-success"></i> Teaching faculty can access all categories: Administrative, Teaching, FDP, Research, Self Initiative');
                } else if (employeeType === 'Non-Teaching') {
                    // Non-teaching faculty can access Administrative, Self Initiative, and FDP
                    allowedCategories = ['Administrative', 'Self Initiative', 'FDP'];
                    filterMsg.html('<i class="bi bi-info-circle text-warning"></i> Non-teaching faculty can access: Administrative, Self Initiative, FDP only');
                } else {
                    filterMsg.html('<i class="bi bi-info-circle"></i> Select a faculty to see available categories');
                    // Show all categories if no faculty selected
                    allCategoryOptions.forEach(opt => {
                        if (opt.value) {
                            categorySelect.append(`<option value="${opt.value}" data-category-name="${opt.name}">${opt.text}</option>`);
                        }
                    });
                    categorySelect.val('').trigger('change');
                    return;
                }
                
                // Add filtered options with case-insensitive comparison
                let hasCategories = false;
                allCategoryOptions.forEach(opt => {
                    if (opt.value) {
                        const categoryName = opt.name;
                        // Case-insensitive comparison
                        const isAllowed = allowedCategories.some(allowed => 
                            allowed.toLowerCase() === categoryName.toLowerCase()
                        );
                        
                        if (isAllowed) {
                            categorySelect.append(`<option value="${opt.value}" data-category-name="${categoryName}">${opt.text}</option>`);
                            hasCategories = true;
                        }
                    }
                });
                
                // Reset selection
                categorySelect.val('').trigger('change');
                
                // If no categories found, show message
                if (!hasCategories) {
                    categorySelect.append('<option value="">No categories available</option>');
                    filterMsg.html('<i class="bi bi-exclamation-triangle text-danger"></i> No categories available for this faculty type.');
                }
            }

            // User selection change - filter categories
            $('#userSelect').on('change', function() {
                const selectedOptions = $(this).find('option:selected');
                
                // Reset templates
                resetTemplates();
                
                if (selectedOptions.length > 0) {
                    let isAllTeaching = true;
                    let isAllNonTeaching = true;
                    
                    selectedOptions.each(function() {
                        const type = $(this).data('employee-type');
                        if (type === 'Teaching') isAllNonTeaching = false;
                        if (type === 'Non-Teaching') isAllTeaching = false;
                    });
                    
                    let employeeType = 'Mixed';
                    if (isAllTeaching && !isAllNonTeaching) employeeType = 'Teaching';
                    if (isAllNonTeaching && !isAllTeaching) employeeType = 'Non-Teaching';
                    
                    // Mixed means we should probably only show common categories (Non-Teaching set)
                    // So if it's Mixed, we treat it as Non-Teaching for safety
                    if (employeeType === 'Mixed') employeeType = 'Non-Teaching';
                    
                    $('#userSelect').data('resolved-type', employeeType);
                    filterCategories(employeeType);
                } else {
                    $('#userSelect').data('resolved-type', '');
                    // Show all categories
                    filterCategories(null);
                }
                
                // Update preview
                updatePreview();
            });

            // Reset templates when category changes
            $('#categorySelect').on('change', function() {
                const categoryId = $(this).val();
                const userIds = $('#userSelect').val();
                const employeeType = $('#userSelect').data('resolved-type');
                
                // Reset template selection
                $('#selectedTemplateId').val('');
                $('#isTemplate').val('0');
                $('#manualTaskTitle').val('');
                $('#manualTaskDesc').val('');
                $('.template-item').removeClass('selected');
                
                if (!categoryId || !userIds || userIds.length === 0) {
                    const templateList = $('#templateList');
                    const placeholder = $('#templatePlaceholder');
                    templateList.html('');
                    templateList.hide();
                    placeholder.show();
                    
                    if (!userIds || userIds.length === 0) {
                        placeholder.html(`
                            <i class="bi bi-person" style="font-size: 1.5rem;"></i>
                            <p class="mt-1 mb-0">Select faculty first</p>
                        `);
                    } else if (!categoryId) {
                        placeholder.html(`
                            <i class="bi bi-tags" style="font-size: 1.5rem;"></i>
                            <p class="mt-1 mb-0">Select a category to see available templates</p>
                        `);
                    }
                    hidePreview();
                    return;
                }
                
                loadTemplates(categoryId, employeeType);
            });

            // Load templates function
            function loadTemplates(categoryId, employeeType) {
                const templateList = $('#templateList');
                const placeholder = $('#templatePlaceholder');
                
                // Get templates from PHP data
                const templates = <?php echo json_encode($templates); ?>;
                const filtered = templates.filter(t => {
                    // Filter by category
                    if (t.category_id != categoryId) return false;
                    
                    // Filter by employee type compatibility
                    if (employeeType === 'Teaching') {
                        // Teaching faculty can see templates for Teaching and Both
                        return t.employee_type === 'Teaching' || t.employee_type === 'Both';
                    } else if (employeeType === 'Non-Teaching') {
                        // Non-teaching faculty can see templates for Non-Teaching and Both
                        return t.employee_type === 'Non-Teaching' || t.employee_type === 'Both';
                    }
                    return true;
                });

                if (filtered.length === 0) {
                    const categoryName = $('#categorySelect option:selected').text();
                    placeholder.html(`
                        <i class="bi bi-exclamation-circle" style="font-size: 1.5rem; color: #ffc107;"></i>
                        <p class="mt-1 mb-0">No templates available for <strong>${categoryName}</strong> for this employee type</p>
                        <small class="text-muted">You can create a custom task manually below</small>
                    `);
                    templateList.html('');
                    templateList.hide();
                    placeholder.show();
                    return;
                }

                // Build template list
                let html = `<div class="row g-2">`;
                filtered.forEach((task) => {
                    const employeeTypeLabel = task.employee_type === 'Both' ? 'All' : task.employee_type;
                    html += `
                        <div class="col-12 col-sm-6">
                            <div class="template-item" onclick="selectTemplate(${task.id}, '${task.task_name}')" id="template_${task.id}">
                                <div class="d-flex flex-wrap justify-content-between align-items-center">
                                    <span class="template-name">${task.task_name}</span>
                                    <span class="badge" style="background: #e9ecef; color: #495057; font-size: 9px;">${employeeTypeLabel}</span>
                                </div>
                                <div class="template-meta">${task.category_name}</div>
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
                
                templateList.html(html);
                placeholder.hide();
                templateList.show();
            }

            // Reset templates
            function resetTemplates() {
                const templateList = $('#templateList');
                const placeholder = $('#templatePlaceholder');
                templateList.html('');
                templateList.hide();
                placeholder.show();
                placeholder.html(`
                    <i class="bi bi-folder-open" style="font-size: 1.5rem;"></i>
                    <p class="mt-1 mb-0">Select a faculty and category to see available templates</p>
                `);
                $('#selectedTemplateId').val('');
                $('#isTemplate').val('0');
                $('.template-item').removeClass('selected');
                hidePreview();
            }

            // Select template - defined globally
            window.selectTemplate = function(templateId, taskName) {
                // Deselect all
                $('.template-item').removeClass('selected');
                // Select current
                $('#template_' + templateId).addClass('selected');
                
                $('#selectedTemplateId').val(templateId);
                $('#isTemplate').val('1');
                $('#manualTaskTitle').val(taskName);
                
                // Show preview
                showPreview(taskName, 'Template task');
            }

            // File upload handler
            document.getElementById('fileInput').addEventListener('change', function(e) {
                const file = this.files[0];
                const wrapper = document.getElementById('fileUploadWrapper');
                const uploadContent = document.getElementById('fileUploadContent');
                const fileSelected = document.getElementById('fileSelected');
                const fileNameDisplay = document.getElementById('fileNameDisplay');
                
                if (file) {
                    // Check file size (5MB)
                    if (file.size > 5 * 1024 * 1024) {
                        Swal.fire('Error', 'File size must be less than 5MB.', 'error');
                        this.value = '';
                        return;
                    }
                    
                    // Check file type
                    const allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (!allowed.includes(ext)) {
                        Swal.fire('Error', 'File type not allowed. Supported: JPG, PNG, PDF, DOC, XLS, TXT', 'error');
                        this.value = '';
                        return;
                    }
                    
                    fileNameDisplay.textContent = file.name;
                    uploadContent.style.display = 'none';
                    fileSelected.style.display = 'block';
                    wrapper.classList.add('has-file');
                } else {
                    uploadContent.style.display = 'block';
                    fileSelected.style.display = 'none';
                    wrapper.classList.remove('has-file');
                }
                
                // Update preview
                updatePreview();
            });

            // Show preview
            function showPreview(taskName, type) {
                const preview = $('#taskPreview');
                const content = $('#previewContent');
                const user = $('#userSelect option:selected').text();
                const category = $('#categorySelect option:selected').text();
                const priority = $('select[name="priority"]').val();
                const duration = $('select[name="duration"]').val();
                const dueDate = $('#dueDate').val();
                const hasFile = document.getElementById('fileInput').files.length > 0;
                
                content.html(`
                    <div class="row g-2 mt-1">
                        <div class="col-12 col-sm-6"><strong>Task:</strong> ${taskName}</div>
                        <div class="col-12 col-sm-6"><strong>Type:</strong> ${type}</div>
                        <div class="col-12 col-sm-6"><strong>User:</strong> ${user || 'Not selected'}</div>
                        <div class="col-12 col-sm-6"><strong>Category:</strong> ${category || 'Not selected'}</div>
                        <div class="col-12 col-sm-6"><strong>Priority:</strong> ${priority}</div>
                        <div class="col-12 col-sm-6"><strong>Duration:</strong> ${duration}</div>
                        <div class="col-12 col-sm-6"><strong>Due Date:</strong> ${dueDate || 'Not set'}</div>
                        <div class="col-12 col-sm-6"><strong>Attachment:</strong> ${hasFile ? 'Uploaded' : 'None'}</div>
                    </div>
                `);
                preview.addClass('show');
            }

            // Hide preview
            function hidePreview() {
                $('#taskPreview').removeClass('show');
            }

            // Update preview
            function updatePreview() {
                const templateId = $('#selectedTemplateId').val();
                const taskName = $('#manualTaskTitle').val().trim();
                if (templateId || taskName) {
                    const name = templateId ? $('#template_' + templateId + ' .template-name').text() : taskName;
                    const type = templateId ? 'Template' : 'Custom';
                    showPreview(name, type);
                } else {
                    hidePreview();
                }
            }

            // Form validation
            $('#assignForm').on('submit', function(e) {
                e.preventDefault();
                
                const userId = $('#userSelect').val();
                const categoryId = $('#categorySelect').val();
                const templateId = $('#selectedTemplateId').val();
                const isTemplate = $('#isTemplate').val();
                const manualTitle = $('#manualTaskTitle').val().trim();
                
                if (!userId) {
                    Swal.fire('Error', 'Please select a faculty member', 'error');
                    return;
                }
                
                if (!categoryId) {
                    Swal.fire('Error', 'Please select a category', 'error');
                    return;
                }
                
                // Check if template selected or manual title entered
                if (isTemplate == '0' && manualTitle === '') {
                    Swal.fire('Error', 'Please select a template or enter a task title', 'error');
                    return;
                }
                
                const userName = $('#userSelect option:selected').text();
                const categoryName = $('#categorySelect option:selected').text();
                const taskName = isTemplate == '1' ? $('#template_' + templateId + ' .template-name').text() : manualTitle;
                const hasFile = document.getElementById('fileInput').files.length > 0;
                
                Swal.fire({
                    title: 'Assign Task?',
                    html: `
                        <div class="text-start" style="font-size: 14px;">
                            <p><strong>User:</strong> ${userName}</p>
                            <p><strong>Category:</strong> ${categoryName}</p>
                            <p><strong>Task:</strong> ${taskName}</p>
                            <p><strong>Type:</strong> ${isTemplate == '1' ? 'Template' : 'Custom'}</p>
                            <p><strong>Attachment:</strong> ${hasFile ? 'Uploaded' : 'None'}</p>
                            <hr>
                            <p class="text-muted small">Task will be assigned with status "Pending"</p>
                            <p class="text-muted small">Email notification will be sent to the faculty member</p>
                        </div>
                    `,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#1a2634',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, Assign',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Show loading
                        Swal.fire({
                            title: 'Assigning Task...',
                            text: 'Please wait while the task is being assigned.',
                            allowOutsideClick: false,
                            showConfirmButton: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        // Submit the form
                        document.getElementById('assignForm').submit();
                    }
                });
            });

            // Manual task title input triggers preview
            $('#manualTaskTitle').on('input', function() {
                const val = $(this).val().trim();
                if (val.length > 0) {
                    $('.template-item').removeClass('selected');
                    $('#selectedTemplateId').val('');
                    $('#isTemplate').val('0');
                    showPreview(val, 'Custom task');
                } else {
                    // Check if there's a template selected
                    const templateId = $('#selectedTemplateId').val();
                    if (!templateId) {
                        hidePreview();
                    }
                }
            });

            // User change - update preview
            $('#userSelect, #categorySelect, select[name="priority"], select[name="duration"], #dueDate, #fileInput').on('change', function() {
                updatePreview();
            });

            // Manual task description input - update preview without hiding
            $('#manualTaskDesc').on('input', function() {
                updatePreview();
            });
        });
    </script>
</body>
</html>
